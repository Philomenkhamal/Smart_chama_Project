<?php
/**
 * ChamaLedger — Fines Management
 * Add / view / waive fines per member per month
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Fines — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();

$pdo  = getDB();
$curr = getSetting('currency', 'KES');
// Validate YYYY-MM format to prevent XSS when echoed into HTML/JS
$rawMonth     = $_GET['month'] ?? date('Y-m');
$defaultMonth = preg_match('/^\d{4}-\d{2}$/', $rawMonth) ? $rawMonth : date('Y-m');

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_fine') {
        $userId   = (int)$_POST['user_id'];
        $type     = cleanInput($_POST['fine_type']);
        $amount   = (float)$_POST['amount'];
        $month    = cleanInput($_POST['month']) . '-01';
        $desc     = cleanInput($_POST['description'] ?? '');
        $adminId  = (int)$_SESSION['user_id'];

        if ($userId && $amount > 0 && $type) {
            // Block duplicate active fine (same member, same type, same month)
            // Waived fines do NOT count — admin can re-fine after waiving
            $dupChk = $pdo->prepare("
                SELECT id FROM member_fines
                WHERE user_id=? AND fine_type=?
                AND DATE_FORMAT(month,'%Y-%m')=DATE_FORMAT(?,'%Y-%m')
                AND status != 'waived'
                LIMIT 1
            ");
            $dupChk->execute([$userId, $type, $month]);
            if ($dupChk->fetch()) {
                setFlash('warning', 'This member already has an active ' . ucfirst(str_replace('_',' ',$type)) . ' fine for ' . date('F Y', strtotime($month)) . '. Waive it first if you want to replace it.');
                $redirectMonth = $_POST['month'] ?? $defaultMonth;
                redirect(APP_URL . '/admin/fines.php?month=' . $redirectMonth);
            }

            $pdo->prepare("
                INSERT INTO member_fines (user_id, fine_type, amount, month, description, created_by)
                VALUES (?,?,?,?,?,?)
            ")->execute([$userId, $type, $amount, $month, $desc, $adminId]);

            // Also debit wallet
            $wallet = new Wallet($pdo);
            $label  = ucfirst(str_replace('_',' ',$type)) . ' fine — ' . date('F Y', strtotime($month));
            $wallet->debit($userId, $amount, $label, 'fine', (int)$pdo->lastInsertId());

            // Notify member
            createNotification($userId, '⚠️ Fine Applied — ' . $curr . ' ' . number_format($amount,0),
                $label . (($desc)? ": $desc" : ''), 'warning', APP_URL . '/member/contributions.php');
            logActivity('FINE_ADDED', "Fine KES{$amount} ({$type}) on member ID {$userId}");
            setFlash('success', t('flash_fine_added'));
        }
    }

    if ($action === 'waive') {
        $fineId = (int)$_POST['fine_id'];
        $fine   = $pdo->prepare("SELECT * FROM member_fines WHERE id=?"); $fine->execute([$fineId]); $fine = $fine->fetch();
        if ($fine && $fine['status'] === 'pending') {
            $pdo->prepare("UPDATE member_fines SET status='waived' WHERE id=?")->execute([$fineId]);
            // Reverse the wallet debit
            $wallet = new Wallet($pdo);
            $label  = 'Fine waived — ' . ucfirst(str_replace('_',' ',$fine['fine_type'])) . ' ' . date('F Y', strtotime($fine['month']));
            $wallet->credit($fine['user_id'], $fine['amount'], $label, 'fine_waived', $fineId);
            setFlash('success', t('flash_fine_waived'));
            logActivity('FINE_WAIVED', "Fine ID {$fineId} waived");
        }
    }

    $redirectMonth = $_POST['month'] ?? $defaultMonth;
    redirect(APP_URL . '/admin/fines.php?month=' . $redirectMonth);
}

// ── Data ─────────────────────────────────────────────────────────────────────
$members = $pdo->query("SELECT id, full_name, membership_number FROM users WHERE role='member' AND status='active' ORDER BY full_name")->fetchAll();

$fines = $pdo->prepare("
    SELECT f.*, u.full_name, u.membership_number
    FROM member_fines f
    JOIN users u ON u.id = f.user_id
    WHERE DATE_FORMAT(f.month,'%Y-%m') = ?
    ORDER BY f.created_at DESC
");
$fines->execute([$defaultMonth]);
$fines = $fines->fetchAll();

$fineTypes = [
    'absentee'          => ['Absentee',         1000],
    'late_contribution' => ['Late Contribution', 500],
    'agm'               => ['AGM Fine',          500],
    'custom'            => ['Custom Fine',       0],
];

require_once ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><?= t('fines_admin_title') ?></h4>
        <small class="text-muted"><?= t('fines_subtitle') ?></small>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <form class="d-flex gap-2" method="GET">
            <input type="month" name="month" value="<?= $defaultMonth ?>" class="form-control form-control-sm" style="width:155px">
            <button class="btn btn-primary btn-sm">Go</button>
        </form>
        <button class="btn btn-danger btn-sm" onclick="runAutoFine()">
            <i class="bi bi-lightning-charge me-1"></i>Auto-Fine Unpaid Members
        </button>
        <a href="<?= APP_URL ?>/admin/monthly_report.php?year=<?= substr($defaultMonth,0,4) ?>&month=<?= (int)substr($defaultMonth,5,2) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-file-earmark-bar-graph me-1"></i>View Report
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- Add Fine Form -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header border-0"><h6 class="mb-0"><i class="bi bi-plus-circle me-2" style="color:#f59e0b"></i><?= t('btn_add') ?> <?= t('fines_admin_title') ?></h6></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="add_fine">
                    <div class="mb-3">
                        <label class="form-label" style="font-size:.82rem"><?= t('lbl_member') ?></label>
                        <select name="user_id" class="form-select form-select-sm" required>
                            <option value=""><?= t('fines_select_member') ?></option>
                            <?php foreach ($members as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['full_name']) ?> (<?= $m['membership_number'] ?? '—' ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-size:.82rem"><?= t('fines_type') ?></label>
                        <select name="fine_type" class="form-select form-select-sm" required id="fineTypeSelect" onchange="prefillAmount(this)">
                            <option value=""><?= t('fines_select_type') ?></option>
                            <?php foreach ($fineTypes as $key => [$label, $default]): ?>
                            <option value="<?= $key ?>" data-amount="<?= $default ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-size:.82rem">Amount (<?= $curr ?>)</label>
                        <input type="number" name="amount" id="fineAmount" class="form-control form-control-sm" min="0.01" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-size:.82rem"><?= t('lbl_month') ?></label>
                        <input type="month" name="month" value="<?= $defaultMonth ?>" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-size:.82rem"><?= t('lbl_notes') ?></label>
                        <input type="text" name="description" class="form-control form-control-sm" placeholder="e.g. Missed AGM on 12th">
                    </div>
                    <button class="btn btn-warning w-100 btn-sm fw-bold">
                        <i class="bi bi-cash-coin me-1"></i>Apply Fine
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Fines List -->
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header border-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-list-ul me-2" style="color:#f59e0b"></i>Fines for <?= date('F Y', strtotime($defaultMonth.'-01')) ?></h6>
                <span class="badge bg-warning bg-opacity-20 text-warning"><?= count($fines) ?> fine<?= count($fines)!=1?'s':'' ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="font-size:.82rem">
                    <thead>
                        <tr><th class="ps-3"><?= t('fines_member') ?></th><th><?= t('fines_type') ?></th><th class="text-end"><?= t('fines_amount') ?></th><th><?= t('fines_status') ?></th><th><?= t('lbl_notes') ?></th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($fines as $f):
                        $typeName = $fineTypes[$f['fine_type']][0] ?? ucfirst($f['fine_type']);
                        $statusBadge = $f['status'] === 'paid' ? 'success' : ($f['status'] === 'waived' ? 'secondary' : 'warning');
                    ?>
                    <tr>
                        <td class="ps-3">
                            <div class="fw-semibold"><?= htmlspecialchars($f['full_name']) ?></div>
                            <div style="font-size:.7rem;color:var(--text-muted)"><?= $f['membership_number'] ?? '' ?></div>
                        </td>
                        <td><?= $typeName ?></td>
                        <td class="text-end fw-bold" style="color:#f59e0b"><?= $curr ?> <?= number_format($f['amount'],0) ?></td>
                        <td><span class="badge bg-<?= $statusBadge ?> bg-opacity-15 text-<?= $statusBadge ?>" style="font-size:.7rem"><?= ucfirst($f['status']) ?></span></td>
                        <td style="color:var(--text-muted);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($f['description'] ?? '') ?></td>
                        <td>
                            <?php if ($f['status'] === 'pending'): ?>
                            <form method="POST" onsubmit="return confirm('Waive this fine?')">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="waive">
                                <input type="hidden" name="fine_id" value="<?= $f['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" title="Waive fine"><i class="bi bi-x-circle"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($fines)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4"><?= t('adm_fin_none') ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function prefillAmount(sel) {
    var amt = sel.options[sel.selectedIndex].dataset.amount;
    if (amt && amt > 0) document.getElementById('fineAmount').value = amt;
}
</script>
<div id="autoFineResult" style="display:none" class="alert mt-3"></div>
<script>
var autoFineCsrf = '<?= csrfToken() ?>';
function runAutoFine() {
    var month = '<?= $defaultMonth ?>';
    if (!confirm('Auto-fine all members who have NOT paid for ' + month + '?\n\nThis will:\n• Check each active member\n• Apply a fine to anyone with no confirmed contribution\n• Debit their wallet\n• Send them a notification')) return;
    var fd = new FormData();
    fd.append('csrf_token', autoFineCsrf);
    fd.append('month', month);
    fetch('<?= APP_URL ?>/api/auto_fine.php', { method:'POST', body:fd, credentials:'same-origin' })
    .then(r=>r.json()).then(data=>{
        var el = document.getElementById('autoFineResult');
        if (data.ok) {
            var msg = '✅ Auto-fine complete for ' + data.month + ': ';
            if (data.fined > 0) {
                msg += '<strong>' + data.fined + ' member(s) fined</strong> KES ' + data.amount.toLocaleString() + ' each';
                if (data.names.length) msg += ' (' + data.names.join(', ') + ')';
            } else {
                msg += 'No new fines — all members already paid or already fined.';
            }
            el.className = 'alert alert-success mt-3';
            el.innerHTML = msg;
            el.style.display = 'block';
            setTimeout(()=>location.reload(), 3000);
        } else {
            el.className = 'alert alert-danger mt-3';
            el.innerHTML = '❌ ' + data.msg;
            el.style.display = 'block';
        }
    });
}
</script>
<?php require_once ROOT . '/includes/footer.php'; ?>
