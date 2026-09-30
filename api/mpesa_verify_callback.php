<?php
/**
 * ChamaLedger — M-Pesa Transaction Status Callback
 * Safaricom posts the result of TransactionStatusQuery here.
 * We store the result so mpesa_verify.php can poll it.
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/config/db.php';

while (ob_get_level()) ob_end_clean();

// Ensure table exists
try {
    getDB()->exec("CREATE TABLE IF NOT EXISTS mpesa_verify_results (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        originator_conversation_id VARCHAR(100) DEFAULT NULL,
        transaction_id VARCHAR(50) DEFAULT NULL,
        result_code INT NOT NULL DEFAULT -1,
        amount DECIMAL(10,2) DEFAULT NULL,
        trans_date VARCHAR(20) DEFAULT NULL,
        raw_response TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_orig (originator_conversation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}
header('Content-Type: application/json');

// Read raw POST from Safaricom
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

// Log for debugging
$logFile = ROOT . '/logs/mpesa_verify_callback.log';
if (!is_dir(ROOT . '/logs')) @mkdir(ROOT . '/logs', 0755, true);
file_put_contents($logFile, date('Y-m-d H:i:s') . "\n" . $raw . "\n\n", FILE_APPEND);

// Extract result
$result = $data['Result'] ?? [];
$resultCode   = $result['ResultCode'] ?? -1;
$transactionId = $result['TransactionID'] ?? '';
$originatorConversationId = $result['OriginatorConversationID'] ?? '';

if ($resultCode == 0 && $transactionId) {
    // Parse result parameters
    $params = [];
    $items  = $result['ResultParameters']['ResultParameter'] ?? [];
    foreach ($items as $item) {
        $params[$item['Key']] = $item['Value'] ?? '';
    }

    $amount        = (float)($params['TransactionAmount'] ?? 0);
    $phone         = $params['InitiatorAccountCurrentBalance'] ?? '';
    $receiptNumber = $params['ReceiptNo'] ?? $transactionId;
    $transDate     = $params['TransactionDate'] ?? '';

    // Store result in DB for polling
    try {
        $pdo = getDB();
        $pdo->prepare("
            INSERT INTO mpesa_verify_results
                (originator_conversation_id, transaction_id, result_code, amount, trans_date, raw_response)
            VALUES (?, ?, 0, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                result_code = 0,
                amount      = VALUES(amount),
                trans_date  = VALUES(trans_date),
                raw_response = VALUES(raw_response),
                updated_at  = NOW()
        ")->execute([$originatorConversationId, $transactionId, $amount, $transDate, $raw]);
    } catch (\Exception $e) {
        // Table might not exist — fail silently, the polling will just time out
    }
} else {
    // Failed result — store failure code
    try {
        $pdo = getDB();
        $pdo->prepare("
            INSERT INTO mpesa_verify_results
                (originator_conversation_id, transaction_id, result_code, raw_response)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                result_code  = VALUES(result_code),
                raw_response = VALUES(raw_response),
                updated_at   = NOW()
        ")->execute([$originatorConversationId, $transactionId, $resultCode, $raw]);
    } catch (\Exception $e) {}
}

echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
