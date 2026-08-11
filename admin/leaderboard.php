<?php
/**
 * admin/leaderboard.php — Admin leaderboard management.
 */
require_once __DIR__ . '/../config/config.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['trigger_payout'])) {
    verify_csrf();
    $cycle = $pdo->query("SELECT * FROM leaderboard_cycles WHERE status = 'active' LIMIT 1")->fetch();
    if (!$cycle) { flash('error', 'No active cycle found.'); redirect('/admin/leaderboard.php'); }
    $weekStart = $cycle['week_start']; $weekEnd = $cycle['week_end'];
    $prizePerPerson = (float) $cycle['prize_per_person'];
    $top10 = $pdo->prepare(
        "SELECT u.id, COUNT(r.id) AS referral_count FROM users u
" .
        "JOIN referrals r ON r.referrer_id = u.id AND r.bonus_paid = 1
" .
        "WHERE u.role = 'earner' AND r.created_at >= :ws
" .
        "AND r.created_at < DATE_ADD(:we, INTERVAL 1 DAY)
" .
        "GROUP BY u.id ORDER BY referral_count DESC LIMIT 10"
    );
    $top10->execute([':ws' => $weekStart . ' 00:00:00', ':we' => $weekEnd . ' 00:00:00']);
    $winners = $top10->fetchAll();
    try {
        $pdo->beginTransaction();
        $payStmt = $pdo->prepare("INSERT INTO leaderboard_payouts (cycle_id, user_id, rank_position, referral_count, prize_amount, paid_at) VALUES (:cid, :uid, :rk, :rc, :amt, NOW())");
        $walletStmt = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :id");
        $txStmt = $pdo->prepare("INSERT INTO wallet_transactions (user_id, type, amount, description) VALUES (:uid, 'credit', :amt, :desc)");
        $rank = 1;
        foreach ($winners as $w) {
            $payStmt->execute([':cid' => $cycle['id'], ':uid' => $w['id'], ':rk' => $rank, ':rc' => $w['referral_count'], ':amt' => $prizePerPerson]);
            $walletStmt->execute([':amt' => $prizePerPerson, ':id' => $w['id']]);
            $txStmt->execute([':uid' => $w['id'], ':amt' => $prizePerPerson, ':desc' => 'Weekly leaderboard prize - #' . $rank]);
            $rank++;
        }
        $pdo->prepare("UPDATE leaderboard_cycles SET status = 'completed', closed_at = NOW() WHERE id = :id")->execute([':id' => $cycle['id']]);
        $pdo->exec("INSERT INTO leaderboard_cycles (week_start, week_end, status) VALUES (DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 13 DAY), 'active')");
        $pdo->commit();
        flash('success', 'Payout completed! ' . count($winners) . ' winners credited.');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('error', 'Payout failed: ' . $e->getMessage());
    }
    redirect('/admin/leaderboard.php');
}
$cycles = $pdo->query("SELECT lc.*, (SELECT COUNT(*) FROM leaderboard_payouts WHERE cycle_id = lc.id) AS winner_count, (SELECT COALESCE(SUM(prize_amount), 0) FROM leaderboard_payouts WHERE cycle_id = lc.id) AS total_paid FROM leaderboard_cycles lc ORDER BY lc.week_start DESC")->fetchAll();
$activeCycle = null;
foreach ($cycles as $c) { if ($c['status'] === 'active') { $activeCycle = $c; break; } }
$pageTitle = 'Leaderboard â Admin â payNex';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="page-head">
  <h1><i class="fa-solid fa-trophy" style="color:var(--amber);"></i> Leaderboard Management</h1>
  <p>Manage weekly prize pool cycles and payouts.</p>
</div>
<?php if ($activeCycle): $secondsUntilReset = strtotime($activeCycle['week_end']) + 86400 - time(); ?>
<div class="card">
  <div class="card-header">
    <h2><i class="fa-solid fa-circle-play" style="color:var(--green);"></i> Current cycle</h2>
    <span class="badge badge-active">ACTIVE</span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-top:12px;">
    <div><div class="text-muted" style="font-size:11px;text-transform:uppercase;font-family:'IBM Plex Mono',monospace;">Week</div><div style="font-size:18px;font-weight:600;"><?= e(date('M j', strtotime($activeCycle['week_start']))) ?> â <?= e(date('M j, Y', strtotime($activeCycle['week_end']))) ?></div></div>
    <div><div class="text-muted" style="font-size:11px;text-transform:uppercase;font-family:'IBM Plex Mono',monospace;">Prize Pool</div><div style="font-size:18px;font-weight:600;">$<?= number_format((float)$activeCycle['total_prize_pool'], 2) ?></div></div>
    <div><div class="text-muted" style="font-size:11px;text-transform:uppercase;font-family:'IBM Plex Mono',monospace;">Per Winner</div><div style="font-size:18px;font-weight:600;">$<?= number_format((float)$activeCycle['prize_per_person'], 2) ?></div></div>
    <div><div class="text-muted" style="font-size:11px;text-transform:uppercase;font-family:'IBM Plex Mono',monospace;">Countdown</div><div style="font-size:18px;font-weight:600;" id="admin-countdown" data-seconds="<?= $secondsUntilReset ?>">--:--:--</div></div>
  </div>
  <form method="POST" style="margin-top:20px;padding-top:16px;border-top:1px solid var(--paper-line);" onsubmit="return confirm('Process weekly payout? This credits top 10 and starts a new cycle.')">
    <?= csrf_field() ?>
    <button type="submit" name="trigger_payout" class="btn btn-primary"><i class="fa-solid fa-gift"></i> Trigger payout now</button>
  </form>
</div>
<?php endif; ?>
<div class="card" style="margin-top:24px;">
  <div class="card-header"><h2><i class="fa-solid fa-clock-rotate-left"></i> Cycle history</h2></div>
  <?php if (!$cycles): ?><p class="text-muted">No cycles recorded yet.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Week</th><th>Period</th><th>Status</th><th>Winners</th><th>Total paid</th><th>Closed</th></tr></thead>
      <tbody>
        <?php foreach ($cycles as $c): ?>
        <tr>
          <td><i class="fa-solid fa-calendar-week"></i> <?= date('M j', strtotime($c['week_start'])) ?> â <?= date('M j', strtotime($c['week_end'])) ?></td>
          <td style="font-family:'IBM Plex Mono',monospace;font-size:13px;"><?= e($c['week_start']) ?> â <?= e($c['week_end']) ?></td>
          <td><?php if ($c['status'] === 'active'): ?><span class="badge badge-active">Active</span><?php elseif ($c['status'] === 'completed'): ?><span class="badge badge-paid">Completed</span><?php else: ?><span class="badge badge-closed"><?= e(ucfirst($c['status'])) ?></span><?php endif; ?></td>
          <td><?= (int)$c['winner_count'] ?></td>
          <td style="font-family:'IBM Plex Mono',monospace;color:var(--green);">$<?= number_format((float)($c['total_paid'] ?? 0), 2) ?></td>
          <td style="font-size:13px;color:var(--ink-soft);"><?= $c['closed_at'] ? e(date('M j, g:ia', strtotime($c['closed_at']))) : 'â' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<script>
(function() {
  var el = document.getElementById('admin-countdown');
  if (el) {
    var totalSec = parseInt(el.dataset.seconds, 10);
    function tick() {
      if (totalSec <= 0) { el.textContent = 'Ready!'; return; }
      var d = Math.floor(totalSec / 86400);
      var h = Math.floor((totalSec % 86400) / 3600);
      var m = Math.floor((totalSec % 3600) / 60);
      var s = totalSec % 60;
      el.textContent = (d > 0 ? d + 'd ' : '') + String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
      totalSec--; setTimeout(tick, 1000);
    }
    tick();
  }
})();
</script>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
