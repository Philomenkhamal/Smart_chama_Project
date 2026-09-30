<?php
/**
 * CHAMA Financial Management System
 * Logout Handler
 */

require_once __DIR__ . '/includes/functions.php';
startSession();

if (isLoggedIn()) {
    // Capture user_id BEFORE destroying session
    $logUserId = (int)($_SESSION['user_id'] ?? 0);
    $logEmail  = $_SESSION['user_email'] ?? '';
    logActivity('LOGOUT', 'User logged out: ' . $logEmail, $logUserId ?: null);
}

// Destroy session
$_SESSION = [];
session_destroy();

redirect(APP_URL . '/index.php?msg=logged_out');
