<?php
/**
 * Recalculate a member's wallet from scratch
 * POST /api/wallet_recalc.php — admin only
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
$userId = (int)($_POST['user_id'] ?? 0);
if (!$userId) { echo json_encode(['ok'=>false,'msg'=>'No member specified']); exit; }

$balance = Wallet::recalculate(getDB(), $userId);
echo json_encode(['ok'=>true, 'balance'=>$balance, 'formatted'=> ($balance >= 0 ? '+' : '') . number_format($balance,2)]);
