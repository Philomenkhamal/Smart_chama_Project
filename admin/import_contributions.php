<?php
/**
 * ChamaLedger — Historical Contributions Import
 * Upload an Excel/CSV with member contribution history
 * Supports: "one row per member, months as columns" OR "one row per payment"
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Import Contribution History — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();

$pdo  = getDB();
$curr = getSetting('currency', 'KES');

// ── Parse uploaded file ───────────────────────────────────────────────────────
function parseUploadedFile(array $file): array {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        $content = file_get_contents($file['tmp_name']);
        // Detect delimiter
        $delim = (substr_count(explode("\n",$content)[0] ?? '', "\t") > substr_count(explode("\n",$content)[0] ?? '', ",")) ? "\t" : ",";
        $lines = array_filter(explode("\n", str_replace("\r", "", $content)), 'strlen');
        return ['rows' => array_map(fn($l) => str_getcsv($l, $delim), array_values($lines))];
    }
    if (in_array($ext, ['xlsx','xls'])) {
        if (!class_exists('ZipArchive')) {
            return ['error' => '❌ Excel upload requires the PHP zip extension. <strong>Quick fix: save your Excel as CSV</strong> — in Excel go to File → Save As → CSV (Comma delimited) → then upload the .csv file instead. This works exactly the same.'];
        }
        $rows = readXlsx($file['tmp_name']);
        if ($rows !== null) return ['rows' => $rows];
        return ['error' => '❌ Could not read the .xlsx file. Please save as CSV and re-upload (File → Save As → CSV).'];
    }
    return ['error' => "❌ Unsupported file type: .$ext — use .xlsx, .csv"];
}

function readXlsx(string $path): ?array {
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();
    if (!$xml) return null;

    // Parse shared strings
    $shared = [];
    if ($sharedXml) {
        preg_match_all('/<t(?:\s[^>]*)?>([^<]*)<\/t>/', $sharedXml, $m);
        $shared = $m[1];
    }

    // Parse cells
    $rows = [];
    preg_match_all('/<row[^>]*r="(\d+)"[^>]*>(.*?)<\/row>/s', $xml, $rowMatches);
    foreach ($rowMatches[1] as $ri => $rowNum) {
        $rowIdx = (int)$rowNum - 1;
        preg_match_all('/<c r="([A-Z]+)(\d+)"(?:[^>]* t="([^"]*)")?[^>]*>.*?<v>([^<]*)<\/v>.*?<\/c>/s', $rowMatches[2][$ri], $cellMatches);
        foreach ($cellMatches[1] as $ci => $col) {
            $colIdx = 0;
            foreach (str_split($col) as $ch) $colIdx = $colIdx * 26 + (ord($ch) - 64);
            $colIdx--;
            $type = $cellMatches[3][$ci];
            $val  = $cellMatches[4][$ci];
            if ($type === 's') $val = $shared[(int)$val] ?? '';
            elseif ($type === '' && is_numeric($val) && strlen($val) > 4) {
                // Could be a date serial — leave as is, handle later
            }
            while (count($rows) <= $rowIdx) $rows[] = [];
            while (count($rows[$rowIdx]) <= $colIdx) $rows[$rowIdx][] = '';
            $rows[$rowIdx][$colIdx] = html_entity_decode((string)$val, ENT_XML1);
        }
    }
    // Normalize row lengths
    $maxCols = max(array_map('count', $rows) ?: [0]);
    foreach ($rows as &$r) while (count($r) < $maxCols) $r[] = '';
    return $rows;
}

// ── Detect format: wide (months as columns) vs tall (one row per payment) ────
function detectFormat(array $headers): string {
    $hdrs = array_map('strtolower', $headers);
    // Wide format: has month-like columns (jan, feb, january, 2024-01, etc.)
    $monthPatterns = ['/^jan/i','/^feb/i','/^mar/i','/^apr/i','/^may/i','/^jun/i',
                      '/^jul/i','/^aug/i','/^sep/i','/^oct/i','/^nov/i','/^dec/i',
                      '/^\d{4}-\d{2}$/','/^\d{1,2}\/\d{4}$/'];
    foreach ($hdrs as $h) {
        foreach ($monthPatterns as $p) {
            if (preg_match($p, trim($h))) return 'wide';
        }
    }
    // Tall format: has a month/date column and an amount column
    $hasMonth  = array_filter($hdrs, fn($h) => preg_match('/month|date|period/i', $h));
    $hasAmount = array_filter($hdrs, fn($h) => preg_match('/amount|paid|payment|contribution/i', $h));
    if ($hasMonth && $hasAmount) return 'tall';
    return 'wide'; // default guess
}

// ── Parse month string to Y-m-01 ─────────────────────────────────────────────
function parseMonth(string $s): ?string {
    $s = trim($s);
    if (!$s) return null;
    // Already Y-m format
    if (preg_match('/^(\d{4})-(\d{2})$/', $s, $m)) return "$m[1]-$m[2]-01";
    // Excel date serial (numeric)
    if (is_numeric($s) && (int)$s > 40000) {
        $ts = mktime(0,0,0,1,((int)$s - 25569),1970) ?: 0;
        return $ts ? date('Y-m-01', $ts) : null;
    }
    // Month name + year: "January 2024", "Jan 2024", "Jan-2024"
    $s2 = preg_replace('/[-_\/]/', ' ', $s);
    $ts = strtotime("1 $s2");
    if ($ts && date('Y',$ts) > 2000 && date('Y',$ts) < 2100) return date('Y-m-01', $ts);
    $ts = strtotime($s);
    if ($ts && date('Y',$ts) > 2000) return date('Y-m-01', $ts);
    return null;
}

// ── Fuzzy-match member name to DB ─────────────────────────────────────────────
function matchMember(PDO $pdo, string $name): ?array {
    $name = trim($name);
    if (!$name) return null;
    // Exact match first
    $s = $pdo->prepare("SELECT id,full_name,membership_number FROM users WHERE role='member' AND status='active' AND LOWER(full_name)=LOWER(?) LIMIT 1");
    $s->execute([$name]);
    if ($r = $s->fetch()) return $r;
    // Soundex / LIKE match
    $s = $pdo->prepare("SELECT id,full_name,membership_number FROM users WHERE role='member' AND status='active' AND (full_name LIKE ? OR full_name LIKE ?) LIMIT 1");
    $parts = explode(' ', $name);
    $s->execute(['%'.($parts[0] ?? $name).'%', '%'.($parts[count($parts)-1] ?? $name).'%']);
    return $s->fetch() ?: null;
}

// ═══════════════════════════════════════════════════════════════════════════════
// MAIN LOGIC
// ═══════════════════════════════════════════════════════════════════════════════

$step       = $_POST['step'] ?? $_GET['step'] ?? 'upload';
$results    = [];
$errors     = [];
$preview    = [];
$format     = '';
$parseError = '';
$imported   = 0;
$skipped    = 0;

// ── STEP 1: Parse & Preview ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview'
    && verifyCsrf($_POST['csrf_token'] ?? '')) {

    $file = $_FILES['import_file'] ?? null;
    if (!$file || $file['error']) {
        $parseError = '❌ No file received.';
    } else {
        $parsed = parseUploadedFile($file);
        if (isset($parsed['error'])) {
            $parseError = $parsed['error'];
        } else {
            $rows   = $parsed['rows'];
            $hdrs   = $rows[0] ?? [];
            $format = detectFormat($hdrs);

            // Store parsed data in session for confirm step
            $_SESSION['contrib_import'] = [
                'rows'   => $rows,
                'format' => $format,
            ];
            $step = 'preview';

            // Build preview (max 5 rows)
            if ($format === 'wide') {
                // Wide: member name col + month columns
                $hdrMap = array_map('strtolower', $hdrs);
                $nameCol = null;
                foreach ($hdrMap as $i => $h) {
                    if (preg_match('/name|member/i', $h)) { $nameCol = $i; break; }
                }
                if ($nameCol === null) $nameCol = 0;
                // Find month columns
                $monthCols = [];
                foreach ($hdrs as $i => $h) {
                    if ($i === $nameCol) continue;
                    $mo = parseMonth($h);
                    if ($mo) $monthCols[$i] = $mo;
                }
                foreach (array_slice($rows, 1, 5) as $row) {
                    $name = trim($row[$nameCol] ?? '');
                    if (!$name) continue;
                    $mem = matchMember($pdo, $name);
                    $payments = [];
                    foreach ($monthCols as $ci => $mo) {
                        $amt = (float)str_replace([',','KES','ksh','Ksh'], '', $row[$ci] ?? '');
                        if ($amt > 0) $payments[] = ['month' => $mo, 'amount' => $amt];
                    }
                    $preview[] = ['name' => $name, 'matched' => $mem['full_name'] ?? null,
                                  'member_id' => $mem['id'] ?? null, 'payments' => $payments];
                }
                $_SESSION['contrib_import']['name_col']   = $nameCol;
                $_SESSION['contrib_import']['month_cols'] = $monthCols;
            } else {
                // Tall format
                $hdrMap = array_map('strtolower', $hdrs);
                $col = array_flip($hdrMap);
                $nameCol   = $col['name'] ?? $col['member_name'] ?? $col['full_name'] ?? $col['member'] ?? 0;
                $monthCol  = $col['month'] ?? $col['payment_month'] ?? $col['date'] ?? $col['period'] ?? 1;
                $amountCol = $col['amount'] ?? $col['paid'] ?? $col['payment'] ?? $col['contribution'] ?? 2;
                foreach (array_slice($rows, 1, 5) as $row) {
                    $name = trim($row[$nameCol] ?? '');
                    $mo   = parseMonth($row[$monthCol] ?? '');
                    $amt  = (float)str_replace([',','KES','ksh'], '', $row[$amountCol] ?? '');
                    if (!$name || !$mo || !$amt) continue;
                    $mem = matchMember($pdo, $name);
                    $preview[] = ['name' => $name, 'matched' => $mem['full_name'] ?? null,
                                  'member_id' => $mem['id'] ?? null,
                                  'payments' => [['month' => $mo, 'amount' => $amt]]];
                }
                $_SESSION['contrib_import']['name_col']   = $nameCol;
                $_SESSION['contrib_import']['month_col']  = $monthCol;
                $_SESSION['contrib_import']['amount_col'] = $amountCol;
            }
        }
    }
}

// ── STEP 2: Confirm & Import ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import'
    && verifyCsrf($_POST['csrf_token'] ?? '')) {

    $imp = $_SESSION['contrib_import'] ?? null;
    if (!$imp) { $parseError = '❌ Session expired. Please re-upload.'; $step = 'upload'; }
    else {
        $rows      = $imp['rows'];
        $format    = $imp['format'];
        $adminId   = (int)$_SESSION['user_id'];
        $overwrite = !empty($_POST['overwrite']); // replace existing records for same member+month
        $status    = $_POST['import_status'] ?? 'confirmed';
        if (!in_array($status, ['confirmed','pending'])) $status = 'confirmed';

        $pdo->beginTransaction();
        try {
            if ($format === 'wide') {
                $nameCol   = $imp['name_col'];
                $monthCols = $imp['month_cols'];
                foreach (array_slice($rows, 1) as $row) {
                    $name = trim($row[$nameCol] ?? '');
                    if (!$name) continue;
                    $mem = matchMember($pdo, $name);
                    if (!$mem) { $errors[] = "No member found for: $name"; $skipped++; continue; }
                    foreach ($monthCols as $ci => $mo) {
                        $rawAmt = str_replace([',','KES','ksh','Ksh',' '], '', $row[$ci] ?? '');
                        $amt = (float)$rawAmt;
                        if ($amt <= 0) continue;
                        if ($overwrite) {
                            $pdo->prepare("DELETE FROM contributions WHERE user_id=? AND DATE_FORMAT(payment_month,'%Y-%m')=DATE_FORMAT(?,'%Y-%m')")->execute([$mem['id'], $mo]);
                        } else {
                            $exists = $pdo->prepare("SELECT COUNT(*) FROM contributions WHERE user_id=? AND DATE_FORMAT(payment_month,'%Y-%m')=DATE_FORMAT(?,'%Y-%m') AND status='confirmed'");
                            $exists->execute([$mem['id'], $mo]);
                            if ($exists->fetchColumn() > 0) { $skipped++; continue; }
                        }
                        $pdo->prepare("INSERT INTO contributions (user_id,amount,payment_month,payment_method,status,confirmed_by,confirmed_at,notes) VALUES (?,?,?,'cash',?,?,NOW(),'Imported from historical records')")
                            ->execute([$mem['id'], $amt, $mo, $status, $status==='confirmed'?$adminId:null]);
                        $imported++;
                    }
                }
            } else {
                $nameCol   = $imp['name_col'];
                $monthCol  = $imp['month_col'];
                $amountCol = $imp['amount_col'];
                foreach (array_slice($rows, 1) as $row) {
                    $name = trim($row[$nameCol] ?? '');
                    $mo   = parseMonth($row[$monthCol] ?? '');
                    $amt  = (float)str_replace([',','KES','ksh',' '], '', $row[$amountCol] ?? '');
                    if (!$name || !$mo || $amt <= 0) continue;
                    $mem = matchMember($pdo, $name);
                    if (!$mem) { $errors[] = "No member found: $name"; $skipped++; continue; }
                    if ($overwrite) {
                        $pdo->prepare("DELETE FROM contributions WHERE user_id=? AND DATE_FORMAT(payment_month,'%Y-%m')=DATE_FORMAT(?,'%Y-%m')")->execute([$mem['id'], $mo]);
                    } else {
                        $exists = $pdo->prepare("SELECT COUNT(*) FROM contributions WHERE user_id=? AND DATE_FORMAT(payment_month,'%Y-%m')=DATE_FORMAT(?,'%Y-%m') AND status='confirmed'");
                        $exists->execute([$mem['id'], $mo]);
                        if ($exists->fetchColumn() > 0) { $skipped++; continue; }
                    }
                    $pdo->prepare("INSERT INTO contributions (user_id,amount,payment_month,payment_method,status,confirmed_by,confirmed_at,notes) VALUES (?,?,?,'cash',?,?,NOW(),'Imported from historical records')")
                        ->execute([$mem['id'], $amt, $mo, $status, $status==='confirmed'?$adminId:null]);
                    $imported++;
                }
            }
            $pdo->commit();
            unset($_SESSION['contrib_import']);
            // Recalculate wallets
            require_once ROOT . '/includes/wallet.php';
            $allMembers = $pdo->query("SELECT id FROM users WHERE role='member' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($allMembers as $uid) { try { Wallet::recalculate($pdo, $uid); } catch(Exception $e){} }
            $step = 'done';
        } catch (Exception $e) {
            $pdo->rollBack();
            $parseError = '❌ Import failed: ' . $e->getMessage();
            $step = 'upload';
        }
    }
}

require_once ROOT . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h4><i class="bi bi-upload me-2" style="color:var(--green)"></i>Import Contribution History</h4>
        <div class="subtitle">Bulk import historical payment records from Excel or CSV</div>
    </div>
    <a href="<?= APP_URL ?>/admin/contributions.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Contributions
    </a>
</div>

<?php if ($step === 'done'): ?>
<!-- ── SUCCESS ─────────────────────────────────────────────────────────────── -->
<div class="card text-center py-5">
    <div class="card-body">
        <div style="font-size:3.5rem;margin-bottom:1rem">✅</div>
        <h4 class="fw-bold mb-2">Import Complete</h4>
        <p class="text-muted mb-4">Historical contributions have been imported and all member wallets recalculated.</p>
        <div class="d-flex gap-3 justify-content-center flex-wrap mb-4">
            <div class="stat-card green p-3" style="min-width:120px">
                <div class="stat-value"><?= $imported ?></div>
                <div class="stat-label">Records imported</div>
            </div>
            <div class="stat-card" style="min-width:120px">
                <div class="stat-value"><?= $skipped ?></div>
                <div class="stat-label">Skipped (duplicate)</div>
            </div>
            <?php if ($errors): ?>
            <div class="stat-card red p-3" style="min-width:120px">
                <div class="stat-value"><?= count($errors) ?></div>
                <div class="stat-label">Unmatched members</div>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($errors): ?>
        <div class="alert alert-warning text-start" style="max-width:500px;margin:0 auto 1.5rem">
            <strong>Could not match these names:</strong><br>
            <?php foreach (array_slice($errors, 0, 10) as $e) echo htmlspecialchars($e) . '<br>'; ?>
            <?php if (count($errors) > 10) echo '...and ' . (count($errors)-10) . ' more'; ?>
        </div>
        <?php endif; ?>
        <div class="d-flex gap-2 justify-content-center flex-wrap">
            <a href="<?= APP_URL ?>/admin/contribution_report.php" class="btn btn-primary">
                <i class="bi bi-bar-chart me-1"></i>View Contribution Report
            </a>
            <a href="<?= APP_URL ?>/admin/import_contributions.php" class="btn btn-outline-secondary">
                <i class="bi bi-upload me-1"></i>Import Another File
            </a>
        </div>
    </div>
</div>

<?php elseif ($step === 'preview' && !empty($preview)): ?>
<!-- ── PREVIEW ────────────────────────────────────────────────────────────── -->
<div class="alert alert-info d-flex gap-2 mb-4">
    <i class="bi bi-info-circle-fill mt-1"></i>
    <div>
        Detected format: <strong><?= $format === 'wide' ? 'Wide (months as columns)' : 'Tall (one row per payment)' ?></strong>.
        Preview shows first 5 rows. Check that names matched correctly before importing.
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-eye me-2"></i>Preview (first 5 rows)</h6></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Name in file</th>
                        <th>Matched member</th>
                        <th>Payments found</th>
                        <th>Total amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($preview as $p):
                    $total = array_sum(array_column($p['payments'], 'amount'));
                ?>
                <tr>
                    <td><?= htmlspecialchars($p['name']) ?></td>
                    <td>
                        <?php if ($p['matched']): ?>
                            <span class="badge bg-success"><i class="bi bi-check me-1"></i><?= htmlspecialchars($p['matched']) ?></span>
                        <?php else: ?>
                            <span class="badge bg-danger"><i class="bi bi-x me-1"></i>Not found</span>
                        <?php endif; ?>
                    </td>
                    <td><?= count($p['payments']) ?> month<?= count($p['payments'])!==1?'s':'' ?></td>
                    <td><?= $curr ?> <?= number_format($total, 2) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-sliders me-2"></i>Import Options</h6></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="import">
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Payment Status to set</label>
                    <select name="import_status" class="form-select">
                        <option value="confirmed" selected>Confirmed ✅ (treated as paid, affects reports)</option>
                        <option value="pending">Pending ⏳ (needs manual confirmation)</option>
                    </select>
                    <div class="form-text">Set "Confirmed" for past payments you know were made.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">If a record already exists</label>
                    <select name="overwrite" class="form-select">
                        <option value="">Skip (keep existing record)</option>
                        <option value="1">Overwrite (replace with imported data)</option>
                    </select>
                    <div class="form-text">Safe default is Skip — avoids duplicates.</div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-cloud-upload me-2"></i>Confirm & Import All Records
                </button>
                <a href="<?= APP_URL ?>/admin/import_contributions.php" class="btn btn-outline-secondary">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ── UPLOAD FORM ─────────────────────────────────────────────────────────── -->

<?php if ($parseError): ?>
<div class="alert alert-danger mb-4"><?= $parseError ?></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Upload card -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Upload File</h6></div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="preview">

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Select Excel or CSV file</label>
                        <input type="file" name="import_file" class="form-control" accept=".csv,.xlsx,.xls" required>
                        <div class="form-text">Max 10MB. Accepts .csv (recommended) or .xlsx</div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-eye me-2"></i>Preview Import
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Instructions -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>File Format Guide</h6></div>
            <div class="card-body small">
                <p class="fw-semibold mb-2">Option A — Wide format (recommended)</p>
                <p class="text-muted mb-3">One row per member, one column per month:</p>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered" style="font-size:.7rem">
                        <thead class="table-dark">
                            <tr><th>Name</th><th>Jan 2024</th><th>Feb 2024</th><th>Mar 2024</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>John Doe</td><td>2000</td><td>2000</td><td>0</td></tr>
                            <tr><td>Jane Smith</td><td>2000</td><td>2000</td><td>2000</td></tr>
                        </tbody>
                    </table>
                </div>

                <p class="fw-semibold mb-2">Option B — Tall format</p>
                <p class="text-muted mb-3">One row per payment:</p>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered" style="font-size:.7rem">
                        <thead class="table-dark">
                            <tr><th>name</th><th>month</th><th>amount</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>John Doe</td><td>2024-01</td><td>2000</td></tr>
                            <tr><td>John Doe</td><td>2024-02</td><td>2000</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="alert alert-warning py-2 px-3 small mb-0">
                    <strong>Important:</strong> Member names in the file must match names already in the system. Add members first via <a href="<?= APP_URL ?>/admin/import_members.php">Import Members</a> if needed.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Download template -->
<div class="card mt-4">
    <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <div class="fw-semibold">Not sure about the format?</div>
            <div class="text-muted small">Download a template pre-filled with your current members</div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= APP_URL ?>/admin/import_contributions.php?download=wide" class="btn btn-outline-success btn-sm">
                <i class="bi bi-download me-1"></i>Wide Template
            </a>
            <a href="<?= APP_URL ?>/admin/import_contributions.php?download=tall" class="btn btn-outline-success btn-sm">
                <i class="bi bi-download me-1"></i>Tall Template
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// ── Template downloads ────────────────────────────────────────────────────────
if (isset($_GET['download']) && in_array($_GET['download'], ['wide','tall'])) {
    $members = $pdo->query("SELECT full_name FROM users WHERE role='member' AND status='active' ORDER BY full_name")->fetchAll(PDO::FETCH_COLUMN);
    $months  = [];
    for ($m = 1; $m <= 12; $m++) $months[] = date('M Y', mktime(0,0,0,$m,1,date('Y')));

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    // Whitelist to prevent header injection — only 'wide' or 'tall' allowed
    $type = ($_GET['download'] === 'wide') ? 'wide' : 'tall';
    header("Content-Disposition: attachment; filename=\"contribution_template_{$type}_" . date('Y') . ".csv\"");

    $out = fopen('php://output', 'w');
    if ($type === 'wide') {
        fputcsv($out, array_merge(['full_name'], $months));
        foreach ($members as $name) fputcsv($out, array_merge([$name], array_fill(0, 12, '')));
    } else {
        fputcsv($out, ['name','month','amount','notes']);
        foreach ($members as $name) fputcsv($out, [$name, date('Y-m'), '', '']);
    }
    fclose($out);
    exit;
}

require_once ROOT . '/includes/footer.php';
?>
