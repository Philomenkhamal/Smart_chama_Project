<?php
/**
 * SmartChama — Database & App Configuration
 * 
 * On Railway: set these as environment variables in the dashboard
 * On localhost: edit the values directly below
 */

// ── Database ─────────────────────────────────────────────────────────────────
// Railway auto-sets MYSQL_* variables when you add a MySQL plugin
define('DB_HOST',    getenv('MYSQLHOST')    ?: getenv('DB_HOST')    ?: 'localhost');
define('DB_NAME',    getenv('MYSQLDATABASE')?: getenv('DB_NAME')    ?: 'chama_db');
define('DB_USER',    getenv('MYSQLUSER')    ?: getenv('DB_USER')    ?: 'root');
define('DB_PASS',    getenv('MYSQLPASSWORD')?: getenv('DB_PASS')    ?: '');
define('DB_PORT',    getenv('MYSQLPORT')    ?: getenv('DB_PORT')    ?: '3306');
define('DB_CHARSET', 'utf8mb4');

// ── App ───────────────────────────────────────────────────────────────────────
// Railway sets RAILWAY_PUBLIC_DOMAIN automatically
$railwayDomain = getenv('RAILWAY_PUBLIC_DOMAIN');
$appUrl = $railwayDomain
    ? 'https://' . $railwayDomain
    : (getenv('APP_URL') ?: ' https://power-aorta-premiere.ngrok-free.dev/chama_system');

define('APP_NAME',    getenv('APP_NAME')    ?: 'SmartChama');
define('APP_URL',     rtrim($appUrl, '/'));
define('APP_VERSION', '1.0.0');
define('TIMEZONE',    getenv('TIMEZONE')    ?: 'Africa/Nairobi');

date_default_timezone_set(TIMEZONE);

// ── M-Pesa ────────────────────────────────────────────────────────────────────
// SECURITY: Never hardcode live credentials here. Set ALL of these as
// environment variables in Railway / your hosting dashboard.
// The sandbox defaults below are Safaricom's PUBLIC test credentials — safe
// to ship only because they grant sandbox access only. Replace with your
// live credentials via env vars for production.
define('MPESA_ENV',             getenv('MPESA_ENV')             ?: 'sandbox');
define('MPESA_CONSUMER_KEY',    getenv('MPESA_CONSUMER_KEY')    ?: '2UzDwMgR8nAKSrRf4ZGyVUtGRdiAiLaRGtwlciWa3yvEY5rQ');  // Set MPESA_CONSUMER_KEY env var
define('MPESA_CONSUMER_SECRET', getenv('MPESA_CONSUMER_SECRET') ?: 'NBrRsLwo9H1ipr2dvsp3shKiMpNQZcopNrCM1jkdMR2J5KKboouVQHQY8GJoBjeq');  // Set MPESA_CONSUMER_SECRET env var
define('MPESA_SHORTCODE',       getenv('MPESA_SHORTCODE')       ?: '174379');      // Safaricom sandbox shortcode
define('MPESA_PASSKEY',         getenv('MPESA_PASSKEY')         ?: 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919'); // Sandbox passkey only
// Set MPESA_CALLBACK_URL env var to your live public HTTPS URL
define('MPESA_CALLBACK_URL', 'https://power-aorta-premiere.ngrok-free.dev/chama_system/api/mpesa_callback.php');

// ── Pochi La Biashara ─────────────────────────────────────────────────────────
// M-Pesa Transaction Status API (needed for live receipt verification)
define('MPESA_INITIATOR_NAME',       getenv('MPESA_INITIATOR_NAME')       ?: '');
define('MPESA_SECURITY_CREDENTIAL',  getenv('MPESA_SECURITY_CREDENTIAL')  ?: '');

define('POCHI_PHONE', getenv('POCHI_PHONE') ?: '');  // Set POCHI_PHONE env var
define('POCHI_NAME',  getenv('POCHI_NAME')  ?: APP_NAME);

// ── Email / SMTP ──────────────────────────────────────────────────────────────
define('MAIL_HOST',      getenv('MAIL_HOST')      ?: 'smtp.gmail.com');
define('MAIL_PORT',      (int)(getenv('MAIL_PORT')      ?: 587));
define('MAIL_USER',      getenv('MAIL_USER')      ?: 'your@gmail.com');
define('MAIL_PASS',      getenv('MAIL_PASS')      ?: 'xxxx xxxx xxxx xxxx');
define('MAIL_FROM',      getenv('MAIL_FROM')      ?: 'your@gmail.com');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'SmartChama');

// ── Database Connection ───────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

// ── Anthropic AI (optional — for ChamaBot AI responses) ──────────────────────
// Get a free API key from console.anthropic.com
// If not set, ChamaBot uses smart rule-based fallback replies (still works great)
define('ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: '');

// ── Africa's Talking SMS ───────────────────────────────────────────────────────
define('AT_USERNAME',  getenv('AT_USERNAME')  ?: 'sandbox');
define('AT_API_KEY',   getenv('AT_API_KEY')   ?: '');
define('AT_SENDER_ID', getenv('AT_SENDER_ID') ?: '');  // your registered sender name e.g. 'ChamaLedger'
define('SMS_ENABLED',  getenv('SMS_ENABLED')  ?: '0'); // set to '1' to enable
