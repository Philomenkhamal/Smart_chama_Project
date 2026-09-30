<?php
/**
 * ChamaLedger — Help Bot API
 * POST /api/helpbot.php  body: {"message":"..."}
 */

// Clean slate - no output buffering interference
if (ob_get_level()) ob_end_clean();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache');

// Bootstrap minimally
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));

// Load config for DB and APP_URL only
require_once ROOT . '/config/db.php';

// Start session cleanly
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

// Auth check
if (empty($_SESSION['user_id'])) {
    echo json_encode(['reply' => 'Please log in to use the help assistant.']);
    exit;
}

// Read JSON input
$raw     = file_get_contents('php://input');
$input   = json_decode($raw, true);
$message = trim($input['message'] ?? '');

if (!$message || strlen($message) < 1) {
    echo json_encode(['reply' => 'Please type a message first.']);
    exit;
}
if (strlen($message) > 500) $message = substr($message, 0, 500);

// DB context
try {
    $pdo       = getDB();
    $userId    = (int)$_SESSION['user_id'];
    $role      = $_SESSION['user_role'] ?? 'member';

    // Settings
    $getSetting = function(string $key, string $default) use ($pdo): string {
        try {
            $s = $pdo->prepare("SELECT value FROM settings WHERE `key`=? LIMIT 1");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return $v !== false ? $v : $default;
        } catch (\Exception $e) { return $default; }
    };

    $groupName  = $getSetting('group_name',            'ChamaLedger');
    $curr       = $getSetting('currency',              'KES');
    $contribAmt = $getSetting('monthly_contribution',  '0');
    $loanLimit  = $getSetting('loan_multiplier',       '3');

    // Member's personal context
    $memberCtx = '';
    if ($role === 'member') {
        $u = $pdo->prepare("SELECT full_name, membership_number, status FROM users WHERE id=? LIMIT 1");
        $u->execute([$userId]); $u = $u->fetch(PDO::FETCH_ASSOC);

        $stSaved = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE user_id=? AND status='confirmed'");
        $stSaved->execute([$userId]);
        $totalSaved = (float)$stSaved->fetchColumn();

        $stLoan = $pdo->prepare("SELECT amount_approved, balance, status FROM loans WHERE user_id=? AND status IN ('approved','disbursed') LIMIT 1");
        $stLoan->execute([$userId]); $loan = $stLoan->fetch(PDO::FETCH_ASSOC);

        $stFines = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM member_fines WHERE user_id=? AND status='pending'");
        $stFines->execute([$userId]);
        $pendingFines = (float)$stFines->fetchColumn();

        $stMissed = $pdo->prepare("SELECT COUNT(*) FROM contributions WHERE user_id=? AND status='missed'");
        $stMissed->execute([$userId]);
        $missedMonths = (int)$stMissed->fetchColumn();

        $memberCtx  = "Member: {$u['full_name']} (#{$u['membership_number']})\n";
        $memberCtx .= "Total saved: {$curr} " . number_format($totalSaved, 2) . "\n";
        $memberCtx .= "Missed months: {$missedMonths}\n";
        if ($loan) {
            $memberCtx .= "Active loan: {$curr} " . number_format($loan['amount_approved'], 2);
            $memberCtx .= " | Outstanding balance: {$curr} " . number_format($loan['balance'], 2) . "\n";
        } else {
            $memberCtx .= "No active loan\n";
        }
        if ($pendingFines > 0) $memberCtx .= "Pending fines: {$curr} " . number_format($pendingFines, 2) . "\n";
    }
} catch (\Exception $e) {
    $groupName = 'ChamaLedger'; $curr = 'KES'; $contribAmt = '0';
    $loanLimit = '3'; $memberCtx = ''; $role = 'member';
}

// ── Build system prompt ───────────────────────────────────────────────────────
$systemPrompt = "You are ChamaBot, a friendly assistant for {$groupName}, a Kenyan chama savings group.
Help members understand the system and their finances. Be warm, concise, and practical.

GROUP DETAILS:
- Group: {$groupName} | Currency: {$curr} | Monthly contribution: {$curr} {$contribAmt}
- Loan multiplier: {$loanLimit}x savings
- Member role: {$role}

MEMBER DATA:
{$memberCtx}

SYSTEM PAGES & HOW TO USE THEM:
1. My Savings — Pay monthly contribution. Methods: STK Push (phone prompt), Pochi, Manual entry. Admin confirms payment.
2. My Loans — View active loan, balance, repayment history.
3. Apply for Loan — Submit loan application. Admin approves/rejects. Max = {$loanLimit}x your total savings.
4. Payment History — All transactions in one list.
5. My Statement — Downloadable PDF with contribution calendar and loan summary.
6. Our Members — Directory of all active members.
7. Events — Special group events with separate contribution targets.
8. Meeting Minutes — Notes from group meetings.
9. Group Chat — Reply on announcements, discuss with admin and members.
10. My Dividends — Year-end profit sharing.
11. My Profile — Update photo, nickname, password, contact details.
12. Fines — Late/missed contributions attract automatic fines. Admin can waive.
13. Notifications — Bell icon top-right. Clicking a notification navigates to that page.

PAYING A CONTRIBUTION (step by step):
1. Sidebar → My Savings
2. Choose month you're paying for
3. Choose method: STK Push = fastest (phone gets a prompt, enter M-Pesa PIN)
4. Pochi: send to the number shown, enter M-Pesa code as reference
5. Manual: admin records it for you
6. Wait for admin to confirm — status changes from 'pending' to 'confirmed'

APPLYING FOR A LOAN:
1. Sidebar → Apply for Loan
2. Enter amount (max {$loanLimit}x your savings = {$curr} " . number_format((float)$contribAmt * (float)$loanLimit, 0) . " approx)
3. Enter reason
4. Submit → admin reviews → you get a notification
5. After approval, admin disburses (M-Pesa or cash)

RULES:
- Keep answers under 120 words unless steps are needed
- Use the member's actual data when answering balance/loan questions
- If asked in Swahili, respond in Swahili
- Never guess — say 'contact your admin' if unsure
- Format steps as numbered lists";

// ── Try Anthropic API ─────────────────────────────────────────────────────────
$reply = null;

if (function_exists('curl_init')) {
    $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : (getenv('ANTHROPIC_API_KEY') ?: '');

    if ($apiKey) {
        $body = json_encode([
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 350,
            'system'     => $systemPrompt,
            'messages'   => [['role' => 'user', 'content' => $message]],
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res && $code === 200) {
            $data  = json_decode($res, true);
            $reply = $data['content'][0]['text'] ?? null;
        }
    }
}

// ── Fallback: smart rule-based replies ───────────────────────────────────────
if (!$reply) {
    $m = strtolower($message);

    // Greetings
    if (preg_match('/^(hi|hello|hey|habari|hujambo|mambo|sasa)\b/', $m)) {
        $name = $_SESSION['user_name'] ?? 'there';
        $reply = "Hello {$name}! 👋 I'm ChamaBot. I can help you with contributions, loans, your balance, fines, and more. What do you need?";
    }
    // Balance / savings
    elseif (preg_match('/balance|savings|saved|nimeweka|jumla|total|kiasi/', $m)) {
        if ($memberCtx) {
            $reply = "Your total confirmed savings are **{$curr} " . number_format($totalSaved ?? 0, 2) . "**.\n\nTo see the full breakdown go to **My Statement** in the sidebar.";
        } else {
            $reply = "Go to **My Statement** in the sidebar to see your full savings balance and contribution history.";
        }
    }
    // Contribution / payment
    elseif (preg_match('/contribut|pay|lipa|payment|mchango|pesa|monthly|kila mwezi/', $m)) {
        $reply = "To pay your monthly contribution ({$curr} {$contribAmt}):\n1. Go to **My Savings** in the sidebar\n2. Select the month\n3. Choose M-Pesa STK Push → enter phone → approve on your phone\n\nAdmin confirms your payment — you'll get a notification.";
    }
    // Loan apply
    elseif (preg_match('/apply.*loan|loan.*apply|omba mkopo|want.*loan|need.*loan/', $m)) {
        $reply = "To apply for a loan:\n1. Sidebar → **Apply for Loan**\n2. Enter amount and reason\n3. Submit — admin reviews and you'll get a notification\n\nMax loan = {$loanLimit}x your savings.";
    }
    // Loan balance/status
    elseif (preg_match('/loan|mkopo|borrow|repay|deni/', $m)) {
        if (!empty($loan)) {
            $reply = "Your active loan: **{$curr} " . number_format($loan['amount_approved'] ?? 0, 2) . "**\nOutstanding balance: **{$curr} " . number_format($loan['balance'] ?? 0, 2) . "**\n\nGo to **My Loans** to make a repayment.";
        } else {
            $reply = "You have no active loan. To apply, go to **Apply for Loan** in the sidebar.";
        }
    }
    // Fines
    elseif (preg_match('/fine|penalty|faini|late|default|kuchelewa/', $m)) {
        $fineAmt = $pendingFines ?? 0;
        if ($fineAmt > 0) {
            $reply = "You have pending fines of **{$curr} " . number_format($fineAmt, 2) . "**. These are applied for late or missed contributions. Contact your admin to request a waiver.";
        } else {
            $reply = "Fines are charged for late or missed contributions. Go to **My Savings** or **Payment History** to see your fine status. Contact your admin if you need a waiver.";
        }
    }
    // Statement / download
    elseif (preg_match('/statement|download|pdf|print|report/', $m)) {
        $reply = "To download your member statement:\n1. Sidebar → **My Statement**\n2. Your contribution calendar and loan summary will appear\n3. Click **Print / Save PDF**";
    }
    // Password / profile
    elseif (preg_match('/password|nywila|change.*pass|reset.*pass|profile|photo|picha/', $m)) {
        $reply = "To change your password or update your profile:\n1. Sidebar → **My Profile**\n2. Update your photo, nickname, or contact details\n3. Scroll down to change your password\n4. Click **Save Changes**";
    }
    // Group chat / announcements
    elseif (preg_match('/chat|announce|message|ujumbe|discuss/', $m)) {
        $reply = "Go to **Group Chat** in the sidebar to see announcements and join discussions. You can reply directly on any announcement and admin will be notified.";
    }
    // Dividend
    elseif (preg_match('/dividend|profit|gawio|share.*profit|year.*end/', $m)) {
        $reply = "Dividends are distributed at year-end based on your savings. Go to **My Dividends** in the sidebar to see what has been allocated to you.";
    }
    // Events
    elseif (preg_match('/event|tukio|special|occasion/', $m)) {
        $reply = "Go to **Events** in the sidebar to see special group events and make your event contributions via M-Pesa or manually.";
    }
    // STK Push help
    elseif (preg_match('/stk|mpesa|m-pesa|safaricom|push|pin/', $m)) {
        $reply = "For M-Pesa STK Push:\n1. Enter your Safaricom phone number\n2. Click **Pay with M-Pesa**\n3. A prompt will pop up on your phone\n4. Enter your M-Pesa PIN to approve\n5. Wait for confirmation (takes ~30 seconds)";
    }
    // Help / menu
    elseif (preg_match('/help|msaada|what can|nini|how|jinsi/', $m)) {
        $reply = "I can help you with:\n• 💰 **Paying contributions** — My Savings\n• 📋 **Loans** — My Loans / Apply for Loan\n• 💳 **Your balance** — My Statement\n• ⚠️ **Fines** — ask me!\n• 👤 **Profile & password** — My Profile\n• 💬 **Group chat** — Group Chat\n\nWhat do you need?";
    }
    // Default
    else {
        $reply = "I'm here to help with {$groupName}! You can ask me about:\n• Paying contributions\n• Loans\n• Your balance\n• Fines\n• How to use any page\n\nOr contact your admin for anything specific.";
    }
}

echo json_encode(['reply' => $reply], JSON_UNESCAPED_UNICODE);
