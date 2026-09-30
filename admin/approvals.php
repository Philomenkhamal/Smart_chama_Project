<?php
/**
 * CHAMA Financial Management System
 * Admin — Member Approvals
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Member Approvals — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();
require_once ROOT . '/includes/header.php';

$pdo = getDB();

// ── Handle POST actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $memberId = (int)($_POST['member_id'] ?? 0);

    if ($memberId > 0 && in_array($action, ['approve', 'reject', 'suspend', 'activate'])) {

        $statusMap = [
            'approve'  => 'active',
            'reject'   => 'rejected',
            'suspend'  => 'suspended',
            'activate' => 'active',
        ];
        $newStatus = $statusMap[$action];

        // Get member info
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND role = "member"');
        $stmt->execute([$memberId]);
        $member = $stmt->fetch();

        if ($member) {
            if ($action === 'approve' && $member['status'] === 'pending') {
                // Generate membership number and set joined date
                $memNum = generateMembershipNumber();
                $pdo->prepare('UPDATE users SET status=?, membership_number=?, joined_date=COALESCE(joined_date, CURDATE()), email_verified=1 WHERE id=?')
                    ->execute([$newStatus, $memNum, $memberId]);

                // In-app notification
                createNotification($memberId, '✅ Registration Approved',
                    "Congratulations! Your membership has been approved. Your number is {$memNum}.",
                    'success', APP_URL . '/member/dashboard.php');

                // Email notification
                sendApprovalEmail($member, $memNum);

                logActivity('APPROVE_MEMBER', "Approved member ID:{$memberId} → {$memNum}");
                setFlash('success', "Member {$member['full_name']} approved. Membership: {$memNum}");
            } else {
                $pdo->prepare('UPDATE users SET status=? WHERE id=?')
                    ->execute([$newStatus, $memberId]);

                $notifMsg = match($action) {
                    'reject'   => 'Your registration has been rejected. Please contact the administrator.',
                    'suspend'  => 'Your account has been temporarily suspended.',
                    'activate' => 'Your account has been reactivated.',
                    default    => ''
                };
                if ($notifMsg) {
                    createNotification($memberId, 'Account Update', $notifMsg,
                        $action === 'activate' ? 'success' : 'warning');
                }
                // Email for reject/activate
                if ($action === 'reject')   sendRejectionEmail($member);
                if ($action === 'activate') sendApprovalEmail($member, $member['membership_number'] ?? 'N/A');
                logActivity('UPDATE_MEMBER_STATUS', "Member ID:{$memberId} → {$newStatus}");
                setFlash('success', "Member status updated to: {$newStatus}");
            }
        }

        redirect(APP_URL . '/admin/approvals.php');
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$validFilters = ['pending', 'active', 'rejected', 'suspended', 'all'];
$_filterRaw   = $_GET['status'] ?? 'pending';
$filter       = in_array($_filterRaw, $validFilters, true) ? $_filterRaw : 'pending';

// Use a parameterized query — no raw string interpolation of user input
if ($filter !== 'all') {
    $membersStmt = $pdo->prepare("SELECT * FROM users WHERE role='member' AND status=? ORDER BY created_at DESC");
    $membersStmt->execute([$filter]);
} else {
    $membersStmt = $pdo->prepare("SELECT * FROM users WHERE role='member' ORDER BY created_at DESC");
    $membersStmt->execute();
}
$members = $membersStmt->fetchAll();

// Count by status for tabs
$counts = [];
foreach (['pending','active','rejected','suspended'] as $s) {
    $counts[$s] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND status='{$s}'")->fetchColumn();
}
$counts['all'] = array_sum($counts);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><?= t('approvals_title') ?></h4>
        <small class="text-muted"><?= t('adm_appr_subtitle') ?></small>
    </div>
</div>

<!-- Status filter tabs -->
<ul class="nav nav-tabs mb-3">
    <?php
    $tabDefs = [
        'pending'   => ['label' => 'Pending',   'badge' => 'warning'],
        'active'    => ['label' => 'Active',     'badge' => 'success'],
        'rejected'  => ['label' => 'Rejected',   'badge' => 'danger'],
        'suspended' => ['label' => 'Suspended',  'badge' => 'secondary'],
        'all'       => ['label' => 'All',        'badge' => 'primary'],
    ];
    foreach ($tabDefs as $key => $tab): ?>
    <li class="nav-item">
        <a class="nav-link <?= $filter === $key ? 'active' : '' ?>"
           href="?status=<?= $key ?>">
            <?= $tab['label'] ?>
            <span class="badge bg-<?= $tab['badge'] ?> ms-1"><?= $counts[$key] ?></span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= t('members_name') ?></th>
                        <th><?= t('members_phone') ?></th>
                        <th><?= t('lbl_na') ?></th>
                        <th><?= t('profile_membership') ?></th>
                        <th><?= t('approvals_registered') ?></th>
                        <th><?= t('members_status') ?></th>
                        <th><?= t('lbl_actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($members)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                            No <?= $filter !== 'all' ? htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') : '' ?> members found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($members as $m): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="avatar-placeholder rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                                     style="width:36px;height:36px;font-size:.85rem;font-weight:600;flex-shrink:0">
                                    <?= strtoupper(substr($m['full_name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="fw-semibold small"><?= htmlspecialchars($m['full_name']) ?></div>
                                    <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($m['occupation'] ?? '—') ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="small"><?= htmlspecialchars($m['email']) ?></div>
                            <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($m['phone']) ?></div>
                        </td>
                        <td class="small font-monospace"><?= htmlspecialchars($m['national_id']) ?></td>
                        <td class="small font-monospace fw-semibold text-primary">
                            <?= $m['membership_number'] ? htmlspecialchars($m['membership_number']) : '<span class="text-muted">' . t('adm_appr_not_assigned') . '</span>' ?>
                        </td>
                        <td class="small text-muted"><?= formatDate($m['created_at'], 'd M Y') ?></td>
                        <td><?= badgeStatus($m['status']) ?></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <!-- View Profile -->
                                <button class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="modal" data-bs-target="#memberModal"
                                        data-member='<?= json_encode([
                                            "name"        => $m['full_name'],
                                            "email"       => $m['email'],
                                            "phone"       => $m['phone'],
                                            "national_id" => $m['national_id'],
                                            "occupation"  => $m['occupation'],
                                            "address"     => $m['address'],
                                            "next_of_kin" => $m['next_of_kin'],
                                            "kin_phone"   => $m['next_of_kin_phone'],
                                            "status"      => $m['status'],
                                            "joined"      => $m['joined_date'],
                                        ], JSON_HEX_APOS) ?>'>
                                    <i class="bi bi-eye"></i>
                                </button>

                                <?php if ($m['status'] === 'pending'): ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button class="btn btn-sm btn-success" onclick="return confirm('Approve this member?')">
                                            <i class="bi bi-check-lg"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button class="btn btn-sm btn-danger" onclick="return confirm('Reject this application?')">
                                            <i class="bi bi-x-lg"></i> Reject
                                        </button>
                                    </form>

                                <?php elseif ($m['status'] === 'active'): ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                                        <input type="hidden" name="action" value="suspend">
                                        <button class="btn btn-sm btn-warning" onclick="return confirm('Suspend this member?')">
                                            <i class="bi bi-pause-circle"></i> Suspend
                                        </button>
                                    </form>

                                <?php elseif (in_array($m['status'], ['suspended', 'rejected'])): ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                                        <input type="hidden" name="action" value="activate">
                                        <button class="btn btn-sm btn-success" onclick="return confirm('Reactivate this member?')">
                                            <i class="bi bi-arrow-clockwise"></i> Activate
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Member Detail Modal -->
<div class="modal fade" id="memberModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><?= t('adm_appr_profile') ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="memberModalBody">
                <!-- Filled via JS -->
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<'JS'
<script>
document.querySelectorAll('[data-member]').forEach(btn => {
    btn.addEventListener('click', function() {
        const m = JSON.parse(this.getAttribute('data-member'));
        document.getElementById('memberModalBody').innerHTML = `
            <dl class="row small mb-0">
                <dt class="col-sm-4"><?= t('lbl_fullname') ?></dt>
                <dd class="col-sm-8">${m.name}</dd>
                <dt class="col-sm-4"><?= t('lbl_email') ?></dt>
                <dd class="col-sm-8">${m.email}</dd>
                <dt class="col-sm-4"><?= t('lbl_phone') ?></dt>
                <dd class="col-sm-8">${m.phone}</dd>
                <dt class="col-sm-4"><?= t('adm_appr_national_id') ?></dt>
                <dd class="col-sm-8 font-monospace">${m.national_id}</dd>
                <dt class="col-sm-4"><?= t('adm_appr_occupation') ?></dt>
                <dd class="col-sm-8">${m.occupation || '—'}</dd>
                <dt class="col-sm-4"><?= t('adm_appr_address') ?></dt>
                <dd class="col-sm-8">${m.address || '—'}</dd>
                <dt class="col-sm-4"><?= t('adm_appr_next_of_kin') ?></dt>
                <dd class="col-sm-8">${m.next_of_kin || '—'} ${m.kin_phone ? '(' + m.kin_phone + ')' : ''}</dd>
                <dt class="col-sm-4"><?= t('lbl_status') ?></dt>
                <dd class="col-sm-8"><span class="badge bg-secondary text-capitalize">${m.status}</span></dd>
            </dl>`;
    });
});
</script>
JS;

require_once ROOT . '/includes/footer.php';
?>
