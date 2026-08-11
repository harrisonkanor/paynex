<?php
/**
 * Database connection (PDO / MySQL)
 * -----------------------------------------------------------------
 * Supports Railway.app MySQL environment variables (MYSQLHOST, MYSQLPORT, etc.)
 * Falls back to local defaults when running locally.
 */

// Support Railway.app MySQL environment variables
$DB_HOST = getenv('MYSQLHOST') ?: '127.0.0.1';
$DB_PORT = getenv('MYSQLPORT') ?: '3306';
$DB_NAME = getenv('MYSQLDATABASE') ?: 'paynex';
$DB_USER = getenv('MYSQLUSER') ?: 'paynex';
$DB_PASS = getenv('MYSQLPASSWORD') ?: 'paynex1234';
$DB_CHARSET = 'utf8mb4';

// Keep constants for backward compatibility
define('DB_HOST', $DB_HOST);
define('DB_PORT', $DB_PORT);
define('DB_NAME', $DB_NAME);
define('DB_USER', $DB_USER);
define('DB_PASS', $DB_PASS);
define('DB_CHARSET', $DB_CHARSET);

$dsn = 'mysql:host=' . $DB_HOST . ';port=' . $DB_PORT . ';dbname=' . $DB_NAME . ';charset=' . $DB_CHARSET;

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Service temporarily unavailable. Please try again later.');
}
