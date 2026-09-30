<?php
/**
 * ChamaLedger — Members Actual Contributions Report
 * Matches the "Members actual Contr." sheet in the Excel exactly:
 * NO | NAME | PHONE | POSITION | [Month payments...] | TOTALS | LESS EVENTS | FINES | MEM CLAIM | CO CLAIM | EXPECTED | BALANCE
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Members Actual Contributions Report';
require_once ROOT . '/includes/functions.php';
requireAdmin();

$pdo        = getDB();
$curr       = getSetting('currency', 'KES');
$groupName  = getSetting('group_name', 'ChamaLedger');
$monthlyAmt = (float)getSetting('monthly_contribution', '10300');

$year = (int)($_GET['year'] ?? date('Y'));

// Build months for this year up to current month
$currentYm  = date('Y-m');
$months = [];
for ($m = 1; $m <= 12; $m++) {
    $ym = sprintf('%04d-%02d', $year, $m);
    if ($ym > $currentYm) break; // don't show future months
    $months[] = [
        'num'   => $m,
        'ym'    => $ym,
        'mo'    => $ym . '-01',
        'label' => date('M Y', mktime(0,0,0,$m,1,$year)),
        'short' => strtoupper(date('M', mktime(0,0,0,$m,1,$year))),
    ];
}

// Total expected per member for the whole period
$totalExpected = count($months) * $monthlyAmt;

$members = $pdo->query("
    SELECT id, full_name, nickname, membership_number, phone, chama_position,
           joined_date, created_at
    FROM users WHERE role='member' AND status='active'
    ORDER BY membership_number, full_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Get month label with event name if applicable ────────────────────────────
function getMonthLabel(PDO $pdo, string $ym, string $short, int $year): string {
    // Check if there are any event obligations this month
    $s = $pdo->prepare("
        SELECT DISTINCT REPLACE(REPLACE(description, 'EVENT_OBLIGATION: ', ''), ' - imported', '')
        FROM member_fines
        WHERE fine_type='custom'
        AND DATE_FORMAT(month,'%Y-%m')=?
        AND description LIKE 'EVENT_OBLIGATION:%'
        LIMIT 1
    ");
    $s->execute([$ym]);
    $event = $s->fetchColumn();
    
    $label = $short . ' ' . $year . ' Pay';
    if ($event) {
        // Shorten event name
        $short_event = explode(' ', $event)[0]; // first word
        if (stripos($event, 'mbaria') !== false)   $short_event = "MBARIA'S";
        if (stripos($event, 'muhia') !== false)    $short_event = "MUHIA'S";
        if (stripos($event, 'quarter') !== false)  $short_event = "+FOOD";
        if (stripos($event, 'mombasa') !== false)  $short_event = "+AGM";
        if (stripos($event, 'luisoi') !== false)   $short_event = "+LUISOI";
        $label .= " + " . $short_event;
    }
    return $label;
}

// ── Get data per member ──────────────────────────────────────────────────────
$tableData = [];

foreach ($members as $idx => $mem) {
    $uid   = $mem['id'];
    $phone = preg_replace('/^254/', '0', $mem['phone'] ?? '');
    
    $monthPayments = []; // ['ym' => amount]
    $totalPaid     = 0.0;
    $totalEvents   = 0.0;
    $totalFines    = 0.0;
    
    foreach ($months as $mo) {
        // Actual payment this month
        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE user_id=? AND status='confirmed' AND DATE_FORMAT(payment_month,'%Y-%m')=?");
        $s->execute([$uid, $mo['ym']]);
        $paid = (float)$s->fetchColumn();
        $monthPayments[$mo['ym']] = $paid;
        $totalPaid += $paid;
        
        // Event obligations this month
        $eo = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM member_fines WHERE user_id=? AND status IN ('pending','paid') AND DATE_FORMAT(month,'%Y-%m')=? AND description LIKE 'EVENT_OBLIGATION:%'");
        $eo->execute([$uid, $mo['ym']]);
        $totalEvents += (float)$eo->fetchColumn();
        
        // All fines this month
        $fines = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM member_fines WHERE user_id=? AND status IN ('pending','paid') AND DATE_FORMAT(month,'%Y-%m')=? AND description NOT LIKE 'EVENT_OBLIGATION:%'");
        $fines->execute([$uid, $mo['ym']]);
        $totalFines += (float)$fines->fetchColumn();
    }
    
    // Calculations matching Excel exactly
    $netPureSavings = $totalPaid - $totalEvents - $totalFines;
    $memberClaim    = max(0, $netPureSavings);   // what member can claim from company
    $companyClaim   = min(0, $netPureSavings);   // negative = company owes member (overpaid)
    $finalBalance   = $totalExpected - $totalPaid; // what member still owes
    
    $tableData[] = [
        'no'          => $idx + 1,
        'name'        => $mem['full_name'],
        'phone'       => $phone,
        'position'    => $mem['chama_position'] ?? '',
        'months'      => $monthPayments,
        'total_paid'  => $totalPaid,
        'less_events' => $totalEvents,
        'total_fines' => $totalFines,
        'member_claim'=> $memberClaim,
        'company_claim'=> $companyClaim,
        'expected'    => $totalExpected,
        'final_balance'=> $finalBalance,
    ];
}

// Column totals
$totMonths    = array_fill_keys(array_column($months, 'ym'), 0.0);
$totPaid      = 0; $totEvents = 0; $totFines = 0;
$totMemClaim  = 0; $totCoClaim = 0; $totExpected = 0; $totFinal = 0;
foreach ($tableData as $r) {
    foreach ($months as $mo) $totMonths[$mo['ym']] += $r['months'][$mo['ym']];
    $totPaid     += $r['total_paid'];
    $totEvents   += $r['less_events'];
    $totFines    += $r['total_fines'];
    $totMemClaim += $r['member_claim'];
    $totCoClaim  += $r['company_claim'];
    $totExpected += $r['expected'];
    $totFinal    += $r['final_balance'];
}

function n(float $v, bool $blankZero=false): string {
    if ($blankZero && $v == 0) return '';
    return number_format((int)round($v));
}

// ── CSV Download ──────────────────────────────────────────────────────────────
if (isset($_GET['download'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"Members_Actual_Contributions_{$year}.csv\"");
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Title
    fputcsv($out, ['Members actual Payments>']);
    
    // Headers
    $h = ['NO.', 'NAME', 'PHONE NUMBER', 'Position'];
    foreach ($months as $mo) $h[] = getMonthLabel($pdo, $mo['ym'], $mo['short'], $year);
    $h[] = 'TOTALS';
    $h[] = 'Less Funeral,Uthoni,Meeting & Hospital';
    $h[] = 'Totals Fines';
    $h[] = 'Members Claim From Company';
    $h[] = 'Companies Claim From Members.';
    $h[] = 'Total Expected';
    $h[] = 'Balance';
    fputcsv($out, $h);
    
    foreach ($tableData as $r) {
        $row = [$r['no'], $r['name'], $r['phone'], $r['position']];
        foreach ($months as $mo) $row[] = $r['months'][$mo['ym']] > 0 ? (int)$r['months'][$mo['ym']] : '';
        $row[] = (int)$r['total_paid'];
        $row[] = $r['less_events'] > 0 ? (int)$r['less_events'] : '';
        $row[] = $r['total_fines'] > 0 ? (int)$r['total_fines'] : '';
        $row[] = $r['member_claim'] > 0 ? (int)$r['member_claim'] : '';
        $row[] = $r['company_claim'] < 0 ? (int)$r['company_claim'] : '';
        $row[] = (int)$r['expected'];
        $row[] = (int)$r['final_balance'];
        fputcsv($out, $row);
    }
    // Totals
    $tot = [' ', '', '', ''];
    foreach ($months as $mo) $tot[] = $totMonths[$mo['ym']] > 0 ? (int)$totMonths[$mo['ym']] : '';
    $tot[] = (int)$totPaid; $tot[] = (int)$totEvents; $tot[] = (int)$totFines;
    $tot[] = (int)$totMemClaim; $tot[] = (int)$totCoClaim;
    $tot[] = (int)$totExpected; $tot[] = (int)$totFinal;
    fputcsv($out, $tot);
    fclose($out); exit;
}

require_once ROOT . '/includes/header.php';
?>

<style>
.report-wrap { overflow-x: auto; }
.report-table { border-collapse: collapse; font-size: .76rem; width: 100%; }
.report-table th {
    background: #1a3a5c; color: #fff;
    padding: .4rem .55rem; text-align: center;
    font-size: .63rem; font-weight: 700; white-space: nowrap;
    border: 1px solid #2d5986; text-transform: uppercase;
    letter-spacing: .02em; line-height: 1.3;
}
.report-table td { padding: .35rem .55rem; border: 1px solid rgba(255,255,255,.07); white-space: nowrap; }
.report-table tbody tr:nth-child(even) td { background: rgba(255,255,255,.02); }
.report-table tbody tr:hover td { background: rgba(0,196,113,.05); }
.col-no    { text-align:center; width:30px; color:var(--text-muted); font-size:.7rem; }
.col-name  { text-align:left; min-width:140px; font-weight:600; font-size:.78rem; }
.col-phone { font-family:monospace; font-size:.7rem; color:var(--text-muted); }
.col-pos   { text-align:center; font-size:.63rem; }
.col-num   { text-align:right; font-variant-numeric:tabular-nums; min-width:65px; }
.pos-b     { background:rgba(255,255,255,.08); border-radius:3px; padding:1px 5px; font-size:.6rem; }
.col-paid  { color:#00c471; font-weight:700; }
.col-event { color:#a78bfa; }
.col-fine  { color:#f59e0b; }
.col-total { color:#fff; font-weight:800; background:rgba(26,58,92,.3) !important; }
.col-mem-claim  { color:#22c55e; font-weight:700; }
.col-co-claim   { color:#f59e0b; font-weight:700; }
.col-debt  { color:#ef4444; font-weight:800; }
.col-credit{ color:#22c55e; font-weight:800; }
.separator-col { border-left: 2px solid #3b82f6 !important; }
.totals-row td { background: rgba(26,58,92,.8) !important; font-weight:800; color:#fff; border-top:2px solid #3b82f6; }
@media print {
    .no-print { display:none!important; }
    body,.card { background:#fff!important; color:#000!important; }
    .report-table { font-size:6.5pt!important; }
    .report-table th,.report-table td { padding:1px 2px!important; }
    .report-table th { background:#1a3a5c!important; color:#fff!important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .totals-row td { background:#1a3a5c!important; color:#fff!important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .print-hdr { display:block!important; }
}
.print-hdr { display:none; text-align:center; margin-bottom:.75rem; }
</style>

<!-- Print header -->
<div class="print-hdr">
    <h2 style="font-size:13pt;font-weight:800;margin:0"><?= htmlspecialchars($groupName) ?></h2>
    <h3 style="font-size:11pt;margin:.2rem 0">MEMBERS ACTUAL CONTRIBUTIONS — <?= $year ?></h3>
    <p style="font-size:8pt;color:#666;margin:0">Monthly Contribution: <?= $curr ?> <?= number_format($monthlyAmt) ?> &nbsp;|&nbsp; Generated: <?= date('d M Y') ?></p>
    <hr style="margin:.3rem 0">
</div>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3 no-print">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-bar-chart-steps me-2" style="color:var(--green)"></i>Members Actual Contributions</h4>
        <div class="text-muted small"><?= htmlspecialchars($groupName) ?> &mdash; <?= $year ?> Summary</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?year=<?=$year?>&download=1" class="btn btn-sm btn-outline-success">
            <i class="bi bi-file-earmark-excel me-1"></i>Download CSV
        </a>
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer me-1"></i>Print
        </button>
    </div>
</div>

<!-- Year filter -->
<div class="card mb-4 no-print">
    <div class="card-body p-3">
        <form method="GET" class="d-flex gap-3 align-items-end">
            <div>
                <label class="form-label mb-1 small fw-semibold">Year</label>
                <select name="year" onchange="this.form.submit()" class="form-select form-select-sm" style="width:100px">
                    <?php for($y=date('Y');$y>=date('Y')-5;$y--): ?>
                    <option value="<?=$y?>" <?=$y===$year?'selected':''?>><?=$y?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="text-muted small">
                Showing <?= count($months) ?> months &nbsp;|&nbsp;
                Expected per member: <strong><?= $curr ?> <?= number_format($totalExpected) ?></strong>
            </div>
        </form>
    </div>
</div>

<!-- Summary stats -->
<div class="row g-3 mb-4 no-print">
    <div class="col-6 col-md-3">
        <div class="stat-card green">
            <div class="stat-icon green"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-label">Total Collected</div>
            <div class="stat-value sm"><?=$curr?> <?=number_format($totPaid)?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card amber">
            <div class="stat-icon amber"><i class="bi bi-calendar-event"></i></div>
            <div class="stat-label">Events Deducted</div>
            <div class="stat-value sm"><?=$curr?> <?=number_format($totEvents)?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card red">
            <div class="stat-icon red"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="stat-label">Total Fines</div>
            <div class="stat-value sm"><?=$curr?> <?=number_format($totFines)?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="bi bi-people"></i></div>
            <div class="stat-label">Total Expected</div>
            <div class="stat-value sm"><?=$curr?> <?=number_format($totExpected)?></div>
        </div>
    </div>
</div>

<!-- THE REPORT TABLE -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="bi bi-table me-2"></i>Members Actual Payments — <?= $year ?></h6>
        <span class="badge bg-primary no-print"><?= count($members) ?> members</span>
    </div>
    <div class="card-body p-0">
        <div class="report-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th class="col-no">NO.</th>
                        <th style="text-align:left">NAME</th>
                        <th style="text-align:left">PHONE NUMBER</th>
                        <th>POSITION</th>
                        <?php foreach ($months as $mo): ?>
                        <th class="col-num"><?= getMonthLabel($pdo, $mo['ym'], $mo['short'], $year) ?></th>
                        <?php endforeach; ?>
                        <th class="col-num separator-col" style="background:#0d2240">TOTALS</th>
                        <th class="col-num" title="Funerals, Uthonis, Meetings, Hospital">LESS EVENTS</th>
                        <th class="col-num">TOTALS FINES</th>
                        <th class="col-num separator-col" title="What member can claim from company (pure savings)">MEMBERS<br>CLAIM</th>
                        <th class="col-num" title="What company claims from member (negative = overpaid)">COMPANY<br>CLAIM</th>
                        <th class="col-num separator-col" style="background:#0d2240">TOTAL<br>EXPECTED</th>
                        <th class="col-num" style="background:#0f1f35" title="Balance = Expected - Paid">BALANCE</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tableData as $r): ?>
                <tr>
                    <td class="col-no"><?= $r['no'] ?></td>
                    <td class="col-name"><?= htmlspecialchars($r['name']) ?></td>
                    <td class="col-phone"><?= htmlspecialchars($r['phone']) ?></td>
                    <td class="col-pos"><?= $r['position'] ? '<span class="pos-b">'.htmlspecialchars($r['position']).'</span>' : '' ?></td>

                    <?php foreach ($months as $mo):
                        $amt = $r['months'][$mo['ym']];
                    ?>
                    <td class="col-num <?= $amt > 0 ? 'col-paid' : '' ?>">
                        <?= $amt > 0 ? n($amt) : '' ?>
                    </td>
                    <?php endforeach; ?>

                    <!-- TOTALS -->
                    <td class="col-num col-total separator-col"><?= n($r['total_paid']) ?></td>
                    <!-- LESS EVENTS -->
                    <td class="col-num col-event"><?= $r['less_events'] > 0 ? n($r['less_events']) : '' ?></td>
                    <!-- FINES -->
                    <td class="col-num col-fine"><?= $r['total_fines'] > 0 ? n($r['total_fines']) : '' ?></td>
                    <!-- MEMBER CLAIM -->
                    <td class="col-num col-mem-claim separator-col"><?= $r['member_claim'] > 0 ? n($r['member_claim']) : '' ?></td>
                    <!-- COMPANY CLAIM (negative = company owes member) -->
                    <td class="col-num col-co-claim"><?= $r['company_claim'] < 0 ? n($r['company_claim']) : '' ?></td>
                    <!-- EXPECTED -->
                    <td class="col-num col-total separator-col"><?= n($r['expected']) ?></td>
                    <!-- BALANCE: positive=owes, negative=overpaid -->
                    <?php $balClass = $r['final_balance'] > 0 ? 'col-debt' : ($r['final_balance'] < 0 ? 'col-credit' : ''); ?>
                    <td class="col-num <?= $balClass ?>" style="background:rgba(15,31,53,.4)">
                        <?= n($r['final_balance']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>

                <!-- TOTALS ROW -->
                <tr class="totals-row">
                    <td class="col-no"> </td>
                    <td colspan="3" style="text-align:right;color:#aaa;font-size:.68rem;letter-spacing:.05em">TOTALS</td>
                    <?php foreach ($months as $mo): ?>
                    <td class="col-num col-paid"><?= $totMonths[$mo['ym']] > 0 ? n($totMonths[$mo['ym']]) : '' ?></td>
                    <?php endforeach; ?>
                    <td class="col-num separator-col"><?= n($totPaid) ?></td>
                    <td class="col-num col-event"><?= $totEvents > 0 ? n($totEvents) : '' ?></td>
                    <td class="col-num col-fine"><?= $totFines > 0 ? n($totFines) : '' ?></td>
                    <td class="col-num col-mem-claim separator-col"><?= $totMemClaim > 0 ? n($totMemClaim) : '' ?></td>
                    <td class="col-num col-co-claim"><?= $totCoClaim < 0 ? n($totCoClaim) : '' ?></td>
                    <td class="col-num separator-col"><?= n($totExpected) ?></td>
                    <td class="col-num <?= $totFinal > 0 ? 'col-debt' : 'col-credit' ?>"><?= n($totFinal) ?></td>
                </tr>
            </table>
        </div>
    </div>
</div>

<!-- Footer note -->
<div class="card mt-3 no-print">
    <div class="card-body py-2 px-3 small text-muted d-flex flex-wrap gap-4">
        <span><span class="text-success fw-bold">Members Claim</span> = Pure savings (paid minus events minus fines)</span>
        <span><span class="text-warning fw-bold">Company Claim</span> = Negative means company owes member (overpaid)</span>
        <span><span class="text-danger fw-bold">Balance</span> = Total Expected minus Total Paid</span>
    </div>
</div>

<?php require_once ROOT . '/includes/footer.php'; ?>
