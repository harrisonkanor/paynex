<?php
/**
 * signup.php — New user registration.
 *
 * Features:
 *   - USDT-TRC20 address collected at signup
 *   - Referral code / link support (?ref=CODE)
 *   - Unique referral code generated for the new user
 *   - Welcome email sent after registration
 *   - Email domain validation (DNS MX check via validate_email_domain())
 *   - Crypto address format validation (client + server side)
 *   - CSRF protection on all form submissions
 *   - Email verification removed (auto-verified on signup)
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/mailer.php';

// Redirect already-logged-in users away from the signup page
if (is_logged_in()) {
    redirect('/dashboard.php');
}

// Pre-fill role and referral code from URL parameters
$refCode = trim($_GET['ref'] ?? '');
$name    = '';
$email   = '';
$usdt    = '';
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- CSRF check must be first thing in every POST handler ---
    verify_csrf();

    // Sanitise all inputs
    $name    = trim($_POST['name']            ?? '');
    $email   = trim($_POST['email']           ?? '');
    $password = $_POST['password']            ?? '';
    $confirm  = $_POST['confirm_password']    ?? '';
    $usdt     = trim($_POST['usdt_address']   ?? '');
    $refCode  = trim($_POST['ref_code']       ?? '');

    // --- Validation ---
    if ($name === '' || mb_strlen($name) > 120) {
        $errors[] = 'Please enter your full name (max 120 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (!validate_email_domain($email)) {
        // DNS MX record check ensures the email domain actually exists
        $domain = substr(strrchr($email, '@'), 1);
        $errors[] = 'Invalid email address — the domain "' . e($domain) . '" does not appear to exist. Please use a valid email provider.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    // Validate USDT address if provided
    if ($usdt !== '' && !validate_usdt_trc20_address($usdt)) {
        $errors[] = 'Invalid USDT (TRC-20) address format. Tron addresses start with T and are 34 characters.';
    }

    // Check for duplicate email (prepared statement protects against SQLi)
    if (!$errors) {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = :email');
        $check->execute([':email' => $email]);
        if ($check->fetch()) {
            $errors[] = 'An account with that email already exists.';
        }
    }

    // Resolve referrer (if a valid ref code was provided)
    $referrerId = null;
    if ($refCode !== '') {
        $refStmt = $pdo->prepare('SELECT id FROM users WHERE referral_code = :code');
        $refStmt->execute([':code' => $refCode]);
        $referrer = $refStmt->fetch();
        $referrerId = $referrer ? (int) $referrer['id'] : null;
        if (!$referrer) {
            $errors[] = 'Referral code not found — you can leave it blank.';
        }
    }

    if (!$errors) {
        // Generate a unique referral code for this new user
        $newReferralCode = generate_referral_code($pdo);

        // Hash password with bcrypt (secure by default in PHP 7+)
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            'INSERT INTO users
               (name, email, password_hash, role, usdt_trc20_address,
                referral_code, referred_by, email_verified)
             VALUES
               (:name, :email, :hash, "earner", :usdt, :refcode, :referred_by, 1)'
        );
        $stmt->execute([
            ':name'        => $name,
            ':email'       => $email,
            ':hash'        => $hash,
            ':usdt'        => $usdt ?: null,
            ':refcode'     => $newReferralCode,
            ':referred_by' => $referrerId,
        ]);
        $newUserId = (int) $pdo->lastInsertId();

        // Record the referral relationship
        if ($referrerId) {
            $refInsert = $pdo->prepare(
                'INSERT IGNORE INTO referrals (referrer_id, referred_id)
                 VALUES (:referrer, :referred)'
            );
            $refInsert->execute([':referrer' => $referrerId, ':referred' => $newUserId]);
        }

        // Log the event to the auditable activity log
        log_activity($pdo, $newUserId, 'account_created');

        // Send a welcome email (no OTP)
        $referralLink = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
                      . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                      . BASE_URL . '/signup.php?ref=' . $newReferralCode;
        
        // Send welcome email (simple version without OTP)
        $subject = 'Welcome to payNex!';
        $message = "Hello $name,\n\n";
        $message .= "Welcome to payNex! Your account has been created successfully.\n\n";
        $message .= "Your referral code: $newReferralCode\n";
        $message .= "Share this link to invite others: $referralLink\n\n";
        $message .= "You can now log in and start earning!\n\n";
        $message .= "Best regards,\nThe payNex Team";
        
        $headers = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . "\r\n";
        $headers .= 'Reply-To: ' . MAIL_FROM_ADDRESS . "\r\n";
        $headers .= 'Content-Type: text/plain; charset=UTF-8\r\n';
        
        @mail($email, $subject, $message, $headers);

        // Redirect to login page with success message
        flash('success', 'Account created successfully! Please log in with your new account.');
        redirect('/login.php');
    }
}

$pageTitle = 'Sign up — payNex';
require __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap" style="max-width:560px;">
  <h1><i class="fa-solid fa-user-plus" style="color:var(--green);"></i> Create your account</h1>
  <p class="sub">Join payNex and start earning — free forever.</p>

  <?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:20px;">
      <i class="fa-solid fa-circle-exclamation"></i>
      <div>
        <?php foreach ($errors as $err): ?>
          <div><?= e($err) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= BASE_URL ?>/signup.php" novalidate>
    <?= csrf_field() ?>
    <!-- Carry the ref code through the form submission -->
    <input type="hidden" name="ref_code" value="<?= e($refCode) ?>">

    <!-- Personal info -->
    <div class="field">
      <label><i class="fa-solid fa-id-card"></i> Full name</label>
      <input type="text" name="name" value="<?= e($name) ?>" required maxlength="120"
             placeholder="Your full name" autofocus>
    </div>

    <div class="field">
      <label><i class="fa-solid fa-envelope"></i> Email</label>
      <input type="email" name="email" value="<?= e($email) ?>" required maxlength="190"
             placeholder="you@example.com" class="email-input"
             data-validate-email="true">
    </div>

    <div class="form-row">
      <div class="field">
        <label><i class="fa-solid fa-lock"></i> Password</label>
        <div style="position:relative;">
          <input type="password" name="password" id="signup-password" required minlength="8" placeholder="Min. 8 characters" style="padding-right:40px;">
          <button type="button" onclick="togglePass('signup-password',this)" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--ink-soft);padding:6px;">
            <i class="fa-solid fa-eye"></i>
          </button>
        </div>
      </div>
      <div class="field">
        <label><i class="fa-solid fa-lock"></i> Confirm password</label>
        <div style="position:relative;">
          <input type="password" name="confirm_password" id="signup-confirm" required minlength="8" placeholder="Repeat password" style="padding-right:40px;">
          <button type="button" onclick="togglePass('signup-confirm',this)" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--ink-soft);padding:6px;">
            <i class="fa-solid fa-eye"></i>
          </button>
        </div>
      </div>
    </div>

    <!-- Crypto payout addresses -->
    <hr style="border:none; border-top:1px solid var(--paper-line); margin:20px 0;">
    <p style="font-size:13px; color:var(--ink-soft); margin-bottom:14px;">
      <i class="fa-solid fa-circle-info"></i>
      Add your USDT deposit address now so withdrawals are ready when you earn.
      You can update this later in your profile.
    </p>

    <div class="field">
      <label><i class="fa-solid fa-circle-dollar-to-slot" style="color:#26a17b;"></i> USDT – TRC20 address</label>
      <input type="text" name="usdt_address" value="<?= e($usdt) ?>"
             placeholder="Your USDT TRC-20 wallet address" maxlength="100"
             class="crypto-input" data-crypto-type="usdt">
      <div class="input-hint crypto-hint" id="usdt-hint">
        <span class="hint-default">Tron network (TRC-20) only. Starts with T, 34 characters.</span>
        <span class="hint-valid" style="display:none;color:var(--green);"><i class="fa-solid fa-check-circle"></i> Valid USDT (TRC-20) address</span>
        <span class="hint-invalid" style="display:none;color:var(--red);"><i class="fa-solid fa-exclamation-circle"></i> Invalid USDT address format</span>
      </div>
    </div>

    <!-- Referral code (optional) -->
    <div class="field">
      <label><i class="fa-solid fa-link"></i> Referral code <span style="font-weight:400;">(optional)</span></label>
      <input type="text" name="ref_code" value="<?= e($refCode) ?>"
             placeholder="e.g. AB3XKZQ7" maxlength="16" style="text-transform:uppercase;">
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">
        <i class="fa-solid fa-rocket"></i> Create free account
      </button>
    </div>
  </form>

  <p class="form-note">Already have an account? <a href="<?= BASE_URL ?>/login.php">Log in</a></p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
