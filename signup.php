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
    verify_csrf();

    $name     = trim($_POST['name']             ?? '');
    $email    = trim($_POST['email']            ?? '');
    $password = $_POST['password']              ?? '';
    $confirm  = $_POST['confirm_password']      ?? '';
    $usdt     = trim($_POST['usdt_address']     ?? '');
    $refCode  = trim($_POST['ref_code']         ?? '');

    if ($name === '' || mb_strlen($name) > 120) {
        $errors[] = 'Please enter your full name.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (!validate_email_domain($email)) {
        $domain = substr(strrchr($email, "@"), 1);
        $errors[] = "Email domain \"{$domain}\" could not be verified. Check for typos or use another email.";
    } elseif (email_exists($pdo, $email)) {
        $errors[] = 'This email is already registered.';
    }

    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    // Optional USDT address validation
    if ($usdt !== '' && !preg_match('/^T[A-Za-z1-9]{33}$/', $usdt)) {
        $errors[] = 'Invalid USDT TRC-20 address format. It should start with T and be 34 characters long.';
    }

    // Referral lookup
    $referrerId = null;
    if ($refCode !== '') {
        $refStmt = $pdo->prepare('SELECT id FROM users WHERE referral_code = :rc LIMIT 1');
        $refStmt->execute([':rc' => $refCode]);
        $referrer = $refStmt->fetch();
        if (!$referrer) {
            $errors[] = 'Invalid referral code.';
        } else {
            $referrerId = $referrer['id'];
        }
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Generate unique referral code for the new user
        $newReferralCode = strtoupper(bin2hex(random_bytes(4)));

        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, usdt_trc20_address, referral_code, referred_by, email_verified)
             VALUES (:name, :email, :hash, "earner", :usdt, :refcode, :referred_by, 1)'
        );
        $stmt->execute([
            ':name'        => $name,
            ':email'       => $email,
            ':hash'        => $hash,
            ':usdt'        => $usdt ?: null,
            ':refcode'     => $newReferralCode,
            ':referred_by' => $referrerId,
        ]);

        $newUserId = $pdo->lastInsertId();

        // Credit referrer's bonus if applicable
        if ($referrerId) {
            $bonus = random_int(11, 29);
            $pdo->prepare('UPDATE users SET total_referrals = total_referrals + 1, wallet_balance = wallet_balance + :bonus WHERE id = :id')
                ->execute([':bonus' => $bonus, ':id' => $referrerId]);
            $pdo->prepare('INSERT INTO wallet_transactions (user_id, type, amount, description) VALUES (:uid, "credit", :bonus, "Referral bonus — new user signup")')
                ->execute([':uid' => $referrerId, ':bonus' => $bonus]);
        }

        // Log activity
        log_activity($pdo, (int)$newUserId, 'user_signup', "New earner account created: {$email}");

        // Send a welcome email (no OTP)
        $referralLink = BASE_URL . "/signup.php?ref={$newReferralCode}";
        mail_welcome_no_otp($email, $name, $newReferralCode, $referralLink);

        // Redirect to congratulations page
        redirect('/signup_success.php');
    }
}

$pageTitle = 'Create account — payNex';
require __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-logo">
      <img src="<?= BASE_URL ?>/assets/images/logo.png" alt="payNex logo" onerror="this.style.display='none'">
      <h1>Create your <span class="text-green">payNex</span> account</h1>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-error">
        <i class="fa-solid fa-circle-exclamation"></i>
        <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
      </div>
    <?php endif; ?>

    <?php if ($refCode): ?>
      <div class="alert alert-success" style="font-size:13px;">
        <i class="fa-solid fa-user-plus"></i>
        You were referred! Referral code <strong><?= htmlspecialchars($refCode) ?></strong> applied.
      </div>
    <?php endif; ?>

    <form method="post" action="<?= BASE_URL ?>/signup.php" novalidate>
      <?= csrf_field() ?>

      <div class="field">
        <label><i class="fa-solid fa-user"></i> Full name</label>
        <input type="text" name="name" value="<?= htmlspecialchars($name) ?>" required placeholder="John Doe" maxlength="120">
      </div>

      <div class="field">
        <label><i class="fa-solid fa-envelope"></i> Email address</label>
        <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required placeholder="you@example.com">
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

      <hr style="border:none; border-top:1px solid var(--paper-line); margin:20px 0;">
      <p style="font-size:13px; color:var(--ink-soft); margin-bottom:14px;">
        <i class="fa-solid fa-circle-info"></i> Add your USDT deposit address (optional, can update later).
      </p>

      <div class="field">
        <label><i class="fa-solid fa-wallet"></i> USDT TRC-20 address</label>
        <input type="text" name="usdt_address" value="<?= htmlspecialchars($usdt) ?>" placeholder="T... (34 chars)" maxlength="34">
      </div>

      <div class="field">
        <label><i class="fa-solid fa-ticket"></i> Referral code (optional)</label>
        <input type="text" name="ref_code" value="<?= htmlspecialchars($refCode) ?>" placeholder="Enter referral code" maxlength="20">
      </div>

      <div class="form-row" style="margin-top:4px;">
        <div class="field" style="flex:1;">
          <label style="font-size:12px; color:var(--ink-soft);">
            By creating an account you agree to the <a href="<?= BASE_URL ?>/terms.php" style="color:var(--green);">Terms of Service</a> and <a href="<?= BASE_URL ?>/privacy.php" style="color:var(--green);">Privacy Policy</a>.
          </label>
        </div>
      </div>

      <button type="submit" class="btn btn-green btn-full">
        <i class="fa-solid fa-user-plus"></i> Create account
      </button>

      <p style="text-align:center; margin-top:16px; font-size:14px; color:var(--ink-soft);">
        Already have an account? <a href="<?= BASE_URL ?>/login.php" style="color:var(--green); font-weight:600;">Log in</a>
      </p>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
