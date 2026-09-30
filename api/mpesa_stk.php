<?php
/**
 * ChamaLedger — M-Pesa STK Push Initiator
 * POST /api/mpesa_stk.php
 * Called via AJAX from member/contributions.php
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
require_once ROOT . '/includes/mpesa.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');
startSession();

// Auth check
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$csrf  = $input['csrf_token'] ?? '';

if (!verifyCsrf($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$user   = currentUser();
$pdo    = getDB();

// Validate input
$phone   = sanitize($input['phone']         ?? $user['phone'] ?? '');
$amount  = (float)($input['amount']         ?? 0);
$month   = sanitize($input['payment_month'] ?? '');
$eventId = (int)($input['event_id']         ?? 0);

if (empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'Phone number is required.']);
    exit;
}
if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0.']);
    exit;
}
if (empty($month) && !$eventId) {
    echo json_encode(['success' => false, 'message' => 'Payment month is required.']);
    exit;
}

if (!$eventId) {
    // Check no pending transaction in flight (prevents accidental double STK push)
    $pending = $pdo->prepare("SELECT id FROM mpesa_transactions WHERE user_id=? AND payment_month=? AND status='pending' AND initiated_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    $pending->execute([$userId, $month . '-01']);
    if ($pending->fetch()) {
        echo json_encode(['success' => false, 'message' => 'A payment request is already in progress.', 'can_clear' => true]);
        exit;
    }
} else {
    // Event contribution duplicate check
    $dup = $pdo->prepare("SELECT id FROM event_contributions WHERE event_id=? AND user_id=? AND status!='rejected'");
    $dup->execute([$eventId, $userId]);
    if ($dup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'You have already paid for this event.']);
        exit;
    }
    // Check event exists and is active
    $evChk = $pdo->prepare("SELECT id,title FROM events WHERE id=? AND status='active'");
    $evChk->execute([$eventId]);
    if (!$evChk->fetch()) {
        echo json_encode(['success' => false, 'message' => 'This event is no longer accepting payments.']);
        exit;
    }
    $month = date('Y-m'); // just for DB storage
}

// Format phone
$formattedPhone = Mpesa::formatPhone($phone);
if (!preg_match('/^2547\d{8}$/', $formattedPhone)) {
    echo json_encode(['success' => false, 'message' => 'Invalid phone number. Use format: 0712345678']);
    exit;
}

// Initiate STK Push
$mpesa      = new Mpesa();
$currency   = getSetting('currency', 'KES');
$memberName = $user['full_name'];
$accountRef = 'CHM-' . ($user['membership_number'] ?? $userId);
$desc       = $eventId ? 'Event Payment ' . $eventId : 'Contribution ' . date('MY', strtotime($month . '-01'));

$result = $mpesa->stkPush($formattedPhone, $amount, $accountRef, $desc);

if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => $result['message']]);
    exit;
}

// Save pending transaction
$pdo->prepare("
    INSERT INTO mpesa_transactions (user_id, phone, amount, payment_month, event_id, checkout_request_id, merchant_request_id, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
")->execute([
    $userId,
    $formattedPhone,
    $amount,
    $month . '-01',
    $eventId ?: null,
    $result['checkout_request_id'],
    $result['merchant_request_id'],
]);

logActivity('MPESA_STK_INITIATED', "STK Push sent to {$formattedPhone} for {$currency}{$amount}", $userId);

echo json_encode([
    'success'             => true,
    'checkout_request_id' => $result['checkout_request_id'],
    'message'             => "Payment prompt sent to {$phone}. Enter your M-Pesa PIN on your phone.",
    'phone'               => $phone,
    'amount'              => $amount,
]);
