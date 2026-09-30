<?php
/**
 * ChamaLedger — Bulk Member Import
 * Flow: Upload file → validate → import immediately (no extra confirm step)
 *       OR: Type manually → submit → import immediately
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Import Members — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireAdmin();

// ── AJAX: next membership number for "Add Row" ─────────────────────────────
if (isset($_GET['get_next_mem_no'])) {
    header('Content-Type: application/json');
    echo json_encode(['mem_no' => generateMembershipNumber()]);
    exit;
}

$pdo = getDB();

// ── CSV template download ───────────────────────────────────────────────────
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    while (ob_get_level()) ob_end_clean();
    header('Content-Disposition: attachment; filename="chama_members_template.csv"');
    $f = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens it correctly
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['full_name', 'phone', 'email', 'nickname', 'chama_position', 'joined_date']);
    fputcsv($f, ['Alex Mwangi',     '0722623774', 'alex@email.com',  'Kangaroo', 'CHAIRMAN',  '2024-01-01']);
    fputcsv($f, ['Jane Njeri',      '0711234567', 'jane@email.com',  'JJ',       'SECRETARY', '2024-01-01']);
    fputcsv($f, ["David Ng'ang'a",  '0733456789', '',                '',         'TREASURER', '2024-01-01']);
    fclose($f);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════

/** Clean a person's name for DB storage (no htmlspecialchars — that corrupts apostrophes) */
function cleanName(string $raw): string {
    // Fix curly/smart apostrophes from Word/phone keyboards
    $raw = str_replace(["\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}", "\u{2032}", "`"], "'", $raw);
    // Strip HTML tags, trim whitespace
    $raw = strip_tags(trim($raw));
    // Remove control characters but keep accented Latin letters and apostrophe
    $raw = preg_replace('/[^\x20-\x7E\xC0-\xFF\']/u', '', $raw);
    return trim($raw);
}

/** Normalise phone to 254XXXXXXXXX format */
function normalisePhone(string $raw, int $rowNum = 0): string {
    $p = preg_replace('/\D/', '', $raw);
    if ($p === '')                       return '2540000' . str_pad($rowNum, 6, '0', STR_PAD_LEFT);
    if (strlen($p) === 9)                return '254' . $p;
    if (str_starts_with($p, '0'))        return '254' . substr($p, 1);
    if (str_starts_with($p, '+254'))     return substr($p, 1);
    if (!str_starts_with($p, '254'))     return '254' . $p;
    return $p;
}

/** Normalise date to Y-m-d; falls back to first of current month */
function normaliseDate(string $raw): string {
    if (empty(trim($raw))) return date('Y-m-01');
    $ts = strtotime(str_replace('/', '-', trim($raw)));
    if ($ts && $ts < strtotime('+2 years')) return date('Y-m-d', $ts);
    return date('Y-m-01');
}

/** Return a unique email: use supplied if valid & not taken, else auto-generate */
function resolveEmail(PDO $pdo, string $raw, string $name, string $phone, int $rowNum): array {
    $e = strtolower(trim($raw));
    $note = '';

    if (!empty($e) && str_contains($e, '@')) {
        // Check if taken
        $chk = $pdo->prepare("SELECT id, phone FROM users WHERE email=? LIMIT 1");
        $chk->execute([$e]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            return [$e, '']; // clean — use it
        }
        // Taken by same person? (matching phone last 6 digits)
        $last6 = substr(preg_replace('/\D/', '', $phone), -6);
        if ($last6 && str_ends_with(preg_replace('/\D/', '', $existing['phone'] ?? ''), $last6)) {
            return [null, 'DUPLICATE_PERSON']; // same person already in system
        }
        // Taken by someone else — auto-generate
        $note = "⚠️ Email <em>" . htmlspecialchars($e) . "</em> already taken — auto-assigned";
    } else {
        $note = "ℹ️ No email provided — auto-assigned";
    }

    // Auto-generate from name + phone
    // Convert accented chars to ASCII, fallback to preg if intl not available
    $asciiName = function_exists('transliterator_transliterate') ? (transliterator_transliterate('Any-Latin; Latin-ASCII', $name) ?: $name) : iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name);
    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '.', $asciiName ?: $name));
    $slug = trim($slug, '.') ?: 'member';
    $seed = substr(preg_replace('/\D/', '', $phone) ?: (string)$rowNum, -6);
    $base = $slug . '.' . $seed . '@member.local';

    // Ensure uniqueness
    $try = $base; $n = 1;
    $chk2 = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email=?");
    $chk2->execute([$try]);
    while ((int)$chk2->fetchColumn() > 0) {
        $try = $slug . '.' . $seed . '.' . $n . '@member.local';
        $chk2->execute([$try]);
        $n++;
    }
    return [$try, $note];
}

/** Insert one member. Returns ['ok'=>true,'mem_no'=>...,'note'=>...] or ['error'=>...] */
function insertMember(PDO $pdo, string $name, string $phone, string $email,
                      string $nick, string $pos, string $jDate, int $rowNum): array
{
    $hash  = password_hash('Member@1234', PASSWORD_BCRYPT, ['cost' => 12]);
    $memNo = generateMembershipNumber();

    // Retry up to 3× on membership_number collision
    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            $pdo->prepare("
                INSERT INTO users
                    (full_name, email, phone, nickname, chama_position,
                     membership_number, joined_date, password_hash, role, status, email_verified, created_at)
                VALUES (?,?,?,?,?, ?,?,?,'member','active',1,NOW())
            ")->execute([$name, $email, $phone, $nick, $pos, $memNo, $jDate, $hash]);

            $uid = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT IGNORE INTO member_wallet (user_id,balance) VALUES (?,0)")->execute([$uid]);
            logActivity('MEMBER_IMPORTED', "Row $rowNum: $name ($memNo)", $uid);
            return ['ok' => true, 'mem_no' => $memNo];

        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'membership_number')) {
                $memNo = generateMembershipNumber(); // retry with new number
            } elseif (str_contains($msg, 'phone') && str_contains($msg, 'Duplicate')) {
                $phone .= str_pad($rowNum, 2, '0', STR_PAD_LEFT); // make unique
            } else {
                return ['error' => "DB error: " . substr($msg, 0, 80)];
            }
        }
    }
    return ['error' => "Could not insert after 3 attempts — add manually"];
}

// ═══════════════════════════════════════════════════════════════════════════
// READ FILE HELPERS
// ═══════════════════════════════════════════════════════════════════════════

function readXlsx(string $path): array {
    if (!class_exists('ZipArchive')) return ['__zip_disabled__' => true];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];
    $shared = [];
    if ($ss = $zip->getFromName('xl/sharedStrings.xml')) {
        $xml = simplexml_load_string($ss);
        foreach ($xml->si as $si) {
            $t = '';
            foreach ($si->xpath('.//t') as $node) $t .= (string)$node;
            $shared[] = $t;
        }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheetXml) return [];
    $rows = [];
    $sheet = simplexml_load_string($sheetXml);
    foreach ($sheet->sheetData->row ?? [] as $row) {
        $rd = []; $prev = -1;
        foreach ($row->c as $cell) {
            preg_match('/^([A-Z]+)/', (string)$cell['r'], $m);
            $col = 0;
            foreach (str_split($m[1]) as $ch) $col = $col * 26 + ord($ch) - 64;
            $col--;
            while (++$prev < $col) $rd[] = '';
            $v = (string)($cell->v ?? '');
            if ((string)$cell['t'] === 's') $v = $shared[(int)$v] ?? '';
            $rd[] = $v; $prev = $col;
        }
        $rows[] = $rd;
    }
    return $rows;
}

function parseFile(array $file): array {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($file['error'] !== UPLOAD_ERR_OK)
        return ['error' => '❌ Upload failed (error code ' . $file['error'] . '). Check PHP upload_max_filesize in php.ini.'];
    if ($ext === 'pdf')
        return ['error' => '❌ PDF files cannot be imported. Use Excel (.xlsx) or CSV.'];
    if ($ext === 'xls')
        return ['error' => '❌ Old .xls format not supported. Save as .xlsx and try again.'];
    if ($ext === 'xlsx') {
        $rows = readXlsx($file['tmp_name']);
        if (isset($rows['__zip_disabled__']))
            return ['error' => '❌ Excel upload requires the PHP zip extension. Enable it in php.ini or use CSV.'];
        if (empty($rows))
            return ['error' => '❌ Could not read Excel file. Make sure it is a valid .xlsx (not renamed from .xls).'];
        return ['rows' => $rows];
    }
    if ($ext === 'csv') {
        $raw = file_get_contents($file['tmp_name']);
        if ($raw === false) return ['error' => '❌ Could not read the uploaded file.'];
        // Strip BOM
        if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
        // Convert encoding
        if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
        if (count($lines) < 2) return ['error' => '❌ The CSV file is empty or has only headers.'];
        return ['rows' => array_map('str_getcsv', $lines)];
    }
    return ['error' => "❌ Unsupported file type .$ext — use .xlsx or .csv."];
}

function extractMembers(array $rawRows): array {
    if (count($rawRows) < 2) return [];
    // Build header map
    $hdr = array_map(fn($h) => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $h))), $rawRows[0]);
    $col = array_flip($hdr);
    $get = function(array $row, array $keys) use ($col): string {
        foreach ($keys as $k) {
            if (isset($col[$k], $row[$col[$k]])) {
                $v = trim($row[$col[$k]]);
                if ($v !== '') return $v;
            }
        }
        return '';
    };
    $out = [];
    foreach (array_slice($rawRows, 1) as $i => $row) {
        $name = cleanName($get($row, ['full_name','name','full name','fullname','member_name']));
        if ($name === '') continue;
        $out[] = [
            'row'   => $i + 2,
            'name'  => $name,
            'phone' => $get($row, ['phone','mobile','phone_number','tel','telephone','contact']),
            'email' => $get($row, ['email','email_address','e_mail','e mail']),
            'nick'  => $get($row, ['nickname','nick','alias']),
            'pos'   => $get($row, ['chama_position','position','role','title']),
            'jdate' => $get($row, ['joined_date','join_date','date_joined','date']),
        ];
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════════════════════
// MAIN PROCESSING
// ═══════════════════════════════════════════════════════════════════════════

$importDone    = false;
$importAdded   = 0;
$importSkipped = 0;
$importResults = [];   // ['name','mem_no','email','note']
$importSkips   = [];   // ['row','name','reason']
$activeTab     = 'tab-file';
$fileError     = '';
$validationErrors = []; // blocking issues before import

// ── POST: File upload ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && verifyCsrf($_POST['csrf_token'] ?? '')
    && ($_POST['action'] ?? '') === 'file_import'
) {
    $activeTab = 'tab-file';
    $file = $_FILES['import_file'] ?? null;

    if (!$file) {
        $fileError = '❌ No file received. Make sure you selected a file.';
    } else {
        $parsed = parseFile($file);
        if (isset($parsed['error'])) {
            $fileError = $parsed['error'];
        } else {
            $members = extractMembers($parsed['rows']);
            if (empty($members)) {
                $fileError = '❌ No data rows found. Make sure your file has a header row and at least one member row. Column "full_name" is required.';
            } else {
                // Run import immediately — no extra confirmation step
                foreach ($members as $m) {
                    [$email, $emailNote] = resolveEmail($pdo, $m['email'], $m['name'], $m['phone'], $m['row']);

                    if ($email === null) {
                        // Same person already in system
                        $importSkipped++;
                        $importSkips[] = ['row' => $m['row'], 'name' => $m['name'], 'reason' => 'Already in system (matching phone)'];
                        continue;
                    }

                    $phone = normalisePhone($m['phone'], $m['row']);
                    $jdate = normaliseDate($m['jdate']);
                    $nick  = cleanName($m['nick']);
                    $pos   = cleanInput($m['pos']);

                    $result = insertMember($pdo, $m['name'], $phone, $email, $nick, $pos, $jdate, $m['row']);
                    if (isset($result['ok'])) {
                        $importAdded++;
                        $importResults[] = [
                            'name'   => $m['name'],
                            'mem_no' => $result['mem_no'],
                            'email'  => $email,
                            'phone'  => $phone,
                            'note'   => $emailNote,
                        ];
                    } else {
                        $importSkipped++;
                        $importSkips[] = ['row' => $m['row'], 'name' => $m['name'], 'reason' => $result['error']];
                    }
                }
                $importDone = true;
            }
        }
    }
}

// ── POST: Manual entry ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && verifyCsrf($_POST['csrf_token'] ?? '')
    && ($_POST['action'] ?? '') === 'bulk_add'
) {
    $activeTab = 'tab-manual';
    $names     = $_POST['full_name']      ?? [];
    $phones    = $_POST['phone']          ?? [];
    $emails    = $_POST['email']          ?? [];
    $nicks     = $_POST['nickname']       ?? [];
    $positions = $_POST['chama_position'] ?? [];
    $joined    = $_POST['joined_date']    ?? [];

    foreach ($names as $i => $rawName) {
        $name = cleanName($rawName);
        if ($name === '') continue;

        $rowNum = $i + 1;
        [$email, $emailNote] = resolveEmail($pdo, $emails[$i] ?? '', $name, $phones[$i] ?? '', $rowNum);

        if ($email === null) {
            $importSkipped++;
            $importSkips[] = ['row' => $rowNum, 'name' => $name, 'reason' => 'Already in system'];
            continue;
        }

        $phone = normalisePhone($phones[$i] ?? '', $rowNum);
        $jdate = normaliseDate($joined[$i] ?? '');
        $nick  = cleanName($nicks[$i] ?? '');
        $pos   = cleanInput($positions[$i] ?? '');

        $result = insertMember($pdo, $name, $phone, $email, $nick, $pos, $jdate, $rowNum);
        if (isset($result['ok'])) {
            $importAdded++;
            $importResults[] = [
                'name'   => $name,
                'mem_no' => $result['mem_no'],
                'email'  => $email,
                'phone'  => $phone,
                'note'   => $emailNote,
            ];
        } else {
            $importSkipped++;
            $importSkips[] = ['row' => $rowNum, 'name' => $name, 'reason' => $result['error']];
        }
    }
    $importDone = true;
}

$memberCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='member'")->fetchColumn();
require_once ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-people me-2 text-primary"></i>Add Members</h4>
        <small class="text-muted">Currently <strong><?= $memberCount ?></strong> member(s) in system.</small>
    </div>
    <a href="<?= APP_URL ?>/admin/members.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Members
    </a>
</div>

<?= getFlash() ?>

<?php if (!class_exists('ZipArchive')): ?>
<div class="alert alert-warning py-2 small mb-3">
    <strong><i class="bi bi-exclamation-triangle me-1"></i>Excel (.xlsx) disabled</strong> — PHP zip extension not enabled on this server.<br>
    <strong>Fix:</strong> open <code>C:\xampp\php\php.ini</code> → find <code>;extension=zip</code> → remove the <code>;</code> → restart Apache.<br>
    Use <strong>CSV</strong> format until then.
</div>
<?php endif; ?>

<?php // ─── FILE ERROR ──────────────────────────────────────────────────────────
if ($fileError): ?>
<div class="alert alert-danger d-flex gap-2 align-items-start mb-4">
    <i class="bi bi-x-circle-fill fs-5 flex-shrink-0 mt-1"></i>
    <div><?= $fileError ?></div>
</div>
<?php endif; ?>

<?php // ─── IMPORT RESULTS ──────────────────────────────────────────────────────
if ($importDone): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-card d-flex align-items-center gap-3 py-3">
        <?php if ($importAdded > 0): ?>
        <i class="bi bi-check-circle-fill text-success fs-4 flex-shrink-0"></i>
        <div class="flex-grow-1">
            <strong class="d-block text-success"><?= $importAdded ?> member(s) added successfully</strong>
            <?php if ($importSkipped): ?><small class="text-muted"><?= $importSkipped ?> skipped — see below.</small><?php endif; ?>
        </div>
        <span class="badge bg-success"><?= $importAdded ?> added</span>
        <?php else: ?>
        <i class="bi bi-exclamation-triangle-fill text-warning fs-4 flex-shrink-0"></i>
        <div class="flex-grow-1"><strong>No new members were added.</strong></div>
        <?php endif; ?>
    </div>

    <?php if (!empty($importResults)): ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">#</th>
                    <th>Name</th>
                    <th>Membership No</th>
                    <th>Login Email</th>
                    <th>Phone</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($importResults as $i => $r): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                    <td class="fw-semibold small"><?= htmlspecialchars($r['name']) ?></td>
                    <td><code class="text-success small"><?= htmlspecialchars($r['mem_no']) ?></code></td>
                    <td class="small text-muted"><?= htmlspecialchars($r['email']) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($r['phone']) ?></td>
                    <td class="small">
                        <?php if ($r['note']): ?>
                            <span class="badge bg-warning text-dark">Auto-fixed</span>
                            <div class="text-muted" style="font-size:.72rem"><?= $r['note'] ?></div>
                        <?php else: ?>
                            <span class="badge bg-success">✓ Added</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="px-3 py-2 bg-light border-top small text-muted">
        <i class="bi bi-key me-1"></i>Default password for all imported members: <code>Member@1234</code>
    </div>
    <?php endif; ?>

    <?php if (!empty($importSkips)): ?>
    <div class="px-3 pt-3 pb-2 border-top">
        <p class="fw-semibold small text-danger mb-2">
            <i class="bi bi-exclamation-triangle me-1"></i><?= count($importSkips) ?> skipped:
        </p>
        <?php foreach ($importSkips as $s): ?>
        <div class="d-flex gap-2 align-items-start mb-2 small">
            <span class="badge bg-secondary flex-shrink-0">Row <?= $s['row'] ?></span>
            <span class="fw-semibold"><?= htmlspecialchars($s['name']) ?></span>
            <span class="text-muted">— <?= htmlspecialchars($s['reason']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ═══════════ TABS ═══════════ -->
<ul class="nav nav-tabs mb-4" id="importTabs">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'tab-file'   ? 'active' : '' ?>" data-bs-toggle="tab" href="#tab-file">
            <i class="bi bi-upload me-1"></i>Upload File
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'tab-manual' ? 'active' : '' ?>" data-bs-toggle="tab" href="#tab-manual">
            <i class="bi bi-keyboard me-1"></i>Enter Manually
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tab-help">
            <i class="bi bi-question-circle me-1"></i>Help
        </a>
    </li>
</ul>

<div class="tab-content">

<!-- ══ TAB: FILE UPLOAD ══════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $activeTab === 'tab-file' ? 'show active' : '' ?>" id="tab-file">
<div class="row g-4">

    <div class="col-md-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-card fw-semibold">
                <i class="bi bi-cloud-upload me-1 text-success"></i>Upload Members File
            </div>
            <div class="card-body">
                <div class="d-flex gap-2 mb-4 flex-wrap">
                    <span class="badge bg-success px-3 py-2"><i class="bi bi-file-earmark-excel me-1"></i>.xlsx ✅</span>
                    <span class="badge bg-success px-3 py-2"><i class="bi bi-filetype-csv me-1"></i>.csv ✅</span>
                    <span class="badge bg-danger px-3 py-2"><i class="bi bi-file-earmark-pdf me-1"></i>.pdf ❌</span>
                    <span class="badge bg-danger px-3 py-2"><i class="bi bi-file-earmark me-1"></i>.xls ❌</span>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="file_import">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Choose your Excel or CSV file</label>
                        <input type="file" name="import_file" class="form-control form-control-lg"
                               accept=".xlsx,.csv" required id="fileInput">
                        <div class="form-text">
                            <i class="bi bi-info-circle me-1"></i>
                            Max size: <?= ini_get('upload_max_filesize') ?>.
                            Column <code>full_name</code> is required. All other columns are optional.
                        </div>
                    </div>
                    <div id="filePreviewName" class="mb-3 d-none">
                        <div class="d-flex align-items-center gap-2 p-2 bg-light rounded small">
                            <i class="bi bi-file-earmark-check text-success"></i>
                            <span id="filePreviewText" class="fw-semibold"></span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success w-100 py-2 fw-bold" id="uploadBtn">
                        <i class="bi bi-upload me-2"></i>Upload &amp; Import Members
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-card fw-semibold">
                <i class="bi bi-download me-1 text-primary"></i>Download Template
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Download the template, fill in your members' details, then upload it above.
                </p>
                <a href="?download_template=1" class="btn btn-outline-primary w-100 py-3 text-start mb-3">
                    <i class="bi bi-filetype-csv fs-4 me-2 text-primary"></i>
                    <span>
                        <strong>CSV Template</strong><br>
                        <small class="text-muted">Opens in Excel, Google Sheets, or Notepad</small>
                    </span>
                </a>
                <div class="p-3 bg-light rounded small">
                    <p class="fw-semibold mb-2">Required columns:</p>
                    <p class="font-monospace mb-1" style="font-size:.78rem">
                        <span class="badge bg-danger me-1">required</span> full_name
                    </p>
                    <p class="font-monospace mb-0" style="font-size:.78rem">
                        <span class="badge bg-secondary me-1">optional</span>
                        phone · email · nickname<br>
                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;chama_position · joined_date
                    </p>
                    <hr class="my-2">
                    <p class="text-muted mb-0" style="font-size:.75rem">
                        <i class="bi bi-lightbulb text-warning me-1"></i>
                        Email is optional — the system auto-generates a login email if none is provided.
                    </p>
                </div>
            </div>
        </div>
    </div>

</div>
</div>

<!-- ══ TAB: MANUAL ENTRY ═════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $activeTab === 'tab-manual' ? 'show active' : '' ?>" id="tab-manual">
<div class="card border-0 shadow-sm">
    <div class="card-header bg-card d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-table me-1 text-primary"></i>Manual Entry Table</span>
        <button type="button" class="btn btn-outline-primary btn-sm" id="addRowBtn">
            <i class="bi bi-plus-lg me-1"></i>Add Row
        </button>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <form method="POST" id="bulkForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="bulk_add">
            <table class="table table-bordered table-sm mb-0 align-middle" id="memberTable" style="font-size:.88rem">
                <thead class="table-dark">
                    <tr>
                        <th style="width:30px">#</th>
                        <th style="min-width:160px">Full Name <span class="text-warning">*</span></th>
                        <th style="min-width:120px">Phone</th>
                        <th style="min-width:170px">
                            Email
                            <span class="text-muted fw-normal" style="font-size:.72rem">(optional)</span>
                        </th>
                        <th style="min-width:100px">Nickname</th>
                        <th style="min-width:110px">Position</th>
                        <th style="min-width:110px">Joined Date</th>
                        <th style="width:36px"></th>
                    </tr>
                </thead>
                <tbody id="memberRows">
                <?php for ($i = 0; $i < 10; $i++): ?>
                <tr>
                    <td class="text-muted text-center row-num"><?= $i + 1 ?></td>
                    <td><input type="text"  name="full_name[]"      class="form-control form-control-sm" placeholder="e.g. John Mwangi"></td>
                    <td><input type="text"  name="phone[]"          class="form-control form-control-sm" placeholder="07XXXXXXXX"></td>
                    <td><input type="email" name="email[]"          class="form-control form-control-sm" placeholder="john@email.com"></td>
                    <td><input type="text"  name="nickname[]"       class="form-control form-control-sm" placeholder="e.g. Kangaroo"></td>
                    <td><input type="text"  name="chama_position[]" class="form-control form-control-sm" placeholder="CHAIRMAN"></td>
                    <td><input type="date"  name="joined_date[]"    class="form-control form-control-sm" value="<?= date('Y-m-01') ?>"></td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger remove-row" title="Remove row">
                            <i class="bi bi-x"></i>
                        </button>
                    </td>
                </tr>
                <?php endfor; ?>
                </tbody>
            </table>
            <div class="p-3 border-top d-flex gap-3 align-items-center flex-wrap">
                <button type="submit" class="btn btn-success px-4 fw-bold">
                    <i class="bi bi-person-plus me-1"></i>Add All Members to System
                </button>
                <small class="text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Rows with empty Full Name are skipped silently.
                    Email is optional — auto-generated if blank.
                    Default password: <code>Member@1234</code>
                </small>
            </div>
        </form>
        </div>
    </div>
</div>
</div>

<!-- ══ TAB: HELP ══════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-help">
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <h6 class="fw-bold mb-4">How to Import Members</h6>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="p-3 bg-light rounded h-100">
                    <h6 class="fw-semibold text-success mb-3"><i class="bi bi-upload me-1"></i>Method 1 — File Upload</h6>
                    <ol class="small ps-3 lh-lg mb-0">
                        <li>Click <strong>Download Template</strong> and open the CSV in Excel or Google Sheets</li>
                        <li>Fill in your members — one member per row. Only <strong>full_name</strong> is required</li>
                        <li>Save the file as CSV or Excel (.xlsx)</li>
                        <li>Come back, choose the file and click <strong>Upload &amp; Import Members</strong></li>
                        <li>Results appear immediately below — check for any skipped rows</li>
                    </ol>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 bg-light rounded h-100">
                    <h6 class="fw-semibold text-primary mb-3"><i class="bi bi-keyboard me-1"></i>Method 2 — Manual Entry</h6>
                    <ol class="small ps-3 lh-lg mb-0">
                        <li>Click the <strong>Enter Manually</strong> tab</li>
                        <li>Fill each row — one member per row</li>
                        <li>Use <strong>Add Row</strong> for more than 10 members</li>
                        <li>Only Full Name is required per row</li>
                        <li>Click <strong>Add All Members to System</strong></li>
                    </ol>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 border border-info rounded bg-info bg-opacity-10">
                    <h6 class="fw-semibold mb-2"><i class="bi bi-envelope me-1 text-info"></i>About Email</h6>
                    <p class="small mb-0">
                        Email is used as the <strong>login username</strong>. If a member has no email,
                        leave it blank — the system creates a placeholder like
                        <code>john.mwangi.722456@member.local</code>.
                        You can update it later in <strong>Members → Edit</strong>.
                    </p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 border border-warning rounded bg-warning bg-opacity-10">
                    <h6 class="fw-semibold mb-2"><i class="bi bi-key me-1"></i>Default Password</h6>
                    <p class="small mb-2">All imported members get: <code class="bg-white px-2 py-1 rounded fw-bold">Member@1234</code></p>
                    <p class="small mb-0 text-muted">
                        Tell each member their login email and ask them to change the password
                        after first login. You can also use <strong>Reset Passwords</strong> to
                        set custom passwords per member.
                    </p>
                </div>
            </div>
            <div class="col-12">
                <div class="p-3 border border-danger rounded bg-danger bg-opacity-10">
                    <h6 class="fw-semibold text-danger mb-1"><i class="bi bi-file-earmark-pdf me-1"></i>Why PDF doesn't work</h6>
                    <p class="small mb-0">
                        A PDF is like a printed photo of a page — the computer cannot extract
                        individual cells from it. Type the data into the CSV template
                        (or use manual entry) to import from a printed ledger.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

</div><!-- /tab-content -->

<script>
// Show file name when user picks a file
document.getElementById('fileInput').addEventListener('change', function() {
    const preview = document.getElementById('filePreviewName');
    const text    = document.getElementById('filePreviewText');
    if (this.files.length > 0) {
        text.textContent = this.files[0].name + ' (' + (this.files[0].size / 1024).toFixed(1) + ' KB)';
        preview.classList.remove('d-none');
    } else {
        preview.classList.add('d-none');
    }
});

// Show spinner on upload
document.querySelector('form[action=""]')?.querySelector('[type=submit]');
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function() {
        const btn = this.querySelector('[type=submit]');
        if (btn) {
            btn.disabled = true;
            const orig = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing…';
            // Re-enable after 15s as safety fallback
            setTimeout(() => { btn.disabled = false; btn.innerHTML = orig; }, 15000);
        }
    });
});

// Add Row button
document.getElementById('addRowBtn').addEventListener('click', function () {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    fetch('?get_next_mem_no=1')
        .then(r => r.json())
        .then(() => {
            const tbody = document.getElementById('memberRows');
            const count = tbody.rows.length + 1;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="text-muted text-center row-num">${count}</td>
                <td><input type="text"  name="full_name[]"      class="form-control form-control-sm" placeholder="e.g. John Mwangi"></td>
                <td><input type="text"  name="phone[]"          class="form-control form-control-sm" placeholder="07XXXXXXXX"></td>
                <td><input type="email" name="email[]"          class="form-control form-control-sm" placeholder="john@email.com"></td>
                <td><input type="text"  name="nickname[]"       class="form-control form-control-sm" placeholder="e.g. Kangaroo"></td>
                <td><input type="text"  name="chama_position[]" class="form-control form-control-sm"></td>
                <td><input type="date"  name="joined_date[]"    class="form-control form-control-sm" value="<?= date('Y-m-01') ?>"></td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-x"></i></button>
                </td>`;
            tbody.appendChild(tr);
            tr.querySelector('input').focus();
            renumber();
        })
        .catch(() => {
            // Even if AJAX fails, still add the row — membership number will be auto-assigned on submit
            const tbody = document.getElementById('memberRows');
            const count = tbody.rows.length + 1;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="text-muted text-center row-num">${count}</td>
                <td><input type="text"  name="full_name[]"      class="form-control form-control-sm" placeholder="e.g. John Mwangi"></td>
                <td><input type="text"  name="phone[]"          class="form-control form-control-sm" placeholder="07XXXXXXXX"></td>
                <td><input type="email" name="email[]"          class="form-control form-control-sm" placeholder="john@email.com"></td>
                <td><input type="text"  name="nickname[]"       class="form-control form-control-sm" placeholder="e.g. Kangaroo"></td>
                <td><input type="text"  name="chama_position[]" class="form-control form-control-sm"></td>
                <td><input type="date"  name="joined_date[]"    class="form-control form-control-sm" value="<?= date('Y-m-01') ?>"></td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-x"></i></button>
                </td>`;
            tbody.appendChild(tr);
            tr.querySelector('input').focus();
            renumber();
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add Row';
        });
});

document.addEventListener('click', function (e) {
    if (e.target.closest('.remove-row')) {
        e.target.closest('tr').remove();
        renumber();
    }
});

function renumber() {
    document.querySelectorAll('#memberRows tr').forEach((tr, i) => {
        const n = tr.querySelector('.row-num');
        if (n) n.textContent = i + 1;
    });
}
</script>

<?php require_once ROOT . '/includes/footer.php'; ?>
