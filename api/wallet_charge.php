<?php
/**
 * Apply monthly contribution charge to all members
 * POST /api/wallet_charge.php — admin only
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
startSession();
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');
if (!isLoggedIn() || (($_SESSION['user_role'] ?? $_SESSION['role'] ?? '') !== 'admin')) {
    echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
}
if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit;
}
$month  = sanitize($_POST['month'] ?? date('Y-m'));
$result = Wallet::applyMonthlyCharges(getDB(), $month);
logActivity('WALLET_MONTHLY_CHARGE', "Applied charges for {$result['month']}: {$result['charged']} members");
echo json_encode(['ok'=>true] + $result);
