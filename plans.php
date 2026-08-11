<?php
/**
 * plans.php — VIP plan selection + deposit flow.
 */
require_once __DIR__ . '/config/config.php';
require_login();

$user = current_user();
if ($user['role'] === 'admin') redirect('/admin/index.php');

$uStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$uStmt->execute([':id' => $user['id']]);
$u = $uStmt->fetch();

$plans = $pdo->query('SELECT * FROM vip_plans ORDER BY level')->fetchAll();

$depositBtc  = get_setting($pdo, 'deposit_wallet_btc');
$depositUsdt = get_setting($pdo, 'deposit_wallet_usdt');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $u['status'] !== 'suspended') {
    verify_csrf();
    $vipLevel = (int) ($_POST['vip_level'] ?? 0);
    $txHash   = trim($_POST['tx_hash'] ?? '');
    $method   = in_array($_POST['method'] ?? '', ['btc', 'usdt_trc20'], true) ? $_POST['method'] : '';
    $planCheck = array_filter($plans, fn($p) => (int)$p['level'] === $vipLevel);
    if (!$planCheck) $errors[] = 'Please choose a valid VIP plan.';
    if ($txHash === '') $errors[] = 'Please enter your transaction hash / proof of payment.';
    if ($method === '') $errors[] = 'Please select the currency you used to deposit.';
    $dup = $pdo->prepare("SELECT id FROM deposit_orders WHERE user_id = :uid AND vip_level = :lv AND status = 'pending'");
    $dup->execute([':uid' => $user['id'], ':lv' => $vipLevel]);
    if ($dup->fetchColumn()) $errors[] = 'You already have a pending deposit for this VIP level. Please wait for admin confirmation.';
    if (!$errors) {
        $plan = current($planCheck);
        $stmt = $pdo->prepare('INSERT INTO deposit_orders (user_id, vip_level, amount, tx_hash) VALUES (:uid, :lv, :amt, :tx)');
        $stmt->execute([':uid' => $user['id'], ':lv' => $vipLevel, ':amt' => $plan['deposit_amount'], ':tx' => $txHash]);
        log_activity($pdo, $user['id'], "deposit_submitted: VIP{$vipLevel}");
        flash('success', 'Deposit submitted! An admin will confirm it shortly.');
        redirect('/plans.php');
    }
}

$ordersStmt = $pdo->prepare('SELECT d.*, vp.label FROM deposit_orders d JOIN vip_plans vp ON vp.level = d.vip_level WHERE d.user_id = :uid ORDER BY d.created_at DESC LIMIT 10');
$ordersStmt->execute([':uid' => $user['id']]);
$myOrders = $ordersStmt->fetchAll();

$pageTitle = 'VIP Plans — payNex';
require __DIR__ . '/includes/header.php';
?>

<div class="page-wrap">
  <div class="page-head">
    <h1><i class="fa-solid fa-crown" style="color:var(--green);"></i> VIP Plans</h1>
    <p>Choose a plan, deposit the required amount, and start completing paid daily tasks.</p>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:20px;">
      <i class="fa-solid fa-circle-exclamation"></i>
      <div><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
    </div>
  <?php endif; ?>

  <?php if ($u['status'] === 'suspended'): ?>
    <div class="suspended-overlay">
      <div><i class="fa-solid fa-ban"></i></div>
      <h2>Account suspended</h2>
      <p><?= e($u['suspension_note'] ?: 'Your account has been suspended.') ?></p>
    </div>
  <?php endif; ?>

  <div class="vip-grid">
    <?php foreach ($plans as $plan):
      $isCurrent = ((int)($u['vip_level'] ?? 0) === (int)$plan['level']);
      $weekly    = $plan['task_reward'] * $plan['tasks_per_day'] * $plan['working_days'];
    ?>
      <div class="vip-card <?= $isCurrent ? 'current' : '' ?>">
        <?php if ($isCurrent): ?>
          <div class="vip-badge"><i class="fa-solid fa-check"></i> Active</div>
        <?php endif; ?>

        <h3>
          <i class="fa-solid fa-crown"
             style="color:<?= ['#94a3b8','#8AD24A','#F7931A'][$plan['level']-1] ?>;"></i>
          <?= e($plan['label']) ?>
        </h3>
        <div class="vip-price">
          <span>$</span><?= number_format($plan['deposit_amount'], 0) ?>
          <span style="font-size:13px; font-weight:400;"> deposit</span>
        </div>

        <ul class="vip-features">
          <li style="margin-bottom:18px;">
            <i class="fa-solid fa-check"></i>
            <span><strong><?= (int)$plan['tasks_per_day'] ?> tasks/day</strong>
              (Mon–Fri)</span>
          </li>
          <li style="margin-bottom:18px;">
            <i class="fa-solid fa-check"></i>
            <span>Earn
              <strong><?= e(money((float)$plan['task_reward'])) ?>/task</strong></span>
          </li>
          <li style="margin-bottom:18px;">
            <i class="fa-solid fa-check"></i>
            <span>Weekly income:
              <strong><?= e(money($weekly)) ?></strong></span>
          </li>
          <li style="margin-bottom:18px;">
            <i class="fa-solid fa-check"></i>
            <span>Min. withdrawal:
              <strong><?= e(money((float)$plan['min_withdrawal'])) ?></strong></span>
          </li>
          <li style="margin-bottom:18px;">
            <i class="fa-solid fa-check"></i>
            <span>Referral bonus:
              <strong><?= e(money((float)$plan['referral_bonus'])) ?>/referral</strong></span>
          </li>
          <li>
            <i class="fa-solid fa-check"></i>
            <span>Need <strong><?= (int)$plan['referrals_needed'] ?> referrals</strong>
              to unlock next tier</span>
          </li>
        </ul>

        <?php if (!$isCurrent && $u['status'] !== 'suspended'): ?>
          <button class="btn btn-primary btn-full"
                  onclick="openDepositForm(<?= (int)$plan['level'] ?>, <?= e(json_encode($plan['deposit_amount'])) ?>)">
            <i class="fa-solid fa-arrow-right"></i>
            <?= (int)($u['vip_level'] ?? 0) > 0 ? 'Switch to ' . e($plan['label']) : 'Activate ' . e($plan['label']) ?>
          </button>
        <?php elseif ($isCurrent): ?>
          <div class="btn btn-dark btn-full" style="cursor:default; opacity:.6;">
            <i class="fa-solid fa-circle-check"></i> Current plan
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card" id="deposit-section" style="display:none;">
    <h2><i class="fa-solid fa-money-bill-transfer"></i> Make your deposit</h2>
    <p id="deposit-instruction" class="text-muted" style="font-size:14.5px; margin-bottom:16px;"></p>

    <div class="deposit-address-box">
      <div class="addr-label">
        <i class="fa-solid fa-circle-dollar-to-slot" style="color:#26a17b;"></i>
        USDT TRC-20 deposit address
      </div>
      <div class="addr-value"><?= e($depositUsdt ?: 'Not configured — contact support') ?></div>
      <?php if ($depositUsdt): ?>
        <button class="copy-btn" onclick="copyAddr(this,'<?= e($depositUsdt) ?>')">
          <i class="fa-solid fa-copy"></i> Copy
        </button>
      <?php endif; ?>
    </div>

    <div class="alert alert-info" style="margin:16px 0;">
      <i class="fa-solid fa-circle-info"></i>
      After sending, paste your <strong>transaction hash</strong> below.
      An admin will verify it and activate your plan within 2 hours.
    </div>

    <form method="post" action="<?= BASE_URL ?>/plans.php" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="vip_level" id="deposit-vip-level" value="">

      <div class="field">
        <label><i class="fa-solid fa-coins"></i> Currency used</label>
        <select name="method">
          <!-- <option value="btc">Bitcoin (BTC)</option> -->
          <option value="usdt_trc20">USDT – TRC20</option>
        </select>
      </div>

      <div class="field">
        <label><i class="fa-solid fa-hashtag"></i> Transaction hash / TXID</label>
        <input type="text" name="tx_hash" required
               placeholder="Paste your full transaction hash here">
        <div class="input-hint">
          Find the TXID in your wallet app after sending.
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">
          <i class="fa-solid fa-paper-plane"></i> Submit deposit for review
        </button>
        <button type="button" class="btn btn-dark"
                onclick="document.getElementById('deposit-section').style.display='none';"
                style="margin-top:8px;">
          <i class="fa-solid fa-arrow-left"></i> Cancel
        </button>
      </div>
    </form>
  </div>

  <?php if ($myOrders): ?>
    <div class="card">
      <h2><i class="fa-solid fa-clock-rotate-left"></i> Deposit history</h2>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr><th>Plan</th><th>Amount</th><th>TX Hash</th><th>Status</th><th>Submitted</th></tr>
          </thead>
          <tbody>
          <?php foreach ($myOrders as $order): ?>
            <tr>
              <td><?= e($order['label']) ?></td>
              <td><?= e(money((float)$order['amount'])) ?></td>
              <td class="text-mono" style="font-size:12px; max-width:160px; overflow:hidden; text-overflow:ellipsis;"><?= e($order['tx_hash'] ?? '—') ?></td>
              <td><span class="badge badge-<?= e($order['status']) ?>"><?= e(ucfirst($order['status'])) ?></span></td>
              <td><?= e(date('M j, Y', strtotime($order['created_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
function openDepositForm(level, amount) {
  document.getElementById('deposit-vip-level').value = level;
  document.getElementById('deposit-instruction').textContent =
    'Send exactly $' + parseFloat(amount).toFixed(2) +
    ' to one of the addresses below, then paste your transaction hash.';
  var section = document.getElementById('deposit-section');
  section.style.display = 'block';
  section.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function copyAddr(btn, addr) {
  navigator.clipboard.writeText(addr).then(function () {
    btn.classList.add('copied');
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
    setTimeout(function () {
      btn.classList.remove('copied');
      btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy';
    }, 2000);
  });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
