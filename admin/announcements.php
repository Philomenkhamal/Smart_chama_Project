<?php
/**
 * CHAMA Financial Management System
 * Admin — Announcements Management
 * Create, edit, publish and delete announcements for members
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Announcements — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();
require_once ROOT . '/includes/header.php';

$pdo     = getDB();
$adminId = (int)$_SESSION['user_id'];

// ── Handle POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        $id       = (int)($_POST['ann_id']  ?? 0);
        $title    = sanitize($_POST['title']    ?? '');
        $body     = trim($_POST['body']         ?? '');
        $priority = in_array($_POST['priority'] ?? '', ['normal','urgent']) ? $_POST['priority'] : 'normal';
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $errors = [];
        if (strlen($title) < 3)   $errors[] = 'Title must be at least 3 characters.';
        if (strlen($body)  < 10)  $errors[] = 'Body must be at least 10 characters.';

        if (empty($errors)) {
            if ($action === 'create') {
                $pdo->prepare('INSERT INTO announcements (title, body, priority, is_active, posted_by) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$title, $body, $priority, $isActive, $adminId]);

                // Notify all active members
                $members = $pdo->query("SELECT id FROM users WHERE role='member' AND status='active'")->fetchAll();
                foreach ($members as $m) {
                    createNotification($m['id'], '📢 ' . $title,
                        substr(strip_tags($body), 0, 120) . '...',
                        $priority === 'urgent' ? 'warning' : 'info',
                        APP_URL . '/member/announcements.php');
                }

                logActivity('ANNOUNCEMENT_CREATE', "Created announcement: {$title}");
                setFlash('success', "Announcement published and " . count($members) . " member(s) notified.");
            } else {
                $pdo->prepare('UPDATE announcements SET title=?, body=?, priority=?, is_active=?, updated_at=NOW() WHERE id=?')
                    ->execute([$title, $body, $priority, $isActive, $id]);
                logActivity('ANNOUNCEMENT_EDIT', "Edited announcement ID:{$id}");
                setFlash('success', 'Announcement updated.');
            }
        } else {
            setFlash('danger', implode('<br>', $errors));
        }
        redirect(APP_URL . '/admin/announcements.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['ann_id'] ?? 0);
        $pdo->prepare('DELETE FROM announcements WHERE id=?')->execute([$id]);
        logActivity('ANNOUNCEMENT_DELETE', "Deleted announcement ID:{$id}");
        setFlash('success', 'Announcement deleted.');
        redirect(APP_URL . '/admin/announcements.php');
    }

    if ($action === 'toggle') {
        $id  = (int)($_POST['ann_id'] ?? 0);
        $pdo->prepare('UPDATE announcements SET is_active = NOT is_active WHERE id=?')->execute([$id]);
        redirect(APP_URL . '/admin/announcements.php');
    }
}

// ── Fetch ──────────────────────────────────────────────────────────────────────
$announcements = $pdo->query("
    SELECT a.*, u.full_name AS posted_by_name
    FROM announcements a JOIN users u ON a.posted_by = u.id
    ORDER BY a.created_at DESC
")->fetchAll();

// For edit, load specific announcement
$editAnn = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editAnn = $stmt->fetch();
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><?= t('announce_title') ?></h4>
        <small class="text-muted"><?= t('adm_ann_subtitle') ?></small>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createModal">
        <i class="bi bi-megaphone me-1"></i>New Announcement
    </button>
</div>

<div class="row g-4">
    <!-- Announcements list -->
    <div class="col-md-8">
        <?php if (empty($announcements)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-megaphone fs-2 d-block mb-2"></i>
                <?= t('announce_none') ?> yet. Create the first one!
            </div>
        </div>
        <?php else: ?>
        <?php foreach ($announcements as $a): ?>
        <div class="card border-0 shadow-sm mb-3 <?= !$a['is_active'] ? 'opacity-60' : '' ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($a['priority'] === 'urgent'): ?>
                        <span class="badge bg-danger"><?= t('adm_ann_urgent') ?></span>
                        <?php else: ?>
                        <span class="badge bg-info text-dark"><?= t('adm_ann_notice') ?></span>
                        <?php endif; ?>
                        <span class="badge <?= $a['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                            <?= $a['is_active'] ? 'Published' : 'Hidden' ?>
                        </span>
                    </div>
                    <div class="d-flex gap-1">
                        <!-- Toggle visibility -->
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="ann_id" value="<?= $a['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" title="<?= $a['is_active'] ? 'Hide' : 'Publish' ?>">
                                <i class="bi bi-<?= $a['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                            </button>
                        </form>
                        <!-- Edit -->
                        <a href="?edit=<?= $a['id'] ?>"
                           class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                           data-bs-target="#editModal"
                           data-id="<?= $a['id'] ?>"
                           data-title="<?= htmlspecialchars($a['title'], ENT_QUOTES) ?>"
                           data-body="<?= htmlspecialchars($a['body'], ENT_QUOTES) ?>"
                           data-priority="<?= $a['priority'] ?>"
                           data-active="<?= $a['is_active'] ?>">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <!-- Delete -->
                        <form method="POST" class="d-inline"
                              onsubmit="return confirm('Delete this announcement?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="ann_id" value="<?= $a['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>

                <h6 class="fw-bold mb-1"><?= htmlspecialchars($a['title']) ?></h6>
                <p class="text-muted small mb-2" style="white-space:pre-line">
                    <?= htmlspecialchars(substr($a['body'], 0, 300)) ?><?= strlen($a['body']) > 300 ? '…' : '' ?>
                </p>

                <div class="d-flex gap-3 text-muted" style="font-size:.75rem">
                    <span><i class="bi bi-person me-1"></i><?= htmlspecialchars($a['posted_by_name']) ?></span>
                    <span><i class="bi bi-calendar me-1"></i><?= formatDate($a['created_at'], 'd M Y, g:ia') ?></span>
                    <?php if ($a['updated_at'] !== $a['created_at']): ?>
                    <span class="text-warning"><i class="bi bi-pencil me-1"></i>Edited <?= formatDate($a['updated_at'], 'd M Y') ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Stats sidebar -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="fw-semibold mb-3"><?= t('adm_ann_stats') ?></h6>
                <?php
                $total     = count($announcements);
                $published = count(array_filter($announcements, fn($a) => $a['is_active']));
                $urgent    = count(array_filter($announcements, fn($a) => $a['priority'] === 'urgent'));
                ?>
                <dl class="row small mb-0">
                    <dt class="col-7"><?= t('adm_ann_total') ?></dt>
                    <dd class="col-5 fw-bold"><?= $total ?></dd>
                    <dt class="col-7"><?= t('adm_ann_published') ?></dt>
                    <dd class="col-5 fw-bold text-success"><?= $published ?></dd>
                    <dt class="col-7"><?= t('adm_ann_hidden') ?></dt>
                    <dd class="col-5 fw-bold text-secondary"><?= $total - $published ?></dd>
                    <dt class="col-7"><?= t('ann_urgent_lbl') ?></dt>
                    <dd class="col-5 fw-bold text-danger"><?= $urgent ?></dd>
                </dl>
            </div>
        </div>
        <div class="card border-0 shadow-sm bg-light">
            <div class="card-body small text-muted">
                <strong><?= t('ann_tips') ?></strong>
                <ul class="ps-3 mt-2 mb-0">
                    <li><?= t('adm_ann_tip1') ?></li>
                    <li><?= t('adm_ann_tip2') ?></li>
                    <li><?= t('ann_notify_tip') ?></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Create Announcement Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="bi bi-megaphone me-2 text-primary"></i><?= t('announce_add') ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control"
                               placeholder="e.g. Monthly Meeting — March 2025" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Message Body <span class="text-danger">*</span></label>
                        <textarea name="body" class="form-control" rows="6"
                                  placeholder="Write your announcement here..." required></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= t('lbl_priority') ?></label>
                            <select name="priority" class="form-select">
                                <option value="normal"><?= t('ann_normal') ?></option>
                                <option value="urgent"><?= t('adm_ann_urgent') ?></option>
                            </select>
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" checked id="isActive">
                                <label class="form-check-label" for="isActive">
                                    Publish immediately
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= t('btn_cancel') ?></button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-send me-1"></i>Publish Announcement
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Announcement Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="bi bi-pencil me-2 text-warning"></i>Edit Announcement</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="ann_id" id="editAnnId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="editTitle" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Message Body <span class="text-danger">*</span></label>
                        <textarea name="body" id="editBody" class="form-control" rows="6" required></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= t('lbl_priority') ?></label>
                            <select name="priority" id="editPriority" class="form-select">
                                <option value="normal"><?= t('ann_normal') ?></option>
                                <option value="urgent"><?= t('adm_ann_urgent') ?></option>
                            </select>
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="editIsActive">
                                <label class="form-check-label" for="editIsActive"><?= t('adm_ann_published') ?></label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= t('btn_cancel') ?></button>
                    <button type="submit" class="btn btn-warning btn-sm">
                        <i class="bi bi-save me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<'JS'
<script>
// Populate edit modal
document.querySelectorAll('[data-bs-target="#editModal"]').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('editAnnId').value     = this.dataset.id;
        document.getElementById('editTitle').value     = this.dataset.title;
        document.getElementById('editBody').value      = this.dataset.body;
        document.getElementById('editPriority').value  = this.dataset.priority;
        document.getElementById('editIsActive').checked = this.dataset.active === '1';
    });
});
</script>
JS;
require_once ROOT . '/includes/footer.php';
?>
