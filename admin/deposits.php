<?php
/**
 * admin/deposits.php — Confirm or reject user deposit orders.
 *
 * When a user submits a deposit (tx hash) to activate a VIP plan,
 * it creates a deposit_order row with status='pending'.
 *
 * Admin actions:
 *   confirm → sets user.vip_level, user.vip_expires_at (+30 days),
 *             inserts a 'credit' wallet_transaction, calls
 *             maybe_award_referral_bonus() to credit the referrer,
 *             marks deposit_order confirmed.
 *   reject  → sets deposit_order status = rejected.
 *
 * Security:
 *   - Prepared statements throughout
 *   - CSRF token on every form
 *   - Entire confirm runs inside a DB transaction
 */
require_once __DIR__ . '/../config/config.php';
require_admin();

/* ---------------------------------------------------------------
 * Handle confirm / reject POST
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $orderId = (int) ($_POST['order_id']  ?? 0);
    $action  = $_POST['action'] ?? '';

    $stmt = $pdo->prepare(
        "SELECT d.*, vp.deposit_amount, vp.label, u.name AS uname, u.email
         FROM deposit_orders d
         JOIN vip_plans vp ON vp.level = d.vip_level
         JOIN users u ON u.id = d.user_id
         WHERE d.id = :id AND d.status = 'pending'"
    );
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        flash('error', 'Deposit order not found or already processed.');
        redirect('/admin/deposits.php');
    }

    if ($action === 'confirm') {
        $pdo->beginTransaction();
        try {
            // 1. Activate the VIP plan for the user (valid for 30 days)
            $expiry = date('Y-m-d', strtotime('+30 days'));
            $pdo->prepare(
                'UPDATE users
                 SET vip_level = :lv, vip_expires_at = :exp
                 WHERE id = :uid'
            )->execute([':lv' => $order['vip_level'], ':exp' => $expiry, ':uid' => $order['user_id']]);

            // 2. Credit the deposit amount to the user's wallet
            //    (so their deposit money is tracked as a credit balance)
            $pdo->prepare(
                'UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :uid'
            )->execute([':amt' => $order['deposit_amount'], ':uid' => $order['user_id']]);

            // 3. Record wallet transaction
            $pdo->prepare(
                'INSERT INTO wallet_transactions (user_id, type, amount, description)
                 VALUES (:uid, "credit", :amt, :desc)'
            )->execute([
                ':uid'  => $order['user_id'],
                ':amt'  => $order['deposit_amount'],
                ':desc' => 'Deposit confirmed — ' . $order['label'] . ' activated',
            ]);

            // 4. Mark the deposit order confirmed
            $pdo->prepare(
                'UPDATE deposit_orders
                 SET status = "confirmed", confirmed_at = NOW()
                 WHERE id = :id'
            )->execute([':id' => $orderId]);

            // 5. Award referral bonus if this user was referred
            maybe_award_referral_bonus($pdo, (int)$order['user_id'], (int)$order['vip_level']);

            $pdo->commit();
            log_activity($pdo, $_SESSION['admin']['id'],
                "deposit_confirmed: order#{$orderId} user#{$order['user_id']} VIP{$order['vip_level']}");
            flash('success', 'Deposit confirmed — ' . e($order['uname']) . ' is now on ' . e($order['label']) . '.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Deposit confirm error: ' . $e->getMessage());
            flash('error', 'Could not confirm deposit. Please try again.');
        }

    } elseif ($action === 'reject') {
        $pdo->prepare(
            'UPDATE deposit_orders SET status = "rejected" WHERE id = :id'
        )->execute([':id' => $orderId]);
        log_activity($pdo, $_SESSION['admin']['id'], "deposit_rejected: order#{$orderId}");
        flash('success', 'Deposit rejected.');
    }

    redirect('/admin/deposits.php');
}

/* ---------------------------------------------------------------
 * Fetch deposit orders
 * ------------------------------------------------------------- */
$filter = in_array($_GET['status'] ?? '', ['pending','confirmed','rejected'], true)
          ? $_GET['status'] : 'pending';

$orders = $pdo->prepare(
    "SELECT d.*, vp.label, vp.deposit_amount AS expected_amt,
            u.name AS uname, u.email
     FROM deposit_orders d
     JOIN vip_plans vp ON vp.level = d.vip_level
     JOIN users u ON u.id = d.user_id
     WHERE d.status = :status
     ORDER BY d.created_at DESC
     LIMIT 200"
);
$orders->execute([':status' => $filter]);
$depositOrders = $orders->fetchAll();

$counts = $pdo->query(
    'SELECT status, COUNT(*) AS n FROM deposit_orders GROUP BY status'
)->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Deposits — payNex admin';
require __DIR__ . '/includes/admin_header.php';
?>

<div class="page-head">
  <h1><i class="fa-solid fa-money-bill"></i> Deposit orders</h1>
  <p>
    Confirm user deposits to activate their VIP plans.
    Always verify the transaction hash on the blockchain before confirming.
  </p>
</div>

<!-- Status filter tabs -->
<div style="display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap;">
  <?php foreach (['pending','confirmed','rejected'] as $s): ?>
    <a href="?status=<?= $s ?>"
       class="btn btn-sm <?= $s === $filter ? 'btn-primary' : 'btn-dark' ?>">
      <?= ucfirst($s) ?>
      <?php if (!empty($counts[$s])): ?>
        <span style="background:rgba(255,255,255,.25);border-radius:999px;padding:1px 7px;font-size:11px;margin-left:4px;">
          <?= (int)$counts[$s] ?>
        </span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (!$depositOrders): ?>
    <p class="text-muted">No <?= e($filter) ?> deposit orders.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>User</th>
            <th>Plan</th>
            <th>Amount</th>
            <th>TX Hash</th>
            <th>Submitted</th>
            <th>Status</th>
            <?php if ($filter === 'pending'): ?><th>Action</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($depositOrders as $order): ?>
          <tr>
            <td>
              <strong><?= e($order['uname']) ?></strong><br>
              <small class="text-muted"><?= e($order['email']) ?></small>
            </td>
            <td>
              <span class="badge badge-vip<?= (int)$order['vip_level'] ?>">
                <i class="fa-solid fa-crown"></i> <?= e($order['label']) ?>
              </span>
            </td>
            <td><?= e(money((float)$order['amount'])) ?></td>
            <td style="max-width:200px; word-break:break-all; font-size:12px; font-family:monospace;">
              <?= e($order['tx_hash'] ?? '—') ?>
              <?php if ($order['tx_hash']): ?>
                <!-- BTC block explorer link -->
                <br>
                <a href="https://www.blockchain.com/explorer/transactions/btc/<?= urlencode($order['tx_hash']) ?>"
                   target="_blank" rel="noopener"
                   style="font-size:11px; color:var(--blue);">
                  <i class="fa-solid fa-arrow-up-right-from-square"></i> Verify on blockchain
                </a>
              <?php endif; ?>
            </td>
            <td><?= e(date('M j, Y', strtotime($order['created_at']))) ?></td>
            <td>
              <span class="badge badge-<?= e($order['status']) ?>">
                <?= e(ucfirst($order['status'])) ?>
              </span>
            </td>
            <?php if ($filter === 'pending'): ?>
              <td style="white-space:nowrap;">
                <form method="post" style="display:inline;"
                      onsubmit="return confirm('Confirm this deposit and activate the VIP plan?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                  <input type="hidden" name="action" value="confirm">
                  <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-check"></i> Confirm
                  </button>
                </form>
                <form method="post" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                  <input type="hidden" name="action" value="reject">
                  <button type="submit" class="btn btn-danger btn-sm">
                    <i class="fa-solid fa-xmark"></i> Reject
                  </button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
