<?php
/**
 * ChamaLedger — Language Switcher
 * Usage: /api/set_lang.php?lang=sw  (or lang=en)
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
startSession();

$lang = preg_replace('/[^a-z]/', '', strtolower($_GET['lang'] ?? $_POST['lang'] ?? 'en'));
setLang($lang);

// Redirect back to wherever the user came from.
// Safety: strip newlines to prevent header injection — we do NOT restrict
// to APP_URL because on localhost APP_URL may be an ngrok/external address
// while HTTP_REFERER is http://localhost/... — that would break the switcher.
$back = $_SERVER['HTTP_REFERER'] ?? '';
// Remove any CR/LF characters that could inject extra headers
$back = str_replace(["\r", "\n", "\0"], '', $back);
// Must be a real http/https URL; fall back to index if not
if (!$back || !preg_match('#^https?://#i', $back)) {
    $back = APP_URL . '/index.php';
}
header('Location: ' . $back);
exit;
