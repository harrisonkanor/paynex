<?php
require_once __DIR__ . '/config/config.php';

// ---- Live platform stats (replaces the old hardcoded demo numbers) ----
$statTasks = (int) $pdo->query(
    "SELECT COUNT(*) FROM task_submissions WHERE status = 'approved'"
)->fetchColumn();

$statPaid = (float) $pdo->query(
    "SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE status = 'paid'"
)->fetchColumn();

$statUsers = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE role = 'earner' AND status = 'active'"
)->fetchColumn();

$pageTitle = 'payNex — Turn Small Tasks Into Real Earnings';
require __DIR__ . '/includes/header.php';
?>

<section class="hero">
  <div class="hero-inner">
    <div>
      <span class="eyebrow">Task marketplace &amp; payouts</span>
      <h1>Turn small tasks<br>into <em>real earnings</em></h1>
      <p class="lead">payNex connects people who need work done with people ready to do it — surveys, data entry, referrals and custom tasks — then pays out straight to your wallet.</p>
      <div class="hero-ctas">
        <a href="<?= BASE_URL ?>/signup.php?role=earner" class="btn btn-primary">Start earning →</a>
      </div>
    </div>
    <div class="hero-visual">
      <canvas id="hero-canvas"></canvas>
      <div class="caption">task → review → wallet → payout</div>
    </div>
  </div>
  <div class="ledger">
    <div class="ledger-inner">
      <div class="ledger-item"><div class="num" id="stat-tasks">0</div><div class="lbl">Tasks completed</div></div>
      <div class="ledger-item"><div class="num" id="stat-paid">$0</div><div class="lbl">Paid out to earners</div></div>
      <div class="ledger-item"><div class="num" id="stat-users">0</div><div class="lbl">Active earners</div></div>
      <div class="ledger-item"><div class="num">~2h</div><div class="lbl">Avg. payout time</div></div>
    </div>
  </div>
</section>

<section class="how" id="how">
  <div class="wrap">
    <div class="section-head reveal">
      <span class="kicker">Process</span>
      <h2>Three steps between you and your next payout</h2>
      <p>Every task on payNex follows the same transparent lifecycle, so you always know exactly where your money stands.</p>
    </div>
    <div class="steps">
      <div class="step reveal">
        <div class="idx">01 / Browse</div>
        <h3>Find a task that fits</h3>
        <p>Filter by category, reward, deadline or difficulty — surveys, data entry, referrals or custom work from real task creators.</p>
        <div class="status-flow"><span class="pill available">Available</span></div>
      </div>
      <div class="step reveal">
        <div class="idx">02 / Submit</div>
        <h3>Do the work, submit proof</h3>
        <p>Accept the task, complete it, and upload your proof of completion. Track its status while the creator reviews it.</p>
        <div class="status-flow"><span class="pill progress">In progress</span><span class="pill review">Pending review</span></div>
      </div>
      <div class="step reveal">
        <div class="idx">03 / Get paid</div>
        <h3>Approved, paid, done</h3>
        <p>Your reward is credited to your wallet balance instantly. Withdraw whenever you're ready, in the method you prefer.</p>
        <div class="status-flow"><span class="pill approved">Approved</span><span class="pill paid">Paid</span></div>
      </div>
    </div>
  </div>
</section>

<section class="audience" id="features">
  <div class="wrap">
    <div class="section-head reveal">
      <span class="kicker">Built for both sides</span>
      <h2>One platform, two experiences</h2>
      <p>Whether you're here to earn or here to get work done, payNex gives you the tools built specifically for that role.</p>
    </div>
    <div class="toggle-row reveal">
      <div class="toggle" id="audience-toggle">
        <button class="active" data-target="earner">For earners</button>
        <button data-target="creator">For task creators</button>
      </div>
    </div>
    <div class="feature-grid" id="feature-grid"></div>
  </div>
</section>

<section class="dash" id="dashboard">
  <div class="wrap">
    <div class="section-head reveal">
      <span class="kicker">Your dashboard</span>
      <h2>Every balance, every task, in one view</h2>
      <p>A personalized dashboard shows exactly where your money and your work stand — no digging required.</p>
    </div>
    <div class="dash-mock reveal">
      <div class="dash-cards">
        <div class="stat-card"><div class="lbl">Available balance</div><div class="val">$284.50</div></div>
        <div class="stat-card blue"><div class="lbl">Pending earnings</div><div class="val">$46.00</div></div>
        <div class="stat-card"><div class="lbl">Lifetime earned</div><div class="val">$1,920.75</div></div>
      </div>
      <div class="task-table">
        <div class="task-row">
          <div><span class="t-name">Product survey — 12 questions</span><span class="t-cat">Surveys</span></div>
          <span class="pill review">Pending review</span>
          <span class="t-reward">+$3.20</span>
        </div>
        <div class="task-row">
          <div><span class="t-name">Clean up spreadsheet of leads</span><span class="t-cat">Data entry</span></div>
          <span class="pill progress">In progress</span>
          <span class="t-reward">+$9.00</span>
        </div>
        <div class="task-row">
          <div><span class="t-name">Refer a friend to payNex</span><span class="t-cat">Referral</span></div>
          <span class="pill paid">Paid</span>
          <span class="t-reward">+$5.00</span>
        </div>
        <div class="task-row">
          <div><span class="t-name">Tag 200 product images</span><span class="t-cat">Custom task</span></div>
          <span class="pill approved">Approved</span>
          <span class="t-reward">+$14.00</span>
        </div>
      </div>
    </div>
    <?php if (is_logged_in()): ?>
      <p style="margin-top:20px;"><a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-dark">Go to your real dashboard →</a></p>
    <?php endif; ?>
  </div>
</section>

<section class="security" id="security">
  <div class="wrap">
    <div class="section-head reveal">
      <span class="kicker">Trust &amp; safety</span>
      <h2>Built to protect every balance</h2>
      <p>Security isn't a feature you notice — it's the reason you don't have to think about it.</p>
    </div>
    <div class="sec-grid">
      <div class="sec-item reveal"><h4>Hardened auth</h4><p>Secure password hashing, email verification and role-based access for users, creators and admins.</p></div>
      <div class="sec-item reveal"><h4>Injection-proof</h4><p>Prepared statements and validated inputs guard against SQL injection and malformed submissions.</p></div>
      <div class="sec-item reveal"><h4>XSS &amp; CSRF protection</h4><p>Every form and session is protected against cross-site scripting and request forgery.</p></div>
      <div class="sec-item reveal"><h4>Activity logging</h4><p>Rate limiting and admin activity logs keep a clear, auditable trail across the platform.</p></div>
    </div>
  </div>
</section>

<section class="withdraw" id="for-creators">
  <div class="wrap">
    <div class="section-head reveal" style="margin-bottom:10px;">
      <span class="kicker">Withdrawals</span>
      <h2>Your earnings, your way out</h2>
      <p>Request a withdrawal, and track it from request to payout — no guessing where your money is.</p>
    </div>
    <div class="wd-flow reveal">
      <div class="wd-node"><div class="dot mono">1</div><h4>Request</h4><p>Enter an amount and choose your payout method.</p></div>
      <div class="wd-arrow">→</div>
      <div class="wd-node"><div class="dot mono">2</div><h4>Review</h4><p>An admin verifies the request before it's approved.</p></div>
      <div class="wd-arrow">→</div>
      <div class="wd-node"><div class="dot mono">3</div><h4>Payout</h4><p>Funds are sent to your crypto wallet, marked Paid.</p></div>
    </div>
  </div>
</section>

<!-- ============================================================
     PREVIOUS WEEK WINNERS
     ========================================================= -->
<?php
$prevWinners = [];
try {
    $prevCycle = $pdo->query(
        "SELECT * FROM leaderboard_cycles WHERE status = 'completed' ORDER BY week_end DESC LIMIT 1"
    )->fetch();
    if ($prevCycle) {
        $wStmt = $pdo->prepare(
            "SELECT lp.rank_position, lp.referral_count, lp.prize_amount,
                    u.name, u.profile_photo
             FROM leaderboard_payouts lp
             JOIN users u ON u.id = lp.user_id
             WHERE lp.cycle_id = :cid
             ORDER BY lp.rank_position ASC
             LIMIT 5"
        );
        $wStmt->execute([':cid' => $prevCycle['id']]);
        $prevWinners = $wStmt->fetchAll();
    }
} catch (Exception $e) { /* silently skip */ }
?>
<?php if ($prevWinners): ?>
<section class="prev-winners-section" id="previous-winners">
  <div class="wrap">
    <div class="section-head reveal">
      <span class="kicker">Last week's champions</span>
      <h2>Top earners from the previous round</h2>
      <p>Congratulations to our previous week's top performers!</p>
    </div>
    <div class="prev-winners-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;">
      <?php foreach ($prevWinners as $pw):
        $medal = '';
        if ((int)$pw['rank_position'] === 1) $medal = '🥇 ';
        elseif ((int)$pw['rank_position'] === 2) $medal = '🥈 ';
        elseif ((int)$pw['rank_position'] === 3) $medal = '🥉 ';
      ?>
        <div class="prev-winner-card" style="background:var(--paper);border-radius:12px;padding:16px;text-align:center;transition:transform .2s,box-shadow .2s;">
          <div style="font-size:22px;margin-bottom:6px;"><?= $medal ?: '#' . (int)$pw['rank_position'] ?></div>
          <?php if ($pw['profile_photo']): ?>
            <img src="<?= BASE_URL ?>/uploads/<?= e($pw['profile_photo']) ?>" style="width:44px;height:44px;border-radius:50%;object-fit:cover;margin:0 auto 8px;display:block;" alt="">
          <?php else: ?>
            <span style="display:inline-flex;width:44px;height:44px;border-radius:50%;background:var(--navy-deep);color:#fff;align-items:center;justify-content:center;font-size:18px;margin-bottom:8px;"><i class="fa-solid fa-user"></i></span>
          <?php endif; ?>
          <div style="font-size:14px;font-weight:600;"><?= e($pw['name']) ?></div>
          <div style="font-size:12px;color:var(--ink-soft);font-family:monospace;margin-top:4px;">
            <?= (int)$pw['referral_count'] ?> ref &middot; $<?= number_format((float)$pw['prize_amount'], 2) ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="cta-band" id="get-started">
  <div class="wrap">
    <h2>Your next task is already waiting.</h2>
    <p>Join as an earner to start browsing tasks today, or post your first task and get real work done.</p>
    <div class="cta-btns">
      <a href="<?= BASE_URL ?>/signup.php?role=earner" class="btn btn-primary">Create free account</a>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
