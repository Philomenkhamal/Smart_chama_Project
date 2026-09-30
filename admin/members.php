<?php
/**
 * ChamaLedger — Admin Members Management
 * Admin can: Add members directly, approve pending, edit, suspend, reset password
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Members — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();
$pdo  = getDB();
$curr = getSetting('currency', 'KES');

// ── Next membership number helper ─────────────────────────────────────────────
function nextMembershipNo(PDO $pdo): string {
    return generateMembershipNumber(); // safe global generator — avoids duplicates
}

// ── POST handler ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = sanitize($_POST['action'] ?? '');

    // ── ADD MEMBER ────────────────────────────────────────────────────────────
    if ($action === 'add_member') {
        $fullName   = cleanInput($_POST['full_name'] ?? '');
        $email      = strtolower(trim($_POST['email'] ?? ''));
        $phone      = cleanInput($_POST['phone'] ?? '');
        $nickname   = cleanInput($_POST['nickname'] ?? '');
        $position   = cleanInput($_POST['chama_position'] ?? '');
        $memNo      = cleanInput($_POST['membership_number'] ?? '') ?: nextMembershipNo($pdo);
        $joinedDate = cleanInput($_POST['joined_date'] ?? '') ?: date('Y-m-d');
        $setPass    = trim($_POST['set_password'] ?? '');
        $defaultPw  = $setPass ?: 'Chama@1234'; // default password

        $errors = [];
        if (!$fullName) $errors[] = 'Full name is required.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (!$phone)  $errors[] = 'Phone number is required.';

        // Check duplicate email
        $chk = $pdo->prepare("SELECT id FROM users WHERE email=?");
        $chk->execute([$email]);
        if ($chk->fetchColumn()) $errors[] = 'That email is already registered.';

        // Check duplicate membership number
        if ($memNo) {
            $chkNo = $pdo->prepare("SELECT id FROM users WHERE membership_number=?");
            $chkNo->execute([$memNo]);
            if ($chkNo->fetchColumn()) {
                // Auto-assign next available instead of showing an error
                $memNo = generateMembershipNumber();
            }
        }

        if (empty($errors)) {
            $hash = password_hash($defaultPw, PASSWORD_BCRYPT, ['cost'=>12]);
            $stmt = $pdo->prepare("
                INSERT INTO users
                    (full_name, email, phone, nickname, chama_position,
                     membership_number, password_hash, role, status, joined_date, email_verified, created_at)
                VALUES (?,?,?,?,?,?,?,'member','active',?,1,NOW())
            ");
            $stmt->execute([$fullName, $email, $phone, $nickname, $position,
                             $memNo, $hash, $joinedDate]);
            $newId = (int)$pdo->lastInsertId();

            // Initialise wallet
            $pdo->prepare("INSERT IGNORE INTO member_wallet (user_id, balance) VALUES (?,0)")->execute([$newId]);

            createNotification($newId, '👋 Welcome to ' . getSetting('group_name','ChamaLedger'),
                "Your account has been created by the admin. Login with your email and password: $defaultPw — please change it after first login.",
                'success');
            logActivity('ADD_MEMBER', "Admin added member: $fullName ($email) — $memNo");
            setFlash('success', "✅ $fullName added successfully! Login: $email / $defaultPw");
        } else {
            setFlash('danger', implode('<br>', $errors));
        }
        redirect(APP_URL . '/admin/members.php?status=active');
    }

    // ── EDIT MEMBER ───────────────────────────────────────────────────────────
    if ($action === 'edit_member') {
        $mid      = (int)($_POST['member_id'] ?? 0);
        $fullName = cleanInput($_POST['full_name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $phone    = cleanInput($_POST['phone'] ?? '');
        $nickname = cleanInput($_POST['nickname'] ?? '');
        $position = cleanInput($_POST['chama_position'] ?? '');
        $memNo    = cleanInput($_POST['membership_number'] ?? '');
        $joinedDate = cleanInput($_POST['joined_date'] ?? '');

        if ($mid && $fullName && $email) {
            // Check duplicate email for OTHER members
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=? AND id!=?");
            $chk->execute([$email, $mid]);
            if ($chk->fetchColumn()) {
                setFlash('danger', 'That email is already used by another member.');
            } else {
                $pdo->prepare("
                    UPDATE users SET full_name=?, email=?, phone=?, nickname=?,
                    chama_position=?, membership_number=?, joined_date=?
                    WHERE id=? AND role='member'
                ")->execute([$fullName,$email,$phone,$nickname,$position,$memNo,$joinedDate?:null,$mid]);
                logActivity('EDIT_MEMBER', "Edited member #$mid: $fullName");
                setFlash('success', "$fullName updated successfully.");
            }
        }
        redirect(APP_URL . '/admin/members.php?status=' . ($_POST['redirect_status'] ?? 'active'));
    }

    // ── RESET PASSWORD ────────────────────────────────────────────────────────
    if ($action === 'reset_password') {
        $mid     = (int)($_POST['member_id'] ?? 0);
        $newPass = trim($_POST['new_password'] ?? '');
        if (!$newPass) $newPass = 'Chama@1234';
        if ($mid) {
            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost'=>12]);
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=? AND role='member'")->execute([$hash,$mid]);
            $mn = $pdo->prepare("SELECT full_name FROM users WHERE id=?"); $mn->execute([$mid]); $mn=$mn->fetchColumn();
            createNotification($mid,'🔑 Password Reset',
                "Your password has been reset by the admin. New password: $newPass — please change it after logging in.",'warning');
            logActivity('RESET_PASSWORD',"Reset password for member #$mid: $mn");
            setFlash('success', "Password for $mn reset to: $newPass");
        }
        redirect(APP_URL . '/admin/members.php?status=' . ($_POST['redirect_status'] ?? 'active'));
    }

    // ── APPROVE PENDING ───────────────────────────────────────────────────────
    if ($action === 'approve') {
        $mid   = (int)($_POST['member_id'] ?? 0);
        $memNo = sanitize($_POST['membership_number'] ?? '') ?: nextMembershipNo($pdo);
        if ($mid) {
            $pdo->prepare("UPDATE users SET status='active', membership_number=?, joined_date=COALESCE(joined_date,CURDATE()), email_verified=1 WHERE id=?")->execute([$memNo,$mid]);
            $pdo->prepare("INSERT IGNORE INTO member_wallet (user_id, balance) VALUES (?,0)")->execute([$mid]);
            $mn = $pdo->prepare("SELECT full_name FROM users WHERE id=?"); $mn->execute([$mid]); $mn=$mn->fetchColumn();
            createNotification($mid,'✅ Account Approved',
                "Your membership has been approved! Membership #: $memNo. You can now access all features.",'success');
            logActivity('APPROVE_MEMBER',"Approved member #$mid: $mn — $memNo");
            setFlash('success', "$mn approved! Membership #: $memNo");
        }
        redirect(APP_URL . '/admin/members.php?status=pending');
    }

    // ── REJECT PENDING ────────────────────────────────────────────────────────
    if ($action === 'reject') {
        $mid = (int)($_POST['member_id'] ?? 0);
        if ($mid) {
            $pdo->prepare("UPDATE users SET status='rejected' WHERE id=? AND role='member'")->execute([$mid]);
            $mn = $pdo->prepare("SELECT full_name FROM users WHERE id=?"); $mn->execute([$mid]); $mn=$mn->fetchColumn();
            logActivity('REJECT_MEMBER',"Rejected member #$mid: $mn");
            setFlash('success', "$mn rejected.");
        }
        redirect(APP_URL . '/admin/members.php?status=pending');
    }

    // ── DELETE MEMBER ─────────────────────────────────────────────────────────
    if ($action === 'delete_member') {
        $mid = (int)($_POST['member_id'] ?? 0);

        // Safety: get member info first
        $mem = $pdo->prepare("SELECT full_name, membership_number FROM users WHERE id=? AND role='member'");
        $mem->execute([$mid]);
        $mem = $mem->fetch(PDO::FETCH_ASSOC);

        if (!$mem) {
            setFlash('danger', 'Member not found.');
            redirect(APP_URL . '/admin/members.php');
        }

        $name  = $mem['full_name'];
        $memNo = $mem['membership_number'];

        // Helper: check whether a table exists in this database
        $tableExists = function(string $tbl) use ($pdo): bool {
            $r = $pdo->query("SHOW TABLES LIKE '$tbl'")->fetchColumn();
            return $r !== false;
        };

        // Count financial history — no longer blocks deletion, just gets reported
        // in the success message so the admin knows what was wiped.
        $stmtC = $pdo->prepare("SELECT COUNT(*) FROM contributions WHERE user_id=?");
        $stmtC->execute([$mid]);
        $contribCount = (int)$stmtC->fetchColumn();

        $stmtL = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE user_id=?");
        $stmtL->execute([$mid]);
        $loanCount = (int)$stmtL->fetchColumn();

        $fineCount = 0;
        if ($tableExists('member_fines')) {
            $stmtF = $pdo->prepare("SELECT COUNT(*) FROM member_fines WHERE user_id=?");
            $stmtF->execute([$mid]);
            $fineCount = (int)$stmtF->fetchColumn();
        }

        // Delete member AND all their financial history — full purge, no blocking.
        // Wrapped in a transaction so it's all-or-nothing: if anything fails,
        // nothing is deleted (no half-wiped member left behind).
        // Most of these tables already have ON DELETE CASCADE on users(id), so the
        // final DELETE FROM users would clean them up automatically — but we delete
        // explicitly here too, in dependency order, so this doesn't silently rely on
        // FK constraints being intact in every environment.
        try {
            $pdo->beginTransaction();

            $pdo->prepare("DELETE FROM loan_payments      WHERE user_id=?")->execute([$mid]);
            $pdo->prepare("DELETE FROM loans              WHERE user_id=?")->execute([$mid]);
            $pdo->prepare("DELETE FROM contributions      WHERE user_id=?")->execute([$mid]);
            if ($tableExists('event_contributions'))    $pdo->prepare("DELETE FROM event_contributions    WHERE user_id=?")->execute([$mid]);
            if ($tableExists('dividend_shares'))         $pdo->prepare("DELETE FROM dividend_shares        WHERE user_id=?")->execute([$mid]);
            if ($tableExists('mpesa_transactions'))      $pdo->prepare("DELETE FROM mpesa_transactions     WHERE user_id=?")->execute([$mid]);
            if ($tableExists('announcement_replies'))    $pdo->prepare("DELETE FROM announcement_replies   WHERE user_id=?")->execute([$mid]);
            if ($tableExists('member_fines'))            $pdo->prepare("DELETE FROM member_fines           WHERE user_id=?")->execute([$mid]);
            if ($tableExists('otp_tokens'))              $pdo->prepare("DELETE FROM otp_tokens        WHERE user_id=?")->execute([$mid]);
            $pdo->prepare("DELETE FROM wallet_ledger WHERE user_id=?")->execute([$mid]);
            $pdo->prepare("DELETE FROM member_wallet WHERE user_id=?")->execute([$mid]);
            $pdo->prepare("DELETE FROM notifications WHERE user_id=?")->execute([$mid]);
            if ($tableExists('activity_log')) $pdo->prepare("DELETE FROM activity_log  WHERE user_id=?")->execute([$mid]);

            $pdo->prepare("DELETE FROM users WHERE id=? AND role='member'")->execute([$mid]);

            $pdo->commit();

            $wiped = [];
            if ($contribCount) $wiped[] = "$contribCount contribution record(s)";
            if ($loanCount)    $wiped[] = "$loanCount loan record(s)";
            if ($fineCount)    $wiped[] = "$fineCount fine record(s)";
            $wipeNote = $wiped ? ' Also removed: ' . implode(', ', $wiped) . '.' : '';

            logActivity('DELETE_MEMBER', "Deleted member: $name ($memNo) — wiped $contribCount contribution(s), $loanCount loan(s), $fineCount fine(s)");
            setFlash('success', "Member <strong>$name</strong> ($memNo) has been permanently deleted.$wipeNote");
        } catch (\Exception $e) {
            $pdo->rollBack();
            setFlash('danger', "Delete failed for <strong>$name</strong>: " . htmlspecialchars($e->getMessage()));
        }

        redirect(APP_URL . '/admin/members.php?status=' . ($_POST['redirect_status'] ?? 'active'));
    }

    // ── SUSPEND / ACTIVATE ────────────────────────────────────────────────────
    if (in_array($action, ['suspend','activate'])) {
        $mid       = (int)($_POST['member_id'] ?? 0);
        $newStatus = $action === 'suspend' ? 'suspended' : 'active';
        $pdo->prepare("UPDATE users SET status=? WHERE id=? AND role='member'")->execute([$newStatus,$mid]);
        $mn = $pdo->prepare("SELECT full_name FROM users WHERE id=?"); $mn->execute([$mid]); $mn=$mn->fetchColumn();
        createNotification($mid,
            $action==='suspend' ? '🔒 Account Suspended' : '✅ Account Reactivated',
            $action==='suspend' ? 'Your account has been suspended. Contact admin.' : 'Your account is reactivated. Welcome back!',
            $action==='suspend' ? 'danger' : 'success');
        logActivity(strtoupper($action).'_MEMBER', "$action member: $mn");
        setFlash('success', "$mn " . ($action==='suspend'?'suspended.':'reactivated.'));
        redirect(APP_URL . '/admin/members.php?status=' . ($_POST['redirect_status'] ?? 'active'));
    }
}

// ── Fetch list ─────────────────────────────────────────────────────────────────
$search  = sanitize($_GET['search'] ?? '');
$validMemberStatuses = ['active', 'pending', 'suspended', 'rejected', 'all'];
$_statusRaw = $_GET['status'] ?? 'active';
$status     = in_array($_statusRaw, $validMemberStatuses, true) ? $_statusRaw : 'active';
$page    = max(1,(int)($_GET['page']??1));
$perPage = 20;
$offset  = ($page-1)*$perPage;

$where  = ["role='member'"];
$params = [];
if ($status && $status !== 'all') { $where[] = 'status=?'; $params[] = $status; }
if ($search) {
    $where[] = '(full_name LIKE ? OR email LIKE ? OR membership_number LIKE ? OR phone LIKE ? OR nickname LIKE ?)';
    $like = "%$search%";
    $params = array_merge($params, [$like,$like,$like,$like,$like]);
}
$whereSQL = 'WHERE '.implode(' AND ',$where);

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM users $whereSQL"); $cntStmt->execute($params);
$totalRows  = (int)$cntStmt->fetchColumn();
$totalPages = (int)ceil($totalRows/$perPage);

$mStmt = $pdo->prepare("
    SELECT u.*,
        (SELECT COALESCE(SUM(amount),0) FROM contributions WHERE user_id=u.id AND status='confirmed') AS total_savings,
        (SELECT COUNT(*) FROM contributions WHERE user_id=u.id AND status='confirmed') AS contrib_months,
        (SELECT l.balance FROM loans l WHERE l.user_id=u.id AND l.status IN ('approved','disbursed') ORDER BY applied_at DESC LIMIT 1) AS loan_balance,
        (SELECT l.loan_number FROM loans l WHERE l.user_id=u.id AND l.status IN ('approved','disbursed') ORDER BY applied_at DESC LIMIT 1) AS active_loan_no
    FROM users u $whereSQL
    ORDER BY CAST(SUBSTRING_INDEX(CONCAT(membership_number,'0'),'-',-1) AS UNSIGNED) ASC,
             membership_number ASC, u.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$mStmt->execute($params);
$members = $mStmt->fetchAll();

$counts = [];
foreach (['pending','active','suspended','rejected','all'] as $s) {
    $q = $s==='all' ? "SELECT COUNT(*) FROM users WHERE role='member'"
                    : "SELECT COUNT(*) FROM users WHERE role='member' AND status='$s'";
    $counts[$s] = (int)$pdo->query($q)->fetchColumn();
}
$nextNo = nextMembershipNo($pdo);

require_once ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-people me-2 text-primary"></i><?= t('members_title') ?></h4>
        <small class="text-muted"><?= t('members_subtitle') ?></small>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addMemberModal">
            <i class="bi bi-person-plus me-1"></i> <?= t('members_add') ?>
        </button>
        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#bulkImportModal">
            <i class="bi bi-upload me-1"></i> <?= t('members_bulk_import') ?>
        </button>
    </div>
</div>

<?= getFlash() ?>

<!-- Search + filter -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
            <input type="hidden" name="status" value="<?= $status ?>">
            <div class="input-group input-group-sm" style="max-width:320px">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control"
                       placeholder="Name, email, phone, membership #…"
                       value="<?= htmlspecialchars($search) ?>">
                <button class="btn btn-primary btn-sm">Go</button>
                <?php if($search): ?>
                <a href="?status=<?=$status?>" class="btn btn-outline-secondary btn-sm">✕</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Status tabs -->
<ul class="nav nav-tabs mb-3">
    <?php $tabs=['active'=>[t('members_active'),'success'],'pending'=>[t('members_pending'),'warning'],'suspended'=>[t('lbl_suspended'),'secondary'],'rejected'=>[t('lbl_rejected'),'danger'],'all'=>[t('lbl_all'),'primary']]; ?>
    <?php foreach($tabs as $key=>[$label,$col]): ?>
    <li class="nav-item">
        <a class="nav-link <?=$status===$key?'active':''?>" href="?status=<?=$key?>&search=<?=urlencode($search)?>">
            <?=$label?> <span class="badge bg-<?=$col?> ms-1"><?=$counts[$key]?></span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<!-- Members table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:36px">#</th>
                        <th><?= t('members_name') ?></th>
                        <th><?= t('profile_membership') ?></th>
                        <th><?= t('lbl_phone') ?> / <?= t('lbl_email') ?></th>
                        <th><?= t('members_position') ?></th>
                        <th><?= t('members_joined') ?></th>
                        <th><?= t('wallet_savings') ?></th>
                        <th><?= t('loan_title') ?></th>
                        <th><?= t('members_status') ?></th>
                        <th><?= t('members_actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($members)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-5">
                        <i class="bi bi-people fs-1 d-block mb-2 opacity-25"></i>
                        <?= t('members_none') ?>.
                        <?php if($status==='active'): ?>
                        <br><button class="btn btn-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                            <i class="bi bi-person-plus me-1"></i>Add First Member
                        </button>
                        <?php endif; ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach($members as $i=>$m): ?>
                    <tr>
                        <td class="text-muted small"><?=$offset+$i+1?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?= memberAvatar($m, 36) ?>
                                <div>
                                    <div class="fw-semibold small"><?=htmlspecialchars($m['full_name'])?></div>
                                    <?php if($m['nickname']): ?>
                                    <div class="text-muted" style="font-size:.7rem">"<?=htmlspecialchars($m['nickname'])?>"</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="font-monospace small fw-semibold text-primary">
                            <?=$m['membership_number']??'<span class="text-muted">—</span>'?>
                        </td>
                        <td>
                            <div class="small"><?=htmlspecialchars($m['email'])?></div>
                            <div class="text-muted" style="font-size:.72rem"><?=htmlspecialchars($m['phone'])?></div>
                        </td>
                        <td class="small text-muted"><?=htmlspecialchars($m['chama_position']??'—')?></td>
                        <td class="small text-muted"><?=$m['joined_date']?date('d M Y',strtotime($m['joined_date'])):'—'?></td>
                        <td>
                            <div class="small fw-bold text-success"><?=money($m['total_savings'],$curr)?></div>
                            <div class="text-muted" style="font-size:.7rem"><?=$m['contrib_months']?> months</div>
                        </td>
                        <td class="small">
                            <?php if($m['active_loan_no']): ?>
                            <span class="text-warning fw-bold"><?=$m['active_loan_no']?></span><br>
                            <span class="text-danger small"><?=money($m['loan_balance']??0,$curr)?></span>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td><?=badgeStatus($m['status'])?></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">

                                <!-- View -->
                                <a href="<?=APP_URL?>/admin/member_detail.php?id=<?=$m['id']?>"
                                   class="btn btn-xs btn-outline-primary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>

                                <!-- Edit -->
                                <button class="btn btn-xs btn-outline-secondary" title="Edit"
                                    onclick="openEdit(<?=htmlspecialchars(json_encode($m))?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>

                                <!-- Reset password -->
                                <button class="btn btn-xs btn-outline-warning" title="Reset Password"
                                    onclick="openResetPw(<?=$m['id']?>, '<?=htmlspecialchars(addslashes($m['full_name']))?>')">
                                    <i class="bi bi-key"></i>
                                </button>

                                <!-- Approve pending -->
                                <?php if($m['status']==='pending'): ?>
                                <button class="btn btn-xs btn-success" title="Approve"
                                    onclick="openApprove(<?=$m['id']?>, '<?=htmlspecialchars(addslashes($m['full_name']))?>')">
                                    <i class="bi bi-check-circle"></i> Approve
                                </button>
                                <form method="POST" style="display:inline"
                                      onsubmit="return confirm('Reject <?=htmlspecialchars(addslashes($m['full_name']))?>')">
                                    <?=csrfField()?>
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="member_id" value="<?=$m['id']?>">
                                    <button class="btn btn-xs btn-danger"><i class="bi bi-x-circle"></i></button>
                                </form>
                                <?php endif; ?>

                                <!-- Suspend/Activate -->
                                <?php if($m['status']==='active'): ?>
                                <form method="POST" style="display:inline"
                                      onsubmit="return confirm('Suspend <?=htmlspecialchars(addslashes($m['full_name']))?>')">
                                    <?=csrfField()?>
                                    <input type="hidden" name="action" value="suspend">
                                    <input type="hidden" name="member_id" value="<?=$m['id']?>">
                                    <input type="hidden" name="redirect_status" value="<?=$status?>">
                                    <button class="btn btn-xs btn-warning" title="Suspend"><i class="bi bi-pause-circle"></i></button>
                                </form>
                                <?php elseif($m['status']==='suspended'): ?>
                                <form method="POST" style="display:inline">
                                    <?=csrfField()?>
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="member_id" value="<?=$m['id']?>">
                                    <input type="hidden" name="redirect_status" value="<?=$status?>">
                                    <button class="btn btn-xs btn-success" title="Reactivate"><i class="bi bi-play-circle"></i></button>
                                </form>
                                <?php endif; ?>

                                <!-- Delete -->
                                <button type="button" class="btn btn-xs btn-outline-danger"
                                    title="Delete member"
                                    onclick="openDeleteModal(<?=$m['id']?>, '<?=htmlspecialchars(addslashes($m['full_name']))?>', '<?=htmlspecialchars(addslashes($m['membership_number']))?>', '<?=$status?>')">
                                    <i class="bi bi-trash3"></i>
                                </button>

                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if($totalPages>1): ?>
    <div class="card-footer bg-card d-flex justify-content-between align-items-center py-2">
        <small class="text-muted">Showing <?=$offset+1?>–<?=min($offset+$perPage,$totalRows)?> of <?=$totalRows?></small>
        <nav><ul class="pagination pagination-sm mb-0">
            <?php for($p=1;$p<=$totalPages;$p++): ?>
            <li class="page-item <?=$p==$page?'active':''?>">
                <a class="page-link" href="?page=<?=$p?>&status=<?=$status?>&search=<?=urlencode($search)?>"><?=$p?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- MODAL: Add Member                                              -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="addMemberModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?=csrfField()?>
                <input type="hidden" name="action" value="add_member">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i><?= t('adm_mem_add') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        The member will be added as <strong><?= t('members_active') ?></strong> immediately.
                        They can log in using their email and the password you set (default: <strong>Chama@1234</strong>).
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('profile_name') ?> <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" placeholder="e.g. John Kamau" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('profile_email') ?> <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" placeholder="e.g. john@email.com" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('profile_phone') ?> <span class="text-danger">*</span></label>
                            <input type="text" name="phone" class="form-control" placeholder="e.g. 0722123456" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold d-flex justify-content-between align-items-center">
                                <?= t('profile_membership') ?>
                                <span class="badge bg-success" style="font-size:.65rem;font-weight:600"><?= t('lbl_optional') ?></span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:#f0fdf4;border-color:#86efac">
                                    <i class="bi bi-hash text-success"></i>
                                </span>
                                <input type="text" name="membership_number" id="memNoInput"
                                       class="form-control fw-semibold"
                                       value="<?=htmlspecialchars($nextNo)?>"
                                       placeholder="e.g. CM-001" readonly
                                       style="background:#f9fefb;color:#166534;font-family:monospace">
                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                        onclick="toggleMemNo()" id="memNoToggle" title="Override number">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            </div>
                            <div class="form-text" id="memNoHelp">
                                <i class="bi bi-info-circle me-1"></i>
                                <?= t('lbl_optional') ?>: <strong><?=htmlspecialchars($nextNo)?></strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('lbl_name') ?> (<?= t('lbl_optional') ?>)</label>
                            <input type="text" name="nickname" class="form-control" placeholder="e.g. Kangaroo">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('members_position') ?></label>
                            <input type="text" name="chama_position" class="form-control"
                                   placeholder="e.g. Chairman, Treasurer, Secretary">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('members_joined') ?></label>
                            <input type="date" name="joined_date" class="form-control"
                                   value="<?=date('Y-m-d')?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('profile_password') ?></label>
                            <div class="input-group">
                                <input type="text" name="set_password" id="addPwField" class="form-control"
                                       placeholder="Leave blank for default: Chama@1234">
                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                        onclick="genPw()"><i class="bi bi-arrow-repeat"></i></button>
                            </div>
                            <div class="form-text"><?= t('adm_mem_default_pw') ?></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= t('btn_cancel') ?></button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-person-check me-1"></i>Add Member
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- MODAL: Edit Member                                             -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="editMemberModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?=csrfField()?>
                <input type="hidden" name="action" value="edit_member">
                <input type="hidden" name="member_id" id="editMemberId">
                <input type="hidden" name="redirect_status" value="<?=$status?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i><?= t('adm_mem_edit') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('profile_name') ?> <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" id="editFullName" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('profile_email') ?> <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="editEmail" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('profile_phone') ?></label>
                            <input type="text" name="phone" id="editPhone" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('profile_membership') ?></label>
                            <input type="text" name="membership_number" id="editMemNo" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('lbl_name') ?></label>
                            <input type="text" name="nickname" id="editNickname" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('members_position') ?></label>
                            <input type="text" name="chama_position" id="editPosition" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= t('members_joined') ?></label>
                            <input type="date" name="joined_date" id="editJoinedDate" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= t('btn_cancel') ?></button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check2 me-1"></i><?= t('btn_save') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- MODAL: Approve                                                 -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?=csrfField()?>
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="member_id" id="approveMemberId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i><?= t('adm_mem_approve') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Approving: <strong id="approveMemberName"></strong></p>
                    <label class="form-label fw-semibold"><?= t('adm_mem_assign_no') ?></label>
                    <input type="text" name="membership_number" id="approveMemNo"
                           class="form-control" value="<?=htmlspecialchars($nextNo)?>">
                    <div class="form-text"><?= t('adm_mem_perm_id') ?></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= t('btn_cancel') ?></button>
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-check2 me-1"></i>Approve &amp; Activate
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- MODAL: Reset Password                                          -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="resetPwModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?=csrfField()?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="member_id" id="resetPwMemberId">
                <input type="hidden" name="redirect_status" value="<?=$status?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-key me-2"></i><?= t('adm_mem_reset_pw') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Resetting password for: <strong id="resetPwName"></strong></p>
                    <label class="form-label fw-semibold"><?= t('adm_mem_new_pw') ?></label>
                    <div class="input-group">
                        <input type="text" name="new_password" id="resetPwField"
                               class="form-control" placeholder="Leave blank for default: Chama@1234">
                        <button type="button" class="btn btn-outline-secondary" onclick="genResetPw()">
                            <i class="bi bi-arrow-repeat"></i> Generate
                        </button>
                    </div>
                    <div class="form-text mt-1">
                        The member will see their new password in their notifications.
                        They should change it after logging in.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= t('btn_cancel') ?></button>
                    <button type="submit" class="btn btn-warning btn-sm fw-bold">
                        <i class="bi bi-key me-1"></i>Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php $extraScripts = <<<JS
<style>
.btn-xs { padding:3px 7px; font-size:.75rem; }
</style>
<script>
function openEdit(m) {
    document.getElementById('editMemberId').value    = m.id;
    document.getElementById('editFullName').value    = m.full_name || '';
    document.getElementById('editEmail').value       = m.email || '';
    document.getElementById('editPhone').value       = m.phone || '';
    document.getElementById('editMemNo').value       = m.membership_number || '';
    document.getElementById('editNickname').value    = m.nickname || '';
    document.getElementById('editPosition').value   = m.chama_position || '';
    document.getElementById('editJoinedDate').value  = m.joined_date || '';
    new bootstrap.Modal(document.getElementById('editMemberModal')).show();
}
function openApprove(id, name) {
    document.getElementById('approveMemberId').value  = id;
    document.getElementById('approveMemberName').textContent = name;
    new bootstrap.Modal(document.getElementById('approveModal')).show();
}
function openResetPw(id, name) {
    document.getElementById('resetPwMemberId').value = id;
    document.getElementById('resetPwName').textContent = name;
    document.getElementById('resetPwField').value = '';
    new bootstrap.Modal(document.getElementById('resetPwModal')).show();
}
function genPw() {
    const chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#!';
    let pw = '';
    for (let i=0;i<10;i++) pw += chars[Math.floor(Math.random()*chars.length)];
    document.getElementById('addPwField').value = pw;
}
function genResetPw() {
    const chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#!';
    let pw = '';
    for (let i=0;i<10;i++) pw += chars[Math.floor(Math.random()*chars.length)];
    document.getElementById('resetPwField').value = pw;
}
function toggleMemNo() {
    const inp = document.getElementById('memNoInput');
    const btn = document.getElementById('memNoToggle');
    const hlp = document.getElementById('memNoHelp');
    if (!inp) return;
    if (inp.readOnly) {
        inp.readOnly = false;
        inp.style.background = '#fff';
        inp.style.color = '#1a2940';
        inp.focus(); inp.select();
        btn.innerHTML = '<i class="bi bi-x-lg"></i>';
        btn.title = 'Reset to auto';
        if (hlp) hlp.innerHTML = '<span class=\"text-warning\"><i class=\"bi bi-exclamation-triangle me-1\"></i>' + '<?= addslashes(t("adm_mem_unique")) ?>' + '</span>';
    } else {
        inp.readOnly = true;
        inp.style.background = '#f9fefb';
        inp.style.color = '#166534';
        btn.innerHTML = '<i class=\"bi bi-pencil\"></i>';
        inp.value = inp.getAttribute('data-auto') || inp.value;
        if (hlp) hlp.innerHTML = '<i class=\"bi bi-info-circle me-1\"></i>Auto-assigned — click pencil to change';
    }
}
document.addEventListener('DOMContentLoaded', function() {
    const inp = document.getElementById('memNoInput');
    if (inp) inp.setAttribute('data-auto', inp.value);
});
</script>
JS;

// ── Delete Confirmation Modal ───────────────────────────────────────────────
?>
<div class="modal fade" id="deleteMemberModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger">
                    <i class="bi bi-trash3 me-2"></i>Delete Member
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2">
                <div class="alert alert-warning d-flex gap-2 align-items-start mb-3">
                    <i class="bi bi-exclamation-triangle-fill text-warning fs-5 flex-shrink-0"></i>
                    <div class="small">
                        <strong><?= t('adm_mem_permanent') ?></strong><br>
                        This will also permanently delete ALL of this member's contributions, loans, and fines. This cannot be undone — if you just want to hide them without losing financial records, use <em>Suspend</em> instead.
                    </div>
                </div>
                <p class="mb-1">You are about to permanently delete:</p>
                <div class="rounded p-3 mb-3" style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3)">
                    <strong id="delMemberName" class="d-block fs-6"></strong>
                    <small id="delMemberNo" class="text-muted"></small>
                </div>
                <p class="small text-muted mb-0">Type <strong>DELETE</strong> to confirm:</p>
                <input type="text" id="deleteConfirmInput" class="form-control mt-1" placeholder="Type DELETE here">
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= t('btn_cancel') ?></button>
                <form method="POST" id="deleteMemberForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_member">
                    <input type="hidden" name="member_id" id="delMemberId">
                    <input type="hidden" name="redirect_status" id="delRedirectStatus">
                    <button type="submit" id="deleteMemberBtn" class="btn btn-danger btn-sm" disabled>
                        <i class="bi bi-trash3 me-1"></i>Yes, Delete Permanently
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function openDeleteModal(id, name, memNo, status) {
    document.getElementById('delMemberId').value         = id;
    document.getElementById('delMemberName').textContent = name;
    document.getElementById('delMemberNo').textContent   = memNo ? '#' + memNo : '';
    document.getElementById('delRedirectStatus').value   = status;
    document.getElementById('deleteConfirmInput').value  = '';
    document.getElementById('deleteMemberBtn').disabled  = true;
    new bootstrap.Modal(document.getElementById('deleteMemberModal')).show();
    setTimeout(() => document.getElementById('deleteConfirmInput').focus(), 400);
}
document.getElementById('deleteConfirmInput')?.addEventListener('input', function() {
    document.getElementById('deleteMemberBtn').disabled = this.value.trim().toUpperCase() !== 'DELETE';
});
</script>

<!-- ── BULK IMPORT MODAL ── -->
<div class="modal fade" id="bulkImportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-upload me-2 text-primary"></i><?= t('members_bulk_import') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info d-flex gap-2 align-items-start mb-3">
                    <i class="bi bi-info-circle-fill fs-5 flex-shrink-0"></i>
                    <div class="small">
                        <?= t('members_import_info') ?><br>
                        <a href="<?= APP_URL ?>/admin/import_members.php?download_template=1" class="fw-bold">
                            <i class="bi bi-download me-1"></i><?= t('members_download_template') ?>
                        </a>
                    </div>
                </div>
                <form method="POST" action="<?= APP_URL ?>/admin/import_members.php" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="import_csv">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= t('members_csv_file') ?></label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                        <div class="form-text"><?= t('members_csv_hint') ?></div>
                    </div>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= t('btn_cancel') ?></button>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i><?= t('members_import_btn') ?></button>
                    </div>
                </form>
                <hr class="my-3">
                <div class="text-center">
                    <small class="text-muted"><?= t('members_import_advanced') ?></small>
                    <a href="<?= APP_URL ?>/admin/import_members.php" class="btn btn-link btn-sm p-0 ms-1"><?= t('members_import_advanced_link') ?></a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php

require_once ROOT . '/includes/footer.php'; ?>
