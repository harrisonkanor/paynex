<?php
/**
 * signup_success.php — Congratulations page after successful registration.
 */
require_once __DIR__ . '/config/config.php';

$user = current_user();
if (!$user) redirect('/signup.php');

$pageTitle = 'Welcome to payNex!';
require __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap" style="max-width:500px;text-align:center;">
  <div style="margin-bottom:24px;">
    <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,var(--green),#4CAF50);display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;animation:pulse 2s infinite;">
      <i class="fa-solid fa-check" style="font-size:36px;color:#fff;"></i>
    </div>
  </div>
  
  <h1 style="font-size:28px;font-weight:700;margin-bottom:12px;">
    🎉 Congratulations!
  </h1>
  
  <p style="font-size:18px;color:var(--ink-soft);margin-bottom:8px;">
    Your account has been created successfully!
  </p>
  
  <p style="font-size:16px;color:var(--ink-soft);margin-bottom:24px;">
    Welcome to <strong>payNex</strong>, <?= e(explode(' ', $user['name'])[0]) ?>!
  </p>
  
  <div style="background:var(--paper);border-radius:12px;padding:20px;margin-bottom:24px;">
    <p style="font-size:14px;color:var(--ink-soft);margin-bottom:12px;">
      <i class="fa-solid fa-circle-info" style="color:var(--blue);"></i>
      Here's what you can do next:
    </p>
    <div style="text-align:left;">
      <p style="font-size:14px;margin-bottom:8px;">
        <i class="fa-solid fa-crown" style="color:var(--amber);width:20px;"></i>
        Activate a VIP plan to unlock tasks
      </p>
      <p style="font-size:14px;margin-bottom:8px;">
        <i class="fa-solid fa-list-check" style="color:var(--green);width:20px;"></i>
        Complete tasks and earn rewards
      </p>
      <p style="font-size:14px;margin-bottom:8px;">
        <i class="fa-solid fa-share-nodes" style="color:var(--blue);width:20px;"></i>
        Refer friends and earn bonuses
      </p>
      <p style="font-size:14px;">
        <i class="fa-solid fa-trophy" style="color:var(--amber);width:20px;"></i>
        Climb the leaderboard for weekly prizes
      </p>
    </div>
  </div>
  
  <!-- Logout first, then go to login page -->
  <a href="<?= BASE_URL ?>/logout.php" class="btn btn-primary" style="width:100%;padding:14px;font-size:16px;">
    <i class="fa-solid fa-right-to-bracket"></i> Log in to your account
  </a>
  
  <p class="form-note" style="margin-top:20px;">
    You'll be redirected to the login page
  </p>
</div>

<style>
@keyframes pulse {
  0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(138, 210, 74, 0.4); }
  70% { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(138, 210, 74, 0); }
  100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(138, 210, 74, 0); }
}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
