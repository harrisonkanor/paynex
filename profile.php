<?php
/**
 * profile.php — User profile editor.
 */
require_once __DIR__ . '/config/config.php';
require_login();

$user   = current_user();
$errors = [];
$ok     = [];

$uStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$uStmt->execute([':id' => $user['id']]);
$u = $uStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    /* ---- 1. Update basic info (name + USDT wallet) ---- */
    if ($action === 'update_info') {
        $name = trim($_POST['name'] ?? '');
        $usdt = trim($_POST['usdt_address'] ?? '');

        if ($name === '' || mb_strlen($name) > 120) {
            $errors[] = 'Name must be between 1 and 120 characters.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare('UPDATE users SET name = :name, usdt_trc20_address = :usdt WHERE id = :id');
            $stmt->execute([':name' => $name, ':usdt' => $usdt ?: null, ':id' => $user['id']]);
            $_SESSION['user']['name'] = $name;
            log_activity($pdo, $user['id'], 'profile_info_updated');
            $ok[] = 'Profile information updated.';
        }
    }

    /* ---- 2. Change password ---- */
    elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $u['password_hash'])) $errors[] = 'Current password is incorrect.';
        if (strlen($new) < 8) $errors[] = 'New password must be at least 8 characters.';
        if ($new !== $confirm) $errors[] = 'New passwords do not match.';

        if (!$errors) {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')->execute([':hash' => $hash, ':id' => $user['id']]);
            log_activity($pdo, $user['id'], 'password_changed');
            $ok[] = 'Password changed successfully.';
        }
    }

    /* ---- 3. Upload profile photo ---- */
    elseif ($action === 'upload_photo') {
        $file = $_FILES['profile_photo'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'No file received. Please choose a photo and try again.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($mimeType, $allowed, true)) {
                $errors[] = 'Only JPEG, PNG, GIF, and WEBP images are allowed.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Profile photo must be smaller than 2 MB.';
            } else {
                $ext = ['image/jpeg' => 'jpg','image/png' => 'png','image/gif' => 'gif','image/webp' => 'webp'][$mimeType];
                $filename = 'photo_' . $user['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $destDir = __DIR__ . '/uploads/';
                $dest = $destDir . $filename;
                if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                if (!move_uploaded_file($file['tmp_name'], $dest)) {
                    $errors[] = 'Upload failed. Please try again.';
                } else {
                    if ($u['profile_photo'] && file_exists($destDir . $u['profile_photo'])) unlink($destDir . $u['profile_photo']);
                    $pdo->prepare('UPDATE users SET profile_photo = :photo WHERE id = :id')->execute([':photo' => $filename, ':id' => $user['id']]);
                    $_SESSION['user']['profile_photo'] = $filename;
                    log_activity($pdo, $user['id'], 'profile_photo_uploaded');
                    $ok[] = 'Profile photo updated.';
                }
            }
        }
    }

    $uStmt->execute([':id' => $user['id']]);
    $u = $uStmt->fetch();
}

$pageTitle = 'Edit profile — payNex';
require __DIR__ . '/includes/header.php';
?>

<div class="page-wrap" style="max-width:720px;">
  <div class="page-head">
    <h1><i class="fa-solid fa-pen-to-square" style="color:var(--green);"></i> Edit profile</h1>
    <p>Update your name, USDT wallet address, password, and profile photo.</p>
  </div>

  <?php if ($ok): ?>
    <div class="alert alert-success" style="margin-bottom:20px;"><i class="fa-solid fa-circle-check"></i><div><?php foreach ($ok as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:20px;"><i class="fa-solid fa-circle-exclamation"></i><div><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div></div>
  <?php endif; ?>

  <!-- PROFILE PHOTO -->
  <div class="card">
    <h2><i class="fa-solid fa-camera"></i> Profile photo</h2>
    <form method="post" action="<?= BASE_URL ?>/profile.php" enctype="multipart/form-data" id="photo-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="upload_photo">
      <div class="profile-photo-wrap">
        <?php if ($u['profile_photo']): ?>
          <img class="profile-avatar-large" src="<?= BASE_URL ?>/uploads/<?= e($u['profile_photo']) ?>" alt="Your profile photo" id="avatar-preview">
        <?php else: ?>
          <div class="profile-avatar-placeholder" id="avatar-preview-placeholder"><i class="fa-solid fa-user"></i></div>
        <?php endif; ?>
        <div>
          <p style="font-size:13.5px; color:var(--ink-soft); margin-bottom:12px;">JPEG, PNG, GIF or WEBP · max 2 MB</p>
          <label for="photo_upload" class="profile-upload-btn"><i class="fa-solid fa-upload"></i> Choose photo</label>
          <p style="font-size:12px; color:var(--ink-soft); margin-top:8px;">Photo uploads automatically once selected.</p>
        </div>
      </div>
      <input type="file" id="photo_upload" name="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp" onchange="this.form.submit()">
    </form>
  </div>

  <!-- BASIC INFO (name + USDT wallet) -->
  <div class="card">
    <h2><i class="fa-solid fa-id-card"></i> Personal information</h2>
    <form method="post" action="<?= BASE_URL ?>/profile.php" novalidate>
      <?= csrf_field() ?><input type="hidden" name="action" value="update_info">

      <div class="field">
        <label><i class="fa-solid fa-user"></i> Display name</label>
        <input type="text" name="name" value="<?= e($u['name']) ?>" maxlength="120" required placeholder="Your full name">
      </div>

      <hr style="border:none; border-top:1px solid var(--paper-line); margin:20px 0;">
      <p style="font-size:13px; color:var(--ink-soft); margin-bottom:14px;">
        <i class="fa-solid fa-circle-info"></i>
        This is your <strong>personal payout address</strong> — where withdrawals are sent when you request them.
      </p>

      <div class="field">
        <label><i class="fa-solid fa-circle-dollar-to-slot" style="color:#26a17b;"></i> USDT – TRC20 address</label>
        <input type="text" name="usdt_address" value="<?= e($u['usdt_trc20_address'] ?? '') ?>" maxlength="100" placeholder="Your USDT TRC-20 wallet address">
        <div class="input-hint">Tron network (TRC-20) only.</div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save changes</button>
      </div>
    </form>
  </div>

  <!-- CHANGE PASSWORD -->
  <div class="card">
    <h2><i class="fa-solid fa-lock"></i> Change password</h2>
    <form method="post" action="<?= BASE_URL ?>/profile.php" novalidate>
      <?= csrf_field() ?><input type="hidden" name="action" value="change_password">
      <div class="field">
        <label><i class="fa-solid fa-lock-open"></i> Current password</label>
        <input type="password" name="current_password" required placeholder="Your current password">
      </div>
      <div class="form-row">
        <div class="field">
          <label><i class="fa-solid fa-lock"></i> New password</label>
          <input type="password" name="new_password" required minlength="8" placeholder="Min. 8 characters">
        </div>
        <div class="field">
          <label><i class="fa-solid fa-lock"></i> Confirm new password</label>
          <input type="password" name="confirm_password" required minlength="8" placeholder="Repeat new password">
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-dark"><i class="fa-solid fa-key"></i> Change password</button>
      </div>
    </form>
  </div>

  <!-- Read-only account info -->
  <div class="card">
    <h2><i class="fa-solid fa-circle-info"></i> Account information</h2>
    <table class="data-table">
      <tr><td style="color:var(--ink-soft);">Email</td><td><?= e($u['email']) ?></td></tr>
      <tr><td style="color:var(--ink-soft);">Referral code</td><td class="text-mono" style="font-size:18px; letter-spacing:4px; color:var(--green);"><?= e($u['referral_code']) ?></td></tr>
      <tr><td style="color:var(--ink-soft);">Member since</td><td><?= e(date('F j, Y', strtotime($u['created_at']))) ?></td></tr>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
