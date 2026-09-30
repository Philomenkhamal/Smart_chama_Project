<?php
/**
 * ChamaLedger — Meeting Minutes
 * Record, view and manage chama meeting minutes
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Meeting Minutes — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();

$pdo     = getDB();
$adminId = (int)$_SESSION['user_id'];
$curr    = getSetting('currency', 'KES');

// Create table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS meeting_minutes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meeting_date DATE NOT NULL,
    meeting_type VARCHAR(60) NOT NULL DEFAULT 'Regular',
    venue VARCHAR(150),
    agenda TEXT,
    minutes TEXT,
    attendees_count INT DEFAULT 0,
    attendee_ids TEXT COMMENT 'comma-separated user IDs',
    next_meeting_date DATE NULL,
    recorded_by INT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
)");

// ── POST: Save / Update / Delete ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_meeting') {
        $date         = sanitize($_POST['meeting_date'] ?? '');
        $type         = sanitize($_POST['meeting_type'] ?? 'Regular');
        $venue        = sanitize($_POST['venue'] ?? '');
        $agenda       = trim($_POST['agenda'] ?? '');
        $minutes      = trim($_POST['minutes'] ?? '');
        $attendeeIds  = implode(',', array_filter(array_map('intval', $_POST['attendees'] ?? [])));
        $attendCount  = count(array_filter(explode(',', $attendeeIds)));
        $nextDate     = sanitize($_POST['next_meeting_date'] ?? '') ?: null;
        $editId       = (int)($_POST['edit_id'] ?? 0);

        if ($editId) {
            $pdo->prepare("UPDATE meeting_minutes SET meeting_date=?,meeting_type=?,venue=?,agenda=?,minutes=?,
                attendees_count=?,attendee_ids=?,next_meeting_date=?,recorded_by=? WHERE id=?")
                ->execute([$date,$type,$venue,$agenda,$minutes,$attendCount,$attendeeIds,$nextDate,$adminId,$editId]);
            logActivity('MEETING_UPDATE', "Updated minutes for $date");
            setFlash('success', t('flash_meet_updated'));
        } else {
            $pdo->prepare("INSERT INTO meeting_minutes (meeting_date,meeting_type,venue,agenda,minutes,attendees_count,attendee_ids,next_meeting_date,recorded_by)
                VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$date,$type,$venue,$agenda,$minutes,$attendCount,$attendeeIds,$nextDate,$adminId]);
            logActivity('MEETING_ADD', "Recorded minutes for $date");
            setFlash('success', t('flash_meet_saved'));
        }
        redirect(APP_URL . '/admin/meetings.php');
    }

    if ($action === 'delete_meeting') {
        $id = (int)($_POST['meeting_id'] ?? 0);
        $pdo->prepare("DELETE FROM meeting_minutes WHERE id=?")->execute([$id]);
        logActivity('MEETING_DELETE', "Deleted meeting minutes ID:$id");
        setFlash('success', t('flash_meet_deleted'));
        redirect(APP_URL . '/admin/meetings.php');
    }
}

// ── Edit mode ─────────────────────────────────────────────────────────────────
$editMeeting = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM meeting_minutes WHERE id=?");
    $s->execute([(int)$_GET['edit']]);
    $editMeeting = $s->fetch(PDO::FETCH_ASSOC);
}

// ── List all meetings ─────────────────────────────────────────────────────────
$meetings = $pdo->query("
    SELECT m.*, u.full_name AS recorded_by_name
    FROM meeting_minutes m LEFT JOIN users u ON m.recorded_by=u.id
    ORDER BY m.meeting_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Members for attendance checkboxes ────────────────────────────────────────
$allMembers = $pdo->query("SELECT id, full_name, membership_number FROM users WHERE role='member' AND status='active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

require_once ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-journal-text me-2 text-primary"></i><?= t('meetings_title') ?></h4>
        <small class="text-muted">Record and manage chama meeting minutes & attendance</small>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#meetingModal">
        <i class="bi bi-plus-lg me-1"></i>Record Meeting
    </button>
</div>

<?= getFlash() ?>

<?php if (empty($meetings)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <i class="bi bi-journal text-muted" style="font-size:3rem"></i>
        <p class="text-muted mt-3 mb-0"><?= t('adm_meet_none') ?><br>
        <small>Click "Record Meeting" to add the first meeting.</small></p>
    </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3"><?= t('lbl_date') ?></th>
                    <th><?= t('lbl_status') ?></th>
                    <th><?= t('meetings_venue') ?></th>
                    <th><?= t('meetings_attendees') ?></th>
                    <th><?= t('meetings_date') ?></th>
                    <th><?= t('contrib_recorded_by') ?></th>
                    <th class="text-end pe-3"><?= t('lbl_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($meetings as $m): ?>
                <tr>
                    <td class="ps-3 fw-semibold"><?= date('d M Y', strtotime($m['meeting_date'])) ?></td>
                    <td><span class="badge bg-primary"><?= htmlspecialchars($m['meeting_type']) ?></span></td>
                    <td class="small text-muted"><?= htmlspecialchars($m['venue'] ?: '—') ?></td>
                    <td>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                            <i class="bi bi-people me-1"></i><?= $m['attendees_count'] ?> members
                        </span>
                    </td>
                    <td class="small text-muted">
                        <?= $m['next_meeting_date'] ? date('d M Y', strtotime($m['next_meeting_date'])) : '—' ?>
                    </td>
                    <td class="small text-muted"><?= htmlspecialchars($m['recorded_by_name'] ?? 'System') ?></td>
                    <td class="text-end pe-3">
                        <button class="btn btn-xs btn-outline-primary me-1"
                            onclick="viewMinutes(<?= htmlspecialchars(json_encode($m)) ?>)" title="View">
                            <i class="bi bi-eye"></i>
                        </button>
                        <a href="?edit=<?= $m['id'] ?>" class="btn btn-xs btn-outline-secondary me-1" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this meeting record?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_meeting">
                            <input type="hidden" name="meeting_id" value="<?= $m['id'] ?>">
                            <button class="btn btn-xs btn-outline-danger" title="Delete"><i class="bi bi-trash3"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Add/Edit Meeting Modal -->
<div class="modal fade" id="meetingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-journal-plus me-2 text-primary"></i>
                    <?= $editMeeting ? 'Edit Meeting Minutes' : 'Record Meeting Minutes' ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_meeting">
                <input type="hidden" name="edit_id" value="<?= $editMeeting['id'] ?? '' ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Meeting Date <span class="text-danger">*</span></label>
                            <input type="date" name="meeting_date" class="form-control" required
                                value="<?= $editMeeting['meeting_date'] ?? date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small"><?= t('lbl_type') ?></label>
                            <select name="meeting_type" class="form-select">
                                <?php foreach(['Regular','Special','AGM','Emergency','Planning'] as $t): ?>
                                <option <?= ($editMeeting['meeting_type']??'Regular')===$t?'selected':'' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small"><?= t('meetings_venue') ?></label>
                            <input type="text" name="venue" class="form-control" placeholder="e.g. Chairman's house"
                                value="<?= htmlspecialchars($editMeeting['venue'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small"><?= t('meetings_agenda') ?></label>
                            <textarea name="agenda" class="form-control" rows="3"
                                placeholder="List agenda items..."><?= htmlspecialchars($editMeeting['agenda'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Minutes / Resolutions <span class="text-danger">*</span></label>
                            <textarea name="minutes" class="form-control" rows="5" required
                                placeholder="Record what was discussed and decided..."><?= htmlspecialchars($editMeeting['minutes'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small"><?= t('adm_meet_next_date') ?></label>
                            <input type="date" name="next_meeting_date" class="form-control"
                                value="<?= $editMeeting['next_meeting_date'] ?? '' ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small"><?= t('meetings_present') ?></label>
                            <?php
                            $presentIds = $editMeeting ? array_map('intval', explode(',', $editMeeting['attendee_ids'] ?? '')) : [];
                            ?>
                            <div class="row g-1" style="max-height:180px;overflow-y:auto;border:1px solid #dee2e6;border-radius:.375rem;padding:.5rem">
                                <?php foreach($allMembers as $mem): ?>
                                <div class="col-md-4">
                                    <div class="form-check form-check-sm">
                                        <input class="form-check-input" type="checkbox" name="attendees[]"
                                            value="<?= $mem['id'] ?>" id="att_<?= $mem['id'] ?>"
                                            <?= in_array($mem['id'], $presentIds) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="att_<?= $mem['id'] ?>">
                                            <?= htmlspecialchars($mem['full_name']) ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-1">
                                <button type="button" class="btn btn-xs btn-outline-secondary" onclick="toggleAll(true)"><?= t('adm_meet_select_all') ?></button>
                                <button type="button" class="btn btn-xs btn-outline-secondary ms-1" onclick="toggleAll(false)"><?= t('adm_meet_deselect') ?></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= t('btn_cancel') ?></button>
                    <button type="submit" class="btn btn-primary btn-sm px-4">
                        <i class="bi bi-save me-1"></i><?= $editMeeting ? 'Update' : 'Save' ?> Minutes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Minutes Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold" id="viewModalTitle"><?= t('adm_meet_heading') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>
</div>

<script>
<?php if($editMeeting): ?>
new bootstrap.Modal(document.getElementById('meetingModal')).show();
<?php endif; ?>

function viewMinutes(m) {
    document.getElementById('viewModalTitle').textContent = m.meeting_type + ' Meeting — ' + new Date(m.meeting_date).toLocaleDateString('en-GB',{day:'numeric',month:'long',year:'numeric'});
    document.getElementById('viewModalBody').innerHTML = `
        <div class="row g-3 mb-3">
            <div class="col-6"><small class="text-muted"><?= t('meetings_venue') ?></small><div class="fw-semibold">${m.venue||'—'}</div></div>
            <div class="col-3"><small class="text-muted"><?= t('adm_meet_attendance') ?></small><div class="fw-semibold">${m.attendees_count} members</div></div>
            <div class="col-3"><small class="text-muted"><?= t('adm_meet_next') ?></small><div class="fw-semibold">${m.next_meeting_date||'—'}</div></div>
        </div>
        ${m.agenda ? `<div class="mb-3"><strong class="small d-block mb-1"><?= t('meetings_agenda') ?></strong><div class="bg-light rounded p-2 small" style="white-space:pre-wrap">${m.agenda}</div></div>` : ''}
        <div class="mb-3"><strong class="small d-block mb-1"><?= t('meetings_minutes_label') ?></strong><div class="bg-light rounded p-3 small" style="white-space:pre-wrap">${m.minutes||'—'}</div></div>
        <div class="text-muted small">Recorded by: ${m.recorded_by_name||'Admin'} &nbsp;·&nbsp; ${m.created_at}</div>
    `;
    new bootstrap.Modal(document.getElementById('viewModal')).show();
}
function toggleAll(state) {
    document.querySelectorAll('#meetingModal input[type=checkbox]').forEach(cb => cb.checked = state);
}
</script>

<?php require_once ROOT . '/includes/footer.php'; ?>
