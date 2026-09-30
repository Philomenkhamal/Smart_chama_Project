<?php
/**
 * ChamaLedger — M-Pesa Receipt Verification
 * GET  ?code=QHX7Y8Z9AB          → format check only (instant)
 * POST { csrf_token, code, expected_amount, context, context_id } → full API verify
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
require_once ROOT . '/includes/mpesa.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');
startSession();

if (!isLoggedIn()) { echo json_encode(['ok'=>false,'message'=>'Not authenticated']); exit; }

$pdo = getDB();

// ── GET: instant format check ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $code = strtoupper(trim($_GET['code'] ?? ''));
    if (!$code) { echo json_encode(['ok'=>false,'message'=>'No code provided']); exit; }

    $valid = Mpesa::isValidReceiptFormat($code); // ^[A-Z][A-Z0-9]{9}$

    // Also check if this code is already used in our system
    $used = false; $usedBy = null;
    if ($valid) {
        $stmt = $pdo->prepare("
            SELECT u.full_name, c.payment_month, c.amount, c.status
            FROM contributions c JOIN users u ON u.id=c.user_id
            WHERE c.reference_code=? LIMIT 1
        ");
        $stmt->execute([$code]);
        $existing = $stmt->fetch();
        if (!$existing) {
            // Check event contributions
            $stmt2 = $pdo->prepare("
                SELECT u.full_name, e.title AS payment_month, ec.amount, ec.status
                FROM event_contributions ec JOIN users u ON u.id=ec.user_id JOIN events e ON e.id=ec.event_id
                WHERE ec.reference_code=? LIMIT 1
            ");
            $stmt2->execute([$code]);
            $existing = $stmt2->fetch();
        }
        if ($existing) {
            $used = true;
            $usedBy = $existing['full_name'] . ' — ' . $existing['payment_month'] . ' (KES ' . number_format($existing['amount'],0) . ', ' . $existing['status'] . ')';
        }
    }

    echo json_encode([
        'ok'      => $valid && !$used,
        'format'  => $valid,
        'already_used' => $used,
        'used_by' => $usedBy,
        'message' => !$valid
            ? 'Invalid format. M-Pesa codes are exactly 10 uppercase letters/numbers (e.g. QHX7Y8Z9AB)'
            : ($used ? 'This code is already recorded: ' . $usedBy : 'Format valid ✓'),
    ]);
    exit;
}

// ── POST: full verification via Safaricom API ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    if (!verifyCsrf($input['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'message'=>'Invalid token']); exit; }

    $code      = strtoupper(trim($input['code'] ?? ''));
    $expected  = (float)($input['expected_amount'] ?? 0);
    $context   = $input['context']    ?? 'contribution'; // 'contribution' or 'event'
    $contextId = (int)($input['context_id'] ?? 0);

    // Step 1: Format check
    if (!Mpesa::isValidReceiptFormat($code)) {
        echo json_encode(['ok'=>false,'message'=>'Invalid M-Pesa code format. Must be 10 uppercase alphanumeric characters.']);
        exit;
    }

    // Step 2: Duplicate check
    $stmt = $pdo->prepare("SELECT u.full_name, c.amount, c.status FROM contributions c JOIN users u ON u.id=c.user_id WHERE c.reference_code=? LIMIT 1");
    $stmt->execute([$code]);
    $dup = $stmt->fetch();
    if (!$dup) {
        $stmt2 = $pdo->prepare("SELECT u.full_name, ec.amount, ec.status FROM event_contributions ec JOIN users u ON u.id=ec.user_id WHERE ec.reference_code=? LIMIT 1");
        $stmt2->execute([$code]);
        $dup = $stmt2->fetch();
    }
    if ($dup) {
        echo json_encode([
            'ok' => false,
            'message' => 'This M-Pesa code is already recorded under ' . htmlspecialchars($dup['full_name']) . ' (KES ' . number_format($dup['amount'],0) . ', ' . $dup['status'] . '). Cannot reuse a receipt.',
            'duplicate' => true,
        ]);
        exit;
    }

    // Step 3: Try Safaricom Transaction Status API (only works on live, not sandbox)
    if (MPESA_ENV === 'live') {
        $initiator  = defined('MPESA_INITIATOR_NAME') ? MPESA_INITIATOR_NAME : '';
        $credential = defined('MPESA_SECURITY_CREDENTIAL') ? MPESA_SECURITY_CREDENTIAL : '';

        if ($initiator && $credential) {
            $mpesa  = new Mpesa();
            $result = $mpesa->verifyTransaction($code, $initiator, $credential);

            if (!$result['valid']) {
                echo json_encode(['ok'=>false,'message'=>'Safaricom could not verify this code: ' . $result['message'], 'api_checked'=>true]);
                exit;
            }

            // Verification queued — store pending verification
            $pdo->prepare("INSERT INTO mpesa_verifications (receipt_code, context, context_id, status) VALUES (?,?,?,'pending') ON DUPLICATE KEY UPDATE status='pending', checked_at=NOW()")
                ->execute([$code, $context, $contextId]);

            echo json_encode(['ok'=>true,'message'=>'Verification sent to Safaricom. Code accepted — awaiting API result.','queued'=>true,'api_checked'=>true]);
            exit;
        }
    }

    // Step 4: Sandbox / no API credentials — format + duplicate check only
    echo json_encode([
        'ok'          => true,
        'message'     => 'Code format valid and not previously used. ✓',
        'api_checked' => false,
        'note'        => MPESA_ENV === 'sandbox' ? 'Running in sandbox — live API verification skipped.' : 'API credentials not configured — format check only.',
    ]);
}
