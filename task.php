<?php
/**
 * task.php — Individual task view + submission.
 */
require_once __DIR__ . '/config/config.php';
require_login();

$user = current_user();
if ($user['role'] === 'admin') redirect('/admin/index.php');

$taskId = (int) ($_GET['id'] ?? 0);
if ($taskId <= 0) redirect('/tasks.php');

$uStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$uStmt->execute([':id' => $user['id']]);
$u = $uStmt->fetch();

$vipLevel = (int) ($u['vip_level'] ?? 0);

// Fetch task
$tStmt = $pdo->prepare('SELECT * FROM tasks WHERE id = :id');
$tStmt->execute([':id' => $taskId]);
$task = $tStmt->fetch();
if (!$task) { flash('error', 'Task not found.'); redirect('/tasks.php'); }

// Fetch claim
$claimStmt = $pdo->prepare('SELECT * FROM task_claims WHERE task_id = :tid AND user_id = :uid');
$claimStmt->execute([':tid' => $taskId, ':uid' => $user['id']]);
$claim = $claimStmt->fetch();

if (!$claim) { flash('error', 'You have not claimed this task.'); redirect('/tasks.php'); }

$isExpired = strtotime($claim['expires_at']) < time();

// Fetch submission
$subStmt = $pdo->prepare('SELECT * FROM task_submissions WHERE task_id = :tid AND user_id = :uid');
$subStmt->execute([':tid' => $taskId, ':uid' => $user['id']]);
$existing = $subStmt->fetch();

$errors = [];

// Handle submission
if (!$existing && !$isExpired && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    $proof      = trim($_POST['proof_text'] ?? '');
    $spinResult = trim($_POST['spin_result'] ?? '');
    
    if ($proof === '' && $task['type'] !== 'spin_wheel') {
        $errors[] = 'Please provide proof of completion.';
    }
    
    if (!$errors) {
        $actualReward = (float) $task['reward'];
        
        $pdo->beginTransaction();
        try {
            // Handle screenshot upload with server-side MIME validation
            $screenshotPath = null;
            if (!empty($_FILES['screenshot']['name'])) {
                // Use finfo for server-side MIME type detection (not client-provided type)
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($_FILES['screenshot']['tmp_name']);
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                
                if (!in_array($mimeType, $allowedTypes, true)) {
                    $errors[] = 'Invalid screenshot type. Allowed: JPEG, PNG, GIF, WebP.';
                } elseif ($_FILES['screenshot']['size'] > 5 * 1024 * 1024) {
                    $errors[] = 'Screenshot must be under 5MB.';
                } else {
                    // Get extension from MIME type, not filename
                    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mimeType];
                    $filename = 'screenshot_' . $user['id'] . '_' . $taskId . '_' . time() . '.' . $ext;
                    $dest = __DIR__ . '/uploads/screenshots/' . $filename;
                    
                    // Create directory if it doesn't exist
                    if (!is_dir(__DIR__ . '/uploads/screenshots/')) {
                        mkdir(__DIR__ . '/uploads/screenshots/', 0755, true);
                    }
                    
                    if (move_uploaded_file($_FILES['screenshot']['tmp_name'], $dest)) {
                        $screenshotPath = 'uploads/screenshots/' . $filename;
                    }
                }
            }
            
            if (!$errors) {
                $ins = $pdo->prepare('INSERT INTO task_submissions (task_id, user_id, proof_text, screenshot_path, spin_result, status, reviewed_at) VALUES (:tid, :uid, :proof, :sspath, :spin, "pending", NULL)');
                $ins->execute([':tid' => $taskId, ':uid' => $user['id'], ':proof' => $proof, ':sspath' => $screenshotPath, ':spin' => $spinResult ?: null]);
                
                // Auto-approve spin wheel tasks
                if ($task['type'] === 'spin_wheel') {
                    $pdo->prepare('UPDATE task_submissions SET status = "approved", reviewed_at = NOW() WHERE task_id = :tid AND user_id = :uid')
                        ->execute([':tid' => $taskId, ':uid' => $user['id']]);
                    $pdo->prepare('UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :uid')->execute([':amt' => $actualReward, ':uid' => $user['id']]);
                    $pdo->prepare('INSERT INTO wallet_transactions (user_id, type, amount, description) VALUES (:uid, "credit", :amt, :desc)')->execute([':uid' => $user['id'], ':amt' => $actualReward, ':desc' => 'Task completed: ' . $task['title']]);
                }
                
                $pdo->prepare('UPDATE tasks SET slots_filled = slots_filled + 1, status = IF(slots_filled + 1 >= slots, "closed", status) WHERE id = :tid')->execute([':tid' => $taskId]);
                $pdo->commit();
                
                log_activity($pdo, $user['id'], "task_submitted: #{$taskId}");
                flash('success', 'Task submitted! Your proof is under review. You will be credited within 5 minutes once approved.');
                redirect('/tasks.php');
            } else {
                $pdo->rollBack();
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Task auto-approve error: ' . $e->getMessage());
            $errors[] = 'Could not process your submission. Please try again.';
        }
    }
}

$pageTitle = htmlspecialchars($task['title']) . ' — payNex';
require __DIR__ . '/includes/header.php';
?>

<div class="page-wrap" style="max-width:720px;">
  <div class="page-head">
    <h1><i class="fa-solid fa-clipboard-list" style="color:var(--green);"></i> <?= e($task['title']) ?></h1>
    <p><?= e(ucfirst($task['type'])) ?> · <?= (int)$task['time_limit_minutes'] ?> min time limit · <?= e(money((float)$task['reward'])) ?> reward</p>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:20px;"><i class="fa-solid fa-circle-exclamation"></i><div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div></div>
  <?php endif; ?>

  <?php if ($existing): ?>
    <div class="card">
      <h2><i class="fa-solid fa-check-circle" style="color:var(--green);"></i> Already submitted</h2>
      <p>Your submission is <strong><?= e(ucfirst($existing['status'])) ?></strong>.</p>
      <?php if ($existing['status'] === 'pending'): ?>
        <p class="text-muted">Please wait for review.</p>
      <?php endif; ?>
    </div>
  <?php elseif ($isExpired): ?>
    <div class="card">
      <h2><i class="fa-solid fa-clock" style="color:var(--orange);"></i> Time expired</h2>
      <p>You ran out of time to complete this task.</p>
    </div>
  <?php else: ?>
    <div class="card">
      <h2><i class="fa-solid fa-file-pen"></i> Submit your proof</h2>
      <form method="post" action="<?= BASE_URL ?>/task.php?id=<?= $taskId ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        
        <div class="field">
          <label>Proof of completion</label>
          <textarea name="proof_text" placeholder="Describe what you did, paste a link, or any proof that shows you completed the task..."><?= e($_POST['proof_text'] ?? '') ?></textarea>
        </div>
        
        <div class="field">
          <label>Screenshot (optional, max 5MB)</label>
          <input type="file" name="screenshot" accept="image/jpeg,image/png,image/gif,image/webp">
        </div>
        
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Submit for review</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
