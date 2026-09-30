<?php
/**
 * CHAMA Financial Management System
 * Admin — <?= t('settings_title') ?>
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Settings — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();
require_once ROOT . '/includes/header.php';

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $keys = ['group_name','monthly_contribution','loan_interest_rate','max_loan_multiplier',
             'currency','membership_prefix','loan_prefix','admin_email',
             'late_fine_amount','late_fine_grace_days',
             'loan_min_months','loan_min_consecutive'];
    // Checkbox settings (save 0 if unchecked)
    foreach (['two_fa_enabled','late_fine_enabled','loan_block_unpaid_fines','loan_block_active_loan'] as $chk) {
        $val = isset($_POST[$chk]) ? '1' : '0';
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
            ->execute([$chk, $val, $val]);
    }
    foreach ($keys as $key) {
        if (isset($_POST[$key])) {
            $val = sanitize($_POST[$key]);
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
                ->execute([$key, $val, $val]);
        }
    }

    // Change admin password
    if (!empty($_POST['new_password']) && !empty($_POST['confirm_password'])) {
        if ($_POST['new_password'] === $_POST['confirm_password'] && strlen($_POST['new_password']) >= 8) {
            $hash = password_hash($_POST['new_password'], PASSWORD_BCRYPT, ['cost'=>12]);
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $_SESSION['user_id']]);
            setFlash('success', 'Settings saved and password updated.');
        } else {
            setFlash('warning', 'Settings saved, but password was NOT changed (min 8 chars, passwords must match).');
        }
    } else {
        setFlash('success', 'Settings saved successfully.');
    }

    logActivity('SETTINGS_UPDATE', 'Admin updated system settings');
    redirect(APP_URL . '/admin/settings.php');
}

// Load all settings
$allSettings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
function setting(string $key, string $default = ''): string {
    global $allSettings;
    return htmlspecialchars($allSettings[$key] ?? $default, ENT_QUOTES, 'UTF-8');
}
?>

<div class="mb-4">
    <h4 class="fw-bold mb-0"><?= t('settings_title') ?></h4>
    <small class="text-muted"><?= t('settings_subtitle') ?></small>
</div>

<div class="row g-4">
    <div class="col-md-8">
        <form method="POST">
            <?= csrfField() ?>

            <!-- Group Info -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-card border-0 pt-3">
                    <h6 class="fw-semibold mb-0"><i class="bi bi-building me-2 text-primary"></i><?= t('settings_group_info') ?></h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('settings_group_name') ?></label>
                            <input type="text" name="group_name" class="form-control"
                                   value="<?= setting('group_name','My Chama Group') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('settings_admin_email') ?></label>
                            <input type="email" name="admin_email" class="form-control"
                                   value="<?= setting('admin_email') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= t('settings_currency_sym') ?></label>
                            <input type="text" name="currency" class="form-control"
                                   value="<?= setting('currency','KES') ?>" placeholder="KES">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= t('settings_member_prefix') ?></label>
                            <input type="text" name="membership_prefix" class="form-control"
                                   value="<?= setting('membership_prefix','CHM') ?>" placeholder="CHM">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= t('settings_loan_prefix') ?></label>
                            <input type="text" name="loan_prefix" class="form-control"
                                   value="<?= setting('loan_prefix','LN') ?>" placeholder="LN">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Financial Parameters -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-card border-0 pt-3">
                    <h6 class="fw-semibold mb-0"><i class="bi bi-sliders me-2 text-success"></i><?= t('settings_financial') ?></h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= t('settings_monthly') ?></label>
                            <div class="input-group">
                                <span class="input-group-text"><?= setting('currency','KES') ?></span>
                                <input type="number" name="monthly_contribution" class="form-control"
                                       value="<?= setting('monthly_contribution','2000') ?>"
                                       min="0" step="1">
                            </div>
                            <small class="text-muted"><?= t('settings_monthly_desc') ?></small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= t('adm_set_loan_rate') ?></label>
                            <div class="input-group">
                                <input type="number" name="loan_interest_rate" class="form-control"
                                       value="<?= setting('loan_interest_rate','10') ?>"
                                       min="0" max="100" step="0.5">
                                <span class="input-group-text"><?= t('adm_set_per_month') ?></span>
                            </div>
                            <small class="text-muted"><?= t('adm_set_flat_rate') ?></small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= t('adm_set_max_mult') ?></label>
                            <div class="input-group">
                                <input type="number" name="max_loan_multiplier" class="form-control"
                                       value="<?= setting('max_loan_multiplier','3') ?>"
                                       min="1" max="10" step="0.5">
                                <span class="input-group-text"><?= t('adm_set_times_savings') ?></span>
                            </div>
                            <small class="text-muted"><?= t('adm_set_max_desc') ?></small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loan Eligibility Rules -->
            <div class="card border-0 shadow-sm mb-4" style="border-left:4px solid #00c471!important">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-1"><i class="bi bi-shield-check me-2 text-success"></i><?= t('adm_set_eligibility') ?></h6>
                    <p class="text-muted mb-3" style="font-size:.82rem">
                        These rules are enforced automatically. Members who don't meet them cannot submit a loan application.
                    </p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:.82rem"><?= t('adm_set_min_months') ?></label>
                            <div class="input-group">
                                <input type="number" name="loan_min_months" class="form-control"
                                       value="<?= getSetting('loan_min_months','3') ?>" min="1" max="24">
                                <span class="input-group-text"><?= t('adm_set_months') ?></span>
                            </div>
                            <small class="text-muted"><?= t('set_months_hint') ?></small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:.82rem"><?= t('adm_set_consec') ?></label>
                            <div class="input-group">
                                <input type="number" name="loan_min_consecutive" class="form-control"
                                       value="<?= getSetting('loan_min_consecutive','3') ?>" min="1" max="12">
                                <span class="input-group-text"><?= t('adm_set_months') ?></span>
                            </div>
                            <small class="text-muted"><?= t('set_consec_hint') ?></small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold d-block" style="font-size:.82rem"><?= t('set_additional') ?></label>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" name="loan_block_unpaid_fines"
                                       id="blockFines" value="1"
                                       <?= getSetting('loan_block_unpaid_fines','1')==='1'?'checked':'' ?>>
                                <label class="form-check-label small" for="blockFines">
                                    Block members with unpaid fines
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="loan_block_active_loan"
                                       id="blockActiveLoan" value="1"
                                       <?= getSetting('loan_block_active_loan','1')==='1'?'checked':'' ?>>
                                <label class="form-check-label small" for="blockActiveLoan">
                                    Block members with existing active loan
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 p-3 rounded-3" style="background:#f0fdf4;font-size:.8rem;color:#166534">
                        <i class="bi bi-cpu-fill me-1"></i>
                        <strong><?= t('set_ai_score') ?></strong> — Members also see an AI-generated credit score (0–100) based on
                        payment consistency, repayment history, profile completeness, and membership duration.
                        This score is shown to them but does not automatically approve or reject — only the rules above block applications.
                    </div>
                </div>
            </div>

            <!-- Change Password -->
            <div class="card border-0 shadow-sm mb-4">

                <!-- Late Fines Card -->
            <div class="settings-card">
                <h6 class="settings-card-title"><i class="bi bi-lightning-charge me-2 text-warning"></i><?= t('set_auto_fines') ?></h6>
                <p class="text-muted" style="font-size:.82rem"><?= t('set_auto_fines_desc') ?></p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" style="font-size:.82rem"><?= t('adm_set_late_fine') ?> (<?= getSetting('currency','KES') ?>)</label>
                        <input type="number" name="late_fine_amount" class="form-control form-control-sm"
                            value="<?= getSetting('late_fine_amount','500') ?>" min="0" step="1">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="font-size:.82rem"><?= t('set_grace_period') ?></label>
                        <input type="number" name="late_fine_grace_days" class="form-control form-control-sm"
                            value="<?= getSetting('late_fine_grace_days','5') ?>" min="0" max="30">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="late_fine_enabled"
                                id="lateFineToggle" value="1" <?= getSetting('late_fine_enabled','1')==='1'?'checked':'' ?>>
                            <label class="form-check-label" for="lateFineToggle" style="font-size:.82rem"><?= t('set_enable_auto') ?></label>
                        </div>
                    </div>
                </div>
            </div>
                <div class="card-header bg-card border-0 pt-3">
                    <h6 class="fw-semibold mb-0"><i class="bi bi-shield-lock me-2 text-danger"></i><?= t('adm_set_change_pw') ?></h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('adm_mem_new_pw') ?></label>
                            <input type="password" name="new_password" class="form-control"
                                   placeholder="Min 8 characters" autocomplete="new-password">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('reset_confirm_pw') ?></label>
                            <input type="password" name="confirm_password" class="form-control"
                                   placeholder="Repeat new password" autocomplete="new-password">
                        </div>
                    </div>
                    <small class="text-muted mt-2 d-block"><?= t('adm_set_leave_blank') ?></small>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-2"></i>Save All Settings
            </button>
        </form>
    </div>

    <!-- Info Panel -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="fw-semibold mb-3"><?= t('adm_set_sys_info') ?></h6>
                <dl class="row small mb-0">
                    <dt class="col-5"><?= t('adm_set_php_ver') ?></dt>
                    <dd class="col-7"><?= PHP_VERSION ?></dd>
                    <dt class="col-5"><?= t('adm_set_server') ?></dt>
                    <dd class="col-7"><?= $_SERVER['SERVER_SOFTWARE'] ?? 'N/A' ?></dd>
                    <dt class="col-5"><?= t('adm_set_app_ver') ?></dt>
                    <dd class="col-7"><?= APP_VERSION ?></dd>
                    <dt class="col-5">Database</dt>
                    <dd class="col-7"><?= DB_NAME ?></dd>
                    <dt class="col-5">Timezone</dt>
                    <dd class="col-7"><?= TIMEZONE ?></dd>
                    <dt class="col-5">Date</dt>
                    <dd class="col-7"><?= date('d M Y') ?></dd>
                </dl>
            </div>
        </div>
        <div class="card border-0 shadow-sm bg-warning-subtle">
            <div class="card-body small">
                <strong>⚠️ Production Checklist:</strong>
                <ul class="ps-3 mt-2 mb-0">
                    <li>Change the default admin password</li>
                    <li>Update <code>APP_URL</code> in config/db.php</li>
                    <li>Remove demo credentials from login page</li>
                    <li>Set strong MySQL password</li>
                    <li>Enable HTTPS on your server</li>
                </ul>
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

<?php require_once ROOT . '/includes/footer.php'; ?>
