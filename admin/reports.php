<?php
/**
 * CHAMA Financial Management System
 * Admin — <?= t('reports_title') ?>
 * Overview reports with print and export capability
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
$pageTitle = t('reports_title') . ' — ChamaLedger';
requireAdmin();
require_once ROOT . '/includes/header.php';

$pdo  = getDB();
$curr = getSetting('currency', 'KES');
$groupName = getSetting('group_name', 'ChamaLedger');

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? 0); // 0 = all months

// ── Aggregate Data ─────────────────────────────────────────────────────────────
// Date filter
$dateWhere = $month
    ? "YEAR(payment_month)=$year AND MONTH(payment_month)=$month"
    : "YEAR(payment_month)=$year";

$dateWhereExp = $month
    ? "YEAR(expense_date)=$year AND MONTH(expense_date)=$month"
    : "YEAR(expense_date)=$year";

// Total savings (confirmed contributions)
$totalSavings = (float)$pdo->query("
    SELECT COALESCE(SUM(amount),0) FROM contributions
    WHERE status='confirmed' AND $dateWhere
")->fetchColumn();

// Total expenses
$totalExpenses = (float)$pdo->query("
    SELECT COALESCE(SUM(amount),0) FROM expenses WHERE YEAR(expense_date)=$year" .
    ($month ? " AND MONTH(expense_date)=$month" : '')
)->fetchColumn();

// Loans disbursed this period
$loansDisbursed = (float)$pdo->query("
    SELECT COALESCE(SUM(amount_approved),0) FROM loans
    WHERE status IN ('disbursed','completed')
    AND YEAR(disbursed_at)=$year" . ($month ? " AND MONTH(disbursed_at)=$month" : '')
)->fetchColumn();

// Loan repayments collected
$repaymentsCollected = (float)$pdo->query("
    SELECT COALESCE(SUM(amount),0) FROM loan_payments
    WHERE status='confirmed'
    AND YEAR(paid_at)=$year" . ($month ? " AND MONTH(paid_at)=$month" : '')
)->fetchColumn();

// Outstanding loan balance
$outstandingLoans = (float)$pdo->query("
    SELECT COALESCE(SUM(balance),0) FROM loans WHERE status IN ('approved','disbursed')
")->fetchColumn();

// Net position
$netPosition = $totalSavings + $repaymentsCollected - $totalExpenses - $loansDisbursed;

// Members stats
$activeMembers  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND status='active'")->fetchColumn();
$totalMembers   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member'")->fetchColumn();
$activeLoans    = (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status IN ('approved','disbursed')")->fetchColumn();
$completedLoans = (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status='completed'")->fetchColumn();

// Monthly breakdown for contributions + repayments chart
$monthlyBreakdown = [];
for ($m = 1; $m <= 12; $m++) {
    $mStr = str_pad($m, 2, '0', STR_PAD_LEFT);
    $contrib = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status='confirmed' AND payment_month LIKE '$year-$mStr%'")->fetchColumn();
    $repay   = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM loan_payments WHERE status='confirmed' AND YEAR(paid_at)=$year AND MONTH(paid_at)=$m")->fetchColumn();
    $exp     = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE YEAR(expense_date)=$year AND MONTH(expense_date)=$m")->fetchColumn();
    $monthlyBreakdown[$m] = ['contrib'=>$contrib, 'repay'=>$repay, 'expense'=>$exp];
}

$chartLabels   = json_encode(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']);
$chartContribs = json_encode(array_column($monthlyBreakdown, 'contrib'));
$chartRepay    = json_encode(array_column($monthlyBreakdown, 'repay'));
$chartExpenses = json_encode(array_column($monthlyBreakdown, 'expense'));

// Per-member savings report
$memberReport = $pdo->query("
    SELECT u.full_name, u.membership_number,
           COALESCE(SUM(c.amount),0) AS total_saved,
           COUNT(c.id) AS months_paid,
           (SELECT COALESCE(SUM(l2.balance),0) FROM loans l2 WHERE l2.user_id=u.id AND l2.status IN ('approved','disbursed')) AS loan_balance
    FROM users u
    LEFT JOIN contributions c ON c.user_id=u.id AND c.status='confirmed'
    WHERE u.role='member' AND u.status='active'
    GROUP BY u.id
    ORDER BY total_saved DESC
")->fetchAll();

// Expense category breakdown
$expenseByCategory = $pdo->query("
    SELECT category, SUM(amount) AS total
    FROM expenses WHERE YEAR(expense_date)=$year
    GROUP BY category ORDER BY total DESC
")->fetchAll();

$years = range(date('Y'), 2020);
?>

<!-- Print styles (only active during print) -->
<style>
@media print {
    .sidebar, .navbar, .no-print { display: none !important; }
    .main-content { padding: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
    body { font-size: 12px; }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <div>
        <h4 class="fw-bold mb-0"><?= t('reports_title') ?></h4>
        <small class="text-muted"><?= t('reports_subtitle') ?></small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/export.php?type=contributions&format=excel&year=<?= $year ?>&month=<?= $month ?>"><i class="bi bi-cash-coin me-2"></i><?= t('rep_contributions') ?></a></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/export.php?type=loans&format=excel&year=<?= $year ?>"><i class="bi bi-bank me-2"></i><?= t('rep_loans') ?></a></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/export.php?type=members&format=excel"><i class="bi bi-people me-2"></i><?= t('rep_members') ?></a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/export.php?type=summary&format=excel&year=<?= $year ?>&month=<?= $month ?>"><i class="bi bi-bar-chart me-2"></i><?= t('rep_summary') ?></a></li>
            </ul>
        </div>
        <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/export.php?type=contributions&format=pdf&year=<?= $year ?>&month=<?= $month ?>" target="_blank"><i class="bi bi-cash-coin me-2"></i><?= t('rep_contributions') ?></a></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/export.php?type=loans&format=pdf&year=<?= $year ?>" target="_blank"><i class="bi bi-bank me-2"></i><?= t('rep_loans') ?></a></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/export.php?type=members&format=pdf" target="_blank"><i class="bi bi-people me-2"></i><?= t('rep_members') ?></a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/export.php?type=summary&format=pdf&year=<?= $year ?>&month=<?= $month ?>" target="_blank"><i class="bi bi-bar-chart me-2"></i><?= t('rep_summary') ?></a></li>
            </ul>
        </div>
        <a href="<?= APP_URL ?>/admin/chama_ledger_report.php?year=<?= $year ?>&month=<?= $month ?: date('n') ?>"
           class="btn btn-primary btn-sm" target="_blank">
            <i class="bi bi-journal-text me-1"></i> Printable Ledger Form
        </a>
        <a href="<?= APP_URL ?>/admin/monthly_report.php?year=<?= $year ?>&month=<?= $month ?: date('n') ?>"
           class="btn btn-outline-primary btn-sm">
            <i class="bi bi-table me-1"></i> Monthly Report
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Print
        </button>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4 no-print">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= t('lbl_year') ?></label>
                <select name="year" class="form-select form-select-sm">
                    <?php foreach ($years as $y): ?>
                    <option <?= $y==$year?'selected':'' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= t('lbl_month') ?></label>
                <select name="month" class="form-select form-select-sm">
                    <option value="0"><?= t('rep_full_year') ?></option>
                    <?php for ($m=1;$m<=12;$m++): ?>
                    <option value="<?= $m ?>" <?= $month==$m?'selected':'' ?>>
                        <?= date('F', mktime(0,0,0,$m,1)) ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100"><i class="bi bi-bar-chart me-1"></i><?= t('rep_generate') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Report Header (visible in print) -->
<div class="text-center mb-4 d-none d-print-block">
    <h3 class="fw-bold"><?= htmlspecialchars($groupName) ?></h3>
    <h5>Financial Report — <?= $month ? date('F', mktime(0,0,0,$month,1)) . ' ' : '' ?><?= $year ?></h5>
    <p class="text-muted small">Generated: <?= date('d F Y, g:ia') ?></p>
    <hr>
</div>

<!-- KPI Summary -->
<div class="row g-3 mb-4">
    <?php $kpis = [
        ['Total Savings',         money($totalSavings, $curr),         'success',  'piggy-bank-fill'],
        ['Loans Disbursed',       money($loansDisbursed, $curr),       'primary',  'cash-stack'],
        ['Repayments Collected',  money($repaymentsCollected, $curr),  'info',     'arrow-return-left'],
        ['Total Expenses',        money($totalExpenses, $curr),        'danger',   'receipt'],
        ['Outstanding Balance',   money($outstandingLoans, $curr),     'warning',  'exclamation-circle'],
        ['Net Cash Position',     money($netPosition, $curr),          $netPosition>=0?'success':'danger', 'graph-up'],
    ]; ?>
    <?php foreach ($kpis as [$label, $value, $color, $icon]): ?>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card border-0 shadow-sm text-center py-3">
            <i class="bi bi-<?= $icon ?> fs-4 text-<?= $color ?> mb-1"></i>
            <div class="text-muted small"><?= $label ?></div>
            <div class="fw-bold text-<?= $color ?> small"><?= $value ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Membership Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="text-muted small"><?= t('rep_active_members') ?></div>
            <h4 class="fw-bold"><?= $activeMembers ?> / <?= $totalMembers ?></h4>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="text-muted small"><?= t('adm_rep_active_loans') ?></div>
            <h4 class="fw-bold text-warning"><?= $activeLoans ?></h4>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="text-muted small"><?= t('adm_rep_completed') ?></div>
            <h4 class="fw-bold text-success"><?= $completedLoans ?></h4>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="text-muted small"><?= t('adm_rep_avg_saving') ?></div>
            <h5 class="fw-bold text-primary">
                <?= $activeMembers > 0 ? money($totalSavings / $activeMembers, $curr) : money(0,$curr) ?>
            </h5>
        </div>
    </div>
</div>

<!-- Monthly Trend Chart -->
<!-- ── Download Centre ──────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4 no-print" style="border-left:4px solid #00c471!important">
    <div class="card-header bg-card border-0 pt-3 pb-2 d-flex align-items-center gap-2">
        <i class="bi bi-download text-success fs-5"></i>
        <h6 class="fw-semibold mb-0">Download Reports</h6>
        <small class="text-muted ms-1">— all reports for <strong><?= $year ?><?= $month ? ' / '.date('F',mktime(0,0,0,$month,1)) : '' ?></strong></small>
    </div>
    <div class="card-body pt-2 pb-3">
        <div class="row g-3">
            <!-- Contributions -->
            <div class="col-12 col-md-6 col-lg-3">
                <div class="border rounded-3 p-3 h-100" style="background:#f8fffc">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-cash-coin text-success fs-5"></i>
                        <strong class="small"><?= t('rep_contributions') ?></strong>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?= APP_URL ?>/admin/export.php?type=contributions&format=excel&year=<?= $year ?>&month=<?= $month ?>"
                           class="btn btn-success btn-sm flex-fill">
                            <i class="bi bi-file-earmark-excel me-1"></i>Excel
                        </a>
                        <a href="<?= APP_URL ?>/admin/export.php?type=contributions&format=pdf&year=<?= $year ?>&month=<?= $month ?>"
                           target="_blank" class="btn btn-outline-danger btn-sm flex-fill">
                            <i class="bi bi-file-earmark-pdf me-1"></i>PDF
                        </a>
                    </div>
                </div>
            </div>
            <!-- Loans -->
            <div class="col-12 col-md-6 col-lg-3">
                <div class="border rounded-3 p-3 h-100" style="background:#fffaf5">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-bank text-warning fs-5"></i>
                        <strong class="small"><?= t('rep_loans') ?></strong>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?= APP_URL ?>/admin/export.php?type=loans&format=excel&year=<?= $year ?>"
                           class="btn btn-success btn-sm flex-fill">
                            <i class="bi bi-file-earmark-excel me-1"></i>Excel
                        </a>
                        <a href="<?= APP_URL ?>/admin/export.php?type=loans&format=pdf&year=<?= $year ?>"
                           target="_blank" class="btn btn-outline-danger btn-sm flex-fill">
                            <i class="bi bi-file-earmark-pdf me-1"></i>PDF
                        </a>
                    </div>
                </div>
            </div>
            <!-- Members -->
            <div class="col-12 col-md-6 col-lg-3">
                <div class="border rounded-3 p-3 h-100" style="background:#f5f8ff">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-people text-primary fs-5"></i>
                        <strong class="small">Members List</strong>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?= APP_URL ?>/admin/export.php?type=members&format=excel"
                           class="btn btn-success btn-sm flex-fill">
                            <i class="bi bi-file-earmark-excel me-1"></i>Excel
                        </a>
                        <a href="<?= APP_URL ?>/admin/export.php?type=members&format=pdf"
                           target="_blank" class="btn btn-outline-danger btn-sm flex-fill">
                            <i class="bi bi-file-earmark-pdf me-1"></i>PDF
                        </a>
                    </div>
                </div>
            </div>
            <!-- Summary -->
            <div class="col-12 col-md-6 col-lg-3">
                <div class="border rounded-3 p-3 h-100" style="background:#fdf5ff">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-bar-chart text-purple fs-5" style="color:#7c3aed"></i>
                        <strong class="small">Financial Summary</strong>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?= APP_URL ?>/admin/export.php?type=summary&format=excel&year=<?= $year ?>&month=<?= $month ?>"
                           class="btn btn-success btn-sm flex-fill">
                            <i class="bi bi-file-earmark-excel me-1"></i>Excel
                        </a>
                        <a href="<?= APP_URL ?>/admin/export.php?type=summary&format=pdf&year=<?= $year ?>&month=<?= $month ?>"
                           target="_blank" class="btn btn-outline-danger btn-sm flex-fill">
                            <i class="bi bi-file-earmark-pdf me-1"></i>PDF
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <!-- Special reports row -->
        <div class="d-flex gap-2 flex-wrap mt-3 pt-3 border-top">
            <span class="text-muted small align-self-center me-1">Special reports:</span>
            <a href="<?= APP_URL ?>/admin/chama_ledger_report.php?year=<?= $year ?>&month=<?= $month ?: date('n') ?>"
               class="btn btn-outline-dark btn-sm" target="_blank">
                <i class="bi bi-journal-text me-1"></i>Printable Ledger Form
            </a>
            <a href="<?= APP_URL ?>/admin/monthly_report.php?year=<?= $year ?>&month=<?= $month ?: date('n') ?>"
               class="btn btn-outline-primary btn-sm">
                <i class="bi bi-table me-1"></i>Monthly Contributions Sheet
            </a>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4 no-print">
    <div class="card-header bg-card border-0 pt-3 pb-0">
        <h6 class="fw-semibold">Monthly Financial Activity — <?= $year ?></h6>
    </div>
    <div class="card-body">
        <canvas id="trendChart" height="80"></canvas>
    </div>
</div>

<!-- Member Savings Report Table -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-card d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold">Member Savings Summary</h6>
        <span class="badge bg-primary"><?= count($memberReport) ?> members</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="memberTable">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Member</th>
                        <th>Membership #</th>
                        <th><?= t('adm_wal_months_paid') ?></th>
                        <th><?= t('mc_total_saved') ?></th>
                        <th><?= t('mdash_loan_balance') ?></th>
                        <th>Net Position</th>
                    </tr>
                </thead>
                <tbody>
                <?php $idx = 1; foreach ($memberReport as $mr): ?>
                <tr>
                    <td class="text-muted small"><?= $idx++ ?></td>
                    <td class="fw-semibold small"><?= htmlspecialchars($mr['full_name']) ?></td>
                    <td class="font-monospace small text-primary"><?= htmlspecialchars($mr['membership_number'] ?? '—') ?></td>
                    <td class="small"><?= $mr['months_paid'] ?></td>
                    <td class="fw-bold small text-success"><?= money($mr['total_saved'], $curr) ?></td>
                    <td class="small <?= $mr['loan_balance'] > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>">
                        <?= money($mr['loan_balance'], $curr) ?>
                    </td>
                    <td class="fw-bold small <?= ($mr['total_saved'] - $mr['loan_balance']) >= 0 ? 'text-success':'text-danger' ?>">
                        <?= money($mr['total_saved'] - $mr['loan_balance'], $curr) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="4" class="text-end small">TOTALS</td>
                        <td class="small text-success"><?= money(array_sum(array_column($memberReport,'total_saved')), $curr) ?></td>
                        <td class="small text-danger"><?= money(array_sum(array_column($memberReport,'loan_balance')), $curr) ?></td>
                        <td class="small"><?= money(
                            array_sum(array_column($memberReport,'total_saved')) -
                            array_sum(array_column($memberReport,'loan_balance')), $curr) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Expense Breakdown -->
<?php if (!empty($expenseByCategory)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-card">
        <h6 class="mb-0 fw-semibold">Expense Breakdown by Category (<?= $year ?>)</h6>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr><th>Category</th><th>Amount</th><th>% of Total</th></tr>
            </thead>
            <tbody>
            <?php foreach ($expenseByCategory as $ec): ?>
            <tr>
                <td class="small"><?= htmlspecialchars($ec['category']) ?></td>
                <td class="small fw-semibold text-danger"><?= money($ec['total'], $curr) ?></td>
                <td class="small">
                    <?php $pct = $totalExpenses > 0 ? ($ec['total'] / $totalExpenses * 100) : 0; ?>
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height:6px">
                            <div class="progress-bar bg-danger" style="width:<?= $pct ?>%"></div>
                        </div>
                        <span><?= round($pct,1) ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Footer for print -->
<div class="d-none d-print-block text-center mt-4" style="font-size:11px; color:#666;">
    <hr>
    Report generated by ChamaLedger Financial System · <?= date('d F Y, g:ia') ?> ·
    Printed by <?= htmlspecialchars($_SESSION['user_name']) ?>
</div>

<?php
$extraScripts = <<<JS
<script>
new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: {
        labels: {$chartLabels},
        datasets: [
            {
                label: 'Contributions',
                data: {$chartContribs},
                backgroundColor: 'rgba(25,135,84,0.8)',
                borderRadius: 4, stack: 'income'
            },
            {
                label: 'Repayments',
                data: {$chartRepay},
                backgroundColor: 'rgba(13,110,253,0.8)',
                borderRadius: 4, stack: 'income'
            },
            {
                label: 'Expenses',
                data: {$chartExpenses},
                backgroundColor: 'rgba(220,53,69,0.8)',
                borderRadius: 4, stack: 'outflow'
            }
        ]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => 'KES ' + v.toLocaleString() } }
        },
        plugins: { legend: { position: 'top' } }
    }
});
</script>
JS;
require_once ROOT . '/includes/footer.php';
?>
