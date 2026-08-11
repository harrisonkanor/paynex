<?php
/**
 * tasks.php — Earner task library.
 */
require_once __DIR__ . '/config/config.php';
require_login();

$user = current_user();
if ($user['role'] === 'admin') redirect('/admin/index.php');

$uStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$uStmt->execute([':id' => $user['id']]);
$u = $uStmt->fetch();

$vipLevel = (int) ($u['vip_level'] ?? 0);
$vipPlan  = $vipLevel ? get_vip_plan($pdo, $vipLevel) : null;
$balance  = (float) $u['wallet_balance'];

$tasks = [];
if ($vipPlan) {
    $stmt = $pdo->prepare(
        "SELECT t.*,
                tc.id        AS claim_id,
                tc.claimed_at,
                tc.expires_at,
                tc.ticket_paid,
                ts.id        AS sub_id,
                ts.status    AS sub_status
         FROM tasks t
         LEFT JOIN task_claims tc
               ON tc.task_id = t.id AND tc.user_id = :uid
         LEFT JOIN task_submissions ts
               ON ts.task_id = t.id AND ts.user_id = :uid2
         WHERE t.status     = 'open'
           AND t.vip_level  = :lv
           AND CURTIME() BETWEEN t.available_from AND t.available_until
         ORDER BY t.created_at DESC"
    );
    $stmt->execute([':uid' => $user['id'], ':uid2' => $user['id'], ':lv' => $vipLevel]);
    $tasks = $stmt->fetchAll();
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $u['status'] !== 'suspended') {
    verify_csrf();
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $tStmt = $pdo->prepare("SELECT * FROM tasks WHERE id = :id AND status = 'open' AND vip_level = :lv");
    $tStmt->execute([':id' => $taskId, ':lv' => $vipLevel]);
    $task = $tStmt->fetch();
    if (!$task) { flash('error', 'Task not found or not available for your VIP level.'); redirect('/tasks.php'); }
    $claimCheck = $pdo->prepare('SELECT id FROM task_claims WHERE task_id = :tid AND user_id = :uid');
    $claimCheck->execute([':tid' => $taskId, ':uid' => $user['id']]);
    if ($claimCheck->fetchColumn()) { flash('error', 'You have already claimed this task.'); redirect('/tasks.php'); }
    $ticketPrice = (float) $task['ticket_price'];
    if ($ticketPrice > 0 && $balance < $ticketPrice) { flash('error', 'Insufficient balance to purchase this task ticket (' . money($ticketPrice) . ').'); redirect('/tasks.php'); }
    $pdo->beginTransaction();
    try {
        if ($ticketPrice > 0) {
            $deduct = $pdo->prepare('UPDATE users SET wallet_balance = wallet_balance - :amt WHERE id = :id AND wallet_balance >= :amt2');
            $deduct->execute([':amt' => $ticketPrice, ':id' => $user['id'], ':amt2' => $ticketPrice]);
            if ($deduct->rowCount() === 0) throw new RuntimeException('Insufficient balance.');
            $tx = $pdo->prepare('INSERT INTO wallet_transactions (user_id, type, amount, description) VALUES (:uid, "debit", :amt, :desc)');
            $tx->execute([':uid' => $user['id'], ':amt' => $ticketPrice, ':desc' => 'Task ticket: ' . $task['title']]);
        }
        $expiresAt = date('Y-m-d H:i:s', time() + $task['time_limit_minutes'] * 60);
        $claim = $pdo->prepare('INSERT INTO task_claims (task_id, user_id, ticket_paid, expires_at) VALUES (:tid, :uid, :ticket, :exp)');
        $claim->execute([':tid' => $taskId, ':uid' => $user['id'], ':ticket' => $ticketPrice, ':exp' => $expiresAt]);
        $pdo->commit();
        log_activity($pdo, $user['id'], "task_claimed: #{$taskId}");
        flash('success', 'Task started! Complete it before the timer runs out.');
        redirect('/task.php?id=' . $taskId);
    } catch (Throwable $e) { $pdo->rollBack(); error_log('Task claim error: ' . $e->getMessage()); flash('error', 'Could not claim task. Please try again.'); redirect('/tasks.php'); }
}

$pageTitle = 'Tasks — payNex';
require __DIR__ . '/includes/header.php';
?>

<div class="page-wrap">
  <div class="page-head">
    <h1><i class="fa-solid fa-list-check" style="color:var(--green);"></i> Task library</h1>
    <?php if ($vipPlan): ?>
      <p>
        <span class="badge badge-vip<?= $vipLevel ?>">
          <i class="fa-solid fa-crown"></i> <?= e($vipPlan['label']) ?>
        </span>
        &nbsp; Earn <?= e(money((float)$vipPlan['task_reward'])) ?> per task
        &nbsp;·&nbsp; Balance: <strong><?= e(money($balance)) ?></strong>
      </p>
    <?php else: ?>
      <p>Activate a VIP plan to unlock tasks.
         <a href="<?= BASE_URL ?>/plans.php" style="color:var(--blue);">View plans →</a>
      </p>
    <?php endif; ?>
  </div>

  <?php if ($u['status'] === 'suspended'): ?>
    <div class="suspended-overlay">
      <div><i class="fa-solid fa-ban"></i></div>
      <h2>Account suspended</h2>
      <p><?= e($u['suspension_note'] ?: 'Your account has been suspended. Tasks are unavailable.') ?></p>
    </div>
  <?php endif; ?>

  <?php if (!$vipPlan): ?>
    <div class="alert alert-info">
      <i class="fa-solid fa-crown"></i>
      You need an active VIP plan to see and complete tasks.
      <a href="<?= BASE_URL ?>/plans.php" style="font-weight:600; margin-left:8px;">Activate a plan →</a>
    </div>
  <?php elseif (!$tasks): ?>
    <div class="card">
      <p class="text-muted text-center" style="padding:20px 0;">
        <i class="fa-solid fa-clock" style="font-size:28px; display:block; margin-bottom:12px;"></i>
        No tasks available right now. Tasks are posted Monday–Friday
        within their time windows. Check back soon!
      </p>
    </div>
  <?php else: ?>
    <div class="task-list">
      <?php foreach ($tasks as $task):
        $isClaimed   = !empty($task['claim_id']);
        $isExpired   = $isClaimed && strtotime($task['expires_at']) < time();
        $isSubmitted = !empty($task['sub_id']);
        $isApproved  = ($task['sub_status'] === 'approved');
        $isMissed    = $isExpired && !$isSubmitted;
        $ticketPrice = (float) $task['ticket_price'];
        $timeLimit   = (int) $task['time_limit_minutes'];
      ?>
        <div class="task-card">
          <div class="task-card-body">
            <h3><?= e($task['title']) ?></h3>
            <div class="task-meta">
              <span>
                <?php if ($task['type'] === 'spin_wheel'): ?>
                  <i class="fa-solid fa-circle-notch"></i> Spin the wheel
                <?php else: ?>
                  <i class="fa-solid fa-clipboard-list"></i> Survey
                <?php endif; ?>
              </span>
              <span>
                <i class="fa-regular fa-clock"></i>
                <?= $timeLimit ?> min time limit
              </span>
              <span>
                <i class="fa-solid fa-user-group"></i>
                <?= (int)($task['slots'] - $task['slots_filled']) ?> slot(s) left
              </span>
              <?php if ($ticketPrice > 0): ?>
                <span>
                  <i class="fa-solid fa-ticket"></i>
                  Ticket: <?= e(money($ticketPrice)) ?>
                </span>
              <?php endif; ?>
              <?php if ($isClaimed && !$isSubmitted && !$isExpired): ?>
                <span class="task-timer" id="timer-<?= (int)$task['id'] ?>" data-expires="<?= e($task['expires_at']) ?>">
                  <i class="fa-solid fa-stopwatch"></i> --:--
                </span>
              <?php elseif ($isMissed): ?>
                <span class="task-timer expired">
                  <i class="fa-solid fa-circle-xmark"></i> Time expired
                </span>
              <?php endif; ?>
            </div>
          </div>

          <div class="task-card-side">
            <div class="task-reward">
              <?php if ($task['type'] === 'spin_wheel'): ?>
                <span style="opacity:0.35;font-weight:400;font-size:16px;">&mdash;</span>
              <?php else: ?>
                <?= e(money((float)$task['reward'])) ?>
              <?php endif; ?>
            </div>

            <?php if ($isSubmitted): ?>
              <span class="badge badge-<?= $task['type'] === 'spin_wheel' ? 'approved' : e($task['sub_status']) ?>">
                <?= $task['type'] === 'spin_wheel' ? 'Completed' : e(ucfirst($task['sub_status'])) ?>
              </span>
            <?php elseif ($isMissed): ?>
              <span class="badge badge-rejected">Missed</span>
            <?php elseif ($isClaimed && !$isExpired): ?>
              <a href="<?= BASE_URL ?>/task.php?id=<?= (int)$task['id'] ?>" class="btn btn-dark btn-sm">
                <i class="fa-solid fa-arrow-right"></i> Continue
              </a>
            <?php elseif ($u['status'] !== 'suspended'): ?>
              <form method="post" action="<?= BASE_URL ?>/tasks.php">
                <?= csrf_field() ?>
                <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm">
                  <?php if ($ticketPrice > 0): ?>
                    <i class="fa-solid fa-ticket"></i>
                    Buy ticket (<?= e(money($ticketPrice)) ?>)
                  <?php else: ?>
                    <i class="fa-solid fa-play"></i> Start task
                  <?php endif; ?>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
document.querySelectorAll('[data-expires]').forEach(function (el) {
  function tick() {
    var diff = Math.floor((new Date(el.dataset.expires) - new Date()) / 1000);
    if (diff <= 0) {
      el.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Expired';
      el.classList.add('expired');
      return;
    }
    var h = Math.floor(diff / 3600), m = Math.floor((diff % 3600) / 60), s = diff % 60;
    el.innerHTML = '<i class="fa-solid fa-stopwatch"></i> ' +
      String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    setTimeout(tick, 1000);
  }
  tick();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
