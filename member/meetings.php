<?php
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Meeting Minutes — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireMember();
require_once ROOT . '/includes/header.php';

$pdo = getDB();
$pdo->exec("CREATE TABLE IF NOT EXISTS meeting_minutes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meeting_date DATE NOT NULL, meeting_type VARCHAR(60) DEFAULT 'Regular',
    venue VARCHAR(150), agenda TEXT, minutes TEXT,
    attendees_count INT DEFAULT 0, attendee_ids TEXT,
    next_meeting_date DATE NULL, recorded_by INT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$meetings = $pdo->query("SELECT * FROM meeting_minutes ORDER BY meeting_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$userId   = (int)$_SESSION['user_id'];
?>
<div class="mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-journal-text me-2 text-primary"></i><?= t('meetings_minutes_title') ?></h4>
    <small class="text-muted"><?= t('meetings_minutes_sub') ?></small>
</div>
<?php if(empty($meetings)): ?>
<div class="card border-0 shadow-sm"><div class="card-body text-center py-5 text-muted">
    <i class="bi bi-journal fs-1"></i><p class="mt-3"><?= t('meetings_minutes_none') ?></p>
</div></div>
<?php else: foreach($meetings as $m):
    $attendIds = array_filter(array_map('intval', explode(',', $m['attendee_ids'] ?? '')));
    $iPresent  = in_array($userId, $attendIds);
?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-card d-flex justify-content-between align-items-center py-3">
        <div>
            <span class="badge bg-primary me-2"><?= htmlspecialchars($m['meeting_type']) ?></span>
            <strong><?= date('d M Y', strtotime($m['meeting_date'])) ?></strong>
            <?php if($m['venue']): ?><small class="text-muted ms-2"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($m['venue']) ?></small><?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-<?= $iPresent?'success':'light text-muted border' ?>">
                <i class="bi bi-<?= $iPresent?'check-circle':'dash-circle' ?> me-1"></i><?= $iPresent?'You attended':'Not recorded' ?>
            </span>
            <small class="text-muted"><i class="bi bi-people me-1"></i><?= $m['attendees_count'] ?> <?= t('meetings_present') ?></small>
        </div>
    </div>
    <div class="card-body">
        <?php if($m['agenda']): ?>
        <p class="small fw-semibold text-muted mb-1"><?= t('meetings_agenda_label') ?></p>
        <div class="bg-light rounded p-2 small mb-3" style="white-space:pre-wrap"><?= htmlspecialchars($m['agenda']) ?></div>
        <?php endif; ?>
        <p class="small fw-semibold text-muted mb-1"><?= t('meetings_minutes_label') ?></p>
        <div class="small" style="white-space:pre-wrap"><?= htmlspecialchars($m['minutes']) ?></div>
        <?php if($m['next_meeting_date']): ?>
        <div class="mt-3 small text-muted"><i class="bi bi-calendar-event me-1 text-primary"></i>
            Next meeting: <strong><?= date('d M Y', strtotime($m['next_meeting_date'])) ?></strong>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; endif; ?>
<?php require_once ROOT . '/includes/footer.php'; ?>
