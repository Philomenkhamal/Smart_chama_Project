<?php
/**
 * ChamaLedger — Export Reports
 * GET /admin/export.php?type=contributions|loans|members|summary&format=pdf|excel&year=2025&month=0
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
requireAdmin();

$pdo       = getDB();
$type      = sanitize($_GET['type']   ?? 'contributions');
$format    = sanitize($_GET['format'] ?? 'excel');
$year      = (int)($_GET['year']      ?? date('Y'));
$month     = (int)($_GET['month']     ?? 0);
$curr      = getSetting('currency', 'KES');
$groupName = getSetting('group_name', 'ChamaLedger');
$now       = date('d M Y H:i');
$dateLabel = $month ? date('F Y', mktime(0,0,0,$month,1,$year)) : "Year $year";

// ── Fetch data based on type ───────────────────────────────────────────────────
$dateWhere = $month
    ? "YEAR(c.payment_month)=$year AND MONTH(c.payment_month)=$month"
    : "YEAR(c.payment_month)=$year";

switch ($type) {
    case 'contributions':
        $rows = $pdo->query("
            SELECT u.full_name, u.phone, u.membership_number,
                   DATE_FORMAT(c.payment_month,'%M %Y') AS month,
                   c.amount, c.payment_method, c.reference_code,
                   c.status, DATE_FORMAT(c.recorded_at,'%d %b %Y') AS date
            FROM contributions c
            JOIN users u ON c.user_id = u.id
            WHERE $dateWhere
            ORDER BY c.payment_month DESC, u.full_name
        ")->fetchAll();
        $title   = "Contributions Report — $dateLabel";
        $headers = ['Member','Phone','Membership No.','Month','Amount','Method','Reference','Status','Date'];
        $cols    = ['full_name','phone','membership_number','month','amount','payment_method','reference_code','status','date'];
        break;

    case 'loans':
        $rows = $pdo->query("
            SELECT u.full_name, u.phone,
                   l.amount_requested, l.amount_approved,
                   l.balance, l.interest_rate,
                   l.status, DATE_FORMAT(l.applied_at,'%d %b %Y') AS applied,
                   DATE_FORMAT(l.disbursed_at,'%d %b %Y') AS disbursed
            FROM loans l
            JOIN users u ON l.user_id = u.id
            WHERE YEAR(l.applied_at)=$year
            ORDER BY l.applied_at DESC
        ")->fetchAll();
        $title   = "Loans Report — $year";
        $headers = ['Member','Phone','Requested','Approved','Balance','Interest %','Status','Applied','Disbursed'];
        $cols    = ['full_name','phone','amount_requested','amount_approved','balance','interest_rate','status','applied','disbursed'];
        break;

    case 'members':
        $rows = $pdo->query("
            SELECT full_name, email, phone, national_id, membership_number,
                   role, status, occupation,
                   DATE_FORMAT(created_at,'%d %b %Y') AS joined
            FROM users ORDER BY full_name
        ")->fetchAll();
        $title   = "Members Report — $groupName";
        $headers = ['Full Name','Email','Phone','National ID','Membership No.','Role','Status','Occupation','Joined'];
        $cols    = ['full_name','email','phone','national_id','membership_number','role','status','occupation','joined'];
        break;

    case 'summary':
    default:
        // Financial summary
        $totalSavings = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE status='confirmed' AND YEAR(payment_month)=$year" . ($month ? " AND MONTH(payment_month)=$month" : ''))->fetchColumn();
        $totalExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE YEAR(expense_date)=$year" . ($month ? " AND MONTH(expense_date)=$month" : ''))->fetchColumn();
        $loansOut = (float)$pdo->query("SELECT COALESCE(SUM(amount_approved),0) FROM loans WHERE status IN ('disbursed','completed') AND YEAR(applied_at)=$year")->fetchColumn();
        $repaid = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM loan_payments WHERE status='confirmed' AND YEAR(paid_at)=$year")->fetchColumn();
        $activeMembers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='active' AND role='member'")->fetchColumn();
        $rows = [
            ['metric'=>'Total Contributions Collected','value'=>number_format($totalSavings,2).' '.$curr],
            ['metric'=>'Total Expenses','value'=>number_format($totalExpenses,2).' '.$curr],
            ['metric'=>'Net Savings','value'=>number_format($totalSavings-$totalExpenses,2).' '.$curr],
            ['metric'=>'Loans Disbursed','value'=>number_format($loansOut,2).' '.$curr],
            ['metric'=>'Loan Repayments Received','value'=>number_format($repaid,2).' '.$curr],
            ['metric'=>'Active Members','value'=>$activeMembers],
        ];
        $title   = "Financial Summary — $dateLabel";
        $headers = ['Metric','Value'];
        $cols    = ['metric','value'];
        break;
}

// ── EXCEL (CSV with BOM) ───────────────────────────────────────────────────────
if ($format === 'excel') {
    // Clean output buffer so no stray HTML leaks into the download
    while (ob_get_level()) ob_end_clean();
    $filename = strtolower($type) . '_' . $year . ($month ? '_' . str_pad($month,2,'0',STR_PAD_LEFT) : '') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens correctly
    fwrite($out, "\xEF\xBB\xBF");

    // Title rows
    fputcsv($out, [$groupName]);
    fputcsv($out, [$title]);
    fputcsv($out, ['Generated: ' . $now]);
    fputcsv($out, []);

    // Headers
    fputcsv($out, $headers);

    // Data
    foreach ($rows as $row) {
        $line = [];
        foreach ($cols as $col) {
            $val = $row[$col] ?? '';
            // Format amounts
            if (in_array($col, ['amount','amount_requested','amount_approved','balance']) && is_numeric($val)) {
                $val = number_format((float)$val, 2);
            }
            $line[] = $val;
        }
        fputcsv($out, $line);
    }

    // Totals for contributions
    if ($type === 'contributions') {
        $total = array_sum(array_column($rows, 'amount'));
        fputcsv($out, []);
        fputcsv($out, ['', '', '', 'TOTAL', number_format($total, 2)]);
    }

    fclose($out);
    exit;
}

// ── PDF (pure PHP, no library needed) ─────────────────────────────────────────
if ($format === 'pdf') {
    while (ob_get_level()) ob_end_clean();
    $filename = strtolower($type) . '_' . $year . ($month ? '_' . str_pad($month,2,'0',STR_PAD_LEFT) : '') . '.html';

    // Build HTML that looks like PDF when printed
    $headerRow = '<tr>' . implode('', array_map(fn($h) => "<th>$h</th>", $headers)) . '</tr>';
    $dataRows  = '';
    foreach ($rows as $i => $row) {
        $cells = '';
        foreach ($cols as $col) {
            $val = htmlspecialchars($row[$col] ?? '');
            if (in_array($col, ['amount','amount_requested','amount_approved','balance']) && is_numeric($row[$col] ?? '')) {
                $val = '<strong>' . number_format((float)$row[$col], 2) . ' ' . $curr . '</strong>';
            }
            if ($col === 'status') {
                $colors = ['confirmed'=>'#00c471','pending'=>'#f59e0b','rejected'=>'#ef4444','approved'=>'#3b82f6','active'=>'#00c471','suspended'=>'#ef4444'];
                $c = $colors[$row[$col]] ?? '#6b87a8';
                $val = "<span style='background:". $c ."22;color:$c;padding:.15rem .5rem;border-radius:99px;font-size:.75rem;font-weight:600'>$val</span>";
            }
            $cells .= "<td>$val</td>";
        }
        $bg = $i % 2 === 0 ? '#ffffff' : '#f8fafc';
        $dataRows .= "<tr style='background:$bg'>$cells</tr>";
    }

    $totalRow = '';
    if ($type === 'contributions' && !empty($rows)) {
        $total = array_sum(array_column($rows, 'amount'));
        $totalRow = "<tr style='background:#f0fdf4;font-weight:700'><td colspan='4' style='text-align:right;padding-right:1rem'>TOTAL</td><td>" . number_format($total,2) . " $curr</td><td colspan='4'></td></tr>";
    }

    header('Content-Type: text/html; charset=UTF-8');
    echo <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>{$title}</title>
<style>
  @page { margin: 1.5cm; }
  @media print { .no-print { display:none; } body { margin:0; } }
  * { box-sizing:border-box; }
  body { font-family: Arial, sans-serif; font-size: 11px; color: #1a2940; margin:0; padding:1.5rem; }
  .header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem; border-bottom:3px solid #00c471; padding-bottom:1rem; }
  .logo { font-size:1.4rem; font-weight:800; color:#060e1a; }
  .logo span { color:#00c471; }
  .meta { text-align:right; font-size:10px; color:#6b87a8; }
  h1 { font-size:1.1rem; margin:0 0 .25rem; color:#060e1a; }
  table { width:100%; border-collapse:collapse; margin-top:1rem; font-size:10.5px; }
  th { background:#060e1a; color:#ffffff; padding:.5rem .6rem; text-align:left; font-size:9.5px; text-transform:uppercase; letter-spacing:.05em; }
  td { padding:.45rem .6rem; border-bottom:1px solid #e5e7eb; }
  .footer { margin-top:2rem; padding-top:.75rem; border-top:1px solid #e5e7eb; font-size:9px; color:#9ca3af; display:flex; justify-content:space-between; }
  .btn-print { background:#00c471; color:#060e1a; border:none; padding:.6rem 1.5rem; border-radius:8px; font-weight:700; cursor:pointer; font-size:.9rem; margin-bottom:1rem; }
</style>
</head><body>
<div class="no-print" style="margin-bottom:1rem">
  <button class="btn-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
  <button onclick="window.close()" style="margin-left:.5rem;padding:.6rem 1rem;border:1px solid #ddd;background:#fff;border-radius:8px;cursor:pointer">✕ Close</button>
  <span style="margin-left:1rem;font-size:.8rem;color:#6b87a8">Tip: In the print dialog choose <strong>Save as PDF</strong> as the destination</span>
</div>
<div class="header">
  <div>
    <div class="logo">Chama<span>Ledger</span></div>
    <h1>{$title}</h1>
    <div style="font-size:10px;color:#6b87a8">{$groupName}</div>
  </div>
  <div class="meta">
    Generated: {$now}<br>
    Period: {$dateLabel}<br>
    Total Records: ' . count($rows) . '
  </div>
</div>
<table>
  <thead>{$headerRow}</thead>
  <tbody>{$dataRows}{$totalRow}</tbody>
</table>
<div class="footer">
  <span>{$groupName} · Confidential</span>
  <span>ChamaLedger · {$now}</span>
</div>
<script>
// Auto-open print dialog after a short delay so the page renders first
window.addEventListener('load', function() {
    setTimeout(function() {
        // Only auto-print if ?autoprint=1 in URL
        if (window.location.search.indexOf('autoprint=1') !== -1) {
            window.print();
        }
    }, 600);
});
</script>
</body></html>
HTML;
    exit;
}
