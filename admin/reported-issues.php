<?php
/**
 * admin/reported-issues.php — Admin reviews user-reported issues.
 */
require_once __DIR__ . '/../config/config.php';
require_admin();

// Handle resolve POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $issueId = (int)($_POST['issue_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    
    if ($action === 'resolve' && $issueId) {
        $pdo->prepare(
            'UPDATE reported_issues SET status = "resolved", resolved_at = NOW(), admin_notes = :notes WHERE id = :id'
        )->execute([':notes' => trim($_POST['admin_notes'] ?? ''), ':id' => $issueId]);
        log_activity($pdo, $_SESSION['admin']['id'], "issue_resolved: #{$issueId}");
        flash('success', 'Issue marked as resolved.');
    } elseif ($action === 'reopen' && $issueId) {
        $pdo->prepare(
            'UPDATE reported_issues SET status = "open", resolved_at = NULL WHERE id = :id'
        )->execute([':id' => $issueId]);
        flash('success', 'Issue reopened.');
    }
    redirect('/admin/reported-issues.php');
}

$filter = in_array($_GET['status'] ?? '', ['open','resolved'], true) ? $_GET['status'] : 'open';

$issues = $pdo->prepare(
    'SELECT ri.*, u.name AS user_name, u.email AS user_email
     FROM reported_issues ri
     JOIN users u ON u.id = ri.user_id
     WHERE ri.status = :status
     ORDER BY ri.created_at DESC LIMIT 100'
);
$issues->execute([':status' => $filter]);
$allIssues = $issues->fetchAll();

$countOpen = (int)$pdo->query("SELECT COUNT(*) FROM reported_issues WHERE status='open'")->fetchColumn();

$pageTitle = 'Reported Issues — payNex admin';
require __DIR__ . '/includes/admin_header.php';
?>

<div class="page-head">
  <h1><i class="fa-solid fa-triangle-exclamation"></i> Reported Issues</h1>
  <p><?= $countOpen ?> open issue(s) needing attention.</p>
</div>

<div style="display:flex;gap:8px;margin-bottom:20px;">
  <a href="?status=open" class="btn btn-sm <?= $filter==='open'?'btn-primary':'btn-dark' ?>">
    Open <?= $countOpen ? "<span style='background:rgba(255,255,255,.25);border-radius:999px;padding:1px 7px;font-size:11px;margin-left:4px;'>{$countOpen}</span>" : '' ?>
  </a>
  <a href="?status=resolved" class="btn btn-sm <?= $filter==='resolved'?'btn-primary':'btn-dark' ?>">Resolved</a>
</div>

<div class="card">
  <?php if (!$allIssues): ?>
    <p class="text-muted">No <?= e($filter) ?> issues.</p>
  <?php else: ?>
    <?php foreach ($allIssues as $issue): ?>
      <div style="border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:18px;margin-bottom:14px;background:rgba(255,255,255,.02);">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
          <div>
            <strong style="color:#EDEFEC;font-size:15px;"><?= e($issue['subject']) ?></strong>
            <span class="badge <?= $issue['status']==='open'?'badge-pending':'badge-approved' ?>" style="margin-left:8px;">
              <?= e($issue['status']) ?>
            </span>
          </div>
          <small style="color:rgba(237,239,236,.45);">
            <?= e(date('M j, Y g:ia', strtotime($issue['created_at']))) ?>
          </small>
        </div>
        <p style="margin:10px 0;font-size:13.5px;color:rgba(237,239,236,.72);line-height:1.6;">
          <?= nl2br(e($issue['description'])) ?>
        </p>
        <div style="font-size:12px;color:rgba(237,239,236,.5);margin-bottom:12px;">
          Reported by <strong style="color:rgba(237,239,236,.7);"><?= e($issue['user_name']) ?></strong>
          (<?= e($issue['user_email']) ?>)
        </div>
        
        <?php if ($issue['status'] === 'open'): ?>
          <form method="post" style="margin-top:8px;">
            <?= csrf_field() ?>
            <input type="hidden" name="issue_id" value="<?= (int)$issue['id'] ?>">
            <input type="hidden" name="action" value="resolve">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <input type="text" name="admin_notes" placeholder="Resolution notes (optional)"
                     style="flex:1;min-width:200px;padding:8px 12px;border:1px solid rgba(255,255,255,.1);border-radius:8px;background:rgba(10,27,41,.4);color:#EDEFEC;font-size:13px;">
              <button type="submit" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-check"></i> Resolve
              </button>
            </div>
          </form>
        <?php else: ?>
          <?php if ($issue['admin_notes']): ?>
            <div style="font-size:13px;color:rgba(138,210,74,.7);margin-top:8px;padding:10px;background:rgba(138,210,74,.05);border-radius:8px;">
              <i class="fa-solid fa-reply"></i> <?= nl2br(e($issue['admin_notes'])) ?>
            </div>
          <?php endif; ?>
          <form method="post" style="margin-top:8px;">
            <?= csrf_field() ?>
            <input type="hidden" name="issue_id" value="<?= (int)$issue['id'] ?>">
            <input type="hidden" name="action" value="reopen">
            <button type="submit" class="btn btn-sm btn-dark">
              <i class="fa-solid fa-rotate-left"></i> Reopen
            </button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
