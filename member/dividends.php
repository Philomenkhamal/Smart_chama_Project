<?php
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'My Dividends — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireMember();
require_once ROOT . '/includes/header.php';

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$curr   = getSetting('currency', 'KES');

// Ensure dividend tables exist (created on first admin visit, but guard here too)
$pdo->exec("CREATE TABLE IF NOT EXISTS dividends (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    year YEAR NOT NULL,
    total_profit DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_savings DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes TEXT DEFAULT NULL,
    status ENUM('draft','distributed') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    distributed_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS dividend_shares (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dividend_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    avg_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    share_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
    share_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    notified TINYINT(1) DEFAULT 0,
    FOREIGN KEY (dividend_id) REFERENCES dividends(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_div_user (dividend_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Get all dividend payouts for this member
$myDividends = $pdo->prepare("
    SELECT ds.*, d.year, d.total_profit, d.distributed_at, d.notes
    FROM dividend_shares ds
    JOIN dividends d ON ds.dividend_id = d.id
    WHERE ds.user_id = ?
    ORDER BY d.distributed_at DESC
");
$myDividends->execute([$userId]);
$myDividends = $myDividends->fetchAll(PDO::FETCH_ASSOC);

$totalReceived = array_sum(array_column($myDividends, 'share_amount'));
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-gift-fill me-2 text-success"></i><?= t('mdiv_title') ?></h4>
        <small class="text-muted"><?= t('mdiv_subtitle') ?></small>
    </div>
</div>

<?= getFlash() ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-gift-fill"></i></div>
                <div>
                    <div class="text-muted small"><?= t('mdiv_total') ?></div>
                    <div class="fw-bold fs-5"><?= money($totalReceived, $curr) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-calendar3"></i></div>
                <div>
                    <div class="text-muted small"><?= t('mdiv_periods') ?></div>
                    <div class="fw-bold fs-5"><?= count($myDividends) ?> period(s)</div>
                </div>
            </div>
        </div>
    </div>
    <?php if (!empty($myDividends)): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-info-subtle text-info"><i class="bi bi-percent"></i></div>
                <div>
                    <div class="text-muted small"><?= t('mdiv_avg') ?></div>
                    <div class="fw-bold fs-5"><?= round(array_sum(array_column($myDividends,'share_pct'))/count($myDividends),2) ?>%</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (empty($myDividends)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <i class="bi bi-gift text-muted" style="font-size:3rem"></i>
        <p class="text-muted mt-3 mb-0"><?= t('mdiv_none') ?><br>
        <small><?= t('mdiv_none_sub') ?></small></p>
    </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-card py-3">
        <h6 class="fw-bold mb-0"><?= t('mdiv_history') ?></h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3"><?= t('mdiv_period') ?></th>
                    <th><?= t('mdiv_group_profit') ?></th>
                    <th><?= t('mdiv_your_share') ?></th>
                    <th><?= t('lbl_your_amount') ?></th>
                    <th><?= t('lbl_distributed') ?></th>
                    <th><?= t('lbl_notes') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($myDividends as $d): ?>
                <tr>
                    <td class="ps-3 fw-semibold">FY <?= htmlspecialchars($d['year']) ?></td>
                    <td><?= money($d['total_profit'], $curr) ?></td>
                    <td><span class="badge bg-primary"><?= $d['share_pct'] ?>%</span></td>
                    <td class="fw-bold text-success"><?= money($d['share_amount'], $curr) ?></td>
                    <td class="small text-muted"><?= $d['distributed_at'] ? date('d M Y', strtotime($d['distributed_at'])) : '—' ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($d['notes'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once ROOT . '/includes/footer.php'; ?>
