<?php
/**
 * SmartChama - Monthly Report
 * Exact format matching the Like Minded Excel:
 * NO | NAME | NICKNAME | PHONE | POSITION | DEBT B/F | [EVENT] | EXPECTED | PAYMENT | LATE FINE | [EVENT FINE] | DEBT C/FWD
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
$pageTitle = 'Monthly Contributions Register — ' . t('nav_ledger_report');
requireAdmin();

$pdo        = getDB();
$curr       = getSetting('currency', 'KES');
$groupName  = getSetting('group_name', 'SmartChama');
$monthlyAmt = (float)getSetting('monthly_contribution', '10300');

$year     = (int)($_GET['year']  ?? date('Y'));
$selMonth = (int)($_GET['month'] ?? date('n'));

$months = [];
for ($m = 1; $m <= 12; $m++) {
    $months[] = [
        'num'   => $m,
        'ym'    => sprintf('%04d-%02d', $year, $m),
        'mo'    => sprintf('%04d-%02d-01', $year, $m),
        'label' => date('F Y', mktime(0,0,0,$m,1,$year)),
        'short' => strtoupper(date('M', mktime(0,0,0,$m,1,$year))),
    ];
}
$mo = $months[$selMonth - 1];
$ym = $mo['ym'];

$members = $pdo->query("
    SELECT id, full_name, nickname, membership_number, phone, chama_position,
           joined_date, created_at
    FROM users WHERE role='member' AND status='active'
    ORDER BY membership_number, full_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Data for one member one month ─────────────────────────────────────────────
function getMonthData(PDO $pdo, int $uid, string $ym, float $monthlyAmt, string $memberStart): array {
    $expected = $ym >= $memberStart ? $monthlyAmt : 0;

    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE user_id=? AND status='confirmed' AND DATE_FORMAT(payment_month,'%Y-%m')=?");
    $s->execute([$uid, $ym]); $paid = (float)$s->fetchColumn();

    // Event obligations (funeral/uthoni contributions every member owes)
    $eo = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM member_fines WHERE user_id=? AND status IN ('pending','paid') AND DATE_FORMAT(month,'%Y-%m')=? AND description LIKE 'EVENT_OBLIGATION:%'");
    $eo->execute([$uid, $ym]); $event_owed = (float)$eo->fetchColumn();

    // Late fine
    $lf = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM member_fines WHERE user_id=? AND status IN ('pending','paid') AND fine_type='late_contribution' AND DATE_FORMAT(month,'%Y-%m')=?");
    $lf->execute([$uid, $ym]); $late = (float)$lf->fetchColumn();

    // Other fines (absentee, agm, custom - NOT event obligations)
    $of = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM member_fines WHERE user_id=? AND status IN ('pending','paid') AND fine_type!='late_contribution' AND DATE_FORMAT(month,'%Y-%m')=? AND description NOT LIKE 'EVENT_OBLIGATION:%'");
    $of->execute([$uid, $ym]); $other_fine = (float)$of->fetchColumn();

    return compact('expected','paid','event_owed','late','other_fine');
}

// ── Build table ────────────────────────────────────────────────────────────────
$chamaStartYm = date('Y-m', strtotime(getChamaStartDate()));

$tableData = [];
foreach ($members as $idx => $mem) {
    $uid         = $mem['id'];
    $memberStart = date('Y-m', strtotime(getMemberStartDate($mem)));

    // Accumulate debt from start up to selected month
    $debtBf = 0.0;
    for ($pm = 1; $pm < $selMonth; $pm++) {
        $pym = sprintf('%04d-%02d', $year, $pm);
        if ($pym < $memberStart) continue;
        $d = getMonthData($pdo, $uid, $pym, $monthlyAmt, $memberStart);
        $debtBf += $d['expected'] + $d['event_owed'] - $d['paid'] + $d['late'] + $d['other_fine'];
    }

    $cur    = getMonthData($pdo, $uid, $ym, $monthlyAmt, $memberStart);
    $debtCf = $debtBf + $cur['expected'] + $cur['event_owed'] - $cur['paid'] + $cur['late'] + $cur['other_fine'];

    $tableData[] = [
        'no'         => $idx + 1,
        'name'       => $mem['full_name'],
        'nickname'   => $mem['nickname'] ?? '',
        'phone'      => preg_replace('/^254/', '0', $mem['phone'] ?? ''),
        'position'   => $mem['chama_position'] ?? '',
        'debt_bf'    => $debtBf,
        'event_owed' => $cur['event_owed'],
        'expected'   => $cur['expected'],
        'paid'       => $cur['paid'],
        'late'       => $cur['late'],
        'other_fine' => $cur['other_fine'],
        'debt_cf'    => $debtCf,
        'pre_join'   => ($ym < $memberStart),
    ];
}

// Totals
$totBf=0; $totEv=0; $totExp=0; $totPaid=0; $totLate=0; $totOther=0; $totCf=0;
$hasEventCol=false; $hasOtherFine=false;
foreach ($tableData as $r) {
    if ($r['pre_join']) continue;
    $totBf   += $r['debt_bf'];  $totEv    += $r['event_owed'];
    $totExp  += $r['expected']; $totPaid  += $r['paid'];
    $totLate += $r['late'];     $totOther += $r['other_fine'];
    $totCf   += $r['debt_cf'];
    if ($r['event_owed'] > 0)  $hasEventCol  = true;
    if ($r['other_fine'] > 0)  $hasOtherFine = true;
}
$collRate = $totExp > 0 ? round($totPaid/$totExp*100,1) : 0;

// Format: whole numbers, blank for zero
function n(float $v, bool $blankZero=false): string {
    if ($blankZero && $v==0) return '';
    return number_format((int)round($v));
}

// ── CSV Download ───────────────────────────────────────────────────────────────
if (isset($_GET['download'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"" . $mo['short'] . "_{$year}_Ledger.csv\"");
    $out = fopen('php://output','w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    $h = ['NO.','NAME','NICKNAME','PHONE NUMBER','POSITION','MONTHLY CONTR DEBT B/BF'];
    if ($hasEventCol) $h[] = 'MONTHS EVENT';
    $h[] = strtoupper($mo['short']).' '.$year.' EXPECT CONTR.';
    $h[] = strtoupper($mo['short']).' '.$year.' PAYMENT';
    $h[] = strtoupper($mo['short']).' '.$year.' LATE CONTR,FINES';
    if ($hasOtherFine) $h[] = 'EVENT/OTHER FINES';
    $h[] = 'DEBT BAL. C/FWD';
    fputcsv($out, $h);
    foreach ($tableData as $r) {
        if ($r['pre_join']) continue;
        $row = [$r['no'],$r['name'],$r['nickname'],$r['phone'],$r['position'],
                $r['debt_bf']!=0 ? (int)round($r['debt_bf']) : ''];
        if ($hasEventCol) $row[] = $r['event_owed']>0 ? (int)$r['event_owed'] : '';
        $row[] = (int)$r['expected'];
        $row[] = $r['paid']>0 ? (int)$r['paid'] : '';
        $row[] = $r['late']>0 ? (int)$r['late'] : '';
        if ($hasOtherFine) $row[] = $r['other_fine']>0 ? (int)$r['other_fine'] : '';
        $row[] = (int)round($r['debt_cf']);
        fputcsv($out, $row);
    }
    // Totals row
    $tot = [' ','','','','', (int)round($totBf)];
    if ($hasEventCol) $tot[] = $totEv>0?(int)$totEv:'';
    $tot[] = (int)$totExp; $tot[] = (int)$totPaid; $tot[] = (int)$totLate;
    if ($hasOtherFine) $tot[] = (int)$totOther;
    $tot[] = (int)round($totCf);
    fputcsv($out, $tot);
    fclose($out); exit;
}

require_once ROOT . '/includes/header.php';
?>

<style>
.ledger-wrap  { overflow-x: auto; }
.ledger-table { border-collapse: collapse; font-size: .78rem; width: 100%; font-family: 'Segoe UI', Arial, sans-serif; }
.ledger-table th {
    background: #1a3a5c;
    color: #fff;
    padding: .45rem .6rem;
    text-align: center;
    font-size: .65rem;
    font-weight: 700;
    white-space: nowrap;
    border: 1px solid #2d5986;
    text-transform: uppercase;
    letter-spacing: .02em;
    line-height: 1.3;
}
.ledger-table td {
    padding: .38rem .6rem;
    border: 1px solid rgba(255,255,255,.07);
    white-space: nowrap;
    vertical-align: middle;
}
.ledger-table tbody tr:nth-child(even) td { background: rgba(255,255,255,.02); }
.ledger-table tbody tr:hover td          { background: rgba(0,196,113,.05); }

.col-no    { text-align:center; width:32px; color:var(--text-muted); font-size:.72rem; }
.col-name  { text-align:left; min-width:150px; font-weight:600; font-size:.8rem; }
.col-nick  { text-align:left; color:var(--text-muted); font-size:.72rem; min-width:80px; }
.col-phone { text-align:left; font-family:monospace; font-size:.72rem; color:var(--text-muted); }
.col-pos   { text-align:center; font-size:.65rem; }
.col-num   { text-align:right; font-variant-numeric:tabular-nums; min-width:70px; }

/* Debt colors — matching Excel: positive=owes(red), negative=credit(green) */
.debt-owed   { color:#ef4444; font-weight:700; }
.debt-credit { color:#22c55e; font-weight:700; }
.debt-zero   { color:rgba(128,128,128,.2); }
.col-paid    { color:#00c471; font-weight:700; }
.col-fine    { color:#f59e0b; font-weight:600; }
.col-event   { color:#a78bfa; font-weight:600; }

/* <?= t('report_debt_cf') ?> column */
.cf-col { background:rgba(15,45,74,.4) !important; }

/* Totals row */
.totals-row td {
    background: rgba(26,58,92,.8) !important;
    font-weight: 800;
    border-top: 2px solid #3b82f6;
    color: #fff;
}

/* Position badge */
.pos-b { background:rgba(255,255,255,.08); border-radius:3px; padding:1px 5px; font-size:.6rem; white-space:nowrap; }

@media print {
    .no-print { display:none !important; }
    body, .card { background:#fff !important; color:#000 !important; }
    .ledger-table { font-size:7pt !important; }
    .ledger-table th, .ledger-table td { padding:2px 3px !important; border-color:#ccc !important; }
    .ledger-table th { background:#1a3a5c !important; color:#fff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .totals-row td { background:#1a3a5c !important; color:#fff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .debt-owed   { color:#c00 !important; }
    .debt-credit { color:#060 !important; }
    .col-paid    { color:#060 !important; }
    .col-fine    { color:#850 !important; }
    .print-hdr   { display:block !important; }
}
.print-hdr { display:none; text-align:center; margin-bottom:1rem; }
</style>

<!-- Print header -->
<div class="print-hdr">
    <h2 style="font-size:14pt;font-weight:800;margin:0"><?= htmlspecialchars($groupName) ?></h2>
    <h3 style="font-size:11pt;margin:.25rem 0"><?= strtoupper($mo['label']) ?> — CONTRIBUTIONS LEDGER</h3>
    <p style="font-size:8pt;color:#666;margin:0">Monthly Contribution: <?= $curr ?> <?= number_format($monthlyAmt) ?> &nbsp;|&nbsp; Generated: <?= date('d M Y') ?></p>
    <hr style="margin:.4rem 0">
</div>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3 no-print">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-journal-richtext me-2" style="color:var(--green)"></i><?= t('nav_ledger_report') ?></h4>
        <div class="text-muted small"><?= htmlspecialchars($groupName) ?> &mdash; <?= $mo['label'] ?></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?year=<?=$year?>&month=<?=$selMonth?>&download=1" class="btn btn-sm btn-outline-success">
            <i class="bi bi-file-earmark-excel me-1"></i>Download CSV
        </a>
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer me-1"></i>Print
        </button>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4 no-print">
    <div class="card-body p-3">
        <form method="GET" class="d-flex gap-3 flex-wrap align-items-end">
            <div>
                <label class="form-label mb-1 small fw-semibold">Year</label>
                <select name="year" onchange="this.form.submit()" class="form-select form-select-sm" style="width:100px">
                    <?php for($y=date('Y');$y>=date('Y')-5;$y--): ?>
                    <option value="<?=$y?>" <?=$y===$year?'selected':''?>><?=$y?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div>
                <label class="form-label mb-1 small fw-semibold">Month</label>
                <select name="month" onchange="this.form.submit()" class="form-select form-select-sm" style="width:150px">
                    <?php foreach($months as $m2): ?>
                    <option value="<?=$m2['num']?>" <?=$m2['num']===$selMonth?'selected':''?>><?=$m2['label']?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4 no-print">
    <div class="col-6 col-md-3">
        <div class="stat-card green">
            <div class="stat-icon green"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-label">Total Collected</div>
            <div class="stat-value sm"><?=$curr?> <?=number_format($totPaid)?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="bi bi-percent"></i></div>
            <div class="stat-label">Collection Rate</div>
            <div class="stat-value sm"><?=$collRate?>%</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card red">
            <div class="stat-icon red"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="stat-label">Total Fines</div>
            <div class="stat-value sm"><?=$curr?> <?=number_format($totLate+$totOther)?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <?php $defaulters=count(array_filter($tableData,fn($r)=>!$r['pre_join']&&$r['paid']==0&&$r['expected']>0)); ?>
        <div class="stat-card amber">
            <div class="stat-icon amber"><i class="bi bi-person-x"></i></div>
            <div class="stat-label">Did Not Pay</div>
            <div class="stat-value sm"><?=$defaulters?> / <?=count($members)?></div>
        </div>
    </div>
</div>

<!-- THE TABLE -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="bi bi-table me-2"></i><?=strtoupper($mo['label'])?> &mdash; CONTRIBUTIONS LEDGER</h6>
        <span class="badge bg-primary no-print"><?=count($members)?> members</span>
    </div>
    <div class="card-body p-0">
        <div class="ledger-wrap">
            <table class="ledger-table">
                <thead>
                    <tr>
                        <th class="col-no">NO.</th>
                        <th style="text-align:left">NAME</th>
                        <th style="text-align:left">NICKNAME</th>
                        <th style="text-align:left">PHONE<br>NUMBER</th>
                        <th>POSITION</th>
                        <th class="col-num">MONTHLY CONTR<br>DEBT B/BF</th>
                        <?php if($hasEventCol): ?>
                        <th class="col-num">MONTHS<br>EVENT</th>
                        <?php endif; ?>
                        <th class="col-num"><?=$mo['short'].' '.$year?><br>EXPECT CONTR.</th>
                        <th class="col-num"><?=$mo['short'].' '.$year?><br>PAYMENT</th>
                        <th class="col-num"><?=$mo['short'].' '.$year?><br>LATE CONTR,FINES</th>
                        <?php if($hasOtherFine): ?>
                        <th class="col-num">EVENT/<br>OTHER FINES</th>
                        <?php endif; ?>
                        <th class="col-num" style="background:#0f2d4a">DEBT BAL.<br>C/FWD</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($tableData as $r): ?>
                <tr<?=$r['pre_join']?' style="opacity:.3"':''?>>
                    <td class="col-no"><?=$r['no']?></td>
                    <td class="col-name"><?=htmlspecialchars($r['name'])?></td>
                    <td class="col-nick"><?=htmlspecialchars($r['nickname'])?></td>
                    <td class="col-phone"><?=htmlspecialchars($r['phone'])?></td>
                    <td class="col-pos"><?=$r['position']?'<span class="pos-b">'.htmlspecialchars($r['position']).'</span>':''?></td>

                    <?php if($r['pre_join']): ?>
                        <td class="col-num debt-zero">—</td>
                        <?php if($hasEventCol): ?><td></td><?php endif; ?>
                        <td class="col-num debt-zero">—</td>
                        <td></td><td></td>
                        <?php if($hasOtherFine): ?><td></td><?php endif; ?>
                        <td class="col-num cf-col debt-zero">—</td>
                    <?php else:
                        $bfCls = $r['debt_bf']>0?'debt-owed':($r['debt_bf']<0?'debt-credit':'debt-zero');
                        $cfCls = $r['debt_cf']>0?'debt-owed':($r['debt_cf']<0?'debt-credit':'debt-zero');
                    ?>
                        <!-- DEBT B/F: blank when 0, number when nonzero -->
                        <td class="col-num <?=$bfCls?>"><?=$r['debt_bf']!=0?n($r['debt_bf']):''?></td>

                        <?php if($hasEventCol): ?>
                        <td class="col-num col-event"><?=$r['event_owed']>0?n($r['event_owed']):''?></td>
                        <?php endif; ?>

                        <!-- EXPECTED -->
                        <td class="col-num"><?=n($r['expected'])?></td>

                        <!-- PAYMENT: blank when 0 -->
                        <td class="col-num col-paid"><?=$r['paid']>0?n($r['paid']):''?></td>

                        <!-- LATE FINE: blank when 0 -->
                        <td class="col-num col-fine"><?=$r['late']>0?n($r['late']):''?></td>

                        <?php if($hasOtherFine): ?>
                        <td class="col-num col-fine"><?=$r['other_fine']>0?n($r['other_fine']):''?></td>
                        <?php endif; ?>

                        <!-- DEBT C/FWD: always shows, negative = credit -->
                        <td class="col-num cf-col <?=$cfCls?>"><?=n($r['debt_cf'])?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>

                <!-- TOTALS ROW — exactly like Excel -->
                <tr class="totals-row">
                    <td style="text-align:center;color:#888;font-size:.7rem"> </td>
                    <td colspan="4" style="text-align:right;color:#888;font-size:.7rem;letter-spacing:.05em">TOTALS</td>
                    <td class="col-num <?=$totBf>0?'debt-owed':($totBf<0?'debt-credit':'') ?>"><?=n($totBf)?></td>
                    <?php if($hasEventCol): ?>
                    <td class="col-num col-event"><?=$totEv>0?n($totEv):''?></td>
                    <?php endif; ?>
                    <td class="col-num"><?=n($totExp)?></td>
                    <td class="col-num col-paid"><?=n($totPaid)?></td>
                    <td class="col-num col-fine"><?=n($totLate)?></td>
                    <?php if($hasOtherFine): ?>
                    <td class="col-num col-fine"><?=n($totOther)?></td>
                    <?php endif; ?>
                    <td class="col-num cf-col <?=$totCf>0?'debt-owed':($totCf<0?'debt-credit':'')?>"><?=n($totCf)?></td>
                </tr>
            </table>
        </div>
    </div>
</div>

<!-- Summary info -->
<div class="card mt-3 no-print">
    <div class="card-body py-2 px-3 small text-muted d-flex flex-wrap gap-4">
        <span>Collection rate: <strong class="<?=$collRate>=80?'text-success':($collRate>=50?'text-warning':'text-danger')?>"><?=$collRate?>%</strong></span>
        <span>Members paid: <strong><?=count(array_filter($tableData,fn($r)=>!$r['pre_join']&&$r['paid']>0))?></strong></span>
        <span>Did not pay: <strong class="text-danger"><?=$defaulters?></strong></span>
        <span>Monthly contribution: <strong><?=$curr?> <?=number_format($monthlyAmt)?></strong></span>
    </div>
</div>

<!-- Month navigation -->
<div class="d-flex gap-2 flex-wrap mt-3 no-print">
    <span class="small text-muted align-self-center">Jump to:</span>
    <?php foreach($months as $m2): ?>
    <a href="?year=<?=$year?>&month=<?=$m2['num']?>"
       class="btn btn-sm <?=$m2['num']===$selMonth?'btn-primary':'btn-outline-secondary'?>">
        <?=$m2['short']?>
    </a>
    <?php endforeach; ?>
</div>

<?php require_once ROOT . '/includes/footer.php'; ?>
