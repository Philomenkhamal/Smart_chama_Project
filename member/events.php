<?php
/**
 * ChamaLedger — Member Events
 * Shows events, member's contribution status, totals, and payment options
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Events — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireMember();
require_once ROOT . '/includes/header.php';

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
$user   = currentUser();
$curr   = getSetting('currency', 'KES');

// ── HANDLE MANUAL PAYMENT SUBMISSION ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $eventId = (int)($_POST['event_id'] ?? 0);
    $amount  = (float)($_POST['amount']  ?? 0);
    $method  = sanitize($_POST['payment_method'] ?? 'mpesa');
    $ref     = sanitize($_POST['reference_code']  ?? '');
    $notes   = sanitize($_POST['notes']            ?? '');

    if ($eventId && $amount > 0) {
        // Check duplicate
        $dup = $pdo->prepare("SELECT id FROM event_contributions WHERE event_id=? AND user_id=? AND status!='rejected'");
        $dup->execute([$eventId, $userId]);
        if ($dup->fetch()) {
            setFlash('warning', 'You have already submitted a contribution for this event.');
        } else {
            $pdo->prepare("
                INSERT INTO event_contributions (event_id,user_id,amount,payment_method,reference_code,notes,status)
                VALUES (?,?,?,?,?,?,'pending')
            ")->execute([$eventId, $userId, $amount, $method, $ref, $notes]);

            $ev = $pdo->prepare("SELECT title FROM events WHERE id=?"); $ev->execute([$eventId]); $ev = $ev->fetch();
            // Notify admins
            $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll();
            foreach ($admins as $a) {
                createNotification($a['id'], '💰 Event Payment Submitted',
                    $user['full_name'] . " submitted {$curr} " . number_format($amount,0) . " for event: " . ($ev['title']??''),
                    'info', APP_URL . '/admin/events.php');
            }
            logActivity('EVENT_CONTRIB_SUBMIT', "Member submitted {$curr}{$amount} for event #{$eventId}");
            setFlash('success', 'Payment submitted! Awaiting admin confirmation.');
        }
    }
    redirect(APP_URL . '/member/events.php');
}

// ── FETCH EVENTS WITH MY CONTRIBUTION STATUS ──────────────────────────────────
$events = $pdo->prepare("
    SELECT e.*,
        ec.id          AS my_ec_id,
        ec.amount      AS my_amount,
        ec.status      AS my_status,
        ec.payment_method AS my_method,
        ec.reference_code AS my_ref,
        ec.recorded_at AS my_date,
        (SELECT COALESCE(SUM(amount),0) FROM event_contributions WHERE event_id=e.id AND status='confirmed') AS total_raised,
        (SELECT COUNT(*) FROM event_contributions WHERE event_id=e.id AND status='confirmed') AS contrib_count
    FROM events e
    LEFT JOIN event_contributions ec ON ec.event_id=e.id AND ec.user_id=?
    ORDER BY e.event_date DESC
");
$events->execute([$userId]);
$events = $events->fetchAll();

// ── TOTALS FOR THIS MEMBER ────────────────────────────────────────────────────
$myTotalEvents   = (float)$pdo->prepare("SELECT COALESCE(SUM(ec.amount),0) FROM event_contributions ec WHERE ec.user_id=? AND ec.status='confirmed'")->execute([$userId]) ? 0 : 0;
$stmt = $pdo->prepare("SELECT COALESCE(SUM(ec.amount),0) FROM event_contributions ec WHERE ec.user_id=? AND ec.status='confirmed'");
$stmt->execute([$userId]); $myTotalEvents = (float)$stmt->fetchColumn();

$myPendingTotal  = 0;
$myConfirmedCount = 0;
$myPendingCount  = 0;
foreach ($events as $ev) {
    if ($ev['my_status'] === 'confirmed') { $myConfirmedCount++; }
    if ($ev['my_status'] === 'pending')   { $myPendingCount++;  $myPendingTotal += (float)$ev['my_amount']; }
}

// JS-safe values
$jsCsrf   = htmlspecialchars(csrfToken(), ENT_QUOTES);
$jsAppUrl = htmlspecialchars(APP_URL, ENT_QUOTES);
$jsPhone  = htmlspecialchars(preg_replace('/[^0-9]/', '', $user['phone'] ?? ''), ENT_QUOTES);

$pochiPhone = defined('POCHI_PHONE') ? POCHI_PHONE : '+254701536730';
$pochiName  = defined('POCHI_NAME')  ? POCHI_NAME  : 'ChamaLedger';
?>

<!-- ── PAGE HEADER ─────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
    <div>
        <h4 class="fw-bold mb-1"><?= t('events_title') ?></h4>
        <small class="text-muted"><?= t('events_subtitle') ?></small>
    </div>
</div>

<!-- ── MY SUMMARY CARDS ───────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 p-3 text-center" style="background:rgba(0,196,113,.07)">
            <div style="font-size:1.5rem;font-weight:800;color:#00c471;font-family:'Syne',sans-serif"><?= $curr ?> <?= number_format($myTotalEvents,0) ?></div>
            <div style="font-size:.72rem;color:var(--text-muted);margin-top:.2rem"><?= t('events_my_total') ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 p-3 text-center" style="background:rgba(59,130,246,.07)">
            <div style="font-size:1.5rem;font-weight:800;color:#3b82f6;font-family:'Syne',sans-serif"><?= $myConfirmedCount ?></div>
            <div style="font-size:.72rem;color:var(--text-muted);margin-top:.2rem"><?= t('events_contributed') ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 p-3 text-center" style="background:rgba(245,158,11,.07)">
            <div style="font-size:1.5rem;font-weight:800;color:#f59e0b;font-family:'Syne',sans-serif"><?= $myPendingCount ?></div>
            <div style="font-size:.72rem;color:var(--text-muted);margin-top:.2rem"><?= t('events_pending') ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 p-3 text-center" style="background:rgba(139,92,246,.07)">
            <div style="font-size:1.5rem;font-weight:800;color:#8b5cf6;font-family:'Syne',sans-serif"><?= count($events) ?></div>
            <div style="font-size:.72rem;color:var(--text-muted);margin-top:.2rem"><?= t('events_total') ?></div>
        </div>
    </div>
</div>

<?php if (empty($events)): ?>
<div class="text-center text-muted py-5">
    <i class="bi bi-calendar-event" style="font-size:3rem;opacity:.2"></i>
    <p class="mt-3"><?= t('events_none') ?> <?= t('events_none_yet') ?></p>
</div>
<?php else: ?>

<div class="row g-3">
<?php foreach ($events as $ev):
    $contributed = $ev['my_amount'] !== null;
    $confirmed   = $ev['my_status'] === 'confirmed';
    $pending     = $ev['my_status'] === 'pending';
    $rejected    = $ev['my_status'] === 'rejected';
    $isClosed    = $ev['status'] !== 'active';
    $canPay      = !$isClosed && (!$contributed || $rejected);

    if ($confirmed)       $statusBadge = '<span class="badge" style="background:rgba(0,196,113,.15);color:#00c471;font-size:.68rem">' . t('events_confirmed') . '</span>';
    elseif ($pending)     $statusBadge = '<span class="badge" style="background:rgba(245,158,11,.15);color:#f59e0b;font-size:.68rem">' . t('ev_pending') . '</span>';
    elseif ($rejected)    $statusBadge = '<span class="badge" style="background:rgba(239,68,68,.15);color:#ef4444;font-size:.68rem">' . t('ev_rejected') . '</span>';
    elseif ($isClosed)    $statusBadge = '<span class="badge" style="background:rgba(107,114,128,.15);color:#6b7280;font-size:.68rem">' . t('ev_closed') . '</span>';
    else                  $statusBadge = '<span class="badge" style="background:rgba(239,68,68,.15);color:#ef4444;font-size:.68rem">' . t('ev_not_paid') . '</span>';

    $accentColor = $confirmed ? '#00c471' : ($pending ? '#f59e0b' : ($isClosed ? '#374151' : '#ef4444'));
?>
<div class="col-md-6">
    <div class="card border-0 shadow-sm h-100" style="border-left:3px solid <?= $accentColor ?> !important">
        <div class="card-body p-3">

            <!-- Title + badge -->
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <div class="fw-bold" style="font-size:.93rem"><?= htmlspecialchars($ev['title']) ?></div>
                    <div style="font-size:.73rem;color:var(--text-muted)">
                        <i class="bi bi-calendar3 me-1"></i><?= date('d M Y', strtotime($ev['event_date'])) ?>
                        <?php if ($ev['is_mandatory']): ?>
                        &nbsp;·&nbsp;<span style="color:#ef4444;font-weight:700"><?= t('ev_mandatory') ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?= $statusBadge ?>
            </div>

            <?php if ($ev['description']): ?>
            <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:.75rem;line-height:1.5"><?= htmlspecialchars($ev['description']) ?></div>
            <?php endif; ?>

            <!-- Group totals row -->
            <div class="d-flex justify-content-between align-items-center mb-2 py-2" style="border-top:1px solid rgba(255,255,255,.05);border-bottom:1px solid rgba(255,255,255,.05)">
                <div style="font-size:.75rem">
                    <span style="color:var(--text-muted)"><?= $ev['contrib_count'] ?> <?= t('mev_member') ?><?= $ev['contrib_count']!=1?'s':'' ?> <?= t('mev_contributed') ?></span>
                </div>
                <div style="font-size:.8rem;font-weight:700;color:#00c471">
                    <?= $curr ?> <?= number_format($ev['total_raised'],0) ?> raised
                </div>
            </div>

            <?php if ($ev['target_amount']): ?>
            <?php $pct = min(100, round($ev['total_raised'] / $ev['target_amount'] * 100)); ?>
            <div style="margin-bottom:.75rem">
                <div style="font-size:.7rem;color:var(--text-muted);margin-bottom:.3rem">
                    Suggested: <?= $curr ?> <?= number_format($ev['target_amount'],0) ?> per member
                </div>
                <div style="height:4px;background:rgba(255,255,255,.07);border-radius:99px;overflow:hidden">
                    <div style="width:<?= $pct ?>%;height:100%;background:linear-gradient(90deg,#00c471,#3b82f6);border-radius:99px"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- MY CONTRIBUTION DETAILS -->
            <?php if ($contributed && !$rejected): ?>
            <div class="p-2 rounded mb-2" style="background:rgba(<?= $confirmed ? '0,196,113' : '245,158,11' ?>,.08);font-size:.78rem">
                <div class="d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-<?= $confirmed ? 'check-circle-fill text-success' : 'clock text-warning' ?> me-1"></i>
                    My contribution: <strong><?= $curr ?> <?= number_format($ev['my_amount'],0) ?></strong></span>
                    <span style="color:var(--text-muted);font-size:.7rem"><?= $ev['my_method'] ?></span>
                </div>
                <?php if ($ev['my_ref']): ?>
                <div style="color:var(--text-muted);font-size:.7rem;margin-top:.2rem">Ref: <code><?= htmlspecialchars($ev['my_ref']) ?></code></div>
                <?php endif; ?>
                <?php if ($ev['my_date']): ?>
                <div style="color:var(--text-muted);font-size:.7rem"><?= date('d M Y H:i', strtotime($ev['my_date'])) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- PAY BUTTON -->
            <?php if ($canPay): ?>
            <button class="btn btn-sm w-100 fw-bold mt-1"
                    style="background:rgba(0,196,113,.15);color:#00c471;border:1px solid rgba(0,196,113,.3)"
                    onclick="openPayModal(<?= $ev['id'] ?>, '<?= addslashes(htmlspecialchars($ev['title'])) ?>', <?= (float)($ev['target_amount'] ?? 0) ?>)">
                <i class="bi bi-cash-coin me-2"></i>Pay for this Event
            </button>
            <?php elseif ($isClosed && !$contributed): ?>
            <div style="font-size:.75rem;color:var(--text-muted);text-align:center;margin-top:.5rem">
                <i class="bi bi-lock me-1"></i>Event closed — contributions no longer accepted
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════
     PAYMENT MODAL — STK Push + Pochi + Manual
══════════════════════════════════════════════════════ -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:520px">
        <div class="modal-content" style="background:var(--card-bg);border:1px solid var(--card-border)">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold mb-0" id="payModalTitle"><?= t('ev_pay_for') ?></h5>
                    <div style="font-size:.75rem;color:var(--text-muted)" id="payModalSub"><?= t('ev_choose_method') ?></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3">

                <!-- Payment tabs -->
                <div style="display:flex;gap:.4rem;background:rgba(255,255,255,.04);border-radius:10px;padding:.3rem;margin-bottom:1.25rem">
                    <button class="ev-tab active" id="evtab-stk"   onclick="evSwitchTab('stk')">
                        <i class="bi bi-phone me-1"></i>STK Push
                    </button>
                    <button class="ev-tab" id="evtab-pochi" onclick="evSwitchTab('pochi')">
                        <i class="bi bi-send me-1"></i>Pochi
                    </button>
                    <button class="ev-tab" id="evtab-manual" onclick="evSwitchTab('manual')">
                        <i class="bi bi-pencil-square me-1"></i>Manual
                    </button>
                </div>

                <!-- ── STK PUSH ── -->
                <div id="evpanel-stk">
                    <div id="evStkAlert" style="display:none;padding:.75rem 1rem;border-radius:10px;margin-bottom:1rem;font-size:.84rem"></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label" style="font-size:.78rem"><?= t('ev_mpesa_phone') ?></label>
                            <input type="tel" id="evStkPhone" class="form-control form-control-sm" placeholder="07XX XXX XXX">
                        </div>
                        <div class="col-6">
                            <label class="form-label" style="font-size:.78rem"><?= t('lbl_amount') ?> (<?= $curr ?>)</label>
                            <input type="number" id="evStkAmount" class="form-control form-control-sm" min="0.01" step="0.01">
                        </div>
                    </div>
                    <button class="btn btn-primary w-100 fw-bold" id="evStkBtn" style="font-size:.85rem">
                        <i class="bi bi-phone me-1"></i>Send STK Push to My Phone
                    </button>
                    <!-- Waiting state -->
                    <div id="evStkWaiting" style="display:none;margin-top:1rem;background:rgba(0,196,113,.06);border:1px solid rgba(0,196,113,.2);border-radius:12px;padding:1.1rem">
                        <div style="display:flex;align-items:center;gap:1rem">
                            <div style="width:42px;height:42px;border-radius:50%;background:rgba(0,196,113,.15);display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:#00c471;flex-shrink:0;animation:vibrate 1.5s ease infinite">
                                <i class="bi bi-phone-vibrate"></i>
                            </div>
                            <div style="flex:1">
                                <div style="font-weight:600;font-size:.85rem;margin-bottom:.3rem"><?= t('ev_check_phone') ?></div>
                                <div style="height:5px;background:rgba(255,255,255,.08);border-radius:99px;overflow:hidden">
                                    <div id="evStkBar" style="height:100%;width:100%;background:linear-gradient(90deg,#00c471,#00dc7e);border-radius:99px"></div>
                                </div>
                                <div id="evStkTimer" style="font-size:.7rem;color:var(--text-muted);margin-top:.25rem">90s remaining</div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="evStkCancelBtn" style="font-size:.75rem"><?= t('btn_cancel') ?></button>
                        </div>
                    </div>
                </div>

                <!-- ── POCHI LA BIASHARA ── -->
                <div id="evpanel-pochi" style="display:none">
                    <div style="background:rgba(0,196,113,.07);border:1px solid rgba(0,196,113,.2);border-radius:12px;padding:1.25rem;margin-bottom:1.25rem">
                        <div style="font-weight:700;font-size:.88rem;color:#00c471;margin-bottom:.75rem">
                            <i class="bi bi-phone-fill me-2"></i>How to Pay via Pochi La Biashara
                        </div>
                        <div class="d-flex gap-3 flex-wrap">
                            <div style="flex:1;min-width:170px">
                                <?php foreach ([
                                    ['1','Open M-Pesa on your phone'],
                                    ['2','Go to <strong>' . t('mc_send_money') . '</strong>'],
                                    ['3','Enter chama number: <strong style="color:#00c471;font-size:.95rem;font-family:monospace">' . htmlspecialchars($pochiPhone) . '</strong>'],
                                    ['4','Enter amount, confirm with PIN'],
                                    ['5','Copy the <strong>' . t('mc_mpesa_code') . '</strong> from SMS'],
                                    ['6','Enter it in Manual tab below'],
                                ] as [$n, $step]): ?>
                                <div style="display:flex;align-items:flex-start;gap:.5rem;margin-bottom:.45rem">
                                    <div style="width:20px;height:20px;border-radius:50%;background:#00c471;color:#060e1a;display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:800;flex-shrink:0;margin-top:1px"><?= $n ?></div>
                                    <div style="font-size:.8rem;color:var(--text-muted);line-height:1.45"><?= $step ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="background:var(--bg-2);border:1px solid rgba(0,196,113,.25);border-radius:10px;padding:1rem;min-width:140px;text-align:center;align-self:flex-start">
                                <div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.4rem"><?= t('lbl_send_to') ?></div>
                                <div style="font-family:'Syne',sans-serif;font-size:1.35rem;font-weight:800;color:#00c471;letter-spacing:.05em"><?= htmlspecialchars($pochiPhone) ?></div>
                                <div style="font-size:.75rem;color:var(--text-muted);margin-top:.3rem"><?= htmlspecialchars($pochiName) ?></div>
                                <div style="font-size:.65rem;background:rgba(0,196,113,.1);border-radius:5px;padding:.25rem .5rem;margin-top:.6rem;color:#00c471"><?= t('mev_pochi') ?></div>
                            </div>
                        </div>
                    </div>
                    <div style="font-size:.8rem;color:var(--text-muted);text-align:center">
                        After paying, use the <a href="javascript:void(0)" onclick="evSwitchTab('manual')" style="color:#00c471;font-weight:600"><?= t('mev_manual_tab') ?></a> to submit your M-Pesa reference code.
                    </div>
                </div>

                <!-- ── MANUAL ── -->
                <div id="evpanel-manual" style="display:none">
                    <form method="POST" id="evManualForm">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="event_id" id="evManualEventId">
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label" style="font-size:.78rem"><?= t('lbl_amount') ?> (<?= $curr ?>)</label>
                                <input type="number" name="amount" id="evManualAmount" class="form-control form-control-sm" min="0.01" step="0.01" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label" style="font-size:.78rem"><?= t('mev_payment_method') ?></label>
                                <select name="payment_method" class="form-select form-select-sm">
                                    <option value="mpesa">M-Pesa</option>
                                    <option value="pochi"><?= t('mev_pochi') ?></option>
                                    <option value="cash">Cash</option>
                                    <option value="bank">Bank</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label" style="font-size:.78rem"><?= t('mev_mpesa_ref') ?></label>
                            <input type="text" name="reference_code" id="evRefCode" class="form-control form-control-sm" placeholder="e.g. QHX7Y8Z9AB" style="font-family:monospace;text-transform:uppercase" autocomplete="off">
                        <div id="evRefFeedback" class="mpesa-ref-feedback"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:.78rem">Notes (optional)</label>
                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="Any extra info…">
                        </div>
                        <button type="submit" class="btn btn-success w-100 fw-bold" style="font-size:.85rem">
                            <i class="bi bi-send me-1"></i>Submit for Admin Confirmation
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>
</div>

<style>
.ev-tab {
    flex:1; padding:.42rem .6rem; border:none; border-radius:8px;
    background:transparent; color:rgba(255,255,255,.5);
    font-size:.78rem; font-weight:600; cursor:pointer; transition:all .15s;
}
.ev-tab.active { background:rgba(0,196,113,.18); color:#00c471; }
.ev-tab:hover:not(.active) { background:rgba(255,255,255,.06); color:rgba(255,255,255,.8); }
@keyframes vibrate {
    0%,100%{transform:rotate(0)} 20%{transform:rotate(-10deg)} 40%{transform:rotate(10deg)}
    60%{transform:rotate(-6deg)} 80%{transform:rotate(6deg)}
}
@keyframes spin { to{transform:rotate(360deg)} }
.spin-sm { display:inline-block;width:13px;height:13px;border:2px solid rgba(0,0,0,.3);border-top-color:transparent;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:5px }
@keyframes shrink { from{width:100%} to{width:0%} }

.mpesa-ref-feedback { font-size:.74rem;margin-top:.3rem;padding:.25rem .5rem;border-radius:6px;min-height:1.4rem; }
.mpesa-ref-feedback.valid { color:#00c471;background:rgba(0,196,113,.08); }
.mpesa-ref-feedback.invalid { color:#ef4444;background:rgba(239,68,68,.08); }
.mpesa-ref-feedback.checking { color:var(--text-muted); }
.mpesa-spin { display:inline-block;width:11px;height:11px;border:2px solid rgba(255,255,255,.2);border-top-color:var(--green);border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:4px; }
</style>

<script>
var EV_CSRF   = "<?= addslashes($jsCsrf) ?>";
var EV_APP    = "<?= addslashes($jsAppUrl) ?>";
var EV_PHONE  = "<?= $jsPhone ?>";
var evCurrentEventId = 0;

function openPayModal(eventId, title, suggested) {
    evCurrentEventId = eventId;
    document.getElementById('payModalTitle').textContent = title;
    document.getElementById('payModalSub').textContent = suggested > 0 ? 'Suggested: <?= $curr ?> ' + suggested.toLocaleString() : 'Choose your payment method';
    document.getElementById('evStkPhone').value  = EV_PHONE || '';
    document.getElementById('evStkAmount').value = suggested > 0 ? suggested : '';
    document.getElementById('evManualEventId').value  = eventId;
    document.getElementById('evManualAmount').value   = suggested > 0 ? suggested : '';
    evSwitchTab('stk');
    evStkReset();
    new bootstrap.Modal(document.getElementById('payModal')).show();
}

function evSwitchTab(tab) {
    ['stk','pochi','manual'].forEach(function(t) {
        document.getElementById('evpanel-' + t).style.display = t === tab ? 'block' : 'none';
        document.getElementById('evtab-' + t).classList.toggle('active', t === tab);
    });
}

// ── STK Push ────────────────────────────────────────────────────────────────
var evStkPoll = null, evStkCountdown = null, evStkCheckoutId = null;

function evStkAlert(msg, type) {
    var el = document.getElementById('evStkAlert');
    var map = {
        danger:  ['rgba(239,68,68,.1)', 'rgba(239,68,68,.3)', '#fca5a5'],
        success: ['rgba(0,196,113,.1)', 'rgba(0,196,113,.3)', '#6ee7b7'],
        warning: ['rgba(245,158,11,.1)','rgba(245,158,11,.3)','#fcd34d']
    };
    var c = map[type] || map.danger;
    el.style.cssText = 'background:' + c[0] + ';border:1px solid ' + c[1] + ';color:' + c[2] + ';border-radius:10px;padding:.7rem 1rem;margin-bottom:1rem;font-size:.84rem';
    el.innerHTML = msg;
    el.style.display = 'block';
}
function evStkHideAlert() { document.getElementById('evStkAlert').style.display = 'none'; }

function evStkReset() {
    evStkStop();
    document.getElementById('evStkWaiting').style.display = 'none';
    var btn = document.getElementById('evStkBtn');
    btn.style.display = 'block';
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-phone me-1"></i>Send STK Push to My Phone';
    evStkHideAlert();
}

document.getElementById('evStkBtn').addEventListener('click', function() {
    var phone  = document.getElementById('evStkPhone').value.trim();
    var amount = parseFloat(document.getElementById('evStkAmount').value);
    var btn    = this;

    evStkHideAlert();
    if (!phone)  return evStkAlert('Enter your M-Pesa phone number.', 'danger');
    if (!amount || amount <= 0) return evStkAlert('Enter a valid amount.', 'danger');

    btn.disabled = true;
    btn.innerHTML = '<span class="spin-sm"></span>Sending…';

    fetch(EV_APP + '/api/mpesa_stk.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
            csrf_token:    EV_CSRF,
            phone:         phone,
            amount:        amount,
            payment_month: null,
            event_id:      evCurrentEventId
        })
    })
    .then(function(r) { return r.text(); })
    .then(function(text) {
        var data;
        try { data = JSON.parse(text); } catch(e) {
            evStkAlert('Server error: ' + text.substring(0,200), 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-phone me-1"></i>Send STK Push to My Phone';
            return;
        }
        if (!data.success) {
            var msg = data.message || 'Request failed.';
            if (data.can_clear) msg += ' <a href="' + EV_APP + '/api/mpesa_clear.php" style="color:inherit;font-weight:700;text-decoration:underline">Clear & retry</a>';
            evStkAlert(msg, 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-phone me-1"></i>Send STK Push to My Phone';
            return;
        }
        evStkCheckoutId = data.checkout_request_id;
        document.getElementById('evStkWaiting').style.display = 'block';
        btn.style.display = 'none';
        evStkStartCountdown(90);
        evStkStartPoll();
    })
    .catch(function(err) {
        evStkAlert('Network error: ' + err.message, 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-phone me-1"></i>Send STK Push to My Phone';
    });
});

document.getElementById('evStkCancelBtn').addEventListener('click', evStkReset);

function evStkStartCountdown(secs) {
    var rem = secs;
    var bar = document.getElementById('evStkBar');
    var timer = document.getElementById('evStkTimer');
    bar.style.transition = 'width ' + secs + 's linear';
    bar.style.width = '0%';
    evStkCountdown = setInterval(function() {
        rem--;
        timer.textContent = rem + 's remaining';
        if (rem <= 0) { evStkStop(); evStkAlert('Timed out. Please try again.', 'warning'); }
    }, 1000);
}

function evStkStartPoll() {
    evStkPoll = setInterval(function() {
        fetch(EV_APP + '/api/mpesa_status.php?checkout_id=' + evStkCheckoutId + '&event_id=' + evCurrentEventId, {
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status !== 'pending') {
                evStkStop();
                document.getElementById('evStkWaiting').style.display = 'none';
                if (data.status === 'success') {
                    evStkAlert('<strong>✓ Payment successful!</strong> Refreshing…', 'success');
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    evStkAlert('Payment failed: ' + (data.message || 'Cancelled or insufficient funds.'), 'danger');
                    document.getElementById('evStkBtn').style.display = 'block';
                    document.getElementById('evStkBtn').disabled = false;
                    document.getElementById('evStkBtn').innerHTML = '<i class="bi bi-phone me-1"></i>Send STK Push to My Phone';
                }
            }
        })
        .catch(function() {});
    }, 3000);
}

function evStkStop() {
    if (evStkPoll)     { clearInterval(evStkPoll);     evStkPoll = null; }
    if (evStkCountdown){ clearInterval(evStkCountdown); evStkCountdown = null; }
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
    attachMpesaValidation('evRefCode', 'evRefFeedback', EV_APP);
});

</script>

<?php require_once ROOT . '/includes/footer.php'; ?>
