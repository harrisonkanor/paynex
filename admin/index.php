<?php
/**
 * admin/index.php — Admin overview dashboard.
 *
 * Shows platform-wide KPIs:
 *   - Total registered users (by VIP level)
 *   - Total tasks posted / pending submissions
 *   - Pending deposit orders
 *   - Pending withdrawals
 *   - Total paid out
 *   - Real-time earnings per VIP tier (with referral breakdown)
 *   - Recent activity log
 */
require_once __DIR__ . '/../config/config.php';
require_admin();

/* ---- Top-level stats ---- */
$totalUsers   = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='earner'")->fetchColumn();
$totalTasks   = (int) $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
$pendingDeps  = (int) $pdo->query("SELECT COUNT(*) FROM deposit_orders WHERE status='pending'")->fetchColumn();
$pendingWd    = (int) $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn();
$pendingSubs  = (int) $pdo->query("SELECT COUNT(*) FROM task_submissions WHERE status='pending'")->fetchColumn();
$totalPaid    = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE status='paid'")->fetchColumn();

/* ---- Earnings per VIP tier ---- */
$vipEarnings = $pdo->query(
    "SELECT u.vip_level,
            COUNT(DISTINCT u.id)                     AS user_count,
            COALESCE(SUM(wt.amount),0)               AS total_earned
     FROM users u
     LEFT JOIN wallet_transactions wt
           ON wt.user_id = u.id AND wt.type='credit'
     WHERE u.role='earner'
     GROUP BY u.vip_level
     ORDER BY u.vip_level"
)->fetchAll();

/* ---- Per-user referral earnings (for admin insight) ---- */
$topReferrers = $pdo->query(
    "SELECT u.name, COUNT(r.id) AS refs, COALESCE(SUM(r.bonus_amount),0) AS bonus
     FROM referrals r
     JOIN users u ON u.id = r.referrer_id
     GROUP BY r.referrer_id
     ORDER BY bonus DESC LIMIT 10"
)->fetchAll();

/* ---- Recent activity ---- */
$recentLogs = $pdo->query(
    "SELECT al.*, u.name FROM activity_logs al
     LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC LIMIT 15"
)->fetchAll();

$pageTitle = 'Admin overview — payNex';
require __DIR__ . '/includes/admin_header.php';
?>

<div class="page-head">
  <h1><i class="fa-solid fa-gauge-high"></i> Overview</h1>
  <p>Real-time platform metrics.</p>
</div>

<!-- KPI stat cards -->
<div class="stat-cards">
  <div class="stat-card">
    <div class="sc-icon"><i class="fa-solid fa-users"></i></div>
    <div class="num"><?= $totalUsers ?></div>
    <div class="lbl">Registered earners</div>
  </div>
  <div class="stat-card">
    <div class="sc-icon"><i class="fa-solid fa-list-check"></i></div>
    <div class="num"><?= $totalTasks ?></div>
    <div class="lbl">Tasks posted</div>
  </div>
  <div class="stat-card">
    <div class="sc-icon"><i class="fa-solid fa-file-pen" style="color:var(--amber);"></i></div>
    <div class="num"><?= $pendingSubs ?></div>
    <div class="lbl">Pending submissions</div>
  </div>
  <div class="stat-card">
    <div class="sc-icon"><i class="fa-solid fa-money-bill" style="color:var(--amber);"></i></div>
    <div class="num"><?= $pendingDeps ?></div>
    <div class="lbl">Pending deposits</div>
  </div>
  <div class="stat-card">
    <div class="sc-icon"><i class="fa-solid fa-arrow-up-from-bracket" style="color:var(--amber);"></i></div>
    <div class="num"><?= $pendingWd ?></div>
    <div class="lbl">Pending withdrawals</div>
  </div>
  <div class="stat-card">
    <div class="sc-icon"><i class="fa-solid fa-sack-dollar" style="color:var(--green);"></i></div>
    <div class="num"><?= e(money($totalPaid)) ?></div>
    <div class="lbl">Total paid out</div>
  </div>
</div>

<!-- VIP earnings breakdown -->
<div class="two-col">
  <div class="card">
    <h2><i class="fa-solid fa-crown"></i> Earnings by VIP tier</h2>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>VIP Level</th><th>Users</th><th>Total credits earned</th></tr></thead>
        <tbody>
        <?php foreach ($vipEarnings as $row): ?>
          <tr>
            <td>
              <?php if ($row['vip_level']): ?>
                <span class="badge badge-vip<?= (int)$row['vip_level'] ?>">
                  <i class="fa-solid fa-crown"></i>
                  VIP <?= (int)$row['vip_level'] ?>
                </span>
              <?php else: ?>
                <span class="text-muted">No plan</span>
              <?php endif; ?>
            </td>
            <td><?= (int)$row['user_count'] ?></td>
            <td><?= e(money((float)$row['total_earned'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Top referrers -->
  <div class="card">
    <h2><i class="fa-solid fa-users-between-lines"></i> Top referrers</h2>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>User</th><th>Referrals</th><th>Bonus paid</th></tr></thead>
        <tbody>
        <?php foreach ($topReferrers as $r): ?>
          <tr>
            <td><?= e($r['name']) ?></td>
            <td><?= (int)$r['refs'] ?></td>
            <td><?= e(money((float)$r['bonus'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$topReferrers): ?>
          <tr><td colspan="3" class="text-muted">No referrals yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Recent activity -->
<div class="card">
  <div class="card-header">
    <h2><i class="fa-solid fa-scroll"></i> Recent activity</h2>
    <a href="<?= BASE_URL ?>/admin/logs.php" class="btn btn-dark btn-sm">View all</a>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>User</th><th>Action</th><th>IP</th><th>When</th></tr></thead>
      <tbody>
      <?php foreach ($recentLogs as $log): ?>
        <tr>
          <td><?= e($log['name'] ?? 'Guest') ?></td>
          <td><?= e($log['action']) ?></td>
          <td class="text-mono" style="font-size:12px;"><?= e($log['ip_address']) ?></td>
          <td><?= e(date('M j, g:ia', strtotime($log['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
