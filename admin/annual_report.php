<?php
/**
 * ChamaLedger — Year-End Summary Report
 * Full financial overview for any year — ideal for AGM
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
requireAdmin();
$pageTitle = t('report_annual') . ' — ChamaLedger';

$pdo  = getDB();
$curr = getSetting('currency', 'KES');
$year = (int)($_GET['year'] ?? date('Y'));
$groupName = getSetting('group_name', 'ChamaLedger');

// ── Summary figures ───────────────────────────────────────────────────────────
$totalSavings  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status='confirmed' AND YEAR(payment_month)=$year")->fetchColumn();
$totalExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE YEAR(expense_date)=$year")->fetchColumn();
$loansIssued   = (float)$pdo->query("SELECT COALESCE(SUM(amount_approved),0) FROM loans WHERE status NOT IN ('pending','rejected') AND YEAR(applied_at)=$year")->fetchColumn();
$loansRepaid   = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM loan_payments WHERE status='confirmed' AND YEAR(paid_at)=$year")->fetchColumn();
$interestEarned= (float)$pdo->query("SELECT COALESCE(SUM(l.total_repayable - l.amount_approved),0) FROM loans l WHERE status='completed' AND YEAR(l.applied_at)=$year")->fetchColumn();
$activeMembers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND status='active'")->fetchColumn();
$newMembers    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND YEAR(created_at)=$year")->fetchColumn();
$netPosition   = $totalSavings + $loansRepaid - $totalExpenses;

// ── Monthly savings breakdown ─────────────────────────────────────────────────
$monthlySavings = $pdo->query("
    SELECT DATE_FORMAT(payment_month,'%b') AS month_label,
           MONTH(payment_month) AS month_num,
           COALESCE(SUM(amount),0) AS total
    FROM contributions WHERE status='confirmed' AND YEAR(payment_month)=$year
    GROUP BY month_num ORDER BY month_num
")->fetchAll(PDO::FETCH_ASSOC);

// ── Top contributors ──────────────────────────────────────────────────────────
$topContributors = $pdo->query("
    SELECT u.full_name, u.membership_number,
           COALESCE(SUM(c.amount),0) AS total_paid,
           COUNT(c.id) AS months_paid
    FROM users u LEFT JOIN contributions c ON c.user_id=u.id AND c.status='confirmed' AND YEAR(c.payment_month)=$year
    WHERE u.role='member' AND u.status='active'
    GROUP BY u.id ORDER BY total_paid DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// ── Member savings vs expected ─────────────────────────────────────────────────
$monthlyAmt = (float)getSetting('monthly_contribution', 0);
$memberPerformance = $pdo->query("
    SELECT u.full_name, u.membership_number, u.joined_date, u.profile_photo,
           COALESCE(SUM(c.amount),0) AS paid,
           COUNT(c.id) AS months_paid
    FROM users u
    LEFT JOIN contributions c ON c.user_id=u.id AND c.status='confirmed' AND YEAR(c.payment_month)=$year
    WHERE u.role='member' AND u.status='active'
    GROUP BY u.id ORDER BY u.full_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Loan summary ─────────────────────────────────────────────────────────────
$loanSummary = $pdo->query("
    SELECT status, COUNT(*) AS cnt, COALESCE(SUM(amount_approved),0) AS total
    FROM loans WHERE YEAR(applied_at)=$year GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);

// ── Expenses breakdown by category ───────────────────────────────────────────
$expensesByCategory = $pdo->query("
    SELECT category, COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
    FROM expenses WHERE YEAR(expense_date)=$year
    GROUP BY category ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Available years for selector ──────────────────────────────────────────────
$years = $pdo->query("
    SELECT DISTINCT YEAR(payment_month) yr FROM contributions
    UNION SELECT DISTINCT YEAR(created_at) FROM users WHERE role='member'
    UNION SELECT DISTINCT YEAR(expense_date) FROM expenses
    ORDER BY yr DESC
")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array(date('Y'), $years)) array_unshift($years, (int)date('Y'));

require_once ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-graph-up-arrow me-2 text-success"></i><?= t('report_annual') ?> — <?= $year ?></h4>
        <small class="text-muted"><?= htmlspecialchars($groupName) ?> · Full year financial summary</small>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <form method="GET" class="d-flex gap-2">
            <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach($years as $y): ?>
                <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i><?= t('report_print') ?>
        </button>
    </div>
</div>

<!-- KPI Summary -->
<div class="row g-3 mb-4">
    <?php
    $kpis = [
        ['Total Savings Collected',  money($totalSavings, $curr),   'success', 'bi-piggy-bank-fill'],
        ['Total Expenses',           money($totalExpenses, $curr),   'danger',  'bi-receipt-cutoff'],
        ['Loans Issued',             money($loansIssued, $curr),     'info',    'bi-cash-stack'],
        ['Loan Repayments',          money($loansRepaid, $curr),     'primary', 'bi-arrow-repeat'],
        ['Interest Earned',          money($interestEarned, $curr),  'warning', 'bi-percent'],
        ['Net Position',             money($netPosition, $curr),     $netPosition>=0?'success':'danger', 'bi-bank'],
        ['Active Members',           $activeMembers,                 'primary', 'bi-people-fill'],
        ['New Members in '.$year,    $newMembers,                    'success', 'bi-person-plus-fill'],
    ];
    foreach($kpis as [$label,$val,$color,$icon]): ?>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3 py-3">
                <div class="stat-icon bg-<?= $color ?>-subtle text-<?= $color ?>" style="flex-shrink:0">
                    <i class="bi <?= $icon ?>"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-muted" style="font-size:.75rem"><?= $label ?></div>
                    <div class="fw-bold text-<?= $color ?>"><?= $val ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <!-- Monthly Savings Chart -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-card py-3"><h6 class="fw-bold mb-0">Monthly Savings — <?= $year ?></h6></div>
            <div class="card-body">
                <canvas id="savingsChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <!-- Expenses by Category -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-card py-3"><h6 class="fw-bold mb-0">Expenses by Category</h6></div>
            <div class="card-body">
                <?php if(empty($expensesByCategory)): ?>
                <p class="text-muted small text-center pt-4">No expenses recorded in <?= $year ?></p>
                <?php else: ?>
                <canvas id="expChart" height="200"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Member Performance Table -->
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-card py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0">Member Savings Performance — <?= $year ?></h6>
        <small class="text-muted">Expected: <?= $monthlyAmt > 0 ? money($monthlyAmt * 12, $curr) . '/year' : 'N/A' ?></small>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:.875rem">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">#</th>
                    <th>Member</th>
                    <th>Months Paid</th>
                    <th>Total Paid</th>
                    <?php if($monthlyAmt > 0): ?><th>Compliance</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach($memberPerformance as $i => $mp):
                    $compliance = $monthlyAmt > 0 ? min(100, round(($mp['paid'] / ($monthlyAmt * 12)) * 100)) : null;
                    $compColor  = $compliance >= 100 ? 'success' : ($compliance >= 50 ? 'warning' : 'danger');
                ?>
                <tr>
                    <td class="ps-3 text-muted"><?= $i+1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?= memberAvatar($mp, 34) ?>
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($mp['full_name']) ?></div>
                                <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($mp['membership_number']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?= $mp['months_paid'] ?> / 12</td>
                    <td class="fw-bold"><?= money($mp['paid'], $curr) ?></td>
                    <?php if($monthlyAmt > 0): ?>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height:6px">
                                <div class="progress-bar bg-<?= $compColor ?>" style="width:<?= $compliance ?>%"></div>
                            </div>
                            <span class="small text-<?= $compColor ?> fw-semibold"><?= $compliance ?>%</span>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Loan Summary -->
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-card py-3"><h6 class="fw-bold mb-0">Loan Activity — <?= $year ?></h6></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr><th class="ps-3">Status</th><th>Count</th><th>Total Amount</th></tr>
            </thead>
            <tbody>
                <?php if(empty($loanSummary)): ?>
                <tr><td colspan="3" class="text-center text-muted py-4">No loans in <?= $year ?></td></tr>
                <?php endif; ?>
                <?php foreach($loanSummary as $ls): ?>
                <tr>
                    <td class="ps-3"><?= badgeStatus($ls['status']) ?></td>
                    <td><?= $ls['cnt'] ?></td>
                    <td class="fw-bold"><?= money($ls['total'], $curr) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
<?php
$months = array_column($monthlySavings, 'month_label');
$values = array_column($monthlySavings, 'total');
?>
new Chart(document.getElementById('savingsChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($months) ?>,
        datasets: [{
            label: 'Savings (<?= $curr ?>)',
            data: <?= json_encode($values) ?>,
            backgroundColor: 'rgba(0,196,113,.7)',
            borderRadius: 4
        }]
    },
    options: { responsive:true, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
});

<?php if(!empty($expensesByCategory)): ?>
new Chart(document.getElementById('expChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($expensesByCategory,'category')) ?>,
        datasets: [{
            data: <?= json_encode(array_column($expensesByCategory,'total')) ?>,
            backgroundColor: ['#ef4444','#f59e0b','#3b82f6','#10b981','#8b5cf6','#ec4899','#14b8a6']
        }]
    },
    options: { responsive:true, plugins:{legend:{position:'bottom'}} }
});
<?php endif; ?>
</script>

<style>
@media print {
    .sidebar, .topbar, button, form, .no-print { display:none!important }
    .card { box-shadow:none!important; border:1px solid #ddd!important }
    .main-content { margin:0!important; padding:0!important }
}
</style>

<?php require_once ROOT . '/includes/footer.php'; ?>
