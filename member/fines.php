<?php
/**
 * ChamaLedger — Member Fines Page
 * Shows member's own fines: pending, paid, waived
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'My Fines — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireLogin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$curr   = getSetting('currency', 'KES');

// ── Load member fines ─────────────────────────────────────────────────────────
$fines = $pdo->prepare("
    SELECT mf.*, 
           DATE_FORMAT(mf.month, '%M %Y') AS month_label,
           u.full_name AS added_by_name
    FROM member_fines mf
    LEFT JOIN users u ON u.id = mf.created_by
    WHERE mf.user_id = ?
    ORDER BY mf.month DESC, mf.created_at DESC
");
$fines->execute([$userId]);
$fines = $fines->fetchAll(PDO::FETCH_ASSOC);

// ── Summary ───────────────────────────────────────────────────────────────────
$totalPending = 0; $totalPaid = 0; $countPending = 0;
foreach ($fines as $f) {
    if ($f['status'] === 'pending') { $totalPending += $f['amount']; $countPending++; }
    if ($f['status'] === 'paid')    { $totalPaid    += $f['amount']; }
}

require_once ROOT . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h4><i class="bi bi-exclamation-octagon me-2" style="color:var(--red)"></i><?= t('fines_title') ?></h4>
        <div class="subtitle"><?= t('mf_subtitle') ?></div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card red">
            <div class="stat-icon red"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div class="stat-label"><?= t('mf_pending') ?></div>
            <div class="stat-value sm"><?= $countPending ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card red">
            <div class="stat-icon red"><i class="bi bi-cash"></i></div>
            <div class="stat-label"><?= t('mf_owed') ?></div>
            <div class="stat-value sm"><?= $curr ?> <?= number_format($totalPending, 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card green">
            <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
            <div class="stat-label"><?= t('mf_total_paid') ?></div>
            <div class="stat-value sm"><?= $curr ?> <?= number_format($totalPaid, 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="bi bi-list-check"></i></div>
            <div class="stat-label"><?= t('mf_total') ?></div>
            <div class="stat-value sm"><?= count($fines) ?></div>
        </div>
    </div>
</div>

<?php if ($countPending > 0): ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
    <div>
        You have <strong><?= $countPending ?> <?= t('mf_pending_count') ?><?= $countPending > 1 ? 's' : '' ?></strong> 
        totalling <strong><?= $curr ?> <?= number_format($totalPending, 2) ?></strong>. 
        Contact your admin to arrange payment.
    </div>
</div>
<?php endif; ?>

<!-- Fines Table -->
<div class="card">
    <div class="card-header">
        <h6><i class="bi bi-exclamation-octagon me-2"></i><?= t('mf_history') ?></h6>
        <span class="text-muted small"><?= count($fines) ?> <?= t('mf_total_count') ?><?= count($fines) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($fines)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-check-circle-fill d-block mb-2" style="font-size:2.5rem;color:var(--green)"></i>
            <div class="fw-semibold"><?= t('fines_none') ?> <?= t('mf_on_account') ?></div>
            <div class="small"><?= t('mf_good_work') ?></div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th><?= t('fines_month') ?></th>
                        <th><?= t('fines_type') ?></th>
                        <th><?= t('lbl_description') ?></th>
                        <th><?= t('fines_amount') ?></th>
                        <th><?= t('fines_status') ?></th>
                        <th><?= t('lbl_date') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($fines as $f): 
                    $typeLabels = [
                        'absentee'          => 'Absentee Fine',
                        'late_contribution' => 'Late Contribution',
                        'agm'               => 'AGM Absence',
                        'custom'            => 'Custom Fine',
                    ];
                    $typeLabel = $typeLabels[$f['fine_type']] ?? ucfirst(str_replace('_', ' ', $f['fine_type']));
                    $statusClass = match($f['status']) {
                        'pending' => 'danger',
                        'paid'    => 'success',
                        'waived'  => 'secondary',
                        default   => 'info',
                    };
                ?>
                <tr>
                    <td>
                        <span class="fw-semibold"><?= htmlspecialchars($f['month_label']) ?></span>
                    </td>
                    <td>
                        <span class="badge bg-<?= $f['status'] === 'pending' ? 'danger' : 'secondary' ?>">
                            <?= htmlspecialchars($typeLabel) ?>
                        </span>
                    </td>
                    <td class="text-muted small">
                        <?= $f['description'] ? htmlspecialchars($f['description']) : '—' ?>
                    </td>
                    <td>
                        <strong class="text-<?= $f['status'] === 'pending' ? 'danger' : 'muted' ?>">
                            <?= $curr ?> <?= number_format($f['amount'], 2) ?>
                        </strong>
                    </td>
                    <td>
                        <span class="badge bg-<?= $statusClass ?>">
                            <?= ucfirst($f['status']) ?>
                        </span>
                    </td>
                    <td class="text-muted small">
                        <?= date('d M Y', strtotime($f['created_at'])) ?>
                        <?php if ($f['added_by_name']): ?>
                        <div style="font-size:.7rem">by <?= htmlspecialchars($f['added_by_name']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once ROOT . '/includes/footer.php'; ?>
