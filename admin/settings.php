<?php
/**
 * admin/settings.php — Platform-wide settings.
 *
 * Settings managed here (stored in site_settings table):
 *   deposit_wallet_usdt      — USDT TRC-20 address shown to users
 *   chatwoot_website_token   — Chatwoot widget token (injected in footer)
 *   referral_commission_pct  — % of referred user's task earnings credited to referrer
 *
 * All keys use set_setting() / get_setting() from functions.php,
 * which use ON DUPLICATE KEY UPDATE (safe prepared statements).
 *
 * === HOW TO CONNECT CHATWOOT TO TELEGRAM ===
 * 1. Sign up at https://www.chatwoot.com (cloud or self-hosted).
 * 2. Create a "Website" inbox → Settings → Inboxes → New Inbox → Website.
 * 3. Copy the "Website Token" and paste it below.
 * 4. To receive messages on Telegram:
 *    Settings → Integrations → Telegram → connect your Telegram bot.
 *    (Create a bot at https://t.me/BotFather first.)
 * 5. Agents answer in the Chatwoot dashboard; users chat via the widget.
 *
 * === EXTERNAL APIS USED IN THIS PROJECT ===
 * 1. SMTP email     — Mailgun / SendGrid / SMTP2GO / Brevo
 *    Where to add  : config/config.php SMTP_* constants
 *    SDK           : composer require phpmailer/phpmailer
 *    Docs          : https://github.com/PHPMailer/PHPMailer
 *
 * 2. Chatwoot live chat / Telegram customer support widget
 *    Where to add  : paste website token in this settings page
 *    Docs          : https://www.chatwoot.com/docs/product/channels/live-chat/sdk/setup/
 *
 * 3. Blockchain TX verification (USDT)
 *    USDT : https://tronscan.org  (no API key needed for public links)
 *    Optionally integrate Tron Grid (https://developers.tron.network/) for
 *    automated TX verification.
 *    Where to add API key : config/config.php (add TRONGRID_KEY constant)
 *
 * 4. reCAPTCHA v3 (brute-force protection on login/signup)
 *    Obtain keys : https://www.google.com/recaptcha/admin
 *    Where to add: RECAPTCHA_SITE_KEY / RECAPTCHA_SECRET_KEY in config/config.php
 *    SDK          : no composer package needed — plain HTTP calls to
 *                   https://www.google.com/recaptcha/api/siteverify
 */
require_once __DIR__ . '/../config/config.php';
require_admin();

$ok     = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Read inputs
    $usdtAddr    = trim($_POST['deposit_wallet_usdt']     ?? '');
    $chatwoot    = trim($_POST['chatwoot_website_token']  ?? '');
    $refPct      = trim($_POST['referral_commission_pct'] ?? '5');

    // Validate referral commission
    if (!is_numeric($refPct) || (float)$refPct < 0 || (float)$refPct > 100) {
        $errors[] = 'Referral commission must be a number between 0 and 100.';
    }

    if (!$errors) {
        // Save each setting (ON DUPLICATE KEY UPDATE — safe prepared statements)
        set_setting($pdo, 'deposit_wallet_usdt',     $usdtAddr);
        set_setting($pdo, 'chatwoot_website_token',  $chatwoot);
        set_setting($pdo, 'referral_commission_pct', $refPct);

        log_activity($pdo, $_SESSION['admin']['id'], 'admin_settings_updated');
        $ok[] = 'Settings saved.';
    }
}

// Load current values
$usdtAddr = get_setting($pdo, 'deposit_wallet_usdt');
$chatwoot = get_setting($pdo, 'chatwoot_website_token');
$refPct   = get_setting($pdo, 'referral_commission_pct', '5');

$pageTitle = 'Settings — payNex admin';
require __DIR__ . '/includes/admin_header.php';
?>

<div class="page-head">
  <h1><i class="fa-solid fa-gear"></i> Platform settings</h1>
  <p>Configure deposit addresses, chat support, and referral commissions.</p>
</div>

<?php if ($ok): ?>
  <div class="alert alert-success" style="margin-bottom:20px;">
    <i class="fa-solid fa-circle-check"></i>
    <div><?php foreach ($ok as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
  </div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="alert alert-error" style="margin-bottom:20px;">
    <i class="fa-solid fa-circle-exclamation"></i>
    <div><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
  </div>
<?php endif; ?>

<form method="post" action="<?= BASE_URL ?>/admin/settings.php" novalidate>
  <?= csrf_field() ?>

  <!-- ============================================================
       DEPOSIT WALLET ADDRESSES
       These are shown to users on the dashboard and plans page
       so they know where to send their deposit.
       ========================================================= -->
  <div class="card" style="max-width:700px; margin-bottom:20px;">
    <h2><i class="fa-solid fa-wallet"></i> Deposit wallet addresses</h2>
    <p class="text-muted" style="font-size:14px; margin-bottom:16px;">
      Users will see this address when they want to deposit USDT
      to activate a VIP plan. Only change it when you rotate wallets.
    </p>

    <div class="field">
      <label>
        <i class="fa-solid fa-circle-dollar-to-slot" style="color:#26a17b;"></i>
        USDT TRC-20 deposit address
      </label>
      <input type="text" name="deposit_wallet_usdt"
             value="<?= e($usdtAddr) ?>"
             placeholder="Your platform USDT TRC-20 deposit address"
             maxlength="120">
      <div class="input-hint">Tron network only (TRC-20). Do not use ERC-20 address here.</div>
    </div>
  </div>

  <!-- ============================================================
       REFERRAL COMMISSION
       ========================================================= -->
  <div class="card" style="max-width:700px; margin-bottom:20px;">
    <h2><i class="fa-solid fa-percent"></i> Referral commission</h2>
    <p class="text-muted" style="font-size:14px; margin-bottom:16px;">
      In addition to the flat referral bonus, you can set a percentage
      of every task reward earned by a referred user that is also
      credited to the referrer's wallet.
    </p>
    <div class="field" style="max-width:200px;">
      <label>Commission rate (%)</label>
      <input type="number" name="referral_commission_pct"
             value="<?= e($refPct) ?>"
             min="0" max="100" step="0.1">
      <div class="input-hint">Set to 0 to disable commission.</div>
    </div>
  </div>

  <!-- ============================================================
       CHATWOOT LIVE SUPPORT
       ========================================================= -->
  <div class="card" style="max-width:700px; margin-bottom:20px;">
    <h2><i class="fa-solid fa-comments"></i> Live support (Chatwoot + Telegram)</h2>

    <div class="alert alert-info" style="margin-bottom:16px; font-size:13.5px;">
      <i class="fa-solid fa-circle-info"></i>
      <div>
        <strong>How to set up:</strong>
        <ol style="margin:8px 0 0 16px; padding:0; line-height:1.9;">
          <li>Sign up at <a href="https://www.chatwoot.com" target="_blank">chatwoot.com</a>
              (free cloud or self-hosted).</li>
          <li>Go to <strong>Settings → Inboxes → New Inbox → Website</strong>.</li>
          <li>Copy the <strong>Website Token</strong> and paste it below.</li>
          <li>To receive user messages on <strong>Telegram</strong>:
              go to <strong>Settings → Integrations → Telegram</strong>
              and connect your Telegram bot
              (create one at <a href="https://t.me/BotFather" target="_blank">@BotFather</a>).
          </li>
        </ol>
        Once the token is saved, the widget appears automatically on every page.
      </div>
    </div>

    <div class="field">
      <label><i class="fa-solid fa-key"></i> Chatwoot website token</label>
      <input type="text" name="chatwoot_website_token"
             value="<?= e($chatwoot) ?>"
             placeholder="e.g. aBcDeFgHiJkLmN..."
             maxlength="120">
      <div class="input-hint">Leave blank to disable the live chat widget.</div>
    </div>
  </div>

  <!-- ============================================================
       EXTERNAL API NOTES
       ========================================================= -->
  <div class="card" style="max-width:700px; margin-bottom:20px;">
    <h2><i class="fa-solid fa-plug"></i> External API integrations</h2>
    <p style="font-size:13.5px; color:var(--ink-soft); line-height:1.8;">
      The following APIs are used or recommended for this platform.
      Add your API keys to <code>config/config.php</code> as instructed.
    </p>
    <table class="data-table" style="margin-top:12px;">
      <thead>
        <tr><th>Service</th><th>Purpose</th><th>Where to get API key</th></tr>
      </thead>
      <tbody>
        <tr>
          <td><strong>Mailgun / SendGrid / Brevo</strong></td>
          <td>Transactional email (signup, login alert, withdrawal emails)</td>
          <td>
            <a href="https://app.mailgun.com" target="_blank">mailgun.com</a> /
            <a href="https://app.sendgrid.com" target="_blank">sendgrid.com</a> /
            <a href="https://app.brevo.com" target="_blank">brevo.com</a>
            — add to <code>SMTP_*</code> constants in <code>config/config.php</code>
          </td>
        </tr>
        <tr>
          <td><strong>Chatwoot</strong></td>
          <td>Live customer support widget (links to Telegram agent)</td>
          <td>
            <a href="https://www.chatwoot.com" target="_blank">chatwoot.com</a>
            — paste website token in settings above
          </td>
        </tr>
        <tr>
          <td><strong>Tron Grid</strong></td>
          <td>Auto-verify USDT TRC-20 transactions (optional)</td>
          <td>
            <a href="https://developers.tron.network/" target="_blank">developers.tron.network</a>
            — add as <code>TRONGRID_KEY</code> in <code>config/config.php</code>
          </td>
        </tr>
        <tr>
          <td><strong>Google reCAPTCHA v3</strong></td>
          <td>Protect login &amp; signup from bots</td>
          <td>
            <a href="https://www.google.com/recaptcha/admin" target="_blank">google.com/recaptcha</a>
            — add as <code>RECAPTCHA_SITE_KEY</code> / <code>RECAPTCHA_SECRET_KEY</code>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">
      <i class="fa-solid fa-floppy-disk"></i> Save all settings
    </button>
  </div>
</form>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
