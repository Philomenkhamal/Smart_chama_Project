<?php
// ─── PRODUCTION GUARD ─────────────────────────────────────────────────────────
// This file should be DELETED after first use.
// Block access if a SETUP_SECRET env variable is set (production safety).
if (!empty(getenv('DISABLE_SETUP')) || !empty($_ENV['DISABLE_SETUP'])) {
    http_response_code(404);
    die('Not found.');
}
// ──────────────────────────────────────────────────────────────────────────────
/**
 * ChamaLedger — Quick Migration Script
 * Run this once to add new tables: sms_log, dividends, dividend_shares, mpesa_verifications
 * Delete this file after running.
 */
if (!defined('ROOT')) define('ROOT', __DIR__);
require_once ROOT . '/includes/functions.php';
$pdo = getDB();

$tables = [
    'mpesa_verifications' => "CREATE TABLE IF NOT EXISTS mpesa_verifications (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        receipt_code VARCHAR(20)  NOT NULL,
        context      VARCHAR(30)  DEFAULT 'contribution',
        context_id   INT UNSIGNED DEFAULT NULL,
        status       ENUM('pending','verified','failed') DEFAULT 'pending',
        result_data  TEXT         DEFAULT NULL,
        checked_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_receipt (receipt_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'sms_log' => "CREATE TABLE IF NOT EXISTS sms_log (
        id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id   INT UNSIGNED  DEFAULT NULL,
        phone     VARCHAR(20)   NOT NULL,
        message   TEXT          NOT NULL,
        type      VARCHAR(50)   DEFAULT 'general',
        status    VARCHAR(20)   DEFAULT 'sent',
        response  TEXT          DEFAULT NULL,
        sent_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_type    (type),
        INDEX idx_sent_at (sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'dividends' => "CREATE TABLE IF NOT EXISTS dividends (
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        year           YEAR         NOT NULL,
        total_profit   DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_savings  DECIMAL(14,2) NOT NULL DEFAULT 0,
        notes          TEXT          DEFAULT NULL,
        status         ENUM('draft','distributed') NOT NULL DEFAULT 'draft',
        created_by     INT UNSIGNED  DEFAULT NULL,
        created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        distributed_at TIMESTAMP     NULL DEFAULT NULL,
        UNIQUE KEY uq_year (year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'dividend_shares' => "CREATE TABLE IF NOT EXISTS dividend_shares (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dividend_id  INT UNSIGNED NOT NULL,
        user_id      INT UNSIGNED NOT NULL,
        avg_balance  DECIMAL(14,2) NOT NULL DEFAULT 0,
        share_pct    DECIMAL(8,4)  NOT NULL DEFAULT 0,
        share_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        notified     TINYINT(1)    DEFAULT 0,
        FOREIGN KEY (dividend_id) REFERENCES dividends(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
        UNIQUE KEY uq_div_user (dividend_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

$columns = [
    // Fix for existing sms_log tables created before the `type` column was added
    "ALTER TABLE sms_log ADD COLUMN IF NOT EXISTS type     VARCHAR(50) DEFAULT 'general' AFTER message",
    "ALTER TABLE sms_log ADD COLUMN IF NOT EXISTS response TEXT        DEFAULT NULL      AFTER status",
    "ALTER TABLE mpesa_transactions ADD COLUMN IF NOT EXISTS event_id INT UNSIGNED DEFAULT NULL AFTER contribution_id",
    "ALTER TABLE mpesa_transactions ADD COLUMN IF NOT EXISTS loan_id  INT UNSIGNED DEFAULT NULL AFTER event_id",
];

$results = [];

foreach ($tables as $name => $sql) {
    try {
        $pdo->exec($sql);
        $results[] = ['table' => $name, 'ok' => true, 'msg' => 'Created / already exists'];
    } catch (PDOException $e) {
        $results[] = ['table' => $name, 'ok' => false, 'msg' => $e->getMessage()];
    }
}

foreach ($columns as $sql) {
    try {
        $pdo->exec($sql);
        $results[] = ['table' => 'ALTER mpesa_transactions', 'ok' => true, 'msg' => 'Column added / already exists'];
    } catch (PDOException $e) {
        $results[] = ['table' => 'ALTER', 'ok' => false, 'msg' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Migration — ChamaLedger</title>
<style>
body{font-family:sans-serif;background:#060e1a;color:#e2eaf4;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.card{background:#0d1f38;border:1px solid rgba(255,255,255,.07);border-radius:16px;padding:2rem;max-width:540px;width:100%}
h2{color:#00c471;margin-bottom:1.5rem}
.row{display:flex;align-items:center;gap:.75rem;padding:.6rem .8rem;border-radius:8px;margin-bottom:.4rem;font-size:.875rem}
.ok{background:rgba(0,196,113,.08);border:1px solid rgba(0,196,113,.15)}
.fail{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.15);color:#fca5a5}
.icon{font-size:1.1rem}
.note{font-size:.78rem;color:#6b87a8;margin-top:1.25rem;line-height:1.7}
a{color:#00c471}
</style>
</head>
<body>
<div class="card">
    <h2>✅ Migration Complete</h2>
    <?php foreach ($results as $r): ?>
    <div class="row <?= $r['ok'] ? 'ok' : 'fail' ?>">
        <span class="icon"><?= $r['ok'] ? '✓' : '✗' ?></span>
        <div>
            <strong><?= htmlspecialchars($r['table']) ?></strong><br>
            <span style="font-size:.75rem;opacity:.8"><?= htmlspecialchars($r['msg']) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
    <?php
    // Seed loan eligibility settings
    $loanDefaults = [
        'loan_min_months'         => '3',
        'loan_min_consecutive'    => '3',
        'loan_block_unpaid_fines' => '1',
        'loan_block_active_loan'  => '1',
    ];
    foreach ($loanDefaults as $k => $v) {
        $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?,?)")
            ->execute([$k, $v]);
    }
    ?>
    <div class="row ok"><span class="icon">✓</span><div><strong>Loan Eligibility Settings</strong><br><span style="font-size:.75rem;opacity:.8">Seeded default rules (3 months min, 3 consecutive, block fines, block active loan)</span></div></div>
    <div class="note">
        All done. <strong style="color:#ef4444">Delete <code>migrate.php</code> from your server</strong> after this — it's not needed anymore.<br><br>
        <a href="<?= APP_URL ?>/admin/sms_reminders.php">→ Go to SMS Reminders</a><br>
        <a href="<?= APP_URL ?>/admin/dividends.php">→ Go to Dividends</a>
    </div>
</div>
</body>
</html>
