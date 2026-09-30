<?php
/**
 * ChamaLedger — SMS Reminders Centre
 * Admin can send bulk SMS reminders for contributions, loan repayments, events
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'SMS Reminders — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();

$pdo     = getDB();
$adminId = (int)$_SESSION['user_id'];

// Auto-create tables if missing
$pdo->exec("CREATE TABLE IF NOT EXISTS sms_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED DEFAULT NULL,
    phone VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) DEFAULT 'general',
    status VARCHAR(20) DEFAULT 'sent',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$curr    = getSetting('currency', 'KES');

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $type    = sanitize($_POST['sms_type'] ?? '');
    $month   = sanitize($_POST['month']    ?? date('Y-m'));
    $custom  = sanitize($_POST['custom_message'] ?? '');
    $targets = $_POST['targets'] ?? 'unpaid'; // 'all' | 'unpaid' | 'overdue'

    $sent = 0; $failed = 0; $skipped = 0;
    $monthly = (float)getSetting('monthly_contribution', 2000);
    $pochiPhone = defined('POCHI_PHONE') ? POCHI_PHONE : '+254701536730';

    if ($type === 'contribution_reminder') {
        // Members who haven't paid this month
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.phone FROM users u
            WHERE u.role='member' AND u.status='active' AND u.phone IS NOT NULL
            " . ($targets === 'unpaid' ? "
            AND u.id NOT IN (
                SELECT user_id FROM contributions
                WHERE payment_month=? AND status='confirmed'
            )" : "") . "
            ORDER BY u.full_name
        ");
        $params = $targets === 'unpaid' ? [$month . '-01'] : [];
        $stmt->execute($params);
        $members = $stmt->fetchAll();

        $monthLabel = date('F Y', strtotime($month . '-01'));
        foreach ($members as $m) {
            if (!$m['phone']) { $skipped++; continue; }
            $result = SMS::contributionReminder($m, $monthly, $monthLabel, $pochiPhone);
            $result['success'] ? $sent++ : $failed++;
            // Log
            $pdo->prepare("INSERT INTO sms_log (user_id,phone,message,type,status) VALUES (?,?,?,?,?)")
                ->execute([$m['id'], $m['phone'], "Contribution reminder for $monthLabel", 'contribution_reminder', $result['success']?'sent':'failed']);
            usleep(150000);
        }

    } elseif ($type === 'loan_overdue') {
        // Members with overdue loans
        $stmt = $pdo->query("
            SELECT u.id, u.full_name, u.phone, l.balance, l.loan_number, l.due_date
            FROM loans l JOIN users u ON u.id=l.user_id
            WHERE l.status IN ('approved','disbursed') AND u.phone IS NOT NULL
            AND (l.due_date IS NULL OR l.due_date < CURDATE())
            ORDER BY u.full_name
        ");
        $loans = $stmt->fetchAll();
        foreach ($loans as $l) {
            if (!$l['phone']) { $skipped++; continue; }
            $due = $l['due_date'] ? date('d M Y', strtotime($l['due_date'])) : 'overdue';
            $result = SMS::loanRepaymentDue($l, (float)$l['balance'], $due, $l['loan_number']);
            $result['success'] ? $sent++ : $failed++;
            $pdo->prepare("INSERT INTO sms_log (user_id,phone,message,type,status) VALUES (?,?,?,?,?)")
                ->execute([$l['id'], $l['phone'], "Loan overdue reminder {$l['loan_number']}", 'loan_overdue', $result['success']?'sent':'failed']);
            usleep(150000);
        }

    } elseif ($type === 'custom') {
        if ($custom) {
            $stmt = $pdo->query("SELECT id,full_name,phone FROM users WHERE role='member' AND status='active' AND phone IS NOT NULL");
            $members = $stmt->fetchAll();
            foreach ($members as $m) {
                $result = SMS::send($m['phone'], $custom);
                $result['success'] ? $sent++ : $failed++;
                $pdo->prepare("INSERT INTO sms_log (user_id,phone,message,type,status) VALUES (?,?,?,?,?)")
                    ->execute([$m['id'], $m['phone'], $custom, 'custom', $result['success']?'sent':'failed']);
                usleep(150000);
            }
        }

    } elseif ($type === 'event_reminder') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        if ($eventId) {
            $ev = $pdo->prepare("SELECT * FROM events WHERE id=?"); $ev->execute([$eventId]); $ev = $ev->fetch();
            if ($ev) {
                $stmt = $pdo->prepare("
                    SELECT u.id, u.full_name, u.phone FROM users u
                    WHERE u.role='member' AND u.status='active' AND u.phone IS NOT NULL
                    AND u.id NOT IN (SELECT user_id FROM event_contributions WHERE event_id=? AND status IN ('confirmed','pending'))
                ");
                $stmt->execute([$eventId]);
                $members = $stmt->fetchAll();
                foreach ($members as $m) {
                    $result = SMS::eventReminder($m, $ev['title'], (float)($ev['target_amount']??0));
                    $result['success'] ? $sent++ : $failed++;
                    $pdo->prepare("INSERT INTO sms_log (user_id,phone,message,type,status) VALUES (?,?,?,?,?)")
                        ->execute([$m['id'], $m['phone'], "Event reminder: {$ev['title']}", 'event_reminder', $result['success']?'sent':'failed']);
                    usleep(150000);
                }
            }
        }
    }

    logActivity('SMS_BULK_SENT', "Type:{$type} sent:{$sent} failed:{$failed}");
    $msg = "SMS batch complete — {$sent} sent";
    if ($failed)  $msg .= ", {$failed} failed";
    if ($skipped) $msg .= ", {$skipped} skipped (no phone)";
    if (!SMS_ENABLED || SMS_ENABLED === '0') $msg .= " (SMS_ENABLED=0 — dry run, nothing actually sent)";
    setFlash($failed > 0 ? 'warning' : 'success', $msg);
    redirect(APP_URL . '/admin/sms_reminders.php');
}

// ── Data ──────────────────────────────────────────────────────────────────────
$totalMembers  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND status='active'")->fetchColumn();
$withPhone     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member' AND status='active' AND phone IS NOT NULL AND phone!='' ")->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE u.role='member' AND u.status='active' AND u.id NOT IN (SELECT user_id FROM contributions WHERE payment_month=? AND status='confirmed')");
$st->execute([date('Y-m-01')]); $unpaidThisMonth = (int)$st->fetchColumn();

$overdueLoans  = (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status IN ('approved','disbursed') AND (due_date IS NULL OR due_date < CURDATE())")->fetchColumn();
$events        = $pdo->query("SELECT id,title FROM events WHERE status='active' ORDER BY event_date DESC")->fetchAll();
$smsLog        = $pdo->query("SELECT s.*,u.full_name FROM sms_log s LEFT JOIN users u ON u.id=s.user_id ORDER BY s.sent_at DESC LIMIT 50")->fetchAll();
$smsEnabled    = SMS_ENABLED === '1' || SMS_ENABLED === true;

require_once ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-chat-dots me-2" style="color:#00c471"></i><?= t('nav_sms_reminders') ?></h4>
        <small class="text-muted"><?= t('adm_sms_subtitle') ?></small>
    </div>
    <?php if (!$smsEnabled): ?>
    <div class="badge" style="background:rgba(245,158,11,.15);color:#f59e0b;font-size:.75rem;padding:.5rem .9rem">
        <i class="bi bi-exclamation-triangle me-1"></i>SMS_ENABLED=0 — Dry run mode (no SMS actually sent)
    </div>
    <?php endif; ?>
</div>

<!-- Status cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.6rem;font-weight:800;color:#00c471"><?= $withPhone ?></div>
            <div style="font-size:.72rem;color:var(--text-muted)"><?= t('adm_sms_with_phone') ?></div>
            <div style="font-size:.68rem;color:var(--text-muted)"><?= $totalMembers - $withPhone ?> <?= t('adm_sms_missing') ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.6rem;font-weight:800;color:#f59e0b"><?= $unpaidThisMonth ?></div>
            <div style="font-size:.72rem;color:var(--text-muted)"><?= t('adm_sms_unpaid') ?></div>
            <div style="font-size:.68rem;color:var(--text-muted)"><?= date('F Y') ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.6rem;font-weight:800;color:#ef4444"><?= $overdueLoans ?></div>
            <div style="font-size:.72rem;color:var(--text-muted)"><?= t('loans_overdue') ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm p-3 text-center">
            <div style="font-size:1.6rem;font-weight:800;color:#3b82f6"><?= count($smsLog) ?></div>
            <div style="font-size:.72rem;color:var(--text-muted)"><?= t('lbl_recent_sms') ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Send SMS Panel -->
    <div class="col-md-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header border-0">
                <h6 class="mb-0 fw-bold"><i class="bi bi-send me-2 text-success"></i><?= t('btn_send') ?> SMS</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>

                    <!-- SMS Type -->
                    <div class="mb-3">
                        <label class="form-label" style="font-size:.8rem"><?= t('adm_sms_type') ?></label>
                        <select name="sms_type" class="form-select form-select-sm" id="smsType" onchange="showSmsFields()">
                            <option value="contribution_reminder"><?= t('adm_sms_contrib') ?></option>
                            <option value="loan_overdue"><?= t('adm_sms_overdue') ?></option>
                            <option value="event_reminder"><?= t('lbl_event_remind') ?></option>
                            <option value="custom"><?= t('lbl_custom_msg') ?></option>
                        </select>
                    </div>

                    <!-- Contribution fields -->
                    <div id="field-contribution">
                        <div class="mb-2">
                            <label class="form-label" style="font-size:.8rem"><?= t('lbl_month') ?></label>
                            <input type="month" name="month" class="form-select form-select-sm" value="<?= date('Y-m') ?>" max="<?= date('Y-m') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:.8rem"><?= t('lbl_send_to') ?></label>
                            <select name="targets" class="form-select form-select-sm">
                                <option value="unpaid"><?= t('sms_unpaid_only') ?></option>
                                <option value="all"><?= t('sms_all_active') ?></option>
                            </select>
                        </div>
                        <div class="p-2 rounded mb-3" style="background:rgba(0,196,113,.07);font-size:.75rem;color:var(--text-muted)">
                            <i class="bi bi-info-circle me-1"></i>
                            Message: <em>"Dear [Name], your [Month] contribution of <?= $curr ?> <?= getSetting('monthly_contribution','2000') ?> is due. Pay via Pochi <?= defined('POCHI_PHONE')?POCHI_PHONE:'' ?>. Login: [URL]"</em>
                        </div>
                    </div>

                    <!-- Event fields -->
                    <div id="field-event" style="display:none">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:.8rem">Event</label>
                            <select name="event_id" class="form-select form-select-sm">
                                <option value=""><?= t('sms_select_event') ?></option>
                                <?php foreach ($events as $ev): ?>
                                <option value="<?= $ev['id'] ?>"><?= htmlspecialchars($ev['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Custom message -->
                    <div id="field-custom" style="display:none">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:.8rem">Message <span id="charCount" style="float:right;color:var(--text-muted)">0/160</span></label>
                            <textarea name="custom_message" class="form-control form-control-sm" rows="4"
                                      maxlength="160" placeholder="Type your message…"
                                      onkeyup="document.getElementById('charCount').textContent=this.value.length+'/160'"></textarea>
                            <div style="font-size:.7rem;color:var(--text-muted);margin-top:.2rem">Sent to ALL active members with a phone number</div>
                        </div>
                    </div>

                    <!-- AT Config warning -->
                    <?php if (!AT_API_KEY && $smsEnabled): ?>
                    <div class="alert alert-warning py-2" style="font-size:.78rem">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Africa's Talking API key not set. Add <code>AT_API_KEY</code> and <code>AT_USERNAME</code> to your environment.
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-success w-100 fw-bold"
                            onclick="return confirm('Send SMS to qualifying members?')">
                        <i class="bi bi-send me-1"></i>
                        Send SMS <?php if (!$smsEnabled): ?>(Dry Run)<?php endif; ?>
                    </button>
                </form>

                <!-- Africa's Talking setup guide -->
                <div class="mt-4 pt-3" style="border-top:1px solid var(--border)">
                    <div style="font-size:.78rem;font-weight:700;color:var(--text-muted);margin-bottom:.6rem">
                        <i class="bi bi-gear me-1"></i>Setup Guide
                    </div>
                    <ol style="font-size:.75rem;color:var(--text-muted);padding-left:1.2rem;line-height:1.8">
                        <li>Register at <a href="https://africastalking.com" target="_blank" style="color:#00c471">africastalking.com</a></li>
                        <li>Create an app, get your API Key</li>
                        <li>Set <code>AT_USERNAME</code>, <code>AT_API_KEY</code> in config</li>
                        <li>Set <code>SMS_ENABLED=1</code> to go live</li>
                        <li>Top up your AT balance (≈KES 1/SMS)</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <!-- SMS Log -->
    <div class="col-md-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header border-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2 text-success"></i>Recent SMS Log</h6>
                <span style="font-size:.72rem;color:var(--text-muted)">Last 50 messages</span>
            </div>
            <div class="table-responsive" style="max-height:520px;overflow-y:auto">
                <table class="table table-hover mb-0" style="font-size:.78rem">
                    <thead style="position:sticky;top:0;background:var(--sidebar-bg)">
                        <tr>
                            <th class="ps-3">Member</th>
                            <th>Phone</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Sent</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($smsLog as $s):
                        $sb = $s['status']==='sent' ? 'success' : 'danger';
                    ?>
                    <tr>
                        <td class="ps-3"><?= htmlspecialchars($s['full_name'] ?? 'Unknown') ?></td>
                        <td style="font-family:monospace"><?= htmlspecialchars($s['phone']) ?></td>
                        <td><span style="font-size:.68rem;background:rgba(59,130,246,.1);color:#3b82f6;padding:.15rem .5rem;border-radius:99px"><?= str_replace('_',' ', $s['type']) ?></span></td>
                        <td><span class="badge bg-<?= $sb ?> bg-opacity-15 text-<?= $sb ?>" style="font-size:.68rem"><?= $s['status'] ?></span></td>
                        <td style="color:var(--text-muted)"><?= date('d M H:i', strtotime($s['sent_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($smsLog)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No SMS sent yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function showSmsFields() {
    var type = document.getElementById('smsType').value;
    document.getElementById('field-contribution').style.display = type==='contribution_reminder' ? 'block' : 'none';
    document.getElementById('field-event').style.display        = type==='event_reminder'        ? 'block' : 'none';
    document.getElementById('field-custom').style.display       = type==='custom'                ? 'block' : 'none';
}
</script>

<?php require_once ROOT . '/includes/footer.php'; ?>
