<?php
/**
 * Global configuration bootstrap.
 * Included at the top of every page.
 */

/* ---------------------------------------------------------------
 * SECURITY HEADERS
 * ------------------------------------------------------------- */
// Prevent clickjacking
header('X-Frame-Options: SAMEORIGIN');

// Prevent MIME sniffing
header('X-Content-Type-Options: nosniff');

// Enable XSS protection
header('X-XSS-Protection: 1; mode=block');

// Referrer policy
header('Referrer-Policy: strict-origin-when-cross-origin');

// Content Security Policy
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https:; connect-src 'self';");

// Strict Transport Security (enable if using HTTPS)
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

/* ---------------------------------------------------------------
 * SESSION — harden cookies before session_start()
 * ------------------------------------------------------------- */
ini_set('session.use_strict_mode', 1);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------------------------------------------------------------
 * SITE CONSTANTS
 * ------------------------------------------------------------- */
define('SITE_NAME', 'paynex');
// For Railway.app deployment, BASE_URL should be empty string (serves from root)
define('BASE_URL', getenv('BASE_URL') ?: '');

/* ---------------------------------------------------------------
 * EMAIL SETTINGS - Must be set via environment variables
 * ------------------------------------------------------------- */
define('MAIL_FROM_ADDRESS', getenv('MAIL_FROM_ADDRESS') ?: 'noreply@paynex.app');
define('MAIL_FROM_NAME',    getenv('MAIL_FROM_NAME') ?: 'payNex');

/* ---- Elastic Email - API key from environment variable only ---- */
$elasticApiKey = getenv('ELASTICEMAIL_API_KEY');
if ($elasticApiKey) {
    define('ELASTICEMAIL_API_KEY', $elasticApiKey);
} else {
    // Log warning but don't expose key
    error_log('WARNING: ELASTICEMAIL_API_KEY environment variable not set');
    define('ELASTICEMAIL_API_KEY', '');
}

/* ---------------------------------------------------------------
 * ERROR HANDLING
 * ------------------------------------------------------------- */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

/* ---------------------------------------------------------------
 * LOAD DEPENDENCIES
 * ------------------------------------------------------------- */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/functions.php';
