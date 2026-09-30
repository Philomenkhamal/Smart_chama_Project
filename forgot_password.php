<?php
// Bypass ngrok browser warning
@header('ngrok-skip-browser-warning: true');
if (!defined('ROOT')) define('ROOT', __DIR__);
require_once ROOT . '/includes/functions.php';
require_once ROOT . '/includes/mailer.php';
if (!dbIsReady()) { header('Location: ' . APP_URL . '/setup.php'); exit; }
startSession();
if (isLoggedIn()) redirect(APP_URL . '/member/dashboard.php');

$sent   = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $email = strtolower(trim(sanitize($_POST['email'] ?? '')));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('err_forgot_invalid_email');
    } else {
        $pdo  = getDB();
        $user = $pdo->prepare("SELECT id, full_name, email, status FROM users WHERE email = ? LIMIT 1");
        $user->execute([$email]);
        $user = $user->fetch();

        if (!$user) {
            $errors[] = t('err_forgot_no_account');
        } elseif ($user['status'] === 'pending') {
            $errors[] = t('err_forgot_pending');
        } elseif ($user['status'] === 'suspended') {
            $errors[] = t('err_forgot_suspended');
        } elseif ($user['status'] === 'active') {
            $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
            $token     = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));
            $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?,?,?)")
                ->execute([$email, $token, $expiresAt]);
            $resetLink = APP_URL . '/reset_password.php?token=' . $token;
            try {
                $mailer = new Mailer();
                $mailer->sendPasswordReset($user, $resetLink);
            } catch (Exception $e) {
                error_log('[ChamaLedger] Reset email failed: ' . $e->getMessage());
            }
            logActivity('PASSWORD_RESET_REQUEST', 'Reset requested for ' . $email);
            $sent = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('forgot_title') ?> — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --green:      #00c471;
            --green-dark: #009954;
            --green-glow: rgba(0,196,113,0.2);
            --navy:       #050f1c;
            --navy-mid:   #0a1f35;
            --navy-light: #0f2d4a;
            --text:       #e8f0f8;
            --muted:      #7a94b0;
            --border:     rgba(0,196,113,0.18);
            --input-bg:   rgba(15,45,74,0.6);
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        html,body{height:100%}
        body{font-family:'DM Sans',sans-serif;background:var(--navy);color:var(--text);display:flex;min-height:100vh;overflow-x:hidden}

        .left-panel{flex:0 0 45%;background:linear-gradient(160deg,#071628 0%,#0a2240 50%,#061320 100%);display:flex;flex-direction:column;justify-content:space-between;padding:3rem;position:relative;overflow:hidden}
        .left-panel::before{content:'';position:absolute;width:500px;height:500px;border-radius:50%;background:radial-gradient(circle,rgba(0,196,113,0.15) 0%,transparent 65%);top:-100px;right:-150px;animation:glow 8s ease-in-out infinite}
        .left-panel::after{content:'';position:absolute;width:350px;height:350px;border-radius:50%;background:radial-gradient(circle,rgba(0,196,113,0.08) 0%,transparent 65%);bottom:-80px;left:-80px;animation:glow 11s ease-in-out infinite reverse}
        @keyframes glow{0%,100%{transform:scale(1) translate(0,0);opacity:1}50%{transform:scale(1.15) translate(20px,-15px);opacity:0.7}}

        .panel-logo{display:flex;align-items:center;gap:.75rem;text-decoration:none;position:relative;z-index:1;animation:fadeIn .5s ease both}
        .panel-logo-icon{width:44px;height:44px;background:linear-gradient(135deg,var(--green),var(--green-dark));border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:#fff;box-shadow:0 0 24px var(--green-glow)}
        .panel-logo-text{font-family:'Syne',sans-serif;font-weight:800;font-size:1.4rem;color:#fff;letter-spacing:-.02em}
        .panel-logo-text span{color:var(--green)}

        .panel-content{position:relative;z-index:1;animation:fadeUp .6s ease .2s both}
        .panel-tagline{font-family:'Syne',sans-serif;font-size:clamp(1.9rem,3.5vw,2.8rem);font-weight:800;line-height:1.1;letter-spacing:-.03em;margin-bottom:1.2rem}
        .panel-tagline .accent{color:var(--green)}
        .panel-sub{font-size:.95rem;color:var(--muted);line-height:1.7;max-width:340px;margin-bottom:2.5rem}

        .panel-features{display:flex;flex-direction:column;gap:.9rem}
        .panel-feature{display:flex;align-items:center;gap:.75rem;font-size:.88rem;color:rgba(232,240,248,.75)}
        .panel-feature-dot{width:28px;height:28px;border-radius:8px;background:var(--green-glow);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:.85rem;color:var(--green);flex-shrink:0}
        .panel-footer{position:relative;z-index:1;font-size:.75rem;color:var(--muted);animation:fadeIn .5s ease .5s both}

        .right-panel{flex:1;display:flex;align-items:center;justify-content:center;padding:2rem;position:relative;background:var(--navy)}
        .right-panel::before{content:'';position:absolute;inset:0;background-image:linear-gradient(rgba(0,196,113,0.04) 1px,transparent 1px),linear-gradient(90deg,rgba(0,196,113,0.04) 1px,transparent 1px);background-size:40px 40px;pointer-events:none}

        .form-box{width:100%;max-width:420px;position:relative;z-index:1;animation:fadeUp .6s ease .1s both}
        .form-header{margin-bottom:2rem}
        .form-header h1{font-family:'Syne',sans-serif;font-size:1.9rem;font-weight:800;letter-spacing:-.02em;margin-bottom:.35rem}
        .form-header p{font-size:.9rem;color:var(--muted)}
        .form-header p a{color:var(--green);text-decoration:none;font-weight:500}

        .alert-box{padding:.85rem 1rem;border-radius:10px;font-size:.875rem;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:.6rem}
        .alert-danger{background:rgba(220,53,69,.12);border:1px solid rgba(220,53,69,.3);color:#ff8b96;animation:shake .4s ease}
        .alert-success{background:rgba(0,196,113,.12);border:1px solid rgba(0,196,113,.3);color:var(--green)}
        @keyframes shake{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-6px)}40%,80%{transform:translateX(6px)}}

        .field{margin-bottom:1.2rem}
        .field label{display:block;font-size:.82rem;font-weight:600;color:rgba(232,240,248,.8);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.45rem}
        .input-wrap{position:relative}
        .input-wrap .icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:1rem;pointer-events:none;transition:color .2s}
        .input-wrap input{width:100%;background:var(--input-bg);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:.8rem .9rem .8rem 2.75rem;font-family:'DM Sans',sans-serif;font-size:.95rem;color:var(--text);outline:none;transition:all .2s;backdrop-filter:blur(10px)}
        .input-wrap input::placeholder{color:rgba(122,148,176,.6)}
        .input-wrap input:focus{border-color:var(--green);box-shadow:0 0 0 3px var(--green-glow);background:rgba(15,45,74,.8)}
        .input-wrap:focus-within .icon{color:var(--green)}

        .btn-submit{width:100%;padding:.9rem;background:var(--green);color:#050f1c;border:none;border-radius:10px;font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;cursor:pointer;transition:all .25s;box-shadow:0 4px 30px rgba(0,196,113,.3);margin-top:.5rem;display:flex;align-items:center;justify-content:center;gap:.5rem}
        .btn-submit:hover{background:#00dc7e;transform:translateY(-2px);box-shadow:0 8px 40px rgba(0,196,113,.45)}

        .divider{display:flex;align-items:center;gap:1rem;margin:1.5rem 0;font-size:.78rem;color:var(--muted)}
        .divider::before,.divider::after{content:'';flex:1;height:1px;background:rgba(255,255,255,.07)}
        .back-link{display:flex;align-items:center;justify-content:center;gap:.4rem;font-size:.85rem;color:var(--muted);text-decoration:none;transition:color .2s}
        .back-link:hover{color:var(--green)}

        /* Success state */
        .success-icon{width:72px;height:72px;background:rgba(0,196,113,.15);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;font-size:2rem;color:var(--green);animation:pop .5s ease both}
        @keyframes pop{from{transform:scale(0.5);opacity:0}to{transform:scale(1);opacity:1}}

        @keyframes fadeIn{from{opacity:0}to{opacity:1}}
        @keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
        @media(max-width:768px){.left-panel{display:none}.right-panel{padding:1.5rem 1.25rem}}
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


<!-- Left Panel -->
<div class="left-panel">
    <a href="<?= APP_URL ?>/home.php" class="panel-logo">
        <div class="panel-logo-icon"><i class="bi bi-bank2"></i></div>
        <span class="panel-logo-text">Chama<span>Ledger</span></span>
    </a>
    <div class="panel-content">
        <?php if ($sent): ?>
        <?= t('forgot_inbox_title') ?>
        <p class="panel-sub"><?= t('forgot_sent_msg') ?></p>
        <?php else: ?>
        <h2 class="panel-tagline"><?= t('forgot_reset_title') ?></h2>
        <p class="panel-sub"><?= t('forgot_instruction') ?> <?= t('forgot_panel_sub2') ?></p>
        <?php endif; ?>
        <div class="panel-features">
            <div class="panel-feature"><div class="panel-feature-dot"><i class="bi bi-shield-lock"></i></div><?= t('forgot_feat1') ?></div>
            <div class="panel-feature"><div class="panel-feature-dot"><i class="bi bi-clock"></i></div><?= t('forgot_feat2') ?></div>
            <div class="panel-feature"><div class="panel-feature-dot"><i class="bi bi-envelope-check"></i></div><?= t('forgot_feat4') ?></div>
        </div>
    </div>
    <div class="panel-footer">© <?= date('Y') ?> ChamaLedger. All rights reserved.</div>
</div>

<!-- Right Panel -->
<div class="right-panel">
    <div class="form-box">

        <?php if ($sent): ?>
        <!-- Sent state -->
        <div style="text-align:center">
            <div class="success-icon"><i class="bi bi-envelope-check-fill"></i></div>
            <div class="form-header" style="text-align:center">
                <h1><?= t('forgot_email_sent') ?></h1>
                <p><?= t('forgot_link_sent') ?></p>
            </div>
            <div class="divider"><?= t('forgot_next_steps') ?></div>
            <a href="<?= APP_URL ?>/index.php" class="btn-submit">
                <i class="bi bi-box-arrow-in-right"></i> <?= t('forgot_back_signin') ?>
            </a>
        </div>

        <?php else: ?>
        <!-- Form state -->
        <div class="form-header">
            <h1><?= t('forgot_title') ?></h1>
            <p>Remember it? <a href="<?= APP_URL ?>/index.php"><?= t('home_nav_signin') ?></a></p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert-box alert-danger">
            <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0;margin-top:2px"></i>
            <div><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
        </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="field">
                <label><?= t('login_email') ?></label>
                <div class="input-wrap">
                    <i class="bi bi-envelope icon"></i>
                    <input type="email" name="email" placeholder="you@email.com"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autofocus required>
                </div>
            </div>
            <button type="submit" class="btn-submit">
                <i class="bi bi-send"></i> <?= t('forgot_btn') ?>
            </button>
        </form>

        <div class="divider">or</div>
        <a href="<?= APP_URL ?>/index.php" class="back-link">
            <i class="bi bi-arrow-left"></i> <?= t('forgot_back_signin') ?>
        </a>
        <?php endif; ?>

    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.alert-box, .alert-danger, .alert-warning, .alert-success').forEach(function(alert) {
        setTimeout(function() {
            alert.style.transition = 'opacity .6s ease, transform .6s ease, max-height .6s ease, margin .6s ease, padding .6s ease';
            alert.style.opacity    = '0';
            alert.style.transform  = 'translateY(-8px)';
            alert.style.maxHeight  = '0';
            alert.style.margin     = '0';
            alert.style.padding    = '0';
            alert.style.overflow   = 'hidden';
            setTimeout(function() { if(alert.parentNode) alert.remove(); }, 650);
        }, 5000);
    });
});
</script>
</body>
</html>
