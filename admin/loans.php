<?php
/**
 * ChamaLedger — Admin Loan Management
 * Review applications, approve/reject, record disbursement
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Loan Management — ChamaLedger Admin';
require_once ROOT . '/includes/functions.php';
require_once ROOT . '/includes/loan_eligibility.php';
requireAdmin();
require_once ROOT . '/includes/header.php';

$pdo     = getDB();
$curr    = getSetting('currency', 'KES');
$adminId = (int)$_SESSION['user_id'];

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $loanId = (int)($_POST['loan_id'] ?? 0);

    // Approve loan
    if ($action === 'approve' && $loanId) {
        $approvedAmt  = (float)($_POST['amount_approved'] ?? 0);
        $reviewNotes  = sanitize($_POST['review_notes'] ?? '');

        $stmt = $pdo->prepare('SELECT * FROM loans WHERE id=? AND status="pending"');
        $stmt->execute([$loanId]);
        $loan = $stmt->fetch();

        if ($loan && $approvedAmt > 0) {
            $calc    = calculateLoan($approvedAmt, $loan['interest_rate'], $loan['duration_months']);
            $dueDate = date('Y-m-d', strtotime("+{$loan['duration_months']} months"));

            $pdo->prepare('
                UPDATE loans SET
                    status="approved", amount_approved=?, total_repayable=?, balance=?,
                    due_date=?, reviewed_by=?, review_date=NOW(), review_notes=?
                WHERE id=?
            ')->execute([
                $approvedAmt, $calc['total_repayable'], $calc['total_repayable'],
                $dueDate, $adminId, $reviewNotes, $loanId
            ]);

            $stmt = $pdo->prepare('SELECT u.id, u.full_name FROM loans l JOIN users u ON l.user_id=u.id WHERE l.id=?');
            $stmt->execute([$loanId]);
            $info = $stmt->fetch();
            createNotification($info['id'], '✅ Loan Approved',
                "Your loan of " . money($approvedAmt, $curr) . " ({$loan['loan_number']}) has been approved! Awaiting disbursement.",
                'success', APP_URL . '/member/my_loans.php');
            logActivity('LOAN_APPROVE', "Loan #{$loan['loan_number']} approved {$curr}{$approvedAmt}");
            try {
                $lUser = $pdo->prepare("SELECT full_name,phone FROM users WHERE id=?"); $lUser->execute([$loan['user_id']]); $lUser=$lUser->fetch();
                if ($lUser) SMS::loanApproved($lUser, $approvedAmt, $loan['loan_number']??'');
            } catch(Exception $e) {}
            setFlash('success', "Loan {$loan['loan_number']} approved for " . money($approvedAmt, $curr));
        }
    }

    // Reject loan
    if ($action === 'reject' && $loanId) {
        $reviewNotes = sanitize($_POST['review_notes'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM loans WHERE id=?');
        $stmt->execute([$loanId]);
        $loan = $stmt->fetch();
        if ($loan) {
            $pdo->prepare('UPDATE loans SET status="rejected", reviewed_by=?, review_date=NOW(), review_notes=? WHERE id=?')
                ->execute([$adminId, $reviewNotes, $loanId]);
            $stmt = $pdo->prepare('SELECT u.id FROM loans l JOIN users u ON l.user_id=u.id WHERE l.id=?');
            $stmt->execute([$loanId]);
            $uid = $stmt->fetchColumn();
            createNotification($uid, '❌ Loan Rejected',
                "Your loan application {$loan['loan_number']} was not approved. Reason: {$reviewNotes}",
                'danger', APP_URL . '/member/my_loans.php');
            logActivity('LOAN_REJECT', "Loan #{$loan['loan_number']} rejected");
            setFlash('warning', "Loan {$loan['loan_number']} rejected.");
        }
    }

    // Disburse loan
    if ($action === 'disburse' && $loanId) {
        $method = $_POST['disbursement_method'] ?? 'cash';
        $ref    = sanitize($_POST['disbursement_ref'] ?? '');
        $stmt   = $pdo->prepare('SELECT * FROM loans WHERE id=? AND status="approved"');
        $stmt->execute([$loanId]);
        $loan   = $stmt->fetch();
        if ($loan) {
            $pdo->prepare('
                UPDATE loans SET status="disbursed", disbursed_at=NOW(),
                    disbursement_method=?, disbursement_ref=? WHERE id=?
            ')->execute([$method, $ref, $loanId]);
            $stmt = $pdo->prepare('SELECT u.id FROM loans l JOIN users u ON l.user_id=u.id WHERE l.id=?');
            $stmt->execute([$loanId]);
            $uid = $stmt->fetchColumn();
            createNotification($uid, '💰 Loan Disbursed',
                "Your loan of " . money($loan['amount_approved'], $curr) . " has been disbursed via {$method}. Ref: {$ref}",
                'success', APP_URL . '/member/my_loans.php');
            logActivity('LOAN_DISBURSE', "Loan #{$loan['loan_number']} disbursed via {$method}");
            setFlash('success', "Loan {$loan['loan_number']} marked as disbursed.");
        }
    }

    redirect(APP_URL . '/admin/loans.php');
}

// ── Fetch loans with filters ───────────────────────────────────────────────────
$validStatuses = ['pending', 'approved', 'rejected', 'disbursed', 'completed', 'defaulted', 'all'];
$_statusRaw    = $_GET['status'] ?? 'pending';
$filterStatus  = in_array($_statusRaw, $validStatuses, true) ? $_statusRaw : 'pending';

if ($filterStatus !== 'all') {
    $loanStmt = $pdo->prepare("
        SELECT l.*, u.full_name, u.membership_number, u.phone
        FROM loans l JOIN users u ON l.user_id = u.id
        WHERE l.status = ?
        ORDER BY l.applied_at DESC LIMIT 200
    ");
    $loanStmt->execute([$filterStatus]);
} else {
    $loanStmt = $pdo->prepare("
        SELECT l.*, u.full_name, u.membership_number, u.phone
        FROM loans l JOIN users u ON l.user_id = u.id
        ORDER BY l.applied_at DESC LIMIT 200
    ");
    $loanStmt->execute();
}
$loans = $loanStmt->fetchAll();

$counts = [];
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE status=?");
foreach (['pending','approved','disbursed','completed','rejected','defaulted'] as $s) {
    $countStmt->execute([$s]);
    $counts[$s] = (int)$countStmt->fetchColumn();
}
$counts['all'] = array_sum($counts);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><?= t('loans_admin_title') ?></h4>
        <small class="text-muted"><?= t('loans_subtitle') ?></small>
    </div>
</div>

<?php
// ── Overdue loans alert ───────────────────────────────────────────────────────
$overdueLoans = $pdo->query("
    SELECT l.*, u.full_name, u.membership_number, u.phone,
           DATEDIFF(CURDATE(), l.due_date) AS days_overdue
    FROM loans l
    JOIN users u ON l.user_id = u.id
    WHERE l.status IN ('approved','disbursed')
    AND l.due_date < CURDATE()
    AND l.balance > 0
    ORDER BY days_overdue DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<?php if (!empty($overdueLoans)): ?>
<div class="card border-0 shadow-sm mb-4" style="border-left:4px solid #dc3545 !important">
    <div class="card-header bg-card d-flex align-items-center gap-2 py-3">
        <i class="bi bi-exclamation-octagon-fill text-danger fs-5"></i>
        <div class="flex-grow-1">
            <strong class="text-danger"><?= t('loans_overdue') ?> — <?= count($overdueLoans) ?> <?= t('loans_defaulters') ?></strong>
            <small class="text-muted ms-2"><?= t('loans_overdue_desc') ?></small>
        </div>
        <span class="badge bg-danger rounded-pill"><?= count($overdueLoans) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.875rem">
            <thead style="background:#fff5f5">
                <tr>
                    <th class="ps-3"><?= t('lbl_member') ?></th>
                    <th><?= t('adm_loan_number') ?></th>
                    <th><?= t('loans_balance') ?></th>
                    <th><?= t('loans_due') ?></th>
                    <th><?= t('lbl_date') ?></th>
                    <th><?= t('lbl_phone') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($overdueLoans as $ol): ?>
                <tr>
                    <td class="ps-3">
                        <div class="fw-semibold small"><?= htmlspecialchars($ol['full_name']) ?></div>
                        <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($ol['membership_number']) ?></div>
                    </td>
                    <td class="font-monospace small"><?= htmlspecialchars($ol['loan_number']) ?></td>
                    <td class="fw-bold text-danger"><?= money($ol['balance'], $curr) ?></td>
                    <td class="small text-danger"><?= date('d M Y', strtotime($ol['due_date'])) ?></td>
                    <td>
                        <span class="badge <?= $ol['days_overdue'] > 30 ? 'bg-danger' : 'bg-warning text-dark' ?>">
                            <?= $ol['days_overdue'] ?> days
                        </span>
                    </td>
                    <td class="small text-muted"><?= htmlspecialchars($ol['phone'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-card d-flex gap-2 align-items-center py-2 small">
        <i class="bi bi-lightbulb text-warning"></i>
        <span><?= t('loans_sms_tip') ?> <a href="<?= APP_URL ?>/admin/sms_reminders.php"><?= t('nav_sms_reminders') ?></a> <?= t('adm_loan_or_default') ?></span>
    </div>
</div>
<?php endif; ?>

<!-- Status Tabs -->
<ul class="nav nav-tabs mb-3">
    <?php
    $tabMap = [
        'pending'  => 'warning',  'approved'  => 'success',
        'disbursed'=> 'info',     'completed' => 'primary',
        'rejected' => 'danger',   'defaulted' => 'dark',
        'all'      => 'secondary'
    ];
    foreach ($tabMap as $s => $color): ?>
    <li class="nav-item">
        <a class="nav-link <?= $filterStatus===$s?'active':'' ?>" href="?status=<?= $s ?>">
            <?= ucfirst($s) ?>
            <span class="badge bg-<?= $color ?> ms-1"><?= $counts[$s] ?? 0 ?></span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= t('adm_loan_number') ?></th>
                        <th><?= t('loans_applicant') ?></th>
                        <th><?= t('loans_requested') ?></th>
                        <th><?= t('loan_approved') ?></th>
                        <th><?= t('loan_duration') ?></th>
                        <th><?= t('loan_purpose') ?></th>
                        <th><?= t('loans_balance') ?></th>
                        <th><?= t('loan_status') ?></th>
                        <th><?= t('ai_score') ?></th>
                        <th><?= t('lbl_date') ?></th>
                        <th><?= t('lbl_actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($loans)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">No <?= $filterStatus !== 'all' ? $filterStatus : '' ?> <?= t('adm_loan_found') ?></td></tr>
                <?php else: ?>
                    <?php foreach ($loans as $l): ?>
                    <tr>
                        <td class="font-monospace small fw-semibold text-primary"><?= htmlspecialchars($l['loan_number']) ?></td>
                        <td>
                            <div class="fw-semibold small"><?= htmlspecialchars($l['full_name']) ?></div>
                            <div class="text-muted" style="font-size:.72rem"><?= htmlspecialchars($l['membership_number'] ?? '') ?></div>
                        </td>
                        <td class="small"><?= money($l['amount_requested'], $curr) ?></td>
                        <td class="small fw-semibold"><?= $l['amount_approved'] ? money($l['amount_approved'], $curr) : '—' ?></td>
                        <td class="small"><?= $l['duration_months'] ?> mo</td>
                        <td class="small text-muted" style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                            title="<?= htmlspecialchars($l['purpose']) ?>">
                            <?= htmlspecialchars(substr($l['purpose'], 0, 40)) ?>…
                        </td>
                        <td class="small <?= ($l['balance'] ?? 0) > 0 ? 'text-danger fw-semibold' : '' ?>">
                            <?= $l['balance'] !== null ? money($l['balance'], $curr) : '—' ?>
                        </td>
                        <td>
                            <?= badgeStatus($l['status']) ?>
                            <?php if ($l['status'] === 'disbursed'): ?>
                                <?php if (!empty($l['member_confirmed'])): ?>
                                <div style="font-size:.65rem;color:#00c471;margin-top:.2rem">
                                    <i class="bi bi-check-circle-fill"></i> Receipt confirmed
                                </div>
                                <?php else: ?>
                                <div style="font-size:.65rem;color:#f59e0b;margin-top:.2rem">
                                    <i class="bi bi-clock"></i> Awaiting confirmation
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-center" style="white-space:nowrap">
                            <?php
                            $sc = checkLoanEligibility($pdo, (int)$l['user_id']);
                            $scC = $sc['score']>=70?'#00c471':($sc['score']>=50?'#f59e0b':'#ef4444');
                            ?>
                            <span style="font-size:1.1rem;font-weight:800;color:<?= $scC ?>"><?= $sc['score'] ?></span>
                            <span style="font-size:.65rem;color:#9ca3af">/100</span><br>
                            <span style="font-size:.65rem;font-weight:600;color:<?= $scC ?>"><?= $sc['score_label'] ?></span>
                        </td>
                        <td class="small text-muted"><?= formatDate($l['applied_at'], 'd M Y') ?></td>
                        <td>
                            <?php if ($l['status'] === 'pending'): ?>
                                <!-- Approve / Reject triggers -->
                                <button class="btn btn-sm btn-success me-1"
                                        data-bs-toggle="modal" data-bs-target="#approveModal"
                                        data-loan-id="<?= $l['id'] ?>"
                                        data-loan-num="<?= htmlspecialchars($l['loan_number']) ?>"
                                        data-amount="<?= $l['amount_requested'] ?>"
                                        data-months="<?= $l['duration_months'] ?>"
                                        data-rate="<?= $l['interest_rate'] ?>"
                                        data-name="<?= htmlspecialchars($l['full_name']) ?>">
                                    <i class="bi bi-check-lg"></i> Approve
                                </button>
                                <button class="btn btn-sm btn-danger"
                                        data-bs-toggle="modal" data-bs-target="#rejectModal"
                                        data-loan-id="<?= $l['id'] ?>"
                                        data-loan-num="<?= htmlspecialchars($l['loan_number']) ?>">
                                    <i class="bi bi-x-lg"></i>
                                </button>

                            <?php elseif ($l['status'] === 'approved'): ?>
                                <button class="btn btn-sm btn-info text-white"
                                        data-bs-toggle="modal" data-bs-target="#disburseModal"
                                        data-loan-id="<?= $l['id'] ?>"
                                        data-loan-num="<?= htmlspecialchars($l['loan_number']) ?>"
                                        data-amount-raw="<?= $l['amount_approved'] ?>"
                                        data-amount="<?= money($l['amount_approved'], $curr) ?>"
                                        data-name="<?= htmlspecialchars($l['full_name']) ?>"
                                        data-phone="<?= preg_replace('/[^0-9]/', '', $l['phone'] ?? '') ?>"
                                        data-mem-no="<?= htmlspecialchars($l['membership_number'] ?? '') ?>">
                                    <i class="bi bi-cash"></i> Disburse
                                </button>

                            <?php elseif (in_array($l['status'], ['disbursed','completed'])): ?>
                                <a href="<?= APP_URL ?>/admin/repayments.php?loan_id=<?= $l['id'] ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-arrow-return-left"></i> Repayments
                                </a>
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

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header">
                <h6 class="modal-title fw-bold text-success"><i class="bi bi-check-circle me-2"></i><?= t('adm_loan_approve') ?></h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="loan_id" id="approve_loan_id">
                <div class="modal-body">
                    <p class="text-muted small mb-3">Loan: <strong id="approve_loan_num"></strong> for <strong id="approve_name"></strong></p>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Amount to Approve (<?= $curr ?>) <span class="text-danger">*</span></label>
                        <input type="number" name="amount_approved" id="approve_amount" class="form-control"
                               min="1" step="0.01" required>
                        <small class="text-muted">Requested: <span id="approve_requested"></span></small>
                    </div>

                    <!-- Quick loan preview -->
                    <div class="bg-light rounded p-3 mb-3 small" id="loanPreview" style="display:none">
                        <div class="row text-center g-2">
                            <div class="col-4"><div class="text-muted"><?= t('lbl_total_repayable') ?></div><strong id="prev_total">—</strong></div>
                            <div class="col-4"><div class="text-muted"><?= t('lbl_monthly_payment') ?></div><strong id="prev_monthly">—</strong></div>
                            <div class="col-4"><div class="text-muted"><?= t('adm_loan_duration') ?></div><strong id="prev_months">—</strong></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= t('adm_loan_notes') ?></label>
                        <textarea name="review_notes" class="form-control" rows="2" placeholder="Any conditions attached to this approval…"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?= t('btn_cancel') ?></button>
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i><?= t('adm_loan_confirm_appr') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header">
                <h6 class="modal-title fw-bold text-danger"><i class="bi bi-x-circle me-2"></i><?= t('adm_loan_reject') ?></h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="loan_id" id="reject_loan_id">
                <div class="modal-body">
                    <p class="text-muted small">Loan: <strong id="reject_loan_num"></strong></p>
                    <div>
                        <label class="form-label fw-semibold">Reason for Rejection <span class="text-danger">*</span></label>
                        <textarea name="review_notes" class="form-control" rows="3"
                                  placeholder="Explain why this loan is being rejected…" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?= t('btn_cancel') ?></button>
                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-x-lg me-1"></i><?= t('adm_loan_reject') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Disburse Modal -->
<!-- Disburse Modal -->
<div class="modal fade" id="disburseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:480px">
        <div class="modal-content shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-cash-coin me-2 text-info"></i>Record Loan Disbursement
                </h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="disburse">
                <input type="hidden" name="loan_id" id="disburse_loan_id">
                <div class="modal-body pt-2">

                    <!-- Member summary card -->
                    <div class="rounded-3 p-3 mb-3" style="background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.18)">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:46px;height:46px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#60a5fa);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#fff;font-weight:800;flex-shrink:0" id="disburse_avatar">—</div>
                            <div style="flex:1">
                                <div style="font-size:1rem;font-weight:700" id="disburse_name">—</div>
                                <div style="font-size:.76rem;color:var(--text-muted,#6b87a8)">
                                    <span id="disburse_membership"></span>
                                    &nbsp;·&nbsp;
                                    <span class="font-monospace" id="disburse_phone"></span>
                                </div>
                            </div>
                            <div class="text-end">
                                <div style="font-size:1.25rem;font-weight:800;color:#3b82f6" id="disburse_amount">—</div>
                                <div style="font-size:.7rem;color:var(--text-muted,#6b87a8)" id="disburse_loan_num">—</div>
                            </div>
                        </div>
                    </div>

                    <!-- How to steps -->
                    <div class="rounded-3 p-3 mb-3" style="background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.15);font-size:.8rem">
                        <div style="font-weight:700;color:#f59e0b;margin-bottom:.5rem"><i class="bi bi-lightbulb me-1"></i><?= t('adm_loan_how_disburse') ?></div>
                        <ol style="margin:0;padding-left:1.2rem;color:var(--text-muted,#6b87a8);line-height:2">
                            <li>Open your M-Pesa app and send <strong id="disburse_amount_hint" style="color:#f59e0b">the amount</strong> to <strong id="disburse_phone_hint" style="color:#f59e0b">the member's number</strong></li>
                            <li>Copy the M-Pesa confirmation code from the SMS you receive</li>
                            <li>Paste it below and click <strong>Confirm</strong></li>
                        </ol>
                    </div>

                    <!-- Form fields -->
                    <div class="row g-3">
                        <div class="col-5">
                            <label class="form-label fw-semibold" style="font-size:.82rem">Payment Method</label>
                            <select name="disbursement_method" class="form-select form-select-sm">
                                <option value="mpesa">M-Pesa</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="cash">Cash</option>
                            </select>
                        </div>
                        <div class="col-7">
                            <label class="form-label fw-semibold" style="font-size:.82rem">M-Pesa / Bank Reference</label>
                            <input type="text" name="disbursement_ref" id="dsbRefInput" class="form-control form-control-sm font-monospace"
                                   placeholder="e.g. QHY9DKL3X8" style="text-transform:uppercase"
                                   oninput="this.value=this.value.toUpperCase()" autocomplete="off">
                            <div id="dsbRefFeedback" class="mpesa-ref-feedback mt-1"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" style="font-size:.82rem">Notes <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" name="disbursement_notes" class="form-control form-control-sm"
                                   placeholder="e.g. Sent on 8 Mar via personal M-Pesa">
                        </div>
                    </div>

                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= t('btn_cancel') ?></button>
                    <button type="submit" class="btn btn-info text-white btn-sm fw-bold px-4">
                        <i class="bi bi-check2-circle me-1"></i>Confirm Disbursement
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.mpesa-ref-feedback { font-size:.74rem;padding:.25rem .5rem;border-radius:6px; }
.mpesa-ref-feedback.valid    { color:#00c471;background:rgba(0,196,113,.08); }
.mpesa-ref-feedback.invalid  { color:#ef4444;background:rgba(239,68,68,.08); }
.mpesa-ref-feedback.checking { color:var(--text-muted,#6b87a8); }
@keyframes spin2 { to{transform:rotate(360deg)} }
.mpesa-spin { display:inline-block;width:11px;height:11px;border:2px solid rgba(255,255,255,.2);border-top-color:#00c471;border-radius:50%;animation:spin2 .6s linear infinite;vertical-align:middle;margin-right:4px; }
</style>

<?php
$appUrlJs  = APP_URL;
$csrfJs    = csrfToken();
$extraScripts = <<<PHPJS
<script>
var APP_URL    = "{$appUrlJs}";
var CSRF_TOKEN = "{$csrfJs}";
</script>
PHPJS;
$extraScripts .= <<<'JS'
<script>
// Approve modal
document.getElementById('approveModal').addEventListener('show.bs.modal', function(e) {
    const btn = e.relatedTarget;
    document.getElementById('approve_loan_id').value   = btn.dataset.loanId;
    document.getElementById('approve_loan_num').textContent = btn.dataset.loanNum;
    document.getElementById('approve_name').textContent     = btn.dataset.name;
    document.getElementById('approve_requested').textContent = 'KES ' + parseFloat(btn.dataset.amount).toLocaleString();
    document.getElementById('approve_amount').value = btn.dataset.amount;
    updatePreview(btn.dataset.amount, btn.dataset.rate, btn.dataset.months);
    document.getElementById('approve_amount').addEventListener('input', function() {
        updatePreview(this.value, btn.dataset.rate, btn.dataset.months);
    });
});

function updatePreview(amount, rate, months) {
    const P = parseFloat(amount) || 0;
    const r = parseFloat(rate)   || 0;
    const n = parseInt(months)   || 0;
    if (!P || !r || !n) return;
    const interest = P * (r/100) * n;
    const total    = P + interest;
    const monthly  = total / n;
    const fmt = v => 'KES ' + v.toLocaleString('en-KE', {minimumFractionDigits:2});
    document.getElementById('prev_total').textContent   = fmt(total);
    document.getElementById('prev_monthly').textContent = fmt(monthly);
    document.getElementById('prev_months').textContent  = n + ' months';
    document.getElementById('loanPreview').style.display = 'block';
}

// Reject modal
document.getElementById('rejectModal').addEventListener('show.bs.modal', function(e) {
    const btn = e.relatedTarget;
    document.getElementById('reject_loan_id').value        = btn.dataset.loanId;
    document.getElementById('reject_loan_num').textContent = btn.dataset.loanNum;
});

// Disburse modal
document.getElementById('disburseModal').addEventListener('show.bs.modal', function(e) {
    const btn    = e.relatedTarget;
    const name   = btn.dataset.name   || '';
    const phone  = btn.dataset.phone  || '';
    const amount = btn.dataset.amount || '';
    const memNo  = btn.dataset.memNo  || '';
    const loanNo = btn.dataset.loanNum || '';

    document.getElementById('disburse_loan_id').value          = btn.dataset.loanId;
    document.getElementById('disburse_loan_num').textContent   = loanNo;
    document.getElementById('disburse_name').textContent       = name;
    document.getElementById('disburse_amount').textContent     = amount;
    document.getElementById('disburse_membership').textContent = memNo;
    document.getElementById('disburse_phone').textContent      = phone ? '0' + phone.slice(-9) : 'No phone';
    document.getElementById('disburse_amount_hint').textContent = amount;
    document.getElementById('disburse_phone_hint').textContent  = phone ? '0' + phone.slice(-9) : 'their phone';

    // Avatar initials
    const initials = name.split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
    document.getElementById('disburse_avatar').textContent = initials || '?';

    // Reset ref field and feedback
    const refInput = document.getElementById('dsbRefInput');
    const refFeed  = document.getElementById('dsbRefFeedback');
    if (refInput) { refInput.value = ''; refInput.className = refInput.className.replace(/ ?is-valid| ?is-invalid/g,''); }
    if (refFeed)  { refFeed.innerHTML = ''; refFeed.className = 'mpesa-ref-feedback'; }

    // Attach live M-Pesa validation
    attachDsbRefValidation();
});

var _dsbRefAttached = false;
function attachDsbRefValidation() {
    if (_dsbRefAttached) return;
    _dsbRefAttached = true;
    const input    = document.getElementById('dsbRefInput');
    const feedback = document.getElementById('dsbRefFeedback');
    if (!input || !feedback) return;
    var timer = null;
    input.addEventListener('input', function() {
        var code = input.value.replace(/[^A-Za-z0-9]/g,'').toUpperCase().slice(0,10);
        input.value = code;
        feedback.innerHTML = ''; feedback.className = 'mpesa-ref-feedback';
        input.className = input.className.replace(/ ?is-valid| ?is-invalid/g,'');
        if (!code) return;
        // Format check
        if (!/^[A-Z]/.test(code))      { feedback.innerHTML = '<i class="bi bi-x-circle me-1"></i>M-Pesa codes start with a letter'; feedback.className='mpesa-ref-feedback invalid'; input.classList.add('is-invalid'); return; }
        if (code.length < 10)          { feedback.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>' + code.length + '/10 characters…'; feedback.className='mpesa-ref-feedback checking'; return; }
        if (!/[0-9]/.test(code))       { feedback.innerHTML = '<i class="bi bi-x-circle me-1"></i>Must contain at least one number'; feedback.className='mpesa-ref-feedback invalid'; input.classList.add('is-invalid'); return; }
        // 10 chars good — server duplicate check
        feedback.innerHTML = '<span class="mpesa-spin"></span>Checking…'; feedback.className = 'mpesa-ref-feedback checking';
        clearTimeout(timer);
        timer = setTimeout(function() {
            fetch(APP_URL + '/api/mpesa_verify.php?code=' + encodeURIComponent(code), {credentials:'same-origin'})
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    feedback.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Valid reference code ✓';
                    feedback.className = 'mpesa-ref-feedback valid';
                    input.classList.add('is-valid'); input.classList.remove('is-invalid');
                } else {
                    feedback.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i>' + (data.message || 'Invalid code');
                    feedback.className = 'mpesa-ref-feedback invalid';
                    input.classList.add('is-invalid'); input.classList.remove('is-valid');
                }
            })
            .catch(() => { feedback.innerHTML = '<i class="bi bi-wifi-off me-1"></i>Could not verify'; feedback.className = 'mpesa-ref-feedback checking'; });
        }, 500);
    });
}
</script>
JS;
require_once ROOT . '/includes/footer.php';
?>
