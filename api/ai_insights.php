<?php
/**
 * ChamaLedger — AI Financial Insights Engine
 * Analyses contribution, loan and member data to surface actionable insights
 * Returns JSON — called by admin dashboard
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
startSession();
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');
if (!isLoggedIn() || (($_SESSION['user_role']??$_SESSION['role']??'') !== 'admin')) {
    echo json_encode(['ok'=>false]); exit;
}

$pdo   = getDB();
$curr  = getSetting('currency','KES');
$year  = (int)date('Y');
$month = (int)date('m');

$insights = [];

// ── 1. Late payers this month ─────────────────────────────────────────────────
$thisMonth = date('Y-m-01');
$activeMembers = $pdo->query("SELECT id,full_name,phone,joined_date,created_at FROM users WHERE role='member' AND status='active'")->fetchAll();
// Helper: safe join date for a member
$safeJoin = function(array $m): string {
    $raw = $m['joined_date'] ?: $m['created_at'];
    $ts  = $raw ? strtotime($raw) : 0;
    return $ts > 0 ? date('Y-m-01', $ts) : date('Y-m-01');
};
$thisMonthYm = date('Y-m');
// Only members who joined BEFORE this month can be "late" for this month
$billableMembers = array_filter($activeMembers, fn($m) => date('Y-m', strtotime($safeJoin($m))) <= $thisMonthYm);
$paidThisMonth = $pdo->query("SELECT DISTINCT user_id FROM contributions WHERE status='confirmed' AND DATE_FORMAT(payment_month,'%Y-%m')='" . date('Y-m') . "'")->fetchAll(PDO::FETCH_COLUMN);
$latePayers = array_filter($billableMembers, fn($m) => !in_array($m['id'], $paidThisMonth));
if (count($latePayers) > 0) {
    $names = array_slice(array_map(fn($m)=>$m['full_name'], array_values($latePayers)), 0, 3);
    $insights[] = [
        'type'     => 'warning',
        'icon'     => 'bi-exclamation-triangle-fill',
        'title'    => count($latePayers) . ' member' . (count($latePayers)>1?'s have':' has') . ' not paid this month',
        'detail'   => implode(', ', $names) . (count($latePayers)>3 ? ' and ' . (count($latePayers)-3) . ' more' : ''),
        'action'   => 'Send reminders',
        'action_url'=> APP_URL . '/admin/members.php?filter=late',
        'data'     => array_values(array_map(fn($m)=>['id'=>$m['id'],'name'=>$m['full_name'],'phone'=>$m['phone']], $latePayers)),
    ];
}

// ── 2. Collection rate trend ──────────────────────────────────────────────────
$totalActive = count($billableMembers);
$paidCount   = count($paidThisMonth);
$rate        = $totalActive > 0 ? round($paidCount / $totalActive * 100) : 0;
$prevMonth   = date('Y-m', strtotime('-1 month'));
$paidPrev    = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM contributions WHERE status='confirmed' AND DATE_FORMAT(payment_month,'%Y-%m')='{$prevMonth}'")->fetchColumn();
$prevRate    = $totalActive > 0 ? round($paidPrev / $totalActive * 100) : 0;
$trend       = $rate - $prevRate;
$insights[] = [
    'type'   => $rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger'),
    'icon'   => 'bi-graph-up-arrow',
    'title'  => "Collection rate: {$rate}% this month",
    'detail' => ($trend >= 0 ? "&#9650; Up {$trend}%" : "&#9660; Down " . abs($trend) . "%") . " vs last month · {$paidCount}/{$totalActive} members paid",
    'action' => null,
];

// ── 3. Monthly savings trend (last 6 months) ──────────────────────────────────
$trendData = [];
for ($i = 5; $i >= 0; $i--) {
    $m   = date('Y-m', strtotime("-{$i} months"));
    $amt = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status='confirmed' AND DATE_FORMAT(payment_month,'%Y-%m')='{$m}'")->fetchColumn();
    $trendData[] = ['month' => date('M', strtotime($m . '-01')), 'amount' => $amt];
}
$insights[] = [
    'type'     => 'chart',
    'icon'     => 'bi-bar-chart-fill',
    'title'    => 'Savings trend (6 months)',
    'detail'   => $curr . ' ' . number_format(array_sum(array_column($trendData,'amount')),0) . ' collected over 6 months',
    'chart'    => $trendData,
];

// ── 4. Members with 2+ consecutive missed payments ────────────────────────────
$chronic = [];
foreach ($activeMembers as $m) {
    $joinYm = date('Y-m', strtotime($safeJoin($m)));
    $last2  = [];
    for ($i = 1; $i <= 2; $i++) {
        $mo = date('Y-m', strtotime("-{$i} months"));
        // Skip months before the member joined — they can't miss what they didn't owe
        if ($mo < $joinYm) { $last2[] = 1; continue; }
        $paid = $pdo->prepare("SELECT COUNT(*) FROM contributions WHERE user_id=? AND status='confirmed' AND DATE_FORMAT(payment_month,'%Y-%m')=?");
        $paid->execute([$m['id'], $mo]);
        $last2[] = (int)$paid->fetchColumn();
    }
    if ($last2[0] === 0 && $last2[1] === 0) $chronic[] = $m['full_name'];
}
if (!empty($chronic)) {
    $insights[] = [
        'type'   => 'danger',
        'icon'   => 'bi-person-x-fill',
        'title'  => count($chronic) . ' member' . (count($chronic)>1?'s have':' has') . ' missed 2+ consecutive months',
        'detail' => implode(', ', array_slice($chronic, 0, 4)) . (count($chronic)>4?' and more':''),
        'action' => 'View members',
        'action_url' => APP_URL . '/admin/members.php',
    ];
}

// ── 5. Outstanding loans risk ─────────────────────────────────────────────────
$overdueLoans = $pdo->query("
    SELECT l.id, l.balance, l.amount_approved, u.full_name,
           DATEDIFF(NOW(), l.disbursed_at) AS days_out
    FROM loans l JOIN users u ON l.user_id=u.id
    WHERE l.status='disbursed' AND l.disbursed_at IS NOT NULL
      AND DATEDIFF(NOW(), l.disbursed_at) > 30
    ORDER BY l.balance DESC LIMIT 5
")->fetchAll();
if (!empty($overdueLoans)) {
    $totalRisk = array_sum(array_column($overdueLoans, 'balance'));
    $insights[] = [
        'type'   => 'warning',
        'icon'   => 'bi-cash-stack',
        'title'  => count($overdueLoans) . ' loan' . (count($overdueLoans)>1?'s':''). ' outstanding >30 days',
        'detail' => $curr . ' ' . number_format($totalRisk, 0) . ' at risk · ' . implode(', ', array_slice(array_column($overdueLoans,'full_name'),0,3)),
        'action' => 'View loans',
        'action_url' => APP_URL . '/admin/loans.php',
    ];
}

// ── 6. Group health score ─────────────────────────────────────────────────────
$totalContribs = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status='confirmed' AND YEAR(payment_month)={$year}")->fetchColumn();
$totalExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE YEAR(expense_date)={$year}")->fetchColumn();
$netSavings    = $totalContribs - $totalExpenses;
$outstandingLoansTotal = (float)$pdo->query("SELECT COALESCE(SUM(balance),0) FROM loans WHERE status IN ('approved','disbursed')")->fetchColumn();
$score = min(100, max(0, (int)(
    ($rate * 0.4) +
    ($netSavings > 0 ? 30 : 0) +
    (empty($chronic) ? 20 : 10) +
    (count($latePayers) < $totalActive * 0.2 ? 10 : 0)
)));
$scoreLabel = $score >= 80 ? 'Excellent' : ($score >= 60 ? 'Good' : ($score >= 40 ? 'Fair' : 'Needs Attention'));
$insights[] = [
    'type'   => $score >= 70 ? 'success' : ($score >= 50 ? 'warning' : 'danger'),
    'icon'   => 'bi-shield-check',
    'title'  => "Group health score: {$score}/100 — {$scoreLabel}",
    'detail' => "Net savings {$curr} " . number_format($netSavings,0) . " · Outstanding loans {$curr} " . number_format($outstandingLoansTotal,0),
    'score'  => $score,
];

// Cache result
try {
    $pdo->prepare("DELETE FROM ai_insights WHERE type='dashboard'")->execute();
    $pdo->prepare("INSERT INTO ai_insights (type, payload) VALUES ('dashboard', ?)")
        ->execute([json_encode($insights)]);
} catch(Exception $e){}

echo json_encode(['ok'=>true, 'insights'=>$insights, 'generated_at'=>date('H:i')]);
