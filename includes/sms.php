<?php
/**
 * ChamaLedger — SMS Service via Africa's Talking
 * Docs: https://developers.africastalking.com/docs/sms/sending
 *
 * Set AT_USERNAME, AT_API_KEY, AT_SENDER_ID in config/db.php
 * Set SMS_ENABLED=1 to activate (0 = log only, don't send)
 */

class SMS {

    private static function formatPhone(string $phone): string {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0'))   $phone = '254' . substr($phone, 1);
        if (!str_starts_with($phone, '254')) $phone = '254' . $phone;
        return '+' . $phone;
    }

    /**
     * Send SMS to one or more recipients
     * $to: string (single phone) or array of phones
     */
    public static function send(string|array $to, string $message): array {
        $phones  = is_array($to) ? $to : [$to];
        $phones  = array_map([self::class, 'formatPhone'], $phones);
        $numbers = implode(',', $phones);

        error_log('[SMS] To: ' . $numbers . ' | Msg: ' . substr($message, 0, 80));

        if (SMS_ENABLED !== '1' && SMS_ENABLED !== true) {
            return ['success' => true, 'sent' => 0, 'message' => 'SMS disabled (SMS_ENABLED=0). Would have sent to: ' . $numbers];
        }

        if (!AT_API_KEY) {
            return ['success' => false, 'message' => 'Africa\'s Talking API key not configured.'];
        }

        $url  = AT_USERNAME === 'sandbox'
            ? 'https://api.sandbox.africastalking.com/version1/messaging'
            : 'https://api.africastalking.com/version1/messaging';

        $data = [
            'username' => AT_USERNAME,
            'to'       => $numbers,
            'message'  => $message,
        ];
        if (AT_SENDER_ID) $data['from'] = AT_SENDER_ID;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_HTTPHEADER     => [
                'apiKey: '    . AT_API_KEY,
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_SSL_VERIFYPEER => true,   // Always verify SSL for Africa's Talking
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        error_log('[SMS] HTTP ' . $httpCode . ' | ' . $response);

        if ($curlErr) return ['success' => false, 'message' => 'SMS error: ' . $curlErr];

        $result = json_decode($response, true);
        $smr    = $result['SMSMessageData'] ?? [];
        $sent   = 0;
        $failed = [];

        foreach ($smr['Recipients'] ?? [] as $r) {
            if (($r['statusCode'] ?? 0) === 101) $sent++;
            else $failed[] = $r['number'] . ': ' . ($r['status'] ?? 'unknown');
        }

        if ($sent > 0) {
            return ['success' => true, 'sent' => $sent, 'failed' => $failed, 'message' => "Sent {$sent} SMS"];
        }

        return ['success' => false, 'sent' => 0, 'failed' => $failed,
                'message' => $smr['Message'] ?? 'SMS send failed'];
    }

    // ── Pre-built message templates ──────────────────────────────────────────

    public static function contributionReminder(array $member, float $amount, string $monthLabel, string $pochiPhone): array {
        $name = explode(' ', $member['full_name'])[0];
        $msg  = "Dear {$name}, your {$monthLabel} chama contribution of KES " . number_format($amount, 0) .
                " is due. Pay via M-Pesa Pochi La Biashara to {$pochiPhone}. Login: " . APP_URL;
        return self::send($member['phone'], $msg);
    }

    public static function paymentConfirmed(array $member, float $amount, string $label, string $ref): array {
        $name = explode(' ', $member['full_name'])[0];
        $msg  = "Dear {$name}, KES " . number_format($amount, 0) .
                " received for {$label}. Ref: {$ref}. Thank you! View statement: " . APP_URL;
        return self::send($member['phone'], $msg);
    }

    public static function loanApproved(array $member, float $amount, string $loanNo): array {
        $name = explode(' ', $member['full_name'])[0];
        $msg  = "Dear {$name}, your loan {$loanNo} of KES " . number_format($amount, 0) .
                " has been approved. Login to view details: " . APP_URL;
        return self::send($member['phone'], $msg);
    }

    public static function loanRepaymentDue(array $member, float $balance, string $dueDate, string $loanNo): array {
        $name = explode(' ', $member['full_name'])[0];
        $msg  = "Dear {$name}, loan {$loanNo} balance KES " . number_format($balance, 0) .
                " is due by {$dueDate}. Make repayment at: " . APP_URL;
        return self::send($member['phone'], $msg);
    }

    public static function dividendNotice(array $member, float $amount, int $year): array {
        $name = explode(' ', $member['full_name'])[0];
        $msg  = "Dear {$name}, your {$year} chama dividend/interest share is KES " .
                number_format($amount, 0) . ". Login to view details: " . APP_URL;
        return self::send($member['phone'], $msg);
    }

    public static function eventReminder(array $member, string $eventTitle, float $suggested): array {
        $name = explode(' ', $member['full_name'])[0];
        $msg  = "Dear {$name}, reminder to contribute for: {$eventTitle}." .
                ($suggested > 0 ? " Suggested: KES " . number_format($suggested, 0) . "." : "") .
                " Pay at: " . APP_URL;
        return self::send($member['phone'], $msg);
    }

    public static function fineNotice(array $member, float $amount, string $reason): array {
        $name = explode(' ', $member['full_name'])[0];
        $msg  = "Dear {$name}, a fine of KES " . number_format($amount, 0) .
                " has been applied to your account: {$reason}. View details: " . APP_URL;
        return self::send($member['phone'], $msg);
    }

    /**
     * Bulk send to all active members
     * Returns summary: ['sent'=>n, 'failed'=>n]
     */
    public static function bulkSend(array $members, callable $messageBuilder): array {
        $sent = 0; $failed = 0;
        foreach ($members as $member) {
            $msg    = $messageBuilder($member);
            $result = self::send($member['phone'], $msg);
            $result['success'] ? $sent++ : $failed++;
            usleep(100000); // 100ms between sends to avoid rate limiting
        }
        return ['sent' => $sent, 'failed' => $failed];
    }
}
