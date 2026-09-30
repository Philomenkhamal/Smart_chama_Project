<?php
/**
 * ChamaLedger — Pending Approval Page
 * Shown after registration. Polls every 8s for status change.
 */
if (!defined('ROOT')) define('ROOT', __DIR__);
require_once ROOT . '/includes/functions.php';
if (!dbIsReady()) { header('Location: ' . APP_URL . '/setup.php'); exit; }

startSession();

// If already logged in and active, go straight to dashboard
if (isLoggedIn()) {
    $u = currentUser();
    if ($u && $u['status'] === 'active') {
        redirect(APP_URL . '/member/dashboard.php');
    }
}

// Get name/email — from session (just registered) or GET param (returning)
$pendingEmail = $_SESSION['pending_email'] ?? sanitize($_GET['email'] ?? '');
$pendingName  = $_SESSION['pending_name']  ?? 'there';

// Check real status right now
$currentStatus = 'pending';
$membershipNum = '';
if ($pendingEmail) {
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT status, full_name, membership_number FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$pendingEmail]);
    $row = $stmt->fetch();
    if ($row) {
        $currentStatus = $row['status'];
        $pendingName   = $row['full_name'];
        $membershipNum = $row['membership_number'] ?? '';
    }
}

// If already active — show brief success then redirect
$alreadyApproved = ($currentStatus === 'active');
$wasRejected     = ($currentStatus === 'rejected');
?>
<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('pending_title') ?> — <?= APP_NAME ?></title>
<script>(function(){var t=localStorage.getItem('cl_theme')||'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.id='htmlRoot';})()</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root {
    --green:#00c471; --green-dim:rgba(0,196,113,0.1); --green-glow:rgba(0,196,113,0.25);
    --amber:#f59e0b; --red:#ef4444;
    --bg:#060e1a; --card-bg:#0d1f38; --card-border:rgba(255,255,255,0.07);
    --text:#e2eaf4; --text-muted:#6b87a8; --divider:rgba(255,255,255,0.07);
    --shadow:0 12px 48px rgba(0,0,0,0.5);
}
[data-theme="light"] {
    --bg:#f0f4f8; --card-bg:#ffffff; --card-border:rgba(0,0,0,0.08);
    --text:#1a2940; --text-muted:#5a7390; --divider:rgba(0,0,0,0.08);
    --shadow:0 8px 32px rgba(0,0,0,0.1);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:1.5rem;transition:background .3s,color .3s}
.card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:20px;padding:2.5rem 2rem;max-width:480px;width:100%;box-shadow:var(--shadow);text-align:center;position:relative}

/* Logo */
.logo{display:flex;align-items:center;justify-content:center;gap:.6rem;margin-bottom:2rem}
.logo-icon{width:38px;height:38px;background:linear-gradient(135deg,#00c471,#009954);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#fff;box-shadow:0 0 16px var(--green-glow)}
.logo-text{font-family:'Syne',sans-serif;font-weight:800;font-size:1.2rem;color:var(--text)}
.logo-text span{color:var(--green)}

/* Status icon with pulse ring */
.status-wrap{position:relative;display:inline-flex;align-items:center;justify-content:center;margin-bottom:1.5rem}
.pulse-ring{position:absolute;width:100px;height:100px;border-radius:50%;border:2px solid var(--green);animation:pulse-out 2s ease-out infinite;opacity:0}
.pulse-ring:nth-child(2){animation-delay:.6s}
.status-icon{width:76px;height:76px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2rem;position:relative;z-index:1;transition:all .4s}
.status-icon.pending  {background:rgba(245,158,11,0.12);color:var(--amber)}
.status-icon.approved {background:rgba(0,196,113,0.12);color:var(--green)}
.status-icon.rejected {background:rgba(239,68,68,0.12);color:var(--red)}

@keyframes pulse-out{0%{transform:scale(1);opacity:.6}100%{transform:scale(1.8);opacity:0}}

/* Title & subtitle */
h2{font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;margin-bottom:.4rem;letter-spacing:-.02em}
.subtitle{font-size:.875rem;color:var(--text-muted);line-height:1.65;margin-bottom:1.75rem}

/* Steps */
.steps{display:flex;flex-direction:column;gap:.6rem;text-align:left;margin-bottom:1.75rem}
.step{display:flex;align-items:center;gap:.85rem;padding:.75rem 1rem;border-radius:12px;font-size:.845rem;border:1px solid transparent;transition:all .4s}
.step.done    {background:rgba(0,196,113,0.08);border-color:rgba(0,196,113,0.2);color:var(--text)}
.step.active  {background:rgba(245,158,11,0.08);border-color:rgba(245,158,11,0.2);color:var(--text)}
.step.waiting {background:rgba(255,255,255,0.02);border-color:var(--divider);color:var(--text-muted)}
.step-dot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.step.done   .step-dot{background:rgba(0,196,113,0.15);color:var(--green)}
.step.active .step-dot{background:rgba(245,158,11,0.15);color:var(--amber)}
.step.waiting .step-dot{background:rgba(255,255,255,0.05);color:var(--text-muted)}
.step-label{font-weight:600}
.step-sub{font-size:.75rem;color:var(--text-muted);margin-top:.1rem}

/* Membership badge */
.mem-badge{background:var(--green-dim);border:1px solid rgba(0,196,113,0.25);border-radius:12px;padding:1rem;margin-bottom:1.5rem}
.mem-badge .label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:.3rem}
.mem-badge .number{font-family:monospace;font-size:1.35rem;font-weight:800;color:var(--green)}

/* Email info box */
.info-box{background:rgba(245,158,11,0.07);border:1px solid rgba(245,158,11,0.2);border-radius:10px;padding:.9rem 1rem;font-size:.8rem;color:var(--text-muted);text-align:left;margin-bottom:1.5rem;line-height:1.6}
.info-box i{color:var(--amber);margin-right:.4rem}
.email-chip{display:inline-block;background:rgba(0,196,113,0.1);border:1px solid rgba(0,196,113,0.2);border-radius:6px;padding:.1rem .5rem;font-family:monospace;font-size:.82rem;color:var(--green)}

/* Buttons */
.btn{display:block;padding:.85rem;background:var(--green);color:#060e1a;border:none;border-radius:11px;font-family:'Syne',sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;text-align:center;text-decoration:none;transition:all .2s;width:100%}
.btn:hover{background:#00dc7e;transform:translateY(-1px);box-shadow:0 6px 24px var(--green-glow);color:#060e1a}
.btn-secondary{background:transparent;border:1px solid var(--divider);color:var(--text);margin-top:.6rem;font-size:.875rem;font-weight:600;font-family:'DM Sans',sans-serif}
.btn-secondary:hover{background:rgba(255,255,255,0.05);transform:none;box-shadow:none}

/* Live indicator */
.live-dot{display:inline-block;width:7px;height:7px;background:var(--green);border-radius:50%;animation:blink 1.4s ease infinite;margin-right:.4rem;vertical-align:middle}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}
.live-text{font-size:.75rem;color:var(--text-muted);margin-top:1.25rem}

/* Theme toggle */
.theme-btn{position:absolute;top:1rem;right:1rem;width:34px;height:34px;border:1px solid var(--divider);background:rgba(255,255,255,0.04);border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text-muted);font-size:.95rem;transition:all .2s}
.theme-btn:hover{color:var(--green);border-color:var(--green);background:var(--green-dim)}
</style>
</head>
<body>

<!-- Language Toggle -->
<div style="position:fixed;top:16px;right:20px;z-index:9999;display:flex;gap:6px;">
  <a href="<?= (defined('APP_URL') ? APP_URL : '') ?>/api/set_lang.php?lang=en"
     style="text-decoration:none;font-size:12px;font-weight:700;padding:5px 12px;border-radius:20px;border:1px solid rgba(255,255,255,0.3);color:<?= getLang()==='en' ? '#fff' : 'rgba(255,255,255,0.6)' ?>;background:<?= getLang()==='en' ? 'rgba(255,255,255,0.2)' : 'transparent' ?>;">
    🇬🇧 EN
  </a>
  <a href="<?= (defined('APP_URL') ? APP_URL : '') ?>/api/set_lang.php?lang=sw"
     style="text-decoration:none;font-size:12px;font-weight:700;padding:5px 12px;border-radius:20px;border:1px solid rgba(255,255,255,0.3);color:<?= getLang()==='sw' ? '#fff' : 'rgba(255,255,255,0.6)' ?>;background:<?= getLang()==='sw' ? 'rgba(255,255,255,0.2)' : 'transparent' ?>;">
    🇰🇪 SW
  </a>
</div>


<div class="card">
    <!-- Theme toggle -->
    <button class="theme-btn" id="themeBtn" title="Toggle theme"><i class="bi bi-moon-stars-fill" id="themeIcon"></i></button>

    <!-- Logo -->
    <div class="logo">
        <div class="logo-icon"><i class="bi bi-bank2"></i></div>
        <span class="logo-text">Chama<span>Ledger</span></span>
    </div>

    <!-- ── PENDING STATE ── -->
    <div id="viewPending" <?= $alreadyApproved || $wasRejected ? 'style="display:none"' : '' ?>>
        <div class="status-wrap">
            <div class="pulse-ring"></div>
            <div class="pulse-ring"></div>
            <div class="status-icon pending"><i class="bi bi-hourglass-split"></i></div>
        </div>

        <h2><?= t('pending_title') ?></h2>
        <p class="subtitle">
            Hi <strong><?= htmlspecialchars($pendingName) ?></strong><?= t('pending_reviewing') ?><br>
            The administrator will approve your account shortly.
        </p>

        <?php if ($pendingEmail): ?>
        <div class="info-box" style="background:rgba(0,196,113,.1);border-color:rgba(0,196,113,.3)">
            <i class="bi bi-envelope-check-fill" style="color:#00c471"></i>
            <span>Waiting for admin approval.
            You'll get an email the moment your account is activated.</span>
        </div>
        <?php endif; ?>

        <div class="steps">
            <div class="step done">
                <div class="step-dot"><i class="bi bi-check"></i></div>
                <div><div class="step-label"><?= t('pending_submitted') ?></div><div class="step-sub"><?= t('pending_saved') ?></div></div>
            </div>
            <div class="step active" id="stepReview">
                <div class="step-dot"><i class="bi bi-clock-history"></i></div>
                <div><div class="step-label"><?= t('pending_admin_review') ?></div><div class="step-sub" id="reviewSub"><?= t('pending_waiting') ?></div></div>
            </div>
            <div class="step waiting" id="stepAccess">
                <div class="step-dot"><i class="bi bi-lock"></i></div>
                <div><div class="step-label"><?= t('pending_access') ?></div><div class="step-sub"><?= t('pending_unlocked') ?></div></div>
            </div>
        </div>

        <div class="live-text">
            <span class="live-dot"></span> Checking for updates automatically&hellip;
        </div>

        <a href="<?= APP_URL ?>/index.php" class="btn btn-secondary" style="margin-top:1.25rem"><?= t('login_btn') ?></a>
    </div>

    <!-- ── APPROVED STATE ── -->
    <div id="viewApproved" <?= !$alreadyApproved ? 'style="display:none"' : '' ?>>
        <div class="status-wrap">
            <div class="status-icon approved"><i class="bi bi-check-circle-fill"></i></div>
        </div>
        <h2><?= t('pending_approved') ?></h2>
        <p class="subtitle"><?= t('pending_welcome') ?> <?= APP_NAME ?>, <strong><?= htmlspecialchars($pendingName) ?></strong><?= t('pending_welcome_msg') ?></p>

        <?php if ($membershipNum): ?>
        <div class="mem-badge">
            <div class="label"><?= t('pending_memno') ?></div>
            <div class="number"><?= htmlspecialchars($membershipNum) ?></div>
        </div>
        <?php endif; ?>

        <a href="<?= APP_URL ?>/index.php" class="btn"><?= t('pending_login_dash') ?></a>
    </div>

    <!-- ── REJECTED STATE ── -->
    <div id="viewRejected" <?= !$wasRejected ? 'style="display:none"' : '' ?>>
        <div class="status-wrap">
            <div class="status-icon rejected"><i class="bi bi-x-circle-fill"></i></div>
        </div>
        <h2><?= t('pending_not_approved') ?></h2>
        <p class="subtitle"><?= t('pending_not_appr_msg') ?></p>
        <a href="<?= APP_URL ?>/index.php" class="btn btn-secondary" style="margin-top:.5rem"><?= t('pending_back_home') ?></a>
    </div>

</div><!-- /card -->

<script>
const APP   = '<?= APP_URL ?>';
const EMAIL = '<?= addslashes($pendingEmail) ?>';
let   pollTimer = null;
let   checkCount = 0;

// ── Theme toggle ──────────────────────────────────────────────
const html      = document.getElementById('htmlRoot');
const themeBtn  = document.getElementById('themeBtn');
const themeIcon = document.getElementById('themeIcon');
function applyTheme(t) {
    html.setAttribute('data-theme', t);
    localStorage.setItem('cl_theme', t);
    themeIcon.className = t === 'dark' ? 'bi bi-moon-stars-fill' : 'bi bi-sun-fill';
}
applyTheme(localStorage.getItem('cl_theme') || 'dark');
themeBtn.addEventListener('click', () => applyTheme(html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'));

// ── Status polling ────────────────────────────────────────────
function showApproved(data) {
    clearInterval(pollTimer);
    document.getElementById('viewPending').style.display  = 'none';
    document.getElementById('viewRejected').style.display = 'none';

    const va = document.getElementById('viewApproved');
    // Inject membership number dynamically if badge not already present
    if (data.membership_number && !document.querySelector('.mem-badge')) {
        const badge = document.createElement('div');
        badge.className = 'mem-badge';
        badge.innerHTML = `<div class="label"><?= t('pending_memno') ?></div><div class="number">${data.membership_number}</div>`;
        va.insertBefore(badge, va.querySelector('.btn'));
    }
    va.style.display = 'block';

    // Flash title tab to alert user
    let alt = false;
    const origTitle = document.title;
    const flashTitle = setInterval(() => {
        document.title = alt ? '✅ APPROVED! — ChamaLedger' : origTitle;
        alt = !alt;
    }, 800);
    setTimeout(() => { clearInterval(flashTitle); document.title = origTitle; }, 8000);
}

function showRejected() {
    clearInterval(pollTimer);
    document.getElementById('viewPending').style.display  = 'none';
    document.getElementById('viewApproved').style.display = 'none';
    document.getElementById('viewRejected').style.display = 'block';
}

async function checkStatus() {
    if (!EMAIL) return;
    checkCount++;
    try {
        const res  = await fetch(APP + '/api/check_status.php?email=' + encodeURIComponent(EMAIL));
        const data = await res.json();

        if (data.status === 'active')    { showApproved(data); return; }
        if (data.status === 'rejected')  { showRejected();     return; }

        // Update sub-label with last checked time
        const sub = document.getElementById('reviewSub');
        if (sub) {
            const now = new Date().toLocaleTimeString('en-KE', {hour:'2-digit', minute:'2-digit'});
            sub.textContent = `Last checked: ${now}`;
        }
    } catch(e) { /* silent — network blip */ }
}

// Start polling — every 8 seconds, but only if still pending
<?php if (!$alreadyApproved && !$wasRejected): ?>
if (EMAIL) {
    pollTimer = setInterval(checkStatus, 8000);
    // First check after 3s
    setTimeout(checkStatus, 3000);
}
<?php endif; ?>

// Also check when tab becomes visible again (user switches back)
document.addEventListener('visibilitychange', () => {
    if (!document.hidden && EMAIL) checkStatus();
});
</script>
</body>
</html>
