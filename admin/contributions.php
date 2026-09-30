<?php
/**
 * ChamaLedger — Admin Contributions Management
 * Record member payments, confirm/reject, view all history
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Contributions — ChamaLedger Admin';
require_once ROOT . '/includes/functions.php';
require_once ROOT . '/includes/mailer.php';
requireAdmin();
require_once ROOT . '/includes/header.php';

$pdo   = getDB();
$curr  = getSetting('currency', 'KES');
$adminId = (int)$_SESSION['user_id'];

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // Record a new contribution on behalf of a member
    if ($action === 'record') {
        $userId   = (int)($_POST['user_id']      ?? 0);
        $amount   = (float)($_POST['amount']     ?? 0);
        $month    = $_POST['payment_month']      ?? '';
        $method   = $_POST['payment_method']     ?? 'cash';
        $ref      = sanitize($_POST['reference_code'] ?? '');
        $notes    = sanitize($_POST['notes']     ?? '');
        $autoConf = isset($_POST['auto_confirm']);

        $errors = [];
        if (!$userId)             $errors[] = t('err_con_select_member');
        if ($amount <= 0)         $errors[] = t('err_con_amount');
        if (!$month)              $errors[] = t('err_con_month');

        if (empty($errors)) {
            // Convert YYYY-MM to date
            $payDate = $month . '-01';
            $status  = $autoConf ? 'confirmed' : 'pending';

            $stmt = $pdo->prepare('
                INSERT INTO contributions
                    (user_id, amount, payment_month, payment_method, reference_code, status,
                     confirmed_by, confirmed_at, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $userId, $amount, $payDate, $method, $ref, $status,
                $autoConf ? $adminId : null,
                $autoConf ? date('Y-m-d H:i:s') : null,
                $notes
            ]);

            $newContribId = (int)$pdo->lastInsertId();
            $contrib = $pdo->prepare('SELECT full_name FROM users WHERE id=?');
            $contrib->execute([$userId]);
            $mName = $contrib->fetchColumn();

            // Credit wallet immediately if auto-confirmed
            if ($autoConf) {
                try {
                    $wallet = new Wallet($pdo);
                    $wallet->credit($userId, $amount,
                        'Payment confirmed — ' . date('F Y', strtotime($payDate)),
                        'contribution', $newContribId);
                } catch(Exception $e) { error_log('Wallet credit: ' . $e->getMessage()); }
            }

            createNotification($userId, 'Contribution Recorded',
                "Your contribution of " . money($amount, $curr) . " for " . date('F Y', strtotime($payDate)) .
                ($autoConf ? " has been confirmed." : " is pending confirmation."),
                $autoConf ? 'success' : 'info',
                APP_URL . '/member/contributions.php');

            logActivity('CONTRIBUTION_RECORD', "Recorded {$curr}{$amount} for {$mName} [{$payDate}]");
            try {
                $smsM = $pdo->prepare("SELECT full_name,phone FROM users WHERE id=?"); $smsM->execute([$userId]); $smsM=$smsM->fetch();
                if ($smsM && $autoConf) SMS::paymentConfirmed($smsM, $amount, $payDate, $ref??'');
            } catch(Exception $e) {}
            setFlash('success', "Contribution of " . money($amount, $curr) . " recorded for {$mName}.");
        } else {
            setFlash('danger', implode(' | ', $errors));
        }
        redirect(APP_URL . '/admin/contributions.php');
    }

    // Confirm / Reject a pending contribution
    if (in_array($action, ['confirm', 'reject'])) {
        $id = (int)($_POST['contrib_id'] ?? 0);
        if ($id) {
            $newStatus = $action === 'confirm' ? 'confirmed' : 'rejected';
            $stmt = $pdo->prepare('
                UPDATE contributions
                   SET status=?, confirmed_by=?, confirmed_at=NOW()
                 WHERE id=?
            ');
            $stmt->execute([$newStatus, $adminId, $id]);

            // Notify member
            $row = $pdo->prepare('SELECT c.*, u.id as uid, u.full_name FROM contributions c JOIN users u ON c.user_id=u.id WHERE c.id=?');
            $row->execute([$id]);
            $c = $row->fetch();
            if ($c) {
                $msg = $action === 'confirm'
                    ? "Your contribution of " . money($c['amount'], $curr) . " for " . monthLabel($c['payment_month']) . " has been confirmed. ✅"
                    : "Your contribution of " . money($c['amount'], $curr) . " for " . monthLabel($c['payment_month']) . " was rejected. Please contact the admin.";
                createNotification($c['uid'], 'Contribution ' . ucfirst($newStatus), $msg,
                    $action === 'confirm' ? 'success' : 'danger',
                    APP_URL . '/member/contributions.php');
                // Credit wallet when confirmed
                if ($action === 'confirm') {
                    try {
                        $wallet = new Wallet($pdo);
                        $wallet->credit($c['user_id'], (float)$c['amount'],
                            'Payment confirmed — ' . date('F Y', strtotime($c['payment_month'])),
                            'contribution', $c['id']);
                    } catch(Exception $e) { error_log('Wallet: ' . $e->getMessage()); }
                }
            }
            logActivity('CONTRIBUTION_' . strtoupper($action), "Contribution ID:{$id} → {$newStatus}");
            if ($newStatus === 'confirmed') {
                try {
                    $cRow = $pdo->prepare("SELECT c.*,u.full_name,u.phone FROM contributions c JOIN users u ON u.id=c.user_id WHERE c.id=?"); $cRow->execute([$id]); $cRow=$cRow->fetch();
                    if ($cRow && $cRow['phone']) SMS::paymentConfirmed(['full_name'=>$cRow['full_name'],'phone'=>$cRow['phone']], (float)$cRow['amount'], date('F Y',strtotime($cRow['payment_month'])), $cRow['reference_code']??'');
                } catch(Exception $e) {}
            }
            setFlash($action === 'confirm' ? 'success' : 'warning', "Contribution " . $newStatus . ".");
        }
        redirect(APP_URL . '/admin/contributions.php');
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$validStatusFilters = ['all', 'pending', 'confirmed', 'rejected'];
$_statusRaw   = $_GET['status'] ?? 'all';
$filterStatus = in_array($_statusRaw, $validStatusFilters, true) ? $_statusRaw : 'all';
$filterMember = (int)($_GET['member'] ?? 0);
// Validate YYYY-MM format
$rawMonth    = $_GET['month'] ?? '';
$filterMonth = preg_match('/^\d{4}-\d{2}$/', $rawMonth) ? $rawMonth : '';

$where  = ['1=1'];
$params = [];

if ($filterStatus !== 'all') { $where[] = 'c.status = ?'; $params[] = $filterStatus; }
if ($filterMember > 0)       { $where[] = 'c.user_id = ?'; $params[] = $filterMember; }
if ($filterMonth)            { $where[] = 'DATE_FORMAT(c.payment_month,"%Y-%m") = ?'; $params[] = $filterMonth; }

$whereStr = implode(' AND ', $where);

$contributions = $pdo->prepare("
    SELECT c.*,
           u.full_name, u.membership_number,
           a.full_name AS confirmed_by_name
    FROM contributions c
    JOIN users u ON c.user_id = u.id
    LEFT JOIN users a ON c.confirmed_by = a.id
    WHERE {$whereStr}
    ORDER BY c.recorded_at DESC
    LIMIT 200
");
$contributions->execute($params);
$contributions = $contributions->fetchAll();

// Summary stats
$totalConfirmed = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status='confirmed'")->fetchColumn();
$totalPending   = (int)$pdo->query("SELECT COUNT(*) FROM contributions WHERE status='pending'")->fetchColumn();
$thisMonth      = date('Y-m-01');
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status='confirmed' AND payment_month=?");
$stmt->execute([$thisMonth]); $monthTotal = (float)$stmt->fetchColumn();

// Active members for dropdown
$members = $pdo->query("SELECT id, full_name, membership_number FROM users WHERE role='member' AND status='active' ORDER BY full_name")->fetchAll();
?>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><?= t('nav_contributions') ?></h4>
        <small class="text-muted"><?= t('adm_con_subtitle') ?></small>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#recordModal">
        <i class="bi bi-plus-lg me-1"></i>Record Payment
    </button>
</div>

<!-- KPI row -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-piggy-bank-fill"></i></div>
                <div>
                    <div class="text-muted small"><?= t('adm_con_total') ?></div>
                    <div class="fw-bold fs-5"><?= money($totalConfirmed, $curr) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-calendar-month"></i></div>
                <div>
                    <div class="text-muted small"><?= t('adm_con_this_month') ?> (<?= date('M Y') ?>)</div>
                    <div class="fw-bold fs-5"><?= money($monthTotal, $curr) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="text-muted small"><?= t('adm_con_pending') ?></div>
                    <div class="fw-bold fs-5"><?= $totalPending ?> <?= t('adm_con_payment') ?><?= $totalPending !== 1 ? 's' : '' ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// ── Pending Contributions Queue ───────────────────────────────────────────────
$pendingQueue = $pdo->query("
    SELECT c.*, u.full_name, u.membership_number, u.phone
    FROM contributions c
    JOIN users u ON c.user_id = u.id
    WHERE c.status = 'pending'
    ORDER BY c.recorded_at ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<?php if (!empty($pendingQueue)): ?>
<div class="card border-0 shadow-sm mb-4" style="border-left:4px solid #f59e0b !important">
    <div class="card-header bg-card d-flex align-items-center gap-2 py-3">
        <i class="bi bi-hourglass-split text-warning fs-5"></i>
        <div class="flex-grow-1">
            <strong><?= t('lbl_pending') ?> <?= t('lbl_approved') ?></strong>
            <small class="text-muted ms-2"><?= count($pendingQueue) ?> <?= t('contrib_pending') ?></small>
        </div>
        <span class="badge bg-warning text-dark rounded-pill"><?= count($pendingQueue) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.875rem">
            <thead style="background:#fffbeb">
                <tr>
                    <th class="ps-3"><?= t('members_name') ?></th>
                    <th><?= t('contrib_month') ?></th>
                    <th><?= t('contrib_amount') ?></th>
                    <th><?= t('contrib_method') ?></th>
                    <th><?= t('contrib_receipt') ?></th>
                    <th><?= t('lbl_date') ?></th>
                    <th class="text-end pe-3"><?= t('lbl_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($pendingQueue as $pq): ?>
                <tr>
                    <td class="ps-3">
                        <div class="fw-semibold small"><?= htmlspecialchars($pq['full_name']) ?></div>
                        <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($pq['membership_number']) ?></div>
                    </td>
                    <td class="small"><?= date('M Y', strtotime($pq['payment_month'])) ?></td>
                    <td class="fw-bold small"><?= money($pq['amount'], $curr) ?></td>
                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars(strtoupper($pq['payment_method'] ?? 'MPESA')) ?></span></td>
                    <td class="font-monospace small text-muted"><?= htmlspecialchars($pq['reference_code'] ?? '—') ?></td>
                    <td class="small text-muted"><?= date('d M, H:i', strtotime($pq['recorded_at'])) ?></td>
                    <td class="text-end pe-3">
                        <div class="d-flex gap-1 justify-content-end">
                            <form method="POST" style="display:inline" onsubmit="return confirm('Confirm this payment?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="confirm">
                                <input type="hidden" name="contrib_id" value="<?= $pq['id'] ?>">
                                <button class="btn btn-success btn-sm px-3">
                                    <i class="bi bi-check-lg me-1"></i>Confirm
                                </button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Reject this payment?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="contrib_id" value="<?= $pq['id'] ?>">
                                <button class="btn btn-outline-danger btn-sm">
                                    <i class="bi bi-x-lg me-1"></i>Reject
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1"><?= t('lbl_member') ?></label>
                <select name="member" class="form-select form-select-sm">
                    <option value=""><?= t('lbl_all_members') ?></option>
                    <?php foreach ($members as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $filterMember == $m['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($m['full_name']) ?> (<?= $m['membership_number'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1"><?= t('lbl_month') ?></label>
                <input type="month" name="month" class="form-control form-control-sm" value="<?= htmlspecialchars($filterMonth) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1"><?= t('contrib_status') ?></label>
                <select name="status" class="form-select form-select-sm">
                    <option value="all"      <?= $filterStatus==='all'       ? 'selected':'' ?>>All</option>
                    <option value="pending"  <?= $filterStatus==='pending'   ? 'selected':'' ?>><?= t('contrib_pending') ?></option>
                    <option value="confirmed"<?= $filterStatus==='confirmed' ? 'selected':'' ?>><?= t('contrib_confirmed') ?></option>
                    <option value="rejected" <?= $filterStatus==='rejected'  ? 'selected':'' ?>><?= t('contrib_rejected') ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
            </div>
            <div class="col-md-2">
                <a href="<?= APP_URL ?>/admin/contributions.php" class="btn btn-sm btn-outline-secondary w-100"><?= t('btn_reset') ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="contribTable">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th><?= t('members_name') ?></th>
                        <th><?= t('contrib_month') ?></th>
                        <th><?= t('contrib_amount') ?></th>
                        <th><?= t('contrib_method') ?></th>
                        <th><?= t('contrib_receipt') ?></th>
                        <th><?= t('contrib_status') ?></th>
                        <th><?= t('contrib_recorded_by') ?></th>
                        <th><?= t('lbl_date') ?></th>
                        <th><?= t('lbl_actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($contributions)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4"><?= t('adm_con_none') ?></td></tr>
                <?php else: ?>
                    <?php foreach ($contributions as $i => $c): ?>
                    <tr>
                        <td class="text-muted small"><?= $i+1 ?></td>
                        <td>
                            <div class="fw-semibold small"><?= htmlspecialchars($c['full_name']) ?></div>
                            <div class="text-muted" style="font-size:.72rem"><?= htmlspecialchars($c['membership_number'] ?? '') ?></div>
                        </td>
                        <td class="small fw-semibold"><?= monthLabel($c['payment_month']) ?></td>
                        <td class="small fw-bold text-success"><?= money($c['amount'], $curr) ?></td>
                        <td><span class="badge bg-light text-dark border text-capitalize"><?= $c['payment_method'] ?></span></td>
                        <td class="small font-monospace text-muted"><?= htmlspecialchars($c['reference_code'] ?: '—') ?></td>
                        <td><?= badgeStatus($c['status']) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($c['confirmed_by_name'] ?? '—') ?></td>
                        <td class="small text-muted"><?= formatDate($c['recorded_at'], 'd M Y H:i') ?></td>
                        <td>
                            <?php if ($c['status'] === 'pending'): ?>
                            <div class="d-flex gap-1">
                                <form method="POST" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="confirm">
                                    <input type="hidden" name="contrib_id" value="<?= $c['id'] ?>">
                                    <button class="btn btn-sm btn-success" title="Confirm">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="contrib_id" value="<?= $c['id'] ?>">
                                    <button class="btn btn-sm btn-danger" title="Reject"
                                            onclick="return confirm('Reject this contribution?')">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            </div>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Record Contribution Modal -->
<div class="modal fade" id="recordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i><?= t('adm_con_record') ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="record">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Member <span class="text-danger">*</span></label>
                        <select name="user_id" class="form-select" required>
                            <option value=""><?= t('lbl_select_member2') ?></option>
                            <?php foreach ($members as $m): ?>
                            <option value="<?= $m['id'] ?>">
                                <?= htmlspecialchars($m['full_name']) ?> (<?= $m['membership_number'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Amount (<?= $curr ?>) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control"
                                   value="<?= getSetting('monthly_contribution', '2000') ?>"
                                   min="0.01" step="0.01" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Payment Month <span class="text-danger">*</span></label>
                            <input type="month" name="payment_month" class="form-control"
                                   value="<?= date('Y-m') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= t('lbl_payment_method') ?></label>
                            <select name="payment_method" class="form-select">
                                <option value="cash"><?= t('lbl_cash') ?></option>
                                <option value="mpesa"><?= t('adm_con_mpesa') ?></option>
                                <option value="bank"><?= t('adm_con_bank') ?></option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= t('adm_con_ref') ?></label>
                            <input type="text" name="reference_code" id="adminContribRef" class="form-control" placeholder="e.g. QHY9DKL3X8">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes (optional)</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Any extra remarks…"></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="auto_confirm" id="autoConfirm" checked>
                                <label class="form-check-label small" for="autoConfirm">
                                    Auto-confirm this payment (mark as confirmed immediately)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= t('btn_cancel') ?></button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-save me-1"></i>Save Contribution
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<style>.mpesa-ref-feedback { font-size:.74rem;margin-top:.3rem;padding:.25rem .5rem;border-radius:6px;min-height:1.4rem; }
.mpesa-ref-feedback.valid { color:#00c471;background:rgba(0,196,113,.08); }
.mpesa-ref-feedback.invalid { color:#ef4444;background:rgba(239,68,68,.08); }
.mpesa-ref-feedback.checking { color:var(--text-muted); }
.mpesa-spin { display:inline-block;width:11px;height:11px;border:2px solid rgba(255,255,255,.2);border-top-color:var(--green);border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:4px; }</style>
<script>
var ADMIN_APP_URL2 = '<?= APP_URL ?>';
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
document.addEventListener('DOMContentLoaded',function(){ attachMpesaValidation('adminContribRef','adminContribRefFb',ADMIN_APP_URL2); });
</script>
<?php require_once ROOT . '/includes/footer.php'; ?>
