<?php
/**
 * CHAMA Financial Management System
 * Member — My Contributions / Savings
 * View history, submit payment evidence
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'My Savings — SmartChama';
require_once ROOT . '/includes/functions.php';
requireMember();
require_once ROOT . '/includes/header.php';

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$user   = currentUser();
$curr   = getSetting('currency', 'KES');

if ($user['status'] !== 'active') {
    setFlash('warning', t('flash_acct_not_active'));
    redirect(APP_URL . '/member/dashboard.php');
}

// ── Handle POST: submit a pending contribution ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $amount  = (float)($_POST['amount']         ?? 0);
    $month   = sanitize($_POST['payment_month'] ?? '');
    $method  = sanitize($_POST['payment_method']?? 'mpesa');
    $ref     = sanitize($_POST['reference_code']?? '');
    $notes   = sanitize($_POST['notes']          ?? '');

    $errors = [];
    if ($amount <= 0)  $errors[] = t('err_amount_zero');
    if (!$month)       $errors[] = t('err_month_required');
    if ($method !== 'cash' && empty($ref)) {
        $errors[] = t('err_ref_required');
    }

    if (empty($errors)) {
        $pdo->prepare('
            INSERT INTO contributions (user_id, amount, payment_month, payment_method, reference_code, notes, status)
            VALUES (?, ?, ?, ?, ?, ?, "pending")
        ')->execute([$userId, $amount, $month . '-01', $method, $ref, $notes]);

        // Notify admins
        $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll();
        foreach ($admins as $admin) {
            createNotification($admin['id'], '💰 New Contribution Submitted',
                "{$user['full_name']} submitted a contribution of " . money($amount, $curr) . " for " . date('F Y', strtotime($month . '-01')),
                'info', APP_URL . '/admin/contributions.php');
        }

        logActivity('CONTRIBUTION_SUBMIT', "Member submitted contribution {$curr}{$amount}");
        setFlash('success', t('flash_payment_submitted'));
        redirect(APP_URL . '/member/contributions.php');
        
    } else {
        setFlash('danger', implode('<br>', $errors));
        $_SESSION['reopen_manual'] = true;
    }
}

// ── Fetch contributions ────────────────────────────────────────────────────────
$contributions = $pdo->prepare("
    SELECT c.*, a.full_name AS confirmed_by_name
    FROM contributions c
    LEFT JOIN users a ON c.confirmed_by = a.id
    WHERE c.user_id = ?
    ORDER BY c.payment_month DESC, c.recorded_at DESC
")->execute([$userId]) ? [] : [];

$stmt = $pdo->prepare("
    SELECT c.*, a.full_name AS confirmed_by_name
    FROM contributions c
    LEFT JOIN users a ON c.confirmed_by = a.id
    WHERE c.user_id=? ORDER BY c.payment_month DESC, c.recorded_at DESC
");
$stmt->execute([$userId]);
$contributions = $stmt->fetchAll();

// Stats
$totalConfirmed = 0;
$monthsPaid     = 0;
foreach ($contributions as $c) {
    if ($c['status'] === 'confirmed') {
        $totalConfirmed += $c['amount'];
        $monthsPaid++;
    }
}

$monthlyRequired = (float)getSetting('monthly_contribution', '10300');

// Which months have been paid (confirmed or pending) to show gaps
$paidMonths = [];
foreach ($contributions as $c) {
    if ($c['status'] !== 'rejected') {
        $paidMonths[] = date('Y-m', strtotime($c['payment_month']));
    }
}

// Months since joining that are unpaid
// Safe join date — handle NULL, empty, AND '0000-00-00' (MySQL bad default)
$joinedDate = getMemberStartDate($user); // Auto: later of member join date or chama start
$startMonth   = new DateTime(date('Y-m-01', strtotime($joinedDate)));
$currentMonth = new DateTime(date('Y-m-01'));
$interval     = new DateInterval('P1M');
$period       = new DatePeriod($startMonth, $interval, $currentMonth->modify('+1 month'));
$thisMonth    = date('Y-m');
$missedMonths = [];
foreach ($period as $dt) {
    $key = $dt->format('Y-m');
    if ($key === $thisMonth) continue;
    if (!in_array($key, $paidMonths)) {
        $missedMonths[] = $dt->format('Y-m');
    }
}

// Chart data: last 12 months
$chartStmt = $pdo->prepare("
    SELECT DATE_FORMAT(payment_month,'%b %Y') AS label, SUM(amount) AS total
    FROM contributions WHERE user_id=? AND status='confirmed'
    GROUP BY payment_month ORDER BY payment_month DESC LIMIT 12
");
$chartStmt->execute([$userId]);
$chartRows   = array_reverse($chartStmt->fetchAll());
$chartLabels = json_encode(array_column($chartRows, 'label'));
$chartValues = json_encode(array_column($chartRows, 'total'));
?>

<?php
// Pre-generate everything JS needs — no PHP inside <script>
$jsAppUrl    = APP_URL;
$jsCsrf      = csrfToken();
$jsPhone     = htmlspecialchars($user['phone'] ?? '', ENT_QUOTES);
$jsMonth     = date('Y-m');
$jsAmount    = getSetting('monthly_contribution', '10300');
$jsCurr      = $curr;
$jsReopenManual = isset($_SESSION['reopen_manual']) ? 'true' : 'false';
if (isset($_SESSION['reopen_manual'])) unset($_SESSION['reopen_manual']);
?>

<!-- Flash -->
<?= getFlash() ?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h4><?= t('mdash_balance') ?></h4>
        <div class="subtitle"><?= t('mc_subtitle') ?></div>
    </div>
    <div class="page-header-right d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/member/statement.php" target="_blank"
           class="btn btn-sm btn-outline-success">
            <i class="bi bi-file-earmark-pdf me-1"></i>View / Print Statement
        </a>
        <a href="<?= APP_URL ?>/member/export_statement.php?format=csv"
           class="btn btn-sm btn-success">
            <i class="bi bi-download me-1"></i>Download CSV
        </a>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card green">
            <div class="stat-icon green"><i class="bi bi-piggy-bank-fill"></i></div>
            <div class="stat-label"><?= t('mc_total_saved') ?></div>
            <div class="stat-value sm"><?= money($totalConfirmed, $curr) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="bi bi-calendar-check"></i></div>
            <div class="stat-label"><?= t('mc_months_paid') ?></div>
            <div class="stat-value"><?= $monthsPaid ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card <?= count($missedMonths) > 0 ? 'amber' : 'teal' ?>">
            <div class="stat-icon <?= count($missedMonths) > 0 ? 'amber' : 'teal' ?>">
                <i class="bi bi-<?= count($missedMonths) > 0 ? 'exclamation-triangle' : 'check-circle' ?>-fill"></i>
            </div>
            <div class="stat-label"><?= t('mc_missed') ?></div>
            <div class="stat-value"><?= count($missedMonths) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card teal">
            <div class="stat-icon teal"><i class="bi bi-award-fill"></i></div>
            <div class="stat-label"><?= t('mc_loan_limit') ?></div>
            <div class="stat-value sm"><?= money($totalConfirmed * (float)getSetting('max_loan_multiplier','3'), $curr) ?></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════
     MAKE A PAYMENT CARD
═══════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header">
        <h6><i class="bi bi-cash-coin me-2" style="color:var(--green)"></i><?= t('mc_make_payment') ?></h6>
    </div>
    <div class="card-body" style="padding:1.5rem">

        <!-- Tabs -->
        <div style="display:flex;gap:.5rem;margin-bottom:1.5rem;background:var(--input-bg);border-radius:10px;padding:.3rem">
            <button class="pay-tab active" id="tab-mpesa" onclick="switchPayTab('mpesa')">
                <i class="bi bi-phone me-1"></i>M-Pesa STK Push
            </button>
            <button class="pay-tab" id="tab-manual" onclick="switchPayTab('manual')">
                <i class="bi bi-pencil-square me-1"></i>Manual / Cash / Bank
            </button>
        </div>

        <!-- ── M-PESA STK PANEL ── -->
        <div id="panel-mpesa">

            <!-- Alert box -->
            <div id="stkAlert" style="display:none;padding:.85rem 1rem;border-radius:10px;margin-bottom:1rem;font-size:.875rem"></div>

            <!-- Input row -->
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label"><?= t('mc_mpesa_phone') ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-phone"></i></span>
                        <input type="tel" id="stkPhone" class="form-control"
                               placeholder="0712 345 678">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= t('mc_pay_month') ?></label>
                    <input type="month" id="stkMonth" class="form-control"
                           max="<?= date('Y-m') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Amount (<?= $curr ?>)</label>
                    <div class="input-group">
                        <span class="input-group-text"><?= $curr ?></span>
                        <input type="number" id="stkAmount" class="form-control"
                               value="<?= getSetting('monthly_contribution','10300') ?>"
                               min="0.01" step="0.01">
                    </div>
                </div>
                <div class="col-md-3">
                    <button type="button" class="btn btn-primary w-100" id="stkBtn">
                        <i class="bi bi-phone me-1"></i>Send STK Push
                    </button>
                </div>
            </div>

            <!-- Waiting bar (hidden until push sent) -->
            <div id="stkWaiting" style="display:none;margin-top:1.25rem;background:var(--input-bg);border:1px solid var(--card-border);border-radius:12px;padding:1.25rem">
                <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
                    <div style="width:48px;height:48px;border-radius:50%;background:var(--green-dim);display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:var(--green);flex-shrink:0;animation:vibrate 1.5s ease infinite">
                        <i class="bi bi-phone-vibrate"></i>
                    </div>
                    <div style="flex:1;min-width:160px">
                        <div style="font-weight:600;margin-bottom:.35rem"><?= t('mc_check_phone') ?></div>
                        <div style="height:6px;background:var(--bg);border-radius:99px;overflow:hidden">
                            <div id="stkBar" style="height:100%;width:100%;background:linear-gradient(90deg,#00c471,#00dc7e);border-radius:99px"></div>
                        </div>
                        <div id="stkTimer" style="font-size:.75rem;color:var(--text-muted);margin-top:.3rem">90s remaining</div>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="stkCancelBtn"><?= t('btn_cancel') ?></button>
                </div>
            </div>

            <!-- STK Push note -->
            <div style="margin-top:1rem;padding:.75rem 1rem;background:rgba(59,130,246,0.08);border:1px solid rgba(59,130,246,0.2);border-radius:10px;font-size:.8rem;color:var(--text-muted)">
                <i class="bi bi-info-circle me-1" style="color:var(--blue)"></i>
                STK Push works with a <strong><?= t('mc_paybill') ?></strong>. If your chama uses <strong>Pochi La Biashara</strong>, use the
                <a href="javascript:void(0)" onclick="switchPayTab('manual')" style="color:var(--green);font-weight:600"><?= t('mc_manual_tab') ?></a> instead.
            </div>

            <!-- Steps hint -->
            <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid var(--divider)">
                <?php foreach([['1','Click Send','Request goes to Safaricom'],['2','Phone vibrates','M-Pesa prompt appears'],['3','Enter PIN','Type your M-Pesa PIN'],['4','Done!','Payment auto-confirmed']] as [$n,$t,$d]): ?>
                <div style="display:flex;align-items:flex-start;gap:.6rem;flex:1;min-width:120px">
                    <div style="width:26px;height:26px;border-radius:50%;background:var(--green-dim);color:var(--green);display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0"><?= $n ?></div>
                    <div><div style="font-size:.8rem;font-weight:600"><?= $t ?></div><div style="font-size:.73rem;color:var(--text-muted)"><?= $d ?></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── MANUAL PAYMENT PANEL ── -->
        <div id="panel-manual" style="display:none">

            <!-- Pochi La Biashara instructions -->
            <?php
            $pochiPhone = defined('POCHI_PHONE') ? POCHI_PHONE : '(set in config)';
            $pochiName  = defined('POCHI_NAME')  ? POCHI_NAME  : 'Chama';
            $pochiSteps = [
                ['1', 'Open M-Pesa on your phone'],
                ['2', 'Go to <strong>' . t('mc_send_money') . '</strong>'],
                ['3', 'Enter chama number: <strong style="color:var(--green);font-size:1rem;font-family:monospace">' . htmlspecialchars($pochiPhone) . '</strong>'],
                ['4', 'Enter the amount and confirm with your PIN'],
                ['5', 'Copy the <strong>' . t('mc_mpesa_code') . '</strong> from your SMS'],
                ['6', 'Paste it in the form below and click Submit'],
            ];
            ?>
            <div style="background:rgba(0,196,113,0.07);border:1px solid rgba(0,196,113,0.2);border-radius:12px;padding:1.25rem;margin-bottom:1.5rem">
                <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:.95rem;margin-bottom:.75rem;color:var(--green)">
                    <i class="bi bi-phone-fill me-2"></i>How to Pay via Pochi La Biashara
                </div>
                <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem">
                    <div style="flex:1;min-width:200px">
                        <?php foreach($pochiSteps as [$n, $step]): ?>
                        <div style="display:flex;align-items:flex-start;gap:.6rem;margin-bottom:.5rem">
                            <div style="width:22px;height:22px;border-radius:50%;background:var(--green);color:#060e1a;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:800;flex-shrink:0;margin-top:1px"><?= $n ?></div>
                            <div style="font-size:.83rem;color:var(--text-muted);line-height:1.5"><?= $step ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:10px;padding:1rem;min-width:180px;text-align:center;align-self:flex-start">
                        <div style="font-size:.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.4rem"><?= t('mc_send_money_to') ?></div>
                        <div style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:var(--green);letter-spacing:.05em"><?= htmlspecialchars($pochiPhone) ?></div>
                        <div style="font-size:.8rem;color:var(--text-muted);margin-top:.2rem"><?= htmlspecialchars($pochiName) ?></div>
                        <div style="margin-top:.75rem;font-size:.7rem;background:rgba(0,196,113,0.1);border-radius:6px;padding:.35rem .6rem;color:var(--green)">
                            <i class="bi bi-info-circle me-1"></i>Pochi La Biashara
                        </div>
                    </div>
                </div>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><?= t('mc_pay_month') ?></label>
                        <input type="month" name="payment_month" class="form-control"
                               value="<?= date('Y-m') ?>" max="<?= date('Y-m') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Amount (<?= $curr ?>)</label>
                        <div class="input-group">
                            <span class="input-group-text"><?= $curr ?></span>
                            <input type="number" name="amount" class="form-control"
                                   value="<?= getSetting('monthly_contribution','10300') ?>"
                                   min="0.01" step="0.01" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= t('contrib_method') ?></label>
                        <select name="payment_method" class="form-select" onchange="this.value==='cash' ? document.getElementById('refWrap').style.display='none' : document.getElementById('refWrap').style.display='block'">
                            <option value="mpesa">M-Pesa (reference)</option>
                            <option value="bank"><?= t('mc_bank_transfer') ?></option>
                            <option value="cash"><?= t('lbl_cash') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3" id="refWrap">
                        <label class="form-label"><?= t('lbl_mpesa_ref') ?></label>
                        <input type="text" name="reference_code" id="contribRefCode" class="form-control"
                               placeholder="e.g. QHX7Y8Z9AB" style="font-family:monospace;text-transform:uppercase" autocomplete="off">
                        <div id="contribRefFeedback" class="mpesa-ref-feedback"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes <span style="font-weight:400;text-transform:none;color:var(--text-muted)"><?= t('mc_optional') ?></span></label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Any extra details..."></textarea>
                    </div>
                    <div class="col-12">
                        <div style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.2);border-radius:10px;padding:.75rem 1rem;font-size:.82rem;color:var(--text-muted);margin-bottom:.75rem">
                            <i class="bi bi-clock me-1" style="color:var(--amber)"></i>
                            Manual payments await admin confirmation — usually within 24 hours.
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send me-1"></i>Submit Payment
                        </button>
                    </div>
                </div>
            </form>
        </div>

    </div>
</div>

<!-- Contribution History -->
<div class="card">
    <div class="card-header">
        <h6><i class="bi bi-table me-2" style="color:var(--green)"></i>Contribution History</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th><?= t('contrib_month') ?></th><th><?= t('contrib_amount') ?></th><th><?= t('contrib_method') ?></th>
                    <th><?= t('contrib_receipt') ?></th><th><?= t('contrib_status') ?></th><th><?= t('contrib_date') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($contributions)): ?>
                <tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted)">
                    <?= t('contrib_no_records') ?> yet — make your first payment above!
                </td></tr>
            <?php else: foreach ($contributions as $c): ?>
                <tr>
                    <td style="font-weight:600"><?= monthLabel($c['payment_month']) ?></td>
                    <td style="color:var(--green);font-weight:600"><?= money($c['amount'], $curr) ?></td>
                    <td>
                        <?php if ($c['payment_method'] === 'mpesa'): ?>
                            <span class="badge bg-success"><i class="bi bi-phone me-1"></i>M-Pesa</span>
                        <?php elseif ($c['payment_method'] === 'bank'): ?>
                            <span class="badge bg-info">Bank</span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><?= t('lbl_cash') ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="font-family:monospace;font-size:.8rem;color:var(--text-muted)"><?= htmlspecialchars($c['reference_code'] ?? '—') ?></td>
                    <td><?= badgeStatus($c['status']) ?></td>
                    <td style="color:var(--text-muted);font-size:.8rem"><?= formatDate($c['recorded_at'], 'd M Y') ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.pay-tab { flex:1;padding:.55rem 1rem;background:transparent;border:none;color:var(--text-muted);border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer }
.pay-tab.active { background:var(--card-bg);color:var(--green);box-shadow:var(--shadow) }
.pay-tab:hover:not(.active) { color:var(--text) }
@keyframes vibrate {
    0%,100%{transform:rotate(0)} 20%{transform:rotate(-12deg)} 40%{transform:rotate(12deg)}
    60%{transform:rotate(-8deg)} 80%{transform:rotate(8deg)}
}
@keyframes shrink { from{width:100%} to{width:0%} }
.spin-sm { display:inline-block;width:14px;height:14px;border:2px solid rgba(0,0,0,0.3);border-top-color:transparent;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:5px }
@keyframes spin { to{transform:rotate(360deg)} }
.mpesa-ref-feedback { font-size:.74rem;margin-top:.3rem;padding:.25rem .5rem;border-radius:6px;min-height:1.4rem;transition:all .2s; }
.mpesa-ref-feedback.valid { color:#00c471;background:rgba(0,196,113,.08); }
.mpesa-ref-feedback.invalid { color:#ef4444;background:rgba(239,68,68,.08); }
.mpesa-ref-feedback.checking { color:var(--text-muted); }
.mpesa-spin { display:inline-block;width:11px;height:11px;border:2px solid rgba(255,255,255,.2);border-top-color:var(--green);border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:4px; }
</style>

<script>
// All PHP values injected as plain JS variables — no PHP inside logic
var STK_CSRF  = "<?= addslashes($jsCsrf) ?>";
var STK_APP   = "<?= addslashes($jsAppUrl) ?>";
var STK_PHONE = "<?= addslashes($jsPhone) ?>";
var STK_MONTH = "<?= $jsMonth ?>";

// Set default values
document.getElementById('stkPhone').value = STK_PHONE;
document.getElementById('stkMonth').value = STK_MONTH;

// Tab switching
function switchPayTab(tab) {
    document.getElementById('panel-mpesa').style.display  = tab === 'mpesa'  ? 'block' : 'none';
    document.getElementById('panel-manual').style.display = tab === 'manual' ? 'block' : 'none';
    document.getElementById('tab-mpesa').classList.toggle('active',  tab === 'mpesa');
    document.getElementById('tab-manual').classList.toggle('active', tab === 'manual');
}

// Auto open manual tab if form had errors
if (<?= $jsReopenManual ?>) switchPayTab('manual');

// STK state
var stkPollTimer = null;
var stkCountdown = null;
var stkCheckoutId = null;

function stkShowAlert(msg, type) {
    var el = document.getElementById('stkAlert');
    var colors = {
        danger:  { bg: 'rgba(239,68,68,0.1)',   border: 'rgba(239,68,68,0.3)',   color: '#fca5a5' },
        success: { bg: 'rgba(0,196,113,0.1)',    border: 'rgba(0,196,113,0.3)',   color: '#6ee7b7' },
        warning: { bg: 'rgba(245,158,11,0.1)',   border: 'rgba(245,158,11,0.3)',  color: '#fcd34d' }
    };
    var c = colors[type] || colors.danger;
    el.style.background   = c.bg;
    el.style.border       = '1px solid ' + c.border;
    el.style.color        = c.color;
    el.style.borderRadius = '10px';
    el.innerHTML = msg;
    el.style.display = 'block';
}

function stkHideAlert() {
    document.getElementById('stkAlert').style.display = 'none';
}

// Wire up button with addEventListener — most reliable
document.getElementById('stkBtn').addEventListener('click', function() {
    var phone  = document.getElementById('stkPhone').value.trim();
    var month  = document.getElementById('stkMonth').value.trim();
    var amount = parseFloat(document.getElementById('stkAmount').value);
    var btn    = document.getElementById('stkBtn');

    stkHideAlert();

    if (!phone)             return stkShowAlert('Please enter your M-Pesa phone number.', 'danger');
    if (!month)             return stkShowAlert('Please select a payment month.', 'danger');
    if (!amount || amount <= 0) return stkShowAlert('Please enter a valid amount.', 'danger');

    btn.disabled  = true;
    btn.innerHTML = '<span class="spin-sm"></span>Sending request…';

    fetch(STK_APP + '/api/mpesa_stk.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
            csrf_token:    STK_CSRF,
            phone:         phone,
            payment_month: month,
            amount:        amount
        })
    })
    .then(function(res) { return res.text(); })
    .then(function(text) {
        var data;
        try { data = JSON.parse(text); }
        catch(e) {
            stkShowAlert('Server error — ' + text.substring(0, 300), 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-phone me-1"></i>Send STK Push';
            return;
        }

        if (!data.success) {
            var msg = data.message || 'Request failed. Try again.';
            if (data.can_clear) {
                msg += ' <a href="' + STK_APP + '/api/mpesa_clear.php" style="color:inherit;font-weight:700;text-decoration:underline">Click here to clear and retry</a>';
            }
            stkShowAlert(msg, 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-phone me-1"></i>Send STK Push';
            return;
        }

        // Success — show waiting state
        stkCheckoutId = data.checkout_request_id;
        document.getElementById('stkWaiting').style.display = 'block';
        btn.style.display = 'none';
        stkStartCountdown(90);
        stkStartPolling();
    })
    .catch(function(err) {
        stkShowAlert('Network error: ' + err.message, 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-phone me-1"></i>Send STK Push';
    });
});

// Cancel button
document.getElementById('stkCancelBtn').addEventListener('click', function() {
    stkStop();
    document.getElementById('stkWaiting').style.display = 'none';
    var btn = document.getElementById('stkBtn');
    btn.style.display = 'block';
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-phone me-1"></i>Send STK Push';
});

function stkStartCountdown(secs) {
    var remaining = secs;
    var bar = document.getElementById('stkBar');
    var timerEl = document.getElementById('stkTimer');
    bar.style.transition = 'width ' + secs + 's linear';
    bar.style.width = '0%';
    stkCountdown = setInterval(function() {
        remaining--;
        timerEl.textContent = remaining + 's remaining';
        if (remaining <= 0) stkStop();
    }, 1000);
}

function stkStartPolling() {
    stkPollTimer = setInterval(function() {
        fetch(STK_APP + '/api/mpesa_status.php?checkout_id=' + stkCheckoutId, {
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status !== 'pending') {
                stkStop();
                stkHandleResult(data);
            }
        })
        .catch(function() {});
    }, 3000);
}

function stkStop() {
    clearInterval(stkPollTimer);
    clearInterval(stkCountdown);
}

function stkHandleResult(data) {
    document.getElementById('stkWaiting').style.display = 'none';
    var btn = document.getElementById('stkBtn');
    btn.style.display = 'block';
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-phone me-1"></i>Send STK Push';

    if (data.status === 'completed') {
        stkShowAlert('✅ Payment confirmed! Receipt: <strong>' + (data.receipt || '') + '</strong> — refreshing…', 'success');
        setTimeout(function() { location.reload(); }, 3000);
    } else if (data.status === 'cancelled') {
        stkShowAlert('⚠️ You cancelled the payment. You can try again.', 'warning');
    } else {
        stkShowAlert('❌ ' + (data.message || 'Payment failed. Please try again.'), 'danger');
    }
}

function attachMpesaValidation(inputId, feedbackId, appUrl) {
    var input = document.getElementById(inputId);
    var feedback = document.getElementById(feedbackId);
    if (!input || !feedback) return;
    var timer = null;

    // Safaricom receipt format: starts with letter, 10 chars total, A-Z and 0-9 only
    function mpesaFormatCheck(code) {
        if (!code) return null;
        if (!/^[A-Z0-9]+$/.test(code))   return 'Only uppercase letters and numbers allowed';
        if (!/^[A-Z]/.test(code))         return 'M-Pesa codes always start with a letter (e.g. P, Q, R…)';
        if (code.length < 10)             return code.length + '/10 characters — keep going…';
        if (code.length > 10)             return 'Too long — M-Pesa codes are exactly 10 characters';
        if (!/[0-9]/.test(code))          return 'Code must contain at least one number';
        if (!/[A-Z]/.test(code.slice(1))) return 'Code must contain at least one letter after the first';
        return 'ok';
    }

    input.addEventListener('input', function() {
        var code = input.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 10);
        input.value = code;
        feedback.innerHTML = '';
        feedback.className = 'mpesa-ref-feedback';
        input.className = input.className.replace(/ ?is-valid| ?is-invalid/g, '');
        if (!code) return;

        var check = mpesaFormatCheck(code);
        if (check !== 'ok') {
            var isTyping = code.length < 10 && check.indexOf('/10') !== -1;
            feedback.innerHTML = '<i class="bi bi-' + (isTyping ? 'hourglass-split' : 'x-circle') + ' me-1"></i>' + check;
            feedback.className = 'mpesa-ref-feedback ' + (isTyping ? 'checking' : 'invalid');
            if (!isTyping) input.classList.add('is-invalid');
            return;
        }

        // Valid format — check against our database
        feedback.innerHTML = '<span class="mpesa-spin"></span>Checking…';
        feedback.className = 'mpesa-ref-feedback checking';
        clearTimeout(timer);
        timer = setTimeout(function() {
            fetch(appUrl + '/api/mpesa_verify.php?code=' + encodeURIComponent(code), { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    feedback.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Valid M-Pesa code ✓';
                    feedback.className = 'mpesa-ref-feedback valid';
                    input.classList.add('is-valid');
                    input.classList.remove('is-invalid');
                } else {
                    feedback.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i>' + data.message;
                    feedback.className = 'mpesa-ref-feedback invalid';
                    input.classList.add('is-invalid');
                    input.classList.remove('is-valid');
                }
            })
            .catch(function() {
                feedback.innerHTML = '<i class="bi bi-wifi-off me-1"></i>Could not verify — submit anyway';
                feedback.className = 'mpesa-ref-feedback checking';
            });
        }, 500);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    attachMpesaValidation('contribRefCode','contribRefFeedback', STK_APP);
});
</script>

<?php require_once ROOT . '/includes/footer.php'; ?>
