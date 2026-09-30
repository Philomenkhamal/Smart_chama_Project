<?php
/**
 * ChamaLedger — M-Pesa Connection Test
 * Visit: /api/mpesa_test.php to diagnose issues
 * DELETE THIS FILE after testing!
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
require_once ROOT . '/includes/mpesa.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: text/html; charset=utf-8');

function row($label, $value, $ok = null) {
    $color = $ok === null ? '#6b87a8' : ($ok ? '#00c471' : '#ef4444');
    $icon  = $ok === null ? '●' : ($ok ? '✅' : '❌');
    echo "<tr><td style='padding:.5rem 1rem;color:#6b87a8'>$icon $label</td><td style='padding:.5rem 1rem;color:$color;font-family:monospace'>$value</td></tr>";
}

?>
<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>M-Pesa Test — ChamaLedger</title>
<style>
body{font-family:sans-serif;background:#060e1a;color:#e2eaf4;padding:2rem;max-width:700px;margin:0 auto}
h2{color:#00c471;margin-bottom:1.5rem}
table{width:100%;border-collapse:collapse;background:#0d1f38;border-radius:12px;overflow:hidden;margin-bottom:1.5rem}
th{background:#0b1829;padding:.75rem 1rem;text-align:left;color:#6b87a8;font-size:.8rem;text-transform:uppercase;letter-spacing:.05em}
.warn{background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:8px;padding:1rem;color:#fcd34d;margin-top:1rem;font-size:.875rem}
</style></head><body>
<h2>🔧 M-Pesa Diagnostics</h2>

<table>
<tr><th colspan="2">Configuration</th></tr>
<?php
row('Environment',    MPESA_ENV);
row('Consumer Key',   substr(MPESA_CONSUMER_KEY,0,8).'...',    strlen(MPESA_CONSUMER_KEY) > 10);
row('Consumer Secret',substr(MPESA_CONSUMER_SECRET,0,8).'...', strlen(MPESA_CONSUMER_SECRET) > 10);
row('Shortcode',      MPESA_SHORTCODE,                          !empty(MPESA_SHORTCODE));
row('Callback URL',   MPESA_CALLBACK_URL,                       str_starts_with(MPESA_CALLBACK_URL,'https'));
row('APP_URL',        APP_URL);
?>
</table>

<table>
<tr><th colspan="2">Access Token Test</th></tr>
<?php
$mpesa = new Mpesa();
$token = $mpesa->getAccessToken();
if ($token) {
    row('Access Token', substr($token,0,20).'...', true);
} else {
    row('Access Token', 'FAILED — check Consumer Key & Secret', false);
}
?>
</table>

<?php if ($token): ?>
<table>
<tr><th colspan="2">STK Push Test (sandbox phone)</th></tr>
<?php
$result = $mpesa->stkPush('254708374149', 1, 'TEST', 'Test');
row('STK Push', $result['success'] ? 'SUCCESS' : 'FAILED: '.$result['message'], $result['success']);
if ($result['success']) {
    row('Checkout ID', $result['checkout_request_id'], true);
}
?>
</table>
<?php endif; ?>

<?php if (!str_starts_with(MPESA_CALLBACK_URL, 'https://') || str_contains(MPESA_CALLBACK_URL, 'localhost')): ?>
<div class="warn">⚠️ Your Callback URL must start with https:// and be publicly accessible (not localhost). Use your ngrok URL.</div>
<?php endif; ?>

<div class="warn" style="color:#fca5a5;border-color:rgba(239,68,68,0.3);background:rgba(239,68,68,0.08)">
⚠️ Delete this file after testing! It exposes your M-Pesa credentials.
</div>
</body></html>
