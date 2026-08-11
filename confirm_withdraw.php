<?php
/**
 * confirm_withdraw.php - Beautiful withdrawal confirmation page.
 * Shows a loader animation then reveals a polished summary card.
 */
require_once __DIR__ . '/config/config.php';
require_login();
require_once __DIR__ . '/includes/mailer.php';

$user = current_user();
$suspended = ($user['status'] === 'suspended');

$uRow = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$uRow->execute([':id' => $user['id']]);
$u = $uRow->fetch();

$balance  = (float) $u['wallet_balance'];
$vipLevel = (int) ($u['vip_level'] ?? 0);
$vipPlan  = $vipLevel ? get_vip_plan($pdo, $vipLevel) : null;
$minWd    = $vipPlan  ? (float) $vipPlan['min_withdrawal'] : 5.00;

$amount  = trim($_POST['amount'] ?? '');
$account = trim($_POST['account_details'] ?? '');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmed']) && !$suspended) {
    verify_csrf();
    if (!is_numeric($amount) || (float)$amount <= 0) { $errors[] = 'Please enter a valid withdrawal amount.'; }
    elseif ((float)$amount < $minWd) { $errors[] = 'Minimum withdrawal for your plan is ' . money($minWd) . '.'; }
    elseif ((float)$amount > $balance) { $errors[] = 'Amount exceeds your available balance of ' . money($balance) . '.'; }
    if ($account === '') { $errors[] = 'Please provide your USDT TRC-20 payout address.'; }
    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $deduct = $pdo->prepare('UPDATE users SET wallet_balance = wallet_balance - :amt WHERE id = :id AND wallet_balance >= :amt2');
            $deduct->execute([':amt' => $amount, ':id' => $user['id'], ':amt2' => $amount]);
            if ($deduct->rowCount() === 0) throw new RuntimeException('Insufficient balance.');
            $pdo->prepare('INSERT INTO wallet_transactions (user_id, type, amount, description) VALUES (:uid, "debit", :amt, "Withdrawal requested")')
                ->execute([':uid' => $user['id'], ':amt' => $amount]);
            $pdo->prepare('INSERT INTO withdrawals (user_id, amount, method, account_details) VALUES (:uid, :amt, "usdt_trc20", :acct)')
                ->execute([':uid' => $user['id'], ':amt' => $amount, ':acct' => $account]);
            $pdo->commit();
            log_activity($pdo, $user['id'], 'withdrawal_requested: ' . money((float)$amount));
            mail_withdrawal_requested($u['email'], $u['name'], (float)$amount, 'usdt_trc20', $account);
            flash('success', 'Withdrawal of ' . money((float)$amount) . ' submitted! An admin will review it shortly.');
            redirect('/withdraw.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Withdrawal error: ' . $e->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }
}

$pageTitle = 'Confirm Withdrawal - payNex';
require __DIR__ . '/includes/header.php';
?>

<style>
.wd-loader-overlay {
  position: fixed; inset: 0; z-index: 9999;
  background: rgba(10, 21, 32, 0.92);
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  opacity: 0; pointer-events: none;
  transition: opacity 0.4s ease;
}
.wd-loader-overlay.active { opacity: 1; pointer-events: all; }
.wd-loader-icon {
  width: 80px; height: 80px; border-radius: 50%;
  border: 4px solid rgba(138,210,74,0.15);
  border-top-color: var(--green);
  animation: wdSpin 0.8s linear infinite;
  position: relative;
}
.wd-loader-icon::before {
  content: '$'; position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 28px; font-weight: 700; color: var(--green);
  animation: wdPulse 1.2s ease-in-out infinite;
}
@keyframes wdSpin { to { transform: rotate(360deg); } }
@keyframes wdPulse {
  0%, 100% { transform: scale(1); opacity: 0.6; }
  50% { transform: scale(1.15); opacity: 1; }
}
.wd-loader-text {
  margin-top: 24px; font-size: 17px; font-weight: 600;
  color: rgba(237,239,236,0.9);
}
.wd-loader-sub {
  margin-top: 6px; font-size: 13px;
  color: rgba(237,239,236,0.4); font-family: monospace;
}
.wd-confirm-card {
  max-width: 520px; margin: 0 auto;
  opacity: 0; transform: translateY(20px);
  transition: opacity 0.5s ease, transform 0.5s ease;
}
.wd-confirm-card.visible { opacity: 1; transform: translateY(0); }
.wd-summary-card {
  background: linear-gradient(135deg, #0A1B29 0%, #0F1E2E 100%);
  border-radius: 16px; padding: 24px; color: #EDEFEC;
  margin-bottom: 20px; position: relative; overflow: hidden;
}
.wd-summary-card::before {
  content: ''; position: absolute; top: -50%; right: -30%;
  width: 200px; height: 200px; border-radius: 50%;
  background: radial-gradient(circle, rgba(138,210,74,0.06), transparent 70%);
  animation: wdGlow 4s ease-in-out infinite;
}
@keyframes wdGlow {
  0%, 100% { transform: translate(0,0) scale(1); opacity: 0.5; }
  50% { transform: translate(-10px, 10px) scale(1.2); opacity: 1; }
}
.wd-summary-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.06);
  position: relative; z-index: 1;
}
.wd-summary-row:last-of-type { border-bottom: none; }
.wd-summary-label {
  font-size: 13px; color: rgba(237,239,236,0.55);
  text-transform: uppercase; letter-spacing: 0.05em;
}
.wd-summary-value {
  font-size: 15px; font-weight: 600; text-align: right;
  max-width: 55%; word-break: break-all;
}
.wd-summary-amount {
  font-size: 28px; font-weight: 700;
  background: linear-gradient(90deg, #fff, #E8B54A);
  -webkit-background-clip: text; background-clip: text;
  -webkit-text-fill-color: transparent;
  position: relative; z-index: 1;
}
.wd-fee-note {
  font-size: 12px; color: rgba(237,239,236,0.35);
  text-align: center; margin-top: 12px; font-family: monospace;
}
.wd-warning-box {
  background: rgba(232,181,74,0.08);
  border: 1px solid rgba(232,181,74,0.15);
  border-radius: 12px; padding: 14px 16px; margin-bottom: 20px;
  display: flex; align-items: flex-start; gap: 10px;
}
.wd-warning-box i { color: #E8B54A; font-size: 16px; margin-top: 2px; }
.wd-warning-box p { font-size: 13px; color: #888; line-height: 1.5; margin: 0; }
.wd-actions { display: flex; gap: 12px; }
.wd-actions .btn { flex: 1; padding: 14px 20px; font-size: 15px; }
</style>

<div class="wd-loader-overlay" id="wd-loader">
  <div class="wd-loader-icon"></div>
  <div class="wd-loader-text">Sending your withdrawal<span id="wd-dots"></span></div>
  <div class="wd-loader-sub">Please do not close this page</div>
</div>

<div class="page-wrap" style="max-width:560px;">
  <?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:20px;"><i class="fa-solid fa-circle-exclamation"></i><div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div></div>
    <div style="text-align:center;margin-top:16px;"><a href="<?= BASE_URL ?>/withdraw.php" class="btn btn-dark"><i class="fa-solid fa-arrow-left"></i> Go back</a></div>
  <?php else: ?>
  <div class="wd-confirm-card" id="wd-confirm-card">
    <div class="page-head" style="text-align:center;">
      <h1><i class="fa-solid fa-circle-check" style="color:var(--green);"></i> Confirm withdrawal</h1>
      <p>Review the details below before confirming</p>
    </div>
    <div class="wd-summary-card">
      <div class="wd-summary-row">
        <span class="wd-summary-label">Amount</span>
        <span class="wd-summary-amount"><?= e(money((float)$amount)) ?></span>
      </div>
      <div class="wd-summary-row">
        <span class="wd-summary-label">Payout method</span>
        <span class="wd-summary-value" style="color:var(--green);"><i class="fa-solid fa-circle-dollar-to-slot"></i> USDT - TRC20</span>
      </div>
      <div class="wd-summary-row">
        <span class="wd-summary-label">Sending to</span>
        <span class="wd-summary-value" style="font-family:monospace;font-size:13px;color:rgba(237,239,236,0.7);"><?= e($account) ?></span>
      </div>
      <div class="wd-summary-row">
        <span class="wd-summary-label">Network</span>
        <span class="wd-summary-value" style="color:#26a17b;"><i class="fa-solid fa-link"></i> TRON (TRC-20)</span>
      </div>
      <div class="wd-summary-row">
        <span class="wd-summary-label">Processing time</span>
        <span class="wd-summary-value" style="font-size:13px;color:rgba(237,239,236,0.5);"><i class="fa-regular fa-clock"></i> 24-48 hours</span>
      </div>
      <div class="wd-fee-note"><i class="fa-solid fa-coins"></i> Network fee: covered by payNex</div>
    </div>
    <div class="wd-warning-box">
      <i class="fa-solid fa-circle-exclamation"></i>
      <p><strong>Double-check this address.</strong> Crypto transactions are irreversible. If the address is wrong, your funds cannot be recovered.</p>
    </div>
    <div class="wd-actions">
      <a href="<?= BASE_URL ?>/withdraw.php" class="btn btn-dark"><i class="fa-solid fa-arrow-left"></i> Go back</a>
      <form method="post" action="<?= BASE_URL ?>/confirm_withdraw.php" id="wd-confirm-form" style="flex:1;">
        <?= csrf_field() ?>
        <input type="hidden" name="amount" value="<?= e($amount) ?>">
        <input type="hidden" name="account_details" value="<?= e($account) ?>">
        <input type="hidden" name="confirmed" value="1">
        <button type="submit" class="btn btn-primary" style="width:100%;" id="wd-final-btn"><i class="fa-solid fa-paper-plane"></i> Yes, withdraw</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
(function() {
  <?php if (!$errors): ?>
  var l = document.getElementById('wd-loader');
  var c = document.getElementById('wd-confirm-card');
  var d = document.getElementById('wd-dots');
  var dc = 0;
  setTimeout(function(){ l.classList.add('active'); }, 100);
  var di = setInterval(function(){ if(d){ dc=(dc+1)%4; d.textContent='.'.repeat(dc); } }, 400);
  setTimeout(function(){ clearInterval(di); l.classList.remove('active'); c.classList.add('visible'); }, 2000);
  var fb = document.getElementById('wd-final-btn');
  var cf = document.getElementById('wd-confirm-form');
  if(fb&&cf){ cf.addEventListener('submit', function(){ fb.disabled=true; fb.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Processing...'; }); }
  <?php endif; ?>
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
