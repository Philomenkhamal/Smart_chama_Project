<?php
/**
 * ChamaLedger — M-Pesa STK Push for Loan Repayments
 * POST /api/mpesa_repay_stk.php
 * { csrf_token, phone, amount, loan_id }
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
require_once ROOT . '/includes/mpesa.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');
startSession();

if (!isLoggedIn()) { echo json_encode(['success'=>false,'message'=>'Not authenticated']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
if (!verifyCsrf($input['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid token']); exit; }

$userId = (int)$_SESSION['user_id'];
$user   = currentUser();
$pdo    = getDB();

$phone  = sanitize($input['phone']  ?? $user['phone'] ?? '');
$amount = (float)($input['amount']  ?? 0);
$loanId = (int)($input['loan_id']   ?? 0);

// Validate loan belongs to this user
$stmt = $pdo->prepare("SELECT * FROM loans WHERE id=? AND user_id=? AND status IN ('approved','disbursed')");
$stmt->execute([$loanId, $userId]);
$loan = $stmt->fetch();

if (!$loan)         { echo json_encode(['success'=>false,'message'=>'Invalid loan.']); exit; }
if ($amount <= 0)   { echo json_encode(['success'=>false,'message'=>'Amount must be greater than 0.']); exit; }
if ($amount > (float)$loan['balance']) {
    echo json_encode(['success'=>false,'message'=>'Amount exceeds loan balance of ' . money($loan['balance'], getSetting('currency','KES'))]); exit;
}
if (empty($phone))  { echo json_encode(['success'=>false,'message'=>'Phone number required.']); exit; }

$formattedPhone = Mpesa::formatPhone($phone);
if (!preg_match('/^2547\d{8}$/', $formattedPhone)) {
    echo json_encode(['success'=>false,'message'=>'Invalid phone. Use format: 0712345678']); exit;
}

// Check no pending repayment STK in flight
$pending = $pdo->prepare("SELECT id FROM mpesa_transactions WHERE user_id=? AND loan_id=? AND status='pending' AND initiated_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
$pending->execute([$userId, $loanId]);
if ($pending->fetch()) {
    echo json_encode(['success'=>false,'message'=>'A repayment request is already in progress.','can_clear'=>true]); exit;
}

$mpesa    = new Mpesa();
$curr     = getSetting('currency','KES');
$accRef   = 'RPY-' . ($loan['loan_number'] ?? $loanId);
$desc     = 'Loan Repayment';

$result = $mpesa->stkPush($formattedPhone, $amount, $accRef, $desc);

if (!$result['success']) {
    echo json_encode(['success'=>false,'message'=>$result['message']]); exit;
}

// Save pending transaction with loan_id
$pdo->prepare("
    INSERT INTO mpesa_transactions (user_id, phone, amount, payment_month, loan_id, event_id, checkout_request_id, merchant_request_id, status)
    VALUES (?, ?, ?, ?, ?, NULL, ?, ?, 'pending')
")->execute([
    $userId, $formattedPhone, $amount,
    date('Y-m-01'), // payment_month placeholder
    $loanId,
    $result['checkout_request_id'],
    $result['merchant_request_id'],
]);

logActivity('MPESA_REPAY_STK', "Loan#{$loanId} STK Push {$curr}{$amount} to {$formattedPhone}", $userId);

echo json_encode([
    'success'             => true,
    'checkout_request_id' => $result['checkout_request_id'],
    'message'             => "Payment prompt sent to {$phone}. Enter your M-Pesa PIN.",
    'amount'              => $amount,
]);
