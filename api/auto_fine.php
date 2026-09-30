<?php
/**
 * ChamaLedger — Auto Late Fine Engine
 * POST /api/auto_fine.php — admin only
 * Scans a given month, finds members who haven't paid, applies late fine.
 * Can be run manually by admin OR called on a schedule.
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
startSession();
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');

$role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? '';
if (!isLoggedIn() || $role !== 'admin') {
    echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
}
if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit;
}

$pdo        = getDB();
$month      = sanitize($_POST['month'] ?? date('Y-m', strtotime('-1 month')));
$fineAmount = (float)getSetting('late_fine_amount', '500');
$enabled    = getSetting('late_fine_enabled', '1') === '1';

if (!$enabled) {
    echo json_encode(['ok'=>false,'msg'=>'Auto-fines are disabled in Settings.']); exit;
}

$monthDate  = $month . '-01';
$monthLabel = date('F Y', strtotime($monthDate));

// Get all active members
$members = $pdo->query("SELECT id, full_name, joined_date, created_at FROM users WHERE role='member' AND status='active'")->fetchAll();

$fined = []; $skipped = [];

foreach ($members as $m) {
    $uid = $m['id'];

    // Skip if member hadn't joined yet during this month (auto: uses chama start date too)
    $memberStart = getMemberStartDate($m);
    if ($memberStart > $monthDate) {
        $skipped[] = $m['full_name'] . ' (not yet a member)';
        continue;
    }

    // Already has an active (non-waived) fine this month?
    $alreadyFined = $pdo->prepare("
        SELECT id FROM member_fines
        WHERE user_id=? AND fine_type='late_contribution'
        AND DATE_FORMAT(month,'%Y-%m')=?
        AND status != 'waived'
    ");
    $alreadyFined->execute([$uid, $month]);
    if ($alreadyFined->fetch()) { $skipped[] = $m['full_name']; continue; }

    // Did they pay this month?
    $paid = $pdo->prepare("
        SELECT COUNT(*) FROM contributions
        WHERE user_id=? AND status='confirmed'
        AND DATE_FORMAT(payment_month,'%Y-%m')=?
    ");
    $paid->execute([$uid, $month]);
    if ((int)$paid->fetchColumn() > 0) { $skipped[] = $m['full_name']; continue; }

    // Apply fine
    $pdo->prepare("
        INSERT INTO member_fines (user_id, fine_type, amount, month, description, created_by)
        VALUES (?, 'late_contribution', ?, ?, ?, ?)
    ")->execute([$uid, $fineAmount, $monthDate, "Auto: did not contribute for {$monthLabel}", (int)$_SESSION['user_id']]);

    $fineId = (int)$pdo->lastInsertId();

    // Debit wallet
    $wallet = new Wallet($pdo);
    $wallet->debit($uid, $fineAmount, "Late contribution fine — {$monthLabel}", 'fine', $fineId);

    // Notify member
    createNotification($uid, '⚠️ Late Contribution Fine — ' . getSetting('currency','KES') . ' ' . number_format($fineAmount,0),
        "You have been fined for not contributing in {$monthLabel}. Fine: KES " . number_format($fineAmount,0),
        'warning', APP_URL . '/member/contributions.php');

    $fined[] = $m['full_name'];
}

logActivity('AUTO_FINE_RUN', "Month: {$monthLabel}, Fined: " . count($fined) . ", Skipped: " . count($skipped));

echo json_encode([
    'ok'      => true,
    'month'   => $monthLabel,
    'fined'   => count($fined),
    'skipped' => count($skipped),
    'names'   => $fined,
    'amount'  => $fineAmount,
]);
