<?php
/**
 * Shared page footer — closes the HTML, loads JS, and injects
 * the Chatwoot live-support widget if a token is configured.
 *
 * Variables optionally set by the calling page:
 *   $statTasks — int  (live tasks completed count for landing page)
 *   $statPaid  — float (total paid out)
 *   $statUsers — int  (active earner count)
 */
?>
<footer>
  <div class="wrap">
    <div class="foot-top">
      <div class="foot-brand">
        <img src="<?= BASE_URL ?>/assets/img/logo.png" alt="payNex logo">
        <span>payNex</span>
      </div>
      <div class="foot-cols">
        <div class="foot-col">
          <h5><i class="fa-solid fa-layer-group"></i> Platform</h5>
          <a href="<?= BASE_URL ?>/index.php#how"><i class="fa-solid fa-route"></i> How it works</a>
          <a href="<?= BASE_URL ?>/index.php#features"><i class="fa-solid fa-star"></i> Features</a>
          <a href="<?= BASE_URL ?>/plans.php"><i class="fa-solid fa-crown"></i> VIP Plans</a>
          <a href="<?= BASE_URL ?>/dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        </div>
        <div class="foot-col">
          <h5><i class="fa-solid fa-building"></i> Company</h5>
          <a href="<?= BASE_URL ?>/index.php#security"><i class="fa-solid fa-shield-halved"></i> Security</a>
          <a href="#"><i class="fa-solid fa-circle-info"></i> About</a>
          <a href="#"><i class="fa-solid fa-envelope"></i> Contact</a>
        </div>
        <div class="foot-col">
          <h5><i class="fa-solid fa-scale-balanced"></i> Legal</h5>
          <a href="#"><i class="fa-solid fa-file-contract"></i> Terms</a>
          <a href="#"><i class="fa-solid fa-lock"></i> Privacy</a>
        </div>
      </div>
    </div>
    <div class="foot-bottom">
      <span>© <?= date('Y') ?> payNex. All rights reserved.</span>
      <span class="mono" style="font-size:11px;">payNex platform v3</span>
    </div>
  </div>
</footer>

<!-- Three.js (required for hero animation on landing page only) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>

<!-- Pass live stat counters to JS (values are 0 unless set by index.php) -->
<script>
  window.PAYNEX_STATS = {
    tasks : <?= (int)($statTasks  ?? 0) ?>,
    paid  : <?= (float)($statPaid ?? 0) ?>,
    users : <?= (int)($statUsers  ?? 0) ?>
  };
</script>

<!-- Main site JS (reveal animations, hamburger, counters, etc.) -->
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>

<?php
/*
 * ================================================================
 * CHATWOOT LIVE SUPPORT WIDGET
 * ================================================================
 * To enable Chatwoot:
 *   1. Sign up at https://www.chatwoot.com (free self-hosted or cloud).
 *   2. Create a "Website" inbox in Chatwoot → Settings → Inboxes.
 *   3. Copy the "Website Token" from the inbox settings.
 *   4. In the payNex admin panel go to Settings → paste the token
 *      into the "Chatwoot website token" field and save.
 *
 * The widget will then appear automatically on every page.
 * To connect it to a Telegram agent, connect your Telegram bot
 * to the same Chatwoot inbox via Settings → Integrations → Telegram.
 *
 * API used: https://www.chatwoot.com/docs/product/channels/live-chat/sdk/setup/
 */
$chatwootToken = get_setting($pdo, 'chatwoot_website_token');
if ($chatwootToken):
?>
<script>
  /* Chatwoot live-support widget — injected only when token is set */
  (function(d,t) {
    var BASE_URL="https://app.chatwoot.com"; /* Change to your self-hosted URL if applicable */
    var g=d.createElement(t),s=d.getElementsByTagName(t)[0];
    g.src=BASE_URL+"/packs/js/sdk.js";
    g.defer=true;
    g.async=true;
    s.parentNode.insertBefore(g,s);
    g.onload=function(){
      window.chatwootSDK.run({
        websiteToken: <?= json_encode($chatwootToken) ?>,
        baseUrl     : BASE_URL
      });
    };
  })(document,"script");
</script>
<?php endif; ?>

<script>
function togglePass(inputId, btn) {
  var input = document.getElementById(inputId);
  if (input.type === 'password') {
    input.type = 'text';
    btn.innerHTML = '<i class="fa-solid fa-eye-slash"></i>';
  } else {
    input.type = 'password';
    btn.innerHTML = '<i class="fa-solid fa-eye"></i>';
  }
}
</script>
</body>
</html>
