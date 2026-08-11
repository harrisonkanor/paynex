<?php
/**
 * reset_password.php — Set a new password using a reset token.
 *
 * Flow:
 *   1. User clicks the link from email (contains token)
 *   2. Validate the token (exists, not expired, not used)
 *   3. Show new password form
 *   4. Hash and save the new password, mark token as used
 */
require_once __DIR__ . '/config/config.php';

// Redirect already-logged-in users
if (is_logged_in()) {
    redirect('/dashboard.php');
}

$token    = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errors   = [];
$success  = false;
$validToken = false;

// Validate the token before showing the form
if ($token !== '') {
    $stmt = $pdo->prepare(
        'SELECT prt.*, u.email, u.name
         FROM password_reset_tokens prt
         JOIN users u ON u.id = prt.user_id
         WHERE prt.token = :token AND prt.used = 0 AND prt.expires_at > NOW()'
    );
    $stmt->execute([':token' => $token]);
    $resetData = $stmt->fetch();

    if ($resetData) {
        $validToken = true;
    } else {
        $errors[] = 'Invalid or expired reset link. Please request a new one.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    verify_csrf();

    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        // Hash and update password
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
            ->execute([':hash' => $hash, ':id' => $resetData['user_id']]);

        // Mark token as used
        $pdo->prepare('UPDATE password_reset_tokens SET used = 1 WHERE id = :id')
            ->execute([':id' => $resetData['id']]);

        log_activity($pdo, (int)$resetData['user_id'], 'password_reset_completed');

        $success = true;
    }
}

$pageTitle = 'Reset Password — payNex';
require __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
  <h1><i class="fa-solid fa-lock-open" style="color:var(--green);"></i> Reset your password</h1>

  <?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:20px;">
      <i class="fa-solid fa-circle-exclamation"></i>
      <div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:20px;">
      <i class="fa-solid fa-circle-check"></i>
      <div>
        <div>Your password has been reset successfully!</div>
      </div>
    </div>
    <div style="text-align:center; margin-top:16px;">
      <a href="<?= BASE_URL ?>/login.php" class="btn btn-primary">
        <i class="fa-solid fa-right-to-bracket"></i> Log in with new password
      </a>
    </div>
  <?php elseif ($validToken): ?>
    <p class="sub">Enter your new password for <strong><?= e($resetData['email']) ?></strong>.</p>

    <form method="post" action="<?= BASE_URL ?>/reset_password.php" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">

      <div class="field">
        <label><i class="fa-solid fa-lock"></i> New password</label>
        <input type="password" name="password" required minlength="8"
               placeholder="Min. 8 characters" autofocus>
      </div>

      <div class="field">
        <label><i class="fa-solid fa-lock"></i> Confirm password</label>
        <input type="password" name="confirm_password" required minlength="8"
               placeholder="Repeat new password">
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-full">
          <i class="fa-solid fa-check"></i> Reset password
        </button>
      </div>
    </form>
  <?php else: ?>
    <p class="sub" style="color:var(--red);">
      This reset link is invalid, expired, or has already been used.
    </p>
    <div style="text-align:center; margin-top:16px;">
      <a href="<?= BASE_URL ?>/forgot_password.php" class="btn btn-primary">
        <i class="fa-solid fa-key"></i> Request a new reset link
      </a>
    </div>
  <?php endif; ?>

  <p class="form-note">
    <a href="<?= BASE_URL ?>/login.php">Back to login</a>
  </p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
