<?php
/**
 * ChamaLedger — M-Pesa Callback Handler
 * Safaricom calls this URL after payment completes/fails
 * URL: /api/mpesa_callback.php
 *
 * IMPORTANT: This URL must be publicly accessible (HTTPS in production)
 * For local testing use: ngrok http 80
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
require_once ROOT . '/includes/mailer.php';

// Always respond 200 to Safaricom immediately
http_response_code(200);
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

// Read callback payload
$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

// Log everything for debugging
error_log('[MPESA CALLBACK] ' . $raw);

if (empty($payload)) exit;

$body = $payload['Body']['stkCallback'] ?? null;
if (!$body) exit;

$checkoutId  = $body['CheckoutRequestID']  ?? '';
$merchantId  = $body['MerchantRequestID']  ?? '';
$resultCode  = (int)($body['ResultCode']   ?? -1);
$resultDesc  = $body['ResultDesc']         ?? '';

$pdo = getDB();

// Find the pending transaction
$stmt = $pdo->prepare("SELECT * FROM mpesa_transactions WHERE checkout_request_id = ? LIMIT 1");
$stmt->execute([$checkoutId]);
$tx = $stmt->fetch();

if (!$tx) {
    error_log('[MPESA CALLBACK] Unknown CheckoutRequestID: ' . $checkoutId);
    exit;
}

if ($resultCode === 0) {
    // ── PAYMENT SUCCESSFUL ──────────────────────────────────────────────────

    // Extract metadata
    $items       = $body['CallbackMetadata']['Item'] ?? [];
    $meta        = [];
    foreach ($items as $item) {
        $meta[$item['Name']] = $item['Value'] ?? null;
    }

    $mpesaReceipt = $meta['MpesaReceiptNumber'] ?? '';
    $amount       = (float)($meta['Amount']     ?? $tx['amount']);
    $phone        = $meta['PhoneNumber']         ?? $tx['phone'];
    $txDate       = $meta['TransactionDate']     ?? null;

    // Update transaction record
    $pdo->prepare("
        UPDATE mpesa_transactions
        SET status='completed', mpesa_receipt=?, result_code=0, result_desc=?,
            amount=?, completed_at=NOW()
        WHERE checkout_request_id=?
    ")->execute([$mpesaReceipt, $resultDesc, $amount, $checkoutId]);

    // Get user info
    $currency = getSetting('currency', 'KES');
    $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $userStmt->execute([$tx['user_id']]);
    $user = $userStmt->fetch();

    $isEventPayment   = !empty($tx['event_id']);
    $isDisbursement   = !empty($tx['loan_id']) && !$isEventPayment && str_starts_with($tx['merchant_request_id'] ?? '', 'DISB-');
    // Use checkout_request_id prefix isn't reliable — use a simpler heuristic:
    // If the loan is still 'approved' when callback fires, it's a disbursement
    $loanForCheck = null;
    if (!empty($tx['loan_id']) && !$isEventPayment) {
        $lc = $pdo->prepare("SELECT status FROM loans WHERE id=?"); $lc->execute([$tx['loan_id']]); $loanForCheck = $lc->fetch();
    }
    $isDisbursement  = !empty($tx['loan_id']) && !$isEventPayment && ($loanForCheck['status'] ?? '') === 'approved';
    $isLoanRepayment = !empty($tx['loan_id']) && !$isEventPayment && !$isDisbursement;

    if ($isDisbursement) {
        // ── LOAN DISBURSEMENT ───────────────────────────────────────────────
        $loanId  = (int)$tx['loan_id'];
        $loanRow = $pdo->prepare("SELECT * FROM loans WHERE id=?"); $loanRow->execute([$loanId]); $loanRow = $loanRow->fetch();

        if ($loanRow) {
            // Mark loan as disbursed
            $pdo->prepare("
                UPDATE loans SET status='disbursed', disbursed_at=NOW(),
                    disbursement_method='mpesa', disbursement_ref=?
                WHERE id=?
            ")->execute([$mpesaReceipt, $loanId]);

            // Notify member
            createNotification($tx['user_id'], '💰 Loan Disbursed — ' . $mpesaReceipt,
                "Your loan {$loanRow['loan_number']} of {$currency} " . number_format($amount,2) . " has been sent to your M-Pesa ({$tx['phone']}). Ref: {$mpesaReceipt}",
                'success', APP_URL . '/member/my_loans.php');

            // Notify admins
            $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll();
            foreach ($admins as $admin) {
                createNotification($admin['id'], '✅ Loan Disbursed — ' . ($user['full_name']??'Member'),
                    "Loan {$loanRow['loan_number']} disbursed {$currency} " . number_format($amount,2) . " via M-Pesa ({$mpesaReceipt})",
                    'info', APP_URL . '/admin/loans.php');
            }

            // SMS
            try { SMS::send($tx['phone'], "Dear " . explode(' ',$user['full_name']??'Member')[0] . ", KES " . number_format($amount,0) . " loan disbursement sent to your M-Pesa. Ref: {$mpesaReceipt}. Repay at: " . APP_URL); } catch(Exception $e) {}

            logActivity('LOAN_DISBURSED_MPESA', "Loan#{$loanId} receipt:{$mpesaReceipt} amount:{$amount}", $tx['user_id']);
        }

    } elseif ($isLoanRepayment) {
        // ── LOAN REPAYMENT ──────────────────────────────────────────────────
        $loanId  = (int)$tx['loan_id'];
        $loanRow = $pdo->prepare("SELECT * FROM loans WHERE id=?"); $loanRow->execute([$loanId]); $loanRow = $loanRow->fetch();

        if ($loanRow) {
            // Insert confirmed repayment
            $pdo->prepare("
                INSERT INTO loan_payments (loan_id, user_id, amount, payment_method, reference_code, status, confirmed_by, confirmed_at)
                VALUES (?,?,'mpesa',?,'confirmed', NULL, NOW())
            ")->execute([$loanId, $tx['user_id'], $amount, $mpesaReceipt]);
            $payId = (int)$pdo->lastInsertId();

            // Recalculate loan
            $newRepaid  = (float)$loanRow['amount_repaid'] + $amount;
            $newBalance = max(0, (float)$loanRow['balance'] - $amount);
            $newStatus  = $newBalance <= 0 ? 'completed' : $loanRow['status'];

            $pdo->prepare("UPDATE loans SET amount_repaid=?, balance=?, status=?" . ($newBalance<=0 ? ", completed_at=NOW()" : "") . " WHERE id=?")
                ->execute([$newRepaid, $newBalance, $newStatus, $loanId]);

            // Debit wallet (loan repayment reduces wallet balance)
            try {
                $wallet = new Wallet($pdo);
                $wallet->debit($tx['user_id'], $amount,
                    'Loan repayment — ' . ($loanRow['loan_number'] ?? $loanId) . ' (' . $mpesaReceipt . ')',
                    'loan_payment', $payId);
            } catch(Exception $e) { error_log('Wallet debit error: ' . $e->getMessage()); }

            // SMS confirmation
            try {
                SMS::paymentConfirmed($user, $amount, 'loan repayment (' . ($loanRow['loan_number']??'') . ')', $mpesaReceipt);
            } catch(Exception $e) {}

            $balMsg = $newBalance <= 0 ? '🎉 Loan fully repaid!' : "Remaining balance: {$currency} " . number_format($newBalance, 2);
            createNotification($tx['user_id'], '✅ Loan Repayment Confirmed — ' . $mpesaReceipt,
                "KES " . number_format($amount,2) . " received for loan {$loanRow['loan_number']}. {$balMsg}",
                'success', APP_URL . '/member/my_loans.php');

            $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll();
            foreach ($admins as $admin) {
                createNotification($admin['id'], '💰 Loan Repayment — ' . ($user['full_name']??'Member'),
                    ($user['full_name']??'Member') . " repaid {$currency} " . number_format($amount,2) . " on {$loanRow['loan_number']} via M-Pesa. Balance: {$currency} " . number_format($newBalance,2),
                    'info', APP_URL . '/admin/repayments.php');
            }
            logActivity('MPESA_LOAN_REPAYMENT', "Loan#{$loanId} receipt:{$mpesaReceipt} amount:{$amount}", $tx['user_id']);
        }

    } elseif ($isEventPayment) {
        // ── EVENT CONTRIBUTION ──────────────────────────────────────────────
        $eventId = (int)$tx['event_id'];
        $evRow   = $pdo->prepare("SELECT title FROM events WHERE id=?"); $evRow->execute([$eventId]); $evRow = $evRow->fetch();

        // Check for existing pending event contribution to update
        $existing = $pdo->prepare("SELECT id FROM event_contributions WHERE event_id=? AND user_id=? AND status='pending'");
        $existing->execute([$eventId, $tx['user_id']]);
        $ecRow = $existing->fetch();

        if ($ecRow) {
            $pdo->prepare("UPDATE event_contributions SET status='confirmed', reference_code=?, payment_method='mpesa', amount=?, confirmed_at=NOW() WHERE id=?")
                ->execute([$mpesaReceipt, $amount, $ecRow['id']]);
            $ecId = $ecRow['id'];
        } else {
            $pdo->prepare("INSERT INTO event_contributions (event_id,user_id,amount,payment_method,reference_code,status,confirmed_at) VALUES (?,?,?,'mpesa',?,'confirmed',NOW())")
                ->execute([$eventId, $tx['user_id'], $amount, $mpesaReceipt]);
            $ecId = $pdo->lastInsertId();
        }

        // Credit wallet
        try {
            $wallet = new Wallet($pdo);
            $wallet->credit($tx['user_id'], $amount,
                'Event: ' . ($evRow['title'] ?? 'Event') . ' (' . $mpesaReceipt . ')',
                'event_contribution', $ecId);
        } catch(Exception $e) { error_log('Wallet credit error: ' . $e->getMessage()); }

        createNotification($tx['user_id'], '✅ Event Payment Confirmed — ' . $mpesaReceipt,
            "Your M-Pesa payment of {$currency} " . number_format($amount,2) . " for event '" . ($evRow['title']??'') . "' has been confirmed.",
            'success', APP_URL . '/member/events.php');

        $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll();
        foreach ($admins as $admin) {
            createNotification($admin['id'], '💰 Event M-Pesa — ' . ($user['full_name']??'Member'),
                ($user['full_name']??'Member') . " paid {$currency} " . number_format($amount,2) . " for event '" . ($evRow['title']??'') . "' ({$mpesaReceipt})",
                'info', APP_URL . '/admin/events.php');
        }

        logActivity('MPESA_EVENT_PAYMENT', "Event #{$eventId} receipt: {$mpesaReceipt} amount: {$amount}", $tx['user_id']);

    } else {
        // ── MONTHLY CONTRIBUTION ────────────────────────────────────────────
        // Only merge into a still-pending manual submission for this month (not
        // an already-confirmed one) — members can make multiple payments per
        // month now, so each new payment should get its own record.
        $existing = $pdo->prepare("SELECT id FROM contributions WHERE user_id=? AND payment_month=? AND status='pending'");
        $existing->execute([$tx['user_id'], $tx['payment_month']]);
        $contrib = $existing->fetch();

        if ($contrib) {
            $pdo->prepare("UPDATE contributions SET status='confirmed', reference_code=?, payment_method='mpesa', amount=?, confirmed_at=NOW() WHERE id=?")
                ->execute([$mpesaReceipt, $amount, $contrib['id']]);
            $contribId = $contrib['id'];
        } else {
            $pdo->prepare("INSERT INTO contributions (user_id, amount, payment_month, payment_method, reference_code, status, confirmed_at) VALUES (?, ?, ?, 'mpesa', ?, 'confirmed', NOW())")
                ->execute([$tx['user_id'], $amount, $tx['payment_month'], $mpesaReceipt]);
            $contribId = $pdo->lastInsertId();
        }

        $pdo->prepare("UPDATE mpesa_transactions SET contribution_id=? WHERE checkout_request_id=?")
            ->execute([$contribId, $checkoutId]);

        $monthLabel = date('F Y', strtotime($tx['payment_month']));
        createNotification($tx['user_id'], '✅ Payment Confirmed — ' . $mpesaReceipt,
            "Your M-Pesa payment of {$currency} " . number_format($amount,2) . " for {$monthLabel} has been confirmed automatically.",
            'success', APP_URL . '/member/contributions.php');

        $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll();
        foreach ($admins as $admin) {
            createNotification($admin['id'], '💰 M-Pesa — ' . ($user['full_name']??'Member'),
                ($user['full_name']??'Member') . " paid {$currency} " . number_format($amount,2) . " via M-Pesa ({$mpesaReceipt}) for {$monthLabel}.",
                'info', APP_URL . '/admin/contributions.php');
        }

        try {
            $wallet = new Wallet($pdo);
            $wallet->credit($tx['user_id'], $amount,
                'M-Pesa — ' . date('F Y', strtotime($tx['payment_month'])) . ' (' . $mpesaReceipt . ')',
                'contribution', $contribId);
        } catch(Exception $e) { error_log('Wallet credit error: ' . $e->getMessage()); }

        logActivity('MPESA_PAYMENT_SUCCESS', "Receipt: {$mpesaReceipt}, Amount: {$amount}", $tx['user_id']);
        try {
            $mailer = new Mailer();
            $mailer->sendPaymentConfirmed($user, $amount, $tx['payment_month'], $mpesaReceipt);
        } catch (Exception $e) { error_log('[ChamaLedger] Email failed: ' . $e->getMessage()); }
    }

} else {
    // ── PAYMENT FAILED / CANCELLED ─────────────────────────────────────────
    $pdo->prepare("
        UPDATE mpesa_transactions
        SET status = CASE WHEN ? = 1032 THEN 'cancelled' ELSE 'failed' END,
            result_code=?, result_desc=?, completed_at=NOW()
        WHERE checkout_request_id=?
    ")->execute([$resultCode, $resultCode, $resultDesc, $checkoutId]);

    // Notify member of failure
    $failMsg = match($resultCode) {
        1032    => 'You cancelled the M-Pesa payment request.',
        1       => 'Insufficient M-Pesa balance.',
        2001    => 'Wrong M-Pesa PIN entered.',
        default => 'M-Pesa payment failed: ' . $resultDesc,
    };

    createNotification($tx['user_id'], '❌ Payment Failed', $failMsg, 'danger', APP_URL . '/member/contributions.php');
    logActivity('MPESA_PAYMENT_FAILED', "Code: {$resultCode}, Desc: {$resultDesc}", $tx['user_id']);
}
