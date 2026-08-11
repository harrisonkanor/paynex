<?php
/**
 * Global configuration bootstrap.
 * Included at the top of every page.
 */

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
