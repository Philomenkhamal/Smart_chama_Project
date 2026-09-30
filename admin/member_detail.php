<?php
/**
 * CHAMA Financial Management System
 * Admin — Member Detail View
 * Full financial profile: savings, loans, repayments, activity
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Member Detail — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();
require_once ROOT . '/includes/header.php';
?>
<div class="mb-3 text-end no-print">
    <a href="<?= APP_URL ?>/member/statement.php?member_id=<?= $member['id'] ?? ($_GET['id']??0) ?>" target="_blank" class="btn btn-outline-success btn-sm">
        <i class="bi bi-file-earmark-text me-1"></i>View Statement
    </a>
</div>
<?php

$pdo  = getDB();
$curr = getSetting('currency', 'KES');
$id   = (int)($_GET['id'] ?? $_POST['member_id'] ?? 0);

// ── Handle edit POST ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='edit_profile' && verifyCsrf($_POST['csrf_token']??'')) {
    $uid = (int)($_POST['member_id'] ?? 0);
    if ($uid) {
        $pdo->prepare("UPDATE users SET
            full_name        = ?,
            phone            = ?,
            nickname         = ?,
            chama_position   = ?,
            membership_number= ?,
            joined_date      = ?,
            occupation       = ?,
            address          = ?,
            next_of_kin      = ?,
            next_of_kin_phone= ?
            WHERE id=? AND role='member'")->execute([
            cleanInput($_POST['full_name']         ?? ''),
            cleanInput($_POST['phone']             ?? ''),
            cleanInput($_POST['nickname']          ?? ''),
            cleanInput($_POST['chama_position']    ?? ''),
            sanitize($_POST['membership_number'] ?? ''),
            $_POST['joined_date'] ?: null,
            cleanInput($_POST['occupation']        ?? ''),
            cleanInput($_POST['address']           ?? ''),
            cleanInput($_POST['next_of_kin']       ?? ''),
            cleanInput($_POST['next_of_kin_phone'] ?? ''),
            $uid
        ]);
        logActivity('MEMBER_EDITED', "Admin edited profile for member ID {$uid}");
        setFlash('success', 'Member profile updated successfully.');
    }
    redirect(APP_URL . '/admin/member_detail.php?id=' . $uid);
}

if (!$id) redirect(APP_URL . '/admin/members.php');

$stmt = $pdo->prepare("SELECT * FROM users WHERE id=? AND role='member'");
$stmt->execute([$id]);
$member = $stmt->fetch();
if (!$member) {
    setFlash('danger', 'Member not found.');
    redirect(APP_URL . '/admin/members.php');
}

// Financial summary
$totalSavings = (float)$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE user_id=? AND status='confirmed'")->execute([$id]) ?
    $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE user_id=? AND status='confirmed'") : 0;

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE user_id=? AND status='confirmed'");
$stmt->execute([$id]);
$totalSavings = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM loan_payments WHERE user_id=? AND status='confirmed'");
$stmt->execute([$id]);
$totalRepaid = (float)$stmt->fetchColumn();

// Contributions list
$stmt = $pdo->prepare("SELECT * FROM contributions WHERE user_id=? ORDER BY payment_month DESC");
$stmt->execute([$id]);
$contributions = $stmt->fetchAll();

// Loans list
$stmt = $pdo->prepare("SELECT * FROM loans WHERE user_id=? ORDER BY applied_at DESC");
$stmt->execute([$id]);
$loans = $stmt->fetchAll();

// Active loan balance
$activeLoanBalance = 0;
foreach ($loans as $l) {
    if (in_array($l['status'], ['approved','disbursed'])) {
        $activeLoanBalance += $l['balance'] ?? 0;
    }
}

// Recent activity
$stmt = $pdo->prepare("SELECT * FROM activity_log WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$id]);
$activity = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><?= htmlspecialchars($member['full_name']) ?></h4>
        <small class="text-muted">
            <?= $member['membership_number'] ?? 'No membership #' ?> · 
            <?= badgeStatus($member['status']) ?>
        </small>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editMemberModal">
            <i class="bi bi-pencil me-1"></i>Edit Profile
        </button>
        <a href="<?= APP_URL ?>/admin/members.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Members
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- Left: Profile + Financial Summary -->
    <div class="col-md-4">
        <!-- Profile Info -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body text-center py-4">
                <div class="avatar-placeholder rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mx-auto mb-3"
                     style="width:70px;height:70px;font-size:1.8rem">
                    <?= strtoupper(substr($member['full_name'],0,1)) ?>
                </div>
                <h6 class="fw-bold mb-1"><?= htmlspecialchars($member['full_name']) ?></h6>
                <p class="font-monospace text-primary small mb-2"><?= $member['membership_number'] ?? '—' ?></p>
            </div>
            <div class="card-body pt-0">
                <dl class="row small mb-0">
                    <dt class="col-5">Email</dt>
                    <dd class="col-7">
                        <div class="text-truncate"><?= htmlspecialchars($member['email']) ?></div>
                    </dd>
                    <dt class="col-5">Phone</dt>
                    <dd class="col-7"><?= htmlspecialchars($member['phone']) ?></dd>
                    <dt class="col-5">National ID</dt>
                    <dd class="col-7 font-monospace"><?= htmlspecialchars($member['national_id']) ?></dd>
                    <dt class="col-5">Occupation</dt>
                    <dd class="col-7"><?= htmlspecialchars($member['occupation'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">Nickname</dt>
                    <dd class="col-7"><?= htmlspecialchars($member['nickname'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">Chama Position</dt>
                    <dd class="col-7"><?= htmlspecialchars($member['chama_position'] ?? '—') ?></dd>
                    <dt class="col-5">Address</dt>
                    <dd class="col-7"><?= htmlspecialchars($member['address'] ?? '—') ?></dd>
                    <dt class="col-5">Next of Kin</dt>
                    <dd class="col-7"><?= htmlspecialchars($member['next_of_kin'] ?? '—') ?></dd>
                    <dt class="col-5">Kin Phone</dt>
                    <dd class="col-7"><?= htmlspecialchars($member['next_of_kin_phone'] ?? '—') ?></dd>
                    <dt class="col-5">Joined</dt>
                    <dd class="col-7"><?= $member['joined_date'] ? formatDate($member['joined_date']) : '—' ?></dd>
                    <dt class="col-5">Last Login</dt>
                    <dd class="col-7"><?= $member['last_login'] ? formatDate($member['last_login'], 'd M Y g:ia') : 'Never' ?></dd>
                </dl>
            </div>
        </div>

        <!-- Financial Summary -->
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="fw-semibold mb-3">Financial Summary</h6>
                <div class="row g-2 text-center small">
                    <div class="col-6">
                        <div class="bg-success-subtle rounded p-2">
                            <div class="text-muted">Total Savings</div>
                            <div class="fw-bold text-success"><?= money($totalSavings, $curr) ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="bg-primary-subtle rounded p-2">
                            <div class="text-muted">Loan Repaid</div>
                            <div class="fw-bold text-primary"><?= money($totalRepaid, $curr) ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="bg-danger-subtle rounded p-2">
                            <div class="text-muted">Loan Balance</div>
                            <div class="fw-bold text-danger"><?= money($activeLoanBalance, $curr) ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="bg-info-subtle rounded p-2">
                            <div class="text-muted">Loan Limit</div>
                            <div class="fw-bold text-info"><?= money($totalSavings * (float)getSetting('max_loan_multiplier','3'), $curr) ?></div>
                        </div>
                    </div>
                </div>

                <hr>
                <div class="d-flex gap-2">
                    <a href="<?= APP_URL ?>/admin/contributions.php?member_id=<?= $id ?>"
                       class="btn btn-sm btn-outline-success w-50">
                        <i class="bi bi-piggy-bank me-1"></i>Savings
                    </a>
                    <a href="<?= APP_URL ?>/admin/loans.php?member_id=<?= $id ?>"
                       class="btn btn-sm btn-outline-primary w-50">
                        <i class="bi bi-cash-stack me-1"></i>Loans
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Contributions + Loans + Activity -->
    <div class="col-md-8">
        <!-- Contributions -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-card d-flex justify-content-between">
                <h6 class="mb-0 fw-semibold">Contributions</h6>
                <span class="badge bg-success"><?= count($contributions) ?> records</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:220px;overflow-y:auto">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>Month</th><th>Amount</th><th>Method</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($contributions)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-2 small">No contributions</td></tr>
                        <?php else: ?>
                            <?php foreach ($contributions as $c): ?>
                            <tr>
                                <td class="small"><?= monthLabel($c['payment_month']) ?></td>
                                <td class="small fw-semibold text-success"><?= money($c['amount'], $curr) ?></td>
                                <td class="small text-capitalize"><?= $c['payment_method'] ?></td>
                                <td><?= badgeStatus($c['status']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Loans -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-card d-flex justify-content-between">
                <h6 class="mb-0 fw-semibold">Loans</h6>
                <span class="badge bg-primary"><?= count($loans) ?> records</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:200px;overflow-y:auto">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>Loan #</th><th>Amount</th><th>Balance</th><th>Status</th><th>Applied</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($loans)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-2 small">No loans</td></tr>
                        <?php else: ?>
                            <?php foreach ($loans as $l): ?>
                            <tr>
                                <td class="small font-monospace"><?= $l['loan_number'] ?></td>
                                <td class="small"><?= money($l['amount_requested'], $curr) ?></td>
                                <td class="small <?= ($l['balance']??0) > 0 ? 'text-danger fw-semibold':'text-muted' ?>">
                                    <?= isset($l['balance']) ? money($l['balance'],$curr) : '—' ?>
                                </td>
                                <td><?= badgeStatus($l['status']) ?></td>
                                <td class="small text-muted"><?= formatDate($l['applied_at'], 'd M Y') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Activity Log -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-card">
                <h6 class="mb-0 fw-semibold">Recent Activity</h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($activity)): ?>
                <p class="text-muted small p-3 mb-0">No activity logged.</p>
                <?php else: ?>
                <div style="max-height:200px;overflow-y:auto">
                    <?php foreach ($activity as $log): ?>
                    <div class="px-3 py-2 border-bottom d-flex justify-content-between small">
                        <span>
                            <span class="badge bg-light text-dark border me-2"><?= htmlspecialchars($log['action']) ?></span>
                            <?= htmlspecialchars($log['description']) ?>
                        </span>
                        <span class="text-muted text-nowrap ms-2"><?= formatDate($log['created_at'], 'd M Y g:ia') ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

?>
<!-- ── Edit Member Modal ───────────────────────────────────────────────────── -->
<div class="modal fade" id="editMemberModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Member Profile</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="edit_profile">
        <input type="hidden" name="member_id" value="<?= $member['id'] ?>">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="full_name" class="form-control" required
                value="<?= htmlspecialchars($member['full_name']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone Number</label>
              <input type="text" name="phone" class="form-control"
                value="<?= htmlspecialchars($member['phone'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Nickname / Alias</label>
              <input type="text" name="nickname" class="form-control" placeholder="e.g. Kangaroo"
                value="<?= htmlspecialchars($member['nickname'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Chama Position</label>
              <input type="text" name="chama_position" class="form-control" placeholder="e.g. CHAIRMAN"
                value="<?= htmlspecialchars($member['chama_position'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Membership Number</label>
              <input type="text" name="membership_number" class="form-control" placeholder="e.g. 1"
                value="<?= htmlspecialchars($member['membership_number'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Joined Date</label>
              <input type="date" name="joined_date" class="form-control"
                value="<?= $member['joined_date'] ? date('Y-m-d', strtotime($member['joined_date'])) : '' ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Occupation</label>
              <input type="text" name="occupation" class="form-control"
                value="<?= htmlspecialchars($member['occupation'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Address</label>
              <input type="text" name="address" class="form-control"
                value="<?= htmlspecialchars($member['address'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Next of Kin Name</label>
              <input type="text" name="next_of_kin" class="form-control"
                value="<?= htmlspecialchars($member['next_of_kin'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Next of Kin Phone</label>
              <input type="text" name="next_of_kin_phone" class="form-control"
                value="<?= htmlspecialchars($member['next_of_kin_phone'] ?? '') ?>">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php require_once ROOT . '/includes/footer.php'; ?>
