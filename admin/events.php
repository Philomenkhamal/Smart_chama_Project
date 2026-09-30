<?php
/**
 * ChamaLedger — Special Events & Contributions
 * Admin can create/edit/delete events, record contributions per member
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Events — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();

$pdo     = getDB();
$curr    = getSetting('currency', 'KES');
$adminId = (int)$_SESSION['user_id'];

// ── POST HANDLERS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // CREATE / EDIT EVENT
    if (in_array($action, ['create_event','edit_event'])) {
        $title       = sanitize($_POST['title'] ?? '');
        $desc        = sanitize($_POST['description'] ?? '');
        $date        = sanitize($_POST['event_date'] ?? '');
        $target      = (float)($_POST['target_amount'] ?? 0);
        $mandatory   = isset($_POST['is_mandatory']) ? 1 : 0;

        if ($action === 'create_event') {
            $pdo->prepare("INSERT INTO events (title,description,event_date,target_amount,is_mandatory,created_by) VALUES (?,?,?,?,?,?)")
                ->execute([$title, $desc, $date, $target ?: null, $mandatory, $adminId]);
            $newId = $pdo->lastInsertId();
            // Notify all members
            $members = $pdo->query("SELECT id FROM users WHERE role='member' AND status='active'")->fetchAll();
            foreach ($members as $m) {
                createNotification($m['id'], '📣 New Event: ' . $title,
                    "A new event has been created: {$title} on " . date('d M Y', strtotime($date)) . ($target ? ". Suggested contribution: {$curr} " . number_format($target,0) : ''),
                    'info', APP_URL . '/member/events.php');
            }
            logActivity('EVENT_CREATED', "Event: {$title}");
            setFlash('success', "Event '{$title}' created.");
        } else {
            $id = (int)$_POST['event_id'];
            $pdo->prepare("UPDATE events SET title=?,description=?,event_date=?,target_amount=?,is_mandatory=? WHERE id=?")
                ->execute([$title, $desc, $date, $target ?: null, $mandatory, $id]);
            logActivity('EVENT_UPDATED', "Event ID:{$id} updated");
            setFlash('success', "Event updated.");
        }
    }

    // DELETE EVENT
    if ($action === 'delete_event') {
        $id = (int)$_POST['event_id'];
        $ev = $pdo->prepare("SELECT title FROM events WHERE id=?"); $ev->execute([$id]); $ev = $ev->fetch();
        $pdo->prepare("DELETE FROM events WHERE id=?")->execute([$id]);
        logActivity('EVENT_DELETED', "Event: " . ($ev['title'] ?? $id));
        setFlash('success', t('flash_event_deleted'));
    }

    // CLOSE / REOPEN EVENT
    if (in_array($action, ['close_event','reopen_event'])) {
        $id = (int)$_POST['event_id'];
        $st = $action === 'close_event' ? 'closed' : 'active';
        $pdo->prepare("UPDATE events SET status=? WHERE id=?")->execute([$st, $id]);
        setFlash('success', 'Event ' . $st . '.');
    }

    // RECORD CONTRIBUTION
    if ($action === 'record_contribution') {
        $eventId = (int)$_POST['event_id'];
        $userId  = (int)$_POST['user_id'];
        $amount  = (float)$_POST['amount'];
        $method  = sanitize($_POST['payment_method'] ?? 'cash');
        $ref     = sanitize($_POST['reference_code']  ?? '');
        $notes   = sanitize($_POST['notes']           ?? '');
        $confirm = isset($_POST['auto_confirm']);

        if ($eventId && $userId && $amount > 0) {
            $status = $confirm ? 'confirmed' : 'pending';
            $pdo->prepare("
                INSERT INTO event_contributions (event_id,user_id,amount,payment_method,reference_code,notes,status,confirmed_by,confirmed_at)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([$eventId,$userId,$amount,$method,$ref,$notes,$status,
                $confirm ? $adminId : null, $confirm ? date('Y-m-d H:i:s') : null]);
            $ecId = (int)$pdo->lastInsertId();

            if ($confirm) {
                // Credit wallet
                $ev = $pdo->prepare("SELECT title FROM events WHERE id=?"); $ev->execute([$eventId]); $ev = $ev->fetch();
                $wallet = new Wallet($pdo);
                $wallet->credit($userId, $amount, "Event contribution: " . ($ev['title'] ?? 'Event'), 'event_contribution', $ecId);
            }

            $u = $pdo->prepare("SELECT full_name FROM users WHERE id=?"); $u->execute([$userId]); $u = $u->fetch();
            $ev2 = $pdo->prepare("SELECT title FROM events WHERE id=?"); $ev2->execute([$eventId]); $ev2 = $ev2->fetch();
            createNotification($userId, '✅ Event Contribution Recorded',
                "Your contribution of {$curr} " . number_format($amount,0) . " for '{$ev2['title']}' has been recorded.",
                'success', APP_URL . '/member/events.php');
            logActivity('EVENT_CONTRIBUTION', "{$curr}{$amount} from " . ($u['full_name']??$userId) . " for event ID {$eventId}");
            setFlash('success', t('flash_contrib_recorded'));
        }
    }

    // CONFIRM / REJECT CONTRIBUTION
    if (in_array($action, ['confirm_ec','reject_ec'])) {
        $ecId   = (int)$_POST['ec_id'];
        $st     = $action === 'confirm_ec' ? 'confirmed' : 'rejected';
        $ec     = $pdo->prepare("SELECT * FROM event_contributions WHERE id=?"); $ec->execute([$ecId]); $ec = $ec->fetch();
        if ($ec) {
            $pdo->prepare("UPDATE event_contributions SET status=?,confirmed_by=?,confirmed_at=NOW() WHERE id=?")
                ->execute([$st, $adminId, $ecId]);
            if ($st === 'confirmed') {
                $ev = $pdo->prepare("SELECT title FROM events WHERE id=?"); $ev->execute([$ec['event_id']]); $ev = $ev->fetch();
                $wallet = new Wallet($pdo);
                $wallet->credit($ec['user_id'], (float)$ec['amount'], "Event contribution confirmed: " . ($ev['title'] ?? ''), 'event_contribution', $ecId);
            }
            setFlash($st === 'confirmed' ? 'success' : 'warning', "Contribution {$st}.");
        }
    }

    redirect(APP_URL . '/admin/events.php' . (isset($_POST['event_id']) ? '?view=' . (int)$_POST['event_id'] : ''));
}

// ── DATA ──────────────────────────────────────────────────────────────────────
$viewId  = (int)($_GET['view'] ?? 0);
$events  = $pdo->query("
    SELECT e.*, u.full_name AS created_by_name,
        (SELECT COUNT(*) FROM event_contributions ec WHERE ec.event_id=e.id AND ec.status='confirmed') AS contrib_count,
        (SELECT COALESCE(SUM(ec.amount),0) FROM event_contributions ec WHERE ec.event_id=e.id AND ec.status='confirmed') AS total_raised
    FROM events e LEFT JOIN users u ON u.id=e.created_by
    ORDER BY e.event_date DESC
")->fetchAll();

$viewEvent = null; $eventContribs = []; $members = [];
if ($viewId) {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id=?"); $stmt->execute([$viewId]); $viewEvent = $stmt->fetch();

    $stmt2 = $pdo->prepare("
        SELECT ec.*, u.full_name, u.membership_number
        FROM event_contributions ec JOIN users u ON u.id=ec.user_id
        WHERE ec.event_id=? ORDER BY ec.recorded_at DESC
    ");
    $stmt2->execute([$viewId]); $eventContribs = $stmt2->fetchAll();
}
$members = $pdo->query("SELECT id,full_name,membership_number FROM users WHERE role='member' AND status='active' ORDER BY full_name")->fetchAll();

// Edit modal data
$editEvent = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id=?"); $stmt->execute([(int)$_GET['edit']]); $editEvent = $stmt->fetch();
}

require_once ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><?= t('events_title') ?></h4>
        <small class="text-muted"><?= t('events_admin_subtitle') ?>.</small>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createModal">
        <i class="bi bi-plus-circle me-1"></i>New Event
    </button>
</div>

<?php if ($viewEvent): ?>
<!-- ── EVENT DETAIL VIEW ──────────────────────────────────────────────────── -->
<div class="mb-3">
    <a href="<?= APP_URL ?>/admin/events.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>All Events
    </a>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h5 class="fw-bold mb-1"><?= htmlspecialchars($viewEvent['title']) ?></h5>
                <div style="color:var(--text-muted);font-size:.85rem"><?= htmlspecialchars($viewEvent['description'] ?? '') ?></div>
                <div class="mt-2" style="font-size:.82rem">
                    <i class="bi bi-calendar3 me-1"></i><?= date('d M Y', strtotime($viewEvent['event_date'])) ?>
                    <?php if ($viewEvent['target_amount']): ?>
                    &nbsp;·&nbsp;<i class="bi bi-bullseye me-1"></i><?= t('events_target') ?> <?= $curr ?> <?= number_format($viewEvent['target_amount'],0) ?> per member
                    <?php endif; ?>
                    <?php if ($viewEvent['is_mandatory']): ?>
                    &nbsp;·&nbsp;<span class="badge bg-danger bg-opacity-15 text-danger" style="font-size:.7rem"><?= t('events_mandatory') ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <span class="badge bg-<?= $viewEvent['status']==='active'?'success':'secondary' ?> bg-opacity-15 text-<?= $viewEvent['status']==='active'?'success':'secondary' ?>"><?= ucfirst($viewEvent['status']) ?></span>
                <a href="?edit=<?= $viewEvent['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i><?= t('btn_edit') ?></a>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="<?= $viewEvent['status']==='active'?'close_event':'reopen_event' ?>">
                    <input type="hidden" name="event_id" value="<?= $viewEvent['id'] ?>">
                    <button class="btn btn-sm btn-outline-warning"><?= $viewEvent['status']==='active'?'Close Event':'Reopen' ?></button>
                </form>
                <form method="POST" onsubmit="return confirm('Delete this event and ALL its contributions?')" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="delete_event">
                    <input type="hidden" name="event_id" value="<?= $viewEvent['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
            </div>
        </div>
        <!-- Stats -->
        <?php
        $totalRaised = array_sum(array_column(array_filter($eventContribs, fn($e)=>$e['status']==='confirmed'), 'amount'));
        $contribCount = count(array_filter($eventContribs, fn($e)=>$e['status']==='confirmed'));
        ?>
        <div class="row g-3 mt-1">
            <div class="col-4"><div class="card border-0 p-3 text-center" style="background:rgba(0,196,113,.08)">
                <div style="font-size:1.3rem;font-weight:800;color:#00c471"><?= $curr ?> <?= number_format($totalRaised,0) ?></div>
                <div style="font-size:.72rem;color:var(--text-muted)"><?= t('events_total_raised') ?></div>
            </div></div>
            <div class="col-4"><div class="card border-0 p-3 text-center" style="background:rgba(59,130,246,.08)">
                <div style="font-size:1.3rem;font-weight:800;color:#3b82f6"><?= $contribCount ?></div>
                <div style="font-size:.72rem;color:var(--text-muted)"><?= t('events_members_contrib') ?></div>
            </div></div>
            <div class="col-4"><div class="card border-0 p-3 text-center" style="background:rgba(245,158,11,.08)">
                <div style="font-size:1.3rem;font-weight:800;color:#f59e0b"><?= count($members) - $contribCount ?></div>
                <div style="font-size:.72rem;color:var(--text-muted)"><?= t('adm_eve_not_yet') ?></div>
            </div></div>
        </div>
    </div>
</div>

<!-- Record Contribution -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header border-0"><h6 class="mb-0"><i class="bi bi-plus-circle me-2 text-success"></i><?= t('adm_eve_record') ?></h6></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="record_contribution">
                    <input type="hidden" name="event_id" value="<?= $viewEvent['id'] ?>">
                    <div class="mb-2">
                        <label class="form-label" style="font-size:.8rem"><?= t('lbl_member') ?></label>
                        <select name="user_id" class="form-select form-select-sm" required>
                            <option value=""><?= t('lbl_select_member') ?></option>
                            <?php foreach ($members as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" style="font-size:.8rem"><?= t('lbl_amount') ?> (<?= $curr ?>)</label>
                        <input type="number" name="amount" class="form-control form-control-sm" min="0.01" step="0.01"
                            value="<?= $viewEvent['target_amount'] ? (int)$viewEvent['target_amount'] : '' ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" style="font-size:.8rem"><?= t('lbl_method') ?></label>
                        <select name="payment_method" class="form-select form-select-sm">
                            <option value="cash"><?= t('lbl_cash') ?></option>
                            <option value="mpesa">M-Pesa</option>
                            <option value="bank"><?= t('lbl_bank') ?></option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" style="font-size:.8rem"><?= t('adm_eve_ref') ?></label>
                        <input type="text" name="reference_code" id="adminEvRef" class="form-control form-control-sm" placeholder="e.g. QHX7Y8Z9AB" style="font-family:monospace;text-transform:uppercase" autocomplete="off">
                        <div id="adminEvRefFeedback" class="mpesa-ref-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="auto_confirm" id="autoConfirm" checked>
                            <label class="form-check-label" for="autoConfirm" style="font-size:.82rem"><?= t('adm_eve_confirm_immed') ?></label>
                        </div>
                    </div>
                    <button class="btn btn-success w-100 btn-sm fw-bold"><?= t('adm_eve_record_pay') ?></button>
                </form>
            </div>
        </div>
    </div>

    <!-- Contributions Table -->
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header border-0 d-flex justify-content-between">
                <h6 class="mb-0"><i class="bi bi-list-check me-2 text-success"></i><?= t('nav_contributions') ?> (<?= count($eventContribs) ?>)</h6>
                <a href="<?= APP_URL ?>/admin/reports.php?event_id=<?= $viewEvent['id'] ?>" class="btn btn-xs btn-outline-secondary" style="font-size:.72rem;padding:.2rem .6rem">
                    <i class="bi bi-bar-chart me-1"></i>Report
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="font-size:.82rem">
                    <thead><tr><th class="ps-3"><?= t('members_name') ?></th><th class="text-end"><?= t('lbl_amount') ?></th><th><?= t('contrib_method') ?></th><th><?= t('contrib_receipt') ?></th><th><?= t('lbl_status') ?></th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($eventContribs as $ec):
                        $sb = $ec['status']==='confirmed'?'success':($ec['status']==='rejected'?'danger':'warning');
                    ?>
                    <tr>
                        <td class="ps-3">
                            <div class="fw-semibold"><?= htmlspecialchars($ec['full_name']) ?></div>
                            <div style="font-size:.7rem;color:var(--text-muted)"><?= $ec['membership_number'] ?? '' ?></div>
                        </td>
                        <td class="text-end fw-bold" style="color:#00c471"><?= $curr ?> <?= number_format($ec['amount'],0) ?></td>
                        <td style="text-transform:capitalize"><?= $ec['payment_method'] ?></td>
                        <td style="font-family:monospace;font-size:.72rem"><?= htmlspecialchars($ec['reference_code'] ?? '—') ?></td>
                        <td><span class="badge bg-<?= $sb ?> bg-opacity-15 text-<?= $sb ?>" style="font-size:.7rem"><?= ucfirst($ec['status']) ?></span></td>
                        <td>
                            <?php if ($ec['status'] === 'pending'): ?>
                            <div class="d-flex gap-1">
                                <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="confirm_ec"><input type="hidden" name="ec_id" value="<?= $ec['id'] ?>"><input type="hidden" name="event_id" value="<?= $viewEvent['id'] ?>"><button class="btn btn-xs btn-success" style="font-size:.72rem;padding:.2rem .5rem" title="Confirm">✓</button></form>
                                <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="reject_ec"><input type="hidden" name="ec_id" value="<?= $ec['id'] ?>"><input type="hidden" name="event_id" value="<?= $viewEvent['id'] ?>"><button class="btn btn-xs btn-danger" style="font-size:.72rem;padding:.2rem .5rem" title="Reject">✕</button></form>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($eventContribs)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4"><?= t('lbl_no_contributions') ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($totalRaised > 0): ?>
                    <tfoot><tr style="font-weight:800;border-top:2px solid var(--border)">
                        <td class="ps-3"><?= t('lbl_total_label') ?></td>
                        <td class="text-end" style="color:#00c471"><?= $curr ?> <?= number_format($totalRaised,0) ?></td>
                        <td colspan="4"></td>
                    </tr></tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ── EVENTS LIST ─────────────────────────────────────────────────────────── -->
<div class="row g-3">
<?php foreach ($events as $ev):
    $statusColor = $ev['status']==='active' ? '#00c471' : '#6b7280';
?>
<div class="col-md-6 col-lg-4">
    <div class="card border-0 shadow-sm h-100" style="border-left:3px solid <?= $statusColor ?>!important">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="fw-bold" style="font-size:.92rem"><?= htmlspecialchars($ev['title']) ?></div>
                    <div style="font-size:.75rem;color:var(--text-muted)"><?= date('d M Y', strtotime($ev['event_date'])) ?></div>
                </div>
                <span class="badge bg-<?= $ev['status']==='active'?'success':'secondary' ?> bg-opacity-15 text-<?= $ev['status']==='active'?'success':'secondary' ?>" style="font-size:.68rem"><?= ucfirst($ev['status']) ?></span>
            </div>
            <?php if ($ev['description']): ?>
            <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:.75rem"><?= htmlspecialchars(substr($ev['description'],0,80)) ?><?= strlen($ev['description'])>80?'…':'' ?></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between align-items-center" style="font-size:.78rem">
                <span style="color:#00c471;font-weight:700"><?= $curr ?> <?= number_format($ev['total_raised'],0) ?> <?= t('adm_eve_raised') ?></span>
                <span style="color:var(--text-muted)"><?= $ev['contrib_count'] ?> <?= t('adm_eve_members') ?></span>
            </div>
            <?php if ($ev['target_amount']): ?>
            <div class="progress mt-2" style="height:4px;background:rgba(255,255,255,.08)">
                <?php $pct = min(100, round(($ev['total_raised'] / ($ev['target_amount'] * count($members))) * 100)); ?>
                <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
            </div>
            <div style="font-size:.68rem;color:var(--text-muted);margin-top:.3rem"><?= $pct ?>% of target (<?= $curr ?> <?= number_format($ev['target_amount'],0) ?>/member)</div>
            <?php endif; ?>
            <div class="d-flex gap-2 mt-3">
                <a href="?view=<?= $ev['id'] ?>" class="btn btn-sm btn-primary flex-grow-1" style="font-size:.78rem"><?= t('adm_eve_view_add') ?></a>
                <a href="?edit=<?= $ev['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                <form method="POST" onsubmit="return confirm('Delete this event?')" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="delete_event">
                    <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php if (empty($events)): ?>
<div class="col-12 text-center text-muted py-5">
    <i class="bi bi-calendar-event" style="font-size:2rem;opacity:.3"></i>
    <p class="mt-2"><?= t('events_none') ?> yet. Create one to start collecting special contributions.</p>
</div>
<?php endif; ?>
</div>
<?php endif; ?>

<!-- ── CREATE / EDIT MODAL ─────────────────────────────────────────────────── -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background:var(--card-bg);border:1px solid var(--border)">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold" id="modalTitle"><?= $editEvent ? 'Edit Event' : 'New Event' ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="eventForm">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="<?= $editEvent ? 'edit_event' : 'create_event' ?>" id="formAction">
                    <?php if ($editEvent): ?>
                    <input type="hidden" name="event_id" value="<?= $editEvent['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label" style="font-size:.82rem"><?= t('adm_eve_title') ?> *</label>
                        <input type="text" name="title" class="form-control" required
                            placeholder="e.g. Hospital Visit — John's Father"
                            value="<?= htmlspecialchars($editEvent['title'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-size:.82rem"><?= t('lbl_description') ?></label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Details…"><?= htmlspecialchars($editEvent['description'] ?? '') ?></textarea>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col">
                            <label class="form-label" style="font-size:.82rem"><?= t('adm_eve_date') ?> *</label>
                            <input type="date" name="event_date" class="form-control" required
                                value="<?= $editEvent['event_date'] ?? date('Y-m-d') ?>">
                        </div>
                        <div class="col">
                            <label class="form-label" style="font-size:.82rem"><?= t('adm_eve_suggested') ?> (<?= $curr ?>)</label>
                            <input type="number" name="target_amount" class="form-control" min="0" step="1"
                                placeholder="Optional"
                                value="<?= $editEvent['target_amount'] ?? '' ?>">
                        </div>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="is_mandatory" id="isMandatory"
                            <?= ($editEvent['is_mandatory'] ?? 0) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="isMandatory" style="font-size:.82rem"><?= t('adm_eve_mandatory_lbl') ?></label>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-bold">
                        <?= $editEvent ? 'Save Changes' : 'Create Event' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
<?php if ($editEvent): ?>
// Auto-open modal for edit
document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('createModal')).show();
});
<?php endif; ?>
</script>

<style>
.mpesa-ref-feedback { font-size:.74rem;margin-top:.3rem;padding:.25rem .5rem;border-radius:6px;min-height:1.4rem; }
.mpesa-ref-feedback.valid { color:#00c471;background:rgba(0,196,113,.08); }
.mpesa-ref-feedback.invalid { color:#ef4444;background:rgba(239,68,68,.08); }
.mpesa-ref-feedback.checking { color:var(--text-muted); }
.mpesa-spin { display:inline-block;width:11px;height:11px;border:2px solid rgba(255,255,255,.2);border-top-color:var(--green);border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:4px; }
</style>

<script>
var ADMIN_APP_URL = '<?= APP_URL ?>';
function attachMpesaValidation(inputId, feedbackId, appUrl) {
    var input = document.getElementById(inputId);
    var feedback = document.getElementById(feedbackId);
    if (!input || !feedback) return;
    var timer = null;

    // Safaricom receipt format: starts with letter, 10 chars total, A-Z and 0-9 only
    function mpesaFormatCheck(code) {
        if (!code) return null;
        if (!/^[A-Z0-9]+$/.test(code))   return 'Only uppercase letters and numbers allowed';
        if (!/^[A-Z]/.test(code))         return 'M-Pesa codes always start with a letter (e.g. P, Q, R…)';
        if (code.length < 10)             return code.length + '/10 characters — keep going…';
        if (code.length > 10)             return 'Too long — M-Pesa codes are exactly 10 characters';
        if (!/[0-9]/.test(code))          return 'Code must contain at least one number';
        if (!/[A-Z]/.test(code.slice(1))) return 'Code must contain at least one letter after the first';
        return 'ok';
    }

    input.addEventListener('input', function() {
        var code = input.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 10);
        input.value = code;
        feedback.innerHTML = '';
        feedback.className = 'mpesa-ref-feedback';
        input.className = input.className.replace(/ ?is-valid| ?is-invalid/g, '');
        if (!code) return;

        var check = mpesaFormatCheck(code);
        if (check !== 'ok') {
            var isTyping = code.length < 10 && check.indexOf('/10') !== -1;
            feedback.innerHTML = '<i class="bi bi-' + (isTyping ? 'hourglass-split' : 'x-circle') + ' me-1"></i>' + check;
            feedback.className = 'mpesa-ref-feedback ' + (isTyping ? 'checking' : 'invalid');
            if (!isTyping) input.classList.add('is-invalid');
            return;
        }

        // Valid format — check against our database
        feedback.innerHTML = '<span class="mpesa-spin"></span>Checking…';
        feedback.className = 'mpesa-ref-feedback checking';
        clearTimeout(timer);
        timer = setTimeout(function() {
            fetch(appUrl + '/api/mpesa_verify.php?code=' + encodeURIComponent(code), { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    feedback.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Valid M-Pesa code ✓';
                    feedback.className = 'mpesa-ref-feedback valid';
                    input.classList.add('is-valid');
                    input.classList.remove('is-invalid');
                } else {
                    feedback.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i>' + data.message;
                    feedback.className = 'mpesa-ref-feedback invalid';
                    input.classList.add('is-invalid');
                    input.classList.remove('is-valid');
                }
            })
            .catch(function() {
                feedback.innerHTML = '<i class="bi bi-wifi-off me-1"></i>Could not verify — submit anyway';
                feedback.className = 'mpesa-ref-feedback checking';
            });
        }, 500);
    });
}
document.addEventListener('DOMContentLoaded', function() {
    attachMpesaValidation('adminEvRef','adminEvRefFeedback', ADMIN_APP_URL);
});
</script>
<?php require_once ROOT . '/includes/footer.php'; ?>
