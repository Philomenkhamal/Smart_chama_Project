<?php
/**
 * ChamaLedger — One-time fix: make national_id nullable
 * Run this ONCE if you get "Duplicate entry '' for key 'national_id'" errors
 * Visit: http://localhost/chama_system/fix_national_id.php
 * DELETE THIS FILE after running it.
 */
if (!defined('ROOT')) define('ROOT', __DIR__);
require_once ROOT . '/includes/functions.php';

// Only allow if not already fixed
$pdo = getDB();

try {
    // Check current column definition
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'national_id'")->fetch();
    $isNullable = ($col['Null'] === 'YES');

    if ($isNullable) {
        echo "<div style='font-family:sans-serif;padding:2rem;max-width:600px'>
            <h2 style='color:green'>✅ Already fixed!</h2>
            <p>The <code>national_id</code> column is already nullable. No action needed.</p>
            <p><a href='/chama_system/admin/import_members.php'>← Back to Import Members</a></p>
        </div>";
        exit;
    }

    // Fix it
    $pdo->exec("ALTER TABLE users MODIFY national_id VARCHAR(30) DEFAULT NULL");

    // Set duplicate empty strings to NULL
    $pdo->exec("UPDATE users SET national_id = NULL WHERE national_id = ''");

    // Fix members with NULL or bad joined_date - set to created_at date
    // Fix all bad joined_date values: NULL, empty, or MySQL's '0000-00-00'
    $pdo->exec("UPDATE users SET joined_date = DATE(created_at) WHERE joined_date IS NULL OR joined_date = '0000-00-00' OR joined_date = ''");

    $fixed = $pdo->query("SELECT COUNT(*) FROM users WHERE role='member'")->fetchColumn();

    echo "<div style='font-family:sans-serif;padding:2rem;max-width:600px;background:#f0fff0;border:2px solid green;border-radius:8px'>
        <h2 style='color:green'>✅ Fixed successfully!</h2>
        <p>The <code>national_id</code> column has been made optional (nullable).</p>
        <p>Joined dates fixed for all members with missing dates.</p>
        <p>You can now import members without National ID numbers.</p>
        <p style='margin-top:1.5rem'>
            <strong>⚠️ Please delete this file now:</strong><br>
            <code>C:\\xampp\\htdocs\\chama_system\\fix_national_id.php</code>
        </p>
        <p><a href='/chama_system/admin/import_members.php' style='background:green;color:white;padding:.5rem 1rem;border-radius:4px;text-decoration:none'>← Back to Import Members</a></p>
    </div>";

} catch (Exception $e) {
    echo "<div style='font-family:sans-serif;padding:2rem;color:red'>
        <h2>❌ Error</h2>
        <p>" . htmlspecialchars($e->getMessage()) . "</p>
    </div>";
}
