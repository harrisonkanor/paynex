<?php
/**
 * Admin panel shared header.
 * Loads config (which starts the session), checks admin auth,
 * then renders the HTML head, Font Awesome, flash messages,
 * and a sidebar layout with full navigation.
 *
 * All admin pages do:
 *   require_once __DIR__ . '/../config/config.php';
 *   require_admin();
 *   $pageTitle = '...';
 *   require __DIR__ . '/includes/admin_header.php';
 */
if (!isset($pdo)) {
    require_once __DIR__ . '/../../config/config.php';
}
$adminSession = $_SESSION['admin'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Admin — payNex') ?></title>
<link rel="icon" href="<?= BASE_URL ?>/assets/img/logo.png">
<!-- Font Awesome 6 Free -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<!-- Original design tokens -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<!-- App supplementary styles (badges, cards, forms, sidebar) -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
</head>
<body>

<!-- ============================================================
     FLASH MESSAGES
     ========================================================= -->
<?php if ($fm = flash('error')): ?>
  <div style="background:rgba(226,104,95,.13); color:#9c2c22; border-bottom:1px solid rgba(226,104,95,.3); padding:12px 28px; font-size:14px;">
    <i class="fa-solid fa-circle-exclamation"></i> <?= e($fm) ?>
  </div>
<?php endif; ?>
<?php if ($fm = flash('success')): ?>
  <div style="background:rgba(138,210,74,.13); color:#2f5c12; border-bottom:1px solid rgba(138,210,74,.3); padding:12px 28px; font-size:14px;">
    <i class="fa-solid fa-circle-check"></i> <?= e($fm) ?>
  </div>
<?php endif; ?>

<!-- ============================================================
     ADMIN LAYOUT  (sidebar + main content)
     ========================================================= -->
<div class="admin-layout">

  <!-- Sidebar -->
  <aside class="admin-sidebar">
    <div style="padding:20px 20px 10px; border-bottom:1px solid rgba(255,255,255,.1); margin-bottom:10px;">
      <a href="<?= BASE_URL ?>/admin/index.php"
         style="display:flex; align-items:center; gap:10px; text-decoration:none;">
        <img src="<?= BASE_URL ?>/assets/img/logo.png" alt="payNex"
             style="width:32px; height:32px; border-radius:8px;">
        <span style="font-family:'Space Grotesk'; font-weight:700; font-size:17px; color:#fff;">
          pay<span style="color:var(--green);">Nex</span>
          <span style="font-size:11px; font-weight:400; color:rgba(255,255,255,.4); margin-left:4px;">admin</span>
        </span>
      </a>
    </div>

    <nav style="padding:8px 0;">
      <?php
      // Helper: render a sidebar link with active highlight
      function adminLink(string $href, string $icon, string $label): void {
          $current = str_replace('/admin/', '', $_SERVER['PHP_SELF'] ?? '');
          $isActive = (basename($href) === basename($current));
          $style = $isActive
              ? 'background:rgba(138,210,74,.15); color:var(--green); border-right:3px solid var(--green);'
              : '';
          echo "<a href=\"{$href}\" style=\"display:flex;align-items:center;gap:12px;padding:12px 20px;color:rgba(237,239,236,.75);font-size:14px;transition:color .15s;{$style}\">";
          echo "<i class=\"fa-solid fa-{$icon}\" style=\"width:18px;text-align:center;\"></i> {$label}";
          echo "</a>\n";
      }
      adminLink(BASE_URL.'/admin/index.php',         'gauge-high',      'Overview');
      adminLink(BASE_URL.'/admin/users.php',          'users',           'Users');
      adminLink(BASE_URL.'/admin/tasks.php',          'list-check',      'Tasks');
      adminLink(BASE_URL.'/admin/submissions.php',    'file-pen',        'Submissions');
      adminLink(BASE_URL.'/admin/deposits.php',       'money-bill',      'Deposits');
      adminLink(BASE_URL.'/admin/withdrawals.php',    'arrow-up-from-bracket', 'Withdrawals');
      adminLink(BASE_URL.'/admin/referrals.php',      'users-between-lines', 'Referrals');
      adminLink(BASE_URL.'/admin/settings.php',       'gear',            'Settings');
      adminLink(BASE_URL.'/admin/logs.php',           'scroll',          'Activity logs');
      adminLink(BASE_URL.'/admin/reported-issues.php', 'triangle-exclamation', 'Reported Issues');
      adminLink(BASE_URL.'/admin/leaderboard.php', 'trophy', 'Leaderboard');
      ?>
    </nav>

    <div style="position:absolute; bottom:0; left:0; right:0; padding:16px 20px; border-top:1px solid rgba(255,255,255,.08);">
      <div style="font-size:12px; color:rgba(255,255,255,.4); margin-bottom:8px;">
        Logged in as <strong style="color:rgba(255,255,255,.7);"><?= e($adminSession['name'] ?? 'Admin') ?></strong>
      </div>
      <a href="<?= BASE_URL ?>/admin/logout.php"
         style="display:flex; align-items:center; gap:8px; font-size:13px; color:rgba(237,239,236,.6);">
        <i class="fa-solid fa-right-from-bracket"></i> Log out
      </a>
    </div>
  </aside>

  <!-- Main content area -->
  <main class="admin-main">
