<?php
/**
 * SmartChama — Shared Dashboard Header
 * FIXED: Language switcher was incorrectly nested inside the notification <button>,
 *        causing EN/SW to collide with the bell icon. Moved to its own sibling element.
 *        All sidebar labels now use t() for full bilingual support.
 *        Theme toggle script added inline so it always runs.
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';

startSession();
sendSecurityHeaders();

if (!isLoggedIn()) {
    header('Location: ' . APP_URL . '/index.php?msg=login_required');
    exit;
}

$user = currentUser();
if (!$user) {
    session_destroy();
    header('Location: ' . APP_URL . '/index.php?msg=login_required');
    exit;
}

$unread      = countUnreadNotifications((int)$user['id']);
$isAdmin     = $user['role'] === 'admin';
$baseUrl     = $isAdmin ? APP_URL . '/admin' : APP_URL . '/member';
$currentLang = getLang();

function isActivePage(string $keyword): string {
    return str_contains($_SERVER['PHP_SELF'], $keyword) ? 'active' : '';
}
function getPendingMembersCount(): int {
    return (int)getDB()->query("SELECT COUNT(*) FROM users WHERE role='member' AND status='pending'")->fetchColumn();
}

$initials = implode('', array_map(fn($p) => strtoupper($p[0]), array_slice(explode(' ', trim($user['full_name'])), 0, 2)));
?>
<!DOCTYPE html>
<html lang="<?= $currentLang === 'sw' ? 'sw' : 'en' ?>" data-theme="dark" id="htmlRoot">
<head>
    <script>
    /* Apply saved theme before CSS loads to prevent flash */
    (function(){
        var t    = localStorage.getItem('cl_theme') || 'dark';
        var root = document.documentElement;
        root.setAttribute('data-theme', t);
        root.classList.add('no-transition');
        window.addEventListener('load', function(){
            setTimeout(function(){ root.classList.remove('no-transition'); }, 50);
        });
    })();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">
</head>
<body data-app-url="<?= APP_URL ?>">

<!-- ── TOP NAVBAR ── -->
<nav class="navbar sticky-top">
    <div class="container-fluid px-3 gap-2">

        <!-- Mobile sidebar toggle -->
        <button class="nav-btn d-lg-none" id="sidebarToggle" style="padding:.42rem .6rem">
            <i class="bi bi-list fs-5"></i>
        </button>

        <!-- Brand / Logo -->
        <a class="navbar-brand me-auto" href="<?= $baseUrl ?>/dashboard.php">
            <div class="navbar-brand-icon"><i class="bi bi-bank2"></i></div>
            Smart <span style="color:var(--green)">Chama</span>
        </a>

        <!-- Right controls — all siblings at the same flex level -->
        <div class="d-flex align-items-center gap-2 flex-shrink-0">

            <!-- Theme toggle -->
            <button class="theme-toggle" id="themeToggle"
                    title="<?= t('lbl_toggle_theme') ?>">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>

            <!-- Language switcher — standalone element, NOT inside any other button -->
            <div class="lang-switcher d-flex align-items-center gap-1"
                 title="<?= t('lang_switch_msg') ?>">
                <a href="<?= APP_URL ?>/api/set_lang.php?lang=en"
                   class="lang-btn<?= $currentLang === 'en' ? ' lang-active' : '' ?>"
                   title="English">🇬🇧 EN</a>
                <a href="<?= APP_URL ?>/api/set_lang.php?lang=sw"
                   class="lang-btn<?= $currentLang === 'sw' ? ' lang-active' : '' ?>"
                   title="Kiswahili">🇰🇪 SW</a>
            </div>

            <!-- Notifications bell -->
            <div class="dropdown">
                <button class="nav-btn position-relative" data-bs-toggle="dropdown" id="notifBtn"
                        aria-label="<?= t('nav_notifications') ?>">
                    <i class="bi bi-bell" id="notifBellIcon"></i>
                    <span class="badge-dot" id="notifBadge"
                          style="<?= $unread === 0 ? 'display:none' : '' ?>">
                        <?= $unread > 9 ? '9+' : ($unread > 0 ? $unread : '') ?>
                    </span>
                </button>
                <div class="dropdown-menu dropdown-menu-end" id="notifDropdown" style="min-width:340px">
                    <div class="dropdown-header d-flex justify-content-between align-items-center">
                        <span><?= t('nav_notifications') ?></span>
                        <a href="#" class="small" style="color:var(--green)" id="markAllRead">
                            <?= t('mark_all_read') ?>
                        </a>
                    </div>
                    <div id="notifList">
                        <div class="px-3 py-3 text-muted small text-center">
                            <span class="spinner-border spinner-border-sm me-1"></span>
                            <?= t('lbl_loading') ?>
                        </div>
                    </div>
                    <div class="dropdown-divider"></div>
                    <a href="<?= $baseUrl ?>/notifications.php"
                       class="dropdown-item text-center small"
                       style="color:var(--text-muted)">
                        <?= t('nav_view_all_notif') ?>
                    </a>
                </div>
            </div>

            <!-- User menu -->
            <div class="dropdown">
                <button class="nav-btn" data-bs-toggle="dropdown" style="gap:.55rem">
                    <?php
                    $hPhoto    = $user['profile_photo'] ?? null;
                    $photoPath = $hPhoto ? ROOT . '/uploads/avatars/' . $hPhoto : null;
                    ?>
                    <?php if ($hPhoto && file_exists($photoPath ?? '')): ?>
                    <img src="<?= APP_URL ?>/uploads/avatars/<?= htmlspecialchars($hPhoto) ?>"
                         style="width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid var(--green)" alt="">
                    <?php else: ?>
                    <span style="width:26px;height:26px;background:linear-gradient(135deg,var(--green),#009954);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:700;color:#060e1a;flex-shrink:0">
                        <?= $initials ?>
                    </span>
                    <?php endif; ?>
                    <span class="d-none d-md-inline" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?>
                    </span>
                    <i class="bi bi-chevron-down" style="font-size:.65rem;opacity:.6"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" style="min-width:210px">
                    <li class="px-3 py-2 border-bottom" style="border-color:rgba(255,255,255,0.07)!important">
                        <div style="font-size:.82rem;font-weight:600;color:var(--text)"><?= htmlspecialchars($user['full_name']) ?></div>
                        <div style="font-size:.73rem;color:var(--text-muted)"><?= htmlspecialchars($user['email']) ?></div>
                        <span class="badge bg-<?= $isAdmin ? 'success' : 'info' ?> mt-1"><?= $isAdmin ? t('lbl_admin_role') : t('lbl_member_role') ?></span>
                    </li>
                    <li class="pt-1">
                        <a class="dropdown-item" href="<?= $baseUrl ?>/profile.php">
                            <i class="bi bi-person me-2" style="color:var(--text-muted)"></i>
                            <?= t('nav_profile') ?>
                        </a>
                    </li>
                    <?php if ($isAdmin): ?>
                    <li>
                        <a class="dropdown-item" href="<?= APP_URL ?>/admin/settings.php">
                            <i class="bi bi-gear me-2" style="color:var(--text-muted)"></i>
                            <?= t('nav_settings') ?>
                        </a>
                    </li>
                    <?php endif; ?>
                    <li><div class="dropdown-divider"></div></li>
                    <li>
                        <a class="dropdown-item text-danger" href="<?= APP_URL ?>/logout.php">
                            <i class="bi bi-box-arrow-right me-2"></i>
                            <?= t('nav_logout') ?>
                        </a>
                    </li>
                </ul>
            </div>

        </div><!-- /.d-flex right controls -->
    </div><!-- /.container-fluid -->
</nav>

<div class="d-flex layout-wrap">

<!-- ── SIDEBAR ── -->
<nav class="sidebar" id="sidebar">
    <div class="sidebar-sticky">

        <?php
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        function navLink($url, $icon, $label, $badge = '') {
            global $uri;
            $path   = parse_url($url, PHP_URL_PATH);
            $active = ($path && str_contains($uri, $path)) ? 'active' : '';
            $b = $badge ? '<span class="ms-auto">' . $badge . '</span>' : '';
            return '<a href="' . $url . '" class="sidebar-link ' . $active . '">'
                 . '<i class="bi ' . $icon . '"></i>'
                 . '<span>' . htmlspecialchars($label) . '</span>'
                 . $b . '</a>';
        }
        ?>

        <?php if ($isAdmin): ?>
        <!-- ── ADMIN NAV ── -->
        <div class="sidebar-label"><?= t('nav_overview') ?></div>
        <?= navLink(APP_URL.'/admin/dashboard.php',      'bi-grid-1x2',       t('nav_dashboard')) ?>

        <div class="sidebar-label"><?= t('nav_people') ?></div>
        <?= navLink(APP_URL.'/admin/members.php',               'bi-people-fill',   t('nav_members')) ?>
        <?= navLink(APP_URL.'/admin/import_contributions.php',  'bi-clock-history', t('nav_import')) ?>
        <?= navLink(APP_URL.'/admin/reset_member_pw.php',       'bi-key',           t('nav_reset_passwords')) ?>
        <?php $pendingMembers = getPendingMembersCount(); ?>
        <?= navLink(APP_URL.'/admin/approvals.php', 'bi-person-check', t('nav_approvals'), $pendingMembers ?: '') ?>

        <div class="sidebar-label"><?= t('nav_money') ?></div>
        <?php
        try {
            $_db = getDB();
            $pendingContribs = (int)$_db->query("SELECT COUNT(*) FROM contributions WHERE status='pending'")->fetchColumn();
            $pendingLoans    = (int)$_db->query("SELECT COUNT(*) FROM loans WHERE status='pending'")->fetchColumn();
        } catch (Exception $__e) { $pendingContribs = 0; $pendingLoans = 0; }
        ?>
        <?= navLink(APP_URL.'/admin/contributions.php', 'bi-piggy-bank-fill', t('nav_contributions'),
            $pendingContribs ? '<span class="badge bg-warning text-dark rounded-pill" style="font-size:.65rem">'.$pendingContribs.'</span>' : '') ?>
        <?= navLink(APP_URL.'/admin/loans.php', 'bi-cash-stack', t('nav_loans'),
            $pendingLoans ? '<span class="badge bg-warning text-dark rounded-pill" style="font-size:.65rem">'.$pendingLoans.'</span>' : '') ?>
        <?= navLink(APP_URL.'/admin/expenses.php',   'bi-receipt-cutoff', t('nav_expenses')) ?>

        <div class="sidebar-label"><?= t('nav_analysis') ?></div>
        <?= navLink(APP_URL.'/admin/wallet.php',                      'bi-wallet2',          t('nav_wallet')) ?>
        <?= navLink(APP_URL.'/admin/monthly_report.php',              'bi-table',            t('nav_monthly_report')) ?>
        <?= navLink(APP_URL.'/admin/contribution_report.php',         'bi-bar-chart-line',   t('nav_contribution_report')) ?>
        <?= navLink(APP_URL.'/admin/actual_contributions_report.php', 'bi-bar-chart-steps',  t('nav_actual_report')) ?>
        <?= navLink(APP_URL.'/admin/monthly_ledger_report.php',       'bi-journal-richtext', t('nav_ledger_report')) ?>
        <?= navLink(APP_URL.'/admin/chama_ledger_report.php',         'bi-journal-text',     t('report_ledger')) ?>
        <?= navLink(APP_URL.'/admin/reports.php',                     'bi-graph-up',         t('nav_reports')) ?>
        <?= navLink(APP_URL.'/admin/annual_report.php',               'bi-graph-up-arrow',   t('nav_annual_report')) ?>

        <div class="sidebar-label"><?= t('nav_tools') ?></div>
        <?= navLink(APP_URL.'/admin/events.php',        'bi-calendar-event-fill', t('nav_events')) ?>
        <?= navLink(APP_URL.'/admin/meetings.php',      'bi-journal-text',        t('nav_meetings')) ?>
        <?= navLink(APP_URL.'/admin/fines.php',         'bi-exclamation-octagon', t('nav_fines')) ?>
        <?= navLink(APP_URL.'/admin/announcements.php', 'bi-megaphone-fill',      t('nav_announcements')) ?>
        <?= navLink(APP_URL.'/admin/sms_reminders.php', 'bi-chat-dots-fill',      t('nav_sms_reminders')) ?>
        <?= navLink(APP_URL.'/admin/dividends.php',     'bi-gift-fill',           t('nav_dividends')) ?>
        <?= navLink(APP_URL.'/admin/audit.php',         'bi-shield-check',        t('nav_audit_trail')) ?>
        <?= navLink(APP_URL.'/admin/settings.php',      'bi-sliders',             t('nav_settings')) ?>

        <?php else: ?>
        <!-- ── MEMBER NAV ── -->
        <div class="sidebar-label"><?= t('nav_money') ?></div>
        <?= navLink(APP_URL.'/member/dashboard.php',    'bi-grid-1x2',           t('nav_dashboard')) ?>
        <?= navLink(APP_URL.'/member/contributions.php','bi-piggy-bank-fill',    t('nav_contributions')) ?>
        <?= navLink(APP_URL.'/member/my_loans.php',     'bi-clipboard-check',    t('nav_my_loans')) ?>
        <?= navLink(APP_URL.'/member/loan_apply.php',   'bi-cash-stack',         t('nav_loan_apply')) ?>
        <?= navLink(APP_URL.'/member/history.php',      'bi-clock-history',      t('nav_history')) ?>
        <?= navLink(APP_URL.'/member/statement.php',    'bi-file-earmark-text',  t('nav_statement')) ?>
        <?= navLink(APP_URL.'/member/fines.php',        'bi-exclamation-octagon',t('nav_fines')) ?>

        <div class="sidebar-label"><?= t('nav_group') ?></div>
        <?= navLink(APP_URL.'/member/members.php',       'bi-people-fill',        t('nav_members')) ?>
        <?= navLink(APP_URL.'/member/events.php',        'bi-calendar-event-fill',t('nav_events')) ?>
        <?= navLink(APP_URL.'/member/meetings.php',      'bi-journal-text',       t('nav_meetings')) ?>
        <?= navLink(APP_URL.'/member/announcements.php', 'bi-chat-dots-fill',     t('nav_announcements')) ?>
        <?= navLink(APP_URL.'/member/dividends.php',     'bi-gift-fill',          t('nav_dividends')) ?>
        <?= navLink(APP_URL.'/member/profile.php',       'bi-person-gear',        t('nav_profile')) ?>

        <?php endif; ?>

        <!-- Logout at bottom of sidebar -->
        <div style="margin-top:2rem;padding-top:1rem;border-top:1px solid rgba(255,255,255,0.06)">
            <a href="<?= APP_URL ?>/logout.php" class="sidebar-link" style="color:var(--red-400,#f87171)">
                <i class="bi bi-box-arrow-right"></i>
                <span><?= t('nav_logout') ?></span>
            </a>
        </div>

    </div><!-- /.sidebar-sticky -->
</nav><!-- /.sidebar -->


<!-- ═══════════════════════════════════════════════════════════════
     LANGUAGE SWITCHER & TOAST SYSTEM STYLES
     ═══════════════════════════════════════════════════════════════ -->
<style>
  /* ── Language Switcher ── */
  .lang-switcher { flex-shrink: 0; }
  .lang-btn {
    display: inline-flex;
    align-items: center;
    text-decoration: none;
    color: rgba(255,255,255,0.65);
    font-size: 11px;
    font-weight: 700;
    padding: 4px 9px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.2);
    transition: all 0.18s;
    letter-spacing: 0.4px;
    white-space: nowrap;
    line-height: 1;
  }
  .lang-btn:hover {
    color: #fff;
    background: rgba(255,255,255,0.18);
    border-color: rgba(255,255,255,0.4);
    text-decoration: none;
  }
  .lang-active {
    color: #fff !important;
    background: rgba(255,255,255,0.22) !important;
    border-color: rgba(255,255,255,0.5) !important;
  }
  /* Light mode overrides */
  [data-theme="light"] .lang-btn {
    color: rgba(26,41,64,0.7);
    border-color: rgba(26,41,64,0.2);
  }
  [data-theme="light"] .lang-btn:hover {
    color: var(--text);
    background: rgba(0,0,0,0.08);
    border-color: rgba(26,41,64,0.4);
  }
  [data-theme="light"] .lang-active {
    color: var(--text) !important;
    background: rgba(0,0,0,0.12) !important;
    border-color: rgba(26,41,64,0.5) !important;
  }

  /* ── Notification Badge — Red glowing dot ── */
  .badge-dot {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #ef4444;
    color: #fff;
    font-weight: 800;
    font-size: 0.6rem;
    line-height: 1;
    border-radius: 50%;
    min-width: 18px;
    height: 18px;
    padding: 0 3px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid var(--sidebar-bg, #0d1f38);
    /* Pulsing red glow */
    box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
    animation: notif-glow 2s ease-in-out infinite;
    transition: transform 0.2s ease;
  }
  @keyframes notif-glow {
    0%   { box-shadow: 0 0 0 0   rgba(239,68,68,0.8); }
    50%  { box-shadow: 0 0 0 6px rgba(239,68,68,0);   }
    100% { box-shadow: 0 0 0 0   rgba(239,68,68,0);   }
  }
  /* Bell wobble when new notification arrives */
  @keyframes notif-bell-ring {
    0%,100% { transform: rotate(0deg);   }
    10%     { transform: rotate(14deg);  }
    20%     { transform: rotate(-12deg); }
    30%     { transform: rotate(10deg);  }
    40%     { transform: rotate(-8deg);  }
    50%     { transform: rotate(5deg);   }
    60%     { transform: rotate(-3deg);  }
  }
  .bell-ring {
    animation: notif-bell-ring 0.7s ease !important;
    transform-origin: top center;
  }
  /* Badge pop-in when count updates */
  @keyframes badge-pop {
    0%   { transform: scale(0.5); opacity:0; }
    70%  { transform: scale(1.3); }
    100% { transform: scale(1);   opacity:1; }
  }
  .badge-pop {
    animation: badge-pop 0.35s cubic-bezier(0.34,1.56,0.64,1) !important;
  }
  /* Light-mode border match */
  [data-theme="light"] .badge-dot {
    border-color: #f0f4f8;
  }

  /* ── Toast container ── */
  #cl-toast-container {
    position: fixed;
    top: 1.25rem; right: 1.25rem;
    z-index: 99999;
    display: flex; flex-direction: column; gap: .65rem;
    pointer-events: none;
    max-width: 360px;
    width: calc(100vw - 2.5rem);
  }
  .cl-toast {
    background: #0d1f38;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 14px;
    padding: 1rem 1.1rem;
    display: flex; align-items: flex-start; gap: .85rem;
    box-shadow: 0 8px 32px rgba(0,0,0,.5), 0 2px 8px rgba(0,0,0,.3);
    pointer-events: all; cursor: pointer;
    transform: translateX(calc(100% + 2rem));
    opacity: 0;
    transition: transform .4s cubic-bezier(.34,1.56,.64,1), opacity .35s ease;
    position: relative; overflow: hidden;
  }
  .cl-toast.show { transform: translateX(0); opacity: 1; }
  .cl-toast.hide { transform: translateX(calc(100% + 2rem)); opacity: 0; }
  .cl-toast::before {
    content:''; position:absolute; left:0; top:0; bottom:0;
    width:4px; border-radius:14px 0 0 14px;
  }
  .cl-toast.t-success::before { background:#00c471; }
  .cl-toast.t-info::before    { background:#3b82f6; }
  .cl-toast.t-warning::before { background:#f59e0b; }
  .cl-toast.t-danger::before  { background:#ef4444; }
  .cl-toast-progress {
    position:absolute; bottom:0; left:0;
    height:3px; border-radius:0 0 14px 14px;
    animation: cl-progress 5s linear forwards;
  }
  .cl-toast.t-success .cl-toast-progress { background:#00c471; }
  .cl-toast.t-info    .cl-toast-progress { background:#3b82f6; }
  .cl-toast.t-warning .cl-toast-progress { background:#f59e0b; }
  .cl-toast.t-danger  .cl-toast-progress { background:#ef4444; }
  @keyframes cl-progress { from{width:100%} to{width:0%} }
  .cl-toast-icon {
    width:38px; height:38px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:1.05rem; flex-shrink:0;
  }
  .t-success .cl-toast-icon { background:rgba(0,196,113,.15);  color:#00c471; }
  .t-info    .cl-toast-icon { background:rgba(59,130,246,.15); color:#3b82f6; }
  .t-warning .cl-toast-icon { background:rgba(245,158,11,.15); color:#f59e0b; }
  .t-danger  .cl-toast-icon { background:rgba(239,68,68,.15);  color:#ef4444; }
  .cl-toast-body { flex:1; min-width:0; }
  .cl-toast-title {
    font-weight:700; font-size:.88rem; color:#e8f0f8; margin-bottom:.2rem;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
  }
  .cl-toast-msg {
    font-size:.8rem; color:#7a94b0; line-height:1.45;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
  }
  .cl-toast-time  { font-size:.7rem; color:#4a6480; margin-top:.3rem; }
  .cl-toast-close {
    background:none; border:none; color:#4a6480; cursor:pointer;
    font-size:.9rem; padding:0; flex-shrink:0; transition:color .2s; line-height:1; margin-top:2px;
  }
  .cl-toast-close:hover { color:#e8f0f8; }

  /* Compact lang on very small screens */
  @media (max-width:400px) {
    .lang-btn { font-size:10px; padding:3px 5px; }
  }
</style>

<!-- Toast container (populated by JS) -->
<div id="cl-toast-container"></div>

<!-- ── Theme toggle script ── -->
<script>
(function(){
    var btn  = document.getElementById('themeToggle');
    var icon = document.getElementById('themeIcon');
    var root = document.documentElement;
    function applyTheme(t) {
        root.setAttribute('data-theme', t);
        localStorage.setItem('cl_theme', t);
        if (icon) icon.className = t === 'dark' ? 'bi bi-moon-stars-fill' : 'bi bi-sun-fill';
    }
    applyTheme(localStorage.getItem('cl_theme') || 'dark');
    if (btn) {
        btn.addEventListener('click', function() {
            applyTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
        });
    }
})();
</script>

<!-- ── Live notification polling ── -->
<script>
(function() {
    'use strict';
    var POLL_INTERVAL = 15000;
    var API_URL       = '<?= APP_URL ?>/api/notifications_poll.php';
    var lastPollTime  = new Date(Date.now() - 60000).toISOString().slice(0,19).replace('T',' ');
    var initialized   = false;

    function playChime(type) {
        try {
            var ctx   = new (window.AudioContext || window.webkitAudioContext)();
            var notes = {
                success:[523.25,659.25,783.99], info:[440,554.37],
                warning:[349.23,440],           danger:[261.63,311.13]
            };
            var freqs = notes[type] || notes.info;
            var now   = ctx.currentTime;
            freqs.forEach(function(freq, i) {
                var osc = ctx.createOscillator(), gain = ctx.createGain();
                osc.connect(gain); gain.connect(ctx.destination);
                osc.type = 'sine';
                osc.frequency.setValueAtTime(freq, now + i*0.12);
                gain.gain.setValueAtTime(0, now + i*0.12);
                gain.gain.linearRampToValueAtTime(0.18, now + i*0.12 + 0.05);
                gain.gain.exponentialRampToValueAtTime(0.001, now + i*0.12 + 0.45);
                osc.start(now + i*0.12); osc.stop(now + i*0.12 + 0.5);
            });
        } catch(e) {}
    }

    var iconMap = {
        success:'bi bi-check-circle-fill', info:'bi bi-info-circle-fill',
        warning:'bi bi-exclamation-triangle-fill', danger:'bi bi-x-circle-fill'
    };

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function showToast(title, message, type, link) {
        type = type || 'info';
        var container = document.getElementById('cl-toast-container');
        if (!container) return;
        var toast = document.createElement('div');
        toast.className = 'cl-toast t-' + type;
        toast.innerHTML =
            '<div class="cl-toast-icon"><i class="'+(iconMap[type]||iconMap.info)+'"></i></div>'+
            '<div class="cl-toast-body">'+
                '<div class="cl-toast-title">'+escHtml(title)+'</div>'+
                '<div class="cl-toast-msg">'+escHtml(message)+'</div>'+
                '<div class="cl-toast-time">just now</div>'+
            '</div>'+
            '<button class="cl-toast-close" title="Dismiss"><i class="bi bi-x"></i></button>'+
            '<div class="cl-toast-progress"></div>';
        if (link) {
            toast.querySelector('.cl-toast-body').addEventListener('click', function(){ window.location.href = link; });
            toast.querySelector('.cl-toast-body').style.cursor = 'pointer';
        }
        toast.querySelector('.cl-toast-close').addEventListener('click', function(e){ e.stopPropagation(); dismissToast(toast); });
        container.appendChild(toast);
        requestAnimationFrame(function(){ requestAnimationFrame(function(){ toast.classList.add('show'); }); });
        var timer = setTimeout(function(){ dismissToast(toast); }, 5000);
        toast._timer = timer;
        toast.addEventListener('mouseenter', function(){
            clearTimeout(toast._timer);
            toast.querySelector('.cl-toast-progress').style.animationPlayState = 'paused';
        });
        toast.addEventListener('mouseleave', function(){
            toast.querySelector('.cl-toast-progress').style.animationPlayState = 'running';
            toast._timer = setTimeout(function(){ dismissToast(toast); }, 2500);
        });
    }

    function dismissToast(toast) {
        toast.classList.remove('show'); toast.classList.add('hide');
        setTimeout(function(){ if (toast.parentNode) toast.parentNode.removeChild(toast); }, 420);
    }

    function updateBadge(count, animate) {
        var badge = document.getElementById('notifBadge');
        var bell  = document.getElementById('notifBellIcon');
        if (!badge) return;
        if (count > 0) {
            var label = count > 9 ? '9+' : String(count);
            var changed = badge.textContent.trim() !== label;
            badge.textContent = label;
            badge.style.display = '';
            if (animate && changed) {
                badge.classList.remove('badge-pop');
                void badge.offsetWidth; // reflow to restart animation
                badge.classList.add('badge-pop');
                setTimeout(function(){ badge.classList.remove('badge-pop'); }, 400);
                if (bell) {
                    bell.classList.remove('bell-ring');
                    void bell.offsetWidth;
                    bell.classList.add('bell-ring');
                    setTimeout(function(){ bell.classList.remove('bell-ring'); }, 750);
                }
            }
        } else {
            badge.style.display = 'none';
        }
    }

    function browserNotify(title, body) {
        if (!("Notification" in window)) return;
        if (Notification.permission === 'granted') {
            new Notification(title, { body: body, icon: '<?= APP_URL ?>/assets/img/icon.png' });
        } else if (Notification.permission !== 'denied') {
            Notification.requestPermission();
        }
    }

    function poll() {
        var url = API_URL + '?since=' + encodeURIComponent(lastPollTime) + '&_=' + Date.now();
        fetch(url, { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (!data.ok) return;
                if (data.server_time) lastPollTime = data.server_time;
                var hasNew = data.notifications && data.notifications.length > 0;
                updateBadge(data.unread || 0, hasNew);
                if (hasNew) {
                    playChime(data.notifications[0].type || 'info');
                    data.notifications.forEach(function(n, i){
                        setTimeout(function(){
                            showToast(n.title, n.message, n.type, n.link);
                            if (!initialized) return;
                            browserNotify(n.title, n.message);
                        }, i * 300);
                    });
                }
                initialized = true;
            })
            .catch(function(){});
    }

    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function(){ poll(); initialized = true; }, 2000);
        setInterval(poll, POLL_INTERVAL);
        document.body.addEventListener('click', function reqPerm(){
            if ("Notification" in window && Notification.permission === 'default') Notification.requestPermission();
            document.body.removeEventListener('click', reqPerm);
        }, { once: true });
    });

    window.ChamaToast = { show: showToast, chime: playChime };
})();
</script>

<!-- ── MAIN CONTENT ── -->
<main class="main-content" id="mainContent">
    <?php renderFlash(); ?>
