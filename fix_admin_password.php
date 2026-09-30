<?php
// ─── PRODUCTION GUARD ─────────────────────────────────────────────────────────
// This file should be DELETED after first use.
// Block access if a SETUP_SECRET env variable is set (production safety).
if (!empty(getenv('DISABLE_SETUP')) || !empty($_ENV['DISABLE_SETUP'])) {
    http_response_code(404);
    die('Not found.');
}
// ──────────────────────────────────────────────────────────────────────────────
/**
 * CHAMA SYSTEM — Admin Password Fix Script
 * =========================================
 * Run this ONCE to fix the admin login issue.
 * 
 * HOW TO USE:
 *   Visit: http://localhost/chama_system/fix_admin_password.php
 * 
 * DELETE THIS FILE immediately after running it.
 */

require_once __DIR__ . '/config/db.php';

// The password we want to set
$newPassword = 'Admin@1234';
$newHash     = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);

// Verify the hash works before saving
if (!password_verify($newPassword, $newHash)) {
    die('<p style="color:red">❌ Hash verification failed. Something is wrong with PHP setup.</p>');
}

$pdo = getDB();

// Update admin account
$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = 'admin@chama.local' AND role = 'admin'");
$stmt->execute([$newHash]);
$affected = $stmt->rowCount();

// Also check if admin exists at all
$check = $pdo->query("SELECT id, email, role, status, password_hash FROM users WHERE role='admin'")->fetchAll();

?>
<!DOCTYPE html>
<html>
<head>
    <title>ChamaLedger — Admin Fix</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:600px">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h4 class="fw-bold mb-4">🔧 ChamaLedger — Admin Password Fix</h4>

            <?php if ($affected > 0): ?>
            <div class="alert alert-success">
                <strong>✅ Success!</strong> Admin password has been reset.
            </div>
            <?php else: ?>
            <div class="alert alert-warning">
                <strong>⚠️ No rows updated.</strong> 
                The admin account may not exist yet — see the table below.
            </div>
            <?php endif; ?>

            <h6 class="fw-semibold mt-3">Admin accounts found in database:</h6>
            <?php if (empty($check)): ?>
            <div class="alert alert-danger">
                <strong>❌ No admin account found!</strong><br>
                You need to re-import the SQL file.<br>
                Go to <strong>phpMyAdmin → chama_db → Import → chama_db.sql</strong>
            </div>
            <?php else: ?>
            <table class="table table-bordered table-sm">
                <thead class="table-light">
                    <tr><th>ID</th><th>Email</th><th>Role</th><th>Status</th><th>Hash Preview</th></tr>
                </thead>
                <tbody>
                <?php foreach ($check as $u): ?>
                <tr>
                    <td><?= $u['id'] ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= $u['role'] ?></td>
                    <td><span class="badge bg-<?= $u['status']==='active'?'success':'warning' ?>"><?= $u['status'] ?></span></td>
                    <td><code style="font-size:.7rem"><?= substr($u['password_hash'], 0, 25) ?>...</code></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php
            // Live verification test
            $testUser = $pdo->query("SELECT password_hash FROM users WHERE email='admin@chama.local'")->fetch();
            $verifyOk = $testUser && password_verify('Admin@1234', $testUser['password_hash']);
            ?>

            <div class="alert <?= $verifyOk ? 'alert-success' : 'alert-danger' ?> mt-3">
                <?php if ($verifyOk): ?>
                ✅ <strong>Password verification PASSED.</strong><br>
                You can now log in with:<br>
                Email: <code>admin@chama.local</code><br>
                Password: <code>Admin@1234</code>
                <?php else: ?>
                ❌ <strong>Password verification FAILED.</strong><br>
                The hash in the database does not match <code>Admin@1234</code>.<br>
                Try re-importing the SQL file from scratch.
                <?php endif; ?>
            </div>

            <div class="alert alert-warning mt-3">
                <strong>⚠️ IMPORTANT:</strong> Delete this file after use!<br>
                <code>C:\xampp\htdocs\chama_system\fix_admin_password.php</code>
            </div>

            <?php if ($verifyOk): ?>
            <a href="<?= APP_URL ?>/index.php" class="btn btn-primary w-100">
                → Go to Login Page
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
