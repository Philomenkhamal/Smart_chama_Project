<?php
/**
 * ChamaLedger — Contribution Report
 * Filter by: Member (month-by-month with totals) OR Event
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Contribution Report — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();

$pdo       = getDB();
$curr      = getSetting('currency', 'KES');
$groupName = getSetting('group_name', 'ChamaLedger');

$filterType = $_GET['filter'] ?? 'member';   // 'member' or 'event'

// ── CSV Download ──────────────────────────────────────────────────────────────
if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    $dlYear      = (int)($_GET['year'] ?? date('Y'));
    $dlMemberId  = (int)($_GET['member_id'] ?? 0);
    $dlMonths    = [];
    for ($m = 1; $m <= 12; $m++) $dlMonths[] = sprintf('%04d-%02d', $dlYear, $m);
    $dlMembers   = $pdo->query("SELECT id,full_name,membership_number,joined_date,created_at FROM users WHERE role='member' AND status='active' ORDER BY full_name")->fetchAll();
    if ($dlMemberId) $dlMembers = array_filter($dlMembers, fn($m) => $m['id'] === $dlMemberId);

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"contributions_{$dlYear}_" . date('Ymd') . ".csv\"");
    $out = fopen('php://output', 'w');
    // Header row
    $hdr = ['Member', 'Membership No'];
    foreach ($dlMonths as $mo) $hdr[] = date('M Y', strtotime($mo));
    $hdr[] = 'Total Paid'; $hdr[] = 'Months Paid';
    fputcsv($out, $hdr);
    // Data rows
    foreach ($dlMembers as $mem) {
        $memberStartYm = date('Y-m', strtotime(getMemberStartDate($mem)));
        $row = [$mem['full_name'], $mem['membership_number']];
        $total = 0; $paidCount = 0;
        foreach ($dlMonths as $mo) {
            if ($mo < $memberStartYm) { $row[] = 'N/A'; continue; }
            $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE user_id=? AND status='confirmed' AND DATE_FORMAT(payment_month,'%Y-%m')=?");
            $s->execute([$mem['id'], $mo]);
            $amt = (float)$s->fetchColumn();
            $row[] = $amt > 0 ? number_format($amt, 2) : '0.00';
            $total += $amt;
            if ($amt > 0) $paidCount++;
        }
        $row[] = number_format($total, 2);
        $row[] = $paidCount;
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}
$memberId   = (int)($_GET['member_id'] ?? 0);
$eventId    = (int)($_GET['event_id']  ?? 0);
$year       = (int)($_GET['year']      ?? date('Y'));

$members = $pdo->query("SELECT id,full_name,membership_number,joined_date,created_at,profile_photo FROM users WHERE role='member' AND status='active' ORDER BY full_name")->fetchAll();
$events  = $pdo->query("SELECT id,title,event_date FROM events ORDER BY event_date DESC")->fetchAll();

// ── MEMBER REPORT ─────────────────────────────────────────────────────────────
$memberRows = []; $memberTotals = []; $months = [];
if ($filterType === 'member' && $year) {
    // Get all 12 months as columns
    for ($m = 1; $m <= 12; $m++) {
        $months[] = sprintf('%04d-%02d', $year, $m);
    }

    $targetMembers = $memberId
        ? array_filter($members, fn($m) => $m['id'] === $memberId)
        : $members;

    foreach ($targetMembers as $mem) {
        $memberStartYm = date('Y-m', strtotime(getMemberStartDate($mem)));
        $row = ['id' => $mem['id'], 'name' => $mem['full_name'], 'memno' => $mem['membership_number'],
                'months' => [], 'total' => 0, 'paid_count' => 0, 'member_start' => $memberStartYm];
        foreach ($months as $mo) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(amount),0) FROM contributions
                WHERE user_id=? AND status='confirmed' AND DATE_FORMAT(payment_month,'%Y-%m')=?
            ");
            $stmt->execute([$mem['id'], $mo]);
            $amt = (float)$stmt->fetchColumn();
            // If month is before member joined, mark as N/A (not applicable)
            if ($mo < $memberStartYm) {
                $row['months'][$mo] = 'na';
            } else {
                $row['months'][$mo] = $amt;
                if ($amt > 0) $row['paid_count']++;
            }
            if ($row['months'][$mo] !== 'na') $row['total'] += $amt;
        }
        $memberRows[] = $row;
    }

    // Column totals
    foreach ($months as $mo) {
        $memberTotals[$mo] = array_sum(array_column(array_map(fn($r) => ['v' => $r['months'][$mo] === 'na' ? 0 : $r['months'][$mo]], $memberRows), 'v'));
    }
}

// ── EVENT REPORT ──────────────────────────────────────────────────────────────
$eventRows = []; $eventTotal = 0; $eventData = null;
if ($filterType === 'event' && $eventId) {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id=?"); $stmt->execute([$eventId]); $eventData = $stmt->fetch();
    $rows = $pdo->prepare("
        SELECT ec.*, u.full_name, u.membership_number, u.phone, u.profile_photo
        FROM event_contributions ec
        JOIN users u ON u.id=ec.user_id
        WHERE ec.event_id=? AND ec.status='confirmed'
        ORDER BY ec.amount DESC
    ");
    $rows->execute([$eventId]); $eventRows = $rows->fetchAll();
    $eventTotal = array_sum(array_column($eventRows, 'amount'));
}

require_once ROOT . '/includes/header.php';
?>

<style>
@media print {
    .no-print { display:none!important; }
    body { background:#fff!important; color:#000!important; }
    .report-table th, .report-table td { font-size:8.5pt!important; padding:.3rem .4rem!important; }
}
.report-table th { font-size:.7rem; white-space:nowrap; }
.report-table td { font-size:.8rem; }
.zero-cell { color:rgba(255,255,255,.15); }
.paid-cell { color:#00c471; font-weight:700; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
    <div>
        <h4 class="fw-bold mb-0">Contribution Report</h4>
        <small class="text-muted">Per-member monthly breakdown or per-event summary</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?filter=<?= $filterType ?>&year=<?= $year ?>&member_id=<?= $memberId ?>&download=csv"
           class="btn btn-outline-success btn-sm no-print">
            <i class="bi bi-download me-1"></i>Download CSV
        </a>
        <a href="<?= APP_URL ?>/admin/import_contributions.php" class="btn btn-outline-primary btn-sm no-print">
            <i class="bi bi-upload me-1"></i>Import History
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print">
            <i class="bi bi-printer me-1"></i>Print / PDF
        </button>
    </div>
</div>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm mb-4 no-print">
    <div class="card-body p-3">
        <form method="GET" class="d-flex gap-3 flex-wrap align-items-end">
            <!-- Filter type toggle -->
            <div>
                <label class="form-label mb-1" style="font-size:.78rem">Report Type</label>
                <div class="btn-group btn-group-sm" role="group">
                    <a href="?filter=member&year=<?= $year ?>" class="btn <?= $filterType==='member'?'btn-primary':'btn-outline-secondary' ?>">
                        <i class="bi bi-person me-1"></i>By Member
                    </a>
                    <a href="?filter=event" class="btn <?= $filterType==='event'?'btn-primary':'btn-outline-secondary' ?>">
                        <i class="bi bi-calendar-event me-1"></i>By Event
                    </a>
                </div>
            </div>

            <input type="hidden" name="filter" value="<?= $filterType ?>">

            <?php if ($filterType === 'member'): ?>
            <div>
                <label class="form-label mb-1" style="font-size:.78rem">Member (leave blank for all)</label>
                <select name="member_id" class="form-select form-select-sm" style="width:200px">
                    <option value="">All Members</option>
                    <?php foreach ($members as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $m['id']===$memberId?'selected':'' ?>><?= htmlspecialchars($m['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label mb-1" style="font-size:.78rem">Year</label>
                <select name="year" class="form-select form-select-sm" style="width:100px">
                    <?php for ($y=date('Y');$y>=2020;$y--): ?>
                    <option value="<?= $y ?>" <?= $y===$year?'selected':'' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <?php else: ?>
            <div>
                <label class="form-label mb-1" style="font-size:.78rem">Event</label>
                <select name="event_id" class="form-select form-select-sm" style="width:260px">
                    <option value="">Select event…</option>
                    <?php foreach ($events as $ev): ?>
                    <option value="<?= $ev['id'] ?>" <?= $ev['id']===$eventId?'selected':'' ?>>
                        <?= htmlspecialchars($ev['title']) ?> (<?= date('M Y', strtotime($ev['event_date'])) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div><button class="btn btn-primary btn-sm" style="margin-top:1.4rem">Generate Report</button></div>
        </form>
    </div>
</div>

<?php if ($filterType === 'member' && !empty($memberRows)): ?>
<!-- ── MEMBER REPORT TABLE ─────────────────────────────────────────────────── -->
<div class="mb-3" style="font-size:.85rem;color:var(--text-muted)">
    <strong style="color:var(--text)"><?= $groupName ?></strong> — <?= $memberId ? htmlspecialchars(array_values(array_filter($members,fn($m)=>$m['id']===$memberId))[0]['full_name']??'All Members') : 'All Members' ?> · <?= $year ?>
</div>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover report-table mb-0">
            <thead>
                <tr style="background:var(--sidebar-bg)">
                    <th class="ps-3" style="position:sticky;left:0;background:var(--sidebar-bg);z-index:1">Member</th>
                    <?php foreach ($months as $mo): ?>
                    <th class="text-end"><?= date('M', strtotime($mo.'-01')) ?></th>
                    <?php endforeach; ?>
                    <th class="text-end pe-3" style="color:#00c471">Total</th>
                    <th class="text-end pe-3">Paid</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($memberRows as $r): ?>
            <tr>
                <td class="ps-3" style="position:sticky;left:0;background:var(--card-bg)">
                    <div class="d-flex align-items-center gap-2">
                        <?= memberAvatar(['full_name'=>$r['name'],'profile_photo'=>$r['profile_photo']??null], 32) ?>
                        <div>
                            <div class="fw-semibold" style="font-size:.82rem"><?= htmlspecialchars($r['name']) ?></div>
                            <div style="font-size:.68rem;color:var(--text-muted);font-family:monospace"><?= $r['memno'] ?? '' ?></div>
                        </div>
                    </div>
                </td>
                <?php foreach ($months as $mo): $amt = $r['months'][$mo]; ?>
                <?php if ($amt === 'na'): ?>
                <td class="text-center zero-cell" title="Before member joined">—</td>
                <?php else: ?>
                <td class="text-end <?= $amt > 0 ? 'paid-cell' : 'zero-cell' ?>">
                    <?= $amt > 0 ? number_format($amt,0) : '—' ?>
                </td>
                <?php endif; ?>
                <?php endforeach; ?>
                <td class="text-end pe-3 fw-bold" style="color:#00c471;font-family:'Syne',sans-serif">
                    <?= $curr ?> <?= number_format($r['total'],0) ?>
                </td>
                <td class="text-end pe-3" style="color:var(--text-muted);font-size:.78rem">
                    <?php
                    $activeMos = count(array_filter($r['months'], fn($v) => $v !== 'na'));
                    echo $r['paid_count'] . '/' . $activeMos;
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--sidebar-bg);font-weight:800;border-top:2px solid var(--border)">
                    <td class="ps-3 py-3" style="position:sticky;left:0;background:var(--sidebar-bg)">TOTAL</td>
                    <?php foreach ($months as $mo): ?>
                    <td class="text-end" style="color:<?= $memberTotals[$mo]>0?'#00c471':'rgba(255,255,255,.2)' ?>">
                        <?= $memberTotals[$mo]>0 ? number_format($memberTotals[$mo],0) : '—' ?>
                    </td>
                    <?php endforeach; ?>
                    <td class="text-end pe-3" style="color:#00c471;font-family:'Syne',sans-serif">
                        <?= $curr ?> <?= number_format(array_sum(array_column($memberRows,'total')),0) ?>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php elseif ($filterType === 'event' && $eventData): ?>
<!-- ── EVENT REPORT TABLE ──────────────────────────────────────────────────── -->
<div class="mb-3" style="font-size:.85rem;color:var(--text-muted)">
    <strong style="color:var(--text)"><?= $groupName ?></strong> — <?= htmlspecialchars($eventData['title']) ?> · <?= date('d M Y', strtotime($eventData['event_date'])) ?>
</div>

<!-- Summary KPIs -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.4rem;font-weight:800;color:#00c471"><?= $curr ?> <?= number_format($eventTotal,0) ?></div>
            <div style="font-size:.72rem;color:var(--text-muted)">Total Raised</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <?php $distinctContributors = count(array_unique(array_column($eventRows, 'user_id'))); ?>
            <div style="font-size:1.4rem;font-weight:800;color:#3b82f6"><?= $distinctContributors ?></div>
            <div style="font-size:.72rem;color:var(--text-muted)">Contributors</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.4rem;font-weight:800;color:#f59e0b">
                <?= $distinctContributors > 0 ? $curr . ' ' . number_format($eventTotal / $distinctContributors, 0) : '—' ?>
            </div>
            <div style="font-size:.72rem;color:var(--text-muted)">Avg per Member</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.4rem;font-weight:800;color:#ef4444"><?= max(0, count($members) - $distinctContributors) ?></div>
            <div style="font-size:.72rem;color:var(--text-muted)">Didn't Contribute</div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover report-table mb-0">
            <thead>
                <tr style="background:var(--sidebar-bg)">
                    <th class="ps-3">#</th>
                    <th>Member</th>
                    <th>Phone</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th class="text-end pe-3">Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($eventRows as $i => $r): ?>
            <tr>
                <td class="ps-3 text-muted"><?= $i+1 ?></td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <?= memberAvatar($r, 32) ?>
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($r['full_name']) ?></div>
                            <div style="font-size:.7rem;color:var(--text-muted)"><?= $r['membership_number'] ?? '' ?></div>
                        </div>
                    </div>
                </td>
                <td style="font-family:monospace;font-size:.78rem"><?= htmlspecialchars($r['phone'] ?? '') ?></td>
                <td style="text-transform:capitalize"><?= $r['payment_method'] ?></td>
                <td style="font-family:monospace;font-size:.75rem"><?= htmlspecialchars($r['reference_code'] ?? '—') ?></td>
                <td class="text-end pe-3 fw-bold paid-cell"><?= $curr ?> <?= number_format($r['amount'],0) ?></td>
            </tr>
            <?php endforeach; ?>

            <!-- Members who didn't contribute -->
            <?php
            $contributedIds = array_column($eventRows, 'user_id');
            $missing = array_filter($members, fn($m) => !in_array($m['id'], $contributedIds));
            if (!empty($missing)): ?>
            <tr><td colspan="6" class="py-2 ps-3" style="font-size:.72rem;color:var(--text-muted);border-top:1px dashed var(--border)">
                ⚠️ Not contributed: <?= implode(', ', array_map(fn($m)=>htmlspecialchars($m['full_name']), $missing)) ?>
            </td></tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--sidebar-bg);font-weight:800;border-top:2px solid var(--border)">
                    <td colspan="5" class="ps-3 py-3" style="font-family:'Syne',sans-serif">TOTAL</td>
                    <td class="text-end pe-3" style="color:#00c471;font-family:'Syne',sans-serif"><?= $curr ?> <?= number_format($eventTotal,0) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php elseif ($filterType === 'member'): ?>
<div class="text-center text-muted py-5">
    <i class="bi bi-bar-chart" style="font-size:2.5rem;opacity:.3"></i>
    <p class="mt-2">Select a year above and click Generate Report</p>
</div>
<?php else: ?>
<div class="text-center text-muted py-5">
    <i class="bi bi-calendar-event" style="font-size:2.5rem;opacity:.3"></i>
    <p class="mt-2">Select an event above and click Generate Report</p>
</div>
<?php endif; ?>

<?php require_once ROOT . '/includes/footer.php'; ?>
