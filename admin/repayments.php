<?php
/**
 * CHAMA Financial Management System
 * Admin — Loan Repayments Management
 * Record, confirm, and track loan repayments from members
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Loan Repayments — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();
require_once ROOT . '/includes/header.php';

$pdo     = getDB();
$adminId = (int)$_SESSION['user_id'];
$curr    = getSetting('currency', 'KES');

// ── Handle POST Actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // Record a new repayment (admin-side)
    if ($action === 'record') {
        $loanId = (int)($_POST['loan_id']        ?? 0);
        $amount = (float)($_POST['amount']        ?? 0);
        $method = sanitize($_POST['payment_method'] ?? 'cash');
        $ref    = sanitize($_POST['reference_code'] ?? '');
        $notes  = sanitize($_POST['notes']           ?? '');

        $stmt = $pdo->prepare("SELECT * FROM loans WHERE id=? AND status NOT IN ('pending','rejected','completed')");
        $stmt->execute([$loanId]);
        $loan = $stmt->fetch();

        $errors = [];
        if (!$loan)        $errors[] = t('err_rep_invalid_loan');
        if ($amount <= 0)  $errors[] = t('err_rep_amount');
        if ($loan && $amount > $loan['balance']) {
            $errors[] = 'Amount exceeds remaining balance of ' . money($loan['balance'], $curr) . '.';
        }

        if (empty($errors)) {
            // Insert payment as confirmed (admin-recorded)
            $pdo->prepare('
                INSERT INTO loan_payments
                    (loan_id, user_id, amount, payment_method, reference_code, notes, status, confirmed_by, confirmed_at)
                VALUES (?, ?, ?, ?, ?, ?, "confirmed", ?, NOW())
            ')->execute([$loanId, $loan['user_id'], $amount, $method, $ref, $notes, $adminId]);

            // Update loan balance
            $newRepaid  = $loan['amount_repaid'] + $amount;
            $newBalance = $loan['balance'] - $amount;
            $newStatus  = $newBalance <= 0 ? 'completed' : $loan['status'];
            $completedAt = $newBalance <= 0 ? date('Y-m-d H:i:s') : null;

            $pdo->prepare('
                UPDATE loans SET amount_repaid=?, balance=?, status=?
                    ' . ($completedAt ? ', completed_at=NOW()' : '') . '
                WHERE id=?
            ')->execute([$newRepaid, max(0, $newBalance), $newStatus, $loanId]);

            // Notify member
            $msg = $newBalance <= 0
                ? "Congratulations! Your loan #{$loan['loan_number']} has been fully repaid. 🎉"
                : "Your loan repayment of " . money($amount, $curr) . " has been confirmed. Remaining balance: " . money($newBalance, $curr);

            createNotification($loan['user_id'], '✅ Repayment Recorded', $msg,
                $newBalance <= 0 ? 'success' : 'info', APP_URL . '/member/my_loans.php');

            logActivity('REPAYMENT_RECORD', "Admin recorded repayment: loan#{$loanId} {$curr}{$amount}");
            setFlash('success', 'Repayment of ' . money($amount, $curr) . ' recorded successfully.' . ($newBalance <= 0 ? ' Loan is now fully repaid!' : ''));
        } else {
            setFlash('danger', implode('<br>', $errors));
        }
        redirect(APP_URL . '/admin/repayments.php');
    }

    // Confirm a member-submitted repayment
    if ($action === 'confirm') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT lp.*, l.loan_number, l.balance, l.amount_repaid, l.user_id as loan_user_id
            FROM loan_payments lp JOIN loans l ON lp.loan_id = l.id
            WHERE lp.id=? AND lp.status='pending'");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();

        if ($payment) {
            $pdo->prepare("UPDATE loan_payments SET status='confirmed', confirmed_by=?, confirmed_at=NOW() WHERE id=?")
                ->execute([$adminId, $paymentId]);

            $newRepaid  = $payment['amount_repaid'] + $payment['amount'];
            $newBalance = $payment['balance'] - $payment['amount'];
            $newStatus  = $newBalance <= 0 ? 'completed' : 'disbursed';

            $pdo->prepare('UPDATE loans SET amount_repaid=?, balance=?, status=?' . ($newBalance <= 0 ? ', completed_at=NOW()' : '') . ' WHERE id=?')
                ->execute([$newRepaid, max(0, $newBalance), $newStatus, $payment['loan_id']]);

            createNotification($payment['user_id'], '✅ Repayment Confirmed',
                "Your repayment of " . money($payment['amount'], $curr) . " for loan #{$payment['loan_number']} has been confirmed.",
                'success', APP_URL . '/member/my_loans.php');

            logActivity('REPAYMENT_CONFIRM', "Confirmed repayment ID:{$paymentId}");
            setFlash('success', 'Repayment confirmed.');
        }
        redirect(APP_URL . '/admin/repayments.php');
    }

    // Reject a member-submitted repayment
    if ($action === 'reject') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $notes     = sanitize($_POST['reject_notes'] ?? '');

        $stmt = $pdo->prepare("SELECT lp.*, l.loan_number FROM loan_payments lp JOIN loans l ON lp.loan_id=l.id WHERE lp.id=?");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();

        if ($payment) {
            $pdo->prepare("UPDATE loan_payments SET status='rejected', notes=?, confirmed_by=?, confirmed_at=NOW() WHERE id=?")
                ->execute([$notes, $adminId, $paymentId]);

            createNotification($payment['user_id'], '❌ Repayment Rejected',
                "Your repayment submission for loan #{$payment['loan_number']} was rejected. Reason: " . ($notes ?: 'N/A'),
                'danger', APP_URL . '/member/my_loans.php');

            logActivity('REPAYMENT_REJECT', "Rejected repayment ID:{$paymentId}");
            setFlash('warning', 'Repayment rejected.');
        }
        redirect(APP_URL . '/admin/repayments.php');
    }
}

// ── Filters ────────────────────────────────────────────────────────────────────
$validStatuses = ['all', 'pending', 'confirmed', 'rejected'];
$_statusRaw    = $_GET['status'] ?? 'all';
$filterStatus  = in_array($_statusRaw, $validStatuses, true) ? $_statusRaw : 'all';
$filterMember  = (int)($_GET['member_id'] ?? 0);
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
if ($filterStatus !== 'all') { $where[] = 'lp.status=?'; $params[] = $filterStatus; }
if ($filterMember)           { $where[] = 'lp.user_id=?'; $params[] = $filterMember; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM loan_payments lp $whereSQL");
$totalStmt->execute($params);
$totalRows  = (int)$totalStmt->fetchColumn();
$totalPages = (int)ceil($totalRows / $perPage);

$payments = $pdo->prepare("
    SELECT lp.*, u.full_name, u.membership_number, l.loan_number, l.balance AS loan_balance,
           a.full_name AS confirmed_by_name
    FROM loan_payments lp
    JOIN users u ON lp.user_id = u.id
    JOIN loans l ON lp.loan_id = l.id
    LEFT JOIN users a ON lp.confirmed_by = a.id
    $whereSQL
    ORDER BY lp.paid_at DESC
    LIMIT $perPage OFFSET $offset
");
$payments->execute($params);
$payments = $payments->fetchAll();

// Summary
$sumStmt = $pdo->query("
    SELECT
        SUM(CASE WHEN status='confirmed' THEN amount ELSE 0 END) AS total_confirmed,
        SUM(CASE WHEN status='pending'   THEN amount ELSE 0 END) AS total_pending,
        COUNT(CASE WHEN status='pending' THEN 1 END) AS count_pending
    FROM loan_payments
");
$summary = $sumStmt->fetch();

// Active loans for record modal
$activeLoans = $pdo->query("
    SELECT l.id, l.loan_number, l.balance, u.full_name, u.membership_number
    FROM loans l JOIN users u ON l.user_id = u.id
    WHERE l.status IN ('approved','disbursed') AND l.balance > 0
    ORDER BY u.full_name
")->fetchAll();

// Members for filter
$members = $pdo->query("SELECT id, full_name, membership_number FROM users WHERE role='member' AND status='active' ORDER BY full_name")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><?= t('repay_title') ?></h4>
        <small class="text-muted"><?= t('repay_subtitle') ?></small>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#recordModal">
        <i class="bi bi-plus-circle me-1"></i>Record Repayment
    </button>
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="text-muted small"><?= t('repay_total_repaid') ?></div>
            <h5 class="fw-bold text-success mb-0"><?= money($summary['total_confirmed'] ?? 0, $curr) ?></h5>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="text-muted small"><?= t('repay_pending') ?></div>
            <h5 class="fw-bold text-warning mb-0"><?= money($summary['total_pending'] ?? 0, $curr) ?></h5>
            <small class="text-muted"><?= $summary['count_pending'] ?> <?= t('adm_rep_payments') ?></small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="text-muted small"><?= t('repay_active_loans') ?></div>
            <h5 class="fw-bold mb-0"><?= count($activeLoans) ?></h5>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="text-muted small"><?= t('repay_total_outstanding') ?></div>
            <?php
            $outstandingStmt = $pdo->query("SELECT COALESCE(SUM(balance),0) FROM loans WHERE status IN ('approved','disbursed')");
            $outstanding = (float)$outstandingStmt->fetchColumn();
            ?>
            <h5 class="fw-bold text-danger mb-0"><?= money($outstanding, $curr) ?></h5>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold"><?= t('lbl_member') ?></label>
                <select name="member_id" class="form-select form-select-sm">
                    <option value=""><?= t('lbl_all_members') ?></option>
                    <?php foreach ($members as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $filterMember == $m['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($m['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= t('lbl_status') ?></label>
                <select name="status" class="form-select form-select-sm">
                    <option value="all"       <?= $filterStatus==='all'       ? 'selected':'' ?>>All</option>
                    <option value="pending"   <?= $filterStatus==='pending'   ? 'selected':'' ?>><?= t('lbl_pending') ?></option>
                    <option value="confirmed" <?= $filterStatus==='confirmed' ? 'selected':'' ?>><?= t('lbl_confirmed') ?></option>
                    <option value="rejected"  <?= $filterStatus==='rejected'  ? 'selected':'' ?>><?= t('lbl_rejected') ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i><?= t('btn_filter') ?></button>
            </div>
            <div class="col-md-1">
                <a href="?" class="btn btn-outline-secondary btn-sm w-100"><?= t('btn_reset') ?></a>
            </div>
        </form>
    </div>
</div>

<!-- Payments Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-card">
        <h6 class="mb-0 fw-semibold">Repayment Records <span class="badge bg-secondary ms-2"><?= $totalRows ?></span></h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= t('repay_member') ?></th>
                        <th><?= t('repay_loan_no') ?></th>
                        <th><?= t('repay_total') ?></th>
                        <th><?= t('contrib_method') ?></th>
                        <th><?= t('contrib_receipt') ?></th>
                        <th><?= t('lbl_status') ?></th>
                        <th><?= t('lbl_date') ?></th>
                        <th><?= t('lbl_actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($payments)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">
                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>No repayments found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold small"><?= htmlspecialchars($p['full_name']) ?></div>
                            <div class="text-muted" style="font-size:.73rem"><?= $p['membership_number'] ?></div>
                        </td>
                        <td class="small font-monospace text-primary fw-semibold"><?= $p['loan_number'] ?></td>
                        <td class="small fw-bold text-success"><?= money($p['amount'], $curr) ?></td>
                        <td><span class="badge bg-light text-dark border text-capitalize"><?= $p['payment_method'] ?></span></td>
                        <td class="small font-monospace text-muted"><?= htmlspecialchars($p['reference_code'] ?: '—') ?></td>
                        <td><?= badgeStatus($p['status']) ?></td>
                        <td class="small text-muted"><?= formatDate($p['paid_at'], 'd M Y') ?></td>
                        <td>
                            <?php if ($p['status'] === 'pending'): ?>
                            <div class="d-flex gap-1">
                                <form method="POST" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="confirm">
                                    <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                    <button class="btn btn-success btn-sm" title="Confirm">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                                <button class="btn btn-danger btn-sm" title="Reject"
                                        onclick="showRejectModal(<?= $p['id'] ?>)">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <?php else: ?>
                                <span class="text-muted small">
                                    <?= htmlspecialchars($p['confirmed_by_name'] ?? '—') ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-card d-flex justify-content-between align-items-center">
        <small class="text-muted">Showing <?= $offset+1 ?>–<?= min($offset+$perPage, $totalRows) ?> of <?= $totalRows ?></small>
        <nav><ul class="pagination pagination-sm mb-0">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $p ?>&status=<?= $filterStatus ?>&member_id=<?= $filterMember ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<!-- Record Repayment Modal -->
<div class="modal fade" id="recordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="bi bi-arrow-return-left me-2 text-success"></i><?= t('lbl_record_repayment') ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="record">
                <div class="modal-body">
                    <?php if (empty($activeLoans)): ?>
                    <div class="alert alert-info small"><?= t('lbl_no_active_loans') ?></div>
                    <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Loan <span class="text-danger">*</span></label>
                        <select name="loan_id" class="form-select" id="loanSelect" required>
                            <option value=""><?= t('lbl_select_loan') ?></option>
                            <?php foreach ($activeLoans as $l): ?>
                            <option value="<?= $l['id'] ?>" data-balance="<?= $l['balance'] ?>">
                                <?= $l['loan_number'] ?> · <?= htmlspecialchars($l['full_name']) ?>
                                (<?= money($l['balance'], $curr) ?> · <?= ucfirst($l['status']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="balanceInfo" class="alert alert-info small d-none mb-3">
                        Remaining balance: <strong id="balanceDisplay"></strong>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Amount (<?= $curr ?>) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" id="repayAmount" class="form-control"
                                   min="0.01" step="0.01" placeholder="0.00" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= t('lbl_payment_method') ?></label>
                            <select name="payment_method" class="form-select">
                                <option value="cash"><?= t('lbl_cash') ?></option>
                                <option value="mpesa"><?= t('adm_rep_mpesa') ?></option>
                                <option value="bank">Bank</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold"><?= t('adm_rep_ref') ?></label>
                        <input type="text" name="reference_code" class="form-control" placeholder="e.g. MPESA transaction ID">
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold"><?= t('lbl_notes') ?></label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes"></textarea>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= t('btn_cancel') ?></button>
                    <?php if (!empty($activeLoans)): ?>
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-check2 me-1"></i>Save Repayment
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content shadow">
            <div class="modal-header">
                <h6 class="modal-title fw-bold text-danger"><?= t('adm_rep_reject') ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="payment_id" id="rejectPaymentId">
                <div class="modal-body">
                    <textarea name="reject_notes" class="form-control" rows="3"
                              placeholder="Reason for rejection..." required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= t('btn_cancel') ?></button>
                    <button type="submit" class="btn btn-danger btn-sm"><?= t('adm_rep_confirm_reject') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<'JS'
<script>
function showRejectModal(id) {
    document.getElementById('rejectPaymentId').value = id;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

document.getElementById('loanSelect')?.addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    const balance = opt.dataset.balance;
    const info = document.getElementById('balanceInfo');
    const display = document.getElementById('balanceDisplay');
    const amtInput = document.getElementById('repayAmount');
    if (balance && this.value) {
        display.textContent = 'KES ' + parseFloat(balance).toLocaleString('en-KE', {minimumFractionDigits: 2});
        info.classList.remove('d-none');
        amtInput.max = balance;
    } else {
        info.classList.add('d-none');
        amtInput.removeAttribute('max');
    }
});
</script>
JS;
require_once ROOT . '/includes/footer.php';
?>
