<?php
/**
 * signup.php — New user registration.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/mailer.php';

if (is_logged_in()) redirect('/dashboard.php');

$refCode = trim($_GET['ref'] ?? '');
$name = '';
$email = '';
$usdt = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $usdt = trim($_POST['usdt_address'] ?? '');
    $refCode = trim($_POST['ref_code'] ?? '');

    if ($name === '' || mb_strlen($name) > 120) $errors[] = 'Please enter your full name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (!validate_email_domain($email)) {
        $domain = substr(strrchr($email, '@'), 1);
        $errors[] = 'Invalid email — domain "' . e($domain) . '" does not exist.';
    }
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';
    if ($usdt !== '' && !validate_usdt_trc20_address($usdt)) $errors[] = 'Invalid USDT address format.';

    if (!$errors) {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = :email');
        $check->execute([':email' => $email]);
        if ($check->fetch()) $errors[] = 'An account with that email already exists.';
    }

    $referrerId = null;
    if ($refCode !== '') {
        $refStmt = $pdo->prepare('SELECT id FROM users WHERE referral_code = :code');
        $refStmt->execute([':code' => $refCode]);
        $referrer = $refStmt->fetch();
        $referrerId = $referrer ? (int) $referrer['id'] : null;
        if (!$referrer) $errors[] = 'Referral code not found.';
    }

    if (!$errors) {
        $newReferralCode = generate_referral_code($pdo);
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, usdt_trc20_address, referral_code, referred_by, email_verified)
             VALUES (:name, :email, :hash, "earner", :usdt, :refcode, :referred_by, 1)'
        );
        $stmt->execute([':name'=>$name, ':email'=>$email, ':hash'=>$hash, ':usdt'=>$usdt?:null, ':refcode'=>$newReferralCode, ':referred_by'=>$referrerId]);
        $newUserId = (int) $pdo->lastInsertId();

        if ($referrerId) {
            $refInsert = $pdo->prepare('INSERT IGNORE INTO referrals (referrer_id, referred_id) VALUES (:referrer, :referred)');
            $refInsert->execute([':referrer'=>$referrerId, ':referred'=>$newUserId]);
        }

        log_activity($pdo, $newUserId, 'account_created');

        // Auto-login the user temporarily to show success page
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'=>$newUserId, 'name'=>$name, 'email'=>$email, 'role'=>'earner',
            'status'=>'active', 'vip_level'=>null, 'profile_photo'=>null,
            'referral_code'=>$newReferralCode, 'email_verified'=>true,
        ];

        // Redirect to success page
        redirect('/signup_success.php');
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
      <div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= BASE_URL ?>/signup.php" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="ref_code" value="<?= e($refCode) ?>">

    <div class="field">
      <label><i class="fa-solid fa-id-card"></i> Full name</label>
      <input type="text" name="name" value="<?= e($name) ?>" required maxlength="120" placeholder="Your full name" autofocus>
    </div>

    <div class="field">
      <label><i class="fa-solid fa-envelope"></i> Email</label>
      <input type="email" name="email" value="<?= e($email) ?>" required maxlength="190" placeholder="you@example.com">
    </div>

    <div class="form-row">
      <div class="field">
        <label><i class="fa-solid fa-lock"></i> Password</label>
        <input type="password" name="password" required minlength="8" placeholder="Min. 8 characters">
      </div>
      <div class="field">
        <label><i class="fa-solid fa-lock"></i> Confirm password</label>
        <input type="password" name="confirm_password" required minlength="8" placeholder="Repeat password">
      </div>
    </div>

    <hr style="border:none; border-top:1px solid var(--paper-line); margin:20px 0;">
    <p style="font-size:13px; color:var(--ink-soft); margin-bottom:14px;">
      <i class="fa-solid fa-circle-info"></i> Add your USDT deposit address (optional, can update later).
    </p>

    <div class="field">
      <label><i class="fa-solid fa-circle-dollar-to-slot" style="color:#26a17b;"></i> USDT – TRC20 address</label>
      <input type="text" name="usdt_address" value="<?= e($usdt) ?>" placeholder="Your USDT TRC-20 wallet address" maxlength="100">
    </div>

    <div class="field">
      <label><i class="fa-solid fa-link"></i> Referral code <span style="font-weight:400;">(optional)</span></label>
      <input type="text" name="ref_code" value="<?= e($refCode) ?>" placeholder="e.g. AB3XKZQ7" maxlength="16" style="text-transform:uppercase;">
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
