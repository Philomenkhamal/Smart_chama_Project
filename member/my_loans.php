<?php
/**
 * CHAMA Financial Management System
 * Member — My Loans
 * View loan history, repayment schedules, submit repayments
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'My Loans — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireMember();
require_once ROOT . '/includes/header.php';

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// Auto-migrate: add member confirmation columns if missing
try { $pdo->exec("ALTER TABLE loans ADD COLUMN member_confirmed TINYINT(1) DEFAULT 0 AFTER disbursement_ref"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE loans ADD COLUMN member_confirmed_at TIMESTAMP NULL DEFAULT NULL AFTER member_confirmed"); } catch(PDOException $e) {}
$user   = currentUser();
$curr   = getSetting('currency', 'KES');

// ── Handle POST: Submit repayment ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    // ── Confirm receipt of disbursement ────────────────────────────────────────
    if ($_POST['action'] ?? '' === 'confirm_receipt') {
        $lId = (int)($_POST['loan_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM loans WHERE id=? AND user_id=? AND status='disbursed' AND member_confirmed=0");
        $stmt->execute([$lId, $userId]);
        $loanToConfirm = $stmt->fetch();
        if ($loanToConfirm) {
            $pdo->prepare("UPDATE loans SET member_confirmed=1, member_confirmed_at=NOW() WHERE id=?")
                ->execute([$lId]);
            // Notify admins
            $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll();
            foreach ($admins as $admin) {
                createNotification($admin['id'], '✅ Loan Receipt Confirmed — ' . $user['full_name'],
                    $user['full_name'] . " has confirmed receipt of loan " . $loanToConfirm['loan_number'] . " (" . money($loanToConfirm['amount_approved'], $curr) . ").",
                    'success', APP_URL . '/admin/loans.php');
            }
            logActivity('LOAN_RECEIPT_CONFIRMED', "Member confirmed receipt of loan#{$lId}");
            setFlash('success', 'Thank you! Receipt confirmed. The admin has been notified.');
        } else {
            setFlash('warning', 'Unable to confirm receipt — loan not found or already confirmed.');
        }
        redirect(APP_URL . '/member/my_loans.php');
    }

    $loanId = (int)($_POST['loan_id']       ?? 0);
    $amount = (float)($_POST['amount']       ?? 0);
    $method = sanitize($_POST['payment_method'] ?? 'mpesa');
    $ref    = sanitize($_POST['reference_code'] ?? '');
    $notes  = sanitize($_POST['notes']           ?? '');

    // Verify loan belongs to this user
    $stmt = $pdo->prepare("SELECT * FROM loans WHERE id=? AND user_id=? AND status IN ('approved','disbursed')");
    $stmt->execute([$loanId, $userId]);
    $loan = $stmt->fetch();

    $errors = [];
    if (!$loan)       $errors[] = 'Invalid loan.';
    if ($amount <= 0) $errors[] = 'Amount must be greater than 0.';
    if ($amount > $loan['balance']) $errors[] = 'Amount exceeds remaining balance.';
    if ($method !== 'cash' && empty($ref)) $errors[] = 'Reference code is required for M-PESA and Bank payments.';

    if (empty($errors)) {
        $pdo->prepare('
            INSERT INTO loan_payments (loan_id, user_id, amount, payment_method, reference_code, notes, status)
            VALUES (?, ?, ?, ?, ?, ?, "pending")
        ')->execute([$loanId, $userId, $amount, $method, $ref, $notes]);

        // Notify admins
        $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll();
        foreach ($admins as $admin) {
            createNotification($admin['id'], '💰 Loan Repayment Submitted',
                "{$user['full_name']} submitted a repayment of " . money($amount, $curr) . " for loan {$loan['loan_number']}",
                'info', APP_URL . '/admin/repayments.php');
        }

        logActivity('REPAYMENT_SUBMIT', "Member submitted repayment for loan#{$loanId}: {$curr}{$amount}");
        setFlash('success', 'Repayment submitted for admin confirmation.');
        redirect(APP_URL . '/member/my_loans.php');
    } else {
        setFlash('danger', implode('<br>', $errors));
    }
}

// ── Fetch loans ────────────────────────────────────────────────────────────────
$loans = $pdo->prepare("
    SELECT l.*, a.full_name AS reviewed_by_name
    FROM loans l
    LEFT JOIN users a ON l.reviewed_by = a.id
    WHERE l.user_id=?
    ORDER BY l.applied_at DESC
");
$loans->execute([$userId]);
$loans = $loans->fetchAll();

// Active loan for repayment modal
$activeLoan = null;
foreach ($loans as $l) {
    if (in_array($l['status'], ['approved', 'disbursed'])) {
        $activeLoan = $l;
        break;
    }
}

// Fetch all repayments for active loan
$repaymentHistory = [];
if ($activeLoan) {
    $stmt = $pdo->prepare("
        SELECT lp.*, a.full_name AS confirmed_by_name
        FROM loan_payments lp
        LEFT JOIN users a ON lp.confirmed_by = a.id
        WHERE lp.loan_id=?
        ORDER BY lp.paid_at DESC
    ");
    $stmt->execute([$activeLoan['id']]);
    $repaymentHistory = $stmt->fetchAll();
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><?= t('loan_title') ?></h4>
        <small class="text-muted"><?= t('ml_subtitle') ?></small>
    </div>
    <?php if ($activeLoan): ?>
    <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#repayModal">
        <i class="bi bi-arrow-return-left me-1"></i>Make Repayment
    </button>
    <?php elseif ($user['status'] === 'active'): ?>
    <a href="<?= APP_URL ?>/member/loan_apply.php" class="btn btn-primary btn-sm">
        <i class="bi bi-cash-stack me-1"></i>Apply for Loan
    </a>
    <?php endif; ?>
</div>

<?php if (empty($loans)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <i class="bi bi-cash-stack fs-1 text-muted mb-3 d-block"></i>
        <h6 class="text-muted"><?= t('ml_no_loans') ?></h6>
        <p class="text-muted small"><?= t('ml_apply_first') ?></p>
        <?php if ($user['status'] === 'active'): ?>
        <a href="<?= APP_URL ?>/member/loan_apply.php" class="btn btn-primary btn-sm"><?= t('ml_apply_btn') ?></a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<!-- Active Loan Detail -->
<?php if ($activeLoan): ?>

<?php if ($activeLoan['status'] === 'disbursed' && !($activeLoan['member_confirmed'] ?? 0)): ?>
<!-- ── CONFIRM RECEIPT BANNER ── -->
<div class="card border-0 mb-4" style="background:linear-gradient(135deg,rgba(0,196,113,.12),rgba(0,220,126,.06));border:1px solid rgba(0,196,113,.3)!important;border-radius:16px">
    <div class="card-body p-4">
        <div class="d-flex align-items-start gap-3 flex-wrap">
            <div style="width:52px;height:52px;border-radius:14px;background:rgba(0,196,113,.2);display:flex;align-items:center;justify-content:center;font-size:1.6rem;flex-shrink:0">
                💰
            </div>
            <div style="flex:1;min-width:200px">
                <div style="font-size:1rem;font-weight:700;margin-bottom:.3rem"><?= t('ml_received') ?></div>
                <div style="font-size:.83rem;color:var(--text-muted,#6b87a8);margin-bottom:1rem">
                    The admin has recorded disbursement of <strong style="color:#00c471"><?= money($activeLoan['amount_approved'], $curr) ?></strong>
                    for loan <strong><?= $activeLoan['loan_number'] ?></strong>
                    <?php if ($activeLoan['disbursement_method']): ?>
                    via <strong><?= strtoupper($activeLoan['disbursement_method']) ?></strong>
                    <?php endif; ?>
                    <?php if ($activeLoan['disbursement_ref']): ?>
                    (Ref: <span class="font-monospace" style="color:#00c471"><?= htmlspecialchars($activeLoan['disbursement_ref']) ?></span>)
                    <?php endif; ?>
                    on <?= $activeLoan['disbursed_at'] ? date('d M Y', strtotime($activeLoan['disbursed_at'])) : 'recently' ?>.
                    <br>Please confirm you received the money so it reflects correctly in the system records.
                </div>
                <form method="POST" onsubmit="return confirm('Confirm you received <?= money($activeLoan['amount_approved'], $curr) ?> for loan <?= $activeLoan['loan_number'] ?>?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="confirm_receipt">
                    <input type="hidden" name="loan_id" value="<?= $activeLoan['id'] ?>">
                    <button type="submit" class="btn fw-bold px-4" style="background:#00c471;color:#060e1a;border-radius:10px">
                        <i class="bi bi-check2-circle me-2"></i>Yes, I Received the Money
                    </button>
                </form>
            </div>
            <div style="text-align:center;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.2);border-radius:10px;padding:.75rem 1.25rem;flex-shrink:0">
                <i class="bi bi-clock" style="color:#f59e0b;font-size:1.4rem"></i>
                <div style="font-size:.7rem;color:#f59e0b;margin-top:.25rem;font-weight:600"><?= t('ml_pending') ?><br><?= t('ml_confirmation') ?></div>
            </div>
        </div>
    </div>
</div>
<?php elseif ($activeLoan['status'] === 'disbursed' && ($activeLoan['member_confirmed'] ?? 0)): ?>
<div class="alert mb-4 py-2 px-3" style="background:rgba(0,196,113,.08);border:1px solid rgba(0,196,113,.2);border-radius:10px;font-size:.82rem">
    <i class="bi bi-check-circle-fill text-success me-2"></i>
    You confirmed receipt of <strong><?= money($activeLoan['amount_approved'], $curr) ?></strong> on <?= date('d M Y', strtotime($activeLoan['member_confirmed_at'])) ?>. Repayments are now active.
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4 border-start border-warning border-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h6 class="fw-bold mb-0">Active Loan — <?= $activeLoan['loan_number'] ?></h6>
                <small class="text-muted"><?= badgeStatus($activeLoan['status']) ?></small>
            </div>
            <?php if ($activeLoan['due_date']): ?>
            <div class="text-end small">
                <div class="text-muted"><?= t('ml_due_date') ?></div>
                <div class="fw-semibold <?= strtotime($activeLoan['due_date']) < time() ? 'text-danger' : 'text-dark' ?>">
                    <?= formatDate($activeLoan['due_date']) ?>
                    <?php if (strtotime($activeLoan['due_date']) < time()): ?>
                    <span class="badge bg-danger ms-1"><?= t('lbl_overdue') ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="row g-3 mb-3">
            <?php
            $amtApproved = $activeLoan['amount_approved'] ?? $activeLoan['amount_requested'];
            $cards = [
                ['Approved Amount',    money($amtApproved, $curr),               'primary'],
                ['Interest Rate',      $activeLoan['interest_rate'] . '% / month', 'secondary'],
                ['Duration',           $activeLoan['duration_months'] . ' months',  'secondary'],
                ['Total Repayable',    money($activeLoan['total_repayable'] ?? 0, $curr), 'info'],
                ['Amount Repaid',      money($activeLoan['amount_repaid'], $curr),  'success'],
                ['Remaining Balance',  money($activeLoan['balance'] ?? 0, $curr),   'danger'],
            ];
            ?>
            <?php foreach ($cards as [$label, $value, $color]): ?>
            <div class="col-6 col-md-2">
                <div class="bg-light rounded p-2 text-center">
                    <div class="text-muted small mb-1"><?= $label ?></div>
                    <div class="fw-bold text-<?= $color ?> small"><?= $value ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Progress Bar -->
        <?php
        $total = $activeLoan['total_repayable'] ?? 0;
        $progress = $total > 0 ? ($activeLoan['amount_repaid'] / $total) * 100 : 0;
        ?>
        <div class="mb-3">
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span><?= t('ml_repay_progress') ?></span>
                <span><?= round($progress, 1) ?><?= t('ml_pct_repaid') ?></span>
            </div>
            <div class="progress" style="height:10px">
                <div class="progress-bar bg-success" style="width:<?= $progress ?>%"></div>
            </div>
        </div>

        <?php if ($activeLoan['purpose']): ?>
        <p class="text-muted small mb-0">
            <strong><?= t('ml_purpose') ?></strong> <?= htmlspecialchars($activeLoan['purpose']) ?>
        </p>
        <?php endif; ?>
    </div>
</div>

<!-- Repayment Schedule -->
<?php
if ($activeLoan && ($activeLoan['status'] === 'approved' || $activeLoan['status'] === 'disbursed')) {
    $scheduleStart = $activeLoan['disbursed_at'] ?? $activeLoan['approved_at'] ?? $activeLoan['created_at'];
    $startTs       = strtotime($scheduleStart ?? 'now');
    $duration      = (int)($activeLoan['duration_months'] ?? 1);
    $totalRepay    = (float)($activeLoan['total_repayable'] ?? 0);
    $monthlyDue    = $duration > 0 ? round($totalRepay / $duration, 2) : $totalRepay;
    $alreadyRepaid = (float)($activeLoan['amount_repaid'] ?? 0);
    $remaining     = (float)($activeLoan['balance'] ?? $totalRepay);
    $schedule      = [];
    $runningBalance = $totalRepay;
    for ($i = 1; $i <= $duration; $i++) {
        $dueTs  = mktime(0,0,0, date('n', $startTs) + $i, 1, date('Y', $startTs));
        $pay    = min($monthlyDue, $runningBalance);
        $runningBalance = max(0, $runningBalance - $pay);
        $isPast = $dueTs < strtotime('today');
        $schedule[] = [
            'month'   => $i,
            'due_date' => date('M Y', $dueTs),
            'due_ts'   => $dueTs,
            'amount'   => $pay,
            'balance'  => $runningBalance,
            'is_past'  => $isPast,
        ];
    }
}
?>
<?php if (!empty($schedule)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-card d-flex align-items-center gap-2 py-3">
        <i class="bi bi-calendar3 text-info fs-5"></i>
        <div class="flex-grow-1">
            <strong class="d-block"><?= t('lbl_repay_schedule') ?></strong>
            <small class="text-muted">Monthly installment: <?= money($monthlyDue, $curr) ?> &nbsp;·&nbsp; <?= $duration ?> months</small>
        </div>
        <span class="badge bg-info text-dark"><?= $duration ?> months</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.875rem">
            <thead style="background:#f8f9fa">
                <tr>
                    <th class="ps-3" style="width:50px">#</th>
                    <th><?= t('ml_due_month') ?></th>
                    <th><?= t('ml_installment') ?></th>
                    <th><?= t('ml_balance_after') ?></th>
                    <th style="width:120px"><?= t('loan_status') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $paidSoFar = 0;
                foreach($schedule as $s):
                    $paidSoFar += $s['amount'];
                    $isPaid    = $paidSoFar <= ($alreadyRepaid + 0.01);
                    $isCurrentMonth = date('Y-m') === date('Y-m', $s['due_ts']);
                    if ($isPaid) $rowStyle = 'background:#f0fdf4';
                    elseif ($s['is_past'] && !$isPaid) $rowStyle = 'background:#fff5f5';
                    elseif ($isCurrentMonth) $rowStyle = 'background:#fffbeb';
                    else $rowStyle = '';
                ?>
                <tr style="<?= $rowStyle ?>">
                    <td class="ps-3 text-muted"><?= $s['month'] ?></td>
                    <td class="fw-semibold small"><?= $s['due_date'] ?></td>
                    <td class="fw-bold"><?= money($s['amount'], $curr) ?></td>
                    <td class="text-muted small"><?= money($s['balance'], $curr) ?></td>
                    <td>
                        <?php if ($isPaid): ?>
                            <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Paid</span>
                        <?php elseif ($s['is_past'] && !$isPaid): ?>
                            <span class="badge bg-danger"><i class="bi bi-exclamation-lg me-1"></i><?= t('lbl_overdue') ?></span>
                        <?php elseif ($isCurrentMonth): ?>
                            <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Due Now</span>
                        <?php else: ?>
                            <span class="badge bg-light text-muted border"><?= t('ml_upcoming') ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-card small text-muted py-2">
        <i class="bi bi-info-circle me-1"></i>
        Schedule is approximate. Actual balance adjusts after each confirmed repayment.
    </div>
</div>
<?php endif; ?>

<!-- Repayment History for Active Loan -->
<?php if (!empty($repaymentHistory)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-card">
        <h6 class="mb-0 fw-semibold"><?= t('ml_repay_history') ?></h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= t('lbl_date') ?></th>
                        <th><?= t('lbl_amount') ?></th>
                        <th><?= t('lbl_method') ?></th>
                        <th><?= t('lbl_reference') ?></th>
                        <th><?= t('loan_status') ?></th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($repaymentHistory as $r): ?>
                <tr>
                    <td class="small"><?= formatDate($r['paid_at'], 'd M Y') ?></td>
                    <td class="small fw-bold text-success"><?= money($r['amount'], $curr) ?></td>
                    <td><span class="badge bg-light text-dark border text-capitalize"><?= $r['payment_method'] ?></span></td>
                    <td class="small font-monospace text-muted"><?= htmlspecialchars($r['reference_code'] ?: '—') ?></td>
                    <td><?= badgeStatus($r['status']) ?></td>
                    <td class="small text-muted">
                        <?= $r['notes'] ? htmlspecialchars($r['notes']) : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- All Loans List -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-card">
        <h6 class="mb-0 fw-semibold"><?= t('ml_all_applications') ?></h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= t('ml_loan_no') ?></th>
                        <th><?= t('lbl_amount') ?></th>
                        <th>Duration</th>
                        <th>Interest</th>
                        <th><?= t('lbl_total_repayable') ?></th>
                        <th><?= t('ml_balance') ?></th>
                        <th><?= t('loan_status') ?></th>
                        <th>Applied</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($loans as $l): ?>
                <tr>
                    <td class="font-monospace small fw-semibold text-primary"><?= $l['loan_number'] ?></td>
                    <td class="small"><?= money($l['amount_requested'], $curr) ?>
                        <?php if ($l['amount_approved'] && $l['amount_approved'] != $l['amount_requested']): ?>
                        <div class="text-muted" style="font-size:.72rem">Approved: <?= money($l['amount_approved'],$curr) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= $l['duration_months'] ?> mo</td>
                    <td class="small"><?= $l['interest_rate'] ?>%/mo</td>
                    <td class="small"><?= $l['total_repayable'] ? money($l['total_repayable'],$curr) : '—' ?></td>
                    <td class="small fw-bold <?= ($l['balance'] ?? 0) > 0 ? 'text-danger' : '' ?>">
                        <?= isset($l['balance']) ? money($l['balance'],$curr) : '—' ?>
                    </td>
                    <td><?= badgeStatus($l['status']) ?></td>
                    <td class="small text-muted"><?= formatDate($l['applied_at'], 'd M Y') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════
     REPAYMENT MODAL — STK Push + Pochi + Manual
═══════════════════════════════════════════ -->
<?php if ($activeLoan):
    $jsPhone   = preg_replace('/[^0-9]/', '', $user['phone'] ?? '');
    $jsCsrf    = htmlspecialchars(csrfToken(), ENT_QUOTES);
    $jsApp     = htmlspecialchars(APP_URL, ENT_QUOTES);
    $jsLoanId  = (int)$activeLoan['id'];
    $jsBalance = (float)($activeLoan['balance'] ?? 0);
    $jsMonthly = round(($activeLoan['total_repayable'] ?? 0) / max(1, $activeLoan['duration_months']), 0);
    $pochiPhone = defined('POCHI_PHONE') ? POCHI_PHONE : '+254701536730';
    $pochiName  = defined('POCHI_NAME')  ? POCHI_NAME  : 'ChamaLedger';
?>
<div class="modal fade" id="repayModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:520px">
        <div class="modal-content" style="background:var(--card-bg,#0d1f38);border:1px solid var(--card-border,rgba(255,255,255,.07))">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="bi bi-arrow-return-left me-2" style="color:#00c471"></i>Loan Repayment
                    </h5>
                    <div style="font-size:.75rem;color:var(--text-muted,#6b87a8)">
                        <?= $activeLoan['loan_number'] ?> &nbsp;·&nbsp;
                        Balance: <strong style="color:#ef4444"><?= money($activeLoan['balance']??0, $curr) ?></strong>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3">

                <!-- Payment tabs -->
                <div style="display:flex;gap:.4rem;background:rgba(255,255,255,.04);border-radius:10px;padding:.3rem;margin-bottom:1.25rem">
                    <button class="rp-tab active" id="rptab-stk"   onclick="rpSwitch('stk')"><i class="bi bi-phone me-1"></i>STK Push</button>
                    <button class="rp-tab"        id="rptab-pochi" onclick="rpSwitch('pochi')"><i class="bi bi-send me-1"></i>Pochi</button>
                    <button class="rp-tab"        id="rptab-manual" onclick="rpSwitch('manual')"><i class="bi bi-pencil-square me-1"></i>Manual</button>
                </div>

                <!-- STK Push Panel -->
                <div id="rppanel-stk">
                    <div id="rpStkAlert" style="display:none;padding:.75rem 1rem;border-radius:10px;margin-bottom:1rem;font-size:.84rem"></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label" style="font-size:.78rem">M-Pesa Phone</label>
                            <input type="tel" id="rpStkPhone" class="form-control form-control-sm" placeholder="07XX XXX XXX">
                        </div>
                        <div class="col-6">
                            <label class="form-label" style="font-size:.78rem">Amount (<?= $curr ?>)</label>
                            <input type="number" id="rpStkAmount" class="form-control form-control-sm" min="0.01" step="0.01"
                                   max="<?= $jsBalance ?>" value="<?= $jsMonthly ?>">
                            <div style="font-size:.7rem;color:var(--text-muted,#6b87a8)">Max: <?= money($activeLoan['balance']??0,$curr) ?></div>
                        </div>
                    </div>
                    <button class="btn btn-success w-100 fw-bold" id="rpStkBtn" style="font-size:.85rem">
                        <i class="bi bi-phone me-1"></i>Send STK Push to My Phone
                    </button>
                    <div id="rpStkWaiting" style="display:none;margin-top:1rem;background:rgba(0,196,113,.06);border:1px solid rgba(0,196,113,.2);border-radius:12px;padding:1.1rem">
                        <div style="display:flex;align-items:center;gap:1rem">
                            <div style="width:42px;height:42px;border-radius:50%;background:rgba(0,196,113,.15);display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:#00c471;flex-shrink:0;animation:vibrate 1.5s ease infinite">
                                <i class="bi bi-phone-vibrate"></i>
                            </div>
                            <div style="flex:1">
                                <div style="font-weight:600;font-size:.85rem;margin-bottom:.3rem">Check your phone — enter M-Pesa PIN</div>
                                <div style="height:5px;background:rgba(255,255,255,.08);border-radius:99px;overflow:hidden">
                                    <div id="rpStkBar" style="height:100%;width:100%;background:linear-gradient(90deg,#00c471,#00dc7e);border-radius:99px"></div>
                                </div>
                                <div id="rpStkTimer" style="font-size:.7rem;color:var(--text-muted,#6b87a8);margin-top:.25rem">90s remaining</div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="rpStkCancelBtn" style="font-size:.75rem">Cancel</button>
                        </div>
                    </div>
                </div>

                <!-- Pochi Panel -->
                <div id="rppanel-pochi" style="display:none">
                    <div style="background:rgba(0,196,113,.07);border:1px solid rgba(0,196,113,.2);border-radius:12px;padding:1.25rem;margin-bottom:1rem">
                        <div style="font-weight:700;font-size:.88rem;color:#00c471;margin-bottom:.75rem">
                            <i class="bi bi-phone-fill me-2"></i>Pay via Pochi La Biashara
                        </div>
                        <div class="d-flex gap-3 flex-wrap">
                            <div style="flex:1;min-width:160px">
                                <?php foreach([
                                    ['1','Open M-Pesa on your phone'],
                                    ['2','Go to <strong>Send Money</strong>'],
                                    ['3','Enter: <strong style="color:#00c471;font-family:monospace">'.$pochiPhone.'</strong>'],
                                    ['4','Enter amount, confirm PIN'],
                                    ['5','Copy the M-Pesa code from SMS'],
                                    ['6','Submit it in the Manual tab'],
                                ] as [$n,$s]): ?>
                                <div style="display:flex;gap:.5rem;margin-bottom:.4rem;align-items:flex-start">
                                    <div style="width:19px;height:19px;border-radius:50%;background:#00c471;color:#060e1a;display:flex;align-items:center;justify-content:center;font-size:.62rem;font-weight:800;flex-shrink:0;margin-top:1px"><?= $n ?></div>
                                    <div style="font-size:.79rem;color:var(--text-muted,#6b87a8)"><?= $s ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="text-align:center;background:var(--bg-2,#0a1929);border:1px solid rgba(0,196,113,.25);border-radius:10px;padding:.9rem;min-width:130px;align-self:flex-start">
                                <div style="font-size:.62rem;color:var(--text-muted,#6b87a8);text-transform:uppercase;letter-spacing:.07em">Send to</div>
                                <div style="font-family:'Syne',sans-serif;font-size:1.25rem;font-weight:800;color:#00c471"><?= htmlspecialchars($pochiPhone) ?></div>
                                <div style="font-size:.72rem;color:var(--text-muted,#6b87a8)"><?= htmlspecialchars($pochiName) ?></div>
                                <div style="font-size:.62rem;background:rgba(0,196,113,.1);border-radius:5px;padding:.2rem .5rem;margin-top:.5rem;color:#00c471">Pochi La Biashara</div>
                            </div>
                        </div>
                    </div>
                    <p style="font-size:.8rem;color:var(--text-muted,#6b87a8);text-align:center">
                        After sending, use the <a href="javascript:void(0)" onclick="rpSwitch('manual')" style="color:#00c471;font-weight:600">Manual tab</a> to submit your code.
                    </p>
                </div>

                <!-- Manual Panel -->
                <div id="rppanel-manual" style="display:none">
                    <form method="POST" id="rpManualForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="loan_id" value="<?= $activeLoan['id'] ?>">
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label" style="font-size:.78rem">Amount (<?= $curr ?>)</label>
                                <input type="number" name="amount" class="form-control form-control-sm" min="0.01" step="0.01"
                                       max="<?= $jsBalance ?>" value="<?= $jsMonthly ?>" required>
                                <div style="font-size:.7rem;color:var(--text-muted,#6b87a8)">Max: <?= money($activeLoan['balance']??0,$curr) ?></div>
                            </div>
                            <div class="col-6">
                                <label class="form-label" style="font-size:.78rem"><?= t('lbl_method') ?></label>
                                <select name="payment_method" class="form-select form-select-sm">
                                    <option value="mpesa">M-Pesa</option>
                                    <option value="bank">Bank Transfer</option>
                                    <option value="cash">Cash</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label" style="font-size:.78rem">M-Pesa Reference Code</label>
                            <input type="text" name="reference_code" id="rpRefCode" class="form-control form-control-sm"
                                   placeholder="e.g. QHX7Y8Z9AB" style="font-family:monospace;text-transform:uppercase" autocomplete="off">
                            <div id="rpRefFeedback" class="mpesa-ref-feedback"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:.78rem">Notes (optional)</label>
                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="Any extra info…">
                        </div>
                        <button type="submit" class="btn btn-success w-100 fw-bold" style="font-size:.85rem">
                            <i class="bi bi-send me-1"></i>Submit for Admin Confirmation
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>
</div>

<style>
.rp-tab { flex:1;padding:.42rem .6rem;border:none;border-radius:8px;background:transparent;color:rgba(255,255,255,.5);font-size:.78rem;font-weight:600;cursor:pointer;transition:all .15s; }
.rp-tab.active { background:rgba(0,196,113,.18);color:#00c471; }
.rp-tab:hover:not(.active) { background:rgba(255,255,255,.06);color:rgba(255,255,255,.8); }
@keyframes vibrate { 0%,100%{transform:rotate(0)} 20%{transform:rotate(-10deg)} 40%{transform:rotate(10deg)} 60%{transform:rotate(-6deg)} 80%{transform:rotate(6deg)} }
@keyframes spin { to{transform:rotate(360deg)} }
.spin-sm2 { display:inline-block;width:13px;height:13px;border:2px solid rgba(0,0,0,.3);border-top-color:transparent;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:5px }
.mpesa-ref-feedback { font-size:.74rem;margin-top:.3rem;padding:.25rem .5rem;border-radius:6px; }
.mpesa-ref-feedback.valid { color:#00c471;background:rgba(0,196,113,.08); }
.mpesa-ref-feedback.invalid { color:#ef4444;background:rgba(239,68,68,.08); }
.mpesa-ref-feedback.checking { color:var(--text-muted,#6b87a8); }
.mpesa-spin { display:inline-block;width:11px;height:11px;border:2px solid rgba(255,255,255,.2);border-top-color:#00c471;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:4px; }
</style>

<script>
var RP_APP    = "<?= $jsApp ?>";
var RP_CSRF   = "<?= $jsCsrf ?>";
var RP_PHONE  = "<?= $jsPhone ?>";
var RP_LOAN   = <?= $jsLoanId ?>;
var RP_BAL    = <?= $jsBalance ?>;

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('rpStkPhone').value = RP_PHONE || '';

    // Reference code validation
    attachMpesaValidation('rpRefCode', 'rpRefFeedback', RP_APP);
});

function rpSwitch(tab) {
    ['stk','pochi','manual'].forEach(function(t) {
        document.getElementById('rppanel-' + t).style.display = t === tab ? 'block' : 'none';
        document.getElementById('rptab-' + t).classList.toggle('active', t === tab);
    });
}

// STK
var rpPoll = null, rpCountdown = null, rpCheckoutId = null;

function rpAlert(msg, type) {
    var el = document.getElementById('rpStkAlert');
    var map = { danger:['rgba(239,68,68,.1)','rgba(239,68,68,.3)','#fca5a5'], success:['rgba(0,196,113,.1)','rgba(0,196,113,.3)','#6ee7b7'], warning:['rgba(245,158,11,.1)','rgba(245,158,11,.3)','#fcd34d'] };
    var c = map[type]||map.danger;
    el.style.cssText='background:'+c[0]+';border:1px solid '+c[1]+';color:'+c[2]+';border-radius:10px;padding:.7rem 1rem;font-size:.84rem';
    el.innerHTML = msg; el.style.display = 'block';
}
function rpHideAlert() { document.getElementById('rpStkAlert').style.display='none'; }

document.getElementById('rpStkBtn').addEventListener('click', function() {
    var phone  = document.getElementById('rpStkPhone').value.trim();
    var amount = parseFloat(document.getElementById('rpStkAmount').value);
    var btn    = this;
    rpHideAlert();
    if (!phone)             return rpAlert('Enter your M-Pesa phone number.', 'danger');
    if (!amount || amount <= 0) return rpAlert('Enter a valid amount.', 'danger');
    if (amount > RP_BAL)    return rpAlert('Amount exceeds loan balance.', 'danger');

    btn.disabled = true;
    btn.innerHTML = '<span class="spin-sm2"></span>Sending…';

    fetch(RP_APP + '/api/mpesa_repay_stk.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin',
        body: JSON.stringify({ csrf_token:RP_CSRF, phone:phone, amount:amount, loan_id:RP_LOAN })
    })
    .then(function(r){ return r.text(); })
    .then(function(text) {
        var data; try { data=JSON.parse(text); } catch(e) { rpAlert('Server error: '+text.substring(0,200),'danger'); btn.disabled=false; btn.innerHTML='<i class="bi bi-phone me-1"></i>Send STK Push to My Phone'; return; }
        if (!data.success) { rpAlert(data.message||'Request failed.','danger'); btn.disabled=false; btn.innerHTML='<i class="bi bi-phone me-1"></i>Send STK Push to My Phone'; return; }
        rpCheckoutId = data.checkout_request_id;
        document.getElementById('rpStkWaiting').style.display='block';
        btn.style.display='none';
        rpStartCountdown(90); rpStartPoll();
    })
    .catch(function(err){ rpAlert('Network error: '+err.message,'danger'); btn.disabled=false; btn.innerHTML='<i class="bi bi-phone me-1"></i>Send STK Push to My Phone'; });
});

document.getElementById('rpStkCancelBtn').addEventListener('click', function() {
    rpStop(); document.getElementById('rpStkWaiting').style.display='none';
    var btn=document.getElementById('rpStkBtn'); btn.style.display='block'; btn.disabled=false;
    btn.innerHTML='<i class="bi bi-phone me-1"></i>Send STK Push to My Phone';
});

function rpStartCountdown(s) {
    var rem=s, bar=document.getElementById('rpStkBar'), timer=document.getElementById('rpStkTimer');
    bar.style.transition='width '+s+'s linear'; bar.style.width='0%';
    rpCountdown=setInterval(function(){ rem--; timer.textContent=rem+'s remaining'; if(rem<=0){rpStop();rpAlert('Timed out. Try again.','warning');} },1000);
}
function rpStartPoll() {
    rpPoll=setInterval(function(){
        fetch(RP_APP+'/api/mpesa_status.php?checkout_id='+rpCheckoutId,{credentials:'same-origin'})
        .then(function(r){return r.json();})
        .then(function(data){
            if(data.status!=='pending'){
                rpStop(); document.getElementById('rpStkWaiting').style.display='none';
                if(data.status==='success'){ rpAlert('<strong>✓ Repayment successful!</strong> Refreshing…','success'); setTimeout(function(){location.reload();},2000); }
                else { rpAlert('Payment failed: '+(data.message||'Cancelled or insufficient funds.'),'danger'); var b=document.getElementById('rpStkBtn'); b.style.display='block'; b.disabled=false; b.innerHTML='<i class="bi bi-phone me-1"></i>Send STK Push to My Phone'; }
            }
        }).catch(function(){});
    },3000);
}
function rpStop() { if(rpPoll){clearInterval(rpPoll);rpPoll=null;} if(rpCountdown){clearInterval(rpCountdown);rpCountdown=null;} }

// M-Pesa ref validation (reuse pattern from contributions)
function attachMpesaValidation(inputId, feedbackId, appUrl) {
    var input=document.getElementById(inputId), feedback=document.getElementById(feedbackId);
    if(!input||!feedback) return;
    var timer=null;
    function check(code) {
        if(!code) return null;
        if(!/^[A-Z0-9]+$/.test(code)) return 'Only uppercase letters and numbers allowed';
        if(!/^[A-Z]/.test(code)) return 'M-Pesa codes start with a letter';
        if(code.length<10) return code.length+'/10 characters…';
        if(code.length>10) return 'Too long — exactly 10 characters';
        if(!/[0-9]/.test(code)) return 'Must contain at least one number';
        return 'ok';
    }
    input.addEventListener('input', function() {
        var code=input.value.replace(/[^A-Za-z0-9]/g,'').toUpperCase().slice(0,10);
        input.value=code; feedback.innerHTML=''; feedback.className='mpesa-ref-feedback';
        input.className=input.className.replace(/ ?is-valid| ?is-invalid/g,'');
        if(!code) return;
        var r=check(code);
        if(r!=='ok') { var typing=code.length<10&&r.indexOf('/10')!==-1; feedback.innerHTML='<i class="bi bi-'+(typing?'hourglass-split':'x-circle')+' me-1"></i>'+r; feedback.className='mpesa-ref-feedback '+(typing?'checking':'invalid'); if(!typing)input.classList.add('is-invalid'); return; }
        feedback.innerHTML='<span class="mpesa-spin"></span>Checking…'; feedback.className='mpesa-ref-feedback checking';
        clearTimeout(timer);
        timer=setTimeout(function(){
            fetch(appUrl+'/api/mpesa_verify.php?code='+encodeURIComponent(code),{credentials:'same-origin'})
            .then(function(r){return r.json();})
            .then(function(data){ if(data.ok){feedback.innerHTML='<i class="bi bi-check-circle-fill me-1"></i>Valid ✓';feedback.className='mpesa-ref-feedback valid';input.classList.add('is-valid');input.classList.remove('is-invalid');}else{feedback.innerHTML='<i class="bi bi-x-circle-fill me-1"></i>'+data.message;feedback.className='mpesa-ref-feedback invalid';input.classList.add('is-invalid');input.classList.remove('is-valid');} })
            .catch(function(){feedback.innerHTML='<i class="bi bi-wifi-off me-1"></i>Could not check';feedback.className='mpesa-ref-feedback checking';});
        },500);
    });
}
</script>
<?php endif; ?>

<?php require_once ROOT . '/includes/footer.php'; ?>
