<?php
/**
 * dashboard.php — Earner main dashboard.
 */
require_once __DIR__ . '/config/config.php';
require_login();
require_email_verified($pdo);

$user = current_user();
if ($user['role'] === 'admin') redirect('/admin/index.php');

$userRow = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$userRow->execute([':id' => $user['id']]);
$u = $userRow->fetch();

$_SESSION['user']['vip_level'] = $u['vip_level'];
$_SESSION['user']['profile_photo'] = $u['profile_photo'];
$_SESSION['user']['email_verified'] = (bool) $u['email_verified'];

$balance = (float) $u['wallet_balance'];

$tcStmt = $pdo->prepare("SELECT COUNT(*) FROM task_submissions WHERE user_id = :id AND status = 'approved'");
$tcStmt->execute([':id' => $user['id']]);
$tasksCompleted = (int) $tcStmt->fetchColumn();

$lifetimeStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE user_id = :id AND type = 'credit'");
$lifetimeStmt->execute([':id' => $user['id']]);
$lifetimeEarned = (float) $lifetimeStmt->fetchColumn();

$vipLevel = (int) ($u['vip_level'] ?? 0);
$vipPlan = $vipLevel ? get_vip_plan($pdo, $vipLevel) : null;

// Today's tasks
$todayTasks = [];
if ($vipPlan) {
    $taskStmt = $pdo->prepare(
        "SELECT t.*, tc.id AS claim_id, tc.claimed_at, tc.expires_at, ts.status AS submission_status
         FROM tasks t
         LEFT JOIN task_claims tc ON tc.task_id = t.id AND tc.user_id = :uid
         LEFT JOIN task_submissions ts ON ts.task_id = t.id AND ts.user_id = :uid2
         WHERE t.status = 'open' AND t.vip_level = :lv
         ORDER BY t.created_at DESC LIMIT 10"
    );
    $taskStmt->execute([':uid' => $user['id'], ':uid2' => $user['id'], ':lv' => $vipLevel]);
    $todayTasks = $taskStmt->fetchAll();
}

$refStmt = $pdo->prepare('SELECT COUNT(*) FROM referrals WHERE referrer_id = :id');
$refStmt->execute([':id' => $user['id']]);
$totalReferrals = (int) $refStmt->fetchColumn();

$refEarnStmt = $pdo->prepare('SELECT COALESCE(SUM(bonus_amount), 0) FROM referrals WHERE referrer_id = :id AND bonus_paid = 1');
$refEarnStmt->execute([':id' => $user['id']]);
$referralEarnings = (float) $refEarnStmt->fetchColumn();

$depositUsdt = get_setting($pdo, 'deposit_wallet_usdt');

$pageTitle = 'Dashboard — payNex';
require __DIR__ . '/includes/header.php';
?>

<div class="page-wrap">

  <?php if ($u['status'] === 'suspended'): ?>
    <div class="suspended-overlay">
      <div><i class="fa-solid fa-ban"></i></div>
      <h2>Account suspended</h2>
      <p><?= $u['suspension_note'] ? e($u['suspension_note']) : 'Your account has been suspended. Please contact support.' ?></p>
    </div>
  <?php endif; ?>

  <div class="page-head">
    <h1>
      <?php if ($u['profile_photo']): ?>
        <img src="<?= BASE_URL ?>/uploads/<?= e($u['profile_photo']) ?>" style="width:42px;height:42px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:10px;">
      <?php else: ?>
        <span style="display:inline-flex;width:42px;height:42px;background:var(--navy-deep);color:#fff;border-radius:50%;align-items:center;justify-content:center;vertical-align:middle;margin-right:10px;font-size:18px;"><i class="fa-solid fa-user"></i></span>
      <?php endif; ?>
      Welcome back, <?= e(explode(' ', $u['name'])[0]) ?>
    </h1>
    <p>
      <?php if ($vipPlan): ?>
        <span class="badge badge-vip<?= $vipLevel ?>"><i class="fa-solid fa-crown"></i> <?= e($vipPlan['label']) ?></span>
        &nbsp;
        <?php if ($u['vip_expires_at']): ?>
          Plan active until <?= e(date('M j, Y', strtotime($u['vip_expires_at']))) ?>
        <?php endif; ?>
      <?php else: ?>
        No active VIP plan. <a href="<?= BASE_URL ?>/plans.php" style="color:var(--blue);">Activate a plan →</a>
      <?php endif; ?>
    </p>
  </div>

  <div class="stat-cards">
    <div class="stat-card"><div class="sc-icon"><i class="fa-solid fa-wallet"></i></div><div class="num"><?= e(money($balance)) ?></div><div class="lbl">Available balance</div></div>
    <div class="stat-card"><div class="sc-icon"><i class="fa-solid fa-list-check"></i></div><div class="num"><?= $tasksCompleted ?></div><div class="lbl">Tasks completed</div></div>
    <div class="stat-card"><div class="sc-icon"><i class="fa-solid fa-sack-dollar"></i></div><div class="num"><?= e(money($lifetimeEarned)) ?></div><div class="lbl">Lifetime earned</div></div>
    <div class="stat-card"><div class="sc-icon"><i class="fa-solid fa-users"></i></div><div class="num"><?= $totalReferrals ?></div><div class="lbl">Referrals</div></div>
  </div>

  <div class="two-col">
    <div>
      <div class="card <?= $u['status'] === 'suspended' ? 'suspended-lock' : '' ?>">
        <div class="card-header"><h2><i class="fa-solid fa-list-check"></i> Today's tasks</h2><?php if ($vipPlan): ?><span class="badge badge-vip<?= $vipLevel ?>"><?= e($vipPlan['label']) ?> · <?= e(money((float)$vipPlan['task_reward'])) ?>/task</span><?php endif; ?></div>
        <?php if (!$vipPlan): ?>
          <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> Activate a VIP plan to unlock daily tasks and start earning. <a href="<?= BASE_URL ?>/plans.php" style="font-weight:600; margin-left:8px;">View plans →</a></div>
        <?php elseif (!$todayTasks): ?>
          <p class="text-muted">No tasks available yet — check back soon.</p>
        <?php else: ?>
          <div class="task-list">
            <?php foreach ($todayTasks as $task):
              $isExpired = $task['claim_id'] && $task['expires_at'] && strtotime($task['expires_at']) < time();
              $isDone = in_array($task['submission_status'], ['approved','rejected']);
            ?>
              <div class="task-card">
                <div class="task-card-body"><h3><?= e($task['title']) ?></h3><div class="task-meta"><span><i class="fa-solid fa-tag"></i> <?= e(ucfirst(str_replace('_',' ',$task['type']))) ?></span><span><i class="fa-regular fa-clock"></i> <?= (int)$task['time_limit_minutes'] ?> min</span><?php if ($task['claim_id'] && !$isDone && !$isExpired): ?><span class="task-timer" id="timer-<?= (int)$task['id'] ?>" data-expires="<?= e($task['expires_at']) ?>"><i class="fa-solid fa-stopwatch"></i> --:--</span><?php elseif ($isExpired && !$isDone): ?><span class="task-timer expired"><i class="fa-solid fa-circle-xmark"></i> Time expired</span><?php endif; ?></div></div>
                <div class="task-card-side"><div class="task-reward"><?php if ($task['type'] === 'spin_wheel'): ?><span style="opacity:0.35;font-weight:400;font-size:16px;">&mdash;</span><?php else: ?><?= e(money((float)$task['reward'])) ?><?php endif; ?></div><?php if ($isDone): ?><span class="badge badge-<?= e($task['submission_status']) ?>"><?= e(ucfirst($task['submission_status'])) ?></span><?php elseif ($isExpired): ?><span class="badge badge-rejected">Missed</span><?php else: ?><a href="<?= $task['claim_id'] ? BASE_URL . '/task.php?id=' . $task['id'] : BASE_URL . '/tasks.php' ?>" class="btn btn-dark btn-sm"><i class="fa-solid fa-play"></i> <?= $task['claim_id'] ? 'Continue' : 'Start task' ?></a><?php endif; ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div>
      <div class="card">
        <h2><i class="fa-solid fa-wallet"></i> Wallet</h2>
        <p class="text-muted" style="font-size:14px; margin-bottom:16px;">Deposit USDT to activate a VIP plan.</p>
        <div class="deposit-address-box"><div class="addr-label"><i class="fa-solid fa-circle-dollar-to-slot" style="color:#26a17b;"></i> USDT TRC-20 deposit address</div><div class="addr-value"><?= e($depositUsdt ?: 'Not configured yet') ?></div><?php if ($depositUsdt): ?><button class="copy-btn" onclick="copyAddr(this,'<?= e($depositUsdt) ?>')"><i class="fa-solid fa-copy"></i> Copy</button><?php endif; ?></div>
        <p style="font-size:12.5px; color:var(--ink-soft);"><i class="fa-solid fa-triangle-exclamation"></i> After depositing, submit your transaction hash below so an admin can confirm your deposit.</p>
        <a href="<?= BASE_URL ?>/plans.php" class="btn btn-primary btn-full mt-12"><i class="fa-solid fa-crown"></i> Activate / upgrade VIP plan</a>
        <?php if ($u['status'] !== 'suspended'): ?><a href="<?= BASE_URL ?>/withdraw.php" class="btn btn-dark btn-full mt-8"><i class="fa-solid fa-arrow-up-from-bracket"></i> Withdraw earnings</a><?php endif; ?>
      </div>
      <div class="card">
        <h2><i class="fa-solid fa-users"></i> Referrals</h2>
        <p class="text-muted" style="font-size:14px;">You've referred <strong><?= $totalReferrals ?></strong> user(s) and earned <strong><?= e(money($referralEarnings)) ?></strong> in bonuses.</p>
        <p style="font-size:12px; color:var(--ink-soft); margin-top:4px;"><i class="fa-solid fa-circle-info"></i> Referral bonuses are credited after your referred user activates a VIP plan.</p>
        <a href="<?= BASE_URL ?>/referrals.php" class="btn btn-info btn-sm mt-8"><i class="fa-solid fa-share-nodes"></i> View referral details</a>
      </div>
      <div class="card">
        <h2><i class="fa-solid fa-pen-to-square"></i> Profile</h2>
        <p class="text-muted" style="font-size:14px;">Update your name, USDT wallet address, or password.</p>
        <a href="<?= BASE_URL ?>/profile.php" class="btn btn-dark btn-sm mt-8"><i class="fa-solid fa-gear"></i> Edit profile</a>
      </div>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('[data-expires]').forEach(function(el) {
  function tick() {
    var diff = Math.floor((new Date(el.dataset.expires) - new Date()) / 1000);
    if (diff <= 0) { el.textContent = 'Time expired'; el.classList.add('expired'); return; }
    var m = Math.floor(diff / 60), s = diff % 60;
    el.innerHTML = '<i class="fa-solid fa-stopwatch"></i> ' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    setTimeout(tick, 1000);
  }
  tick();
});
function copyAddr(btn, addr) {
  navigator.clipboard.writeText(addr).then(function() {
    btn.classList.add('copied');
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
    setTimeout(function() { btn.classList.remove('copied'); btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy'; }, 2000);
  });
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
