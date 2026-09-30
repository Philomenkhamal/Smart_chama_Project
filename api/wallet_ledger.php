<?php
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
startSession();
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');
if (!isLoggedIn()) { echo json_encode(['ok'=>false]); exit; }

$pdo    = getDB();
$userId = (int)($_GET['user_id'] ?? $_SESSION['user_id']);
$role   = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'member';

// Members can only see their own ledger
if ($role !== 'admin') $userId = (int)$_SESSION['user_id'];

$wallet  = new Wallet($pdo);
$entries = $wallet->getLedger($userId, 50);
echo json_encode(['ok'=>true, 'entries'=>$entries, 'balance'=>$wallet->getBalance($userId)]);
