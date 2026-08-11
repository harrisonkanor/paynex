<?php
/**
 * admin/tasks.php — Task library management.
 *
 * Admins can:
 *   - View all tasks with their VIP level, type, status, and slots
 *   - Toggle task open/closed
 *   - Delete tasks
 *   - Navigate to task-edit.php to create or edit a task
 *
 * Only admins can post tasks — earners cannot create tasks.
 * Task types: survey | spin_wheel
 * VIP levels: 1 (VIP 1), 2 (VIP 2), 3 (VIP 3)
 */
require_once __DIR__ . '/../config/config.php';
require_admin();

/* ---------------------------------------------------------------
 * Handle toggle-status and delete actions (POST)
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $taskId = (int) ($_POST['task_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($taskId > 0) {
        if ($action === 'toggle') {
            // Toggle open ↔ closed using a conditional UPDATE (SQLi-safe prepared stmt)
            $pdo->prepare(
                "UPDATE tasks
                 SET status = IF(status='open','closed','open')
                 WHERE id = :id"
            )->execute([':id' => $taskId]);
            log_activity($pdo, $_SESSION['admin']['id'], "admin_toggle_task: #{$taskId}");
            flash('success', 'Task status toggled.');
        } elseif ($action === 'delete') {
            // Cascades to task_claims and task_submissions via FK ON DELETE CASCADE
            $pdo->prepare('DELETE FROM tasks WHERE id = :id')->execute([':id' => $taskId]);
            log_activity($pdo, $_SESSION['admin']['id'], "admin_delete_task: #{$taskId}");
            flash('success', 'Task deleted.');
        }
    }
    redirect('/admin/tasks.php');
}

/* ---------------------------------------------------------------
 * Fetch all tasks with admin name
 * ------------------------------------------------------------- */
$tasks = $pdo->query(
    "SELECT t.*, u.name AS admin_name
     FROM tasks t
     JOIN users u ON u.id = t.admin_id
     ORDER BY t.created_at DESC"
)->fetchAll();

$pageTitle = 'Manage tasks — payNex admin';
require __DIR__ . '/includes/admin_header.php';
?>

<div class="page-head">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
      <h1><i class="fa-solid fa-list-check"></i> Task library</h1>
      <p>Create, edit, and manage tasks shown to earners. Only admins can post tasks.</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/task-edit.php" class="btn btn-primary btn-sm">
      <i class="fa-solid fa-plus"></i> New task
    </a>
  </div>
</div>

<div class="card">
  <?php if (!$tasks): ?>
    <p class="text-muted">No tasks created yet. <a href="<?= BASE_URL ?>/admin/task-edit.php">Create your first task →</a></p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th><i class="fa-solid fa-heading"></i> Title</th>
            <th><i class="fa-solid fa-crown"></i> VIP</th>
            <th><i class="fa-solid fa-tag"></i> Type</th>
            <th><i class="fa-solid fa-dollar-sign"></i> Reward</th>
            <th><i class="fa-solid fa-ticket"></i> Ticket</th>
            <th><i class="fa-solid fa-users"></i> Slots</th>
            <th><i class="fa-solid fa-circle"></i> Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($tasks as $t): ?>
          <tr>
            <td>
              <strong><?= e($t['title']) ?></strong><br>
              <small class="text-muted">by <?= e($t['admin_name']) ?></small>
            </td>
            <td>
              <span class="badge badge-vip<?= (int)$t['vip_level'] ?>">
                <i class="fa-solid fa-crown"></i> VIP <?= (int)$t['vip_level'] ?>
              </span>
            </td>
            <td>
              <?php if ($t['type'] === 'spin_wheel'): ?>
                <i class="fa-solid fa-circle-notch"></i> Spin wheel
              <?php else: ?>
                <i class="fa-solid fa-clipboard-list"></i> Survey
              <?php endif; ?>
            </td>
            <td><strong><?= e(money((float)$t['reward'])) ?></strong></td>
            <td><?= $t['ticket_price'] > 0 ? e(money((float)$t['ticket_price'])) : '<span class="text-muted">Free</span>' ?></td>
            <td><?= (int)$t['slots_filled'] ?> / <?= (int)$t['slots'] ?></td>
            <td>
              <span class="badge badge-<?= e($t['status']) ?>">
                <?= e(ucfirst($t['status'])) ?>
              </span>
            </td>
            <td style="white-space:nowrap;">
              <!-- Edit button -->
              <a href="<?= BASE_URL ?>/admin/task-edit.php?id=<?= (int)$t['id'] ?>"
                 class="btn btn-dark btn-sm" style="margin-right:4px;">
                <i class="fa-solid fa-pen"></i> Edit
              </a>

              <!-- Toggle open/closed -->
              <form method="post" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                <input type="hidden" name="action" value="toggle">
                <button type="submit" class="btn btn-sm"
                        style="background:var(--amber);color:#fff;margin-right:4px;">
                  <i class="fa-solid fa-toggle-on"></i>
                  <?= $t['status'] === 'open' ? 'Close' : 'Open' ?>
                </button>
              </form>

              <!-- Delete (requires confirm) -->
              <form method="post" style="display:inline;"
                    onsubmit="return confirm('Delete this task? All submissions will also be deleted.');">
                <?= csrf_field() ?>
                <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn-danger btn-sm">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
