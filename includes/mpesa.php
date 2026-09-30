<?php
/**
 * ChamaLedger — M-Pesa Daraja STK Push Service
 * Handles: Access token, STK Push initiation, callback processing
 *
 * Docs: https://developer.safaricom.co.ke/APIs/MpesaExpressSimulate
 */

class Mpesa {

    private string $baseUrl;
    private string $consumerKey;
    private string $consumerSecret;
    private string $shortcode;
    private string $passkey;
    private string $callbackUrl;

    public function __construct() {
        $this->baseUrl        = MPESA_ENV === 'live'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
        $this->consumerKey    = MPESA_CONSUMER_KEY;
        $this->consumerSecret = MPESA_CONSUMER_SECRET;
        $this->shortcode      = MPESA_SHORTCODE;
        $this->passkey        = MPESA_PASSKEY;
        $this->callbackUrl    = MPESA_CALLBACK_URL;
    }

    /**
     * Get OAuth access token from Safaricom
     */
    public function getAccessToken(): ?string {
        $credentials = base64_encode($this->consumerKey . ':' . $this->consumerSecret);

        $ch = curl_init($this->baseUrl . '/oauth/v1/generate?grant_type=client_credentials');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $credentials],
            CURLOPT_SSL_VERIFYPEER => (MPESA_ENV === 'sandbox') ? false : true,  // Disable only in sandbox
            CURLOPT_SSL_VERIFYHOST => (MPESA_ENV === 'sandbox') ? false : 2,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'ChamaLedger/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log('[MPESA] cURL error on token: ' . $curlErr);
            return null;
        }
        if ($httpCode !== 200 || !$response) {
            error_log('[MPESA] Token fetch failed. HTTP ' . $httpCode . ' | ' . $response);
            return null;
        }

        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }

    /**
     * Generate the STK Push password (base64 of shortcode+passkey+timestamp)
     */
    private function getPassword(string $timestamp): string {
        return base64_encode($this->shortcode . $this->passkey . $timestamp);
    }

    /**
     * Format phone: 0712345678 or +254712345678 → 254712345678
     */
    public static function formatPhone(string $phone): string {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0'))    $phone = '254' . substr($phone, 1);
        if (str_starts_with($phone, '+254')) $phone = substr($phone, 1);
        if (!str_starts_with($phone, '254')) $phone = '254' . $phone;
        return $phone;
    }

    /**
     * Initiate STK Push — sends prompt to member's phone
     *
     * Returns: ['success' => bool, 'checkout_request_id' => string, 'message' => string]
     */
    public function stkPush(string $phone, float $amount, string $accountRef, string $description): array {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Could not connect to M-Pesa. Try again.'];
        }

        $timestamp = date('YmdHis');
        $password  = $this->getPassword($timestamp);
        $phone     = self::formatPhone($phone);
        $amount    = (int)ceil($amount); // M-Pesa requires whole numbers

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => $amount,
            'PartyA'            => $phone,
            'PartyB'            => $this->shortcode,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => $this->callbackUrl,
            'AccountReference'  => substr($accountRef, 0, 12),
            'TransactionDesc'   => substr($description, 0, 13),
        ];

        $ch = curl_init($this->baseUrl . '/mpesa/stkpush/v1/processrequest');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => (MPESA_ENV === 'sandbox') ? false : true,  // Disable only in sandbox
            CURLOPT_SSL_VERIFYHOST => (MPESA_ENV === 'sandbox') ? false : 2,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'ChamaLedger/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        error_log('[MPESA STK] HTTP ' . $httpCode . ' | curl_err=' . $curlErr . ' | ' . $response);

        if ($curlErr) {
            return ['success' => false, 'message' => 'Connection failed: ' . $curlErr . '. Make sure your server can reach the internet.'];
        }
        if (!$response) {
            return ['success' => false, 'message' => 'No response from M-Pesa. Check your internet connection.'];
        }

        $data = json_decode($response, true);

        if ($httpCode === 200 && isset($data['ResponseCode']) && $data['ResponseCode'] === '0') {
            return [
                'success'             => true,
                'checkout_request_id' => $data['CheckoutRequestID'],
                'merchant_request_id' => $data['MerchantRequestID'],
                'message'             => 'STK Push sent successfully.',
            ];
        }

        // Parse Daraja error
        $errMsg = $data['errorMessage'] ?? $data['ResponseDescription'] ?? 'M-Pesa request failed.';
        return ['success' => false, 'message' => $errMsg];
    }

    /**
     * Query STK Push status (poll from frontend)
     */
    public function queryStatus(string $checkoutRequestId): array {
        $token = $this->getAccessToken();
        if (!$token) return ['success' => false, 'message' => 'Token error'];

        $timestamp = date('YmdHis');
        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $this->getPassword($timestamp),
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];

        $ch = curl_init($this->baseUrl . '/mpesa/stkpushquery/v1/query');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => (MPESA_ENV === 'sandbox') ? false : true,
            CURLOPT_SSL_VERIFYHOST => (MPESA_ENV === 'sandbox') ? false : 2,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'ChamaLedger/1.0',
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true) ?? [];
    }

    /**
     * Validate M-Pesa receipt format (client-side pre-check)
     * Format: exactly 10 uppercase alphanumeric chars, e.g. QHX7Y8Z9AB
     */
    public static function isValidReceiptFormat(string $code): bool {
        // Safaricom format: starts with letter, followed by 9 alphanumeric chars
        $code = strtoupper(trim($code));
        if (strlen($code) !== 10)              return false;
        if (!preg_match('/^[A-Z][A-Z0-9]{9}$/', $code)) return false;
        if (!preg_match('/[0-9]/', $code))     return false; // must have at least one digit
        return true;
    }

    /**
     * Verify a transaction receipt via Safaricom Transaction Status API
     * Returns: ['valid' => bool, 'amount' => float, 'phone' => string, 'date' => string, 'message' => string]
     */
    public function verifyTransaction(string $receipt, string $initiatorName, string $securityCredential): array {
        $token = $this->getAccessToken();
        if (!$token) return ['valid' => false, 'message' => 'Could not connect to M-Pesa.'];

        $callbackUrl = defined('APP_URL') ? APP_URL . '/api/mpesa_verify_callback.php' : $this->callbackUrl;

        $payload = [
            'Initiator'          => $initiatorName,
            'SecurityCredential' => $securityCredential,
            'CommandID'          => 'TransactionStatusQuery',
            'TransactionID'      => strtoupper(trim($receipt)),
            'PartyA'             => $this->shortcode,
            'IdentifierType'     => '4',
            'ResultURL'          => $callbackUrl,
            'QueueTimeOutURL'    => $callbackUrl,
            'Remarks'            => 'Verify',
            'Occasion'           => 'ChamaLedger',
        ];

        $ch = curl_init($this->baseUrl . '/mpesa/transactionstatus/v1/query');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => (MPESA_ENV === 'sandbox') ? false : true,
            CURLOPT_SSL_VERIFYHOST => (MPESA_ENV === 'sandbox') ? false : 2,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'ChamaLedger/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        error_log('[MPESA VERIFY] HTTP ' . $httpCode . ' | ' . $response);

        if ($curlErr || !$response) {
            return ['valid' => false, 'message' => 'Connection error: ' . $curlErr];
        }

        $data = json_decode($response, true);
        // ResponseCode 0 = request accepted (result comes via callback)
        if (isset($data['ResponseCode']) && $data['ResponseCode'] === '0') {
            return ['valid' => true, 'message' => 'Verification request accepted. Awaiting result.', 'queued' => true];
        }

        return ['valid' => false, 'message' => $data['errorMessage'] ?? $data['ResponseDescription'] ?? 'Verification failed.'];
    }

}