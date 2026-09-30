<?php
/**
 * ChamaLedger — Notification Polling Endpoint
 * Returns new unread notifications since a given timestamp
 * GET /api/notifications_poll.php?since=TIMESTAMP
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
startSession();

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'notifications' => [], 'unread' => 0]);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$since  = sanitize($_GET['since'] ?? '');
$pdo    = getDB();

// Validate since timestamp — default to 60 seconds ago
if (!$since || !strtotime($since)) {
    $since = date('Y-m-d H:i:s', time() - 60);
}

// Fetch new notifications since last poll
$stmt = $pdo->prepare("
    SELECT id, title, message, type, link, created_at
    FROM notifications
    WHERE user_id = ?
      AND is_read  = 0
      AND created_at > ?
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->execute([$userId, $since]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total unread count
$unread = (int)$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0")
            ->execute([$userId]) ? $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0") : 0;
$stmtU = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$stmtU->execute([$userId]);
$unread = (int)$stmtU->fetchColumn();

// Mark fetched ones as read
if (!empty($notifications)) {
    $ids = implode(',', array_map('intval', array_column($notifications, 'id')));
    $pdo->exec("UPDATE notifications SET is_read=1 WHERE id IN ($ids)");
}

echo json_encode([
    'ok'            => true,
    'notifications' => $notifications,
    'unread'        => $unread,
    'server_time'   => date('Y-m-d H:i:s'),
]);
