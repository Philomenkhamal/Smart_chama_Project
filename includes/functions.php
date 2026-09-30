<?php
/**
 * SmartChama Financial Management System
 * Core Helper Functions
 */

// ── Output buffering — MUST be first line ─────────────────────────────────────
// This ensures header() calls always work, even after HTML has been output.

// Bypass ngrok browser warning interstitial page
if (isset($_SERVER['HTTP_X_FORWARDED_HOST']) || str_contains($_SERVER['HTTP_HOST'] ?? '', 'ngrok')) {
    header('ngrok-skip-browser-warning: true');
}
// The buffer is flushed automatically at the end of the script.
if (!ob_get_level()) ob_start();

require_once __DIR__ . '/../config/db.php';


// ══════════════════════════════════════════════════════
// LANGUAGE / TRANSLATION SYSTEM
// ══════════════════════════════════════════════════════

/**
 * Get current language code ('en' or 'sw')
 */
function getLang(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return $_SESSION['chama_lang'] ?? 'en';
}

/**
 * Set language in session
 */
function setLang(string $lang): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['chama_lang'] = in_array($lang, ['en', 'sw']) ? $lang : 'en';
}

/**
 * Translate a key. Falls back to English if Swahili key missing.
 * Usage: <?= t('nav_dashboard') ?>
 */
function t(string $key): string {
    static $cache = [];
    $lang = getLang();
    if (!isset($cache[$lang])) {
        $file = (defined('ROOT') ? ROOT : dirname(__DIR__)) . "/lang/{$lang}.php";
        $cache[$lang] = file_exists($file) ? require $file : [];
    }
    if (!isset($cache[$lang][$key]) && $lang !== 'en') {
        // Fallback to English
        if (!isset($cache['en'])) {
            $file = (defined('ROOT') ? ROOT : dirname(__DIR__)) . '/lang/en.php';
            $cache['en'] = file_exists($file) ? require $file : [];
        }
        return htmlspecialchars((string)($cache['en'][$key] ?? $key), ENT_QUOTES, 'UTF-8');
    }
    return htmlspecialchars((string)($cache[$lang][$key] ?? $key), ENT_QUOTES, 'UTF-8');
}

function dbIsReady(): bool {
    try {
        getDB()->query("SELECT 1 FROM users LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ─── Session ──────────────────────────────────────────────────────────────────

function sendSecurityHeaders(): void {
    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    // Prevent MIME sniffing
    header('X-Content-Type-Options: nosniff');
    // XSS protection for older browsers
    header('X-XSS-Protection: 1; mode=block');
    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // ini_set must be called before session_start
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_samesite', 'Lax');
        // Secure flag only when actually on HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            ini_set('session.cookie_secure', '1');
        }
        session_start();
    }
}

function isLoggedIn(): bool {
    startSession();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    startSession();
    if (!isLoggedIn()) {
        redirect(APP_URL . '/index.php?msg=login_required');
    }
}

function requireAdmin(): void {
    requireLogin();
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        redirect(APP_URL . '/member/dashboard.php');
    }
}

function requireMember(): void {
    requireLogin();
    if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'member'])) {
        redirect(APP_URL . '/index.php');
    }
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    static $user = null;
    if ($user === null) {
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

// ─── Security ─────────────────────────────────────────────────────────────────

/**
 * sanitize() — for HTML OUTPUT only. Encodes special chars for safe display.
 * DO NOT use this to clean data before storing in the database.
 */
function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * cleanInput() — for DATABASE STORAGE. Strips HTML tags and trims whitespace.
 * Does NOT htmlspecialchars — that would corrupt names like "Ng'ang'a".
 * Always use this (not sanitize) when saving user input to the DB.
 */
function cleanInput(string $input): string {
    return trim(strip_tags($input));
}

function generateToken(): string {
    return bin2hex(random_bytes(32));
}

function csrfToken(): string {
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken();
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool {
    startSession();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfField_value() . '">';
}

function csrfField_value(): string {
    return htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');
}

// ─── Redirect & Flash ─────────────────────────────────────────────────────────

function redirect(string $url): void {
    // Clean ALL output buffers before sending the Location header.
    // This works even if HTML has already been partially output.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Location: ' . $url, true, 302);
        exit;
    }
    // Fallback: if headers truly already sent, use JS redirect
    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url) . '">';
    exit;
}

function setFlash(string $type, string $message): void {
    startSession();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}


/**
 * getFlash() — retrieves the queued flash message and returns rendered HTML.
 * Returns empty string if no flash is set.
 * Use: <?= getFlash() ?>  OR  echo getFlash();
 * Also works as: if ($flash = getFlashRaw()) { ... }
 */
function getFlash(): string {
    startSession();
    if (!isset($_SESSION['flash'])) return '';
    $flash   = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $type    = htmlspecialchars($flash['type']    ?? 'info',    ENT_QUOTES, 'UTF-8');
    $message = $flash['message'] ?? ''; // allow HTML in messages (admin-set, trusted)
    return "<div class=\"alert alert-{$type} alert-dismissible fade show\" role=\"alert\" style=\"margin-bottom:1rem\">
                {$message}
                <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\"></button>
            </div>";
}

/** Raw access — returns the flash array if you need type/message separately */
function getFlashRaw(): ?array {
    startSession();
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function renderFlash(): void {
    echo getFlash();
}

// ─── Formatting ───────────────────────────────────────────────────────────────

function money(float $amount, string $currency = 'KES'): string {
    return $currency . ' ' . number_format($amount, 2);
}

function formatDate(string $date, string $format = 'd M Y'): string {
    return date($format, strtotime($date));
}

function monthLabel(string $date): string {
    return date('F Y', strtotime($date));
}

function badgeStatus(string $status): string {
    $map = [
        'pending'    => 'warning',
        'active'     => 'success',
        'approved'   => 'success',
        'confirmed'  => 'success',
        'disbursed'  => 'info',
        'completed'  => 'primary',
        'rejected'   => 'danger',
        'suspended'  => 'secondary',
        'defaulted'  => 'danger',
    ];
    $class = $map[$status] ?? 'secondary';
    return "<span class=\"badge bg-{$class}\">" . ucfirst($status) . "</span>";
}

// ─── Membership Number Generator ──────────────────────────────────────────────

function generateMembershipNumber(): string {
    static $batchCounter = null; // Tracks counter within a single PHP request (batch-safe)

    $pdo    = getDB();
    $prefix = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='membership_prefix'")->fetchColumn() ?: 'CM';

    // Get highest existing number from DB
    $stmt = $pdo->prepare("SELECT membership_number FROM users WHERE membership_number LIKE ?");
    $stmt->execute([$prefix . '-%']);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $num) {
        $parts = explode('-', $num);
        $n = (int)end($parts);
        if ($n > $max) $max = $n;
    }

    // During batch import, $batchCounter ensures each call within the same
    // request increments beyond what previous calls already reserved
    if ($batchCounter === null || $batchCounter <= $max) {
        $batchCounter = $max + 1;
    } else {
        $batchCounter++;
    }

    // Extra safety: keep incrementing until truly unique in DB
    $candidate = $prefix . '-' . str_pad($batchCounter, 3, '0', STR_PAD_LEFT);
    $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE membership_number=?");
    $check->execute([$candidate]);
    while ((int)$check->fetchColumn() > 0) {
        $batchCounter++;
        $candidate = $prefix . '-' . str_pad($batchCounter, 3, '0', STR_PAD_LEFT);
        $check->execute([$candidate]);
    }

    return $candidate;
}

function generateLoanNumber(): string {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='loan_prefix'");
    $prefix = $stmt->fetchColumn() ?: 'LN';
    $year   = date('Y');

    // Use MAX to find the highest existing number — avoids duplicates after deletions
    $stmt = $pdo->prepare("SELECT loan_number FROM loans WHERE loan_number LIKE ?");
    $stmt->execute([$prefix . '-' . $year . '-%']);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $num) {
        $parts = explode('-', $num);
        $n = (int)end($parts);
        if ($n > $max) $max = $n;
    }
    $candidate = $prefix . '-' . $year . '-' . str_pad($max + 1, 4, '0', STR_PAD_LEFT);

    // Final uniqueness guard
    $check = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE loan_number=?");
    $check->execute([$candidate]);
    $offset = $max + 1;
    while ((int)$check->fetchColumn() > 0) {
        $offset++;
        $candidate = $prefix . '-' . $year . '-' . str_pad($offset, 4, '0', STR_PAD_LEFT);
        $check->execute([$candidate]);
    }

    return $candidate;
}

// ─── Notifications ────────────────────────────────────────────────────────────

function createNotification(int $userId, string $title, string $message, string $type = 'info', string $link = ''): void {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $title, $message, $type, $link]);
}

function countUnreadNotifications(int $userId): int {
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

// ─── Activity Log ─────────────────────────────────────────────────────────────

function logActivity(string $action, string $description = '', ?int $userId = null): void {
    try {
        $pdo = getDB();
        $uid = $userId ?? (isset($_SESSION["user_id"]) ? (int)$_SESSION["user_id"] : null);
        // Only pass user_id if it actually exists in users table to avoid FK violation
        if ($uid !== null) {
            $exists = $pdo->prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
            $exists->execute([$uid]);
            if (!$exists->fetchColumn()) $uid = null;
        }
        $ip = $_SERVER["REMOTE_ADDR"] ?? null;
        $pdo->prepare("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)")
            ->execute([$uid, $action, $description, $ip]);
    } catch (\Exception $e) {
        // Never let logging crash the app
        error_log("[ChamaLedger] logActivity failed: " . $e->getMessage());
    }
}

// ─── System Setting ───────────────────────────────────────────────────────────

function getSetting(string $key, string $default = ''): string {
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $val  = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

/**
 * Get the date from which the chama started tracking contributions.
 * Fully automatic — derived from the earliest confirmed contribution in DB,
 * falling back to earliest member join date, then current month.
 * Results are cached in a static variable for performance.
 *
 * @return string  Y-m-01 format, e.g. "2025-01-01"
 */
function getChamaStartDate(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    try {
        $pdo = getDB();

        // 1. Earliest confirmed contribution (most reliable — actual financial record)
        $row = $pdo->query("SELECT MIN(payment_month) FROM contributions WHERE status='confirmed'")->fetchColumn();
        if ($row && $row !== '0000-00-00') {
            $cached = date('Y-m-01', strtotime($row));
            return $cached;
        }

        // 2. Earliest member join date (next best — when members were added)
        $row = $pdo->query("
            SELECT MIN(joined_date) FROM users
            WHERE role='member' AND status='active'
            AND joined_date IS NOT NULL AND joined_date != '0000-00-00'
        ")->fetchColumn();
        if ($row && $row !== '0000-00-00') {
            $cached = date('Y-m-01', strtotime($row));
            return $cached;
        }

        // 3. Earliest member created_at
        $row = $pdo->query("
            SELECT MIN(created_at) FROM users
            WHERE role='member' AND status='active'
        ")->fetchColumn();
        if ($row) {
            $cached = date('Y-m-01', strtotime($row));
            return $cached;
        }
    } catch (Exception $e) {}

    // 4. Absolute fallback — start of this year
    $cached = date('Y-01-01');
    return $cached;
}

/**
 * Get a member's effective start date — the LATER of:
 * (a) their actual join date, and (b) the chama start date.
 * This prevents showing missed months before the chama existed.
 *
 * @param array $user  User row with joined_date and created_at
 * @return string      Y-m-01 format
 */
function getMemberStartDate(array $user): string {
    $rawJoin = $user['joined_date'] ?? '';
    $jTs     = ($rawJoin && $rawJoin !== '0000-00-00') ? strtotime($rawJoin) : 0;
    if ($jTs <= 0) {
        $rawJoin = $user['created_at'] ?? '';
        $jTs = ($rawJoin && $rawJoin !== '0000-00-00') ? strtotime($rawJoin) : 0;
    }
    if ($jTs <= 0) $jTs = time();

    $joinDate   = date('Y-m-01', $jTs);
    $chamaStart = getChamaStartDate();

    // Return whichever is LATER — member can't have missed months before chama started
    return $joinDate > $chamaStart ? $joinDate : $chamaStart;
}


// ─── Loan Calculator ──────────────────────────────────────────────────────────

/**
 * Simple flat-rate interest calculation (common in Kenyan Chamas):
 *   Total Interest = Principal × Rate × Months
 *   Monthly Installment = Total Repayable / Months
 */
function calculateLoan(float $principal, float $ratePercent, int $months): array {
    $totalInterest   = $principal * ($ratePercent / 100) * $months;
    $totalRepayable  = $principal + $totalInterest;
    $monthlyPayment  = $totalRepayable / $months;

    return [
        'principal'       => $principal,
        'interest_rate'   => $ratePercent,
        'months'          => $months,
        'total_interest'  => round($totalInterest, 2),
        'total_repayable' => round($totalRepayable, 2),
        'monthly_payment' => round($monthlyPayment, 2),
    ];
}

// ─── Email ────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/sms.php';

function sendApprovalEmail(array $member, string $membershipNumber): void {
    $name     = htmlspecialchars($member['full_name']);
    $email    = $member['email'];
    $loginUrl = APP_URL . '/index.php';
    $year     = date('Y');
    $html = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;background:#f0f4f8;margin:0;padding:2rem'>
<div style='max-width:520px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08)'>
  <div style='background:linear-gradient(135deg,#060e1a,#0f2040);padding:2rem;text-align:center'>
    <h1 style='font-size:1.5rem;font-weight:800;color:#fff;margin:0'>ChamaLedger</h1>
  </div>
  <div style='padding:2rem;text-align:center'>
    <div style='font-size:3rem;margin-bottom:.75rem'>&#x2705;</div>
    <h2 style='color:#1a2940;font-size:1.3rem'>You're approved, {$name}!</h2>
    <p style='color:#5a7390;font-size:.875rem;line-height:1.7'>Your ChamaLedger membership has been approved by the administrator.</p>
    <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:1.25rem;margin:1.25rem 0'>
      <div style='font-size:.75rem;font-weight:700;text-transform:uppercase;color:#059669;margin-bottom:.3rem'>Your Membership Number</div>
      <div style='font-size:1.5rem;font-weight:800;color:#064e3b;font-family:monospace'>{$membershipNumber}</div>
    </div>
    <a href='{$loginUrl}' style='display:inline-block;background:#00c471;color:#060e1a;font-weight:700;padding:.85rem 2rem;border-radius:10px;text-decoration:none'>Login to Dashboard &rarr;</a>
  </div>
  <div style='background:#f8fafc;padding:1rem 2rem;text-align:center;border-top:1px solid #e2e8f0'>
    <p style='font-size:.75rem;color:#94a3b8;margin:0'>&copy; {$year} ChamaLedger &mdash; sent to {$email}</p>
  </div>
</div></body></html>";
    sendEmail($email, $member['full_name'], 'ChamaLedger — Your Account is Approved! ✅', $html);
}

function sendRejectionEmail(array $member): void {
    $name  = htmlspecialchars($member['full_name']);
    $email = $member['email'];
    $html  = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;background:#f0f4f8;margin:0;padding:2rem'>
<div style='max-width:520px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden'>
  <div style='background:#060e1a;padding:2rem;text-align:center'>
    <h1 style='color:#fff;margin:0'>ChamaLedger</h1>
  </div>
  <div style='padding:2rem;text-align:center'>
    <h2 style='color:#1a2940'>Registration Update</h2>
    <p style='color:#5a7390;font-size:.875rem;line-height:1.7'>Dear {$name},<br><br>After review, your registration was not approved at this time. Please contact the group administrator for more information.</p>
  </div>
</div></body></html>";
    sendEmail($email, $member['full_name'], 'ChamaLedger — Registration Update', $html);
}

function sendRegistrationReceivedEmail(array $member): void {
    $name      = htmlspecialchars($member['full_name']);
    $email     = $member['email'];
    $statusUrl = APP_URL . '/pending.php';
    $html = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;background:#f0f4f8;margin:0;padding:2rem'>
<div style='max-width:520px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden'>
  <div style='background:linear-gradient(135deg,#060e1a,#0f2040);padding:2rem;text-align:center'>
    <h1 style='color:#fff;margin:0'>ChamaLedger</h1>
  </div>
  <div style='padding:2rem'>
    <h2 style='color:#1a2940;font-size:1.1rem'>Hi {$name}, we got your application!</h2>
    <p style='color:#5a7390;font-size:.875rem;line-height:1.7'>Your registration is awaiting approval from the administrator. You will receive another email as soon as a decision is made.</p>
    <div style='background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:1rem;margin:1rem 0;font-size:.83rem;color:#92400e'>&#x23F3; Approvals typically happen within 24&ndash;48 hours.</div>
    <div style='text-align:center;margin-top:1.5rem'>
      <a href='{$statusUrl}' style='display:inline-block;background:#00c471;color:#060e1a;font-weight:700;padding:.75rem 2rem;border-radius:10px;text-decoration:none'>Check Your Status &rarr;</a>
    </div>
  </div>
</div></body></html>";
    sendEmail($email, $member['full_name'], 'ChamaLedger — Registration Received', $html);
}

// ── Report helper: render member avatar (photo or coloured initials) ──────────
function memberAvatar(array $member, int $size = 36): string {
    $name    = trim($member['full_name'] ?? $member['name'] ?? '?');
    $photo   = $member['profile_photo'] ?? null;
    $path    = $photo ? ROOT . '/uploads/avatars/' . $photo : null;
    $parts   = array_filter(explode(' ', $name));
    $initials = implode('', array_map(fn($p) => strtoupper($p[0]), array_slice($parts, 0, 2)));
    $colors  = ['#2563eb','#16a34a','#d97706','#dc2626','#7c3aed','#0891b2','#be185d','#059669'];
    $color   = $colors[abs(crc32($name)) % count($colors)];
    $fs      = (int)round($size * 0.38);
    if ($path && file_exists($path)) {
        $src = APP_URL . '/uploads/avatars/' . htmlspecialchars($photo);
        return '<img src="' . $src . '" style="width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;object-fit:cover;border:2px solid #e5e7eb;flex-shrink:0;vertical-align:middle" alt="">';
    }
    return '<div style="width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;background:' . $color . ';color:#fff;font-weight:700;font-size:' . $fs . 'px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;font-family:sans-serif;vertical-align:middle">' . htmlspecialchars($initials) . '</div>';
}


