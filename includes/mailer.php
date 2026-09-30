<?php
/**
 * ChamaLedger — Email Service (powered by bundled PHPMailer)
 *
 * Config in config/db.php:
 *   MAIL_HOST, MAIL_PORT, MAIL_USER, MAIL_PASS, MAIL_FROM, MAIL_FROM_NAME
 *
 * Gmail setup:
 *   1. Enable 2-Step Verification on your Google account
 *   2. Go to myaccount.google.com → Security → App Passwords
 *   3. Generate an App Password for "Mail"
 *   4. Paste it as MAIL_PASS (with or without spaces)
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/SMTP.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';

class Mailer {

    private string $host;
    private int    $port;
    private string $user;
    private string $pass;
    private string $from;
    private string $fromName;
    private bool   $useSMTP;

    public function __construct() {
        $this->host     = defined('MAIL_HOST')      ? MAIL_HOST      : '';
        $this->port     = defined('MAIL_PORT')      ? (int)MAIL_PORT : 587;
        $this->user     = defined('MAIL_USER')      ? MAIL_USER      : '';
        $this->pass     = defined('MAIL_PASS')      ? MAIL_PASS      : '';
        $this->from     = defined('MAIL_FROM')      ? MAIL_FROM      : '';
        $this->fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'ChamaLedger';
        $this->useSMTP  = !empty($this->host) && !empty($this->user) && !empty($this->pass)
                          && !str_contains($this->user, 'your@');
    }

    /**
     * Send an email — returns true on success, false on failure (never throws)
     */
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
        // Skip if not configured
        if (empty($this->from) || str_contains($this->from, 'your@') || empty($this->user) || str_contains($this->user, 'your@')) {
            error_log('[ChamaLedger Mailer] Not configured — set MAIL_USER, MAIL_PASS, MAIL_FROM in config/db.php');
            return false;
        }

        try {
            $mail = new PHPMailer(true);

            if ($this->useSMTP) {
                $mail->isSMTP();
                $mail->Host        = $this->host;
                $mail->SMTPAuth    = true;
                $mail->Username    = $this->user;
                $mail->Password    = str_replace(' ', '', $this->pass); // strip spaces from App Password
                $mail->Port        = $this->port;
                $mail->SMTPSecure  = ($this->port === 465)
                    ? PHPMailer::ENCRYPTION_SMTPS
                    : PHPMailer::ENCRYPTION_STARTTLS;
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                        'allow_self_signed' => true,
                    ]
                ];
                $mail->SMTPAutoTLS = ($this->port === 587);
            } else {
                $mail->isMail();
            }

            $mail->CharSet  = 'UTF-8';
            $mail->Encoding = '8bit';
            $mail->setFrom($this->from, $this->fromName);
            $mail->addAddress($toEmail, $toName);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '</p>','</div>'], "\n", $htmlBody));

            $mail->send();
            return true;

        } catch (PHPMailerException $e) {
            error_log('[ChamaLedger Mailer] PHPMailer error: ' . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log('[ChamaLedger Mailer] Unexpected error: ' . $e->getMessage());
            return false;
        }
    }

    // ── Pre-built templates ───────────────────────────────────────────────────

    public function sendPaymentConfirmed(array $user, float $amount, string $month, string $receipt = ''): bool {
        $curr = defined('getSetting') ? getSetting('currency','KES') : 'KES';
        try { $curr = getSetting('currency','KES'); } catch(\Exception $e){}
        return $this->send($user['email'], $user['full_name'],
            '✅ Payment Confirmed — ' . date('F Y', strtotime($month)),
            $this->template('Payment Confirmed', $user['full_name'], "
                <p>Your contribution of <strong>" . number_format($amount, 2) . " {$curr}</strong>
                for <strong>" . date('F Y', strtotime($month)) . "</strong> has been received and confirmed.</p>
                " . ($receipt ? "<p>M-Pesa Receipt: <strong style='font-family:monospace;letter-spacing:.05em;color:#00c471'>{$receipt}</strong></p>" : "") . "
                <p>Thank you for staying on track with your savings!</p>
            ", 'green', '💰')
        );
    }

    public function sendLoanApproved(array $user, float $amount): bool {
        try { $curr = getSetting('currency','KES'); } catch(\Exception $e){ $curr = 'KES'; }
        return $this->send($user['email'], $user['full_name'], '✅ Loan Approved — ChamaLedger',
            $this->template('Loan Approved', $user['full_name'], "
                <p>Great news! Your loan of <strong>" . number_format($amount, 2) . " {$curr}</strong> has been <strong>approved</strong>.</p>
                <p>Please contact your admin for disbursement details.</p>
            ", 'green', '🎉')
        );
    }

    public function sendLoanRejected(array $user, string $reason = ''): bool {
        return $this->send($user['email'], $user['full_name'], 'Loan Application Update — ChamaLedger',
            $this->template('Loan Application', $user['full_name'], "
                <p>Unfortunately your loan application has not been approved at this time.</p>
                " . ($reason ? "<p>Reason: <em>{$reason}</em></p>" : "") . "
                <p>You may reapply after improving your savings record.</p>
            ", 'amber', '📋')
        );
    }

    public function sendWelcome(array $user): bool {
        return $this->send($user['email'], $user['full_name'], '👋 Welcome to ChamaLedger',
            $this->template('Welcome!', $user['full_name'], "
                <p>Your account has been created and is pending admin approval.</p>
                <p>You will receive an email once your account is activated.</p>
                <p><a href='" . APP_URL . "' style='color:#00c471'>" . APP_URL . "</a></p>
            ", 'green', '👋')
        );
    }


    public function sendAccountApproved(array $user): bool {
        return $this->send($user['email'], $user['full_name'], '✅ Account Approved — ChamaLedger',
            $this->template('Account Approved', $user['full_name'], "
                <p>Your ChamaLedger account has been <strong>approved</strong>! You can now log in.</p>
                <p style='margin-top:1.25rem'>
                    <a href='" . APP_URL . "/index.php' style='display:inline-block;background:#00c471;color:#060e1a;padding:.65rem 1.75rem;border-radius:8px;text-decoration:none;font-weight:700'>Login Now →</a>
                </p>
            ", 'green', '✅')
        );
    }

    public function sendPasswordReset(array $user, string $resetLink): bool {
        return $this->send($user['email'], $user['full_name'], '🔐 Password Reset — ChamaLedger',
            $this->template('Reset Your Password', $user['full_name'], "
                <p>We received a request to reset your password. Click the button below — this link <strong>expires in 30 minutes</strong>.</p>
                <p style='margin-top:1.25rem'>
                    <a href='{$resetLink}' style='display:inline-block;background:#00c471;color:#060e1a;padding:.65rem 1.75rem;border-radius:8px;text-decoration:none;font-weight:700'>Reset Password →</a>
                </p>
                <p style='color:#6b87a8;font-size:.82rem;margin-top:1.25rem'>If you did not request this, you can safely ignore this email.</p>
            ", 'blue', '🔐')
        );
    }

    public function sendMissedPayment(array $user, string $month): bool {
        return $this->send($user['email'], $user['full_name'],
            '⚠️ Missed Contribution — ' . date('F Y', strtotime($month)),
            $this->template('Missed Contribution', $user['full_name'], "
                <p>Your contribution for <strong>" . date('F Y', strtotime($month)) . "</strong> has not been recorded yet.</p>
                <p>Please make your payment as soon as possible to stay in good standing.</p>
                <p style='margin-top:1.25rem'>
                    <a href='" . APP_URL . "/member/contributions.php' style='display:inline-block;background:#00c471;color:#060e1a;padding:.65rem 1.75rem;border-radius:8px;text-decoration:none;font-weight:700'>Pay Now →</a>
                </p>
            ", 'amber', '⚠️')
        );
    }

    // ── Base HTML template ────────────────────────────────────────────────────

    private function template(string $title, string $name, string $content, string $color, string $icon): string {
        $accents = ['green'=>'#00c471','blue'=>'#3b82f6','amber'=>'#f59e0b','red'=>'#ef4444'];
        $accent  = $accents[$color] ?? '#00c471';
        $appName = defined('APP_NAME') ? APP_NAME : 'ChamaLedger';
        $year    = date('Y');
        return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>{$title}</title></head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;padding:2rem 1rem">
<tr><td align="center">
<table width="100%" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08)">
  <tr><td style="background:#060e1a;padding:1.75rem 2rem;text-align:center">
    <span style="font-size:1.25rem;font-weight:800;color:#ffffff">🏦 {$appName}</span>
  </td></tr>
  <tr><td style="background:linear-gradient(135deg,#0d1f38,#0b1829);padding:2rem;text-align:center">
    <div style="font-size:3rem;margin-bottom:.75rem">{$icon}</div>
    <h1 style="margin:0;color:#ffffff;font-size:1.3rem;font-weight:800">{$title}</h1>
  </td></tr>
  <tr><td style="padding:2rem">
    <p style="margin:0 0 1rem;color:#374151;font-size:.95rem">Hi <strong>{$name}</strong>,</p>
    <div style="color:#374151;font-size:.9rem;line-height:1.75">{$content}</div>
  </td></tr>
  <tr><td style="background:#f9fafb;padding:1.25rem 2rem;text-align:center;border-top:1px solid #e5e7eb">
    <p style="margin:0;color:#9ca3af;font-size:.78rem">{$appName} · Automated message · © {$year}</p>
  </td></tr>
</table>
</td></tr></table>
</body></html>
HTML;
    }
}

/**
 * Global helper used by functions.php email wrappers
 */
function sendEmail(string $toEmail, string $toName, string $subject, string $html): bool {
    $mailer = new Mailer();
    return $mailer->send($toEmail, $toName, $subject, $html);
}
