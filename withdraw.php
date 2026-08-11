<?php
/**
 * withdraw.php — Earner withdrawal requests.
 * Payout method: USDT – TRC20 only.
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

$errors  = [];
$amount  = '';
$account = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$suspended) {
    verify_csrf();
    $amount  = $_POST['amount'] ?? '';
    $account = trim($_POST['account_details'] ?? '');

    if (!is_numeric($amount) || (float)$amount <= 0) {
        $errors[] = 'Please enter a valid withdrawal amount.';
    } elseif ((float)$amount < $minWd) {
        $errors[] = 'Minimum withdrawal for your plan is ' . money($minWd) . '.';
    } elseif ((float)$amount > $balance) {
        $errors[] = 'Amount exceeds your available balance of ' . money($balance) . '.';
    }

    if ($account === '') {
        $errors[] = 'Please provide your USDT TRC-20 payout address.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $deduct = $pdo->prepare('UPDATE users SET wallet_balance = wallet_balance - :amt WHERE id = :id AND wallet_balance >= :amt2');
            $deduct->execute([':amt' => $amount, ':id' => $user['id'], ':amt2' => $amount]);
            if ($deduct->rowCount() === 0) throw new RuntimeException('Insufficient balance.');

            $tx = $pdo->prepare('INSERT INTO wallet_transactions (user_id, type, amount, description) VALUES (:uid, "debit", :amt, "Withdrawal requested")');
            $tx->execute([':uid' => $user['id'], ':amt' => $amount]);

            $wd = $pdo->prepare('INSERT INTO withdrawals (user_id, amount, method, account_details) VALUES (:uid, :amt, "usdt_trc20", :acct)');
            $wd->execute([':uid' => $user['id'], ':amt' => $amount, ':acct' => $account]);

            $pdo->commit();
            log_activity($pdo, $user['id'], 'withdrawal_requested: ' . money((float)$amount));
            mail_withdrawal_requested($u['email'], $u['name'], (float)$amount, 'usdt_trc20', $account);
            flash('success', 'Withdrawal request submitted. An admin will review it shortly.');
            redirect('/withdraw.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Withdrawal error: ' . $e->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }
}

$uRow->execute([':id' => $user['id']]);
$u = $uRow->fetch();
$balance = (float) $u['wallet_balance'];

$histStmt = $pdo->prepare('SELECT * FROM withdrawals WHERE user_id = :id ORDER BY requested_at DESC LIMIT 50');
$histStmt->execute([':id' => $user['id']]);
$history = $histStmt->fetchAll();

$pageTitle = 'Withdraw — payNex';
require __DIR__ . '/includes/header.php';
?>

<div class="page-wrap">
  <div class="page-head">
    <h1><i class="fa-solid fa-arrow-up-from-bracket"></i> Withdrawals</h1>
    <p>Available balance: <strong><?= e(money($balance)) ?></strong><?php if ($vipPlan): ?>&nbsp;·&nbsp; Minimum: <strong><?= e(money($minWd)) ?></strong><?php endif; ?></p>
  </div>

  <?php if ($suspended): ?>
    <div class="suspended-overlay"><div><i class="fa-solid fa-ban"></i></div><h2>Account suspended</h2><p><?= e($u['suspension_note'] ?: 'Your account has been suspended. Withdrawals are not available.') ?></p></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:20px;"><i class="fa-solid fa-circle-exclamation"></i><div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div></div>
  <?php endif; ?>

  <div class="two-col">
    <div class="card">
      <h2><i class="fa-solid fa-clock-rotate-left"></i> Withdrawal history</h2>
      <?php if (!$history): ?>
        <p class="text-muted">No withdrawal requests yet.</p>
      <?php else: ?>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Amount</th><th>Method</th><th>Status</th><th>Requested</th></tr></thead>
          <tbody><?php foreach ($history as $wd): ?>
            <tr><td><strong><?= e(money($wd['amount'])) ?></strong></td><td><?= e(strtoupper($wd['method'])) ?></td>
            <td><span class="badge badge-<?= e($wd['status']) ?>"><?= e(ucfirst($wd['status'])) ?></span><?php if ($wd['status'] === 'rejected' && $wd['admin_note']): ?><br><small class="text-muted"><?= e($wd['admin_note']) ?></small><?php endif; ?></td>
            <td><?= e(date('M j, Y', strtotime($wd['requested_at']))) ?></td></tr>
          <?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </div>

    <div class="card <?= $suspended ? 'suspended-lock' : '' ?>">
      <h2><i class="fa-solid fa-paper-plane"></i> Request withdrawal</h2>
      <form id="wd-form" method="post" action="<?= BASE_URL ?>/confirm_withdraw.php" novalidate>
        <?= csrf_field() ?>

        <div class="field">
          <label><i class="fa-solid fa-dollar-sign"></i> Amount (USD)</label>
          <input type="number" id="wd-amount" name="amount" value="<?= e((string)$amount) ?>" min="<?= e(number_format($minWd, 2)) ?>" step="0.01" max="<?= e((string)$balance) ?>" required placeholder="<?= e(money($minWd)) ?> minimum">
        </div>

        <div class="field">
          <label><i class="fa-solid fa-circle-dollar-to-slot" style="color:#26a17b;"></i> Payout method</label>
          <div style="padding:10px 14px; background:#f4f6f4; border-radius:8px; font-weight:600; color:var(--ink);">
            <i class="fa-solid fa-circle-check" style="color:#26a17b;"></i> USDT – TRC20
          </div>
        </div>

        <div class="field">
          <label><i class="fa-solid fa-address-card"></i> USDT TRC-20 payout address</label>
          <input type="text" id="wd-account" name="account_details" value="<?= e($account ?: ($u['usdt_trc20_address'] ?? '')) ?>" placeholder="Your Tron (TRC-20) wallet address" required>
          <?php if ($u['usdt_trc20_address']): ?>
            <div class="input-hint">
              Saved address: <a href="#" onclick="document.getElementById('wd-account').value='<?= e($u['usdt_trc20_address']) ?>';return false;">Use my saved USDT address</a>
            </div>
          <?php endif; ?>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary btn-full"><i class="fa-solid fa-eye"></i> Review withdrawal details</button>
        </div>
        
      </form>
    </div>
  </div>
</div>

<script>
(function() {
  var accountInp = document.getElementById('wd-account');
  var usdtAddr = <?= json_encode($u['usdt_trc20_address'] ?? '') ?>;
  if (usdtAddr && accountInp && !accountInp.value) accountInp.value = usdtAddr;
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
