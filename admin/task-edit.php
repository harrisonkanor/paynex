<?php
/**
 * admin/task-edit.php — Create or edit a task.
 *
 * GET  ?id=N  → load existing task for editing
 * GET  (no id) → blank form to create a new task
 * POST         → save (insert or update)
 *
 * Task fields:
 *   title, description, type (survey|spin_wheel), vip_level,
 *   reward, ticket_price, slots, time_limit_minutes,
 *   available_from, available_until, status
 *
 * Only admins can access this page.
 * All DB operations use prepared statements (SQL injection protection).
 */
require_once __DIR__ . '/../config/config.php';
require_admin();

$adminId = (int) $_SESSION['admin']['id'];

/* ---------------------------------------------------------------
 * Determine create vs. edit mode
 * ------------------------------------------------------------- */
$editId = (int) ($_GET['id'] ?? 0);
$isEdit = ($editId > 0);

// Defaults for a new task
$task = [
    'title'               => '',
    'description'         => '',
    'type'                => 'survey',
    'vip_level'           => 1,
    'reward'              => '',
    'ticket_price'        => '0.00',
    'slots'               => 100,
    'time_limit_minutes'  => 60,
    'available_from'      => '09:00',
    'available_until'     => '21:00',
    'status'              => 'open',
];

// Fetch existing task for editing
if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = :id');
    $stmt->execute([':id' => $editId]);
    $row = $stmt->fetch();
    if (!$row) {
        flash('error', 'Task not found.');
        redirect('/admin/tasks.php');
    }
    $task = $row;
}

$errors = [];

/* ---------------------------------------------------------------
 * Handle POST — validate and save
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Read and sanitise all inputs
    $task['title']              = trim($_POST['title']              ?? '');
    $task['description']        = trim($_POST['description']        ?? '');
    $task['type']               = in_array($_POST['type'] ?? '', ['survey','spin_wheel'], true)
                                  ? $_POST['type'] : 'survey';
    $task['vip_level']          = (int) ($_POST['vip_level']         ?? 1);
    $task['reward']             = $_POST['reward']                  ?? '';
    $task['ticket_price']       = $_POST['ticket_price']            ?? '0';
    $task['slots']              = (int) ($_POST['slots']             ?? 100);
    $task['time_limit_minutes'] = (int) ($_POST['time_limit_minutes'] ?? 60);
    $task['available_from']     = $_POST['available_from']          ?? '09:00';
    $task['available_until']    = $_POST['available_until']         ?? '21:00';
    $task['status']             = ($_POST['status'] ?? '') === 'closed' ? 'closed' : 'open';

    // --- Validation ---
    if ($task['title'] === '' || mb_strlen($task['title']) > 160) {
        $errors[] = 'Title is required (max 160 characters).';
    }
    if ($task['description'] === '') {
        $errors[] = 'Description is required.';
    }
    if (!in_array($task['vip_level'], [1,2,3], true)) {
        $errors[] = 'VIP level must be 1, 2, or 3.';
    }
    if (!is_numeric($task['reward']) || (float)$task['reward'] <= 0) {
        $errors[] = 'Reward must be a positive number.';
    }
    if (!is_numeric($task['ticket_price']) || (float)$task['ticket_price'] < 0) {
        $errors[] = 'Ticket price must be 0 or a positive number.';
    }
    if ($task['slots'] < 1) {
        $errors[] = 'Slots must be at least 1.';
    }
    if ($task['time_limit_minutes'] < 5) {
        $errors[] = 'Time limit must be at least 5 minutes.';
    }

    if (!$errors) {
        if ($isEdit) {
            // Update existing task
            $pdo->prepare(
                'UPDATE tasks
                 SET title = :title, description = :desc, type = :type,
                     vip_level = :vip, reward = :reward, ticket_price = :ticket,
                     slots = :slots, time_limit_minutes = :time,
                     available_from = :from, available_until = :until,
                     status = :status
                 WHERE id = :id'
            )->execute([
                ':title'  => $task['title'],
                ':desc'   => $task['description'],
                ':type'   => $task['type'],
                ':vip'    => $task['vip_level'],
                ':reward' => (float)$task['reward'],
                ':ticket' => (float)$task['ticket_price'],
                ':slots'  => $task['slots'],
                ':time'   => $task['time_limit_minutes'],
                ':from'   => $task['available_from'],
                ':until'  => $task['available_until'],
                ':status' => $task['status'],
                ':id'     => $editId,
            ]);
            log_activity($pdo, $adminId, "admin_edit_task: #{$editId}");
            flash('success', 'Task updated successfully.');
        } else {
            // Create new task
            $pdo->prepare(
                'INSERT INTO tasks
                   (admin_id, title, description, type, vip_level, reward, ticket_price,
                    slots, time_limit_minutes, available_from, available_until, status)
                 VALUES
                   (:admin, :title, :desc, :type, :vip, :reward, :ticket,
                    :slots, :time, :from, :until, :status)'
            )->execute([
                ':admin'  => $adminId,
                ':title'  => $task['title'],
                ':desc'   => $task['description'],
                ':type'   => $task['type'],
                ':vip'    => $task['vip_level'],
                ':reward' => (float)$task['reward'],
                ':ticket' => (float)$task['ticket_price'],
                ':slots'  => $task['slots'],
                ':time'   => $task['time_limit_minutes'],
                ':from'   => $task['available_from'],
                ':until'  => $task['available_until'],
                ':status' => $task['status'],
            ]);
            log_activity($pdo, $adminId, 'admin_create_task: ' . $task['title']);
            flash('success', 'Task created successfully.');
        }
        redirect('/admin/tasks.php');
    }
}

$pageTitle = ($isEdit ? 'Edit task' : 'New task') . ' — payNex admin';
require __DIR__ . '/includes/admin_header.php';
?>

<div class="page-head">
  <h1>
    <i class="fa-solid fa-<?= $isEdit ? 'pen' : 'plus' ?>"></i>
    <?= $isEdit ? 'Edit task' : 'Create new task' ?>
  </h1>
  <p>
    <?= $isEdit
        ? 'Editing: <strong>' . e($task['title']) . '</strong>'
        : 'Fill in the details below. This task will appear in the task library for the selected VIP level.' ?>
  </p>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error" style="margin-bottom:20px;">
    <i class="fa-solid fa-circle-exclamation"></i>
    <div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
  </div>
<?php endif; ?>

<div class="card" style="max-width:760px;">
  <form method="post"
        action="<?= BASE_URL ?>/admin/task-edit.php<?= $isEdit ? '?id='.$editId : '' ?>"
        novalidate>
    <?= csrf_field() ?>

    <!-- TITLE -->
    <div class="field">
      <label><i class="fa-solid fa-heading"></i> Task title</label>
      <input type="text" name="title"
             value="<?= e($task['title']) ?>"
             maxlength="160" required
             placeholder="e.g. Complete a 5-question product survey">
    </div>

    <!-- DESCRIPTION / INSTRUCTIONS -->
    <div class="field">
      <label><i class="fa-solid fa-align-left"></i> Instructions</label>
      <textarea name="description" required
                placeholder="Describe what the earner must do to complete this task and how to prove it..."><?= e($task['description']) ?></textarea>
    </div>

    <!-- TYPE + VIP LEVEL side by side -->
    <div class="form-row">
      <div class="field">
        <label><i class="fa-solid fa-tag"></i> Task type</label>
        <select name="type">
          <option value="survey"     <?= $task['type'] === 'survey'     ? 'selected' : '' ?>>
            Survey (text/URL proof)
          </option>
          <option value="spin_wheel" <?= $task['type'] === 'spin_wheel' ? 'selected' : '' ?>>
            Spin the wheel
          </option>
        </select>
      </div>

      <div class="field">
        <label><i class="fa-solid fa-crown"></i> VIP level</label>
        <select name="vip_level">
          <option value="1" <?= (int)$task['vip_level'] === 1 ? 'selected' : '' ?>>VIP 1 ($5 deposit)</option>
          <option value="2" <?= (int)$task['vip_level'] === 2 ? 'selected' : '' ?>>VIP 2 ($10 deposit)</option>
          <option value="3" <?= (int)$task['vip_level'] === 3 ? 'selected' : '' ?>>VIP 3 ($20 deposit)</option>
        </select>
        <div class="input-hint">Only earners on this VIP level will see this task.</div>
      </div>
    </div>

    <!-- REWARD + TICKET PRICE side by side -->
    <div class="form-row">
      <div class="field">
        <label><i class="fa-solid fa-dollar-sign"></i> Reward per completion (USD)</label>
        <input type="number" name="reward"
               value="<?= e((string)$task['reward']) ?>"
               min="0.01" step="0.01" required
               placeholder="e.g. 0.20">
        <div class="input-hint">Amount credited to the earner on approval.</div>
      </div>

      <div class="field">
        <label><i class="fa-solid fa-ticket"></i> Ticket price (0 = free)</label>
        <input type="number" name="ticket_price"
               value="<?= e((string)$task['ticket_price']) ?>"
               min="0" step="0.01"
               placeholder="0.00">
        <div class="input-hint">Deducted from earner's wallet when they claim the task.</div>
      </div>
    </div>

    <!-- SLOTS + TIME LIMIT side by side -->
    <div class="form-row">
      <div class="field">
        <label><i class="fa-solid fa-users"></i> Available slots (max completions)</label>
        <input type="number" name="slots"
               value="<?= (int)$task['slots'] ?>"
               min="1" step="1" required>
      </div>

      <div class="field">
        <label><i class="fa-regular fa-clock"></i> Time limit (minutes)</label>
        <input type="number" name="time_limit_minutes"
               value="<?= (int)$task['time_limit_minutes'] ?>"
               min="5" step="1" required>
        <div class="input-hint">Earner must submit within this window or the task is missed.</div>
      </div>
    </div>

    <!-- AVAILABLE WINDOW side by side -->
    <div class="form-row">
      <div class="field">
        <label><i class="fa-solid fa-sun"></i> Available from</label>
        <input type="time" name="available_from"
               value="<?= e(substr($task['available_from'], 0, 5)) ?>">
      </div>
      <div class="field">
        <label><i class="fa-solid fa-moon"></i> Available until</label>
        <input type="time" name="available_until"
               value="<?= e(substr($task['available_until'], 0, 5)) ?>">
      </div>
    </div>

    <!-- STATUS -->
    <div class="field">
      <label><i class="fa-solid fa-circle"></i> Status</label>
      <select name="status">
        <option value="open"   <?= $task['status'] === 'open'   ? 'selected' : '' ?>>Open (visible to earners)</option>
        <option value="closed" <?= $task['status'] === 'closed' ? 'selected' : '' ?>>Closed (hidden from earners)</option>
      </select>
    </div>

    <div class="form-actions" style="display:flex; gap:12px; flex-wrap:wrap;">
      <button type="submit" class="btn btn-primary">
        <i class="fa-solid fa-floppy-disk"></i>
        <?= $isEdit ? 'Save changes' : 'Create task' ?>
      </button>
      <a href="<?= BASE_URL ?>/admin/tasks.php" class="btn btn-dark">
        <i class="fa-solid fa-arrow-left"></i> Cancel
      </a>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
