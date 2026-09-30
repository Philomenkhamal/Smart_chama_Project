<?php
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
startSession();
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');
if (!isLoggedIn()) { echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }

$pdo    = getDB();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'post') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }
    $annId   = (int)($_POST['ann_id'] ?? 0);
    $message = trim(sanitize($_POST['message'] ?? ''));
    $userId  = (int)$_SESSION['user_id'];
    if (!$annId || strlen($message) < 1 || strlen($message) > 1000) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid input']); exit;
    }
    // Check announcement exists
    $ann = $pdo->prepare("SELECT id, title, posted_by FROM announcements WHERE id=? AND is_active=1");
    $ann->execute([$annId]); $ann = $ann->fetch();
    if (!$ann) { echo json_encode(['ok'=>false,'msg'=>'Not found']); exit; }

    $pdo->prepare("INSERT INTO announcement_replies (announcement_id, user_id, message) VALUES (?,?,?)")
        ->execute([$annId, $userId, $message]);
    $replyId = $pdo->lastInsertId();

    // Notify admin if member replied
    $user = $pdo->prepare("SELECT full_name, role FROM users WHERE id=?");
    $user->execute([$userId]); $user = $user->fetch();
    if ($user['role'] === 'member') {
        $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll();
        foreach ($admins as $a) {
            createNotification($a['id'], '💬 New Reply', $user['full_name'] . ' replied on "' . $ann['title'] . '"', 'info', APP_URL.'/member/announcements.php?id='.$annId);
        }
    } else {
        // Admin replied — notify all members who replied before
        $prev = $pdo->prepare("SELECT DISTINCT user_id FROM announcement_replies WHERE announcement_id=? AND user_id!=?");
        $prev->execute([$annId, $userId]);
        foreach ($prev->fetchAll() as $m) {
            createNotification($m['user_id'], '💬 Admin Replied', 'Admin replied on "' . $ann['title'] . '"', 'info', APP_URL.'/member/announcements.php?id='.$annId);
        }
    }

    logActivity('CHAT_REPLY', "Reply on ann#{$annId}", $userId);
    // Return the new reply
    $reply = $pdo->prepare("SELECT r.*, u.full_name, u.role FROM announcement_replies r JOIN users u ON r.user_id=u.id WHERE r.id=?");
    $reply->execute([$replyId]); $reply = $reply->fetch();
    echo json_encode(['ok'=>true,'reply'=>$reply]);

} elseif ($action === 'load') {
    $annId  = (int)($_GET['ann_id'] ?? 0);
    $since  = sanitize($_GET['since'] ?? '1970-01-01 00:00:00');
    $stmt   = $pdo->prepare("SELECT r.*, u.full_name, u.role FROM announcement_replies r JOIN users u ON r.user_id=u.id WHERE r.announcement_id=? AND r.created_at > ? ORDER BY r.created_at ASC");
    $stmt->execute([$annId, $since]);
    echo json_encode(['ok'=>true,'replies'=>$stmt->fetchAll()]);
}
