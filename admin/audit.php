<?php
/**
 * ChamaLedger — Audit Trail
 * Full log of every admin + member action in the system
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Audit Trail — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();

$pdo = getDB();

// ── Filters ────────────────────────────────────────────────────────────────────
$search    = sanitize($_GET['q']      ?? '');
$filterUser= (int)($_GET['user_id']   ?? 0);
$filterAct = sanitize($_GET['action'] ?? '');
$dateFrom  = sanitize($_GET['from']   ?? date('Y-m-01'));
$dateTo    = sanitize($_GET['to']     ?? date('Y-m-d'));
$perPage   = 50;
$page      = max(1, (int)($_GET['page'] ?? 1));
$offset    = ($page - 1) * $perPage;

// ── Build query ────────────────────────────────────────────────────────────────
$where = ["DATE(al.created_at) BETWEEN ? AND ?"];
$params = [$dateFrom, $dateTo];

if ($search) {
    $where[] = "(al.action LIKE ? OR al.description LIKE ? OR u.full_name LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($filterUser) {
    $where[] = "al.user_id = ?";
    $params[] = $filterUser;
}
if ($filterAct) {
    $where[] = "al.action = ?";
    $params[] = $filterAct;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$totalRows = (int)$pdo->prepare("SELECT COUNT(*) FROM activity_log al LEFT JOIN users u ON al.user_id=u.id $whereSQL")->execute($params) ?
             $pdo->prepare("SELECT COUNT(*) FROM activity_log al LEFT JOIN users u ON al.user_id=u.id $whereSQL")->execute($params) : 0;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log al LEFT JOIN users u ON al.user_id=u.id $whereSQL");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $pdo->prepare("
    SELECT al.*, u.full_name, u.role, u.membership_number
    FROM activity_log al
    LEFT JOIN users u ON al.user_id = u.id
    $whereSQL
    ORDER BY al.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Distinct actions for filter dropdown ───────────────────────────────────────
$actions = $pdo->query("SELECT DISTINCT action FROM activity_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

// ── All members for user filter ────────────────────────────────────────────────
$members = $pdo->query("SELECT id, full_name, membership_number FROM users WHERE role='member' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

// ── Color-code action types ────────────────────────────────────────────────────
function actionBadge(string $action): string {
    $action = strtoupper($action);
    if (str_contains($action, 'DELETE'))  return 'danger';
    if (str_contains($action, 'LOGIN'))   return 'primary';
    if (str_contains($action, 'FAIL') || str_contains($action, 'REJECT')) return 'warning';
    if (str_contains($action, 'ADD') || str_contains($action, 'CONFIRM') || str_contains($action, 'APPROVE') || str_contains($action, 'IMPORT')) return 'success';
    if (str_contains($action, 'LOAN'))    return 'info';
    if (str_contains($action, 'SUSPEND') || str_contains($action, 'EDIT')) return 'secondary';
    return 'dark';
}

require_once ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-shield-check me-2 text-primary"></i><?= t('audit_title') ?></h4>
        <small class="text-muted">Full log of every action in the system — <?= number_format($totalRows) ?> record(s) found</small>
    </div>
    <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-download me-1"></i>Export CSV
    </a>
</div>

<?= getFlash() ?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1"><?= t('btn_search') ?></label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Name, action, description…" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1"><?= t('adm_audit_action_type') ?></label>
                <select name="action" class="form-select form-select-sm">
                    <option value=""><?= t('adm_audit_all_actions') ?></option>
                    <?php foreach($actions as $a): ?>
                    <option value="<?= htmlspecialchars($a) ?>" <?= $filterAct===$a?'selected':'' ?>><?= htmlspecialchars($a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1"><?= t('lbl_member') ?></label>
                <select name="user_id" class="form-select form-select-sm">
                    <option value=""><?= t('adm_audit_all_users') ?></option>
                    <?php foreach($members as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $filterUser===$m['id']?'selected':'' ?>>
                        <?= htmlspecialchars($m['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1"><?= t('lbl_from') ?></label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= $dateFrom ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= $dateTo ?>">
            </div>
            <div class="col-md-1">
                <button class="btn btn-primary btn-sm w-100"><?= t('btn_filter') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Log Table -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem">
            <thead style="background:#f8f9fa">
                <tr>
                    <th class="ps-3" style="width:150px"><?= t('adm_audit_datetime') ?></th>
                    <th style="width:160px"><?= t('lbl_user') ?></th>
                    <th style="width:160px"><?= t('lbl_action') ?></th>
                    <th><?= t('lbl_description') ?></th>
                    <th style="width:120px"><?= t('adm_audit_ip') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="5" class="text-center text-muted py-5"><?= t('lbl_no_activity') ?></td></tr>
                <?php endif; ?>
                <?php foreach($logs as $log): ?>
                <tr>
                    <td class="ps-3 text-muted small text-nowrap">
                        <?= date('d M Y', strtotime($log['created_at'])) ?><br>
                        <span style="font-size:.75rem"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
                    </td>
                    <td>
                        <?php if ($log['full_name']): ?>
                        <div class="fw-semibold small"><?= htmlspecialchars($log['full_name']) ?></div>
                        <div class="text-muted" style="font-size:.75rem">
                            <?= $log['role'] === 'admin' ? '🛡 Admin' : ('👤 ' . htmlspecialchars($log['membership_number'] ?? '')) ?>
                        </div>
                        <?php else: ?>
                        <span class="text-muted small"><?= t('lbl_system') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge text-bg-<?= actionBadge($log['action']) ?>" style="font-size:.72rem">
                            <?= htmlspecialchars($log['action']) ?>
                        </span>
                    </td>
                    <td class="small text-muted"><?= htmlspecialchars($log['description']) ?></td>
                    <td class="small text-muted font-monospace"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if($totalPages > 1): ?>
    <div class="card-footer bg-card d-flex justify-content-between align-items-center py-2">
        <small class="text-muted">Page <?= $page ?> of <?= $totalPages ?> &nbsp;·&nbsp; <?= number_format($totalRows) ?> records</small>
        <div class="d-flex gap-1">
            <?php for($p=1;$p<=$totalPages;$p++): ?>
            <a href="?<?= http_build_query(array_merge($_GET,['page'=>$p])) ?>"
               class="btn btn-sm <?= $p===$page?'btn-primary':'btn-outline-secondary' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
// ── CSV Export ─────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Re-run without limit
    $allStmt = $pdo->prepare("
        SELECT al.created_at, u.full_name, u.role, u.membership_number, al.action, al.description, al.ip_address
        FROM activity_log al LEFT JOIN users u ON al.user_id=u.id
        $whereSQL ORDER BY al.created_at DESC
    ");
    $allStmt->execute($params);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_log_'.date('Y-m-d').'.csv"');
    $f = fopen('php://output','w');
    fputcsv($f, ['Date','Time','User','Role','Membership No','Action','Description','IP']);
    foreach($allStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        fputcsv($f, [
            date('d/m/Y', strtotime($r['created_at'])),
            date('H:i:s', strtotime($r['created_at'])),
            $r['full_name'] ?? 'System', $r['role'] ?? '', $r['membership_number'] ?? '',
            $r['action'], $r['description'], $r['ip_address'] ?? ''
        ]);
    }
    fclose($f); exit;
}

require_once ROOT . '/includes/footer.php';
?>
