<?php
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Dashboard — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();
require_once ROOT . '/includes/header.php';

$pdo = getDB();
$currency = getSetting('currency', 'KES');

$stats = [
    'total_members'     => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND status='active'")->fetchColumn(),
    'pending_members'   => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND status='pending'")->fetchColumn(),
    'total_savings'     => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status='confirmed'")->fetchColumn(),
    'total_expenses'    => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses")->fetchColumn(),
    'active_loans'      => (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status IN ('approved','disbursed')")->fetchColumn(),
    'loans_outstanding' => (float)$pdo->query("SELECT COALESCE(SUM(balance),0) FROM loans WHERE status IN ('approved','disbursed')")->fetchColumn(),
    'pending_loans'     => (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status='pending'")->fetchColumn(),
    'total_repaid'      => (float)$pdo->query("SELECT COALESCE(SUM(amount_repaid),0) FROM loans")->fetchColumn(),
];

$recentContribs = $pdo->query("
    SELECT c.*, u.full_name, u.membership_number 
    FROM contributions c JOIN users u ON c.user_id = u.id 
    ORDER BY c.recorded_at DESC LIMIT 8
")->fetchAll();

$recentLoans = $pdo->query("
    SELECT l.*, u.full_name, u.membership_number 
    FROM loans l JOIN users u ON l.user_id = u.id 
    ORDER BY l.applied_at DESC LIMIT 8
")->fetchAll();

$chartData = $pdo->query("
    SELECT DATE_FORMAT(payment_month,'%b %Y') AS label, SUM(amount) AS total
    FROM contributions WHERE status='confirmed'
    AND payment_month >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY payment_month ORDER BY payment_month ASC
")->fetchAll();

$chartLabels = json_encode(array_column($chartData, 'label'));
$chartValues = json_encode(array_column($chartData, 'total'));
$jsSavings     = (float)$stats['total_savings'];
$jsOutstanding = (float)$stats['loans_outstanding'];
$jsExpenses    = (float)$stats['total_expenses'];
$netFunds      = $jsSavings - $jsExpenses;
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h4><?= t('adash_title') ?></h4>
        <div class="subtitle">
            <i class="bi bi-circle-fill me-1" style="font-size:.45rem;color:var(--green);vertical-align:middle"></i>
            <?= t('dash_welcome') ?>, <?= htmlspecialchars(explode(' ', $_SESSION['user_name'])[0]) ?> &nbsp;·&nbsp; <?= date('l, d F Y') ?>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($stats['pending_members'] > 0): ?>
        <a href="<?= APP_URL ?>/admin/approvals.php" class="btn btn-warning btn-sm">
            <i class="bi bi-person-exclamation me-1"></i><?= $stats['pending_members'] ?> <?= t('adash_pending') ?>
        </a>
        <?php endif; ?>
        <?php if ($stats['pending_loans'] > 0): ?>
        <a href="<?= APP_URL ?>/admin/loans.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-hourglass-split me-1"></i><?= $stats['pending_loans'] ?> <?= t('nav_loans') ?> <?= t('lbl_pending') ?>
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/admin/reports.php" class="btn btn-primary btn-sm">
            <i class="bi bi-download me-1"></i><?= t('report_download') ?>
        </a>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="stat-card green">
            <div class="stat-icon green"><i class="bi bi-piggy-bank-fill"></i></div>
            <div class="stat-label"><?= t('adash_total_savings') ?></div>
            <div class="stat-value sm"><?= money($stats['total_savings'], $currency) ?></div>
            <div class="stat-sub">
                <span><?= t('adash_net_funds') ?>: <strong style="color:var(--green)"><?= money($netFunds, $currency) ?></strong></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
            <div class="stat-label"><?= t('adash_members') ?></div>
            <div class="stat-value"><?= $stats['total_members'] ?></div>
            <div class="stat-sub">
                <?php if ($stats['pending_members'] > 0): ?>
                <span class="warn"><i class="bi bi-clock"></i> <?= $stats['pending_members'] ?> <?= t('lbl_pending') ?></span>
                <?php else: ?>
                <span class="up"><i class="bi bi-check-circle"></i> <?= t('lbl_approved') ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card amber">
            <div class="stat-icon amber"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-label"><?= t('adash_loans_out') ?></div>
            <div class="stat-value sm"><?= money($stats['loans_outstanding'], $currency) ?></div>
            <div class="stat-sub">
                <span><?= $stats['active_loans'] ?> <?= t('nav_loans') ?></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card teal">
            <div class="stat-icon teal"><i class="bi bi-arrow-return-left"></i></div>
            <div class="stat-label"><?= t('repay_total') ?></div>
            <div class="stat-value sm"><?= money($stats['total_repaid'], $currency) ?></div>
            <div class="stat-sub">
                <span><?= t('adash_expenses') ?>: <?= money($stats['total_expenses'], $currency) ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h6><i class="bi bi-bar-chart me-2" style="color:var(--green)"></i><?= t('adash_collections') ?></h6>
                <span class="badge bg-secondary"><?= t('report_select_month') ?> 6</span>
            </div>
            <div class="card-body" style="padding:1.25rem">
                <canvas id="contributionsChart" height="95"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <h6><i class="bi bi-pie-chart me-2" style="color:var(--green)"></i><?= t('adash_net_funds') ?></h6>
            </div>
            <div class="card-body d-flex flex-column align-items-center justify-content-center" style="padding:1.25rem">
                <canvas id="fundPieChart" style="max-height:200px"></canvas>
                <!-- Legend -->
                <div class="d-flex gap-3 mt-3 flex-wrap justify-content-center" style="font-size:.75rem">
                    <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--green);margin-right:4px"></span><?= t('adash_total_savings') ?></span>
                    <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--amber);margin-right:4px"></span><?= t('adash_loans_out') ?></span>
                    <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--red);margin-right:4px"></span><?= t('adash_expenses') ?></span>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════
     AI INSIGHTS PANEL — Redesigned
     ═══════════════════════════════════════════ -->

<style>
/* ── Insights Panel ──────────────────────────────────────────────── */
#insightsPanelWrap {
    background: linear-gradient(135deg, rgba(0,196,113,.04) 0%, rgba(59,130,246,.04) 100%);
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 20px;
    padding: 0;
    overflow: hidden;
    margin-bottom: 1.75rem;
}
.insights-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.1rem 1.5rem;
    border-bottom: 1px solid rgba(255,255,255,.06);
    background: rgba(255,255,255,.02);
}
.insights-title-group { display:flex; align-items:center; gap:.75rem; }
.insights-pulse {
    width: 8px; height: 8px; border-radius: 50%; background: #00c471;
    animation: pulse-dot 2s infinite;
    box-shadow: 0 0 0 0 rgba(0,196,113,.4);
}
@keyframes pulse-dot {
    0%   { box-shadow: 0 0 0 0 rgba(0,196,113,.4); }
    70%  { box-shadow: 0 0 0 8px rgba(0,196,113,0); }
    100% { box-shadow: 0 0 0 0 rgba(0,196,113,0); }
}
.insights-label {
    font-family: 'Syne', sans-serif;
    font-weight: 800;
    font-size: .95rem;
    letter-spacing: -.01em;
}
.insights-timestamp {
    font-size: .7rem;
    color: var(--text-muted);
    background: rgba(255,255,255,.05);
    padding: .2rem .6rem;
    border-radius: 99px;
}
.insights-actions { display:flex; gap:.5rem; align-items:center; }

/* ── Grid ── */
#insightsContainer {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0;
}
@media (max-width: 768px) { #insightsContainer { grid-template-columns: 1fr; } }

/* ── Individual insight cell ── */
.ins-cell {
    padding: 1.25rem 1.5rem;
    border-right: 1px solid rgba(255,255,255,.05);
    border-bottom: 1px solid rgba(255,255,255,.05);
    position: relative;
    overflow: hidden;
    transition: background .2s;
}
.ins-cell:hover { background: rgba(255,255,255,.025); }
.ins-cell:nth-child(3n) { border-right: none; }
.ins-cell-wide {
    grid-column: span 2;
}
.ins-cell-full {
    grid-column: 1 / -1;
    border-right: none;
}

/* ── Accent bar top ── */
.ins-cell::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
}
.ins-cell.t-success::before { background: linear-gradient(90deg, #00c471, transparent); }
.ins-cell.t-warning::before { background: linear-gradient(90deg, #f59e0b, transparent); }
.ins-cell.t-danger::before  { background: linear-gradient(90deg, #ef4444, transparent); }
.ins-cell.t-chart::before   { background: linear-gradient(90deg, #3b82f6, transparent); }
.ins-cell.t-score::before   { background: linear-gradient(90deg, #8b5cf6, transparent); }

/* ── Glow bg ── */
.ins-cell.t-success { background: radial-gradient(ellipse at top left, rgba(0,196,113,.05) 0%, transparent 70%); }
.ins-cell.t-warning { background: radial-gradient(ellipse at top left, rgba(245,158,11,.05) 0%, transparent 70%); }
.ins-cell.t-danger  { background: radial-gradient(ellipse at top left, rgba(239,68,68,.05) 0%, transparent 70%); }
.ins-cell.t-chart   { background: radial-gradient(ellipse at top left, rgba(59,130,246,.05) 0%, transparent 70%); }
.ins-cell.t-score   { background: radial-gradient(ellipse at top left, rgba(139,92,246,.05) 0%, transparent 70%); }

/* ── Cell contents ── */
.ins-meta {
    display: flex;
    align-items: center;
    gap: .5rem;
    margin-bottom: .6rem;
}
.ins-badge {
    font-size: .62rem;
    font-weight: 800;
    letter-spacing: .07em;
    text-transform: uppercase;
    padding: .15rem .55rem;
    border-radius: 99px;
}
.t-success .ins-badge { background: rgba(0,196,113,.15); color:#00c471; }
.t-warning .ins-badge { background: rgba(245,158,11,.15); color:#f59e0b; }
.t-danger  .ins-badge { background: rgba(239,68,68,.15);  color:#ef4444; }
.t-chart   .ins-badge { background: rgba(59,130,246,.15); color:#3b82f6; }
.t-score   .ins-badge { background: rgba(139,92,246,.15); color:#8b5cf6; }

.ins-number {
    font-family: 'Syne', sans-serif;
    font-size: 2.2rem;
    font-weight: 800;
    line-height: 1;
    margin-bottom: .3rem;
    letter-spacing: -.04em;
}
.t-success .ins-number { color: #00c471; }
.t-warning .ins-number { color: #f59e0b; }
.t-danger  .ins-number { color: #ef4444; }
.t-chart   .ins-number { color: #3b82f6; }
.t-score   .ins-number { color: #8b5cf6; }

.ins-headline {
    font-size: .82rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: .25rem;
    line-height: 1.3;
}
.ins-sub {
    font-size: .74rem;
    color: var(--text-muted);
    line-height: 1.5;
}
.ins-cta {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    font-size: .72rem;
    font-weight: 700;
    text-decoration: none;
    margin-top: .65rem;
    padding: .3rem .75rem;
    border-radius: 99px;
    border: 1px solid;
    transition: all .15s;
}
.t-success .ins-cta { color:#00c471; border-color:rgba(0,196,113,.3); }
.t-success .ins-cta:hover { background:rgba(0,196,113,.1); }
.t-warning .ins-cta { color:#f59e0b; border-color:rgba(245,158,11,.3); }
.t-warning .ins-cta:hover { background:rgba(245,158,11,.1); }
.t-danger  .ins-cta { color:#ef4444; border-color:rgba(239,68,68,.3); }
.t-danger  .ins-cta:hover { background:rgba(239,68,68,.1); }

/* ── Bar chart ── */
.ins-barchart {
    display: flex;
    align-items: flex-end;
    gap: 6px;
    height: 60px;
    margin-top: .75rem;
}
.ins-bar-col { display:flex; flex-direction:column; align-items:center; flex:1; justify-content:flex-end; }
.ins-bar {
    width: 100%;
    border-radius: 4px 4px 0 0;
    transition: height .6s cubic-bezier(.34,1.56,.64,1);
    position: relative;
}
.ins-bar-lbl { font-size: .58rem; color: var(--text-muted); margin-top: 3px; white-space:nowrap; }

/* ── Health ring ── */
.ins-ring-wrap {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    margin-top: .5rem;
}
.score-ring-lg { width: 72px; height: 72px; flex-shrink: 0; }
.score-ring-stats { display:flex; flex-direction:column; gap:.3rem; }
.score-stat { font-size:.72rem; color:var(--text-muted); }
.score-stat strong { color:var(--text); font-weight:700; }

/* ── Late payers chip list ── */
.ins-chips { display:flex; flex-wrap:wrap; gap:.35rem; margin-top:.6rem; }
.ins-chip {
    font-size:.68rem;
    padding:.2rem .55rem;
    border-radius:99px;
    background:rgba(245,158,11,.1);
    color:#f59e0b;
    border:1px solid rgba(245,158,11,.2);
    white-space: nowrap;
}
.ins-chip.more { background:rgba(255,255,255,.05); color:var(--text-muted); border-color:rgba(255,255,255,.1); }

/* ── Remind btn ── */
.ins-remind-btn {
    display: inline-flex; align-items:center; gap:.3rem;
    font-size:.72rem; font-weight:700;
    background:rgba(245,158,11,.12);
    color:#f59e0b;
    border:1px solid rgba(245,158,11,.25);
    border-radius:99px;
    padding:.28rem .8rem;
    cursor:pointer;
    transition:all .15s;
    margin-top:.65rem;
}
.ins-remind-btn:hover { background:rgba(245,158,11,.22); }

/* ── Trend arrow ── */
.ins-trend-up   { color:#00c471; font-size:.72rem; }
.ins-trend-down { color:#ef4444; font-size:.72rem; }

/* ── Spin ── */
@keyframes spin { to { transform: rotate(360deg); } }
.spin { animation: spin .7s linear infinite; }

/* ── Stagger animation ── */
.ins-cell { opacity:0; transform:translateY(12px); animation: ins-in .4s forwards; }
@keyframes ins-in { to { opacity:1; transform:none; } }
</style>

<div id="insightsPanelWrap" class="mb-4">
    <!-- Header -->
    <div class="insights-header">
        <div class="insights-title-group">
            <div class="insights-pulse"></div>
            <span class="insights-label"><?= t('ai_insights') ?></span>
            <span class="insights-timestamp" id="insightsTime"><?= t('lbl_loading') ?></span>
        </div>
        <div class="insights-actions">
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" style="font-size:.75rem" data-bs-toggle="dropdown">
                    <i class="bi bi-lightning-charge me-1"></i><?= t('ai_bulk_actions') ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="#" onclick="bulkAction('approve_members',[],'<?= t('ai_approve_all') ?>?')">
                        <i class="bi bi-person-check-fill me-2 text-success"></i><?= t('ai_approve_all') ?>
                    </a></li>
                    <li><a class="dropdown-item" href="#" onclick="bulkAction('send_reminders',[],'<?= t('ai_remind_all') ?>?')">
                        <i class="bi bi-bell-fill me-2 text-warning"></i><?= t('ai_remind_all') ?>
                    </a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/members.php">
                        <i class="bi bi-people me-2"></i><?= t('ai_select_members') ?>
                    </a></li>
                </ul>
            </div>
            <button class="btn btn-sm btn-outline-secondary" onclick="loadInsights()" id="refreshInsightsBtn" title="Refresh">
                <i class="bi bi-arrow-clockwise" id="refreshIcon"></i>
            </button>
        </div>
    </div>
    <!-- Content grid -->
    <div id="insightsContainer" style="min-height:180px;display:flex;align-items:center;justify-content:center">
        <div style="text-align:center;color:var(--text-muted);padding:2rem">
            <div style="width:28px;height:28px;border:2px solid rgba(0,196,113,.3);border-top-color:#00c471;border-radius:50%;animation:spin .7s linear infinite;margin:0 auto .75rem"></div>
            <div style="font-size:.8rem"><?= t('ai_loading') ?></div>
        </div>
    </div>
</div>

<script>
var csrfToken = '<?= csrfToken() ?>';
// Translatable strings for AI panel
var aiLang = {
    loading:     <?= json_encode(t('ai_loading')) ?>,
    error:       <?= json_encode(t('ai_error')) ?>,
    retry:       <?= json_encode(t('ai_retry')) ?>,
    network:     <?= json_encode(t('lbl_network_error')) ?>,
    updated:     <?= json_encode(t('ai_updated')) ?>,
    good:        <?= json_encode(t('ai_good')) ?>,
    attention:   <?= json_encode(t('ai_attention')) ?>,
    alert:       <?= json_encode(t('ai_alert')) ?>,
    trend:       <?= json_encode(t('ai_trend')) ?>,
    score:       <?= json_encode(t('ai_score')) ?>,
    collections: <?= json_encode(t('ai_collections')) ?>
};

function loadInsights() {
    var icon = document.getElementById('refreshIcon');
    icon.className = 'bi bi-arrow-clockwise spin';
    document.getElementById('insightsContainer').style.cssText = 'min-height:180px;display:flex;align-items:center;justify-content:center';
    document.getElementById('insightsContainer').innerHTML =
        '<div style="text-align:center;color:var(--text-muted);padding:2rem"><div style="width:28px;height:28px;border:2px solid rgba(0,196,113,.3);border-top-color:#00c471;border-radius:50%;animation:spin .7s linear infinite;margin:0 auto .75rem"></div><div style="font-size:.8rem">' + aiLang.loading + '</div></div>';

    fetch('<?= APP_URL ?>/api/ai_insights.php?_=' + Date.now(), { credentials: 'same-origin' })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        icon.className = 'bi bi-arrow-clockwise';
        document.getElementById('insightsTime').textContent = aiLang.updated + ' ' + data.generated_at;
        if (!data.ok) {
            document.getElementById('insightsContainer').style.cssText = '';
            document.getElementById('insightsContainer').innerHTML =
                '<div style="padding:2rem;text-align:center;color:var(--text-muted);font-size:.82rem"><i class="bi bi-exclamation-circle me-2"></i>' + aiLang.error + ' <a href="" onclick="loadInsights();return false" style="color:var(--green)">' + aiLang.retry + '</a></div>';
            return;
        }
        renderInsights(data.insights);
    })
    .catch(function() {
        icon.className = 'bi bi-arrow-clockwise';
        document.getElementById('insightsContainer').innerHTML =
            '<div style="padding:2rem;text-align:center;color:var(--text-muted);font-size:.82rem"><i class="bi bi-wifi-off me-2"></i>' + aiLang.network + '. <a href="" onclick="loadInsights();return false" style="color:var(--green)">' + aiLang.retry + '</a></div>';
    });
}

function renderInsights(insights) {
    var maxAmt = 0;
    insights.forEach(function(ins) {
        if (ins.chart) maxAmt = Math.max.apply(null, ins.chart.map(function(d){ return d.amount; }).concat([1]));
    });

    var html = '';

    insights.forEach(function(ins, idx) {
        var typeClass = 't-' + (ins.type === 'chart' ? 'chart' : ins.score !== undefined ? 'score' : ins.type);
        var delay = (idx * 60) + 'ms';

        // Determine cell width
        var cellClass = 'ins-cell ' + typeClass;
        if (ins.type === 'chart') cellClass += ' ins-cell-wide';

        html += '<div class="' + cellClass + '" style="animation-delay:' + delay + '">';

        // Badge + icon meta row
        var badgeText = ins.type === 'success' ? aiLang.good : ins.type === 'warning' ? aiLang.attention : ins.type === 'danger' ? aiLang.alert : ins.type === 'chart' ? aiLang.trend : aiLang.score;
        html += '<div class="ins-meta">';
        html += '<span class="ins-badge">' + badgeText + '</span>';
        html += '</div>';

        // Chart card
        if (ins.type === 'chart' && ins.chart) {
            var total = ins.chart.reduce(function(a,d){ return a+d.amount; }, 0);
            html += '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem">';
            // Left: number + headline
            html += '<div>';
            html += '<div class="ins-number">' + (total > 0 ? formatK(total) : '0') + '</div>';
            html += '<div class="ins-headline">' + aiLang.collections + '</div>';
            html += '<div class="ins-sub">' + escHtml(ins.detail) + '</div>';
            html += '</div>';
            // Right: bar chart
            html += '<div style="flex:1;max-width:200px">';
            html += '<div class="ins-barchart">';
            ins.chart.forEach(function(d) {
                var pct = maxAmt > 0 ? Math.max(5, Math.round(d.amount / maxAmt * 100)) : 5;
                var opacity = d.amount > 0 ? 0.3 + (d.amount/maxAmt)*0.7 : 0.15;
                html += '<div class="ins-bar-col">';
                html += '<div class="ins-bar" style="height:' + pct + "%;background:rgba(59,130,246," + opacity + ")" + '" title="' + d.month + ': ' + d.amount.toLocaleString() + '"></div>';
                html += '<div class="ins-bar-lbl">' + d.month + '</div>';
                html += '</div>';
            });
            html += '</div></div></div>';

        // Health score card
        } else if (ins.score !== undefined) {
            var sc = ins.score;
            var scoreColor = sc >= 70 ? '#00c471' : sc >= 50 ? '#f59e0b' : '#ef4444';
            var scoreLabel = sc >= 80 ? 'Excellent' : sc >= 60 ? 'Good' : sc >= 40 ? 'Fair' : 'Needs Work';
            var circumference = 2 * Math.PI * 15.9;
            var dashArr = (sc / 100 * circumference).toFixed(1) + ' ' + circumference.toFixed(1);

            html += '<div class="ins-number" style="color:' + scoreColor + '">' + sc + '<span style="font-size:1rem;font-weight:400;color:var(--text-muted)">/100</span></div>';
            html += '<div class="ins-headline">' + escHtml(ins.title.replace(/Group health score: \d+\/100 — /, '')) + ' — ' + scoreLabel + '</div>';
            html += '<div class="ins-ring-wrap">';
            html += '<svg class="score-ring-lg" viewBox="0 0 36 36">';
            html += '<circle cx="18" cy="18" r="15.9" fill="none" stroke="rgba(255,255,255,.06)" stroke-width="3.5"/>';
            html += '<circle cx="18" cy="18" r="15.9" fill="none" stroke="' + scoreColor + '" stroke-width="3.5" stroke-dasharray="' + dashArr + '" stroke-linecap="round" transform="rotate(-90 18 18)" style="transition:stroke-dasharray .8s ease"/>';
            html += '<text x="18" y="22.5" text-anchor="middle" font-size="8.5" font-weight="800" fill="' + scoreColor + '">' + sc + '</text>';
            html += '</svg>';
            html += '<div class="ins-sub">' + escHtml(ins.detail) + '</div>';
            html += '</div>';

        // Collection rate card
        } else if (ins.icon === 'bi-graph-up-arrow') {
            var rateMatch = ins.title.match(/(\d+)%/);
            var rate = rateMatch ? rateMatch[1] : '0';
            var trendUp = ins.detail.indexOf('&#9650;') >= 0 || ins.detail.indexOf('Up') >= 0;
            html += '<div class="ins-number">' + rate + '<span style="font-size:1.1rem;font-weight:400;color:var(--text-muted)">%</span></div>';
            html += '<div class="ins-headline">' + '<?= addslashes(t("adm_dash_collection")) ?>' + '</div>';
            html += '<div class="ins-sub" style="margin-top:.2rem">';
            html += '<span class="' + (trendUp ? 'ins-trend-up' : 'ins-trend-down') + '">';
            html += (trendUp ? '↑' : '↓') + ' ' + ins.detail.replace(/.*?(Up|Down)\s+(\d+%)/,'$1 $2').split('·')[0].trim();
            html += '</span>';
            html += '<span style="color:var(--text-muted)"> · ' + (ins.detail.split('·')[1]||''). trim() + '</span>';
            html += '</div>';

        // Late payers card
        } else if (ins.data && ins.data.length > 0) {
            var count = ins.data.length;
            html += '<div class="ins-number">' + count + '</div>';
            html += '<div class="ins-headline">Member' + (count>1?'s haven\'t':'hasn\'t') + ' paid this month</div>';
            html += '<div class="ins-chips">';
            var shown = ins.data.slice(0,4);
            shown.forEach(function(m){
                html += '<span class="ins-chip">' + escHtml(m.name.split(' ')[0]) + '</span>';
            });
            if (ins.data.length > 4) html += '<span class="ins-chip more">+' + (ins.data.length-4) + ' more</span>';
            html += '</div>';
            var ids = JSON.stringify(ins.data.map(function(d){ return d.id; }));
            var msg = 'Send reminders to ' + count + ' member' + (count>1?'s':'') + '?';
            html += '<button class="ins-remind-btn" onclick="bulkAction(\'send_reminders\',' + ids + ',\'' + msg + '\')">';
            html += '<i class="bi bi-bell-fill"></i> Remind ' + count + ' member' + (count>1?'s':'') + '</button>';

        // Chronic missers / loan risk — generic big-number card
        } else {
            var numMatch = ins.title.match(/^(\d+)/);
            if (numMatch) {
                html += '<div class="ins-number">' + numMatch[1] + '</div>';
                html += '<div class="ins-headline">' + escHtml(ins.title.replace(/^\d+\s*/,'')) + '</div>';
            } else {
                html += '<div class="ins-headline" style="font-size:1rem;margin-top:.25rem">' + escHtml(ins.title) + '</div>';
            }
            html += '<div class="ins-sub">' + ins.detail + '</div>';
            if (ins.action && ins.action_url) {
                html += '<a href="' + ins.action_url + '" class="ins-cta"><i class="bi bi-arrow-right-circle"></i>' + escHtml(ins.action) + '</a>';
            }
        }

        html += '</div>';
    });

    var container = document.getElementById('insightsContainer');
    container.style.cssText = '';
    container.innerHTML = html;
}

function formatK(n) {
    if (n >= 1000000) return (n/1000000).toFixed(1).replace(/\.0$/,'') + 'M';
    if (n >= 1000)    return (n/1000).toFixed(1).replace(/\.0$/,'') + 'K';
    return n.toLocaleString();
}

function bulkAction(action, ids, confirmMsg) {
    if (!confirm(confirmMsg)) return;
    var fd = new FormData();
    fd.append('action', action);
    fd.append('ids', JSON.stringify(ids));
    fd.append('csrf_token', csrfToken);
    if (window.ChamaToast) window.ChamaToast.show('Processing…', 'Running bulk action…', 'info');
    fetch('<?= APP_URL ?>/api/bulk_action.php', { method:'POST', body:fd, credentials:'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (window.ChamaToast) window.ChamaToast.show(data.ok ? 'Done!' : 'Error', data.msg, data.ok ? 'success' : 'danger');
        if (data.ok) setTimeout(loadInsights, 1500);
    })
    .catch(function(){ alert('Network error'); });
}

function escHtml(str) {
    return String(str||'')        .replace(/&amp;/g,'&').replace(/&/g,'&amp;')        .replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

document.addEventListener('DOMContentLoaded', function() {
    setTimeout(loadInsights, 800);
});
// Add spin style
var s = document.createElement('style');
s.textContent = '.spin { animation: spin .8s linear infinite; } @keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(s);
</script>

<!-- Recent Tables -->
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h6><i class="bi bi-piggy-bank me-2" style="color:var(--green)"></i><?= t('adash_recent_contribs') ?></h6>
                <a href="<?= APP_URL ?>/admin/contributions.php" class="btn btn-outline-primary btn-sm"><?= t('nav_view_all_notif') ?></a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th><?= t('lbl_member') ?></th><th><?= t('lbl_month') ?></th><th><?= t('lbl_amount') ?></th><th><?= t('lbl_status') ?></th></tr></thead>
                    <tbody>
                    <?php if (empty($recentContribs)): ?>
                        <tr><td colspan="4" class="text-center py-4" style="color:var(--text-muted)"><?= t('adm_dash_no_contrib') ?></td></tr>
                    <?php else: foreach ($recentContribs as $c): ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;font-size:.855rem"><?= htmlspecialchars($c['full_name']) ?></div>
                                <div style="font-size:.72rem;color:var(--text-muted)"><?= htmlspecialchars($c['membership_number'] ?? '') ?></div>
                            </td>
                            <td style="font-size:.83rem"><?= monthLabel($c['payment_month']) ?></td>
                            <td style="font-weight:600;color:var(--green)"><?= money($c['amount'], $currency) ?></td>
                            <td><?= badgeStatus($c['status']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h6><i class="bi bi-cash-stack me-2" style="color:var(--amber)"></i><?= t('adm_dash_recent_loans') ?></h6>
                <a href="<?= APP_URL ?>/admin/loans.php" class="btn btn-outline-primary btn-sm"><?= t('nav_view_all_notif') ?></a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th><?= t('lbl_member') ?></th><th><?= t('lbl_amount') ?></th><th>Term</th><th><?= t('lbl_status') ?></th></tr></thead>
                    <tbody>
                    <?php if (empty($recentLoans)): ?>
                        <tr><td colspan="4" class="text-center py-4" style="color:var(--text-muted)"><?= t('adm_dash_no_loans') ?></td></tr>
                    <?php else: foreach ($recentLoans as $l): ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;font-size:.855rem"><?= htmlspecialchars($l['full_name']) ?></div>
                                <div style="font-size:.72rem;color:var(--text-muted)"><?= htmlspecialchars($l['loan_number'] ?? '') ?></div>
                            </td>
                            <td style="font-weight:600"><?= money($l['amount_requested'], $currency) ?></td>
                            <td style="font-size:.83rem;color:var(--text-muted)"><?= $l['duration_months'] ?> mo</td>
                            <td><?= badgeStatus($l['status']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<JS
<script>
const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
Chart.defaults.color = isDark ? '#6b87a8' : '#5a7390';
Chart.defaults.borderColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.07)';
Chart.defaults.font.family = "'DM Sans', sans-serif";

// Bar chart
(function(){
    const labels = {$chartLabels};
    const values = {$chartValues};
    if (!labels.length) {
        document.getElementById('contributionsChart').parentElement.innerHTML =
            '<p class="text-center py-4" style="color:var(--text-muted)">' + '<?= addslashes(t("adm_dash_no_contribs")) ?>' + '</p>';
        return;
    }
    new Chart(document.getElementById('contributionsChart'), {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: 'rgba(0,196,113,0.65)',
                hoverBackgroundColor: 'rgba(0,196,113,0.9)',
                borderRadius: 7,
                borderSkipped: false,
            }]
        },
        options: {
            responsive:true,
            plugins:{ legend:{ display:false }, tooltip:{
                backgroundColor:'rgba(13,31,56,0.95)',
                borderColor:'rgba(255,255,255,0.1)',
                borderWidth:1,
                padding:12,
                callbacks:{ label: ctx => ' {$currency} ' + ctx.parsed.y.toLocaleString() }
            }},
            scales:{
                x:{ grid:{ display:false }, ticks:{ font:{size:11} } },
                y:{ beginAtZero:true, grid:{ color:'rgba(255,255,255,0.05)' }, ticks:{ callback: v => '{$currency} ' + v.toLocaleString(), font:{size:11} } }
            }
        }
    });
})();

// Doughnut
new Chart(document.getElementById('fundPieChart'), {
    type: 'doughnut',
    data: {
        labels: ['Savings','Outstanding','Expenses'],
        datasets:[{
            data:[{$jsSavings},{$jsOutstanding},{$jsExpenses}],
            backgroundColor:['rgba(0,196,113,0.8)','rgba(245,158,11,0.8)','rgba(239,68,68,0.8)'],
            borderWidth:0,
            hoverOffset:8
        }]
    },
    options:{
        responsive:true,
        cutout:'70%',
        plugins:{
            legend:{ display:false },
            tooltip:{
                backgroundColor:'rgba(13,31,56,0.95)',
                borderColor:'rgba(255,255,255,0.1)',
                borderWidth:1,
                padding:12,
                callbacks:{ label: ctx => ' {$currency} ' + ctx.parsed.toLocaleString() }
            }
        }
    }
});
</script>
JS;
require_once ROOT . '/includes/footer.php';
?>
