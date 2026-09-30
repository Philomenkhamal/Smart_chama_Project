<?php
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
require_once ROOT . '/includes/mailer.php';
startSession();
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');
if (!isLoggedIn() || (($_SESSION['user_role']??$_SESSION['role']??'') !== 'admin')) {
    echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
}
if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit;
}

$pdo    = getDB();
$action = sanitize($_POST['action'] ?? '');
$ids    = array_map('intval', json_decode($_POST['ids'] ?? '[]', true) ?: []);
$curr   = getSetting('currency','KES');

switch ($action) {

    case 'approve_members':
        // Approve all pending members
        $pending = $ids
            ? $pdo->query("SELECT * FROM users WHERE id IN (" . implode(',', $ids) . ") AND status='pending'")->fetchAll()
            : $pdo->query("SELECT * FROM users WHERE status='pending' AND role='member'")->fetchAll();

        $count = 0;
        foreach ($pending as $m) {
            $memNo = generateMembershipNumber();
            $pdo->prepare("UPDATE users SET status='active', membership_number=?, email_verified=1 WHERE id=?")->execute([$memNo, $m['id']]);
            createNotification($m['id'], '✅ Account Approved', 'Welcome! Your account is now active. Membership: ' . $memNo, 'success', APP_URL . '/member/dashboard.php');
            try { $mailer = new Mailer(); $mailer->sendAccountApproved($m); } catch(Exception $e){}
            logActivity('BULK_APPROVE_MEMBER', 'Approved member ID:' . $m['id']);
            $count++;
        }
        echo json_encode(['ok'=>true, 'msg'=>"{$count} member(s) approved successfully.", 'count'=>$count]);
        break;

    case 'send_reminders':
        // Send contribution reminders to members who haven't paid this month
        $thisMonth = date('Y-m');
        $paidIds   = $pdo->query("SELECT DISTINCT user_id FROM contributions WHERE status='confirmed' AND DATE_FORMAT(payment_month,'%Y-%m')='{$thisMonth}'")->fetchAll(PDO::FETCH_COLUMN);

        $query = empty($ids)
            ? "SELECT * FROM users WHERE role='member' AND status='active'" . (!empty($paidIds) ? " AND id NOT IN (" . implode(',', $paidIds) . ")" : "")
            : "SELECT * FROM users WHERE id IN (" . implode(',', $ids) . ") AND status='active'";
        $members = $pdo->query($query)->fetchAll();

        $count = 0;
        $month = date('Y-m-01');
        foreach ($members as $m) {
            createNotification($m['id'], '⚠️ Contribution Reminder', 'Your contribution for ' . date('F Y') . ' is pending. Please pay at your earliest convenience.', 'warning', APP_URL . '/member/contributions.php');
            try { $mailer = new Mailer(); $mailer->sendMissedPayment($m, $month); } catch(Exception $e){}
            $count++;
        }
        logActivity('BULK_SEND_REMINDERS', "Sent reminders to {$count} members");
        echo json_encode(['ok'=>true, 'msg'=>"Reminders sent to {$count} member(s).", 'count'=>$count]);
        break;

    case 'reject_members':
        if (empty($ids)) { echo json_encode(['ok'=>false,'msg'=>'Select members first']); exit; }
        foreach ($ids as $id) {
            $m = $pdo->prepare("SELECT * FROM users WHERE id=?");
            $m->execute([$id]); $m = $m->fetch();
            if ($m) {
                $pdo->prepare("UPDATE users SET status='rejected' WHERE id=?")->execute([$id]);
                createNotification($id, 'Registration Update', 'Your application was not approved. Contact the admin for details.', 'danger');
                try { $mailer = new Mailer(); $mailer->sendLoanRejected($m, 'Registration not approved at this time.'); } catch(Exception $e){}
            }
        }
        logActivity('BULK_REJECT_MEMBERS', 'Rejected ' . count($ids) . ' members');
        echo json_encode(['ok'=>true, 'msg'=>count($ids) . ' member(s) rejected.', 'count'=>count($ids)]);
        break;

    case 'suspend_members':
        if (empty($ids)) { echo json_encode(['ok'=>false,'msg'=>'Select members first']); exit; }
        $pdo->query("UPDATE users SET status='suspended' WHERE id IN (" . implode(',', $ids) . ")");
        logActivity('BULK_SUSPEND', 'Suspended ' . count($ids) . ' members');
        echo json_encode(['ok'=>true, 'msg'=>count($ids) . ' member(s) suspended.']);
        break;

    default:
        echo json_encode(['ok'=>false, 'msg'=>'Unknown action']);
}
