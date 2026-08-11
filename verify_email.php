<?php
/**
 * verify_email.php — Email verification via OTP code.
 *
 * Flow:
 *   1. User signs up → OTP code stored in users.verification_code
 *   2. User is redirected here to enter the 6-digit code
 *   3. On successful verification, email_verified is set to 1
 *   4. User can also request a new OTP code (resend)
 *
 * Security:
 *   - Rate limited: max 10 failed attempts per IP per 15 min
 *   - OTP stored in DB (not session) for persistence
 *   - CSRF protected
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/mailer.php';

require_login();

$user = current_user();

// If already verified, redirect to dashboard
if (!empty($user['email_verified'])) {
    // Double-check from DB
    $check = $pdo->prepare('SELECT email_verified FROM users WHERE id = :id');
    $check->execute([':id' => $user['id']]);
    if ((bool)$check->fetchColumn()) {
        $_SESSION['user']['email_verified'] = 1;
        flash('success', 'Your email is already verified.');
        redirect('/dashboard.php');
    }
}

// Fetch user's full data
$uStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$uStmt->execute([':id' => $user['id']]);
$u = $uStmt->fetch();

$errors  = [];
$success = false;

// Rate limiting: max 10 failed verification attempts per IP per 15 minutes
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateCheck = $pdo->prepare(
    'SELECT COUNT(*) FROM activity_logs
     WHERE action LIKE "otp_verify_failed%"
       AND ip_address = :ip
       AND created_at > (NOW() - INTERVAL 15 MINUTE)'
);
$rateCheck->execute([':ip' => $ip]);
$failedAttempts = (int) $rateCheck->fetchColumn();
$tooManyAttempts = $failedAttempts >= 10;

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    // Resend OTP
    if ($action === 'resend') {
        $otp = generate_otp_code();
        $upd = $pdo->prepare('UPDATE users SET verification_code = :code WHERE id = :id');
        $upd->execute([':code' => $otp, ':id' => $user['id']]);

        mail_verification_otp($u['email'], $u['name'], $otp);

        // Reset rate limit counter on resend
        $pdo->prepare(
            'DELETE FROM activity_logs WHERE user_id = :uid AND action LIKE "otp_verify_failed%"'
        )->execute([':uid' => $user['id']]);

        log_activity($pdo, $user['id'], 'verification_otp_resent');
        flash('success', 'A new verification code has been sent to your email.');
        redirect('/verify_email.php');
    }

    // Verify OTP
    if ($action === 'verify') {
        if ($tooManyAttempts) {
            $errors[] = 'Too many failed attempts. Please wait 15 minutes or request a new code.';
        } else {
            $otp = trim($_POST['otp'] ?? '');

            if ($otp === '' || !preg_match('/^\d{6}$/', $otp)) {
                $errors[] = 'Please enter a valid 6-digit verification code.';
            } else {
                // Check the OTP (case-sensitive match)
                if ($u['verification_code'] === $otp) {
                    $pdo->prepare('UPDATE users SET email_verified = 1, verification_code = NULL WHERE id = :id')
                        ->execute([':id' => $user['id']]);

                    $_SESSION['user']['email_verified'] = 1;

                    log_activity($pdo, $user['id'], 'email_verified');
                    flash('success', 'Email verified! You now have full access to the platform.');
                    redirect('/dashboard.php');
                } else {
                    log_activity($pdo, $user['id'], 'otp_verify_failed');
                    $errors[] = 'Invalid verification code. Please try again or request a new code.';
                }
            }
        }
    }
}

$pageTitle = 'Verify Email — payNex';
require __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
  <h1><i class="fa-solid fa-shield-check" style="color:var(--green);"></i> Verify your email</h1>
  <p class="sub">
    We sent a 6-digit code to <strong><?= e($u['email']) ?></strong>.
    Enter it below to activate your account.
  </p>

  <?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:20px;">
      <i class="fa-solid fa-circle-exclamation"></i>
      <div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= BASE_URL ?>/verify_email.php" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="verify">

    <div class="field">
      <label><i class="fa-solid fa-qrcode"></i> Verification code</label>
      <div class="otp-input-wrap">
        <input type="text" name="otp" value="<?= e($_POST['otp'] ?? '') ?>"
               required maxlength="6" minlength="6"
               placeholder="000000"
               class="otp-input"
               autofocus
               inputmode="numeric"
               pattern="\d{6}"
               autocomplete="one-time-code">
      </div>
      <div class="input-hint">
        Enter the 6-digit code sent to your email.
        <?php if ($tooManyAttempts): ?>
          <span style="color:var(--red); display:block; margin-top:4px;">
            <i class="fa-solid fa-triangle-exclamation"></i>
            Too many failed attempts. Request a new code below.
          </span>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-full" <?= $tooManyAttempts ? 'disabled' : '' ?>>
        <i class="fa-solid fa-check-circle"></i> Verify email
      </button>
    </div>
  </form>

  <div style="text-align:center; margin-top:20px;">
    <form method="post" action="<?= BASE_URL ?>/verify_email.php" style="display:inline;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="resend">
      <button type="submit" class="btn btn-ghost btn-ghost-light" style="font-size:13.5px;">
        <i class="fa-solid fa-rotate"></i> Resend verification code
      </button>
    </form>
  </div>

  <p class="form-note">
    <a href="<?= BASE_URL ?>/logout.php" style="color:var(--red);">Log out</a>
  </p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
