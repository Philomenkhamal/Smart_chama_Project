<?php
/**
 * ChamaLedger — Member Statement Download
 * GET /member/export_statement.php?format=csv
 * Members download their own statement. Admins can pass ?member_id=X
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
requireLogin();
startSession();

$pdo       = getDB();
$userId    = (int)$_SESSION['user_id'];
$role      = $_SESSION['user_role'] ?? 'member';
$curr      = getSetting('currency', 'KES');
$groupName = getSetting('group_name', 'ChamaLedger');

// Admin can download any member's statement
$targetId = $userId;
if ($role === 'admin' && !empty($_GET['member_id'])) {
    $targetId = (int)$_GET['member_id'];
}

$user = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
$user->execute([$targetId]);
$user = $user->fetch();
if (!$user) { http_response_code(404); exit('Member not found'); }

$now  = date('Y-m-d H:i');
$name = $user['full_name'];
$mem  = $user['membership_number'] ?? 'N/A';

// ── Contributions ─────────────────────────────────────────────────────────────
$contribs = $pdo->prepare("
    SELECT DATE_FORMAT(payment_month,'%M %Y') AS month,
           amount, payment_method, reference_code,
           status, DATE_FORMAT(recorded_at,'%d %b %Y') AS date
    FROM contributions
    WHERE user_id = ?
    ORDER BY payment_month DESC
");
$contribs->execute([$targetId]);
$contribs = $contribs->fetchAll();

// ── Loans ─────────────────────────────────────────────────────────────────────
$loans = $pdo->prepare("
    SELECT amount_requested, amount_approved, balance, interest_rate,
           status, DATE_FORMAT(applied_at,'%d %b %Y') AS applied,
           DATE_FORMAT(disbursed_at,'%d %b %Y') AS disbursed
    FROM loans WHERE user_id = ?
    ORDER BY applied_at DESC
");
$loans->execute([$targetId]);
$loans = $loans->fetchAll();

// ── Fines ─────────────────────────────────────────────────────────────────────
$fines = $pdo->prepare("
    SELECT fine_type, amount, status,
           DATE_FORMAT(created_at,'%d %b %Y') AS date
    FROM member_fines WHERE user_id = ?
    ORDER BY created_at DESC
");
$fines->execute([$targetId]);
$fines = $fines->fetchAll();

// ── Summary totals ────────────────────────────────────────────────────────────
$stSaved = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE user_id=? AND status='confirmed'");
$stSaved->execute([$targetId]);
$totalSaved = (float)$stSaved->fetchColumn();

$stFines = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM member_fines WHERE user_id=? AND status='unpaid'");
$stFines->execute([$targetId]);
$totalFines = (float)$stFines->fetchColumn();

// ── Output CSV ────────────────────────────────────────────────────────────────
$filename = 'statement_' . strtolower(str_replace(' ', '_', $name)) . '_' . date('Y-m-d') . '.csv';

while (ob_get_level()) ob_end_clean();
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

$out = fopen('php://output', 'w');

// UTF-8 BOM for Excel
fwrite($out, "\xEF\xBB\xBF");

// ── HEADER ────────────────────────────────────────────────────────────────────
fputcsv($out, [$groupName . ' — Member Statement']);
fputcsv($out, ['Member:', $name, 'Membership No:', $mem]);
fputcsv($out, ['Phone:', $user['phone'] ?? '', 'Joined:', $user['joined_date'] ?? '']);
fputcsv($out, ['Generated:', $now, '', '']);
fputcsv($out, []);

// ── SUMMARY ───────────────────────────────────────────────────────────────────
fputcsv($out, ['=== SUMMARY ===']);
fputcsv($out, ['Total Contributions (confirmed)', number_format($totalSaved, 2) . ' ' . $curr]);
fputcsv($out, ['Outstanding Fines', number_format($totalFines, 2) . ' ' . $curr]);
fputcsv($out, ['Total Loans', count($loans)]);
fputcsv($out, []);

// ── CONTRIBUTIONS ─────────────────────────────────────────────────────────────
fputcsv($out, ['=== CONTRIBUTION HISTORY ===']);
fputcsv($out, ['Month', 'Amount (' . $curr . ')', 'Method', 'Reference', 'Status', 'Date Recorded']);
$cTotal = 0;
foreach ($contribs as $r) {
    fputcsv($out, [
        $r['month'],
        number_format((float)$r['amount'], 2),
        ucfirst($r['payment_method'] ?? ''),
        $r['reference_code'] ?? '',
        strtoupper($r['status']),
        $r['date']
    ]);
    if ($r['status'] === 'confirmed') $cTotal += (float)$r['amount'];
}
fputcsv($out, ['', 'TOTAL CONFIRMED:', number_format($cTotal, 2) . ' ' . $curr]);
fputcsv($out, []);

// ── LOANS ─────────────────────────────────────────────────────────────────────
if ($loans) {
    fputcsv($out, ['=== LOAN HISTORY ===']);
    fputcsv($out, ['Requested', 'Approved', 'Balance', 'Interest %', 'Status', 'Applied', 'Disbursed']);
    foreach ($loans as $r) {
        fputcsv($out, [
            number_format((float)($r['amount_requested'] ?? 0), 2),
            number_format((float)($r['amount_approved']  ?? 0), 2),
            number_format((float)($r['balance']          ?? 0), 2),
            $r['interest_rate'] . '%',
            strtoupper($r['status']),
            $r['applied']   ?? '',
            $r['disbursed'] ?? '-'
        ]);
    }
    fputcsv($out, []);
}

// ── FINES ─────────────────────────────────────────────────────────────────────
if ($fines) {
    fputcsv($out, ['=== FINES ===']);
    fputcsv($out, ['Type', 'Amount (' . $curr . ')', 'Status', 'Date']);
    foreach ($fines as $r) {
        fputcsv($out, [
            ucwords(str_replace('_', ' ', $r['fine_type'])),
            number_format((float)$r['amount'], 2),
            strtoupper($r['status']),
            $r['date']
        ]);
    }
    fputcsv($out, []);
}

fputcsv($out, ['--- End of Statement ---']);
fclose($out);
exit;
