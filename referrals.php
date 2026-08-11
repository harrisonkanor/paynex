<?php
/**
 * referrals.php — Earner referral hub.
 *
 * Shows:
 *   - Unique referral link and referral code (copy-to-clipboard)
 *   - List of referred users with their VIP level and whether a bonus was paid
 *   - Total referral earnings
 *
 * Referral flow:
 *   1. User A signs up with User B's code → referrals row created.
 *   2. User A activates a VIP plan (deposits) → admin confirms deposit.
 *   3. maybe_award_referral_bonus() in functions.php credits User B.
 */
require_once __DIR__ . '/config/config.php';
require_login();

$user = current_user();

/* ---------------------------------------------------------------
 * Generate the referral link from the current HTTP host
 * ------------------------------------------------------------- */
$proto       = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
$referralLink = $proto . '://' . $host . BASE_URL . '/signup.php?ref=' . urlencode($user['referral_code']);

/* ---------------------------------------------------------------
 * Fetch all referrals this user has made
 * ------------------------------------------------------------- */
$refStmt = $pdo->prepare(
    'SELECT r.*, u.name, u.email, u.vip_level, u.created_at AS joined_at
     FROM referrals r
     JOIN users u ON u.id = r.referred_id
     WHERE r.referrer_id = :id
     ORDER BY r.created_at DESC'
);
$refStmt->execute([':id' => $user['id']]);
$referrals = $refStmt->fetchAll();

/* ---------------------------------------------------------------
 * Total referral earnings
 * ------------------------------------------------------------- */
$totalBonusStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(bonus_amount),0) FROM referrals
     WHERE referrer_id = :id AND bonus_paid = 1'
);
$totalBonusStmt->execute([':id' => $user['id']]);
$totalBonus = (float) $totalBonusStmt->fetchColumn();

$pageTitle = 'Referrals — payNex';
require __DIR__ . '/includes/header.php';
?>

<div class="page-wrap">
  <div class="page-head">
    <h1><i class="fa-solid fa-users" style="color:var(--green);"></i> Referrals</h1>
    <p>Share your link, earn bonuses when your referrals join a VIP plan.</p>
  </div>

  <!-- ============================================================
       REFERRAL CODE + LINK
       ========================================================= -->
  <div class="referral-box">
    <h3><i class="fa-solid fa-share-nodes"></i> Your referral details</h3>

    <!-- Big referral code display -->
    <div style="margin-bottom:16px;">
      <div style="font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:rgba(237,239,236,.55); margin-bottom:6px;">
        Referral code
      </div>
      <div class="ref-code-display"><?= e($user['referral_code']) ?></div>
    </div>

    <!-- Referral link with copy button -->
    <div style="font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:rgba(237,239,236,.55); margin-bottom:6px;">
      Referral link
    </div>
    <div class="ref-link-row">
      <input type="text" id="ref-link-input"
             value="<?= e($referralLink) ?>"
             readonly
             aria-label="Referral link">
      <button onclick="copyRefLink()" id="ref-copy-btn">
        <i class="fa-solid fa-copy"></i> Copy
      </button>
    </div>

    <!-- Stats row -->
    <div style="display:flex; gap:28px; margin-top:22px; flex-wrap:wrap;">
      <div>
        <div style="font-size:22px; font-weight:700; font-family:'Space Grotesk'; color:var(--green);">
          <?= count($referrals) ?>
        </div>
        <div style="font-size:12px; color:rgba(237,239,236,.6); text-transform:uppercase; letter-spacing:.05em;">
          Total referrals
        </div>
      </div>
      <div>
        <div style="font-size:22px; font-weight:700; font-family:'Space Grotesk'; color:var(--green);">
          <?= e(money($totalBonus)) ?>
        </div>
        <div style="font-size:12px; color:rgba(237,239,236,.6); text-transform:uppercase; letter-spacing:.05em;">
          Referral earnings
        </div>
      </div>
    </div>
  </div>

  <!-- ============================================================
       HOW THE REFERRAL BONUS WORKS
       ========================================================= -->
  <div class="card">
    <h2><i class="fa-solid fa-circle-info"></i> How referral rewards work</h2>
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px,1fr)); gap:16px; margin-top:12px;">
      <?php
      // Fetch VIP plans so we can display referral bonus info per tier
      $plans = $pdo->query('SELECT * FROM vip_plans ORDER BY level')->fetchAll();
      foreach ($plans as $plan):
      ?>
        <div style="background:var(--paper); border:1px solid var(--paper-line); border-radius:12px; padding:16px;">
          <div class="badge badge-vip<?= (int)$plan['level'] ?>" style="margin-bottom:8px;">
            <i class="fa-solid fa-crown"></i> <?= e($plan['label']) ?>
          </div>
          <div style="font-size:22px; font-weight:700; font-family:'Space Grotesk'; color:var(--green);">
            <?= e(money((float)$plan['referral_bonus'])) ?>
          </div>
          <div style="font-size:13px; color:var(--ink-soft); margin-top:4px;">
            per referral who joins <?= e($plan['label']) ?><br>
            (need <?= (int)$plan['referrals_needed'] ?> to unlock next level)
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <p style="font-size:13.5px; color:var(--ink-soft); margin-top:16px;">
      <i class="fa-solid fa-triangle-exclamation"></i>
      Bonus is credited to your wallet automatically when your referred user activates a VIP plan.
    </p>
  </div>

  <!-- ============================================================
       REFERRED USERS TABLE
       ========================================================= -->
  <div class="card">
    <h2><i class="fa-solid fa-list"></i> Your referrals (<?= count($referrals) ?>)</h2>

    <?php if (!$referrals): ?>
      <p class="text-muted">
        You haven't referred anyone yet.
        Share your link above to start earning bonuses!
      </p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th><i class="fa-solid fa-user"></i> Name</th>
              <th><i class="fa-solid fa-crown"></i> VIP level</th>
              <th><i class="fa-solid fa-calendar"></i> Joined</th>
              <th><i class="fa-solid fa-dollar-sign"></i> Bonus earned</th>
              <th><i class="fa-solid fa-check"></i> Status</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($referrals as $ref): ?>
            <tr>
              <td><?= e($ref['name']) ?></td>
              <td>
                <?php if ($ref['vip_level']): ?>
                  <span class="badge badge-vip<?= (int)$ref['vip_level'] ?>">
                    <i class="fa-solid fa-crown"></i>
                    VIP <?= (int)$ref['vip_level'] ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted">No plan</span>
                <?php endif; ?>
              </td>
              <td><?= e(date('M j, Y', strtotime($ref['joined_at']))) ?></td>
              <td>
                <?php if ($ref['bonus_paid']): ?>
                  <strong style="color:var(--green);"><?= e(money((float)$ref['bonus_amount'])) ?></strong>
                <?php else: ?>
                  <span class="text-muted">Pending</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($ref['bonus_paid']): ?>
                  <span class="badge badge-approved"><i class="fa-solid fa-check"></i> Paid</span>
                <?php else: ?>
                  <span class="badge badge-pending"><i class="fa-solid fa-hourglass-half"></i> Pending</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
/* Copy referral link to clipboard */
function copyRefLink() {
  var input = document.getElementById('ref-link-input');
  var btn   = document.getElementById('ref-copy-btn');
  navigator.clipboard.writeText(input.value).then(function () {
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
    setTimeout(function () {
      btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy';
    }, 2000);
  });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
