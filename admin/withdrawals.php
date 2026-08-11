<?php
/**
 * admin/withdrawals.php — Manage withdrawal requests.
 *
 * States:
 *   pending  → admin can approve or reject
 *   approved → admin can mark as paid
 *   paid     → final state (paid out)
 *   rejected → amount refunded automatically to user wallet
 *
 * On rejection: the amount is refunded to the user's wallet
 * and a 'credit' transaction is inserted. A rejection email
 * is sent to the user if SMTP is configured.
 *
 * On mark-paid: payout email is sent to the user.
 *
 * Security:
 *   - Prepared statements on all queries
 *   - CSRF on every form
 *   - Refund runs inside a DB transaction to prevent partial updates
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/mailer.php'; // for email notifications
require_admin();

/* ---------------------------------------------------------------
 * Handle admin actions (POST)
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $wdId  = (int) ($_POST['withdrawal_id'] ?? 0);
    $act   = $_POST['action'] ?? '';
    $note  = trim($_POST['admin_note'] ?? '');

    // Fetch the withdrawal + user details
    $stmt = $pdo->prepare(
        'SELECT w.*, u.name, u.email
         FROM withdrawals w
         JOIN users u ON u.id = w.user_id
         WHERE w.id = :id'
    );
    $stmt->execute([':id' => $wdId]);
    $wd = $stmt->fetch();

    if (!$wd) {
        flash('error', 'Withdrawal not found.');
        redirect('/admin/withdrawals.php');
    }

    /* ---- Approve (pending → approved) ---- */
    if ($act === 'approve' && $wd['status'] === 'pending') {
        $pdo->prepare(
            'UPDATE withdrawals
             SET status = "approved", processed_at = NOW(), admin_note = :note
             WHERE id = :id'
        )->execute([':note' => $note ?: null, ':id' => $wdId]);

        log_activity($pdo, $_SESSION['admin']['id'], "wd_approved: #{$wdId}");
        flash('success', 'Withdrawal approved.');

    /* ---- Mark paid (approved → paid) ---- */
    } elseif ($act === 'mark_paid' && $wd['status'] === 'approved') {
        $pdo->prepare(
            'UPDATE withdrawals SET status = "paid", processed_at = NOW() WHERE id = :id'
        )->execute([':id' => $wdId]);

        log_activity($pdo, $_SESSION['admin']['id'], "wd_paid: #{$wdId}");
        // Send payout email to the user
        mail_withdrawal_paid($wd['email'], $wd['name'], (float)$wd['amount']);
        flash('success', 'Marked as paid — email sent to user.');

    /* ---- Reject (pending → rejected) — auto-refund ---- */
    } elseif ($act === 'reject' && $wd['status'] === 'pending') {
        $pdo->beginTransaction();
        try {
            // Refund the amount back to the user's wallet
            $pdo->prepare(
                'UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :uid'
            )->execute([':amt' => $wd['amount'], ':uid' => $wd['user_id']]);

            // Record the refund in the wallet ledger
            $pdo->prepare(
                'INSERT INTO wallet_transactions (user_id, type, amount, description)
                 VALUES (:uid, "credit", :amt, "Withdrawal rejected — refunded")'
            )->execute([':uid' => $wd['user_id'], ':amt' => $wd['amount']]);

            // Mark withdrawal as rejected with optional admin note
            $pdo->prepare(
                'UPDATE withdrawals
                 SET status = "rejected", processed_at = NOW(), admin_note = :note
                 WHERE id = :id'
            )->execute([':note' => $note ?: 'Rejected by admin.', ':id' => $wdId]);

            $pdo->commit();
            log_activity($pdo, $_SESSION['admin']['id'], "wd_rejected: #{$wdId}");

            // Send rejection + refund email to the user
            mail_withdrawal_rejected($wd['email'], $wd['name'], (float)$wd['amount'], $note);
            flash('success', 'Withdrawal rejected and amount refunded to the user.');

        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Withdrawal rejection error: ' . $e->getMessage());
            flash('error', 'Could not process rejection. Please try again.');
        }
    } else {
        flash('error', 'Invalid action or incorrect status for this operation.');
    }

    redirect('/admin/withdrawals.php');
}

/* ---------------------------------------------------------------
 * Fetch withdrawals — filter by status
 * ------------------------------------------------------------- */
$filter = in_array($_GET['status'] ?? '', ['pending','approved','paid','rejected'], true)
          ? $_GET['status'] : 'pending';

$wds = $pdo->prepare(
    "SELECT w.*, u.name, u.email
     FROM withdrawals w
     JOIN users u ON u.id = w.user_id
     WHERE w.status = :status
     ORDER BY w.requested_at DESC
     LIMIT 200"
);
$wds->execute([':status' => $filter]);
$withdrawals = $wds->fetchAll();

$counts = $pdo->query(
    'SELECT status, COUNT(*) AS n FROM withdrawals GROUP BY status'
)->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Withdrawals — payNex admin';
require __DIR__ . '/includes/admin_header.php';
?>

<div class="page-head">
  <h1><i class="fa-solid fa-arrow-up-from-bracket"></i> Withdrawals</h1>
  <p>Process user withdrawal requests. Rejected requests are automatically refunded.</p>
</div>

<!-- Status filter tabs -->
<div style="display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap;">
  <?php foreach (['pending','approved','paid','rejected'] as $s): ?>
    <a href="?status=<?= $s ?>"
       class="btn btn-sm <?= $s === $filter ? 'btn-primary' : 'btn-dark' ?>">
      <?= ucfirst($s) ?>
      <?php if (!empty($counts[$s])): ?>
        <span style="background:rgba(255,255,255,.25); border-radius:999px; padding:1px 7px; font-size:11px; margin-left:4px;">
          <?= (int)$counts[$s] ?>
        </span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (!$withdrawals): ?>
    <p class="text-muted">No <?= e($filter) ?> withdrawals.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>User</th>
            <th>Amount</th>
            <th>Method</th>
            <th>Address / Account</th>
            <th>Status</th>
            <th>Requested</th>
            <?php if (in_array($filter, ['pending','approved'], true)): ?>
              <th>Action</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($withdrawals as $wd): ?>
          <tr>
            <td>
              <strong><?= e($wd['name']) ?></strong><br>
              <small class="text-muted"><?= e($wd['email']) ?></small>
            </td>
            <td><strong><?= e(money((float)$wd['amount'])) ?></strong></td>
            <td><?= e(strtoupper($wd['method'])) ?></td>
            <td style="max-width:220px; word-break:break-all; font-size:12.5px; font-family:monospace;">
              <?= e($wd['account_details']) ?>
            </td>
            <td>
              <span class="badge badge-<?= e($wd['status']) ?>">
                <?= e(ucfirst($wd['status'])) ?>
              </span>
              <?php if ($wd['admin_note']): ?>
                <br><small class="text-muted"><?= e(mb_substr($wd['admin_note'], 0, 50)) ?></small>
              <?php endif; ?>
            </td>
            <td><?= e(date('M j, Y', strtotime($wd['requested_at']))) ?></td>

            <?php if ($filter === 'pending'): ?>
              <td style="white-space:nowrap;">
                <!-- Approve -->
                <form method="post" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="withdrawal_id" value="<?= (int)$wd['id'] ?>">
                  <input type="hidden" name="action" value="approve">
                  <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-check"></i> Approve
                  </button>
                </form>
                <!-- Reject with optional note -->
                <details style="display:inline-block; vertical-align:middle; margin-left:4px;">
                  <summary class="btn btn-danger btn-sm" style="cursor:pointer;">
                    <i class="fa-solid fa-xmark"></i> Reject
                  </summary>
                  <form method="post" style="margin-top:6px; padding:10px; background:var(--paper); border:1px solid var(--paper-line); border-radius:8px; min-width:200px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="withdrawal_id" value="<?= (int)$wd['id'] ?>">
                    <input type="hidden" name="action" value="reject">
                    <div class="field" style="margin-bottom:8px;">
                      <label style="font-size:12px;">Rejection reason</label>
                      <input type="text" name="admin_note"
                             placeholder="e.g. Invalid address"
                             style="width:100%; padding:6px 10px; font-size:13px; border:1px solid var(--paper-line); border-radius:6px;">
                    </div>
                    <button type="submit" class="btn btn-danger btn-sm">
                      <i class="fa-solid fa-check"></i> Confirm reject
                    </button>
                  </form>
                </details>
              </td>
            <?php elseif ($filter === 'approved'): ?>
              <td>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="withdrawal_id" value="<?= (int)$wd['id'] ?>">
                  <input type="hidden" name="action" value="mark_paid">
                  <button type="submit" class="btn btn-dark btn-sm">
                    <i class="fa-solid fa-paper-plane"></i> Mark paid
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
