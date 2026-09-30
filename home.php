<?php
// Bypass ngrok browser warning
@header('ngrok-skip-browser-warning: true');
if (!defined('ROOT')) define('ROOT', __DIR__);
require_once ROOT . '/config/db.php';

// If already logged in, skip home and go straight to dashboard
if (session_status() === PHP_SESSION_NONE) session_start();
if (!defined('ROOT')) define('ROOT', __DIR__);
require_once ROOT . '/includes/functions.php';
if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'member';
    header('Location: ' . APP_URL . ($role === 'admin' ? '/admin/dashboard.php' : '/member/dashboard.php'));
    exit;
}

// Pull live stats if DB is available (fail silently)
$stats = ['members' => '—', 'savings' => '—', 'loans' => '—'];
try {
    $pdo = getDB();
    $stats['members'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND status='active'")->fetchColumn();
    $raw = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status='confirmed'")->fetchColumn();
    $stats['savings'] = $raw >= 1000000 ? number_format($raw/1000000,1).'M' : number_format($raw/1000,0).'K';
    $stats['loans'] = (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status IN ('approved','disbursed','completed')")->fetchColumn();
} catch(Exception $e) {}

$groupName = defined('APP_NAME') ? APP_NAME : 'SmartChama';
?>
<!DOCTYPE html>
<html lang="<?= getLang() === 'sw' ? 'sw' : 'en' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($groupName) ?></title>
<meta name="description" content="Manage your chama contributions, loans, and savings with ease.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ═══════════════════════════════════════════════════════
   SMARTCHAMA — HOME PAGE
   Dark navy / electric green / warm white
   ═══════════════════════════════════════════════════════ */
:root {
    --green:       #00c471;
    --green-dim:   rgba(0,196,113,.12);
    --green-glow:  rgba(0,196,113,.25);
    --navy:        #040d18;
    --navy2:       #071628;
    --navy3:       #0b2140;
    --text:        #e8f0f8;
    --text-muted:  #7a94b0;
    --border:      rgba(255,255,255,.07);
    --card-bg:     rgba(11,33,64,.6);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
    font-family: 'DM Sans', sans-serif;
    background: var(--navy);
    color: var(--text);
    overflow-x: hidden;
    line-height: 1.6;
}

/* ── NOISE OVERLAY ── */
body::before {
    content: '';
    position: fixed; inset: 0; z-index: 0;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.035'/%3E%3C/svg%3E");
    pointer-events: none;
}

/* ── NAV ── */
nav {
    position: fixed; top: 0; left: 0; right: 0; z-index: 100;
    padding: 1.1rem 2.5rem;
    display: flex; align-items: center; justify-content: space-between;
    background: rgba(4,13,24,.85);
    backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--border);
    transition: padding .3s;
}
.nav-logo {
    display: flex; align-items: center; gap: .65rem;
    text-decoration: none;
}
.nav-logo-mark {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, var(--green), #009954);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    box-shadow: 0 0 20px var(--green-glow);
}
.nav-logo-text {
    font-family: 'Syne', sans-serif;
    font-weight: 800; font-size: 1.2rem;
    color: var(--text); letter-spacing: -.02em;
}
.nav-logo-text span { color: var(--green); }
.nav-links { display: flex; align-items: center; gap: .35rem; }
.nav-link {
    padding: .45rem .9rem; border-radius: 8px;
    color: var(--text-muted); text-decoration: none;
    font-size: .88rem; font-weight: 500;
    transition: all .2s;
}
.nav-link:hover { color: var(--text); background: var(--border); }
.nav-cta {
    padding: .5rem 1.25rem;
    background: var(--green); color: #040d18;
    border-radius: 8px; text-decoration: none;
    font-weight: 700; font-size: .88rem;
    transition: all .2s;
    box-shadow: 0 0 20px rgba(0,196,113,.2);
}
.nav-cta:hover { background: #00dc7e; transform: translateY(-1px); }
.nav-hamburger { display: none; background: none; border: none; color: var(--text); font-size: 1.4rem; cursor: pointer; }

/* ── HERO ── */
.hero {
    min-height: 100vh;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    text-align: center;
    padding: 7rem 1.5rem 4rem;
    position: relative;
}
/* Radial glow blobs */
.hero::before {
    content: '';
    position: absolute;
    width: 700px; height: 700px;
    background: radial-gradient(circle, rgba(0,196,113,.12) 0%, transparent 65%);
    top: 50%; left: 50%; transform: translate(-50%, -55%);
    pointer-events: none;
    animation: pulse 8s ease-in-out infinite;
}
.hero::after {
    content: '';
    position: absolute;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(59,130,246,.08) 0%, transparent 65%);
    bottom: 10%; right: 5%;
    pointer-events: none;
    animation: pulse 12s ease-in-out infinite reverse;
}
@keyframes pulse { 0%,100% { transform: translate(-50%,-55%) scale(1); } 50% { transform: translate(-50%,-55%) scale(1.15); } }

.hero-badge {
    display: inline-flex; align-items: center; gap: .5rem;
    background: rgba(0,196,113,.1); border: 1px solid rgba(0,196,113,.25);
    color: var(--green); border-radius: 99px;
    padding: .35rem 1rem; font-size: .78rem; font-weight: 600;
    margin-bottom: 1.75rem;
    animation: fadeDown .6s ease both;
    position: relative; z-index: 1;
}
.hero-badge-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--green);
    animation: blink 2s ease-in-out infinite;
}
@keyframes blink { 0%,100% { opacity: 1; } 50% { opacity: .3; } }

.hero-title {
    font-family: 'Syne', sans-serif;
    font-size: clamp(2.8rem, 7vw, 5.5rem);
    font-weight: 800; line-height: 1.05;
    letter-spacing: -.03em;
    margin-bottom: 1.25rem;
    position: relative; z-index: 1;
    animation: fadeDown .6s ease .1s both;
}
.hero-title .accent {
    color: var(--green);
    position: relative;
}
.hero-title .accent::after {
    content: '';
    position: absolute; bottom: -4px; left: 0; right: 0; height: 3px;
    background: linear-gradient(90deg, var(--green), transparent);
    border-radius: 99px;
}
.hero-title .line2 { display: block; color: var(--text-muted); font-weight: 600; }

.hero-sub {
    font-size: clamp(.95rem, 2vw, 1.15rem);
    color: var(--text-muted); font-weight: 300;
    max-width: 520px; margin: 0 auto 2.5rem;
    position: relative; z-index: 1;
    animation: fadeDown .6s ease .2s both;
}

.hero-actions {
    display: flex; gap: 1rem; flex-wrap: wrap; justify-content: center;
    position: relative; z-index: 1;
    animation: fadeDown .6s ease .3s both;
}
.btn-primary-hero {
    display: inline-flex; align-items: center; gap: .5rem;
    background: var(--green); color: #040d18;
    padding: .85rem 2rem; border-radius: 12px;
    font-weight: 700; font-size: 1rem; text-decoration: none;
    box-shadow: 0 0 40px rgba(0,196,113,.3);
    transition: all .25s;
}
.btn-primary-hero:hover { background: #00dc7e; transform: translateY(-3px); box-shadow: 0 8px 50px rgba(0,196,113,.4); }
.btn-secondary-hero {
    display: inline-flex; align-items: center; gap: .5rem;
    background: rgba(255,255,255,.06); color: var(--text);
    border: 1px solid var(--border);
    padding: .85rem 2rem; border-radius: 12px;
    font-weight: 600; font-size: 1rem; text-decoration: none;
    transition: all .25s; backdrop-filter: blur(8px);
}
.btn-secondary-hero:hover { background: rgba(255,255,255,.1); transform: translateY(-3px); }

/* ── STATS STRIP ── */
.stats-strip {
    position: relative; z-index: 1;
    margin-top: 4rem;
    display: flex; gap: 0; justify-content: center;
    background: rgba(11,33,64,.5);
    border: 1px solid var(--border);
    border-radius: 16px; overflow: hidden;
    backdrop-filter: blur(12px);
    animation: fadeDown .6s ease .45s both;
    max-width: 580px; width: 100%;
}
.stat-item {
    flex: 1; padding: 1.25rem 1rem;
    text-align: center; position: relative;
}
.stat-item + .stat-item::before {
    content: ''; position: absolute; left: 0; top: 20%; bottom: 20%;
    width: 1px; background: var(--border);
}
.stat-num {
    font-family: 'Syne', sans-serif;
    font-size: 1.6rem; font-weight: 800;
    color: var(--green);
    display: block;
}
.stat-label { font-size: .75rem; color: var(--text-muted); font-weight: 400; }

/* ── SCROLL INDICATOR ── */
.scroll-hint {
    position: absolute; bottom: 2rem; left: 50%; transform: translateX(-50%);
    display: flex; flex-direction: column; align-items: center; gap: .4rem;
    color: var(--text-muted); font-size: .72rem;
    animation: bounce 2s ease-in-out infinite, fadeDown .6s ease .6s both;
    z-index: 1;
}
@keyframes bounce { 0%,100% { transform: translateX(-50%) translateY(0); } 50% { transform: translateX(-50%) translateY(6px); } }

/* ── FEATURES ── */
.section { padding: 6rem 1.5rem; position: relative; }
.section-inner { max-width: 1100px; margin: 0 auto; }
.section-label {
    font-size: .75rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .12em;
    color: var(--green); margin-bottom: .75rem;
}
.section-title {
    font-family: 'Syne', sans-serif;
    font-size: clamp(1.8rem, 4vw, 2.8rem);
    font-weight: 800; letter-spacing: -.02em;
    line-height: 1.1; margin-bottom: 1rem;
}
.section-sub { color: var(--text-muted); max-width: 500px; font-size: .95rem; font-weight: 300; }

.features-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.25rem;
    margin-top: 3.5rem;
}
.feat-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 18px; padding: 1.75rem;
    transition: all .3s;
    position: relative; overflow: hidden;
    backdrop-filter: blur(12px);
}
.feat-card::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 2px;
    background: linear-gradient(90deg, transparent, var(--green), transparent);
    opacity: 0; transition: opacity .3s;
}
.feat-card:hover { transform: translateY(-5px); border-color: rgba(0,196,113,.2); box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.feat-card:hover::before { opacity: 1; }
.feat-icon {
    width: 48px; height: 48px; border-radius: 14px;
    background: var(--green-dim); border: 1px solid rgba(0,196,113,.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem; margin-bottom: 1.1rem;
    color: var(--green);
}
.feat-title {
    font-family: 'Syne', sans-serif;
    font-size: 1.05rem; font-weight: 700;
    margin-bottom: .5rem;
}
.feat-desc { font-size: .87rem; color: var(--text-muted); line-height: 1.65; }

/* Featured / big card */
.feat-card.big {
    grid-column: span 2;
    display: flex; gap: 2rem; align-items: center;
    padding: 2.25rem;
}
.feat-card.big .feat-content { flex: 1; }
.feat-card.big .feat-visual {
    flex: 0 0 200px;
    background: rgba(0,196,113,.05);
    border: 1px solid rgba(0,196,113,.15);
    border-radius: 14px; padding: 1.25rem;
    font-size: .78rem; color: var(--text-muted);
}
.mini-card { background: rgba(11,33,64,.8); border-radius: 10px; padding: .75rem 1rem; margin-bottom: .5rem; display: flex; align-items: center; gap: .75rem; }
.mini-card:last-child { margin-bottom: 0; }
.mini-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

/* ── HOW IT WORKS ── */
.how-grid {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem;
    margin-top: 3.5rem; position: relative;
}
.how-grid::before {
    content: ''; position: absolute;
    top: 28px; left: calc(12.5% + 14px); right: calc(12.5% + 14px); height: 1px;
    background: linear-gradient(90deg, var(--green), rgba(0,196,113,.3), var(--green));
    z-index: 0;
}
.how-step { text-align: center; position: relative; z-index: 1; }
.how-num {
    width: 56px; height: 56px; border-radius: 50%;
    background: var(--navy2); border: 2px solid var(--green);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.1rem;
    font-family: 'Syne', sans-serif; font-size: 1.2rem; font-weight: 800; color: var(--green);
    box-shadow: 0 0 20px var(--green-glow);
}
.how-title { font-family: 'Syne', sans-serif; font-weight: 700; font-size: .95rem; margin-bottom: .4rem; }
.how-desc { font-size: .82rem; color: var(--text-muted); line-height: 1.6; }

/* ── CTA SECTION ── */
.cta-section {
    padding: 6rem 1.5rem;
    text-align: center;
}
.cta-box {
    max-width: 700px; margin: 0 auto;
    background: linear-gradient(135deg, var(--navy2), var(--navy3));
    border: 1px solid rgba(0,196,113,.2);
    border-radius: 24px; padding: 4rem 3rem;
    position: relative; overflow: hidden;
}
.cta-box::before {
    content: '';
    position: absolute; top: -60px; right: -60px;
    width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(0,196,113,.15), transparent 65%);
    pointer-events: none;
}
.cta-box::after {
    content: '';
    position: absolute; bottom: -60px; left: -60px;
    width: 250px; height: 250px;
    background: radial-gradient(circle, rgba(59,130,246,.1), transparent 65%);
    pointer-events: none;
}
.cta-title {
    font-family: 'Syne', sans-serif;
    font-size: clamp(1.8rem, 4vw, 2.6rem);
    font-weight: 800; letter-spacing: -.025em;
    margin-bottom: .85rem; position: relative; z-index: 1;
}
.cta-sub { color: var(--text-muted); margin-bottom: 2rem; position: relative; z-index: 1; }
.cta-actions { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; position: relative; z-index: 1; }

/* ── FOOTER ── */
footer {
    border-top: 1px solid var(--border);
    padding: 2.5rem 2.5rem;
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 1rem;
    color: var(--text-muted); font-size: .82rem;
}
.footer-links { display: flex; gap: 1.5rem; }
.footer-links a { color: var(--text-muted); text-decoration: none; transition: color .2s; }
.footer-links a:hover { color: var(--green); }

/* ── ANIMATIONS ── */
@keyframes fadeDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes fadeUp   { from { opacity: 0; transform: translateY(24px);  } to { opacity: 1; transform: translateY(0); } }

.reveal { opacity: 0; transform: translateY(30px); transition: opacity .7s ease, transform .7s ease; }
.reveal.visible { opacity: 1; transform: translateY(0); }

/* ── MOBILE ── */
@media (max-width: 900px) {
    .features-grid { grid-template-columns: 1fr; }
    .feat-card.big { flex-direction: column; grid-column: span 1; }
    .feat-card.big .feat-visual { flex: none; width: 100%; }
    .how-grid { grid-template-columns: repeat(2, 1fr); }
    .how-grid::before { display: none; }
    nav { padding: 1rem 1.25rem; }
    .nav-links { display: none; }
    .nav-links.open { display: flex; flex-direction: column; position: absolute; top: 100%; left: 0; right: 0; background: var(--navy2); border-bottom: 1px solid var(--border); padding: 1rem; gap: .5rem; }
    .nav-hamburger { display: block; }
    footer { flex-direction: column; text-align: center; }
    .cta-box { padding: 2.5rem 1.5rem; }
}
@media (max-width: 600px) {
    .stats-strip { flex-direction: column; }
    .stat-item + .stat-item::before { left: 20%; right: 20%; top: 0; bottom: auto; width: auto; height: 1px; }
    .how-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- NAV -->
<nav id="navbar">
    <a href="<?= APP_URL ?>/home.php" class="nav-logo">
        <div class="nav-logo-mark">🏦</div>
        <span class="nav-logo-text">Smart<span>Chama</span></span>
    </a>
    <div class="nav-links" id="navLinks">
        <a href="#features" class="nav-link"><?= t('home_nav_features') ?></a>
        <a href="#how" class="nav-link"><?= t('home_nav_how') ?></a>
        <a href="<?= APP_URL ?>/index.php" class="nav-link"><?= t('home_nav_signin') ?></a>
        <a href="<?= APP_URL ?>/register.php" class="nav-cta"><?= t('home_nav_getstarted') ?></a>
    </div>
    <div style="display:flex;gap:5px;align-items:center;margin-right:.5rem">
      <a href="<?= APP_URL ?>/api/set_lang.php?lang=en" style="text-decoration:none;font-size:11px;font-weight:700;padding:4px 9px;border-radius:12px;border:1px solid rgba(255,255,255,0.3);color:<?= getLang()==="en" ? "#fff" : "rgba(255,255,255,0.6)" ?>;background:<?= getLang()==="en" ? "rgba(255,255,255,0.2)" : "transparent" ?>;">🇬🇧 EN</a>
      <a href="<?= APP_URL ?>/api/set_lang.php?lang=sw" style="text-decoration:none;font-size:11px;font-weight:700;padding:4px 9px;border-radius:12px;border:1px solid rgba(255,255,255,0.3);color:<?= getLang()==="sw" ? "#fff" : "rgba(255,255,255,0.6)" ?>;background:<?= getLang()==="sw" ? "rgba(255,255,255,0.2)" : "transparent" ?>;">🇰🇪 SW</a>
    </div>
    <button class="nav-hamburger" onclick="document.getElementById('navLinks').classList.toggle('open')">
        <i class="bi bi-list"></i>
    </button>
</nav>

<!-- HERO -->
<section class="hero">
    <div class="hero-badge">
        <span class="hero-badge-dot"></span>
        <?= t('home_hero_badge') ?>
    </div>
    <h1 class="hero-title">
        <?= t('home_hero_title1') ?><br>
        <span class="accent"><?= t('home_hero_title2') ?></span>
        <span class="line2"><?= t('home_hero_title3') ?></span>
    </h1>
    <p class="hero-sub">
        <?= t('home_hero_sub') ?>
    </p>
    <div class="hero-actions">
        <a href="<?= APP_URL ?>/register.php" class="btn-primary-hero">
            <i class="bi bi-person-plus-fill"></i> <?= t('home_hero_join') ?>
        </a>
        <a href="<?= APP_URL ?>/index.php" class="btn-secondary-hero">
            <i class="bi bi-box-arrow-in-right"></i> <?= t('home_hero_signin') ?>
        </a>
    </div>

    <div class="stats-strip">
        <div class="stat-item">
            <span class="stat-num" id="statMembers"><?= htmlspecialchars($stats['members']) ?></span>
            <span class="stat-label"><?= t('home_stat_members') ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-num" id="statSavings"><?= htmlspecialchars($stats['savings']) ?></span>
            <span class="stat-label"><?= t('home_stat_savings') ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-num" id="statLoans"><?= htmlspecialchars($stats['loans']) ?></span>
            <span class="stat-label"><?= t('home_stat_loans') ?></span>
        </div>
    </div>

    <div class="scroll-hint">
        <span><?= t('home_scroll') ?></span>
        <i class="bi bi-chevron-down"></i>
    </div>
</section>

<!-- FEATURES -->
<section class="section" id="features">
    <div class="section-inner">
        <div class="reveal">
            <div class="section-label"><?= t('home_feat_label') ?></div>
            <h2 class="section-title"><?= t('home_feat_title') ?></h2>
            <p class="section-sub"><?= t('home_feat_sub') ?></p>
        </div>

        <div class="features-grid">
            <div class="feat-card big reveal">
                <div class="feat-content">
                    <div class="feat-icon"><i class="bi bi-graph-up-arrow"></i></div>
                    <div class="feat-title"><?= t('home_feat1_title') ?></div>
                    <p class="feat-desc"><?= t('home_feat1_desc') ?></p>
                </div>
                <div class="feat-visual">
                    <div class="mini-card">
                        <span class="mini-dot" style="background:#00c471"></span>
                        <span><?= t('home_demo_rate') ?> <strong style="color:#00c471">87%</strong> ↑</span>
                    </div>
                    <div class="mini-card">
                        <span class="mini-dot" style="background:#f59e0b"></span>
                        <span><?= t('home_demo_late') ?></span>
                    </div>
                    <div class="mini-card">
                        <span class="mini-dot" style="background:#3b82f6"></span>
                        <span><?= t('home_demo_health') ?> <strong style="color:#3b82f6">82/100</strong></span>
                    </div>
                    <div class="mini-card">
                        <span class="mini-dot" style="background:#00c471"></span>
                        <span><?= t('home_demo_savings') ?></span>
                    </div>
                </div>
            </div>

            <div class="feat-card reveal">
                <div class="feat-icon"><i class="bi bi-phone"></i></div>
                <div class="feat-title"><?= t('home_feat2_title') ?></div>
                <p class="feat-desc"><?= t('home_feat2_desc') ?></p>
            </div>

            <div class="feat-card reveal">
                <div class="feat-icon"><i class="bi bi-cash-stack"></i></div>
                <div class="feat-title"><?= t('home_feat3_title') ?></div>
                <p class="feat-desc"><?= t('home_feat3_desc') ?></p>
            </div>

            <div class="feat-card reveal">
                <div class="feat-icon"><i class="bi bi-file-earmark-text"></i></div>
                <div class="feat-title"><?= t('home_feat4_title') ?></div>
                <p class="feat-desc"><?= t('home_feat4_desc') ?></p>
            </div>

            <div class="feat-card reveal">
                <div class="feat-icon"><i class="bi bi-chat-dots"></i></div>
                <div class="feat-title"><?= t('home_feat5_title') ?></div>
                <p class="feat-desc"><?= t('home_feat5_desc') ?></p>
            </div>

            <div class="feat-card reveal">
                <div class="feat-icon"><i class="bi bi-shield-check"></i></div>
                <div class="feat-title"><?= t('home_feat6_title') ?></div>
                <p class="feat-desc"><?= t('home_feat6_desc') ?></p>
            </div>
        </div>
    </div>
</section>

<!-- HOW IT WORKS -->
<section class="section" id="how" style="background: linear-gradient(180deg, transparent, rgba(11,33,64,.4), transparent)">
    <div class="section-inner" style="text-align:center">
        <div class="reveal">
            <div class="section-label"><?= t('home_how_label') ?></div>
            <h2 class="section-title"><?= t('home_how_title') ?></h2>
            <p class="section-sub" style="margin: 0 auto"><?= t('home_how_sub') ?></p>
        </div>
        <div class="how-grid reveal">
            <div class="how-step">
                <div class="how-num">1</div>
                <div class="how-title"><?= t('home_how1_title') ?></div>
                <p class="how-desc"><?= t('home_how1_desc') ?></p>
            </div>
            <div class="how-step">
                <div class="how-num">2</div>
                <div class="how-title"><?= t('home_how2_title') ?></div>
                <p class="how-desc"><?= t('home_how2_desc') ?></p>
            </div>
            <div class="how-step">
                <div class="how-num">3</div>
                <div class="how-title"><?= t('home_how3_title') ?></div>
                <p class="how-desc"><?= t('home_how3_desc') ?></p>
            </div>
            <div class="how-step">
                <div class="how-num">4</div>
                <div class="how-title"><?= t('home_how4_title') ?></div>
                <p class="how-desc"><?= t('home_how4_desc') ?></p>
            </div>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="cta-section">
    <div class="cta-box reveal">
        <h2 class="cta-title"><?= t('home_cta_title') ?></h2>
        <p class="cta-sub"><?= t('home_cta_sub') ?></p>
        <div class="cta-actions">
            <a href="<?= APP_URL ?>/register.php" class="btn-primary-hero">
                <i class="bi bi-person-plus-fill"></i> <?= t('home_cta_create') ?>
            </a>
            <a href="<?= APP_URL ?>/index.php" class="btn-secondary-hero">
                <i class="bi bi-box-arrow-in-right"></i> <?= t('home_cta_signin') ?>
            </a>
        </div>
    </div>
</section>

<!-- FOOTER -->
<footer>
    <div class="nav-logo" style="gap:.5rem">
        <div class="nav-logo-mark" style="width:28px;height:28px;font-size:.9rem">🏦</div>
        <span class="nav-logo-text" style="font-size:1rem">Smart<span>Chama</span></span>
    </div>
    <div class="footer-links">
        <a href="#features"><?= t('home_nav_features') ?></a>
        <a href="#how"><?= t('home_nav_how') ?></a>
        <a href="<?= APP_URL ?>/index.php"><?= t('home_nav_signin') ?></a>
        <a href="<?= APP_URL ?>/register.php"><?= t('login_register') ?></a>
    </div>
    <span>© <?= date('Y') ?> <?= htmlspecialchars($groupName) ?> <?= t('home_footer_rights') ?></span>
</footer>

<script>
// Scroll reveal
var revealEls = document.querySelectorAll('.reveal');
var observer = new IntersectionObserver(function(entries) {
    entries.forEach(function(e) {
        if (e.isIntersecting) { e.target.classList.add('visible'); observer.unobserve(e.target); }
    });
}, { threshold: 0.12 });
revealEls.forEach(function(el) { observer.observe(el); });

// Sticky nav shadow on scroll
window.addEventListener('scroll', function() {
    document.getElementById('navbar').style.boxShadow = window.scrollY > 20 ? '0 4px 40px rgba(0,0,0,.4)' : '';
});

// Animate stat numbers counting up
function countUp(el, target, isText) {
    if (isText || isNaN(parseFloat(target))) return; // skip if —
    var start = 0, duration = 1800, step = 16;
    var num = parseFloat(target);
    var interval = setInterval(function() {
        start += step;
        var progress = Math.min(start / duration, 1);
        var eased = 1 - Math.pow(1 - progress, 3);
        el.textContent = Math.round(eased * num);
        if (progress >= 1) clearInterval(interval);
    }, 16);
}

// Trigger counter when stats come into view
var statsEl = document.querySelector('.stats-strip');
var counted = false;
var statsObserver = new IntersectionObserver(function(entries) {
    if (entries[0].isIntersecting && !counted) {
        counted = true;
        var m = document.getElementById('statMembers').textContent;
        var l = document.getElementById('statLoans').textContent;
        countUp(document.getElementById('statMembers'), m);
        countUp(document.getElementById('statLoans'), l);
    }
});
if (statsEl) statsObserver.observe(statsEl);
</script>
</body>
</html>
