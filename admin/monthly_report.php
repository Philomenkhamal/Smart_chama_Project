<?php
/**
 * ChamaLedger — <?= t('report_monthly') ?>
 * Mirrors the Like Minded Brothers SHG sheet format:
 * No. | Name | Nickname | Phone | Position | Debt B/Bf | Expected | Paid | Fines | Debt C/Fwd
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Monthly Report — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();

$pdo       = getDB();
$curr      = getSetting('currency', 'KES');
$groupName = getSetting('group_name', 'ChamaLedger');
$required  = (float)getSetting('monthly_contribution', '10300');

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? (int)date('m'));
$monthDate      = sprintf('%04d-%02d-01', $year, $month);
$monthLabel     = date('F Y', strtotime($monthDate));
$selectedMonthYm = date('Y-m', strtotime($monthDate)); // used for join-date comparison
$prevMonth  = date('Y-m-01', strtotime($monthDate . ' -1 month'));

// ── Fetch all active members with their data for this month ──────────────────
$members = $pdo->query("
    SELECT u.id, u.full_name, u.nickname, u.phone, u.chama_position, u.membership_number, u.joined_date, u.created_at, u.profile_photo
    FROM users u
    WHERE u.role='member' AND u.status='active'
    ORDER BY u.membership_number ASC, u.full_name ASC
")->fetchAll();

$rows       = [];
$totDebtBf  = 0; $totExpected = 0; $totPaid = 0;
$totFines   = 0; $totDebtCf   = 0;

foreach ($members as $i => $m) {
    $uid = $m['id'];

    // Debt B/Bf = wallet balance at END of previous month
    // We approximate: current balance BEFORE this month's charge and payment
    // = current balance - this month's payment + this month's charge
    $wStmt = $pdo->prepare("SELECT COALESCE(balance,0) FROM member_wallet WHERE user_id=?");
    $wStmt->execute([$uid]); $walletBal = (float)($wStmt->fetchColumn() ?: 0);

    // This month's confirmed payments
    $paidStmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount),0) FROM contributions
        WHERE user_id=? AND status='confirmed'
        AND DATE_FORMAT(payment_month,'%Y-%m') = ?
    ");
    $paidStmt->execute([$uid, sprintf('%04d-%02d',$year,$month)]);
    $paid = (float)$paidStmt->fetchColumn();

    // Fines this month
    $finesStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN fine_type='absentee'          THEN amount ELSE 0 END),0) AS absentee,
            COALESCE(SUM(CASE WHEN fine_type='late_contribution'  THEN amount ELSE 0 END),0) AS late,
            COALESCE(SUM(CASE WHEN fine_type='agm'               THEN amount ELSE 0 END),0) AS agm,
            COALESCE(SUM(CASE WHEN fine_type='custom'            THEN amount ELSE 0 END),0) AS custom,
            COALESCE(SUM(amount),0) AS total
        FROM member_fines
        WHERE user_id=? AND DATE_FORMAT(month,'%Y-%m')=? AND status != 'waived'
    ");
    $finesStmt->execute([$uid, sprintf('%04d-%02d',$year,$month)]);
    $fines = $finesStmt->fetch();

    // Debt B/Bf = balance before this month's activity
    // = current_balance - paid + required + total_fines_pending_this_month
    $debtBf = $walletBal - $paid + $required + (float)$fines['total'];

    // Debt C/Fwd = wallet balance now (after payments and charges)
    $debtCf = $walletBal;

    $rows[] = [
        'no'         => $i + 1,
        'id'         => $uid,
        'member_start' => date('Y-m', strtotime(getMemberStartDate($m))),
        'name'       => $m['full_name'],
        'profile_photo' => $m['profile_photo'] ?? null,
        'nickname'   => $m['nickname']       ?? '',
        'phone'      => $m['phone']          ?? '',
        'position'   => $m['chama_position'] ?? '',
        'memno'      => $m['membership_number'] ?? '',
        'debt_bf'    => $debtBf,
        'expected'   => $required,
        'paid'       => $paid,
        'fine_absent'=> (float)$fines['absentee'],
        'fine_late'  => (float)$fines['late'],
        'fine_agm'   => (float)$fines['agm'],
        'fine_custom'=> (float)$fines['custom'],
        'fine_total' => (float)$fines['total'],
        'debt_cf'    => $debtCf,
    ];

    $totDebtBf  += $debtBf;
    $totExpected += $required;
    $totPaid     += $paid;
    $totFines    += (float)$fines['total'];
    $totDebtCf   += $debtCf;
}

require_once ROOT . '/includes/header.php';
?>

<style>
@media print {
    .no-print { display:none!important; }
    body { background:#fff!important; color:#000!important; }
    .report-table { font-size:9pt; }
    .page-title { font-size:13pt; }
    .card { box-shadow:none!important; border:1px solid #ddd!important; }
}
.report-table th { font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
.report-table td { font-size:.8rem; vertical-align:middle; }
.debt-neg { color:#ef4444; font-weight:700; }
.debt-pos { color:#00c471; font-weight:700; }
.debt-zero{ color:#6b7280; }
.fine-cell { color:#f59e0b; }
</style>

<!-- Controls -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h4 class="fw-bold mb-0"><?= t('report_monthly') ?></h4>
        <small class="text-muted">Mirrors your chama sheet — debt carried forward per member</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <form class="d-flex gap-2" method="GET">
            <select name="month" class="form-select form-select-sm" style="width:130px">
                <?php for ($m=1;$m<=12;$m++): ?>
                <option value="<?= $m ?>" <?= $m===$month?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                <?php endfor; ?>
            </select>
            <select name="year" class="form-select form-select-sm" style="width:90px">
                <?php for ($y=date('Y');$y>=2020;$y--): ?>
                <option value="<?= $y ?>" <?= $y===$year?'selected':'' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <button class="btn btn-primary btn-sm">Go</button>
        </form>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i><?= t('report_print') ?>
        </button>
        <a href="<?= APP_URL ?>/admin/export.php?type=contributions&format=excel&year=<?= $year ?>&month=<?= $month ?>"
           class="btn btn-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Excel
        </a>
        <a href="<?= APP_URL ?>/admin/export.php?type=contributions&format=pdf&year=<?= $year ?>&month=<?= $month ?>"
           target="_blank" class="btn btn-danger btn-sm">
            <i class="bi bi-file-earmark-pdf me-1"></i>PDF
        </a>
        <a href="<?= APP_URL ?>/admin/fines.php?month=<?= $year.'-'.sprintf('%02d',$month) ?>" class="btn btn-warning btn-sm">
            <i class="bi bi-cash-coin me-1"></i>Manage Fines
        </a>
    </div>
</div>

<!-- Report Header (prints nicely) -->
<div class="card border-0 shadow-sm mb-0" style="border-radius:12px 12px 0 0!important">
    <div class="card-body py-3 px-4" style="background:var(--sidebar-bg);border-radius:12px 12px 0 0">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <div class="fw-bold page-title" style="font-size:1.1rem;font-family:'Syne',sans-serif"><?= htmlspecialchars($groupName) ?></div>
                <div style="font-size:.85rem;color:var(--text-muted)"><?= strtoupper($monthLabel) ?> CONTRIBUTIONS REPORT</div>
            </div>
            <div style="font-size:.78rem;color:var(--text-muted);text-align:right">
                <div>Generated: <?= date('d M Y, H:i') ?></div>
                <div>Monthly Contribution: <strong><?= $curr ?> <?= number_format($required,0) ?></strong></div>
            </div>
        </div>
    </div>
</div>

<!-- Report Table -->
<div class="card border-0 shadow-sm" style="border-radius:0 0 12px 12px">
    <div class="table-responsive">
        <table class="table table-hover report-table mb-0">
            <thead>
                <tr style="background:var(--sidebar-bg)">
                    <th class="ps-3">#</th>
                    <th>Name</th>
                    <th>Nickname</th>
                    <th>Phone</th>
                    <th>Position</th>
                    <th class="text-end">Debt B/Bf</th>
                    <th class="text-end"><?= t('report_expected') ?></th>
                    <th class="text-end"><?= t('report_paid') ?></th>
                    <th class="text-end" title="Absentee Fine">Absent</th>
                    <th class="text-end" title="Late Contribution Fine">Late</th>
                    <th class="text-end" title="AGM Fine">AGM</th>
                    <th class="text-end" title="Other Fines">Other</th>
                    <th class="text-end pe-3">Debt C/Fwd</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                // Positive debt = owes money = red. Negative = credit/overpaid = green
                $cfClass = $r['debt_cf'] > 0 ? 'debt-neg' : ($r['debt_cf'] < 0 ? 'debt-pos' : 'debt-zero');
                $bfClass = $r['debt_bf'] > 0 ? 'debt-neg' : ($r['debt_bf'] < 0 ? 'debt-pos' : 'debt-zero');
            ?>
            <tr>
                <td class="ps-3 text-muted"><?= $r['no'] ?></td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <?= memberAvatar(['full_name'=>$r['name'],'profile_photo'=>$r['profile_photo']??null], 34) ?>
                        <div>
                            <div class="fw-semibold" style="font-size:.82rem"><?= htmlspecialchars($r['name']) ?></div>
                            <?php if ($r['memno']): ?><div style="font-size:.68rem;color:var(--text-muted);font-family:monospace"><?= $r['memno'] ?></div><?php endif; ?>
                        </div>
                    </div>
                </td>
                <td style="color:var(--text-muted)"><?= htmlspecialchars($r['nickname']) ?></td>
                <td style="font-family:monospace;font-size:.78rem"><?= htmlspecialchars($r['phone']) ?></td>
                <td style="font-size:.75rem">
                    <?php if ($r['position']): ?>
                    <span class="badge bg-primary bg-opacity-10 text-primary" style="font-size:.68rem"><?= htmlspecialchars($r['position']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="text-end <?= $bfClass ?>">
                    <?= $r['debt_bf'] != 0 ? ($r['debt_bf'] > 0 ? '' : '') . number_format($r['debt_bf'],0) : '—' ?>
                </td>
                <?php
                $isPreJoin = ($r['member_start'] ?? '0000-00') > $selectedMonthYm;
                ?>
                <td class="text-end" style="color:<?= $isPreJoin ? 'rgba(255,255,255,.2)' : 'var(--text-muted)' ?>"><?= $isPreJoin ? 'n/a' : number_format($r['expected'],0) ?></td>
                <td class="text-end <?= $isPreJoin ? '' : ($r['paid']>0?'debt-pos':'text-muted') ?>" <?= $isPreJoin ? 'style="color:rgba(255,255,255,.2);font-size:.72rem"' : '' ?>>
                    <?= $isPreJoin ? 'n/a' : ($r['paid'] > 0 ? number_format($r['paid'],0) : '—') ?>
                </td>
                <td class="text-end fine-cell"><?= $r['fine_absent'] > 0 ? number_format($r['fine_absent'],0) : '' ?></td>
                <td class="text-end fine-cell"><?= $r['fine_late']   > 0 ? number_format($r['fine_late'],0)   : '' ?></td>
                <td class="text-end fine-cell"><?= $r['fine_agm']    > 0 ? number_format($r['fine_agm'],0)    : '' ?></td>
                <td class="text-end fine-cell"><?= $r['fine_custom'] > 0 ? number_format($r['fine_custom'],0) : '' ?></td>
                <td class="text-end pe-3 <?= $cfClass ?>" style="font-weight:800;font-family:'Syne',sans-serif">
                    <?= number_format($r['debt_cf'], 0) ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr><td colspan="13" class="text-center text-muted py-5">No active members found.</td></tr>
            <?php endif; ?>
            </tbody>
            <!-- Totals Row -->
            <tfoot>
                <tr style="background:var(--sidebar-bg);font-weight:800;border-top:2px solid var(--border)">
                    <td colspan="5" class="ps-3 py-3" style="font-family:'Syne',sans-serif">TOTALS</td>
                    <td class="text-end <?= $totDebtBf > 0 ? 'debt-neg' : ($totDebtBf < 0 ? 'debt-pos' : '') ?>"><?= number_format($totDebtBf, 0) ?></td>
                    <td class="text-end"><?= number_format($totExpected, 0) ?></td>
                    <td class="text-end debt-pos"><?= number_format($totPaid, 0) ?></td>
                    <td colspan="4" class="text-end fine-cell"><?= $totFines > 0 ? number_format($totFines, 0) : '' ?></td>
                    <td class="text-end pe-3 <?= $totDebtCf > 0 ? 'debt-neg' : ($totDebtCf < 0 ? 'debt-pos' : '') ?>"><?= number_format($totDebtCf, 0) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mt-3 no-print">
    <?php
    // Only count members who were already members in the selected month
    $activeRows   = array_filter($rows, fn($r) => ($r['member_start'] ?? '0000-00') <= $selectedMonthYm);
    $paidCount    = count(array_filter($activeRows, fn($r) => $r['paid'] >= $r['expected']));
    $unpaidCount  = count(array_filter($activeRows, fn($r) => $r['paid'] == 0));
    $partialCount = count($activeRows) - $paidCount - $unpaidCount;
    $collRate     = count($rows) > 0 ? round(($totPaid / ($totExpected ?: 1)) * 100) : 0;
    ?>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.5rem;font-weight:800;color:#00c471"><?= $paidCount ?></div>
            <div style="font-size:.75rem;color:var(--text-muted)">Fully Paid</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.5rem;font-weight:800;color:#ef4444"><?= $unpaidCount ?></div>
            <div style="font-size:.75rem;color:var(--text-muted)">Not Paid</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.5rem;font-weight:800;color:#f59e0b"><?= $partialCount ?></div>
            <div style="font-size:.75rem;color:var(--text-muted)">Partial</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.5rem;font-weight:800;color:#3b82f6"><?= $collRate ?>%</div>
            <div style="font-size:.75rem;color:var(--text-muted)">Collection Rate</div>
        </div>
    </div>
</div>

<?php require_once ROOT . '/includes/footer.php'; ?>
