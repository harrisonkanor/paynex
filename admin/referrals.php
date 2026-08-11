<?php
/**
 * admin/referrals.php — Full referral overview.
 *
 * Admins can see:
 *   - Every referral relationship (who referred whom)
 *   - VIP level of referred user
 *   - Bonus paid / pending
 *   - Real-time earnings per user broken down by their referrals
 *   - Filter by VIP level
 */
require_once __DIR__ . '/../config/config.php';
require_admin();

/* ---------------------------------------------------------------
 * Per-VIP earnings summary (users + their referral earnings)
 * ------------------------------------------------------------- */
$vipBreakdown = $pdo->query(
    "SELECT
        u.vip_level,
        COUNT(DISTINCT u.id)                           AS earner_count,
        COALESCE(SUM(wt.amount), 0)                    AS direct_earnings,
        COALESCE(SUM(r.bonus_amount), 0)               AS referral_bonuses_paid
     FROM users u
     LEFT JOIN wallet_transactions wt
           ON wt.user_id = u.id AND wt.type = 'credit'
     LEFT JOIN referrals r
           ON r.referrer_id = u.id AND r.bonus_paid = 1
     WHERE u.role = 'earner'
     GROUP BY u.vip_level
     ORDER BY u.vip_level"
)->fetchAll();

/* ---------------------------------------------------------------
 * All referrals table
 * ------------------------------------------------------------- */
$vipFilter = (int) ($_GET['vip'] ?? 0);

if ($vipFilter > 0) {
    $refStmt = $pdo->prepare(
        "SELECT r.*,
                referrer.name  AS referrer_name,  referrer.email AS referrer_email,
                referred.name  AS referred_name,  referred.email AS referred_email,
                referred.vip_level,
                COALESCE(
                    (SELECT SUM(wt.amount) FROM wallet_transactions wt
                     WHERE wt.user_id = referred.id AND wt.type = 'credit'),
                0) AS referred_earnings
         FROM referrals r
         JOIN users referrer ON referrer.id = r.referrer_id
         JOIN users referred ON referred.id = r.referred_id
         WHERE referred.vip_level = :lv
         ORDER BY r.created_at DESC
         LIMIT 500"
    );
    $refStmt->execute([':lv' => $vipFilter]);
} else {
    $refStmt = $pdo->query(
        "SELECT r.*,
                referrer.name  AS referrer_name,  referrer.email AS referrer_email,
                referred.name  AS referred_name,  referred.email AS referred_email,
                referred.vip_level,
                COALESCE(
                    (SELECT SUM(wt.amount) FROM wallet_transactions wt
                     WHERE wt.user_id = referred.id AND wt.type = 'credit'),
                0) AS referred_earnings
         FROM referrals r
         JOIN users referrer ON referrer.id = r.referrer_id
         JOIN users referred ON referred.id = r.referred_id
         ORDER BY r.created_at DESC
         LIMIT 500"
    );
}
$referrals = $refStmt->fetchAll();

$pageTitle = 'Referrals — payNex admin';
require __DIR__ . '/includes/admin_header.php';
?>

<div class="page-head">
  <h1><i class="fa-solid fa-users-between-lines"></i> Referrals</h1>
  <p>Real-time earnings breakdown per VIP tier and all referral relationships.</p>
</div>

<!-- VIP earnings summary cards -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px,1fr)); gap:16px; margin-bottom:28px;">
  <?php foreach ($vipBreakdown as $row): ?>
    <div class="card" style="margin:0;">
      <?php if ($row['vip_level']): ?>
        <div class="badge badge-vip<?= (int)$row['vip_level'] ?>" style="margin-bottom:10px;">
          <i class="fa-solid fa-crown"></i> VIP <?= (int)$row['vip_level'] ?>
        </div>
      <?php else: ?>
        <div class="badge badge-pending" style="margin-bottom:10px;">No plan</div>
      <?php endif; ?>
      <div style="font-size:22px; font-weight:700; font-family:'Space Grotesk';">
        <?= (int)$row['earner_count'] ?> users
      </div>
      <div style="font-size:13px; color:var(--ink-soft); margin-top:6px;">
        Direct earnings: <strong><?= e(money((float)$row['direct_earnings'])) ?></strong>
      </div>
      <div style="font-size:13px; color:var(--ink-soft);">
        Ref. bonuses paid: <strong><?= e(money((float)$row['referral_bonuses_paid'])) ?></strong>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- VIP filter tabs -->
<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap; align-items:center;">
  <span style="font-size:13px; color:var(--ink-soft); margin-right:4px;">Filter by VIP:</span>
  <a href="?" class="btn btn-sm <?= $vipFilter === 0 ? 'btn-primary' : 'btn-dark' ?>">All</a>
  <a href="?vip=1" class="btn btn-sm <?= $vipFilter === 1 ? 'btn-primary' : 'btn-dark' ?>">VIP 1</a>
  <a href="?vip=2" class="btn btn-sm <?= $vipFilter === 2 ? 'btn-primary' : 'btn-dark' ?>">VIP 2</a>
  <a href="?vip=3" class="btn btn-sm <?= $vipFilter === 3 ? 'btn-primary' : 'btn-dark' ?>">VIP 3</a>
</div>

<div class="card">
  <h2><i class="fa-solid fa-list"></i> Referral relationships (<?= count($referrals) ?>)</h2>
  <?php if (!$referrals): ?>
    <p class="text-muted">No referrals yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Referrer</th>
            <th>Referred user</th>
            <th>Referred VIP</th>
            <th>Referred earnings</th>
            <th>Bonus paid</th>
            <th>Joined</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($referrals as $ref): ?>
          <tr>
            <td>
              <strong><?= e($ref['referrer_name']) ?></strong><br>
              <small class="text-muted"><?= e($ref['referrer_email']) ?></small>
            </td>
            <td>
              <strong><?= e($ref['referred_name']) ?></strong><br>
              <small class="text-muted"><?= e($ref['referred_email']) ?></small>
            </td>
            <td>
              <?php if ($ref['vip_level']): ?>
                <span class="badge badge-vip<?= (int)$ref['vip_level'] ?>">
                  VIP <?= (int)$ref['vip_level'] ?>
                </span>
              <?php else: ?>
                <span class="text-muted">None</span>
              <?php endif; ?>
            </td>
            <td><?= e(money((float)$ref['referred_earnings'])) ?></td>
            <td>
              <?php if ($ref['bonus_paid']): ?>
                <strong style="color:var(--green);"><?= e(money((float)$ref['bonus_amount'])) ?></strong>
                <span class="badge badge-approved" style="margin-left:4px;">Paid</span>
              <?php else: ?>
                <span class="badge badge-pending">Pending</span>
              <?php endif; ?>
            </td>
            <td><?= e(date('M j, Y', strtotime($ref['created_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
