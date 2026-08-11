<?php
/**
 * admin/submissions.php — Review task submissions from earners.
 *
 * Admin can:
 *   - View all pending / approved / rejected submissions
 *   - Approve → reward is credited to the earner's wallet
 *   - Reject  → no credit; sets status = rejected
 *
 * Filter by status via ?status=pending (default).
 *
 * Security:
 *   - All DB queries use prepared statements
 *   - CSRF token on every approve/reject form
 *   - Wallet credit runs inside a DB transaction
 */
require_once __DIR__ . '/../config/config.php';
require_admin();

/* ---------------------------------------------------------------
 * Handle approve / reject POST
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $subId    = (int) ($_POST['submission_id'] ?? 0);
    $decision = ($_POST['decision'] ?? '') === 'approved' ? 'approved' : 'rejected';

    // Fetch the submission + task reward + earner info
    $stmt = $pdo->prepare(
        'SELECT ts.*, t.reward, t.title AS task_title, u.name AS earner_name
         FROM task_submissions ts
         JOIN tasks t ON t.id = ts.task_id
         JOIN users u ON u.id = ts.user_id
         WHERE ts.id = :id AND ts.status = "pending"'
    );
    $stmt->execute([':id' => $subId]);
    $sub = $stmt->fetch();

    if (!$sub) {
        flash('error', 'Submission not found or already reviewed.');
        redirect('/admin/submissions.php');
    }

    $pdo->beginTransaction();
    try {
        // Mark the submission reviewed
        $pdo->prepare(
            'UPDATE task_submissions
             SET status = :status, reviewed_at = NOW()
             WHERE id = :id'
        )->execute([':status' => $decision, ':id' => $subId]);

        if ($decision === 'approved') {
            // Credit the reward to the earner's wallet
            $pdo->prepare(
                'UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :uid'
            )->execute([':amt' => $sub['reward'], ':uid' => $sub['user_id']]);

            // Record in wallet ledger
            $pdo->prepare(
                'INSERT INTO wallet_transactions (user_id, type, amount, description)
                 VALUES (:uid, "credit", :amt, :desc)'
            )->execute([
                ':uid'  => $sub['user_id'],
                ':amt'  => $sub['reward'],
                ':desc' => 'Task approved: ' . $sub['task_title'],
            ]);

            // Increment slots_filled; close task if all slots taken
            $pdo->prepare(
                'UPDATE tasks
                 SET slots_filled = slots_filled + 1,
                     status = IF(slots_filled + 1 >= slots, "closed", status)
                 WHERE id = :tid'
            )->execute([':tid' => $sub['task_id']]);
        }

        $pdo->commit();
        log_activity($pdo, $_SESSION['admin']['id'],
            "submission_{$decision}: sub#{$subId} earner#{$sub['user_id']}");
        flash('success', 'Submission ' . $decision . '.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Submission review error: ' . $e->getMessage());
        flash('error', 'Could not process the review. Please try again.');
    }

    redirect('/admin/submissions.php');
}

/* ---------------------------------------------------------------
 * Fetch submissions — filter by status
 * ------------------------------------------------------------- */
$status  = in_array($_GET['status'] ?? '', ['pending','approved','rejected'], true)
           ? $_GET['status'] : 'pending';

$subs = $pdo->prepare(
    "SELECT ts.*, t.title AS task_title, t.reward, t.vip_level,
            u.name AS earner_name, u.email AS earner_email
     FROM task_submissions ts
     JOIN tasks t ON t.id = ts.task_id
     JOIN users u ON u.id = ts.user_id
     WHERE ts.status = :status
     ORDER BY ts.submitted_at DESC
     LIMIT 200"
);
$subs->execute([':status' => $status]);
$submissions = $subs->fetchAll();

$counts = $pdo->query(
    "SELECT status, COUNT(*) AS n FROM task_submissions GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Submissions — payNex admin';
require __DIR__ . '/includes/admin_header.php';
?>

<div class="page-head">
  <h1><i class="fa-solid fa-file-pen"></i> Task submissions</h1>
  <p>Review proof submitted by earners and approve or reject each one.</p>
</div>

<!-- Status filter tabs -->
<div style="display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap;">
  <?php foreach (['pending','approved','rejected'] as $s): ?>
    <a href="?status=<?= $s ?>"
       class="btn btn-sm <?= $s === $status ? 'btn-primary' : 'btn-dark' ?>">
      <?= ucfirst($s) ?>
      <?php if (!empty($counts[$s])): ?>
        <span style="background:rgba(255,255,255,.25); border-radius:999px; padding:1px 7px; font-size:11px; margin-left:4px;">
          <?= (int)$counts[$s] ?>
        </span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (!$submissions): ?>
    <p class="text-muted">No <?= e($status) ?> submissions.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Earner</th>
            <th>Task (VIP)</th>
            <th>Reward</th>
            <th>Proof</th>
            <th>Submitted</th>
            <th>Status</th>
            <?php if ($status === 'pending'): ?><th>Action</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($submissions as $sub): ?>
          <tr>
            <td>
              <strong><?= e($sub['earner_name']) ?></strong><br>
              <small class="text-muted"><?= e($sub['earner_email']) ?></small>
            </td>
            <td>
              <?= e($sub['task_title']) ?><br>
              <span class="badge badge-vip<?= (int)$sub['vip_level'] ?>" style="font-size:11px;">
                VIP <?= (int)$sub['vip_level'] ?>
              </span>
            </td>
            <td><?= e(money((float)$sub['reward'])) ?></td>
            <td style="max-width:280px; font-size:13px;">
              <?= nl2br(e(mb_substr($sub['proof_text'], 0, 200))) ?>
              <?php if ($sub['spin_result']): ?>
                <br><em>Spin: <?= e($sub['spin_result']) ?></em>
              <?php endif; ?>
            </td>
            <td><?= e(date('M j, Y g:ia', strtotime($sub['submitted_at']))) ?></td>
            <td>
              <span class="badge badge-<?= e($sub['status']) ?>">
                <?= e(ucfirst($sub['status'])) ?>
              </span>
            </td>
            <?php if ($status === 'pending'): ?>
              <td style="white-space:nowrap;">
                <!-- Approve -->
                <form method="post" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="submission_id" value="<?= (int)$sub['id'] ?>">
                  <input type="hidden" name="decision" value="approved">
                  <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-check"></i> Approve
                  </button>
                </form>
                <!-- Reject -->
                <form method="post" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="submission_id" value="<?= (int)$sub['id'] ?>">
                  <input type="hidden" name="decision" value="rejected">
                  <button type="submit" class="btn btn-danger btn-sm">
                    <i class="fa-solid fa-xmark"></i> Reject
                  </button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
