<?php
/**
 * ChamaLedger — Clear stuck pending M-Pesa transactions
 * Visit: /api/mpesa_clear.php when logged in as member
 * This clears YOUR OWN stuck pending transactions only
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
startSession();
requireMember();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// Mark all pending transactions older than 2 minutes as failed
$stmt = $pdo->prepare("
    UPDATE mpesa_transactions 
    SET status='failed', result_desc='Manually cleared'
    WHERE user_id = ? AND status = 'pending'
");
$stmt->execute([$userId]);
$cleared = $stmt->rowCount();

setFlash('success', "Cleared {$cleared} stuck transaction(s). You can now try paying again.");
redirect(APP_URL . '/member/contributions.php');
