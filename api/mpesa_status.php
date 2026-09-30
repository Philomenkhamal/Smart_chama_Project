<?php
/**
 * ChamaLedger — Poll M-Pesa Transaction Status
 * GET /api/mpesa_status.php?checkout_id=xxx
 * Called every 3 seconds from frontend after STK Push
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');
startSession();

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['status' => 'error']); exit; }

$checkoutId = sanitize($_GET['checkout_id'] ?? '');
if (!$checkoutId) { echo json_encode(['status' => 'error', 'message' => 'Missing ID']); exit; }

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM mpesa_transactions WHERE checkout_request_id=? AND user_id=? LIMIT 1");
$stmt->execute([$checkoutId, $userId]);
$tx = $stmt->fetch();

if (!$tx) { echo json_encode(['status' => 'error', 'message' => 'Transaction not found']); exit; }

// If still pending and >90s old — mark as timed out
if ($tx['status'] === 'pending') {
    $age = time() - strtotime($tx['initiated_at']);
    if ($age > 90) {
        $pdo->prepare("UPDATE mpesa_transactions SET status='failed', result_desc='Timed out' WHERE id=?")
            ->execute([$tx['id']]);
        echo json_encode(['status' => 'failed', 'message' => 'Payment timed out. Please try again.']);
        exit;
    }
}

$messages = [
    'completed' => '✅ Payment confirmed! Receipt: ' . ($tx['mpesa_receipt'] ?? ''),
    'failed'    => '❌ ' . ($tx['result_desc'] ?? 'Payment failed. Please try again.'),
    'cancelled' => '⚠️ Payment was cancelled.',
    'pending'   => 'Waiting for M-Pesa confirmation…',
];

echo json_encode([
    'status'   => $tx['status'],
    'message'  => $messages[$tx['status']] ?? 'Unknown status',
    'receipt'  => $tx['mpesa_receipt'],
    'amount'   => $tx['amount'],
]);
