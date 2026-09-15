<?php
// Debug: Check if files exist
// echo "Current directory: " . __DIR__ . "<br>";
// echo "Database file exists: " . (file_exists(__DIR__ . '/../config/database.php') ? 'Yes' : 'No') . "<br>";
// echo "Auth file exists: " . (file_exists(__DIR__ . '/auth.php') ? 'Yes' : 'No') . "<br>";


// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define base path
// define('BASE_PATH', dirname(dirname(__FILE__)));
// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Debug server time
error_log("Server time: " . date('Y-m-d H:i:s'));

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
?>