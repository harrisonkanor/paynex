<?php
/**
 * forgot_password.php — Request a password reset link.
 *
 * Flow:
 *   1. User enters their email
 *   2. If email exists, generate a token and store it in password_reset_tokens
 *   3. Send reset link via email
 *   4. Show success message (always — don't reveal if email exists)
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/mailer.php';

// Redirect already-logged-in users
if (is_logged_in()) {
    redirect('/dashboard.php');
}

$errors = [];
$sent   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (!$errors) {
        // Find user by email
        $stmt = $pdo->prepare('SELECT id, name FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            // Delete any existing tokens for this user
            $del = $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :uid');
            $del->execute([':uid' => $user['id']]);

            // Generate a secure random token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+60 minutes'));

            $ins = $pdo->prepare(
                'INSERT INTO password_reset_tokens (user_id, token, expires_at)
                 VALUES (:uid, :token, :expires)'
            );
            $ins->execute([':uid' => $user['id'], ':token' => $token, ':expires' => $expiresAt]);

            // Send reset email
            $resetLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                       . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                       . BASE_URL . '/reset_password.php?token=' . $token;

            mail_password_reset($email, $user['name'], $resetLink);

            log_activity($pdo, (int)$user['id'], 'password_reset_requested');
        }

        // Always show success — prevents email enumeration
        $sent = true;
    }
}

$pageTitle = 'Forgot Password — payNex';
require __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
  <h1><i class="fa-solid fa-key" style="color:var(--green);"></i> Forgot your password?</h1>
  <p class="sub">Enter your email and we'll send you a reset link.</p>

  <?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:20px;">
      <i class="fa-solid fa-circle-exclamation"></i>
      <div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    </div>
  <?php endif; ?>

  <?php if ($sent): ?>
    <div class="alert alert-success" style="margin-bottom:20px;">
      <i class="fa-solid fa-circle-check"></i>
      <div>
        <div>If an account with that email exists, a password reset link has been sent.</div>
        <div style="margin-top:6px; font-size:13px;">Please check your inbox and spam folder.</div>
      </div>
    </div>
    <div style="text-align:center; margin-top:16px;">
      <a href="<?= BASE_URL ?>/login.php" class="btn btn-primary">
        <i class="fa-solid fa-arrow-left"></i> Back to login
      </a>
    </div>
  <?php else: ?>
    <form method="post" action="<?= BASE_URL ?>/forgot_password.php" novalidate>
      <?= csrf_field() ?>

      <div class="field">
        <label><i class="fa-solid fa-envelope"></i> Email</label>
        <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required maxlength="190"
               placeholder="you@example.com" autofocus>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-full">
          <i class="fa-solid fa-paper-plane"></i> Send reset link
        </button>
      </div>
    </form>

    <p class="form-note">
      <a href="<?= BASE_URL ?>/login.php">Back to login</a>
    </p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
