<?php
/**
 * Shared page header — HTML <head>, Flash messages, responsive nav with
 * hamburger menu, and Font Awesome icons.
 *
 * Variables used (set in the calling page before include):
 *   $pageTitle — string, optional
 */
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/config.php ';
require_once __DIR__ . '/security_headers.php ';
}
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle ?? 'PayNex — Turn Small Tasks Into Real Earnings') ?></title>
<link rel="icon" href="<?= BASE_URL ?>/assets/img/logo.png">

<!-- Google Fonts (same families as original design) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

<!-- Font Awesome 6 Free (icons used throughout the app) -->
<!-- Source: https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<!-- Original design system -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<!-- App-specific supplementary styles (forms, dashboard, badges, responsive) -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
</head>
<body>

<!-- ============================================================
     FLASH MESSAGES — shown once, then cleared
     =========================================================== -->
<?php if ($flashError = flash('error')): ?>
  <div class="flash-wrap">
    <div class="alert alert-error">
      <i class="fa-solid fa-circle-exclamation"></i> <?= e($flashError) ?>
      <button class="alert-close" onclick="this.parentElement.parentElement.remove()">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
  </div>
<?php endif; ?>
<?php if ($flashSuccess = flash('success')): ?>
  <div class="flash-wrap">
    <div class="alert alert-success">
      <i class="fa-solid fa-circle-check"></i> <?= e($flashSuccess) ?>
      <button class="alert-close" onclick="this.parentElement.parentElement.remove()">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
  </div>
<?php endif; ?>

<!-- ============================================================
     MAIN NAV (sticky, dark, responsive with hamburger)
     =========================================================== -->
<header class="nav" id="main-nav">
  <div class="nav-inner">

    <!-- Logo / brand -->
    <a href="<?= BASE_URL ?>/index.php" class="brand">
      <img src="<?= BASE_URL ?>/assets/img/logo.png" alt="payNex logo">
      <span>PayNex</span>
    </a>

    <!-- Desktop nav links -->
    <nav class="links" id="nav-links">
      <?php if ($user): ?>
        <a href="<?= BASE_URL ?>/dashboard.php">
          <i class="fa-solid fa-gauge-high"></i> Dashboard
        </a>
        <?php if ($user['role'] === 'earner'): ?>
          <a href="<?= BASE_URL ?>/tasks.php">
            <i class="fa-solid fa-list-check"></i> Tasks
          </a>
          <a href="<?= BASE_URL ?>/referrals.php">
            <i class="fa-solid fa-users"></i> Referrals
          </a>
          <a href="<?= BASE_URL ?>/leaderboard.php">
            <i class="fa-solid fa-trophy" style="color:var(--amber);"></i> Leaderboard
          </a>
          <a href="<?= BASE_URL ?>/withdraw.php">
            <i class="fa-solid fa-wallet"></i> Withdraw
          </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/index.php#how">
          <i class="fa-solid fa-route"></i> How it works
        </a>
        <a href="<?= BASE_URL ?>/index.php#features">
          <i class="fa-solid fa-star"></i> Features
        </a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/index.php#how">
          <i class="fa-solid fa-route"></i> How it works
        </a>
        <a href="<?= BASE_URL ?>/index.php#features">
          <i class="fa-solid fa-star"></i> Features
        </a>
        <a href="<?= BASE_URL ?>/index.php#security">
          <i class="fa-solid fa-shield-halved"></i> Security
        </a>
      <?php endif; ?>
    </nav>

    <!-- Right side: user info or login/signup buttons -->
    <div class="nav-right">
      <?php if ($user): ?>
        <!-- Logged-in user menu -->
        <div class="nav-user-menu" tabindex="0" role="button" aria-haspopup="true" aria-expanded="false">
          <?php if ($user['profile_photo'] ?? false): ?>
            <img class="nav-avatar" src="<?= BASE_URL ?>/uploads/<?= e($user['profile_photo']) ?>" alt="">
          <?php else: ?>
            <span class="nav-avatar-placeholder">
              <i class="fa-solid fa-user"></i>
            </span>
          <?php endif; ?>
          <span class="nav-hello"><?= e(explode(' ', $user['name'])[0]) ?></span>
          <div class="nav-dropdown">
            <a href="<?= BASE_URL ?>/profile.php">
              <i class="fa-solid fa-pen-to-square"></i> Edit profile
            </a>
            <a href="<?= BASE_URL ?>/dashboard.php">
              <i class="fa-solid fa-gauge-high"></i> Dashboard
            </a>
            <?php if ($user['role'] === 'earner'): ?>
            <a href="<?= BASE_URL ?>/leaderboard.php">
              <i class="fa-solid fa-trophy"></i> Leaderboard
            </a>
            <?php endif; ?>
            <div class="nav-drop-divider"></div>
            <a href="<?= BASE_URL ?>/logout.php" class="nav-drop-danger">
              <i class="fa-solid fa-right-from-bracket"></i> Log out
            </a>
          </div>
        </div>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/login.php" class="nav-cta nav-cta-ghost">Log in</a>
        <a href="<?= BASE_URL ?>/signup.php" class="nav-cta">Get started</a>
      <?php endif; ?>

      <!-- Hamburger button (mobile only) -->
      <button class="menu-toggle" id="menu-toggle" aria-label="Toggle navigation"
              aria-expanded="false" aria-controls="mobile-nav">
        <span class="hamburger">
          <span></span><span></span><span></span>
        </span>
      </button>
    </div>
  </div>

  <!-- Mobile drawer (hidden by default, toggled by hamburger) -->
  <div class="mobile-nav" id="mobile-nav" aria-hidden="true">
    <nav class="mobile-links">
      <?php if ($user): ?>
        <a href="<?= BASE_URL ?>/dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <?php if ($user['role'] === 'earner'): ?>
          <a href="<?= BASE_URL ?>/tasks.php"><i class="fa-solid fa-list-check"></i> Tasks</a>
          <a href="<?= BASE_URL ?>/referrals.php"><i class="fa-solid fa-users"></i> Referrals</a>
          <a href="<?= BASE_URL ?>/leaderboard.php"><i class="fa-solid fa-trophy"></i> Leaderboard</a>
          <a href="<?= BASE_URL ?>/withdraw.php"><i class="fa-solid fa-wallet"></i> Withdraw</a>
          <a href="<?= BASE_URL ?>/profile.php"><i class="fa-solid fa-pen-to-square"></i> Edit profile</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/index.php#how"><i class="fa-solid fa-route"></i> How it works</a>
        <a href="<?= BASE_URL ?>/index.php#features"><i class="fa-solid fa-star"></i> Features</a>
        <div class="mobile-divider"></div>
        <a href="<?= BASE_URL ?>/logout.php" style="color:var(--red);">
          <i class="fa-solid fa-right-from-bracket"></i> Log out
        </a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/index.php#how"><i class="fa-solid fa-route"></i> How it works</a>
        <a href="<?= BASE_URL ?>/index.php#features"><i class="fa-solid fa-star"></i> Features</a>
        <a href="<?= BASE_URL ?>/index.php#security"><i class="fa-solid fa-shield-halved"></i> Security</a>
        <div class="mobile-divider"></div>
        <a href="<?= BASE_URL ?>/login.php"><i class="fa-solid fa-right-to-bracket"></i> Log in</a>
        <a href="<?= BASE_URL ?>/signup.php"><i class="fa-solid fa-user-plus"></i> Get started</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
