<?php
/**
 * ChamaLedger — STK Push Diagnostic Tool
 * Visit this page as admin to diagnose exactly why STK is failing
 * DELETE THIS FILE after fixing the issue
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
require_once ROOT . '/includes/mpesa.php';
startSession();
if (!isLoggedIn() || (($_SESSION['user_role'] ?? $_SESSION['role'] ?? '') !== 'admin')) {
    die('Admin only.');
}
$pdo = getDB();

$checks = [];
$testResult = null;

// Run test STK if requested
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_phone'])) {
    $phone = preg_replace('/\D/', '', $_POST['test_phone']);
    if (str_starts_with($phone, '0')) $phone = '254' . substr($phone, 1);
    $mpesa = new Mpesa();
    // Step 1: token
    // Test raw cURL first
    $rawCurl = @file_get_contents('https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
    $curlTest = [];
    $ch = curl_init('https://sandbox.safaricom.co.ke');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_TIMEOUT=>10, CURLOPT_NOBODY=>true]);
    curl_exec($ch);
    $curlTest['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlTest['error']     = curl_error($ch);
    $curlTest['errno']     = curl_errno($ch);
    curl_close($ch);

    $token = $mpesa->getAccessToken();
    if (!$token) {
        $testResult = ['ok' => false, 'step' => 'Access Token',
            'error' => 'Could not get access token. Check MPESA_CONSUMER_KEY and MPESA_CONSUMER_SECRET.',
            'curl_test' => $curlTest];
    } else {
        $testResult = ['ok' => false, 'step' => 'Access Token', 'token_preview' => substr($token, 0, 20) . '…', 'token_ok' => true];
        // Step 2: STK push
        $res = $mpesa->stkPush($phone, 1, 'TEST', 'Test payment');
        $testResult['stk'] = $res;
        $testResult['ok']  = $res['success'];
    }
}

// Check 1: Environment
$checks['env'] = [
    'label' => 'M-Pesa Environment',
    'value' => MPESA_ENV,
    'ok'    => in_array(MPESA_ENV, ['sandbox', 'live']),
    'note'  => MPESA_ENV === 'sandbox' ? 'Sandbox — STK push goes to the Safaricom test simulator, NOT a real phone' : 'LIVE — real money, real phones',
];

// Check 2: Callback URL
$cbUrl = MPESA_CALLBACK_URL;
$checks['callback'] = [
    'label' => 'Callback URL',
    'value' => $cbUrl,
    'ok'    => str_starts_with($cbUrl, 'https://'),
    'note'  => str_starts_with($cbUrl, 'https://')
        ? (str_contains($cbUrl, 'localhost') || str_contains($cbUrl, '127.0.0.1')
            ? '⚠️ PROBLEM: localhost URLs will NOT receive callbacks from Safaricom. Use ngrok or Railway.'
            : '✅ Looks like a valid public URL')
        : '❌ Must start with https://',
];

// Check 3: Credentials look populated
$checks['creds'] = [
    'label' => 'Consumer Key / Secret',
    'value' => substr(MPESA_CONSUMER_KEY, 0, 8) . '… / ' . substr(MPESA_CONSUMER_SECRET, 0, 8) . '…',
    'ok'    => strlen(MPESA_CONSUMER_KEY) > 10 && strlen(MPESA_CONSUMER_SECRET) > 10,
    'note'  => 'From developer.safaricom.co.ke → your app → Keys',
];

// Check 4: Shortcode
$checks['shortcode'] = [
    'label' => 'Shortcode',
    'value' => MPESA_SHORTCODE,
    'ok'    => !empty(MPESA_SHORTCODE),
    'note'  => MPESA_ENV === 'sandbox' ? 'Sandbox default is 174379' : 'Your live PayBill/Till number',
];

// Check 5: APP_URL
$checks['appurl'] = [
    'label' => 'APP_URL',
    'value' => APP_URL,
    'ok'    => !str_contains(APP_URL, 'localhost') || MPESA_ENV === 'sandbox',
    'note'  => str_contains(APP_URL, 'localhost')
        ? (MPESA_ENV === 'sandbox' ? 'Localhost OK for sandbox (STK goes to simulator)' : '⚠️ Localhost won\'t work for live M-Pesa callbacks')
        : '✅',
];

// Check 6: cURL available
$checks['curl'] = [
    'label' => 'PHP cURL extension',
    'value' => function_exists('curl_init') ? 'Enabled' : 'MISSING',
    'ok'    => function_exists('curl_init'),
    'note'  => function_exists('curl_init') ? 'Required for M-Pesa API calls' : '❌ Install php-curl',
];

// Check 7: Recent transactions
$recent = $pdo->query("SELECT * FROM mpesa_transactions ORDER BY initiated_at DESC LIMIT 5")->fetchAll();

$allOk = array_reduce($checks, fn($c, $i) => $c && $i['ok'], true);
?>
<!DOCTYPE html>
<html><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>STK Debug — ChamaLedger</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
body{font-family:'DM Sans',sans-serif;background:#040d18;color:#e8f0f8;margin:0;padding:2rem}
.page{max-width:860px;margin:0 auto}
h1{font-family:'Syne',sans-serif;font-size:1.8rem;margin-bottom:.25rem;color:#fff}
.sub{color:#7a94b0;margin-bottom:2rem;font-size:.9rem}
.card{background:#071628;border:1px solid rgba(255,255,255,.07);border-radius:16px;padding:1.5rem;margin-bottom:1.25rem}
.card h3{font-family:'Syne',sans-serif;font-size:1rem;margin-bottom:1rem;color:#e8f0f8}
table{width:100%;border-collapse:collapse}
td,th{padding:.65rem .75rem;text-align:left;font-size:.85rem;border-bottom:1px solid rgba(255,255,255,.05)}
th{color:#7a94b0;font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em}
.ok{color:#00c471}.fail{color:#ef4444}.warn{color:#f59e0b}
.badge{padding:.2rem .65rem;border-radius:99px;font-size:.72rem;font-weight:700}
.badge-ok{background:rgba(0,196,113,.15);color:#00c471}
.badge-fail{background:rgba(239,68,68,.15);color:#ef4444}
.badge-warn{background:rgba(245,158,11,.15);color:#f59e0b}
input[type=tel]{background:#0b2140;border:1px solid rgba(255,255,255,.1);color:#e8f0f8;padding:.65rem 1rem;border-radius:10px;font-size:.9rem;width:220px}
button{background:#00c471;color:#040d18;border:none;padding:.65rem 1.5rem;border-radius:10px;font-weight:700;font-size:.9rem;cursor:pointer;margin-left:.5rem}
button:hover{background:#00dc7e}
pre{background:#040d18;padding:1rem;border-radius:10px;font-size:.78rem;color:#00c471;overflow-x:auto;word-break:break-all;white-space:pre-wrap}
.delete-note{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:10px;padding:.75rem 1rem;font-size:.82rem;color:#fca5a5;margin-bottom:1.5rem}
</style>
</head>
<body>
<div class="page">
<h1>🔍 STK Push Diagnostics</h1>
<p class="sub">Real-time checks for why M-Pesa STK Push may not be working</p>
<div class="delete-note"><i class="bi bi-exclamation-triangle me-2"></i><strong>Security reminder:</strong> Delete this file after debugging — <code>api/stk_debug.php</code></div>

<!-- CONFIG CHECKS -->
<div class="card">
<h3>Configuration Checks</h3>
<table>
<thead><tr><th>Check</th><th>Value</th><th>Status</th><th>Note</th></tr></thead>
<tbody>
<?php foreach ($checks as $c): ?>
<tr>
    <td><?= $c['label'] ?></td>
    <td><code style="font-size:.8rem"><?= htmlspecialchars($c['value']) ?></code></td>
    <td><span class="badge <?= $c['ok'] ? 'badge-ok' : 'badge-fail' ?>"><?= $c['ok'] ? '✅ OK' : '❌ FAIL' ?></span></td>
    <td style="color:#7a94b0;font-size:.8rem"><?= $c['note'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- SANDBOX EXPLAINER -->
<?php if (MPESA_ENV === 'sandbox'): ?>
<div class="card" style="border-color:rgba(245,158,11,.3)">
<h3 style="color:#f59e0b">⚠️ You are in SANDBOX mode</h3>
<p style="color:#7a94b0;font-size:.88rem;line-height:1.7">
In sandbox, Safaricom does <strong style="color:#e8f0f8">NOT</strong> send a real prompt to your phone. Instead:<br><br>
<strong style="color:#e8f0f8">1.</strong> The STK push request succeeds (returns a CheckoutRequestID) ✅<br>
<strong style="color:#e8f0f8">2.</strong> Nothing happens on any phone — this is normal in sandbox<br>
<strong style="color:#e8f0f8">3.</strong> To simulate a callback, use the <a href="https://developer.safaricom.co.ke/APIs/MpesaExpressSimulate" style="color:#00c471" target="_blank">Safaricom Simulator →</a><br>
<strong style="color:#e8f0f8">4.</strong> Or your callback URL must be public (Railway/ngrok) to receive simulated results<br><br>
<strong style="color:#f59e0b">To test on a real phone:</strong> You need a live Safaricom account, set <code>MPESA_ENV=live</code>, and use your real PayBill/Till credentials.
</p>
</div>
<?php endif; ?>

<!-- CALLBACK URL PROBLEM -->
<?php if (str_contains(MPESA_CALLBACK_URL, 'localhost') || str_contains(MPESA_CALLBACK_URL, '127.0.0.1')): ?>
<div class="card" style="border-color:rgba(239,68,68,.3)">
<h3 style="color:#ef4444">❌ Callback URL Problem</h3>
<p style="color:#7a94b0;font-size:.88rem;line-height:1.7">
Your callback URL is <code><?= MPESA_CALLBACK_URL ?></code><br><br>
Safaricom's servers cannot reach <code>localhost</code>. When payment completes, Safaricom tries to tell your app — but it can't find your computer.<br><br>
<strong style="color:#e8f0f8">Fix options:</strong><br>
• <strong style="color:#00c471">Railway (recommended):</strong> Deploy there and the callback URL sets itself automatically<br>
• <strong style="color:#00c471">ngrok:</strong> Run <code style="color:#00c471">ngrok http 80</code> → update <code>MPESA_CALLBACK_URL</code> in config/db.php to <code>https://xxxx.ngrok.io/chama_system/api/mpesa_callback.php</code><br>
• <strong style="color:#00c471">Cloudflare Tunnel:</strong> Free permanent tunnel, no restart needed
</p>
</div>
<?php endif; ?>

<!-- LIVE TEST -->
<div class="card">
<h3>Live API Test — Send Real STK Request</h3>
<p style="color:#7a94b0;font-size:.85rem;margin-bottom:1rem">
    Enter a phone number to send a KES 1 test STK push. In sandbox this tests the API connection — no real prompt is sent.
</p>
<form method="POST" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
    <input type="tel" name="test_phone" placeholder="0712 345 678" value="<?= htmlspecialchars($_POST['test_phone'] ?? '') ?>">
    <button type="submit"><i class="bi bi-send me-1"></i>Test STK Push</button>
</form>
<?php if ($testResult): ?>
<div style="margin-top:1.25rem">
    <?php if ($testResult['token_ok'] ?? false): ?>
    <div style="color:#00c471;margin-bottom:.5rem">✅ Access token obtained: <code><?= $testResult['token_preview'] ?></code></div>
    <?php endif; ?>
    <?php if (isset($testResult['stk'])): ?>
    <div style="color:<?= $testResult['stk']['success'] ? '#00c471' : '#ef4444' ?>;margin-bottom:.5rem">
        <?= $testResult['stk']['success'] ? '✅ STK Push sent successfully!' : '❌ STK Push failed: ' . htmlspecialchars($testResult['stk']['message']) ?>
    </div>
    <?php if ($testResult['stk']['success']): ?>
    <div style="color:#7a94b0;font-size:.82rem">CheckoutRequestID: <code><?= htmlspecialchars($testResult['stk']['checkout_request_id'] ?? '') ?></code></div>
    <?php endif; ?>
    <?php endif; ?>
    <?php if (!($testResult['token_ok'] ?? false)): ?>
    <div style="color:#ef4444;margin-bottom:.5rem">❌ <?= htmlspecialchars($testResult['error'] ?? 'Unknown error') ?></div>
    <?php if (!empty($testResult['curl_test'])): $ct = $testResult['curl_test']; ?>
    <div style="margin-top:.75rem;background:#040d18;padding:1rem;border-radius:8px;font-size:.82rem">
        <div style="color:#7a94b0;margin-bottom:.35rem">Raw cURL test to sandbox.safaricom.co.ke:</div>
        <?php if ($ct['error']): ?>
        <div style="color:#ef4444">❌ cURL error (<?= $ct['errno'] ?>): <?= htmlspecialchars($ct['error']) ?></div>
        <div style="color:#f59e0b;margin-top:.5rem">
            <?php if ($ct['errno'] == 6): ?>⚠️ Could not resolve host — no internet or DNS blocked<br>
            <?php elseif ($ct['errno'] == 7): ?>⚠️ Connection refused — port 443 may be blocked by firewall/antivirus<br>
            <?php elseif ($ct['errno'] == 28): ?>⚠️ Connection timed out — slow internet or firewall blocking outbound HTTPS<br>
            <?php elseif ($ct['errno'] == 35 || $ct['errno'] == 60): ?>⚠️ SSL error — try disabling antivirus HTTPS scanning temporarily<br>
            <?php else: ?>⚠️ Check your internet connection and firewall settings<br><?php endif; ?>
            <strong>Common fix on XAMPP Windows:</strong> Disable antivirus HTTPS scanning (Avast/ESET/Kaspersky intercepts SSL and causes cURL failures)
        </div>
        <?php else: ?>
        <div style="color:#00c471">✅ Connected to Safaricom (HTTP <?= $ct['http_code'] ?>) — cURL works fine</div>
        <div style="color:#7a94b0;margin-top:.35rem">Token failure is likely wrong credentials. Re-copy from developer.safaricom.co.ke</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>

<!-- RECENT TRANSACTIONS -->
<div class="card">
<h3>Recent M-Pesa Transactions (last 5)</h3>
<?php if (empty($recent)): ?>
<p style="color:#7a94b0;font-size:.85rem">No transactions yet.</p>
<?php else: ?>
<table>
<thead><tr><th>Phone</th><th>Amount</th><th>Month</th><th>Status</th><th>CheckoutID</th><th>Receipt</th><th>When</th></tr></thead>
<tbody>
<?php foreach ($recent as $tx): 
    $sc = match($tx['status']) { 'completed'=>'badge-ok', 'pending'=>'badge-warn', default=>'badge-fail' };
?>
<tr>
    <td><?= htmlspecialchars($tx['phone']) ?></td>
    <td>KES <?= number_format($tx['amount'],0) ?></td>
    <td><?= date('M Y', strtotime($tx['payment_month'])) ?></td>
    <td><span class="badge <?= $sc ?>"><?= ucfirst($tx['status']) ?></span></td>
    <td><code style="font-size:.72rem"><?= htmlspecialchars(substr($tx['checkout_request_id'] ?? '—', 0, 30)) ?></code></td>
    <td><?= htmlspecialchars($tx['mpesa_receipt'] ?? '—') ?></td>
    <td style="color:#7a94b0;font-size:.78rem"><?= $tx['initiated_at'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<!-- WHAT THE ERROR MEANS -->
<div class="card">
<h3>Common Error Codes &amp; What They Mean</h3>
<table>
<thead><tr><th>Error</th><th>Meaning</th><th>Fix</th></tr></thead>
<tbody>
<tr><td><code>Invalid Access Token</code></td><td>Consumer key/secret wrong or expired</td><td>Re-copy from developer.safaricom.co.ke → your app</td></tr>
<tr><td><code>Bad Request - Invalid PhoneNumber</code></td><td>Phone format wrong</td><td>Must be 2547XXXXXXXX — the formatPhone() function handles this</td></tr>
<tr><td><code>The initiator information is invalid</code></td><td>Wrong shortcode for environment</td><td>Sandbox: use 174379. Live: your actual PayBill</td></tr>
<tr><td><code>1032</code> (callback)</td><td>User cancelled on their phone</td><td>Normal — tell user to try again and not dismiss the prompt</td></tr>
<tr><td><code>1</code> (callback)</td><td>Insufficient balance</td><td>Normal — user needs to top up M-Pesa</td></tr>
<tr><td><code>2001</code> (callback)</td><td>Wrong PIN entered</td><td>Normal — user entered wrong PIN 3 times</td></tr>
<tr><td>Callback never arrives</td><td>Callback URL not reachable</td><td>Use Railway or ngrok — localhost blocks Safaricom</td></tr>
<tr><td><code>Could not connect to M-Pesa</code></td><td>cURL failed or no internet</td><td>Check server has outbound HTTPS access to api.safaricom.co.ke</td></tr>
</tbody>
</table>
</div>

<p style="color:#6b7280;font-size:.78rem;margin-top:1rem">⚠️ Delete api/stk_debug.php after use — it exposes your M-Pesa config to anyone logged in as admin.</p>
</div>
</body></html>
