<?php
require_once __DIR__ . '/../config/config.php';
require_admin();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$total = (int) $pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT al.*, u.name FROM activity_logs al
     LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

$totalPages = max(1, (int) ceil($total / $perPage));

$pageTitle = 'Activity logs — payNex admin';
require __DIR__ . '/includes/admin_header.php';
?>

<div class="page-head">
  <h1>Activity logs</h1>
  <p>A full, auditable trail of actions across the platform.</p>
</div>

<div class="card">
  <table class="data-table">
    <thead><tr><th>User</th><th>Action</th><th>IP address</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $log): ?>
      <tr>
        <td><?= e($log['name'] ?? 'System / guest') ?></td>
        <td><?= e($log['action']) ?></td>
        <td class="mono"><?= e($log['ip_address']) ?></td>
        <td><?= e(date('M j, Y g:ia', strtotime($log['created_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div style="display:flex; justify-content:space-between; margin-top:16px; font-size:13.5px; color:var(--ink-soft);">
    <span>Page <?= $page ?> of <?= $totalPages ?></span>
    <span>
      <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">← Previous</a><?php endif; ?>
      <?php if ($page < $totalPages): ?> &nbsp; <a href="?page=<?= $page + 1 ?>">Next →</a><?php endif; ?>
    </span>
  </div>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
