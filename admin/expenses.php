<?php
/**
 * CHAMA Financial Management System
 * Admin — Expenses Management
 * Record and track group operational expenses
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Expenses — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();
require_once ROOT . '/includes/header.php';

$pdo     = getDB();
$adminId = (int)$_SESSION['user_id'];
$curr    = getSetting('currency', 'KES');

$categories = ['Venue', 'Stationery', 'Utilities', 'Transport', 'Food & Drinks',
               'Legal & Professional', 'Bank Charges', 'Communication', 'Other'];

// ── Handle POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $category    = sanitize($_POST['category']      ?? '');
        $description = sanitize($_POST['description']   ?? '');
        $amount      = (float)($_POST['amount']          ?? 0);
        $date        = sanitize($_POST['expense_date']   ?? '');
        $receiptRef  = sanitize($_POST['receipt_ref']    ?? '');

        $errors = [];
        if (!$category)          $errors[] = 'Category is required.';
        if (strlen($description) < 5) $errors[] = 'Description must be at least 5 characters.';
        if ($amount <= 0)        $errors[] = 'Amount must be greater than 0.';
        if (!$date)              $errors[] = 'Expense date is required.';

        if (empty($errors)) {
            $pdo->prepare('INSERT INTO expenses (category, description, amount, expense_date, recorded_by, receipt_ref) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$category, $description, $amount, $date, $adminId, $receiptRef]);
            logActivity('EXPENSE_ADD', "Added expense: {$category} {$curr}{$amount}");
            setFlash('success', 'Expense recorded successfully.');
        } else {
            setFlash('danger', implode('<br>', $errors));
        }
        redirect(APP_URL . '/admin/expenses.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['expense_id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM expenses WHERE id=?')->execute([$id]);
            logActivity('EXPENSE_DELETE', "Deleted expense ID:{$id}");
            setFlash('success', 'Expense deleted.');
        }
        redirect(APP_URL . '/admin/expenses.php');
    }
}

// ── Filters ────────────────────────────────────────────────────────────────────
// Sanitize at source so all subsequent echoes are safe
$filterCat   = htmlspecialchars(trim($_GET['category'] ?? ''), ENT_QUOTES, 'UTF-8');
$filterYear  = preg_match('/^\d{4}$/', $_GET['year'] ?? '') ? $_GET['year'] : date('Y');
$filterMonth = preg_match('/^\d{1,2}$/', $_GET['month'] ?? '') ? (int)$_GET['month'] : '';
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 20;
$offset      = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
if ($filterCat)   { $where[] = 'category=?';                  $params[] = $filterCat; }
if ($filterYear)  { $where[] = 'YEAR(expense_date)=?';        $params[] = $filterYear; }
if ($filterMonth) { $where[] = 'MONTH(expense_date)=?';       $params[] = $filterMonth; }
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$totalRows  = (int)$pdo->prepare("SELECT COUNT(*) FROM expenses $whereSQL")->execute($params) ?
               $pdo->prepare("SELECT COUNT(*) FROM expenses $whereSQL") : 0;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses $whereSQL");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($totalRows / $perPage);

$stmt = $pdo->prepare("
    SELECT e.*, u.full_name AS recorded_by_name
    FROM expenses e JOIN users u ON e.recorded_by = u.id
    $whereSQL
    ORDER BY e.expense_date DESC, e.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$expenses = $stmt->fetchAll();

// Category totals for chart
$catTotals = $pdo->prepare("
    SELECT category, SUM(amount) AS total
    FROM expenses
    WHERE YEAR(expense_date)=?
    GROUP BY category ORDER BY total DESC
");
$catTotals->execute([$filterYear]);
$catTotals = $catTotals->fetchAll();

// Monthly totals for bar chart
$monthlyTotals = $pdo->prepare("
    SELECT MONTH(expense_date) AS m, SUM(amount) AS total
    FROM expenses WHERE YEAR(expense_date)=?
    GROUP BY MONTH(expense_date) ORDER BY m
");
$monthlyTotals->execute([$filterYear]);
$monthlyArr = array_fill(1, 12, 0);
foreach ($monthlyTotals->fetchAll() as $row) $monthlyArr[(int)$row['m']] = (float)$row['total'];

$yearTotal = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE YEAR(expense_date)=?");
$yearTotal->execute([$filterYear]);
$yearTotal = (float)$yearTotal->fetchColumn();

$chartMonthLabels = json_encode(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']);
$chartMonthValues = json_encode(array_values($monthlyArr));
$chartCatLabels   = json_encode(array_column($catTotals, 'category'));
$chartCatValues   = json_encode(array_column($catTotals, 'total'));

$years = range(date('Y'), 2020);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><?= t('nav_expenses') ?></h4>
        <small class="text-muted"><?= t('expenses_subtitle') ?></small>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="bi bi-plus-circle me-1"></i>Add Expense
    </button>
</div>

<!-- Summary + Charts -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h6 class="fw-semibold mb-3"><?= t('adm_exp_year_summary') ?> (<?= $filterYear ?>)</h6>
                <div class="display-6 fw-bold text-danger mb-1"><?= money($yearTotal, $curr) ?></div>
                <p class="text-muted small"><?= t('expenses_total_year') ?></p>
                <hr>
                <?php foreach ($catTotals as $ct): ?>
                <div class="d-flex justify-content-between small mb-1">
                    <span class="text-muted"><?= htmlspecialchars($ct['category']) ?></span>
                    <strong><?= money($ct['total'], $curr) ?></strong>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-card border-0 pt-3 pb-0">
                <h6 class="fw-semibold mb-0"><?= t('adm_exp_monthly_trend') ?> (<?= $filterYear ?>)</h6>
            </div>
            <div class="card-body">
                <canvas id="monthlyChart" height="160"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-card border-0 pt-3 pb-0">
                <h6 class="fw-semibold mb-0"><?= t('adm_exp_by_category') ?> (<?= $filterYear ?>)</h6>
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <?php if (empty($catTotals)): ?>
                <p class="text-muted small"><?= t('expenses_no_data') ?></p>
                <?php else: ?>
                <canvas id="catChart" height="180"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= t('lbl_year') ?></label>
                <select name="year" class="form-select form-select-sm">
                    <?php foreach ($years as $y): ?>
                    <option <?= $y == $filterYear ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= t('lbl_month') ?></label>
                <select name="month" class="form-select form-select-sm">
                    <option value=""><?= t('lbl_all_months') ?></option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $filterMonth == $m ? 'selected':'' ?>>
                        <?= date('F', mktime(0,0,0,$m,1)) ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold"><?= t('lbl_category') ?></label>
                <select name="category" class="form-select form-select-sm">
                    <option value=""><?= t('lbl_all_categories') ?></option>
                    <?php foreach ($categories as $cat): ?>
                    <option <?= $filterCat === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                    <?php endforeach; ?>
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

<!-- Expenses Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-card">
        <h6 class="mb-0 fw-semibold">Expense Records <span class="badge bg-secondary ms-2"><?= $totalRows ?></span></h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= t('lbl_date') ?></th>
                        <th><?= t('expense_category') ?></th>
                        <th><?= t('lbl_description') ?></th>
                        <th><?= t('lbl_amount') ?></th>
                        <th><?= t('expense_receipt') ?></th>
                        <th><?= t('contrib_recorded_by') ?></th>
                        <th><?= t('lbl_actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($expenses)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">
                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>No expenses found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($expenses as $e): ?>
                    <tr>
                        <td class="small fw-semibold"><?= formatDate($e['expense_date'], 'd M Y') ?></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($e['category']) ?></span></td>
                        <td class="small"><?= htmlspecialchars($e['description']) ?></td>
                        <td class="fw-bold text-danger small"><?= money($e['amount'], $curr) ?></td>
                        <td class="small font-monospace text-muted"><?= htmlspecialchars($e['receipt_ref'] ?: '—') ?></td>
                        <td class="small"><?= htmlspecialchars($e['recorded_by_name']) ?></td>
                        <td>
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Delete this expense record?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="expense_id" value="<?= $e['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
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
        <small class="text-muted">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$totalRows) ?> of <?= $totalRows ?></small>
        <nav><ul class="pagination pagination-sm mb-0">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p==$page?'active':'' ?>">
                <a class="page-link" href="?page=<?= $p ?>&year=<?= $filterYear ?>&month=<?= $filterMonth ?>&category=<?= urlencode($filterCat) ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<!-- Add Expense Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="bi bi-receipt me-2 text-danger"></i><?= t('lbl_add_expense') ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                            <select name="category" class="form-select" required>
                                <option value=""><?= t('lbl_select') ?></option>
                                <?php foreach ($categories as $cat): ?>
                                <option><?= $cat ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                            <input type="date" name="expense_date" class="form-control"
                                   value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
                            <textarea name="description" class="form-control" rows="2"
                                      placeholder="What was this expense for?" required></textarea>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= t('adm_exp_amount') ?> (<?= $curr ?>) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control"
                                   min="0.01" step="0.01" placeholder="0.00" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= t('adm_exp_receipt') ?></label>
                            <input type="text" name="receipt_ref" class="form-control" placeholder="Optional ref">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= t('btn_cancel') ?></button>
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="bi bi-save me-1"></i>Save Expense
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<JS
<script>
// Monthly bar chart
new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
        labels: {$chartMonthLabels},
        datasets: [{
            label: 'Expenses ({$curr})',
            data: {$chartMonthValues},
            backgroundColor: 'rgba(220,53,69,0.75)',
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() } } }
    }
});

// Category doughnut
var catEl = document.getElementById('catChart');
if (catEl) {
    new Chart(catEl, {
        type: 'doughnut',
        data: {
            labels: {$chartCatLabels},
            datasets: [{
                data: {$chartCatValues},
                backgroundColor: ['#dc3545','#fd7e14','#ffc107','#198754','#0d6efd','#6f42c1','#20c997','#e83e8c'],
                borderWidth: 2
            }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
    });
}
</script>
JS;
require_once ROOT . '/includes/footer.php';
?>
