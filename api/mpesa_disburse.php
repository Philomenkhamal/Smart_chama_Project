<?php
/**
 * ChamaLedger — M-Pesa STK Push for Loan Disbursement
 * Admin triggers payment directly to member's phone
 * POST { csrf_token, loan_id, phone, amount }
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
require_once ROOT . '/includes/mpesa.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');
startSession();

if (!isLoggedIn() || ($_SESSION['user_role'] ?? $_SESSION['role'] ?? '') !== 'admin') { echo json_encode(['success'=>false,'message'=>'Not authorised']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
if (!verifyCsrf($input['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid token']); exit; }

$pdo    = getDB();
$adminId = (int)$_SESSION['user_id'];
$curr   = getSetting('currency', 'KES');

$loanId = (int)($input['loan_id'] ?? 0);
$phone  = sanitize($input['phone'] ?? '');
$amount = (float)($input['amount'] ?? 0);

// Validate loan
$stmt = $pdo->prepare("SELECT l.*, u.full_name, u.phone AS member_phone FROM loans l JOIN users u ON u.id=l.user_id WHERE l.id=? AND l.status='approved'");
$stmt->execute([$loanId]);
$loan = $stmt->fetch();

if (!$loan)       { echo json_encode(['success'=>false,'message'=>'Loan not found or not in approved state.']); exit; }
if ($amount <= 0) { echo json_encode(['success'=>false,'message'=>'Amount must be greater than 0.']); exit; }

$phone = $phone ?: $loan['member_phone'];
if (!$phone) { echo json_encode(['success'=>false,'message'=>'Member has no phone number on file. Enter it manually.']); exit; }

$formattedPhone = Mpesa::formatPhone($phone);
if (!preg_match('/^2547\d{8}$/', $formattedPhone)) {
    echo json_encode(['success'=>false,'message'=>'Invalid phone number. Use format: 0712345678']); exit;
}

// Block duplicate in-flight STK
$pending = $pdo->prepare("SELECT id FROM mpesa_transactions WHERE loan_id=? AND status='pending' AND initiated_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
$pending->execute([$loanId]);
if ($pending->fetch()) {
    echo json_encode(['success'=>false,'message'=>'A disbursement request is already in progress for this loan.']); exit;
}

$mpesa   = new Mpesa();
$accRef  = 'DISB-' . ($loan['loan_number'] ?? $loanId);
$desc    = 'Loan Disbursement';

$result = $mpesa->stkPush($formattedPhone, $amount, $accRef, $desc);

if (!$result['success']) {
    echo json_encode(['success'=>false,'message'=>$result['message']]); exit;
}

// Log as pending transaction — loan_id set, negative type to indicate outgoing
$pdo->prepare("
    INSERT INTO mpesa_transactions (user_id, phone, amount, payment_month, loan_id, checkout_request_id, merchant_request_id, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
")->execute([
    $loan['user_id'], $formattedPhone, $amount,
    date('Y-m-01'),
    $loanId,
    $result['checkout_request_id'],
    $result['merchant_request_id'],
]);

logActivity('LOAN_DISBURSE_STK', "Loan#{$loanId} STK Push {$curr}{$amount} to {$formattedPhone}", $adminId);

echo json_encode([
    'success'             => true,
    'checkout_request_id' => $result['checkout_request_id'],
    'message'             => "STK Push sent to {$phone}. Member should enter their M-Pesa PIN.",
]);
