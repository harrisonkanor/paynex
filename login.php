<?php
/**
 * login.php — User authentication.
 *
 * Security measures:
 *   - CSRF token on every POST
 *   - Brute-force protection: 5 attempts / 15 minutes per email
 *   - session_regenerate_id() on login (prevents session-fixation attacks)
 *   - Login-alert email sent to the user on each successful login
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/mailer.php';

// Redirect already-logged-in users
if (is_logged_in()) {
    redirect('/dashboard.php');
}

$email  = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();  // must be first

    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';

    // --- Rate limiting check ---
    if (too_many_login_attempts($pdo, $email)) {
        $errors[] = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        // Prepared statement — prevents SQL injection
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // Record the failed attempt for rate limiting
            record_login_attempt($pdo, $email);
            $errors[] = 'Incorrect email or password.';
        } elseif ($user['status'] !== 'active') {
            $note = $user['suspension_note'] ? ' — ' . $user['suspension_note'] : '';
            $errors[] = 'Your account has been suspended' . $note . '. Contact support for help.';
        } else {
            // Success — clear any previous failed attempts
            clear_login_attempts($pdo, $email);

            // Regenerate session ID to prevent session fixation attacks
            session_regenerate_id(true);

            // Store minimal user data in session (not the password hash!)
            $_SESSION['user'] = [
                'id'                => (int) $user['id'],
                'name'              => $user['name'],
                'email'             => $user['email'],
                'role'              => $user['role'],
                'status'            => $user['status'],
                'vip_level'         => $user['vip_level'],
                'profile_photo'     => $user['profile_photo'],
                'referral_code'     => $user['referral_code'],
                'email_verified'    => (bool) $user['email_verified'],
            ];

            log_activity($pdo, (int) $user['id'], 'login');

            // Send login-alert email (informational, not required for login)
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            mail_login_alert($user['email'], $user['name'], $ip);

            // Redirect admin users to the admin panel
            if ($user['role'] === 'admin') {
                $_SESSION['admin'] = [
                    'id'    => (int) $user['id'],
                    'name'  => $user['name'],
                    'email' => $user['email'],
                ];
                redirect('/admin/index.php');
            }

            // Redirect to dashboard (email verification removed)
            redirect('/dashboard.php');
        }
    }
}

$pageTitle = 'Log in — payNex';
require __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
  <h1><i class="fa-solid fa-right-to-bracket" style="color:var(--green);"></i> Welcome back</h1>
  <p class="sub">Log in to your payNex account.</p>

  <?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:20px;">
      <i class="fa-solid fa-circle-exclamation"></i>
      <div>
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= BASE_URL ?>/login.php" novalidate>
    <?= csrf_field() ?>

    <div class="field">
      <label><i class="fa-solid fa-envelope"></i> Email</label>
      <input type="email" name="email" value="<?= e($email) ?>" required maxlength="190"
             placeholder="you@example.com" autofocus>
    </div>

    <div class="field">
      <label><i class="fa-solid fa-lock"></i> Password</label>
      <div style="position:relative;">
          <input type="password" name="password" id="login-password" required placeholder="Your password" style="padding-right:40px;">
          <button type="button" onclick="togglePass('login-password',this)" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--ink-soft);padding:6px;">
            <i class="fa-solid fa-eye"></i>
          </button>
        </div>
    </div>

    <div style="text-align:right; margin-bottom:16px;">
      <a href="<?= BASE_URL ?>/forgot_password.php" style="font-size:13px; color:var(--blue);">
        <i class="fa-solid fa-key"></i> Forgot password?
      </a>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-full">
        <i class="fa-solid fa-arrow-right-to-bracket"></i> Log in
      </button>
    </div>
  </form>

  <p class="form-note">New to payNex? <a href="<?= BASE_URL ?>/signup.php">Create an account</a></p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
