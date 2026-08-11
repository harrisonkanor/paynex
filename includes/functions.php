<?php
/**
 * Shared helper functions — used across the entire payNex app.
 *
 * Sections:
 *   1. Output escaping
 *   2. Redirect
 *   3. Flash messages
 *   4. CSRF protection
 *   5. Auth helpers
 *   6. Activity logging
 *   7. Login rate limiting
 *   8. Formatting helpers
 *   9. Referral code generator
 *  10. Site settings helper
 *  11. Suspension check
 *  12. Crypto address validation
 *  13. Email verification (OTP)
 *  14. Email domain validation
 */

/* =============================================================
 * 1. OUTPUT ESCAPING
 * Always run user-supplied data through e() before printing it
 * in HTML to prevent Cross-Site Scripting (XSS) attacks.
 * =========================================================== */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/* =============================================================
 * 2. REDIRECT
 * =========================================================== */
function redirect(string $path): void
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

/* =============================================================
 * 3. FLASH MESSAGES
 * Set: flash('error', 'Something went wrong.')
 * Get: $msg = flash('error');  — clears it automatically
 * =========================================================== */
function flash(string $key, ?string $message = null)
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return;
    }
    if (!empty($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}

/* =============================================================
 * 4. CSRF PROTECTION
 * Every state-changing form must include csrf_field().
 * Every POST handler must call verify_csrf() before doing anything.
 * =========================================================== */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        die('Invalid or expired form submission. Please go back and try again.');
    }
}

/* =============================================================
 * 5. AUTH HELPERS
 * =========================================================== */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('error', 'Please log in to continue.');
        redirect('/login.php');
    }
}

function require_role(string $role): void
{
    require_login();
    $user = current_user();
    if ($user['role'] !== $role) {
        http_response_code(403);
        die('You do not have permission to view this page.');
    }
}

function require_admin(): void
{
    if (empty($_SESSION['admin'])) {
        redirect('/admin/login.php');
    }
}

function is_suspended(): bool
{
    $u = current_user();
    return $u && $u['status'] === 'suspended';
}

/* =============================================================
 * 6. ACTIVITY LOGGING
 * =========================================================== */
function log_activity(PDO $pdo, ?int $userId, string $action): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO activity_logs (user_id, action, ip_address, created_at)
         VALUES (:uid, :action, :ip, NOW())'
    );
    $stmt->execute([
        ':uid'    => $userId,
        ':action' => mb_substr($action, 0, 255),
        ':ip'     => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);
}

/* =============================================================
 * 7. LOGIN RATE LIMITING
 * =========================================================== */
function too_many_login_attempts(PDO $pdo, string $email): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE email = :email AND attempted_at > (NOW() - INTERVAL 15 MINUTE)'
    );
    $stmt->execute([':email' => $email]);
    return (int) $stmt->fetchColumn() >= 5;
}

function record_login_attempt(PDO $pdo, string $email): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (email, ip_address, attempted_at)
         VALUES (:email, :ip, NOW())'
    );
    $stmt->execute([':email' => $email, ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
}

function clear_login_attempts(PDO $pdo, string $email): void
{
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE email = :email');
    $stmt->execute([':email' => $email]);
}

/* =============================================================
 * 8. FORMATTING HELPERS
 * =========================================================== */
function money(float $amount): string
{
    return '$' . number_format($amount, 2);
}

function time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60)   return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

/* =============================================================
 * 9. REFERRAL CODE GENERATOR
 * =========================================================== */
function generate_referral_code(PDO $pdo): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $check = $pdo->prepare('SELECT id FROM users WHERE referral_code = :code');
        $check->execute([':code' => $code]);
    } while ($check->fetchColumn());
    return $code;
}

/* =============================================================
 * 10. SITE SETTINGS
 * =========================================================== */
function get_setting(PDO $pdo, string $key, string $default = ''): string
{
    static $cache = [];
    if (!isset($cache[$key])) {
        $stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :k');
        $stmt->execute([':k' => $key]);
        $cache[$key] = $stmt->fetchColumn() ?: $default;
    }
    return (string) $cache[$key];
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO site_settings (setting_key, setting_value)
         VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = :v2'
    );
    $stmt->execute([':k' => $key, ':v' => $value, ':v2' => $value]);
}

/* =============================================================
 * 11. VIP PLAN HELPER
 * =========================================================== */
function get_vip_plan(PDO $pdo, int $level): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM vip_plans WHERE level = :lv');
    $stmt->execute([':lv' => $level]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function maybe_award_referral_bonus(PDO $pdo, int $referredUserId, int $vipLevel): void
{
    $stmt = $pdo->prepare(
        'SELECT r.*, vp.referral_bonus FROM referrals r
         JOIN vip_plans vp ON vp.level = :lv
         WHERE r.referred_id = :rid AND r.bonus_paid = 0'
    );
    $stmt->execute([':rid' => $referredUserId, ':lv' => $vipLevel]);
    $ref = $stmt->fetch();
    if (!$ref) return;

    $depCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM deposit_orders
         WHERE user_id = :uid AND status = "confirmed"'
    );
    $depCheck->execute([':uid' => $referredUserId]);
    if ((int)$depCheck->fetchColumn() === 0) return;

    $bonus = (float) $ref['referral_bonus'];
    $credit = $pdo->prepare('UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :id');
    $credit->execute([':amt' => $bonus, ':id' => $ref['referrer_id']]);

    $tx = $pdo->prepare('INSERT INTO wallet_transactions (user_id, type, amount, description) VALUES (:uid, "credit", :amt, :desc)');
    $tx->execute([':uid' => $ref['referrer_id'], ':amt' => $bonus, ':desc' => 'Referral bonus — new VIP ' . $vipLevel . ' member (deposit confirmed)']);

    $upd = $pdo->prepare('UPDATE referrals SET bonus_paid = 1, bonus_amount = :amt, vip_level = :lv WHERE id = :id');
    $upd->execute([':amt' => $bonus, ':lv' => $vipLevel, ':id' => $ref['id']]);
}

/* =============================================================
 * 12. USDT (TRC-20) ADDRESS VALIDATION
 * =========================================================== */

/**
 * Validate a USDT TRC-20 (Tron) address format.
 * Tron addresses start with 'T' and are base58, 34 characters long.
 */
function validate_usdt_trc20_address(string $address): bool
{
    $addr = trim($address);
    if ($addr === '') return true;
    if (strlen($addr) === 34 && $addr[0] === 'T') {
        if (preg_match('/^T[a-km-zA-HJ-NP-Z1-9]{33}$/', $addr)) {
            return true;
        }
    }
    return false;
}

/**
 * Validate an email domain exists (has MX records).
 */
function validate_email_domain(string $email): bool
{
    $parts = explode('@', $email);
    if (count($parts) !== 2) return false;
    $domain = trim($parts[1]);
    if ($domain === '') return false;
    if (function_exists('checkdnsrr')) {
        return checkdnsrr($domain, 'MX');
    }
    $mxHosts = [];
    if (function_exists('getmxrr')) {
        return getmxrr($domain, $mxHosts) && count($mxHosts) > 0;
    }
    return true;
}

/* =============================================================
 * 13. EMAIL VERIFICATION (OTP)
 * =========================================================== */

function generate_otp_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function is_email_verified(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT email_verified FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    return (bool) $stmt->fetchColumn();
}

function require_email_verified(PDO $pdo): void
{
    require_login();
    $user = current_user();
    if (empty($user['email_verified'])) {
        if (!is_email_verified($pdo, $user['id'])) {
            flash('error', 'Please verify your email address before accessing the platform.');
            redirect('/verify_email.php');
        }
        $_SESSION['user']['email_verified'] = 1;
    }
}
