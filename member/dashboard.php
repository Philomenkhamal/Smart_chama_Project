<?php
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
requireMember();
$pageTitle = 'My Dashboard — ChamaLedger';
require_once ROOT . '/includes/header.php';

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$walletObj = new Wallet($pdo);
$walletBal = $walletObj->getBalance($userId);
$walletLedger = $walletObj->getLedger($userId, 5);

// Event contributions total
$evStmt = $pdo->prepare("SELECT COALESCE(SUM(ec.amount),0) FROM event_contributions ec WHERE ec.user_id=? AND ec.status='confirmed'");
$evStmt->execute([$userId]); $myEventsTotal = (float)$evStmt->fetchColumn();
$evCountStmt = $pdo->prepare("SELECT COUNT(*) FROM event_contributions ec WHERE ec.user_id=? AND ec.status='confirmed'");
$evCountStmt->execute([$userId]); $myEventsCount = (int)$evCountStmt->fetchColumn();
$curr = getSetting('currency','KES');
$user   = currentUser();
$curr   = getSetting('currency', 'KES');
$monthlyRequired = (float)getSetting('monthly_contribution', '10300');

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE user_id=? AND status='confirmed'");
$stmt->execute([$userId]); $myTotalSavings = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM contributions WHERE user_id=? AND status='confirmed'");
$stmt->execute([$userId]); $myContribMonths = (int)$stmt->fetchColumn();

// Expected months since joining (for missed count on dashboard)
$joinedDate = getMemberStartDate($user); // Auto: later of member join date or chama start
$joinedDt      = new DateTime(date('Y-m-01', strtotime($joinedDate)));
$nowDt         = new DateTime(date('Y-m-01'));
$diffMonths    = (int)$joinedDt->diff($nowDt)->m + ((int)$joinedDt->diff($nowDt)->y * 12);
$expectedMonths = max(0, $diffMonths); // months from join to last month (not counting current)
$missedCount   = max(0, $expectedMonths - $myContribMonths);

$stmt = $pdo->prepare("SELECT * FROM loans WHERE user_id=? AND status IN ('approved','disbursed') ORDER BY applied_at DESC LIMIT 1");
$stmt->execute([$userId]); $activeLoan = $stmt->fetch();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE user_id=? AND status='pending'");
$stmt->execute([$userId]); $pendingLoans = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM contributions WHERE user_id=? ORDER BY recorded_at DESC LIMIT 6");
$stmt->execute([$userId]); $recentContribs = $stmt->fetchAll();

$announcements = $pdo->query("
    SELECT a.*, u.full_name as posted_by_name FROM announcements a JOIN users u ON a.posted_by = u.id
    WHERE a.is_active=1 ORDER BY a.priority DESC, a.created_at DESC LIMIT 4
")->fetchAll();

$loanLimit  = $myTotalSavings * (float)getSetting('max_loan_multiplier', '3');
$loanProgress = 0;
if ($activeLoan) {
    $total = (float)($activeLoan['total_repayable'] ?? 0);
    $loanProgress = $total > 0 ? min(100, ($activeLoan['amount_repaid'] / $total) * 100) : 0;
}

// Chart data
$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(payment_month,'%b %Y') AS label, SUM(amount) AS total
    FROM contributions WHERE user_id=? AND status='confirmed'
    GROUP BY payment_month ORDER BY payment_month ASC LIMIT 12
");
$stmt->execute([$userId]);
$myChartData   = $stmt->fetchAll();
$myChartLabels = json_encode(array_column($myChartData, 'label'));
$myChartValues = json_encode(array_column($myChartData, 'total'));
?>
<?php
// Show password change prompt if member has never changed it
$pwChanged = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE user_id=? AND action='PASSWORD_CHANGE'");
$pwChanged->execute([$userId]);
if (!(int)$pwChanged->fetchColumn()): ?>
<div class="alert alert-warning alert-dismissible d-flex align-items-center gap-2 mb-3" role="alert" style="border-left:4px solid #f59e0b">
    <i class="bi bi-shield-lock-fill fs-5 text-warning"></i>
    <div>
        <strong><?= t('mdash_action_required') ?></strong> You are using a temporary password.
        <a href="<?= APP_URL ?>/member/profile.php" class="alert-link fw-bold"><?= t('mdash_change_pw') ?></a>
        and secure your account.
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h4><?= t('adash_title') ?></h4>
        <div class="subtitle">
            <i class="bi bi-circle-fill me-1" style="font-size:.45rem;color:var(--green);vertical-align:middle"></i>
            <?= htmlspecialchars($user['full_name']) ?> &nbsp;·&nbsp;
            <?= htmlspecialchars($user['membership_number'] ?? 'Pending #') ?> &nbsp;·&nbsp;
            <?= date('d F Y') ?>
        </div>
    </div>
    <?php if ($user['status'] === 'active'): ?>
    <a href="<?= APP_URL ?>/member/loan_apply.php" class="btn btn-primary btn-sm">
        <i class="bi bi-cash-stack me-1"></i>Apply for Loan
    </a>
    <?php endif; ?>
</div>

<?php if ($user['status'] !== 'active'): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-exclamation-triangle-fill"></i>
    Your account is <strong><?= $user['status'] ?></strong>. Some features may be restricted until fully activated.
</div>
<?php endif; ?>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <!-- Wallet Balance Card -->
        <?php
        $walColor  = $walletBal < 0 ? '#ef4444' : '#00c471';
        $walIcon   = $walletBal < 0 ? 'bi-arrow-down-circle-fill' : ($walletBal > 0 ? 'bi-arrow-up-circle-fill' : 'bi-check-circle-fill');
        $walLabel  = $walletBal < 0 ? 'You owe the chama' : ($walletBal > 0 ? 'Chama owes you' : 'Fully settled');
        ?>
        <div class="stat-card" style="--card-glow:<?= $walColor ?>33;border-color:<?= $walColor ?>33;grid-column:1/-1">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem">
                <div>
                    <div class="stat-label"><i class="bi <?= $walIcon ?> me-1" style="color:<?= $walColor ?>"></i><?= t('mdash_wallet_balance') ?></div>
                    <div class="stat-value sm" style="color:<?= $walColor ?>;font-size:1.8rem">
                        <?= $walletBal >= 0 ? '+' : '' ?><?= money(abs($walletBal), $curr) ?>
                    </div>
                    <div style="font-size:.78rem;color:var(--text-muted);margin-top:.3rem"><?= $walLabel ?></div>
                </div>
                <!-- Mini ledger -->
                <div style="font-size:.75rem;min-width:200px">
                    <?php foreach ($walletLedger as $entry): ?>
                    <div style="display:flex;justify-content:space-between;padding:.25rem 0;border-bottom:1px solid rgba(255,255,255,.05)">
                        <span style="color:var(--text-muted);max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($entry['description']) ?></span>
                        <span style="font-weight:700;color:<?= $entry['type']==='credit'?'#00c471':'#ef4444' ?>;margin-left:.5rem">
                            <?= $entry['type']==='credit'?'+':'-' ?><?= number_format($entry['amount'],0) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($walletLedger)): ?>
                    <div style="color:var(--text-muted)"><?= t('mdash_no_transactions') ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon green"><i class="bi bi-piggy-bank-fill"></i></div>
            <div class="stat-label"><?= t('mdash_total_savings') ?></div>
            <div class="stat-value sm"><?= money($myTotalSavings, $curr) ?></div>
            <div class="stat-sub up"><i class="bi bi-check-circle"></i> <?= $myContribMonths ?> <?= t('mdash_months_paid') ?></div>
            <?php if ($missedCount > 0): ?>
            <div class="stat-sub" style="color:#f59e0b;margin-top:2px">
                <i class="bi bi-exclamation-triangle"></i> <?= $missedCount ?> month<?= $missedCount > 1 ? 's' : '' ?> missed
            </div>
            <?php endif; ?>
        </div>
        <?php if ($myEventsTotal > 0): ?>
        <div class="stat-card" style="background:rgba(139,92,246,.07);border-color:rgba(139,92,246,.2)">
            <div class="stat-icon" style="background:rgba(139,92,246,.15);color:#8b5cf6"><i class="bi bi-calendar-event-fill"></i></div>
            <div class="stat-label"><?= t('mdash_event_contrib') ?></div>
            <div class="stat-value sm" style="color:#8b5cf6"><?= money($myEventsTotal, $curr) ?></div>
            <div class="stat-sub"><i class="bi bi-check-circle"></i> <?= $myEventsCount ?> event<?= $myEventsCount!=1?'s':'' ?> <?= t('mdash_contributed') ?></div>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="bi bi-calendar-check"></i></div>
            <div class="stat-label"><?= t('dash_monthly_target') ?></div>
            <div class="stat-value sm"><?= money($monthlyRequired, $curr) ?></div>
            <div class="stat-sub"><?= t('mdash_per_month') ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <?php if ($activeLoan): ?>
        <div class="stat-card amber">
            <div class="stat-icon amber"><i class="bi bi-cash-coin"></i></div>
            <div class="stat-label"><?= t('mdash_loan_balance') ?></div>
            <div class="stat-value sm" style="color:var(--amber)"><?= money($activeLoan['balance'], $curr) ?></div>
            <div class="stat-sub warn"><i class="bi bi-calendar3"></i> Due <?= formatDate($activeLoan['due_date'] ?? '', 'M Y') ?></div>
        </div>
        <?php else: ?>
        <div class="stat-card teal">
            <div class="stat-icon teal"><i class="bi bi-check-circle-fill"></i></div>
            <div class="stat-label"><?= t('mdash_loan_status') ?></div>
            <div class="stat-value" style="font-size:1.1rem;color:var(--green)"><?= t('mdash_no_active_loan') ?></div>
            <div class="stat-sub up"><i class="bi bi-circle-fill" style="font-size:.4rem"></i> Clear</div>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card" style="--card-glow:rgba(20,184,166,0.15)">
            <div class="stat-icon" style="background:rgba(20,184,166,0.15);color:var(--teal)"><i class="bi bi-award-fill"></i></div>
            <div class="stat-label"><?= t('lbl_loan_limit') ?></div>
            <div class="stat-value sm" style="color:var(--teal)"><?= money($loanLimit, $curr) ?></div>
            <div class="stat-sub"><?= getSetting('max_loan_multiplier','3') ?><?= t('mdash_x_savings') ?></div>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="row g-3">

    <!-- Left: Chart + Table -->
    <div class="col-lg-8">

        <!-- Savings Chart -->
        <div class="card mb-3">
            <div class="card-header">
                <h6><i class="bi bi-graph-up me-2" style="color:var(--green)"></i><?= t('lbl_savings_history') ?></h6>
                <a href="<?= APP_URL ?>/member/contributions.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-plus me-1"></i>Record Payment
                </a>
            </div>
            <div class="card-body" style="padding:1.25rem">
                <canvas id="myContribChart" height="90"></canvas>
            </div>
        </div>

        <!-- Recent Contributions -->
        <div class="card">
            <div class="card-header">
                <h6><i class="bi bi-clock-history me-2" style="color:var(--blue)"></i><?= t('lbl_recent_contrib') ?></h6>
                <a href="<?= APP_URL ?>/member/history.php" class="btn btn-outline-primary btn-sm"><?= t('lbl_full_history') ?></a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th><?= t('lbl_month') ?></th><th><?= t('lbl_amount') ?></th><th><?= t('mdash_method') ?></th><th><?= t('lbl_status') ?></th><th>Date</th></tr></thead>
                    <tbody>
                    <?php if (empty($recentContribs)): ?>
                        <tr><td colspan="5" class="text-center py-4" style="color:var(--text-muted)"><?= t('mdash_no_contrib') ?></td></tr>
                    <?php else: foreach ($recentContribs as $c): ?>
                        <tr>
                            <td style="font-weight:600"><?= monthLabel($c['payment_month']) ?></td>
                            <td style="color:var(--green);font-weight:600"><?= money($c['amount'], $curr) ?></td>
                            <td style="text-transform:capitalize;color:var(--text-muted)"><?= $c['payment_method'] ?></td>
                            <td><?= badgeStatus($c['status']) ?></td>
                            <td style="color:var(--text-muted)"><?= formatDate($c['recorded_at'], 'd M Y') ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Right: Loan + Announcements -->
    <div class="col-lg-4">

        <!-- Active Loan / CTA -->
        <?php if ($activeLoan): ?>
        <div class="card mb-3" style="border-color:rgba(245,158,11,0.3)!important">
            <div class="card-header" style="border-color:rgba(245,158,11,0.2)!important">
                <h6><i class="bi bi-cash-stack me-2" style="color:var(--amber)"></i><?= t('mdash_active_loan') ?></h6>
                <span class="badge bg-warning"><?= $activeLoan['loan_number'] ?></span>
            </div>
            <div class="card-body" style="padding:1.25rem">
                <div class="row g-2 mb-3">
                    <?php
                    $loanItems = [
                        ['Approved', money($activeLoan['amount_approved'] ?? $activeLoan['amount_requested'], $curr), 'var(--text)'],
                        ['Balance',  money($activeLoan['balance'] ?? 0, $curr), 'var(--amber)'],
                        ['Repaid',   money($activeLoan['amount_repaid'], $curr), 'var(--green)'],
                        ['Due',      $activeLoan['due_date'] ? formatDate($activeLoan['due_date'], 'M Y') : 'N/A', 'var(--text)'],
                    ];
                    foreach ($loanItems as [$label, $val, $color]): ?>
                    <div class="col-6">
                        <div style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);border-radius:9px;padding:.65rem;text-align:center">
                            <div style="font-size:.7rem;color:var(--text-muted);margin-bottom:.2rem"><?= $label ?></div>
                            <div style="font-size:.85rem;font-weight:700;color:<?= $color ?>"><?= $val ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-bottom:.5rem">
                    <div class="d-flex justify-content-between" style="font-size:.75rem;color:var(--text-muted);margin-bottom:.35rem">
                        <span><?= t('mdash_repay_progress') ?></span><span><?= round($loanProgress) ?>%</span>
                    </div>
                    <div class="progress" style="height:7px">
                        <div class="progress-bar" style="width:<?= $loanProgress ?>%"></div>
                    </div>
                </div>
                <a href="<?= APP_URL ?>/member/my_loans.php" class="btn btn-outline-secondary btn-sm w-100 mt-2">
                    View Details
                </a>
            </div>
        </div>

        <?php elseif ($pendingLoans > 0): ?>
        <div class="card mb-3" style="border-color:rgba(59,130,246,0.3)!important">
            <div class="card-body text-center py-4">
                <div style="width:50px;height:50px;background:rgba(59,130,246,0.12);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem">
                    <i class="bi bi-hourglass-split" style="font-size:1.3rem;color:var(--blue)"></i>
                </div>
                <div style="font-weight:600;margin-bottom:.3rem"><?= t('mdash_under_review') ?></div>
                <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:1rem"><?= t('mdash_review_msg') ?></div>
                <a href="<?= APP_URL ?>/member/my_loans.php" class="btn btn-outline-primary btn-sm"><?= t('mdash_check_status') ?></a>
            </div>
        </div>

        <?php else: ?>
        <div class="card mb-3">
            <div class="card-body text-center py-4">
                <div style="width:50px;height:50px;background:rgba(0,196,113,0.12);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem">
                    <i class="bi bi-cash-stack" style="font-size:1.3rem;color:var(--green)"></i>
                </div>
                <div style="font-weight:600;margin-bottom:.3rem"><?= t('mdash_no_active_loan') ?></div>
                <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:.3rem"><?= t('mdash_qualify') ?></div>
                <div style="font-family:'Syne',sans-serif;font-size:1.2rem;font-weight:700;color:var(--green);margin-bottom:1rem"><?= money($loanLimit, $curr) ?></div>
                <?php if ($user['status'] === 'active'): ?>
                <a href="<?= APP_URL ?>/member/loan_apply.php" class="btn btn-primary btn-sm px-4"><?= t('mdash_apply_now') ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Announcements -->
        <div class="card">
            <div class="card-header">
                <h6><i class="bi bi-megaphone me-2" style="color:var(--amber)"></i><?= t('mdash_announcements') ?></h6>
                <a href="<?= APP_URL ?>/member/announcements.php" class="btn btn-outline-secondary btn-sm" style="font-size:.72rem;padding:.25rem .6rem">See all</a>
            </div>
            <?php if (empty($announcements)): ?>
            <div class="card-body text-center py-4" style="color:var(--text-muted);font-size:.85rem">
                <i class="bi bi-megaphone d-block mb-2" style="font-size:1.5rem"></i>No announcements yet.
            </div>
            <?php else: ?>
            <?php foreach ($announcements as $i => $a): ?>
            <div class="px-3 py-3<?= $i < count($announcements)-1 ? ' border-bottom' : '' ?>">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div style="font-size:.85rem;font-weight:600;line-height:1.3"><?= htmlspecialchars($a['title']) ?></div>
                    <?php if ($a['priority'] === 'urgent'): ?>
                    <span class="badge bg-danger flex-shrink-0"><?= t('mdash_urgent') ?></span>
                    <?php elseif ($a['priority'] === 'high'): ?>
                    <span class="badge bg-warning flex-shrink-0">High</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:.77rem;color:var(--text-muted);margin-top:.25rem;line-height:1.5">
                    <?= htmlspecialchars(substr($a['body'], 0, 85)) . (strlen($a['body']) > 85 ? '…' : '') ?>
                </div>
                <div style="font-size:.7rem;color:rgba(107,135,168,0.6);margin-top:.3rem"><?= formatDate($a['created_at'], 'd M Y') ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div><!-- /right col -->
</div><!-- /row -->

<?php
$extraScripts = <<<JS
<script>
const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
Chart.defaults.color = isDark ? '#6b87a8' : '#5a7390';
Chart.defaults.borderColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.07)';
Chart.defaults.font.family = "'DM Sans', sans-serif";

var labels = {$myChartLabels};
var values = {$myChartValues};
if (labels.length) {
    new Chart(document.getElementById('myContribChart'), {
        type: 'line',
        data: {
            labels,
            datasets:[{
                data: values,
                borderColor: 'rgba(0,196,113,1)',
                backgroundColor: 'rgba(0,196,113,0.08)',
                tension: 0.4,
                fill: true,
                pointRadius: 5,
                pointBackgroundColor: 'var(--navy)',
                pointBorderColor: 'rgba(0,196,113,1)',
                pointBorderWidth: 2,
                pointHoverRadius: 7,
            }]
        },
        options:{
            responsive:true,
            plugins:{
                legend:{display:false},
                tooltip:{
                    backgroundColor:'rgba(13,31,56,0.95)',
                    borderColor:'rgba(255,255,255,0.1)',
                    borderWidth:1,
                    padding:12,
                    callbacks:{ label: ctx => ' {$curr} ' + ctx.parsed.y.toLocaleString() }
                }
            },
            scales:{
                x:{ grid:{ display:false }, ticks:{ font:{size:11} } },
                y:{ beginAtZero:true, grid:{ color:'rgba(255,255,255,0.05)' }, ticks:{ callback: v => '{$curr} ' + v.toLocaleString(), font:{size:11} } }
            }
        }
    });
} else {
    document.getElementById('myContribChart').parentElement.innerHTML =
        '<p class="text-center py-4" style="color:var(--text-muted)">' + '<?= addslashes(t("mdash_no_contribs")) ?>' + '</p>';
}
</script>
JS;
require_once ROOT . '/includes/footer.php';
?>
