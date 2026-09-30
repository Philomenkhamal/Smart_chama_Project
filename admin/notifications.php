<?php
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Notifications — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// Mark all as read when page is opened
$pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$userId]);

// Fetch all notifications (last 60)
$notifs = $pdo->prepare("
    SELECT id, title, message, type, is_read, link, created_at
    FROM notifications
    WHERE user_id=?
    ORDER BY created_at DESC
    LIMIT 60
");
$notifs->execute([$userId]);
$notifs = $notifs->fetchAll(PDO::FETCH_ASSOC);

require_once ROOT . '/includes/header.php';

$icons  = ['info'=>'bi-info-circle-fill text-primary','success'=>'bi-check-circle-fill text-success','warning'=>'bi-exclamation-triangle-fill text-warning','danger'=>'bi-x-circle-fill text-danger'];
$colors = ['info'=>'#3b82f6','success'=>'#22c55e','warning'=>'#f59e0b','danger'=>'#ef4444'];
?>

<div class="mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-bell-fill me-2 text-primary"></i><?= t('notif_title') ?></h4>
    <small class="text-muted"><?= t('notif_subtitle') ?></small>
</div>

<div class="card border-0 shadow-sm">
    <?php if (empty($notifs)): ?>
    <div class="card-body text-center py-5">
        <i class="bi bi-bell-slash text-muted" style="font-size:3rem"></i>
        <p class="text-muted mt-3 mb-0"><?= t('notif_none') ?></p>
    </div>
    <?php else: ?>
    <div class="list-group list-group-flush">
        <?php foreach ($notifs as $n):
            $icon  = $icons[$n['type']] ?? $icons['info'];
            $color = $colors[$n['type']] ?? $colors['info'];
            $link  = $n['link'] ?? '';
        ?>
        <<?= $link ? 'a href="' . htmlspecialchars($link) . '"' : 'div' ?>
            class="list-group-item list-group-item-action border-0 px-4 py-3"
            style="border-left:3px solid <?= $color ?> !important">
            <div class="d-flex gap-3 align-items-start">
                <i class="bi <?= $icon ?> fs-5 flex-shrink-0 mt-1"></i>
                <div class="flex-grow-1">
                    <div class="fw-semibold small"><?= htmlspecialchars($n['title']) ?></div>
                    <div class="text-muted small"><?= htmlspecialchars($n['message']) ?></div>
                    <div class="text-muted" style="font-size:.72rem;margin-top:3px">
                        <?= date('d M Y, g:i a', strtotime($n['created_at'])) ?>
                    </div>
                </div>
                <?php if ($link): ?>
                <i class="bi bi-arrow-right-circle text-muted flex-shrink-0 mt-1"></i>
                <?php endif; ?>
            </div>
        </<?= $link ? 'a' : 'div' ?>>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once ROOT . '/includes/footer.php'; ?>
