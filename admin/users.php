<?php
/**
 * admin/users.php — User management.
 *
 * Features:
 *   - Search by name or email
 *   - View VIP level, wallet balance, referrals, and real-time earnings
 *   - Suspend or reactivate accounts (with optional note shown to user)
 *   - Suspended users see their pages but cannot perform actions
 *
 * Security:
 *   - All DB queries use prepared statements
 *   - CSRF token on every POST form
 *   - Admin cannot suspend other admins
 */
require_once __DIR__ . '/../config/config.php';
require_admin();

$admin = $_SESSION['admin'];

/* ---------------------------------------------------------------
 * Handle suspend / activate POST
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $userId = (int) ($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $note   = trim($_POST['suspension_note'] ?? '');

    if ($userId > 0 && in_array($action, ['suspend', 'activate'], true)) {
        $newStatus = $action === 'suspend' ? 'suspended' : 'active';

        // Prepared stmt — never concatenate user input into SQL
        $pdo->prepare(
            "UPDATE users
             SET status = :status, suspension_note = :note
             WHERE id = :id AND role != 'admin'"
        )->execute([
            ':status' => $newStatus,
            ':note'   => $action === 'suspend' ? ($note ?: 'Your account has been suspended.') : null,
            ':id'     => $userId,
        ]);

        log_activity($pdo, $admin['id'], "admin_{$action}_user: #{$userId}");
        flash('success', 'User ' . $action . 'd successfully.');
    }

    redirect('/admin/users.php' . (($_GET['q'] ?? '') ? '?q=' . urlencode($_GET['q']) : ''));
}

/* ---------------------------------------------------------------
 * Search and fetch users with earnings + referral counts
 * ------------------------------------------------------------- */
$search = trim($_GET['q'] ?? '');

$sql = "SELECT u.*,
    COALESCE(
        (SELECT SUM(wt.amount)
         FROM wallet_transactions wt
         WHERE wt.user_id = u.id AND wt.type = 'credit'),
        0
    ) AS total_earned,
    COALESCE(
        (SELECT COUNT(*) FROM referrals r WHERE r.referrer_id = u.id),
        0
    ) AS referral_count,
    COALESCE(
        (SELECT SUM(r.bonus_amount) FROM referrals r
         WHERE r.referrer_id = u.id AND r.bonus_paid = 1),
        0
    ) AS referral_bonus_total
    FROM users u
    WHERE u.role != 'admin'";

if ($search !== '') {
    $sql .= ' AND (u.name LIKE :q OR u.email LIKE :q OR u.referral_code LIKE :q)';
}
$sql .= ' ORDER BY u.created_at DESC LIMIT 200';

$stmt = $pdo->prepare($sql);
if ($search !== '') {
    $stmt->execute([':q' => '%' . $search . '%']);
} else {
    $stmt->execute();
}
$users = $stmt->fetchAll();

$pageTitle = 'Manage users — payNex admin';
require __DIR__ . '/includes/admin_header.php';
?>

<div class="page-head">
  <h1><i class="fa-solid fa-users"></i> Users</h1>
  <p>Search, review, and manage earner accounts. See their VIP level, referrals, and live earnings.</p>
</div>

<!-- Search bar -->
<form method="get" action="<?= BASE_URL ?>/admin/users.php"
      style="display:flex; gap:10px; margin-bottom:20px;">
  <input type="text" name="q" value="<?= e($search) ?>"
         placeholder="Search by name, email, or referral code"
         style="flex:1; padding:10px 14px; border:1px solid var(--paper-line); border-radius:10px; font-size:14.5px;">
  <button type="submit" class="btn btn-dark btn-sm">
    <i class="fa-solid fa-magnifying-glass"></i> Search
  </button>
  <?php if ($search): ?>
    <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-sm" style="background:var(--paper-line);">
      <i class="fa-solid fa-xmark"></i> Clear
    </a>
  <?php endif; ?>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Name / Email</th>
          <th><i class="fa-solid fa-crown"></i> VIP</th>
          <th><i class="fa-solid fa-wallet"></i> Balance</th>
          <th><i class="fa-solid fa-sack-dollar"></i> Lifetime earned</th>
          <th><i class="fa-solid fa-users"></i> Referrals</th>
          <th><i class="fa-solid fa-gift"></i> Ref. bonuses</th>
          <th><i class="fa-solid fa-circle"></i> Status</th>
          <th>Joined</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td>
            <strong><?= e($u['name']) ?></strong><br>
            <small class="text-muted"><?= e($u['email']) ?></small><br>
            <small class="text-mono" style="font-size:11px; color:var(--ink-soft);">
              Code: <?= e($u['referral_code']) ?>
            </small>
          </td>
          <td>
            <?php if ($u['vip_level']): ?>
              <span class="badge badge-vip<?= (int)$u['vip_level'] ?>">
                <i class="fa-solid fa-crown"></i> VIP <?= (int)$u['vip_level'] ?>
              </span>
              <?php if ($u['vip_expires_at']): ?>
                <br><small class="text-muted">until <?= e(date('M j', strtotime($u['vip_expires_at']))) ?></small>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><?= e(money((float)$u['wallet_balance'])) ?></td>
          <td><?= e(money((float)$u['total_earned'])) ?></td>
          <td><?= (int)$u['referral_count'] ?></td>
          <td><?= e(money((float)$u['referral_bonus_total'])) ?></td>
          <td>
            <span class="badge badge-<?= e($u['status']) ?>">
              <?= e(ucfirst($u['status'])) ?>
            </span>
            <?php if ($u['status'] === 'suspended' && $u['suspension_note']): ?>
              <br><small class="text-muted" style="font-size:11px;">
                <?= e(mb_substr($u['suspension_note'], 0, 40)) ?>
              </small>
            <?php endif; ?>
          </td>
          <td><?= e(date('M j, Y', strtotime($u['created_at']))) ?></td>
          <td>
            <?php if ($u['status'] === 'active'): ?>
              <!-- Suspend form — includes optional note field -->
              <details style="cursor:pointer;">
                <summary class="btn btn-danger btn-sm" style="cursor:pointer; display:inline-block;">
                  <i class="fa-solid fa-ban"></i> Suspend
                </summary>
                <form method="post" style="margin-top:8px; padding:10px; background:var(--paper); border:1px solid var(--paper-line); border-radius:8px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                  <input type="hidden" name="action" value="suspend">
                  <div class="field" style="margin-bottom:8px;">
                    <label style="font-size:12px;">Reason (shown to user)</label>
                    <input type="text" name="suspension_note"
                           placeholder="e.g. Suspected fraud"
                           style="width:100%; padding:6px 10px; font-size:13px; border:1px solid var(--paper-line); border-radius:6px;">
                  </div>
                  <button type="submit" class="btn btn-danger btn-sm">
                    <i class="fa-solid fa-check"></i> Confirm suspend
                  </button>
                </form>
              </details>
            <?php else: ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="action" value="activate">
                <button type="submit" class="btn btn-primary btn-sm">
                  <i class="fa-solid fa-circle-check"></i> Reactivate
                </button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?>
        <tr><td colspan="9" class="text-muted" style="text-align:center; padding:20px;">
          No users found<?= $search ? ' matching "' . e($search) . '"' : '' ?>.
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
