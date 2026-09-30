<?php
/**
 * SmartChama — Member Savings Statement
 * Printable / Save-as-PDF HTML page
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
requireLogin();
startSession();

$pdo      = getDB();
$userId   = (int)$_SESSION['user_id'];
$role     = $_SESSION['user_role'] ?? 'member';
$curr     = getSetting('currency', 'KES');
$groupName= getSetting('group_name', 'SmartChama');

// Admin can view any member's statement
$targetId = $userId;
if ($role === 'admin' && isset($_GET['member_id'])) {
    $targetId = (int)$_GET['member_id'];
}

$year  = (int)($_GET['year'] ?? date('Y'));

// Fetch member info
$member = $pdo->prepare("SELECT * FROM users WHERE id=?");
$member->execute([$targetId]); $member = $member->fetch();
if (!$member) { header('Location: ' . APP_URL . '/member/dashboard.php'); exit; }

// Fetch all confirmed contributions for the year
$contributions = $pdo->prepare("
    SELECT * FROM contributions
    WHERE user_id=? AND status='confirmed' AND YEAR(payment_month)=?
    ORDER BY payment_month ASC
");
$contributions->execute([$targetId, $year]); $contributions = $contributions->fetchAll();

// Fetch loans
$loans = $pdo->prepare("
    SELECT l.*, COALESCE(SUM(lp.amount),0) AS paid_amount
    FROM loans l
    LEFT JOIN loan_payments lp ON lp.loan_id=l.id AND lp.status='confirmed'
    WHERE l.user_id=? AND YEAR(l.applied_at)=?
    GROUP BY l.id ORDER BY l.applied_at DESC
");
$loans->execute([$targetId, $year]); $loans = $loans->fetchAll();

$totalContribs = array_sum(array_column($contributions, 'amount'));
$totalLoans    = array_sum(array_column($loans, 'amount_approved'));
$totalRepaid   = array_sum(array_column($loans, 'paid_amount'));
$outstanding   = array_sum(array_column(
    array_filter($loans, fn($l) => in_array($l['status'],['approved','disbursed'])),
    'balance'
));

// Fetch event contributions for the year
$eventContribs = $pdo->prepare("
    SELECT ec.*, e.title AS event_title, e.event_date
    FROM event_contributions ec
    JOIN events e ON e.id = ec.event_id
    WHERE ec.user_id=? AND ec.status='confirmed' AND YEAR(e.event_date)=?
    ORDER BY e.event_date ASC
");
$eventContribs->execute([$targetId, $year]); $eventContribs = $eventContribs->fetchAll();
$totalEvents = array_sum(array_column($eventContribs, 'amount'));
$grandTotal  = $totalContribs + $totalEvents;

// All 12 months for calendar view — only from join date onward
// Safe join date: handle NULL, empty string, AND '0000-00-00' (all treated as invalid)
$joinTs = strtotime(getMemberStartDate($member)); // Auto: later of join date or chama start // absolute fallback: this month
$joinedDate = date('Y-m-d', $joinTs);
$joinedYm   = date('Y-m', $joinTs);
$months = [];
for ($m = 1; $m <= 12; $m++) {
    $key = $year . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
    // Skip months before the member joined
    if ($key < $joinedYm) {
        $months[$key] = 'pre_join'; // mark as pre-join, not missed
    } else {
        $months[$key] = null;
    }
}
foreach ($contributions as $c) {
    $key = substr($c['payment_month'], 0, 7);
    if (array_key_exists($key, $months)) $months[$key] = $c;
}

$statementDate = date('d F Y');
$refNo         = 'STMT-' . strtoupper(substr(md5($targetId . $year), 0, 8));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= t('stmt_savings') ?> <?= $year ?> — <?= htmlspecialchars($member['full_name']) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Syne:wght@700;800&display=swap');
@page { margin: 1.5cm; size: A4; }
@media print {
    .no-print { display:none !important; }
    body { background: #fff !important; }
    .page-break { page-break-before: always; }
}
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'DM Sans',Arial,sans-serif; background:#f0f4f8; color:#1a2940; font-size:13px; }
.page { background:#fff; max-width:800px; margin:0 auto; padding:2.5rem; }

/* Header */
.stmt-header { display:flex; justify-content:space-between; align-items:flex-start; padding-bottom:1.5rem; border-bottom:3px solid #00c471; margin-bottom:1.75rem; }
.stmt-brand { display:flex; align-items:center; gap:.75rem; }
.stmt-brand-icon { width:44px;height:44px;background:linear-gradient(135deg,#00c471,#009954);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.4rem; }
.stmt-brand-name { font-family:'Syne',sans-serif; font-size:1.4rem; font-weight:800; color:#060e1a; }
.stmt-brand-name span { color:#00c471; }
.stmt-meta { text-align:right; font-size:.78rem; color:#6b87a8; line-height:1.8; }
.stmt-meta strong { color:#1a2940; }

/* Member card */
.member-card { background:linear-gradient(135deg,#060e1a,#0a2240); border-radius:14px; padding:1.5rem; color:#fff; margin-bottom:1.75rem; display:flex; justify-content:space-between; align-items:center; }
.member-card .name { font-family:'Syne',sans-serif; font-size:1.2rem; font-weight:800; margin-bottom:.4rem; }
.member-card .detail { font-size:.8rem; color:rgba(255,255,255,.65); line-height:1.9; }
.member-card .year-badge { background:rgba(0,196,113,.2); border:1px solid rgba(0,196,113,.4); color:#00c471; border-radius:8px; padding:.5rem 1rem; font-family:'Syne',sans-serif; font-size:1.1rem; font-weight:800; text-align:center; }

/* KPI row */
.kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:1.75rem; }
.kpi { background:#f8fafc; border-radius:12px; padding:1rem; border-left:3px solid #e2e8f0; }
.kpi.green  { border-left-color:#00c471; }
.kpi.blue   { border-left-color:#3b82f6; }
.kpi.amber  { border-left-color:#f59e0b; }
.kpi.red    { border-left-color:#ef4444; }
.kpi-label  { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#6b87a8; margin-bottom:.3rem; }
.kpi-value  { font-family:'Syne',sans-serif; font-size:1.1rem; font-weight:800; color:#1a2940; }

/* Section title */
.section-title { font-family:'Syne',sans-serif; font-size:.85rem; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#6b87a8; margin-bottom:.85rem; margin-top:1.75rem; padding-bottom:.4rem; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; gap:.5rem; }
.section-title::before { content:''; display:inline-block; width:4px; height:14px; background:#00c471; border-radius:99px; }

/* Contribution calendar grid */
.month-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:.65rem; margin-bottom:1rem; }
.month-cell { border-radius:10px; padding:.65rem; text-align:center; border:1px solid #e2e8f0; }
.month-cell .m-name { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#6b87a8; margin-bottom:.25rem; }
.month-cell .m-amount { font-size:.82rem; font-weight:700; color:#1a2940; }
.month-cell .m-status { font-size:.65rem; margin-top:.2rem; }
.month-cell.paid   { background:#f0fdf4; border-color:#bbf7d0; }
.month-cell.paid .m-name { color:#059669; }
.month-cell.paid .m-amount { color:#047857; }
.month-cell.missed { background:#fff5f5; border-color:#fecaca; }
.month-cell.missed .m-name { color:#dc2626; }
.month-cell.future { background:#f8fafc; border-color:#e2e8f0; opacity:.6; }

/* Table */
table { width:100%; border-collapse:collapse; font-size:.82rem; }
thead tr { background:#060e1a; color:#fff; }
thead th { padding:.6rem .75rem; text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; font-weight:700; }
tbody tr:nth-child(even) { background:#f8fafc; }
tbody td { padding:.55rem .75rem; border-bottom:1px solid #f0f4f8; }
tbody tr:last-child td { border-bottom:none; }
.badge-sm { padding:.2rem .55rem; border-radius:99px; font-size:.68rem; font-weight:700; }
.badge-green  { background:#dcfce7; color:#15803d; }
.badge-blue   { background:#dbeafe; color:#1d4ed8; }
.badge-amber  { background:#fef9c3; color:#a16207; }
.badge-red    { background:#fee2e2; color:#b91c1c; }

/* Footer */
.stmt-footer { margin-top:2rem; padding-top:1rem; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; font-size:.72rem; color:#9ca3af; }
.watermark { position:fixed;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-35deg);font-size:5rem;font-weight:900;color:rgba(0,196,113,.04);pointer-events:none;z-index:0;font-family:'Syne',sans-serif;white-space:nowrap; }

/* Print button */
.print-bar { background:#060e1a; padding:1rem 2rem; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:100; }
.print-bar .info { color:rgba(255,255,255,.6); font-size:.82rem; }
.print-bar .info strong { color:#fff; }
.btn-print { background:#00c471; color:#060e1a; border:none; padding:.65rem 1.75rem; border-radius:8px; font-weight:800; font-size:.9rem; cursor:pointer; display:flex; align-items:center; gap:.5rem; transition:background .2s; }
.btn-print:hover { background:#00dc7e; }
.btn-back { color:rgba(255,255,255,.5); text-decoration:none; font-size:.85rem; display:flex; align-items:center; gap:.35rem; transition:color .2s; }
.btn-back:hover { color:#fff; }
</style>
</head>
<body>

<!-- Print bar -->
<div class="print-bar no-print">
    <a href="javascript:history.back()" class="btn-back"><?= t('stmt_back') ?></a>
    <div class="info"><?= t('stmt_for') ?> <strong><?= htmlspecialchars($member['full_name']) ?></strong> · <?= $year ?></div>
    <button class="btn-print" onclick="window.print()">
        &#128438; Print / Save PDF
    </button>
</div>

<div class="page">
<div class="watermark">SmartChama</div>

<!-- Header -->
<div class="stmt-header">
    <div class="stmt-brand">
        <div class="stmt-brand-icon">&#127968;</div>
        <div>
            <div class="stmt-brand-name">Smart<span>Chama</span></div>
            <div style="font-size:.75rem;color:#6b87a8"><?= htmlspecialchars($groupName) ?></div>
        </div>
    </div>
    <div class="stmt-meta">
        <div><strong><?= t('stmt_date') ?></strong> <?= $statementDate ?></div>
        <div><strong><?= t('stmt_reference') ?></strong> <?= $refNo ?></div>
        <div><strong><?= t('ms_period') ?></strong> January – December <?= $year ?></div>
        <div><strong><?= t('ms_currency') ?></strong> <?= $curr ?></div>
    </div>
</div>

<!-- Member card -->
<div class="member-card">
    <div style="display:flex;align-items:center;gap:14px">
        <?php
        $stPhoto = $member['profile_photo'] ?? null;
        $stPhotoPath = $stPhoto ? ROOT . '/uploads/avatars/' . $stPhoto : null;
        if ($stPhoto && file_exists($stPhotoPath)): ?>
        <img src="<?= APP_URL ?>/uploads/avatars/<?= htmlspecialchars($stPhoto) ?>"
             style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.4);flex-shrink:0" alt="">
        <?php else:
            $stInitials = implode('', array_map(fn($p)=>strtoupper($p[0]), array_slice(array_filter(explode(' ', trim($member['full_name']))),0,2)));
        ?>
        <div style="width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,.25);
                    display:flex;align-items:center;justify-content:center;
                    font-size:1.5rem;font-weight:700;flex-shrink:0;color:#fff">
            <?= htmlspecialchars($stInitials) ?>
        </div>
        <?php endif; ?>
        <div>
            <div class="name"><?= htmlspecialchars($member['full_name']) ?></div>
            <div class="detail">
                <span>&#128241; <?= htmlspecialchars($member['phone'] ?? 'N/A') ?></span> &nbsp;&nbsp;
                <span>&#9993; <?= htmlspecialchars($member['email']) ?></span><br>
                <span>&#128100; <?= htmlspecialchars($member['membership_number'] ?? 'N/A') ?></span> &nbsp;&nbsp;
                <span>&#128197; Member since <?= formatDate($member['created_at'], 'M Y') ?></span>
            </div>
        </div>
    </div>
    <div class="year-badge">
        <?= $year ?><br>
        <span style="font-size:.65rem;font-weight:400;opacity:.7"><?= t('ms_stmt_year') ?></span>
    </div>
</div>

<!-- KPIs -->
<div class="kpi-row">
    <div class="kpi green">
        <div class="kpi-label"><?= t('ms_total_saved') ?></div>
        <div class="kpi-value"><?= number_format($grandTotal ?? $totalContribs,2) ?></div>
        <div style="font-size:.7rem;color:#6b87a8;margin-top:.2rem"><?= $curr ?> · <?= count($contributions) ?> <?= t('ms_monthly') ?><?= !empty($eventContribs) ? ' + ' . count($eventContribs) . ' events' : '' ?></div>
    </div>
    <div class="kpi blue">
        <div class="kpi-label"><?= t('ms_loans_taken') ?></div>
        <div class="kpi-value"><?= number_format($totalLoans,2) ?></div>
        <div style="font-size:.7rem;color:#6b87a8;margin-top:.2rem"><?= $curr ?> · <?= count($loans) ?> loan<?= count($loans)!=1?'s':'' ?></div>
    </div>
    <div class="kpi amber">
        <div class="kpi-label"><?= t('stmt_loan_repaid') ?></div>
        <div class="kpi-value"><?= number_format($totalRepaid,2) ?></div>
        <div style="font-size:.7rem;color:#6b87a8;margin-top:.2rem"><?= $curr ?></div>
    </div>
    <div class="kpi <?= $outstanding > 0 ? 'red' : 'green' ?>">
        <div class="kpi-label"><?= t('stmt_outstanding') ?></div>
        <div class="kpi-value"><?= number_format($outstanding,2) ?></div>
        <div style="font-size:.7rem;color:#6b87a8;margin-top:.2rem"><?= $curr ?> <?= t('ms_balance') ?></div>
    </div>
</div>

<!-- Contribution calendar -->
<div class="section-title">Contribution Calendar <?= $year ?></div>
<div class="month-grid">
<?php
$monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$currentMonth = date('Y-m');
$i = 0;
foreach ($months as $key => $c):
    $isFuture  = $key > $currentMonth;
    $isPreJoin = ($c === 'pre_join');
    $isPaid    = !$isPreJoin && $c !== null;
    $cls  = $isPreJoin ? 'future' : ($isFuture ? 'future' : ($isPaid ? 'paid' : 'missed'));
    $icon = $isPreJoin ? '—' : ($isFuture ? '—' : ($isPaid ? '&#10003;' : '&#215;'));
    $amtText = $isPreJoin ? 'n/a' : ($isFuture ? '' : ($isPaid ? number_format($c['amount'],0) : 'Missed'));
?>
<div class="month-cell <?= $cls ?>">
    <div class="m-name"><?= $monthNames[$i++] ?></div>
    <div class="m-amount"><?= $isPreJoin ? '—' : ($isFuture ? '—' : ($isPaid ? $curr . ' ' . number_format($c['amount'],0) : 'Missed')) ?></div>
    <div class="m-status"><?= $icon ?></div>
</div>
<?php endforeach; ?>
</div>
<div style="font-size:.75rem;color:#6b87a8;margin-top:.5rem;display:flex;gap:1.5rem">
    <span style="color:#059669"><?= t('stmt_paid_marker') ?></span>
    <span style="color:#dc2626"><?= t('stmt_missed') ?></span>
    <span style="color:#9ca3af">&#9632; <?= t('ms_future') ?></span>
    <span style="margin-left:auto">Paid months: <?= count(array_filter($contributions)) ?>/<?= count(array_filter(array_keys($months), fn($k) => $k >= $joinedYm && $k <= $currentMonth)) ?></span>
</div>

<!-- Contributions table -->
<?php if (!empty($contributions)): ?>
<div class="section-title"><?= t('ms_contrib_details') ?></div>
<table>
    <thead><tr>
        <th>#</th><th><?= t('ms_month') ?></th><th><?= t('ms_amount') ?></th><th><?= t('ms_method') ?></th><th><?= t('ms_ref') ?></th><th>Date Paid</th>
    </tr></thead>
    <tbody>
    <?php foreach ($contributions as $i => $c): ?>
    <tr>
        <td style="color:#9ca3af"><?= $i+1 ?></td>
        <td><?= date('F Y', strtotime($c['payment_month'])) ?></td>
        <td><strong><?= $curr ?> <?= number_format($c['amount'],2) ?></strong></td>
        <td><?= ucfirst(str_replace('_',' ',$c['payment_method']??'')) ?></td>
        <td style="font-family:monospace;font-size:.78rem;color:#3b82f6"><?= htmlspecialchars($c['reference_code']??'—') ?></td>
        <td><?= formatDate($c['recorded_at'], 'd M Y') ?></td>
    </tr>
    <?php endforeach; ?>
    <tr style="background:#f0fdf4;font-weight:700">
        <td colspan="2" style="text-align:right;color:#059669">TOTAL</td>
        <td style="color:#059669"><?= $curr ?> <?= number_format($totalContribs,2) ?></td>
        <td colspan="3"></td>
    </tr>
    </tbody>
</table>
<?php endif; ?>

<!-- Event Contributions table -->
<?php if (!empty($eventContribs)): ?>
<div class="section-title" style="margin-top:1.75rem">Event Contributions</div>
<table>
    <thead><tr>
        <th>#</th><th>Event</th><th>Date</th><th><?= t('ms_amount') ?></th><th><?= t('ms_method') ?></th><th><?= t('ms_ref') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($eventContribs as $i => $ec): ?>
    <tr>
        <td style="color:#9ca3af"><?= $i+1 ?></td>
        <td><strong><?= htmlspecialchars($ec['event_title']) ?></strong></td>
        <td><?= date('d M Y', strtotime($ec['event_date'])) ?></td>
        <td><strong><?= $curr ?> <?= number_format($ec['amount'],2) ?></strong></td>
        <td><?= ucfirst(str_replace('_',' ',$ec['payment_method']??'')) ?></td>
        <td style="font-family:monospace;font-size:.78rem;color:#3b82f6"><?= htmlspecialchars($ec['reference_code']??'—') ?></td>
    </tr>
    <?php endforeach; ?>
    <tr style="background:#f0fdf4;font-weight:700">
        <td colspan="3" style="text-align:right;color:#059669">EVENT TOTAL</td>
        <td style="color:#059669"><?= $curr ?> <?= number_format($totalEvents,2) ?></td>
        <td colspan="2"></td>
    </tr>
    </tbody>
</table>
<?php endif; ?>

<!-- Grand total if has events -->
<?php if (!empty($eventContribs) && !empty($contributions)): ?>
<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:.75rem 1.25rem;margin:.75rem 0;display:flex;justify-content:space-between;font-weight:800;font-size:.9rem">
    <span style="color:#059669">GRAND TOTAL (Monthly + Events)</span>
    <span style="color:#059669"><?= $curr ?> <?= number_format($grandTotal, 2) ?></span>
</div>
<?php endif; ?>

<!-- Loans table -->
<?php if (!empty($loans)): ?>
<div class="section-title" style="margin-top:1.75rem">Loan Summary</div>
<table>
    <thead><tr>
        <th><?= t('ms_loan_no') ?></th><th>Applied</th><th><?= t('ms_approved') ?></th><th><?= t('ms_repaid') ?></th><th>Balance</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach ($loans as $l):
        $sBadge = match($l['status']) { 'disbursed'=>'badge-blue', 'completed'=>'badge-green', 'rejected'=>'badge-red', default=>'badge-amber' };
    ?>
    <tr>
        <td style="font-family:monospace;font-size:.78rem"><?= htmlspecialchars($l['loan_number']??'—') ?></td>
        <td><?= formatDate($l['applied_at'],'d M Y') ?></td>
        <td><?= $curr ?> <?= number_format($l['amount_approved']??0,2) ?></td>
        <td><?= $curr ?> <?= number_format($l['paid_amount'],2) ?></td>
        <td><?= $curr ?> <?= number_format($l['balance']??0,2) ?></td>
        <td><span class="badge-sm <?= $sBadge ?>"><?= ucfirst($l['status']) ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- Footer -->
<div class="stmt-footer">
    <span><?= htmlspecialchars($groupName) ?> · Confidential Member Statement</span>
    <span>Generated: <?= date('d M Y H:i') ?> · Ref: <?= $refNo ?></span>
    <span>SmartChama v1.0</span>
</div>
</div><!-- .page -->

<!-- Year selector -->
<div class="no-print" style="text-align:center;padding:1.5rem;background:#f0f4f8">
    <span style="font-size:.85rem;color:#6b87a8;margin-right:.75rem">View another year:</span>
    <?php for ($y = date('Y'); $y >= date('Y')-4; $y--): ?>
    <a href="?<?= $role==='admin'?'member_id='.$targetId.'&':'' ?>year=<?= $y ?>"
       style="margin:.25rem;display:inline-block;padding:.4rem .9rem;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none;
              background:<?= $y==$year?'#00c471':'#fff' ?>;color:<?= $y==$year?'#060e1a':'#374151' ?>;
              border:1px solid <?= $y==$year?'#00c471':'#e2e8f0' ?>">
        <?= $y ?>
    </a>
    <?php endfor; ?>
</div>
</body>
</html>
