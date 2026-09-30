<?php
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
$pageTitle = t('nav_wallet') . ' — SmartChama';
requireAdmin();
require_once ROOT . '/includes/header.php';

$pdo      = getDB();
$curr     = getSetting('currency', 'KES');
$required = (float)getSetting('monthly_contribution', '2000');

// Fetch all active members with their wallet balance AND ledger entry count
$members = $pdo->query("
    SELECT u.id, u.full_name, u.phone, u.membership_number, u.created_at,
           COALESCE(w.balance, 0) AS balance,
           (SELECT COUNT(*) FROM contributions c WHERE c.user_id=u.id AND c.status='confirmed') AS paid_months,
           (SELECT COUNT(*) FROM wallet_ledger wl WHERE wl.user_id=u.id) AS ledger_entries
    FROM users u
    LEFT JOIN member_wallet w ON w.user_id = u.id
    WHERE u.role='member' AND u.status='active'
    ORDER BY w.balance ASC
")->fetchAll();

$totalOwed    = array_sum(array_map(fn($m) => min(0, $m['balance']), $members)); // sum of negatives
$totalCredit  = array_sum(array_map(fn($m) => max(0, $m['balance']), $members)); // sum of positives
$inDebt       = count(array_filter($members, fn($m) => $m['balance'] < 0));
$inCredit     = count(array_filter($members, fn($m) => $m['balance'] > 0));
$settled      = count(array_filter($members, fn($m) => $m['balance'] == 0));
$csrf         = csrfToken();
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><?= t('nav_wallet') ?></h4>
        <small class="text-muted"><?= t('adm_wal_subtitle') ?></small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-primary btn-sm" onclick="applyMonthlyCharge()">
            <i class="bi bi-calendar-check me-1"></i>Apply This Month's Charge
        </button>
        <button class="btn btn-outline-secondary btn-sm" onclick="recalcAll()">
            <i class="bi bi-arrow-clockwise me-1"></i>Recalculate All
        </button>
    </div>
</div>

<!-- KPI Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.4rem;font-weight:800;color:#ef4444"><?= $curr ?> <?= number_format(abs($totalOwed),0) ?></div>
            <div style="font-size:.75rem;color:var(--text-muted)"><?= $inDebt ?> member<?= $inDebt!=1?'s':'' ?> <?= t('adm_wal_owe') ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.4rem;font-weight:800;color:#00c471"><?= $curr ?> <?= number_format($totalCredit,0) ?></div>
            <div style="font-size:.75rem;color:var(--text-muted)"><?= $inCredit ?> member<?= $inCredit!=1?'s':'' ?> <?= t('adm_wal_credit') ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.4rem;font-weight:800;color:var(--text)"><?= $settled ?></div>
            <div style="font-size:.75rem;color:var(--text-muted)">fully settled</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.4rem;font-weight:800;color:#3b82f6"><?= $curr ?> <?= number_format($required,0) ?></div>
            <div style="font-size:.75rem;color:var(--text-muted)">monthly required</div>
        </div>
    </div>
</div>

<div id="actionResult" style="display:none" class="alert mb-3"></div>

<!-- Members Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-wallet2 me-2" style="color:var(--green)"></i>All <?= t('nav_wallet') ?></h6>
        <small class="text-muted"><?= count($members) ?> active members</small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th><?= t('lbl_member') ?></th>
                    <th><?= t('adm_wal_memno') ?></th>
                    <th><?= t('adm_wal_months_paid') ?></th>
                    <th><?= t('dash_balance') ?></th>
                    <th><?= t('lbl_status') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($members as $m):
                $bal     = (float)$m['balance'];
                $hasActivity = (int)$m['ledger_entries'] > 0;
                $balCls  = $bal < 0 ? 'text-danger' : ($bal > 0 ? 'text-success' : 'text-muted');
                $balIcon = $bal < 0 ? '↓' : ($bal > 0 ? '↑' : '–');
                // Only show "Settled" when the member has actual wallet transactions AND balance is 0.
                // A brand-new member with balance=0 and no ledger entries has never been charged —
                // calling them "Settled" is misleading.
                if ($bal < 0) {
                    $statusLabel = 'Owes Chama';
                    $statusBadge = 'danger';
                } elseif ($bal > 0) {
                    $statusLabel = 'Chama Owes';
                    $statusBadge = 'success';
                } elseif ($hasActivity) {
                    $statusLabel = 'Settled';
                    $statusBadge = 'secondary';
                } else {
                    $statusLabel = 'Not Charged';
                    $statusBadge = 'warning';
                }
            ?>
            <tr>
                <td>
                    <div class="fw-semibold" style="font-size:.88rem"><?= htmlspecialchars($m['full_name']) ?></div>
                    <div style="font-size:.75rem;color:var(--text-muted)"><?= htmlspecialchars($m['phone'] ?? '') ?></div>
                </td>
                <td style="font-size:.82rem;font-family:monospace"><?= htmlspecialchars($m['membership_number'] ?? '—') ?></td>
                <td style="font-size:.88rem"><?= $m['paid_months'] ?> month<?= $m['paid_months']!=1?'s':'' ?></td>
                <td>
                    <span class="fw-bold <?= $balCls ?>" style="font-size:.95rem;font-family:'Syne',sans-serif">
                        <?= $balIcon ?> <?= $curr ?> <?= number_format(abs($bal), 2) ?>
                    </span>
                    <?php if ($bal < 0): ?>
                    <div style="font-size:.7rem;color:#ef4444">owes <?= $curr ?> <?= number_format(abs($bal),2) ?></div>
                    <?php elseif ($bal > 0): ?>
                    <div style="font-size:.7rem;color:#00c471">chama owes <?= $curr ?> <?= number_format($bal,2) ?></div>
                    <?php endif; ?>
                </td>
                <td><span class="badge bg-<?= $statusBadge ?> bg-opacity-10 text-<?= $statusBadge ?>" style="font-size:.72rem"><?= $statusLabel ?></span></td>
                <td>
                    <div class="d-flex gap-1">
                        <button class="btn btn-sm btn-outline-secondary" onclick="viewLedger(<?= $m['id'] ?>, '<?= htmlspecialchars(addslashes($m['full_name'])) ?>')" title="View ledger">
                            <i class="bi bi-journal-text"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-warning" onclick="recalcMember(<?= $m['id'] ?>, '<?= htmlspecialchars(addslashes($m['full_name'])) ?>')" title="Recalculate">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($members)): ?>
            <tr><td colspan="6" class="text-center text-muted py-4"><?= t('lbl_no_active_members') ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Ledger Modal -->
<div class="modal fade" id="ledgerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:var(--card-bg);border:1px solid var(--border)">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold" id="ledgerTitle"><?= t('lbl_transaction_ledger') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="ledgerBody">
                <div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></div>
            </div>
        </div>
    </div>
</div>

<script>
var csrf = '<?= $csrf ?>';

function showResult(msg, type) {
    var el = document.getElementById('actionResult');
    el.className = 'alert alert-' + type + ' mb-3';
    el.innerHTML = msg;
    el.style.display = 'block';
    setTimeout(function(){ el.style.display='none'; }, 6000);
}

function applyMonthlyCharge() {
    var month = prompt('Apply charges for month (YYYY-MM):', '<?= date('Y-m') ?>');
    if (!month) return;
    if (!confirm('Debit KES <?= number_format($required,0) ?> from every active member\'s wallet for ' + month + '?\n\nMembers already charged this month will be skipped.')) return;
    var fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('month', month);
    fetch('<?= APP_URL ?>/api/wallet_charge.php', { method:'POST', body:fd, credentials:'same-origin' })
    .then(r=>r.json()).then(data => {
        if (data.ok) {
            showResult('✅ ' + data.month + ': ' + data.charged + ' members charged KES <?= number_format($required,0) ?>, ' + data.skipped + ' already charged.', 'success');
            setTimeout(()=>location.reload(), 2500);
        } else {
            showResult('❌ ' + data.msg, 'danger');
        }
    });
}

function recalcMember(id, name) {
    if (!confirm('Recalculate ' + name + '\'s wallet from scratch?\n\nThis rebuilds their entire history from join date.')) return;
    var fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('user_id', id);
    fetch('<?= APP_URL ?>/api/wallet_recalc.php', { method:'POST', body:fd, credentials:'same-origin' })
    .then(r=>r.json()).then(data => {
        if (data.ok) {
            showResult('✅ ' + name + ' recalculated. New balance: ' + data.formatted, 'success');
            setTimeout(()=>location.reload(), 2000);
        } else {
            showResult('❌ ' + data.msg, 'danger');
        }
    });
}

function recalcAll() {
    if (!confirm('Recalculate ALL members\' wallets from scratch?\n\nThis may take a moment.')) return;
    var members = <?= json_encode(array_map(fn($m) => ['id'=>$m['id'],'name'=>$m['full_name']], $members)) ?>;
    var done = 0;
    showResult('<div class="spinner-border spinner-border-sm me-2"></div>Recalculating ' + members.length + ' members…', 'info');
    function next() {
        if (done >= members.length) { showResult('✅ All ' + members.length + ' members recalculated.', 'success'); setTimeout(()=>location.reload(), 2000); return; }
        var m = members[done++];
        var fd = new FormData(); fd.append('csrf_token', csrf); fd.append('user_id', m.id);
        fetch('<?= APP_URL ?>/api/wallet_recalc.php', { method:'POST', body:fd, credentials:'same-origin' })
        .then(r=>r.json()).then(()=>next());
    }
    next();
}

function viewLedger(userId, name) {
    document.getElementById('ledgerTitle').textContent = name + ' — Transaction Ledger';
    document.getElementById('ledgerBody').innerHTML = '<div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></div>';
    var modal = new bootstrap.Modal(document.getElementById('ledgerModal'));
    modal.show();

    fetch('<?= APP_URL ?>/api/wallet_ledger.php?user_id=' + userId, { credentials:'same-origin' })
    .then(r=>r.json()).then(data => {
        if (!data.ok || !data.entries.length) {
            document.getElementById('ledgerBody').innerHTML = '<p class="text-muted text-center py-3">No ledger entries yet.</p>';
            return;
        }
        var html = '<div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.82rem"><thead><tr><th>Date</th><th>Description</th><th>Debit</th><th>Credit</th><th><?= addslashes(t("dash_balance")) ?></th></tr></thead><tbody>';
        data.entries.forEach(function(e) {
            var isDebit  = e.type === 'debit';
            var balColor = parseFloat(e.balance_after) < 0 ? '#ef4444' : '#00c471';
            html += '<tr>';
            html += '<td style="color:var(--text-muted)">' + e.created_at.slice(0,10) + '</td>';
            html += '<td>' + e.description + '</td>';
            html += '<td style="color:#ef4444">' + (isDebit  ? '<?= $curr ?> ' + parseFloat(e.amount).toLocaleString('en-KE',{minimumFractionDigits:2}) : '') + '</td>';
            html += '<td style="color:#00c471">' + (!isDebit ? '<?= $curr ?> ' + parseFloat(e.amount).toLocaleString('en-KE',{minimumFractionDigits:2}) : '') + '</td>';
            html += '<td style="font-weight:700;color:' + balColor + '">' + (parseFloat(e.balance_after) >= 0 ? '+' : '') + parseFloat(e.balance_after).toLocaleString('en-KE',{minimumFractionDigits:2}) + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        html += '<div class="mt-3 pt-3 border-top d-flex justify-content-between" style="font-size:.82rem"><span class="text-muted">Showing last ' + data.entries.length + ' entries</span>';
        var finalBal = parseFloat(data.entries[0].balance_after);
        html += '<span class="fw-bold" style="color:' + (finalBal>=0?'#00c471':'#ef4444') + '">Current: ' + (finalBal>=0?'+':'') + finalBal.toLocaleString('en-KE',{minimumFractionDigits:2}) + '</span></div>';
        document.getElementById('ledgerBody').innerHTML = html;
    });
}
</script>
<?php require_once ROOT . '/includes/footer.php'; ?>
