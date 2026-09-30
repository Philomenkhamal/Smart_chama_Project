<?php
/**
 * CHAMA Financial Management System
 * Member — Full Payment History
 * Combined view: contributions + loan repayments
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Payment History — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireMember();
require_once ROOT . '/includes/header.php';

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$curr   = getSetting('currency', 'KES');

// Fetch all contributions
$contribs = $pdo->prepare("
    SELECT 'contribution' AS type, c.id, c.recorded_at AS date, c.amount,
           c.payment_method, c.reference_code, c.status, c.notes,
           NULL AS loan_number,
           DATE_FORMAT(c.payment_month,'%M %Y') AS description
    FROM contributions c
    WHERE c.user_id=?
")->execute([$userId]) ? [] : [];

$stmt = $pdo->prepare("
    SELECT 'contribution' AS type, c.id, c.recorded_at AS date, c.amount,
           c.payment_method, c.reference_code, c.status, c.notes,
           NULL AS loan_number,
           CONCAT('Contribution — ', DATE_FORMAT(c.payment_month,'%M %Y')) AS description
    FROM contributions c WHERE c.user_id=?
");
$stmt->execute([$userId]);
$contributions = $stmt->fetchAll();

// Fetch all loan repayments
$stmt = $pdo->prepare("
    SELECT 'repayment' AS type, lp.id, lp.paid_at AS date, lp.amount,
           lp.payment_method, lp.reference_code, lp.status, lp.notes,
           l.loan_number,
           CONCAT('Loan Repayment — ', l.loan_number) AS description
    FROM loan_payments lp
    JOIN loans l ON lp.loan_id = l.id
    WHERE lp.user_id=?
");
$stmt->execute([$userId]);
$repayments = $stmt->fetchAll();

// Merge and sort by date desc
$allTx = array_merge($contributions, $repayments);
usort($allTx, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));

// Totals
$totalContribs   = array_sum(array_map(fn($c) => $c['status']==='confirmed' ? $c['amount'] : 0, $contributions));
$totalRepayments = array_sum(array_map(fn($r) => $r['status']==='confirmed' ? $r['amount'] : 0, $repayments));
$totalPending    = array_sum(array_map(fn($t) => $t['status']==='pending' ? $t['amount'] : 0, $allTx));
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><?= t('mh_title') ?></h4>
        <small class="text-muted"><?= t('mh_subtitle') ?></small>
    </div>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print">
        <i class="bi bi-printer me-1"></i>Print
    </button>
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="text-muted small"><?= t('mh_total_contrib') ?></div>
            <h5 class="fw-bold text-success"><?= money($totalContribs, $curr) ?></h5>
            <small class="text-muted"><?= count($contributions) ?> <?= t('mh_trans_count') ?></small>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="text-muted small"><?= t('mh_loan_repay') ?></div>
            <h5 class="fw-bold text-primary"><?= money($totalRepayments, $curr) ?></h5>
            <small class="text-muted"><?= count($repayments) ?> <?= t('mh_trans_count') ?></small>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="text-muted small"><?= t('mh_pending') ?></div>
            <h5 class="fw-bold text-warning"><?= money($totalPending, $curr) ?></h5>
        </div>
    </div>
</div>

<!-- Transaction List -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-card d-flex justify-content-between">
        <h6 class="mb-0 fw-semibold"><?= t('mh_all_trans') ?></h6>
        <span class="badge bg-secondary"><?= count($allTx) ?> total</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="historyTable">
                <thead class="table-light">
                    <tr>
                        <th><?= t('lbl_date') ?></th>
                        <th><?= t('lbl_description') ?></th>
                        <th><?= t('lbl_type') ?></th>
                        <th><?= t('lbl_amount') ?></th>
                        <th><?= t('lbl_method') ?></th>
                        <th><?= t('lbl_reference') ?></th>
                        <th><?= t('lbl_status') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($allTx)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">
                        <i class="bi bi-clock-history fs-3 d-block mb-2"></i>No transactions yet.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($allTx as $tx): ?>
                    <tr>
                        <td class="small text-muted"><?= formatDate($tx['date'], 'd M Y') ?></td>
                        <td class="small fw-semibold"><?= htmlspecialchars($tx['description']) ?></td>
                        <td>
                            <?php if ($tx['type'] === 'contribution'): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                <i class="bi bi-piggy-bank me-1"></i>Saving
                            </span>
                            <?php else: ?>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                <i class="bi bi-arrow-return-left me-1"></i>Repayment
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold small <?= $tx['type']==='contribution' ? 'text-success' : 'text-primary' ?>">
                            <?= money($tx['amount'], $curr) ?>
                        </td>
                        <td><span class="badge bg-light text-dark border text-capitalize small"><?= $tx['payment_method'] ?></span></td>
                        <td class="small font-monospace text-muted"><?= htmlspecialchars($tx['reference_code'] ?: '—') ?></td>
                        <td><?= badgeStatus($tx['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once ROOT . '/includes/footer.php'; ?>
