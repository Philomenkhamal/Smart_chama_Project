<?php
/**
 * CHAMA Financial Management System
 * AJAX API — Notifications
 * GET  /api/notifications.php          → fetch latest
 * POST /api/notifications.php?mark_all → mark all read
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');
startSession();

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$pdo    = getDB();

// Mark all as read
if (isset($_GET['mark_all'])) {
    $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')
        ->execute([$userId]);
    echo json_encode(['success' => true]);
    exit;
}

// Mark single notification as read
if (isset($_GET['mark_one'])) {
    $nid = (int)$_GET['mark_one'];
    $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')
        ->execute([$nid, $userId]);
    echo json_encode(['success' => true]);
    exit;
}

// Fetch latest 10 notifications
$stmt = $pdo->prepare('
    SELECT id, title, message, type, is_read, link, created_at
    FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
');
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

$unread = countUnreadNotifications($userId);

echo json_encode([
    'notifications' => $notifications,
    'unread_count'  => $unread,
]);
