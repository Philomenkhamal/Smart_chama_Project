<?php
/**
 * ChamaLedger — Registration Status Checker
 * GET /api/check_status.php?email=xxx
 * Called every few seconds from pending.php
 *
 * Security: Only returns status + login URL — no PII.
 * Full_name and membership_number are shown only after the user
 * logs in, not to unauthenticated pollers.
 * A rate-limit token (generated at registration and stored in session)
 * is required so this endpoint cannot be used for user enumeration.
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');

// Require a session token that was set during registration — prevents
// unauthenticated callers from probing arbitrary email addresses.
startSession();
$sessionEmail = $_SESSION['pending_email'] ?? '';
$requestEmail = trim($_GET['email'] ?? '');

if (!$requestEmail || !filter_var($requestEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email']);
    exit;
}

// Only allow checking the email that belongs to this session
if (strtolower($sessionEmail) !== strtolower($requestEmail)) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$pdo  = getDB();
$stmt = $pdo->prepare("SELECT status FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$requestEmail]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['status' => 'not_found']);
    exit;
}

echo json_encode([
    'status'    => $user['status'],  // pending|active|suspended|rejected only
    'login_url' => APP_URL . '/index.php',
]);
