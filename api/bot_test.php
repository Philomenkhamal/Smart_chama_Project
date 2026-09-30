<?php
/**
 * ChamaLedger — Bot/Session Debug (Admin only)
 * DELETE THIS FILE before going live.
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
requireAdmin();

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');

$raw = file_get_contents('php://input');

echo json_encode([
    'test'        => 'ok',
    'raw_input'   => $raw,
    'method'      => $_SERVER['REQUEST_METHOD'],
    'logged_in'   => isLoggedIn(),
    'role'        => $_SESSION['user_role'] ?? $_SESSION['role'] ?? null,
    // Session data intentionally NOT dumped — security risk
]);
