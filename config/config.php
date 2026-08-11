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
 * EMAIL SETTINGS
 * ------------------------------------------------------------- */
define('MAIL_FROM_ADDRESS', 'Trustedofficialgh@gmail.com');
define('MAIL_FROM_NAME',    'payNex');

/* ---- Elastic Email (production) ---- */
if (getenv('ELASTICEMAIL_API_KEY')) {
    define('ELASTICEMAIL_API_KEY', getenv('ELASTICEMAIL_API_KEY'));
} else {
    define('ELASTICEMAIL_API_KEY', 'E9F401B1BB44D54E4E80CB41B99E01272CF2D47E95370536EA7A4A5E11B1AAF39A8D9B5825DA70D854BA39688C62B355');
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
