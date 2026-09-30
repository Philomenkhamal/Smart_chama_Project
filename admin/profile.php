<?php
/**
 * CHAMA Financial Management System
 * Admin — Profile Page
 */

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/functions.php';
requireAdmin();

// Load member profile (shared logic) — ROOT already defined, won't conflict
require_once ROOT . '/member/profile.php';
