<?php
/**
 * report_issue.php — User-facing form to report an issue.
 */
require_once __DIR__ . '/config/config.php';
require_login();
require_email_verified($pdo);

$user = current_user();
if ($user['role'] === 'admin') redirect('/admin/index.php');

$errors = [];
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    $subject = trim($_POST['subject'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if ($subject === '' || mb_strlen($subject) > 200) {
        $errors[] = 'Please enter a subject (max 200 characters).';
    }
    if ($description === '' || mb_strlen($description) > 5000) {
        $errors[] = 'Please describe the issue (max 5000 characters).';
    }
    
    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO reported_issues (user_id, subject, description) VALUES (:uid, :subj, :desc)'
        );
        $stmt->execute([':uid' => $user['id'], ':subj' => $subject, ':desc' => $description]);
        log_activity($pdo, $user['id'], 'issue_reported: #' . $pdo->lastInsertId());
        $ok = true;
    }
}

$pageTitle = 'Report an issue — payNex';
require __DIR__ . '/includes/header.php';
?>

<div class="page-wrap" style="max-width:640px;">
  <div class="page-head">
    <h1><i class="fa-solid fa-triangle-exclamation" style="color:var(--amber);"></i> Report an issue</h1>
    <p>Having a problem? Let us know and we'll look into it.</p>
  </div>

  <?php if ($ok): ?>
    <div class="card" style="text-align:center;padding:40px;">
      <i class="fa-solid fa-circle-check" style="font-size:48px;color:var(--green);display:block;margin-bottom:16px;"></i>
      <h2>Issue reported</h2>
      <p class="text-muted">We've received your report. Our team will review it and get back to you.</p>
      <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-primary mt-12">
        <i class="fa-solid fa-arrow-left"></i> Back to dashboard
      </a>
    </div>
  <?php else: ?>
    <?php if ($errors): ?>
      <div class="alert alert-error" style="margin-bottom:20px;">
        <i class="fa-solid fa-circle-exclamation"></i>
        <div><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
      </div>
    <?php endif; ?>

    <div class="card">
      <form method="post" action="<?= BASE_URL ?>/report_issue.php" novalidate>
        <?= csrf_field() ?>
        <div class="field">
          <label><i class="fa-solid fa-heading"></i> Subject</label>
          <input type="text" name="subject" required maxlength="200"
                 placeholder="e.g. Task not crediting, Withdrawal issue, Bug report...">
        </div>
        <div class="field">
          <label><i class="fa-solid fa-pencil"></i> Description</label>
          <textarea name="description" required maxlength="5000" rows="6"
                    placeholder="Describe the issue in detail — what happened, what you expected, any error messages..."></textarea>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-paper-plane"></i> Submit report
          </button>
        </div>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
