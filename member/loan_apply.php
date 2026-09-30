<?php
/**
 * SmartChama — Member Loan Application
 * Full eligibility check + AI credit score
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
require_once ROOT . '/includes/loan_eligibility.php';
requireMember();
$pageTitle = t('loan_apply_title') . ' — SmartChama';
require_once ROOT . '/includes/header.php';

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$user   = currentUser();
$curr   = getSetting('currency', 'KES');

// Only active members
if ($user['status'] !== 'active') {
    setFlash('warning', 'Your account must be active to apply for a loan.');
    redirect(APP_URL . '/member/dashboard.php');
}

// Run full eligibility check
$elig        = checkLoanEligibility($pdo, $userId);
$eligible    = $elig['eligible'];
$maxLoan     = $elig['max_loan'];
$totalSavings= $elig['total_savings'];
$multiplier  = $elig['multiplier'];
$defaultRate = (float)getSetting('loan_interest_rate', '10');
$errors      = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    // Re-run eligibility server-side (can't trust client)
    if (!$eligible) {
        $errors[] = 'You do not meet the loan eligibility requirements.';
    } else {
        $amount  = (float)($_POST['amount']  ?? 0);
        $months  = (int)($_POST['months']    ?? 0);
        $purpose = sanitize($_POST['purpose'] ?? '');

        if ($amount < 1000)
            $errors[] = 'Minimum loan amount is ' . money(1000, $curr) . '.';
        elseif ($amount > $maxLoan)
            $errors[] = "Maximum loan you qualify for is " . money($maxLoan, $curr) . ".";
        elseif ($months < 1 || $months > 24)
            $errors[] = 'Repayment period must be between 1 and 24 months.';
        elseif (strlen($purpose) < 10)
            $errors[] = 'Please describe your loan purpose (at least 10 characters).';

        if (empty($errors)) {
            $loanNumber = generateLoanNumber();
            $calc       = calculateLoan($amount, $defaultRate, $months);

            $stmt = $pdo->prepare('
                INSERT INTO loans
                    (user_id, loan_number, amount_requested, interest_rate,
                     duration_months, purpose, total_repayable, balance, status)
                VALUES (?,?,?,?,?,?,?,?,"pending")
            ');
            $stmt->execute([
                $userId, $loanNumber, $amount, $defaultRate,
                $months, $purpose, $calc['total_repayable'], $calc['total_repayable'],
            ]);

            $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll();
            foreach ($admins as $admin) {
                createNotification($admin['id'], 'New Loan Application',
                    "{$user['full_name']} applied for a loan of " . money($amount, $curr) .
                    " · Credit Score: {$elig['score']}/100 ({$elig['score_label']})",
                    'info', APP_URL . '/admin/loans.php');
            }
            logActivity('LOAN_APPLY', "Loan #{$loanNumber} applied for {$curr}{$amount} | Score:{$elig['score']}", $userId);
            setFlash('success', "Loan application {$loanNumber} submitted! The committee will review within 3–5 days.");
            redirect(APP_URL . '/member/my_loans.php');
        }
    }
}
?>

<style>
.score-ring-wrap { position:relative; width:130px; height:130px; margin:0 auto; }
.score-ring-wrap svg { transform:rotate(-90deg); }
.score-center { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); text-align:center; }
.score-num { font-size:2rem; font-weight:800; line-height:1; }
.score-lbl { font-size:.7rem; font-weight:600; letter-spacing:.08em; text-transform:uppercase; opacity:.75; }
.elig-check { display:flex; align-items:flex-start; gap:.6rem; padding:.55rem .75rem; border-radius:8px; margin-bottom:.4rem; font-size:.85rem; }
.elig-check.pass  { background:#f0fdf4; color:#166534; }
.elig-check.fail  { background:#fef2f2; color:#991b1b; }
.elig-check.warn  { background:#fffbeb; color:#92400e; }
.elig-check i     { font-size:1rem; margin-top:.05rem; flex-shrink:0; }
.score-bar-wrap { height:8px; border-radius:99px; background:#e5e7eb; overflow:hidden; margin-top:.4rem; }
.score-bar { height:100%; border-radius:99px; transition:width 1s ease; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><?= t('la_title') ?></h4>
        <small class="text-muted"><?= t('la_subtitle') ?></small>
    </div>
    <a href="<?= APP_URL ?>/member/my_loans.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>My Loans
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e) echo "<li>{$e}</li>"; ?></ul>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ── LEFT: Form or Blocked ─────────────────────────────────────────── -->
    <div class="col-lg-7">
        <?php if (!$eligible): ?>
        <!-- BLOCKED -->
        <div class="card border-0 shadow-sm" style="border-left:4px solid #ef4444!important">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:48px;height:48px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center">
                        <i class="bi bi-x-circle-fill text-danger fs-4"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-danger"><?= t('la_not_eligible') ?></h5>
                        <small class="text-muted"><?= t('la_not_eligible_sub') ?></small>
                    </div>
                </div>
                <hr>
                <p class="fw-semibold small mb-2"><?= t('la_why_not') ?></p>
                <?php foreach ($elig['reasons'] as $r): ?>
                <div class="elig-check fail">
                    <i class="bi bi-x-circle-fill"></i>
                    <span><?= htmlspecialchars($r) ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (!empty($elig['warnings'])): ?>
                <p class="fw-semibold small mb-2 mt-3"><?= t('la_also_note') ?></p>
                <?php foreach ($elig['warnings'] as $w): ?>
                <div class="elig-check warn">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span><?= htmlspecialchars($w) ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                <div class="mt-3 p-3 rounded-3" style="background:#f8fafc;font-size:.82rem;color:#475569">
                    <i class="bi bi-lightbulb-fill text-warning me-1"></i>
                    <strong><?= t('la_what_to_do') ?></strong> Pay contributions consistently every month, clear any outstanding fines,
                    and return after <?= getSetting('loan_min_months','3') ?> months of membership to re-apply.
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- ELIGIBLE FORM -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-card pt-3 pb-0 border-0">
                <h6 class="fw-semibold"><?= t('la_form_title') ?></h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <?= t('loan_amount') ?> (<?= $curr ?>) <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><?= $curr ?></span>
                            <input type="number" name="amount" id="calc_amount" class="form-control"
                                   placeholder="0.00" min="1000" max="<?= $maxLoan ?>" step="100"
                                   value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" required>
                        </div>
                        <small class="text-muted">
                            Max: <strong class="text-success"><?= money($maxLoan, $curr) ?></strong>
                            (<?= $multiplier ?>× your savings of <?= money($totalSavings, $curr) ?>)
                        </small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Repayment Period <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="months" id="calc_months" class="form-control"
                                   placeholder="e.g. 6" min="1" max="24"
                                   value="<?= htmlspecialchars($_POST['months'] ?? '') ?>" required>
                            <span class="input-group-text"><?= t('la_months') ?></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= t('la_interest') ?></label>
                        <div class="input-group">
                            <input type="number" class="form-control" value="<?= $defaultRate ?>" readonly>
                            <span class="input-group-text"><?= t('lbl_per_month_flat') ?></span>
                        </div>
                        <small class="text-muted"><?= t('lbl_group_rate') ?></small>
                    </div>

                    <div id="calcResult" class="mb-3"></div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Purpose of Loan <span class="text-danger">*</span></label>
                        <textarea name="purpose" class="form-control" rows="3"
                            placeholder="e.g. School fees, business capital, medical emergency…"
                            required><?= htmlspecialchars($_POST['purpose'] ?? '') ?></textarea>
                        <small class="text-muted"><?= t('lbl_purpose_hint') ?></small>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-send me-2"></i>Submit Loan Application
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── RIGHT: AI Credit Score + Eligibility ──────────────────────────── -->
    <div class="col-lg-5">

        <!-- AI Score Card -->
        <div class="card border-0 shadow-sm mb-3" style="border-top:3px solid <?= $elig['score_color'] ?>!important">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-cpu-fill" style="color:<?= $elig['score_color'] ?>"></i>
                    <h6 class="fw-bold mb-0"><?= t('la_ai_score') ?></h6>
                    <span class="badge ms-auto" style="background:<?= $elig['score_color'] ?>22;color:<?= $elig['score_color'] ?>;font-size:.7rem">
                        <?= $elig['score_label'] ?>
                    </span>
                </div>

                <!-- Ring -->
                <div class="score-ring-wrap mb-3">
                    <svg width="130" height="130" viewBox="0 0 130 130">
                        <circle cx="65" cy="65" r="55" fill="none" stroke="#e5e7eb" stroke-width="12"/>
                        <circle cx="65" cy="65" r="55" fill="none"
                                stroke="<?= $elig['score_color'] ?>" stroke-width="12"
                                stroke-linecap="round"
                                stroke-dasharray="345.6"
                                stroke-dashoffset="<?= round(345.6 * (1 - $elig['score'] / 100)) ?>"
                                id="scoreArc"/>
                    </svg>
                    <div class="score-center">
                        <div class="score-num" style="color:<?= $elig['score_color'] ?>"><?= $elig['score'] ?></div>
                        <div class="score-lbl" style="color:<?= $elig['score_color'] ?>">/ 100</div>
                    </div>
                </div>

                <p class="text-center small text-muted mb-3 px-2">
                    <?= htmlspecialchars($elig['recommendation']) ?>
                </p>

                <!-- Score breakdown bar -->
                <div class="score-bar-wrap mb-1">
                    <div class="score-bar" style="width:<?= $elig['score'] ?>%;background:<?= $elig['score_color'] ?>"></div>
                </div>
                <div class="d-flex justify-content-between" style="font-size:.7rem;color:#9ca3af">
                    <span><?= t('la_score_poor') ?></span><span>50 — Fair</span><span>100 — Excellent</span>
                </div>
            </div>
        </div>

        <!-- Eligibility Checklist -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-card border-0 pt-3 pb-1">
                <h6 class="fw-semibold mb-0 small"><i class="bi bi-list-check me-2 text-primary"></i><?= t('la_eligibility') ?></h6>
            </div>
            <div class="card-body pt-2 pb-3">
                <?php foreach ($elig['positive'] as $p): ?>
                <div class="elig-check pass">
                    <i class="bi bi-check-circle-fill"></i>
                    <span><?= htmlspecialchars($p) ?></span>
                </div>
                <?php endforeach; ?>
                <?php foreach ($elig['reasons'] as $r): ?>
                <div class="elig-check fail">
                    <i class="bi bi-x-circle-fill"></i>
                    <span><?= htmlspecialchars($r) ?></span>
                </div>
                <?php endforeach; ?>
                <?php foreach ($elig['warnings'] as $w): ?>
                <div class="elig-check warn">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span><?= htmlspecialchars($w) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Loan Details -->
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="fw-semibold mb-3 small"><i class="bi bi-info-circle me-2 text-primary"></i><?= t('la_your_details') ?></h6>
                <dl class="row small mb-0">
                    <dt class="col-7"><?= t('la_total_savings') ?></dt>
                    <dd class="col-5 fw-semibold"><?= money($totalSavings, $curr) ?></dd>
                    <dt class="col-7"><?= t('la_multiplier') ?></dt>
                    <dd class="col-5"><?= $multiplier ?>×</dd>
                    <dt class="col-7"><?= t('la_max_loan') ?></dt>
                    <dd class="col-5 fw-semibold <?= $eligible?'text-success':'text-danger' ?>"><?= money($maxLoan, $curr) ?></dd>
                    <dt class="col-7"><?= t('la_months_active') ?></dt>
                    <dd class="col-5"><?= $elig['months_active'] ?></dd>
                    <dt class="col-7"><?= t('la_consec_paid') ?></dt>
                    <dd class="col-5"><?= $elig['consec_paid'] ?> / <?= getSetting('loan_min_consecutive','3') ?> <?= t('la_needed') ?></dd>
                    <dt class="col-7"><?= t('la_unpaid_fines') ?></dt>
                    <dd class="col-5 <?= $elig['unpaid_fines']>0?'text-danger fw-semibold':'' ?>"><?= money($elig['unpaid_fines'], $curr) ?></dd>
                    <dt class="col-7">Payment Consistency</dt>
                    <dd class="col-5"><?= $elig['consistency'] ?>%</dd>
                    <dt class="col-7">Loans Repaid</dt>
                    <dd class="col-5"><?= $elig['repaid_loans'] ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<script>
// Live loan calculator
(function() {
    const amt    = document.getElementById('calc_amount');
    const mths   = document.getElementById('calc_months');
    const result = document.getElementById('calcResult');
    if (!amt || !result) return;

    function calc() {
        const a = parseFloat(amt.value) || 0;
        const m = parseInt(mths ? mths.value : 0) || 0;
        const r = <?= $defaultRate ?>;
        if (a < 1 || m < 1) { result.innerHTML = ''; return; }
        const interest  = a * (r / 100) * m;
        const total     = a + interest;
        const monthly   = total / m;
        result.innerHTML = `
            <div class="p-3 rounded-3" style="background:#f0fdf4;border:1px solid #bbf7d0">
                <div class="row g-2 text-center">
                    <div class="col-4">
                        <div style="font-size:.7rem;color:#6b87a8;text-transform:uppercase">Principal</div>
                        <div class="fw-bold"><?= $curr ?> ${a.toLocaleString('en-KE',{minimumFractionDigits:2})}</div>
                    </div>
                    <div class="col-4">
                        <div style="font-size:.7rem;color:#6b87a8;text-transform:uppercase">Total Repay</div>
                        <div class="fw-bold text-success"><?= $curr ?> ${total.toLocaleString('en-KE',{minimumFractionDigits:2})}</div>
                    </div>
                    <div class="col-4">
                        <div style="font-size:.7rem;color:#6b87a8;text-transform:uppercase">Monthly</div>
                        <div class="fw-bold text-primary"><?= $curr ?> ${monthly.toLocaleString('en-KE',{minimumFractionDigits:2})}</div>
                    </div>
                </div>
            </div>`;
    }
    if (amt)  amt.addEventListener('input',  calc);
    if (mths) mths.addEventListener('input', calc);
})();
</script>

<?php require_once ROOT . '/includes/footer.php'; ?>
