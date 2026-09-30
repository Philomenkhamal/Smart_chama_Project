<?php
/**
 * CHAMA Financial Management System
 * Member — Profile Management
 * Update personal details and change password
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'My Profile — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireMember();
require_once ROOT . '/includes/header.php';
// Statement quick link injected by ChamaLedger
?>
<div class="no-print mb-3" style="text-align:right">
    <a href="<?= APP_URL ?>/member/statement.php" class="btn btn-outline-success btn-sm">
        <i class="bi bi-file-earmark-text me-1"></i>Download My Statement
    </a>
</div>
<?php

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$user   = currentUser();
$curr   = getSetting('currency', 'KES');

// ── Handle POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // ── Photo upload ──────────────────────────────────────────────────────────
    if ($action === 'upload_photo') {
        $file = $_FILES['profile_photo'] ?? null;
        if ($file && $file['error'] === 0) {
            // Trust the actual file bytes, not the browser-supplied MIME type
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $realMime = $finfo->file($file['tmp_name']);
            $allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png',
                         'image/gif'  => 'gif', 'image/webp' => 'webp'];
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg','jpeg','png','gif','webp'];

            if (!array_key_exists($realMime, $allowed)) {
                setFlash('danger', t('flash_photo_type'));
            } elseif (!in_array($ext, $allowedExts, true)) {
                setFlash('danger', t('flash_photo_ext'));
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                setFlash('danger', t('flash_photo_size'));
            } else {
                $safeExt  = $allowed[$realMime]; // derive extension from real MIME, not filename
                $uploadDir = ROOT . '/uploads/avatars/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $filename = 'avatar_' . $userId . '_' . time() . '.' . $safeExt;
                $destPath = $uploadDir . $filename;
                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    // Delete old photo — use parameterised query, never raw $userId
                    $oldStmt = $pdo->prepare('SELECT profile_photo FROM users WHERE id = ?');
                    $oldStmt->execute([$userId]);
                    $oldPhoto = $oldStmt->fetchColumn();
                    if ($oldPhoto && file_exists(ROOT . '/uploads/avatars/' . $oldPhoto)) {
                        unlink(ROOT . '/uploads/avatars/' . $oldPhoto);
                    }
                    $pdo->prepare("UPDATE users SET profile_photo=? WHERE id=?")->execute([$filename, $userId]);
                    logActivity('PHOTO_UPLOAD', 'Profile photo updated');
                    setFlash('success', t('flash_photo_updated'));
                } else {
                    setFlash('danger', t('flash_photo_failed'));
                }
            }
        } else {
            setFlash('danger', t('flash_photo_error'));
        }
        redirect(APP_URL . '/member/profile.php');
    }

    // ── Remove photo ───────────────────────────────────────────────────────────
    if ($action === 'remove_photo') {
        $rmStmt = $pdo->prepare('SELECT profile_photo FROM users WHERE id = ?');
        $rmStmt->execute([$userId]);
        $oldPhoto = $rmStmt->fetchColumn();
        if ($oldPhoto && file_exists(ROOT . '/uploads/avatars/' . $oldPhoto)) {
            unlink(ROOT . '/uploads/avatars/' . $oldPhoto);
        }
        $pdo->prepare("UPDATE users SET profile_photo=NULL WHERE id=?")->execute([$userId]);
        logActivity('PHOTO_REMOVE', 'Profile photo removed');
        setFlash('success', t('flash_photo_removed'));
        redirect(APP_URL . '/member/profile.php');
    }

    if ($action === 'update_profile') {
        $phone    = cleanInput($_POST['phone']           ?? '');
        $nickname = cleanInput($_POST['nickname']        ?? '');
        $address  = cleanInput($_POST['address']         ?? '');
        $occ      = cleanInput($_POST['occupation']      ?? '');
        $kin      = cleanInput($_POST['next_of_kin']     ?? '');
        $kinPhone = cleanInput($_POST['next_of_kin_phone'] ?? '');

        $errors = [];
        if (!preg_match('/^[0-9]{10,13}$/', $phone)) $errors[] = t('err_phone_digits');

        if (empty($errors)) {
            $pdo->prepare('
                UPDATE users SET phone=?, nickname=?, address=?, occupation=?, next_of_kin=?, next_of_kin_phone=?
                WHERE id=?
            ')->execute([$phone, $nickname, $address, $occ, $kin, $kinPhone, $userId]);
            logActivity('PROFILE_UPDATE', 'User updated profile');
            setFlash('success', t('flash_profile_updated'));
        } else {
            setFlash('danger', implode('<br>', $errors));
        }
        redirect(APP_URL . '/' . $user['role'] . '/profile.php');
    }

    if ($action === 'change_password') {
        $current  = $_POST['current_password']  ?? '';
        $new      = $_POST['new_password']       ?? '';
        $confirm  = $_POST['confirm_password']   ?? '';

        $errors = [];
        if (!password_verify($current, $user['password_hash'])) {
            $errors[] = t('err_wrong_password');
        }
        if (strlen($new) < 8) $errors[] = t('err_new_pw_short');
        if (!preg_match('/[A-Z]/', $new)) $errors[] = t('err_pw_uppercase2');
        if (!preg_match('/[0-9]/', $new)) $errors[] = t('err_pw_number2');
        if ($new !== $confirm) $errors[] = t('err_pw_mismatch2');

        if (empty($errors)) {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $userId]);
            logActivity('PASSWORD_CHANGE', 'User changed password');
            setFlash('success', t('flash_pw_changed'));
        } else {
            setFlash('danger', implode('<br>', $errors));
        }
        redirect(APP_URL . '/' . $user['role'] . '/profile.php');
    }
}

// Reload user fresh from DB
$stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Financial summary
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE user_id=? AND status='confirmed'");
$stmt->execute([$userId]);
$totalSavings = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE user_id=?");
$stmt->execute([$userId]);
$totalLoans = (int)$stmt->fetchColumn();
?>

<div class="mb-4">
    <h4 class="fw-bold mb-0"><?= t('profile_title') ?></h4>
    <small class="text-muted"><?= t('mp_subtitle') ?></small>
</div>

<div class="row g-4">
    <!-- Profile Card -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm text-center mb-3">
            <div class="card-body py-4">
                <?php $photoFile = $user['profile_photo'] ?? null; ?>
                <div class="position-relative d-inline-block mb-3">
                    <?php if ($photoFile && file_exists(ROOT . '/uploads/avatars/' . $photoFile)): ?>
                    <img src="<?= APP_URL ?>/uploads/avatars/<?= htmlspecialchars($photoFile) ?>"
                         class="rounded-circle border border-2 border-primary"
                         style="width:90px;height:90px;object-fit:cover" alt="Profile Photo">
                    <?php else: ?>
                    <div class="avatar-placeholder rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                         style="width:90px;height:90px;font-size:2.2rem">
                        <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                    </div>
                    <?php endif; ?>
                    <!-- Camera overlay trigger -->
                    <label for="photoInput" class="position-absolute bottom-0 end-0 bg-white border rounded-circle d-flex align-items-center justify-content-center"
                           style="width:28px;height:28px;cursor:pointer;box-shadow:0 1px 4px rgba(0,0,0,.2)" title="Change photo">
                        <i class="bi bi-camera-fill text-primary" style="font-size:.75rem"></i>
                    </label>
                </div>

                <!-- Hidden photo upload form -->
                <form method="POST" enctype="multipart/form-data" id="photoForm" style="display:none">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="upload_photo">
                    <input type="file" id="photoInput" name="profile_photo" accept="image/*"
                           onchange="document.getElementById('photoForm').submit()">
                </form>
                <?php if ($photoFile): ?>
                <form method="POST" class="mb-2" onsubmit="return confirm('Remove your profile photo?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="remove_photo">
                    <button class="btn btn-link btn-sm text-danger p-0" style="font-size:.75rem">
                        <i class="bi bi-trash me-1"></i>Remove photo
                    </button>
                </form>
                <?php else: ?>
                <div class="text-muted small mb-2"><?= t('mp_upload_photo') ?></div>
                <?php endif; ?>

                <h5 class="fw-bold mb-1"><?= htmlspecialchars($user['full_name']) ?></h5>
                <p class="text-muted small mb-1"><?= htmlspecialchars($user['email']) ?></p>
                <p class="font-monospace text-primary small fw-semibold mb-2">
                    <?= htmlspecialchars($user['membership_number'] ?? 'Pending') ?>
                </p>
                <?php if (!empty($user['chama_position'])): ?>
                <p class="badge bg-light text-dark border mb-1"><?= htmlspecialchars($user['chama_position']) ?></p>
                <?php endif; ?>
                <?= badgeStatus($user['status']) ?>

                <hr class="my-3">
                <div class="row g-2 text-center small">
                    <div class="col-6">
                        <div class="text-muted"><?= t('mp_joined') ?></div>
                        <strong><?= $user['joined_date'] ? formatDate($user['joined_date'], 'M Y') : 'Pending' ?></strong>
                    </div>
                    <div class="col-6">
                        <div class="text-muted"><?= t('lbl_role') ?></div>
                        <strong><?= ucfirst($user['role']) ?></strong>
                    </div>
                    <div class="col-6">
                        <div class="text-muted"><?= t('mp_total_savings') ?></div>
                        <strong class="text-success"><?= money($totalSavings, $curr) ?></strong>
                    </div>
                    <div class="col-6">
                        <div class="text-muted"><?= t('mp_total_loans') ?></div>
                        <strong><?= $totalLoans ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Membership Card -->
        <div class="card border-0 shadow-sm" style="background: linear-gradient(135deg,#1a56db,#0f172a); color:white;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <div style="font-size:.65rem;opacity:.7;text-transform:uppercase;letter-spacing:.1em">
                            <?= htmlspecialchars(getSetting('group_name','Chama Group')) ?>
                        </div>
                        <div class="fw-bold"><?= htmlspecialchars($user['full_name']) ?></div>
                    </div>
                    <i class="bi bi-bank2 fs-4 opacity-75"></i>
                </div>
                <div class="font-monospace fw-bold fs-5 mb-1">
                    <?= htmlspecialchars($user['membership_number'] ?? '—') ?>
                </div>
                <div style="font-size:.72rem;opacity:.7">
                    Since <?= $user['joined_date'] ? formatDate($user['joined_date'], 'M Y') : '—' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Forms -->
    <div class="col-md-8">
        <!-- Personal Info Form -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-card border-0 pt-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-person me-2 text-primary"></i><?= t('mp_personal_info') ?></h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('profile_name') ?></label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" readonly>
                            <small class="text-muted"><?= t('mp_contact_admin') ?></small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('profile_email') ?></label>
                            <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('profile_phone') ?> <span class="text-danger">*</span></label>
                            <input type="tel" name="phone" class="form-control"
                                   value="<?= htmlspecialchars($user['phone']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('mp_nickname') ?></label>
                            <input type="text" name="nickname" class="form-control"
                                   value="<?= htmlspecialchars($user['nickname'] ?? '') ?>"
                                   placeholder="e.g. Kangaroo, Wakili">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('mp_occupation') ?></label>
                            <input type="text" name="occupation" class="form-control"
                                   value="<?= htmlspecialchars($user['occupation'] ?? '') ?>"
                                   placeholder="e.g. Teacher, Business">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?= t('mp_address') ?></label>
                            <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <h6 class="text-muted small text-uppercase fw-semibold mb-3"><?= t('mp_next_of_kin') ?></h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('lbl_name') ?></label>
                            <input type="text" name="next_of_kin" class="form-control"
                                   value="<?= htmlspecialchars($user['next_of_kin'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('lbl_phone') ?></label>
                            <input type="tel" name="next_of_kin_phone" class="form-control"
                                   value="<?= htmlspecialchars($user['next_of_kin_phone'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-save me-1"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- <?= t('profile_password') ?> -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-card border-0 pt-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-shield-lock me-2 text-danger"></i><?= t('profile_password') ?></h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?= t('profile_current_pw') ?> <span class="text-danger">*</span></label>
                            <input type="password" name="current_password" class="form-control"
                                   placeholder="Enter current password" autocomplete="current-password" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('profile_new_pw') ?> <span class="text-danger">*</span></label>
                            <input type="password" name="new_password" id="newPw" class="form-control"
                                   placeholder="Min 8 chars, 1 uppercase, 1 number"
                                   autocomplete="new-password" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Confirm <?= t('profile_new_pw') ?> <span class="text-danger">*</span></label>
                            <input type="password" name="confirm_password" id="confirmPw" class="form-control"
                                   placeholder="Repeat new password"
                                   autocomplete="new-password" required>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-danger btn-sm">
                            <i class="bi bi-lock me-1"></i><?= t('profile_password') ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

        <!-- Language Setting -->
        <div class="card mb-4">
          <div class="card-header fw-bold">
            <i class="bi bi-translate me-2"></i><?= t('settings_language') ?> / Language
          </div>
          <div class="card-body">
            <div class="d-flex gap-3 flex-wrap">
              <a href="<?= APP_URL ?>/api/set_lang.php?lang=en"
                 class="btn btn-lg <?= getLang()==='en' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                🇬🇧 &nbsp;English
              </a>
              <a href="<?= APP_URL ?>/api/set_lang.php?lang=sw"
                 class="btn btn-lg <?= getLang()==='sw' ? 'btn-success' : 'btn-outline-secondary' ?>">
                🇰🇪 &nbsp;Kiswahili
              </a>
            </div>
            <small class="text-muted mt-2 d-block">
              <?= t('lang_label') ?>: <strong><?= getLang()==='sw' ? 'Kiswahili 🇰🇪' : 'English 🇬🇧' ?></strong>
            </small>
          </div>
        </div>
</div>

<?php
$extraScripts = <<<'JS'
<script>
document.getElementById('confirmPw')?.addEventListener('input', function() {
    const match = this.value === document.getElementById('newPw').value;
    this.classList.toggle('is-valid', match && this.value.length > 0);
    this.classList.toggle('is-invalid', !match && this.value.length > 0);
});
</script>
JS;
require_once ROOT . '/includes/footer.php';
?>
