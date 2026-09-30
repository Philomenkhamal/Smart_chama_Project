<?php
/**
 * SmartChama — Printable Ledger Form
 * Exact replica of the original handwritten chama form
 * All data pulled live from the system (contributions, events, fines, wallet)
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
requireAdmin();

$pdo        = getDB();
$curr       = getSetting('currency', 'KES');
$chamaName  = getSetting('group_name', 'CHAMA');
$monthlyAmt = (float)getSetting('monthly_contribution', 0);

// ── Filters ─────────────────────────────────────────────────────────────────
$year       = (int)($_GET['year']  ?? date('Y'));
$month      = (int)($_GET['month'] ?? (int)date('m'));
$monthDate  = sprintf('%04d-%02d-01', $year, $month);
$monthYm    = date('Y-m', strtotime($monthDate));
$monthLabel = date('F Y', strtotime($monthDate));

// ── All active members ───────────────────────────────────────────────────────
$members = $pdo->query("
    SELECT u.id, u.full_name, u.nickname, u.phone,
           u.chama_position, u.membership_number,
           u.joined_date, u.created_at
    FROM users u
    WHERE u.role='member' AND u.status='active'
    ORDER BY CAST(SUBSTRING_INDEX(CONCAT(u.membership_number,'0'),'-',-1) AS UNSIGNED) ASC,
             u.membership_number ASC, u.full_name ASC
")->fetchAll();

// ── Build rows ───────────────────────────────────────────────────────────────
$rows = [];
$T = ['debt_bf'=>0,'expected'=>0,'contributed'=>0,'events'=>0,'absent'=>0,'late'=>0,'agm'=>0,'other_fines'=>0,'balance'=>0];

foreach ($members as $idx => $m) {
    $uid = (int)$m['id'];

    // Join date — auto-computed: later of member join or chama start
    $joinedYm = date('Y-m', strtotime(getMemberStartDate($m)));
    $preJoin  = ($joinedYm > $monthYm);

    if ($preJoin) {
        $rows[] = array_merge(['no'=>$m['membership_number']??($idx+1),
            'name'=>$m['full_name'],'profile_photo'=>$m['profile_photo']??null,'nick'=>$m['nickname']??'',
            'phone'=>preg_replace('/^254/','0',$m['phone']??''),
            'position'=>$m['chama_position']??''], 
            array_fill_keys(['debt_bf','expected','contributed','events','absent','late','agm','other_fines','balance'],null),
            ['pre_join'=>true]);
        continue;
    }

    // ── 1. DEBT B/FORWARD ────────────────────────────────────────────────────
    // Sum all credits minus debits in wallet BEFORE start of this month
    $stDebt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE -amount END),0)
        FROM wallet_ledger WHERE user_id=? AND created_at < ?
    ");
    $stDebt->execute([$uid, $monthDate]);
    $debtBf = (float)$stDebt->fetchColumn();

    // ── 2. EXPECTED CONTRIBUTION ─────────────────────────────────────────────
    $expected = $monthlyAmt;

    // ── 3. MONTHLY CONTRIBUTION PAID ────────────────────────────────────────
    $stContr = $pdo->prepare("
        SELECT COALESCE(SUM(amount),0) FROM contributions
        WHERE user_id=? AND status='confirmed'
        AND DATE_FORMAT(payment_month,'%Y-%m')=?
    ");
    $stContr->execute([$uid, $monthYm]);
    $contributed = (float)$stContr->fetchColumn();

    // ── 4. EVENT CONTRIBUTIONS (Patrons/Daughter/Special) ───────────────────
    // All confirmed event payments whose event falls in this month
    $stEv = $pdo->prepare("
        SELECT COALESCE(SUM(ec.amount),0)
        FROM event_contributions ec
        JOIN events e ON ec.event_id = e.id
        WHERE ec.user_id=? AND ec.status='confirmed'
        AND DATE_FORMAT(e.event_date,'%Y-%m')=?
    ");
    $stEv->execute([$uid, $monthYm]);
    $events = (float)$stEv->fetchColumn();

    // ── 5. FINES ─────────────────────────────────────────────────────────────
    $stFines = $pdo->prepare("
        SELECT fine_type, COALESCE(SUM(amount),0) AS total
        FROM member_fines
        WHERE user_id=? AND DATE_FORMAT(month,'%Y-%m')=?
        AND status != 'waived'
        GROUP BY fine_type
    ");
    $stFines->execute([$uid, $monthYm]);
    $fines      = $stFines->fetchAll(PDO::FETCH_KEY_PAIR);
    $absentFine = (float)($fines['absentee'] ?? 0);
    $lateFine   = (float)($fines['late_contribution'] ?? 0);
    $agmFine    = (float)($fines['agm'] ?? 0);
    $otherFines = 0;
    foreach ($fines as $ft => $fa) {
        if (!in_array($ft, ['absentee','late_contribution','agm'])) {
            $otherFines += (float)$fa;
        }
    }
    $totalFines = $absentFine + $lateFine + $agmFine + $otherFines;

    // ── 6. BALANCE C/FORWARD ─────────────────────────────────────────────────
    // Formula matches original form:
    // Bal C/Fwd = Debt B/F + Contributed + Events - Expected - Fines
    // Positive = savings ahead | Negative = owes chama
    $balance = $debtBf + $contributed + $events - $expected - $totalFines;

    // ── Totals ───────────────────────────────────────────────────────────────
    $T['debt_bf']     += $debtBf;
    $T['expected']    += $expected;
    $T['contributed'] += $contributed;
    $T['events']      += $events;
    $T['absent']      += $absentFine;
    $T['late']        += $lateFine;
    $T['agm']         += $agmFine;
    $T['other_fines'] += $otherFines;
    $T['balance']     += $balance;

    $rows[] = [
        'no'          => $m['membership_number'] ?: ($idx+1),
        'name'        => $m['full_name'],
        'profile_photo' => $m['profile_photo'] ?? null,
        'nick'        => $m['nickname'] ?? '',
        'phone'       => preg_replace('/^254/', '0', $m['phone'] ?? ''),
        'position'    => $m['chama_position'] ?? '',
        'debt_bf'     => $debtBf,
        'expected'    => $expected,
        'contributed' => $contributed,
        'events'      => $events,
        'absent'      => $absentFine,
        'late'        => $lateFine,
        'agm'         => $agmFine,
        'other_fines' => $otherFines,
        'balance'     => $balance,
        'pre_join'    => false,
    ];
}

// ── Summary stats ────────────────────────────────────────────────────────────
$activeRows = array_filter($rows, fn($r)=>!$r['pre_join']);
$paidCount  = count(array_filter($activeRows, fn($r)=>($r['contributed']??0)>0));
$unpaidCount= count(array_filter($activeRows, fn($r)=>($r['contributed']??0)==0));
$collRate   = $T['expected']>0 ? round(($T['contributed']/$T['expected'])*100) : 0;

// ── Helpers ──────────────────────────────────────────────────────────────────
function fmtN($v, $dash=true): string {
    if ($v === null) return '<span class="na">n/a</span>';
    $v = (float)$v;
    if ($v == 0 && $dash) return '<span class="zero">—</span>';
    if ($v < 0) return '<span class="neg">('.number_format(abs($v),0).')</span>';
    return number_format($v, 0);
}
function fmtBal($v): string {
    if ($v === null) return '<span class="na">n/a</span>';
    $v = (float)$v;
    if ($v == 0) return '<span class="zero">—</span>';
    if ($v < 0) return '<span class="bal-neg">('.number_format(abs($v),0).')</span>';
    return '<span class="bal-pos">'.number_format($v,0).'</span>';
}
function totFmt($v): string {
    if ((float)$v == 0) return '—';
    if ((float)$v < 0) return '('.number_format(abs((float)$v),0).')';
    return number_format((float)$v, 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($chamaName) ?> — Ledger Report <?= $monthLabel ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: Arial, sans-serif; font-size:8.5pt; background:#f4f7fb; color:#111; }

/* ── Controls (hidden on print) ─────────────────────────────── */
.controls {
    background:#1a3a5c; color:#fff; padding:10px 16px;
    display:flex; gap:10px; align-items:center; flex-wrap:wrap;
    position:sticky; top:0; z-index:99;
}
.controls select, .controls button {
    padding:5px 10px; border-radius:4px; border:none; font-size:9pt; cursor:pointer;
}
.controls select { background:#fff; color:#111; }
.btn-view  { background:#3b82f6; color:#fff; font-weight:700; }
.btn-print { background:#00c471; color:#060e1a; font-weight:700; }
.btn-back  { background:transparent; color:#aaa; border:1px solid #aaa !important; font-size:8pt; }
.controls label { font-size:8.5pt; display:flex; align-items:center; gap:5px; }

/* ── Report wrapper ─────────────────────────────────────────── */
.report { padding:10px 12px; max-width:100%; }

/* ── Title block ────────────────────────────────────────────── */
.report-title {
    text-align:center; margin-bottom:8px;
    border-bottom:3px solid #1a3a5c; padding-bottom:6px;
}
.report-title h1 { font-size:14pt; color:#1a3a5c; text-transform:uppercase; letter-spacing:.5px; }
.report-title h2 { font-size:10pt; color:#2d6a9f; margin-top:3px; }
.report-title p  { font-size:8pt; color:#777; margin-top:2px; }

/* ── Summary cards (screen only) ───────────────────────────── */
.summary { display:flex; gap:10px; margin:8px 0 10px; flex-wrap:wrap; }
.sc {
    flex:1; min-width:110px; border:1px solid #c5d8e8; border-radius:8px;
    padding:8px 10px; text-align:center; background:#fff;
    box-shadow:0 1px 4px rgba(0,0,0,.06);
}
.sc .val { font-size:15pt; font-weight:800; color:#1a3a5c; line-height:1.1; }
.sc .lbl { font-size:7pt; color:#777; margin-top:3px; text-transform:uppercase; letter-spacing:.3px; }
.sc.green .val { color:#005500; }
.sc.red   .val { color:#cc2200; }
.sc.amber .val { color:#b45309; }

/* ── Main table ─────────────────────────────────────────────── */
.tbl-wrap { overflow-x:auto; background:#fff; border-radius:8px; box-shadow:0 1px 6px rgba(0,0,0,.08); }
table { width:100%; border-collapse:collapse; font-size:7.8pt; }

/* Header rows */
thead tr.hdr1 { background:#1a3a5c; color:#fff; }
thead tr.hdr2 { background:#2d5f8a; color:#fff; }
thead th {
    padding:5px 3px; text-align:center; font-size:7.3pt;
    border:1px solid #2a4f7a; line-height:1.25; white-space:nowrap;
}
thead th.tl { text-align:left; padding-left:5px; }
thead th.group { font-size:7pt; letter-spacing:.3px; border-bottom:none; }

/* Body rows */
tbody tr { transition:background .1s; }
tbody tr:nth-child(even)  { background:#edf4fb; }
tbody tr:nth-child(odd)   { background:#ffffff; }
tbody tr:hover            { background:#cfe5f7 !important; }
tbody tr.pre-join         { opacity:.45; background:#f9f9f9 !important; }

tbody td {
    padding:3.5px 4px; border:1px solid #c5d8e8;
    vertical-align:middle; text-align:right;
}
tbody td.tl   { text-align:left; }
tbody td.ctr  { text-align:center; }
tbody td.name { font-weight:600; text-align:left; white-space:nowrap; padding-left:5px; }
tbody td.nick { text-align:left; color:#444; font-style:italic; }
tbody td.phone{ font-family:monospace; font-size:7pt; text-align:center; }
tbody td.pos  { font-size:6.5pt; color:#1a5276; text-align:center; }
tbody td.bal  { font-weight:700; background:rgba(0,80,180,.05); }

/* Totals row */
tfoot tr { background:#d0e8f5; }
tfoot td {
    padding:5px 4px; border:1.5px solid #1a3a5c;
    font-weight:800; text-align:right; font-size:8pt; color:#1a3a5c;
}
tfoot td.tl { text-align:left; padding-left:5px; }
tfoot td.bal { background:rgba(0,80,180,.08); }

/* Value colours */
.neg     { color:#cc2200; }
.bal-neg { color:#cc2200; font-weight:800; }
.bal-pos { color:#005500; font-weight:800; }
.zero    { color:#bbb; }
.na      { color:#ccc; font-size:7pt; }

/* Contribution paid = bold green */
.paid { color:#005500; font-weight:700; }

/* ── Footer note ─────────────────────────────────────────────── */
.footnote {
    font-size:7.5pt; color:#666; margin-top:10px;
    border-top:1px solid #ddd; padding-top:6px; line-height:1.6;
}

/* ── PRINT ───────────────────────────────────────────────────── */
@media print {
    @page { size:A4 landscape; margin:7mm; }
    body   { font-size:7pt; background:#fff; }
    .controls, .summary, .no-print { display:none !important; }
    .report { padding:0; }
    .tbl-wrap { box-shadow:none; border-radius:0; overflow:visible; }
    table { font-size:6.8pt; }
    thead tr.hdr1 { background:#1a3a5c !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    thead tr.hdr2 { background:#2d5f8a !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    tbody tr:nth-child(even) { background:#edf4fb !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    tfoot tr { background:#d0e8f5 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    tbody td.bal, tfoot td.bal { background:rgba(0,80,180,.05) !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .neg,.bal-neg { color:#cc2200 !important; }
    .bal-pos { color:#005500 !important; }
    .paid { color:#005500 !important; }
}
</style>
</head>
<body>

<!-- ── Controls ───────────────────────────────────────────────────────────── -->
<div class="controls no-print">
    <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <label>Year:
            <select name="year">
                <?php for($y=date('Y');$y>=2020;$y--): ?>
                <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
                <?php endfor; ?>
            </select>
        </label>
        <label>Month:
            <select name="month">
                <?php for($mo=1;$mo<=12;$mo++): ?>
                <option value="<?=$mo?>" <?=$mo==$month?'selected':''?>><?=date('F',mktime(0,0,0,$mo,1))?></option>
                <?php endfor; ?>
            </select>
        </label>
        <button type="submit" class="btn-view">&#128269; View</button>
    </form>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
        <button class="btn-print" onclick="window.print()">&#128424; Print / PDF</button>
        <a href="<?= APP_URL ?>/admin/export.php?type=contributions&format=excel&year=<?= $year ?>&month=<?= $month ?>"
           style="background:#1d6f42;color:#fff;padding:.45rem 1rem;border-radius:6px;font-size:.8rem;font-weight:700;text-decoration:none;border:none">
            &#8595; Excel
        </a>
        <a href="<?= APP_URL ?>/admin/export.php?type=contributions&format=pdf&year=<?= $year ?>&month=<?= $month ?>"
           target="_blank"
           style="background:#c0392b;color:#fff;padding:.45rem 1rem;border-radius:6px;font-size:.8rem;font-weight:700;text-decoration:none;border:none">
            &#128196; PDF
        </a>
        <a href="<?= APP_URL ?>/admin/reports.php"><button class="btn-back">&#8592; Back</button></a>
    </div>
</div>

<div class="report">

<!-- ── Title ──────────────────────────────────────────────────────────────── -->
<div class="report-title">
    <h1><?= htmlspecialchars($chamaName) ?></h1>
    <h2><?= strtoupper($monthLabel) ?> MONTHLY CONTRIBUTION &amp; LEDGER REPORT</h2>
    <p>
        <?= count($rows) ?> members &nbsp;·&nbsp;
        Generated <?= date('d M Y, H:i') ?> &nbsp;·&nbsp;
        Collection rate: <strong><?= $collRate ?>%</strong>
    </p>
</div>

<!-- ── Summary cards (screen only) ──────────────────────────────────────── -->
<div class="summary no-print">
    <div class="sc green">
        <div class="val"><?= $paidCount ?></div>
        <div class="lbl">Paid This Month</div>
    </div>
    <div class="sc red">
        <div class="val"><?= $unpaidCount ?></div>
        <div class="lbl">Not Paid</div>
    </div>
    <div class="sc green">
        <div class="val"><?= number_format($T['contributed'],0) ?></div>
        <div class="lbl"><?=$curr?> Collected</div>
    </div>
    <div class="sc">
        <div class="val"><?= number_format($T['expected'],0) ?></div>
        <div class="lbl"><?=$curr?> Expected</div>
    </div>
    <div class="sc amber">
        <div class="val"><?= number_format($T['events'],0) ?></div>
        <div class="lbl"><?=$curr?> Events</div>
    </div>
    <div class="sc red">
        <div class="val"><?= number_format($T['absent']+$T['late']+$T['agm']+$T['other_fines'],0) ?></div>
        <div class="lbl"><?=$curr?> Fines</div>
    </div>
    <div class="sc <?= $T['balance']<0?'red':($T['balance']>0?'green':'') ?>">
        <div class="val <?= $T['balance']<0?'neg':'' ?>"><?= totFmt($T['balance']) ?></div>
        <div class="lbl">Net Balance C/Fwd</div>
    </div>
    <div class="sc amber">
        <div class="val"><?= $collRate ?>%</div>
        <div class="lbl">Collection Rate</div>
    </div>
</div>

<!-- ── Table ──────────────────────────────────────────────────────────────── -->
<div class="tbl-wrap">
<table>
    <thead>
        <!-- Group headers -->
        <tr class="hdr1">
            <th rowspan="2" style="width:22px">NO.</th>
            <th rowspan="2" class="tl" style="min-width:95px">FULL NAME</th>
            <th rowspan="2" class="tl" style="min-width:55px">NICKNAME</th>
            <th rowspan="2" style="width:65px">PHONE</th>
            <th rowspan="2" style="width:58px">POSITION</th>
            <th rowspan="2" style="width:52px">DEBT<br>B/FWD</th>
            <th rowspan="2" style="width:46px">EXPECTED<br>CONTR.</th>
            <th colspan="2" class="group" style="border-bottom:1px solid #4a8fc0">INCOME THIS MONTH</th>
            <th colspan="4" class="group" style="border-bottom:1px solid #c0504d">DEDUCTIONS / FINES</th>
            <th rowspan="2" class="bal" style="width:58px">DEBT BAL.<br>C/FWD</th>
        </tr>
        <tr class="hdr2">
            <th style="width:55px"><?= strtoupper(date('M Y',strtotime($monthDate))) ?><br>CONTRIBUTION</th>
            <th style="width:48px">EVENTS &amp;<br>SPECIALS</th>
            <th style="width:42px">ABSENTEE<br>FINE</th>
            <th style="width:42px">LATE<br>FINE</th>
            <th style="width:42px">AGM<br>FINE</th>
            <th style="width:42px">OTHER<br>FINES</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
    <tr class="<?= $r['pre_join']?'pre-join':'' ?>">
        <td class="ctr"><?= htmlspecialchars((string)$r['no']) ?></td>
        <td class="name">
            <div style="display:flex;align-items:center;gap:6px">
                <?= memberAvatar(['full_name'=>$r['name'],'profile_photo'=>$r['profile_photo']??null], 30) ?>
                <span><?= htmlspecialchars($r['name']) ?></span>
            </div>
        </td>
        <td class="nick"><?= htmlspecialchars($r['nick']) ?></td>
        <td class="phone"><?= htmlspecialchars($r['phone']) ?></td>
        <td class="pos"><?= htmlspecialchars($r['position']) ?></td>
        <td><?= fmtN($r['debt_bf'], false) ?></td>
        <td><?= fmtN($r['expected']) ?></td>
        <td class="<?= ($r['contributed']??0)>0?'paid':'' ?>"><?= fmtN($r['contributed']) ?></td>
        <td><?= fmtN($r['events']) ?></td>
        <td><?= fmtN($r['absent']) ?></td>
        <td><?= fmtN($r['late']) ?></td>
        <td><?= fmtN($r['agm']) ?></td>
        <td><?= fmtN($r['other_fines']) ?></td>
        <td class="bal"><?= fmtBal($r['balance']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5" class="tl">TOTALS &nbsp;(<?= count($activeRows) ?> members)</td>
            <td><?= totFmt($T['debt_bf']) ?></td>
            <td><?= totFmt($T['expected']) ?></td>
            <td><?= totFmt($T['contributed']) ?></td>
            <td><?= totFmt($T['events']) ?></td>
            <td><?= totFmt($T['absent']) ?></td>
            <td><?= totFmt($T['late']) ?></td>
            <td><?= totFmt($T['agm']) ?></td>
            <td><?= totFmt($T['other_fines']) ?></td>
            <td class="bal <?= $T['balance']<0?'neg':'' ?>"><?= totFmt($T['balance']) ?></td>
        </tr>
    </tfoot>
</table>
</div>

<!-- ── Footnote ───────────────────────────────────────────────────────────── -->
<div class="footnote">
    <strong>Key:</strong>
    Balances in <span class="bal-neg">(brackets)</span> = member owes the chama. &nbsp;|&nbsp;
    <span class="bal-pos">Positive</span> = chama holds savings on member's behalf. &nbsp;|&nbsp;
    <span class="zero">—</span> = zero. &nbsp;|&nbsp;
    <span class="na">n/a</span> = member had not yet joined in this period. &nbsp;|&nbsp;
    <strong>Events &amp; Specials</strong> = all event/project contributions paid in this month. &nbsp;|&nbsp;
    Generated by SmartChama &nbsp;·&nbsp; <?= date('d M Y H:i') ?>
</div>

</div><!-- /report -->
</body>
</html>
