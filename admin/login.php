<?php
require_once __DIR__ . '/../config/config.php';

if (!empty($_SESSION['admin'])) {
    redirect('/admin/index.php');
}

$email = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (too_many_login_attempts($pdo, $email)) {
        $errors[] = 'Too many login attempts. Please wait 15 minutes and try again.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email AND role = 'admin' LIMIT 1");
        $stmt->execute([':email' => $email]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            record_login_attempt($pdo, $email);
            $errors[] = 'Incorrect email or password.';
        } else {
            clear_login_attempts($pdo, $email);
            session_regenerate_id(true);

            $_SESSION['admin'] = [
                'id'    => (int) $admin['id'],
                'name'  => $admin['name'],
                'email' => $admin['email'],
            ];

            log_activity($pdo, (int) $admin['id'], 'admin_login');
            redirect('/admin/index.php');
        }
    }
}

$pageTitle = 'Admin login — payNex';
require __DIR__ . '/../includes/header.php';
?>

<div class="auth-wrap">
  <h1>Admin login</h1>
  <p class="sub">Restricted access.</p>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= BASE_URL ?>/admin/login.php">
    <?= csrf_field() ?>
    <div class="field">
      <label>Email</label>
      <input type="email" name="email" value="<?= e($email) ?>" required autofocus>
    </div>
    <div class="field">
      <label>Password</label>
      <input type="password" name="password" required>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Log in</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
