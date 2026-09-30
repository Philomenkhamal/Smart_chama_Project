<?php
/**
 * ChamaLedger — Email Test Endpoint (admin only)
 * Visit: /api/mail_test.php?to=youremail@gmail.com
 * DELETE THIS FILE before going live.
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
require_once ROOT . '/includes/mailer.php';
requireAdmin();

while (ob_get_level()) ob_end_clean();
header('Content-Type: text/plain; charset=utf-8');

$to = trim($_GET['to'] ?? '');
if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo "Usage: /api/mail_test.php?to=youremail@gmail.com\n";
    exit;
}

echo "=== ChamaLedger Email Test ===\n\n";
echo "MAIL_HOST : " . (defined('MAIL_HOST') ? MAIL_HOST : 'NOT SET') . "\n";
echo "MAIL_PORT : " . (defined('MAIL_PORT') ? MAIL_PORT : 'NOT SET') . "\n";
echo "MAIL_USER : " . (defined('MAIL_USER') ? MAIL_USER : 'NOT SET') . "\n";
echo "MAIL_PASS : " . (defined('MAIL_PASS') && MAIL_PASS && !str_contains(MAIL_PASS,'xxxx') ? str_repeat('*', 8) . ' (set)' : 'NOT SET') . "\n";
echo "MAIL_FROM : " . (defined('MAIL_FROM') ? MAIL_FROM : 'NOT SET') . "\n\n";

echo "Sending test email to: $to ...\n";

$mailer = new Mailer();
$result = $mailer->send($to, 'Test User', '✅ ChamaLedger Email Test', "
    <div style='font-family:Arial,sans-serif;padding:2rem;background:#f0f4f8;border-radius:12px'>
        <h2 style='color:#00c471'>✅ Email is working!</h2>
        <p>If you received this, your ChamaLedger email configuration is correct.</p>
        <p style='color:#888;font-size:.85rem'>Sent at: " . date('Y-m-d H:i:s') . "</p>
    </div>
");

if ($result) {
    echo "✅ SUCCESS — Email sent! Check your inbox (and spam folder).\n";
} else {
    echo "❌ FAILED — Check your PHP error log for details.\n";
    echo "   Likely cause: MAIL_USER / MAIL_PASS not set in config/db.php\n";
    echo "   Or: Gmail App Password not generated correctly\n";
}
