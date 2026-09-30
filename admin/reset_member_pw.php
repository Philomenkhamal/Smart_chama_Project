<?php
/**
 * ChamaLedger — Quick password reset for any member
 * Admin visits: /admin/reset_member_pw.php?email=john@example.com&pw=NewPass123
 * OR just visits the page to reset all members to default
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
requireAdmin();
$pdo = getDB();

$done = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'reset_one') {
        $email   = strtolower(trim($_POST['email'] ?? ''));
        $newPass = trim($_POST['new_password'] ?? '') ?: 'Chama@1234';
        $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE email=? AND role='member'");
        $stmt->execute([$email]);
        $member = $stmt->fetch();
        if (!$member) {
            $errors[] = "No member found with email: $email";
        } else {
            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $member['id']]);
            createNotification($member['id'], '🔑 Password Reset',
                "Your password has been reset. New password: $newPass — please change it after logging in.", 'warning');
            logActivity('RESET_PASSWORD', "Admin reset password for {$member['full_name']} ({$email})");
            $done[] = "✅ {$member['full_name']} ({$email}) → password set to: <strong>$newPass</strong>";
        }
    }

    if ($action === 'reset_all_default') {
        $hash = password_hash('Chama@1234', PASSWORD_BCRYPT, ['cost' => 12]);
        $members = $pdo->query("SELECT id, full_name, email FROM users WHERE role='member'")->fetchAll();
        foreach ($members as $m) {
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $m['id']]);
        }
        logActivity('RESET_ALL_PASSWORDS', "Admin reset ALL member passwords to default Chama@1234");
        $done[] = "✅ Reset " . count($members) . " members to default password: <strong>Chama@1234</strong>";
    }
}

// List all members
$members = $pdo->query("SELECT id, full_name, email, membership_number, status FROM users WHERE role='member' ORDER BY full_name")->fetchAll();
require_once ROOT . '/includes/header.php';
?>
<div class="mb-4">
    <h4 class="fw-bold"><i class="bi bi-key me-2 text-warning"></i>Password Reset Tool</h4>
    <small class="text-muted">Reset passwords for members who can't log in</small>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><?= implode('<br>', $errors) ?></div>
<?php endif; ?>
<?php if ($done): ?>
<div class="alert alert-success"><?= implode('<br>', $done) ?></div>
<?php endif; ?>

<div class="row g-3">
    <!-- Reset single member -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-card fw-semibold">Reset One Member's Password</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reset_one">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Member Email</label>
                        <select name="email" class="form-select" required>
                            <option value="">— Select member —</option>
                            <?php foreach ($members as $m): ?>
                            <option value="<?= htmlspecialchars($m['email']) ?>">
                                <?= htmlspecialchars($m['full_name']) ?> (<?= htmlspecialchars($m['email']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Password</label>
                        <input type="text" name="new_password" class="form-control" placeholder="Leave blank for Chama@1234">
                    </div>
                    <button type="submit" class="btn btn-warning btn-sm fw-bold">
                        <i class="bi bi-key me-1"></i>Reset Password
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Reset all to default -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm border-danger">
            <div class="card-header bg-card fw-semibold text-danger">Reset ALL Members to Default</div>
            <div class="card-body">
                <p class="text-muted small">Sets every member's password to <strong>Chama@1234</strong>. Use this when multiple members can't log in.</p>
                <form method="POST" onsubmit="return confirm('Reset ALL member passwords to Chama@1234?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reset_all_default">
                    <button type="submit" class="btn btn-danger btn-sm fw-bold">
                        <i class="bi bi-arrow-repeat me-1"></i>Reset All to Chama@1234
                    </button>
                </form>
            </div>
        </div>

        <!-- Member login credentials table -->
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-header bg-card fw-semibold">Member Login Details</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>#</th><th>Name</th><th>Login Email</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($members as $m): ?>
                        <tr>
                            <td class="font-monospace small text-primary"><?= $m['membership_number'] ?? '—' ?></td>
                            <td class="small fw-semibold"><?= htmlspecialchars($m['full_name']) ?></td>
                            <td class="small font-monospace"><?= htmlspecialchars($m['email']) ?></td>
                            <td><?= badgeStatus($m['status']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once ROOT . '/includes/footer.php'; ?>
