<?php
/**
 * leaderboard.php — Enhanced referral leaderboard with weekly prize pool.
 *
 * Features:
 *   - 3D podium section for top 3 with entrance animations
 *   - Live auto-refresh via AJAX polling every 30s
 *   - Animated countdown timer and week progress bar
 *   - Previous cycle winners history
 */
require_once __DIR__ . '/config/config.php';
require_login();
require_email_verified($pdo);

$user = current_user();
if ($user['role'] === 'admin') redirect('/admin/index.php');

$cycle = $pdo->query("SELECT * FROM leaderboard_cycles WHERE status = 'active' LIMIT 1")->fetch();

if (!$cycle) {
    $pdo->exec(
        "INSERT INTO leaderboard_cycles (week_start, week_end, status)
         VALUES (
           DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY),
           DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 6 DAY),
           'active'
         )"
    );
    $cycle = $pdo->query("SELECT * FROM leaderboard_cycles WHERE status = 'active' LIMIT 1")->fetch();
}

$weekStart = $cycle['week_start'];
$weekEnd   = $cycle['week_end'];
$prizePool = (float) $cycle['total_prize_pool'];
$nextReset = strtotime($weekEnd) + 86400;
$secondsUntilReset = $nextReset - time();

// Tiered prize distribution
$prize1st = 150.00;
$prize2nd = 100.00;
$prize3rd = 75.00;
$remainingPool = $prizePool - $prize1st - $prize2nd - $prize3rd;
$prize4to20 = $remainingPool / 17;

// Fetch top 20
$stmt = $pdo->prepare(
    "SELECT u.id, u.name, u.profile_photo,
            COUNT(r.id) AS referral_count,
            COALESCE(SUM(r.bonus_amount), 0) AS bonus_earned
     FROM users u
     JOIN referrals r ON r.referrer_id = u.id AND r.bonus_paid = 1
     WHERE u.role = 'earner'
       AND r.created_at >= :ws
       AND r.created_at < DATE_ADD(:we, INTERVAL 1 DAY)
     GROUP BY u.id
     ORDER BY bonus_earned DESC, referral_count DESC
     LIMIT 20"
);
$stmt->execute([':ws' => $weekStart . ' 00:00:00', ':we' => $weekEnd . ' 00:00:00']);
$leaderboard = $stmt->fetchAll();

// Current user rank
$currentUserRank = null;
$allRanked = $pdo->prepare(
    "SELECT u.id, COUNT(r.id) AS cnt, COALESCE(SUM(r.bonus_amount), 0) AS bonus
     FROM users u
     JOIN referrals r ON r.referrer_id = u.id AND r.bonus_paid = 1
     WHERE u.role = 'earner'
       AND r.created_at >= :ws
       AND r.created_at < DATE_ADD(:we, INTERVAL 1 DAY)
     GROUP BY u.id
     ORDER BY bonus DESC, cnt DESC"
);
$allRanked->execute([':ws' => $weekStart . ' 00:00:00', ':we' => $weekEnd . ' 00:00:00']);
$allRows = $allRanked->fetchAll();
foreach ($allRows as $i => $row) {
    if ((int)$row['id'] === (int)$user['id']) {
        $currentUserRank = $i + 1;
        break;
    }
}

// Previous cycle winners
$prevCycle = $pdo->query(
    "SELECT * FROM leaderboard_cycles WHERE status = 'completed' ORDER BY week_end DESC LIMIT 1"
)->fetch();

$prevWinners = [];
if ($prevCycle) {
    $wStmt = $pdo->prepare(
        "SELECT lp.rank_position, lp.referral_count, lp.prize_amount,
                u.name, u.profile_photo
         FROM leaderboard_payouts lp
         JOIN users u ON u.id = lp.user_id
         WHERE lp.cycle_id = :cid
         ORDER BY lp.rank_position ASC
         LIMIT 20"
    );
    $wStmt->execute([':cid' => $prevCycle['id']]);
    $prevWinners = $wStmt->fetchAll();
}

// Top 3 for podium

// Top 3 for podium
$top3 = array_slice($leaderboard, 0, 3);
$top3Data = [
    1 => $top3[0] ?? null,
    2 => $top3[1] ?? null,
    3 => $top3[2] ?? null,
];

/* ---------------------------------------------------------------
 * JSON RESPONSE MODE — for live auto-refresh
 * ------------------------------------------------------------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    $json = [
        'seconds_until_reset' => $secondsUntilReset,
        'leaderboard' => []
    ];
    foreach ($leaderboard as $i => $entry) {
        $json['leaderboard'][] = [
            'rank' => $i + 1,
            'id' => (int)$entry['id'],
            'name' => $entry['name'],
            'profile_photo' => $entry['profile_photo'],
            'referral_count' => (int)$entry['referral_count'],
            'bonus_earned' => (float)$entry['bonus_earned'],
        ];
    }
    echo json_encode($json);
    exit;
}

$pageTitle = 'Leaderboard — payNex';
require __DIR__ . '/includes/header.php';
?>

<div class="page-wrap" style="max-width:860px;">
  <div class="page-head" style="text-align:center;">
    <h1 style="display:flex;align-items:center;justify-content:center;gap:12px;">
      <i class="fa-solid fa-trophy" style="color:var(--amber);filter:drop-shadow(0 2px 8px rgba(232,181,74,.3));"></i>
      Referral Leaderboard
      <span class="live-badge"><span class="live-dot"></span> Live</span>
    </h1>
    <p style="max-width:500px;margin:8px auto 0;">
      <strong>$<?= number_format($prizePool) ?></strong> in weekly prizes — Top 3 get <strong>$<?= number_format($prize1st) ?></strong>, <strong>$<?= number_format($prize2nd) ?></strong>, <strong>$<?= number_format($prize3rd) ?></strong>!
    </p>
  </div>

  <!-- PRIZE POOL CARD WITH COUNTDOWN -->
  <div class="prize-card">
    <div class="prize-card-glow"></div>
    <div style="position:relative;z-index:1;">
      <div class="prize-row">
        <div class="prize-item">
          <i class="fa-solid fa-gift prize-icon"></i>
          <div class="prize-amount">$<?= number_format($prizePool) ?></div>
          <div class="prize-label">Prize Pool</div>
        </div>
        <div class="prize-divider"></div>
        <div class="prize-item">
          <div class="prize-week">
            <i class="fa-regular fa-calendar"></i> Week of <?= e(date('M j', strtotime($weekStart))) ?> — <?= e(date('M j, Y', strtotime($weekEnd))) ?>
          </div>
          <?php if ($secondsUntilReset > 0): ?>
            <?php
      $h = floor($secondsUntilReset / 3600);
      $m = floor(($secondsUntilReset % 3600) / 60);
      $s = $secondsUntilReset % 60;
      $initDisplay = ($h > 0 ? $h . ":" : "") . str_pad($m, 2, "0", STR_PAD_LEFT) . ":" . str_pad($s, 2, "0", STR_PAD_LEFT);
      ?>
            <div id="cycle-countdown" class="prize-countdown" data-seconds="<?= $secondsUntilReset ?>">
              <?= $initDisplay ?>
            </div>
            <div class="prize-countdown-label">until next payout</div>
          <?php else: ?>
            <div class="prize-pending">
              <i class="fa-solid fa-hourglass-end"></i> Payout pending...
            </div>
          <?php endif; ?>
        </div>
      </div>

      <?php
      $weekTotal = 7 * 86400;
      $weekElapsed = time() - strtotime($weekStart);
      $weekPct = min(100, round(($weekElapsed / $weekTotal) * 100));
      ?>
      <div class="prize-progress-wrap">
        <div class="prize-progress-bar" style="width:<?= $weekPct ?>%;"></div>
      </div>
      <div class="prize-progress-labels">
        <span>Week started</span>
        <span><?= $weekPct ?>% complete</span>
        <span>Payout day</span>
      </div>
    </div>
  </div>

  <!-- USER RANK ALERTS -->
  <?php if ($currentUserRank && $currentUserRank > 20): ?>
    <div class="alert alert-info rank-alert">
      <i class="fa-solid fa-circle-info"></i>
      You're currently ranked <strong>#<?= $currentUserRank ?></strong> this week by bonus earned.
      Earn more by referring friends who purchase higher VIP plans!
      <a href="<?= BASE_URL ?>/referrals.php" class="alert-cta">Refer now →</a>
    </div>
  <?php endif; ?>

  <?php if ($currentUserRank && $currentUserRank <= 20): ?>
    <div class="alert alert-success rank-alert">
      <i class="fa-solid fa-trophy" style="color:var(--amber);"></i>
      You're in the <strong>top 20</strong> at <strong>#<?= $currentUserRank ?></strong> by bonus earned!
      Encourage your referrals to activate higher VIP tiers to boost your rank.
    </div>
  <?php endif; ?>

  <?php if (!$currentUserRank): ?>
    <div class="alert alert-info rank-alert">
      <i class="fa-solid fa-circle-info"></i>
      You haven't earned any referral bonuses this week yet. Refer friends who sign up and activate a VIP plan — just signing up isn't enough!
      <a href="<?= BASE_URL ?>/referrals.php" class="alert-cta">Start now →</a>
    </div>
  <?php endif; ?>

  <!-- ============================================================
       PODIUM SECTION — Top 3
       ========================================================= -->
  <?php if ($top3Data[1]): ?>
  <div class="podium-section">
    <!-- 2nd Place -->
    <div class="podium-slot slot-2 fade-in-left" style="animation-delay:0.2s;">
      <?php if ($top3Data[2]): $e = $top3Data[2]; ?>
        <div class="podium-avatar-wrap">
          <?php if ($e['profile_photo']): ?>
            <img src="<?= BASE_URL ?>/uploads/<?= e($e['profile_photo']) ?>" class="podium-avatar podium-silver" alt="">
          <?php else: ?>
            <span class="podium-avatar-placeholder podium-silver"><i class="fa-solid fa-user"></i></span>
          <?php endif; ?>
          <div class="podium-medal medal-silver"><i class="fa-solid fa-medal"></i></div>
        </div>
        <div class="podium-name"><?= e($e['name']) ?></div>
        <div class="podium-stats">
          <span class="podium-refs"><?= (int)$e['referral_count'] ?></span> referrals
          <div class="podium-bonus">+$<?= number_format((float)$e['bonus_earned'], 2) ?> bonus</div>
        </div>
        <div class="podium-prize">$<?= number_format($prize2nd) ?></div>
        <?php if ((int)$e['id'] === (int)$user['id']): ?><span class="you-tag podium-you">You</span><?php endif; ?>
      <?php endif; ?>
      <div class="podium-base base-2">
        <span class="base-label">2nd</span>
      </div>
    </div>

    <!-- 1st Place (center, raised) -->
    <div class="podium-slot slot-1 fade-in-up" style="animation-delay:0.1s;">
      <?php if ($top3Data[1]): $e = $top3Data[1]; ?>
        <div class="crown-animation">👑</div>
        <div class="podium-avatar-wrap">
          <?php if ($e['profile_photo']): ?>
            <img src="<?= BASE_URL ?>/uploads/<?= e($e['profile_photo']) ?>" class="podium-avatar podium-gold" alt="">
          <?php else: ?>
            <span class="podium-avatar-placeholder podium-gold"><i class="fa-solid fa-user"></i></span>
          <?php endif; ?>
          <div class="podium-medal medal-gold"><i class="fa-solid fa-crown"></i></div>
        </div>
        <div class="podium-name"><?= e($e['name']) ?></div>
        <div class="podium-stats">
          <span class="podium-refs"><?= (int)$e['referral_count'] ?></span> referrals
          <div class="podium-bonus">+$<?= number_format((float)$e['bonus_earned'], 2) ?> bonus</div>
        </div>
        <div class="podium-prize">$<?= number_format($prize1st) ?></div>
        <?php if ((int)$e['id'] === (int)$user['id']): ?><span class="you-tag podium-you">You</span><?php endif; ?>
      <?php endif; ?>
      <div class="podium-base base-1">
        <span class="base-label">1st</span>
      </div>
    </div>

    <!-- 3rd Place -->
    <div class="podium-slot slot-3 fade-in-right" style="animation-delay:0.3s;">
      <?php if ($top3Data[3]): $e = $top3Data[3]; ?>
        <div class="podium-avatar-wrap">
          <?php if ($e['profile_photo']): ?>
            <img src="<?= BASE_URL ?>/uploads/<?= e($e['profile_photo']) ?>" class="podium-avatar podium-bronze" alt="">
          <?php else: ?>
            <span class="podium-avatar-placeholder podium-bronze"><i class="fa-solid fa-user"></i></span>
          <?php endif; ?>
          <div class="podium-medal medal-bronze"><i class="fa-solid fa-medal"></i></div>
        </div>
        <div class="podium-name"><?= e($e['name']) ?></div>
        <div class="podium-stats">
          <span class="podium-refs"><?= (int)$e['referral_count'] ?></span> referrals
          <div class="podium-bonus">+$<?= number_format((float)$e['bonus_earned'], 2) ?> bonus</div>
        </div>
        <div class="podium-prize">$<?= number_format($prize3rd) ?></div>
        <?php if ((int)$e['id'] === (int)$user['id']): ?><span class="you-tag podium-you">You</span><?php endif; ?>
      <?php endif; ?>
      <div class="podium-base base-3">
        <span class="base-label">3rd</span>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ============================================================
       FULL LEADERBOARD TABLE (Ranks 4-10, or all if no top 3)
       ========================================================= -->
  <div class="card leaderboard-card">
    <div class="card-header">
      <h2>
        <i class="fa-solid fa-list-ol"></i>
        Full rankings
        <?php if (!$leaderboard): ?>
        <?php else: ?>
          <span class="count-badge"><?= count($leaderboard) ?></span>
        <?php endif; ?>
      </h2>
      <span class="auto-refresh-note"><i class="fa-solid fa-rotate"></i> Updates every 30s</span>
    </div>

    <?php if (!$leaderboard): ?>
      <div class="empty-state">
        <i class="fa-solid fa-trophy empty-icon"></i>
        <h3>No activated referrals yet</h3>
        <p class="text-muted">Refer friends who sign up and activate a VIP plan to earn bonuses and climb the leaderboard!</p>
        <a href="<?= BASE_URL ?>/referrals.php" class="btn btn-primary btn-sm mt-12">
          <i class="fa-solid fa-share-nodes"></i> Start referring
        </a>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table leaderboard-table" id="leaderboard-table">
          <thead>
            <tr>
              <th style="padding:16px 14px;width:50px;">Rank</th>
              <th style="padding:16px 14px;">User</th>
              <th style="padding:16px 14px;text-align:center;width:100px;">VIP Referrals <span class="sort-indicator" title="Only referred users who activated a VIP plan count" style="background:rgba(46,143,214,.12);color:var(--blue);cursor:help;">?</span></th>
              <th style="padding:16px 14px;text-align:right;width:120px;">Bonus earned <span class="sort-indicator" title="Ranked by bonus earned">↓</span></th>
              <th style="padding:16px 14px;text-align:center;width:80px;">Prize</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($leaderboard as $i => $entry):
              $rank = $i + 1;
              $isMe = (int)$entry['id'] === (int)$user['id'];
              $isPodium = $rank <= 3;
            ?>
              <tr class="leaderboard-row <?= $isMe ? 'row-is-me' : '' ?> <?= $isPodium ? 'row-podium row-podium-' . $rank : '' ?>"
                  style="animation-delay:<?= 0.05 * ($i - ($isPodium ? 0 : 3)) ?>s;">
                <td style="padding:14px;vertical-align:middle;">
                  <?php if ($rank === 1): ?>
                    <span class="rank-badge rank-gold"><i class="fa-solid fa-crown"></i></span>
                  <?php elseif ($rank === 2): ?>
                    <span class="rank-badge rank-silver"><i class="fa-solid fa-medal"></i></span>
                  <?php elseif ($rank === 3): ?>
                    <span class="rank-badge rank-bronze"><i class="fa-solid fa-medal"></i></span>
                  <?php else: ?>
                    <span class="rank-badge rank-number"><?= $rank ?></span>
                  <?php endif; ?>
                </td>
                <td style="padding:14px;vertical-align:middle;">
                  <div class="user-cell">
                    <?php if ($entry['profile_photo']): ?>
                      <img src="<?= BASE_URL ?>/uploads/<?= e($entry['profile_photo']) ?>" class="lb-avatar" alt="">
                    <?php else: ?>
                      <span class="lb-avatar-placeholder"><i class="fa-solid fa-user"></i></span>
                    <?php endif; ?>
                    <div>
                      <strong class="user-name"><?= e($entry['name']) ?></strong>
                      <?php if ($isMe): ?>
                        <span class="you-tag">You</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td style="padding:14px;text-align:center;vertical-align:middle;">
                  <div class="ref-count"><?= (int)$entry['referral_count'] ?></div>
                </td>
                <td style="padding:14px;text-align:right;vertical-align:middle;">
                  <span class="bonus-amount">+$<?= number_format((float)$entry['bonus_earned'], 2) ?></span>
                </td>
                <td style="padding:14px;text-align:center;vertical-align:middle;">
                  <?php if ($rank === 1): ?>
                    <span class="prize-badge" style="background:rgba(255,215,0,.2);color:#8a6412;">$<?= number_format($prize1st) ?></span>
                  <?php elseif ($rank === 2): ?>
                    <span class="prize-badge" style="background:rgba(192,192,192,.2);color:#555;">$<?= number_format($prize2nd) ?></span>
                  <?php elseif ($rank === 3): ?>
                    <span class="prize-badge" style="background:rgba(205,127,50,.2);color:#8a4512;">$<?= number_format($prize3rd) ?></span>
                  <?php elseif ($rank <= 20): ?>
                    <span class="prize-badge">$<?= number_format($prize4to20) ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="table-footer-note">
        <i class="fa-solid fa-circle-info"></i>
        Rankings are by <strong>total referral bonus earned</strong>, not just headcount. Only referred friends who <strong>activate a VIP plan</strong> count toward your score. Refer friends to higher VIP tiers (VIP 3 = $4 bonus) to climb faster! Top 20 each win <strong>$<?= number_format($prizePerPerson) ?></strong> on Monday.
      </div>
    <?php endif; ?>
  </div>

  <!-- ============================================================
       PREVIOUS WEEK WINNERS
       ========================================================= -->
  <?php if ($prevWinners): ?>
  <div class="card prev-winners-card">
    <div class="card-header">
      <h2><i class="fa-solid fa-clock-rotate-left"></i> Last week's winners</h2>
      <span class="prev-cycle-date">
        <?= e(date('M j', strtotime($prevCycle['week_start']))) ?> — <?= e(date('M j, Y', strtotime($prevCycle['week_end']))) ?>
      </span>
    </div>
    <div class="prev-winners-grid">
      <?php foreach ($prevWinners as $pw):
        $medal = '';
        if ($pw['rank_position'] === 1) $medal = '🥇';
        elseif ($pw['rank_position'] === 2) $medal = '🥈';
        elseif ($pw['rank_position'] === 3) $medal = '🥉';
      ?>
        <div class="prev-winner">
          <div class="prev-rank"><?= $medal ?: '#' . $pw['rank_position'] ?></div>
          <?php if ($pw['profile_photo']): ?>
            <img src="<?= BASE_URL ?>/uploads/<?= e($pw['profile_photo']) ?>" class="prev-avatar" alt="">
          <?php else: ?>
            <span class="prev-avatar-placeholder"><i class="fa-solid fa-user"></i></span>
          <?php endif; ?>
          <div class="prev-name"><?= e($pw['name']) ?></div>
          <div class="prev-stats"><?= (int)$pw['referral_count'] ?> refs · $<?= number_format((float)$pw['prize_amount'], 2) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ============================================================
       HOW IT WORKS
       ========================================================= -->
  <div class="card how-it-works-card">
    <h2><i class="fa-solid fa-circle-question"></i> How the weekly prize pool works</h2>
    <div class="prize-stats-grid">
      <div class="prize-stat-box">
        <div class="prize-stat-num">$<?= number_format($prizePool) ?></div>
        <div class="prize-stat-label">Total weekly prize pool</div>
      </div>
      <div class="prize-stat-box">
        <div class="prize-stat-num" style="color:var(--amber);">$<?= number_format($prize1st) ?> / $<?= number_format($prize2nd) ?> / $<?= number_format($prize3rd) ?></div>
        <div class="prize-stat-label">Top 3 prizes</div>
      </div>
    </div>
    <ol class="how-steps">
      <li><strong>Refer friends</strong> — Share your unique referral link. Each sign-up counts.</li>
      <li><strong>Climb the ranks</strong> — The more referrals you bring this week, the higher you climb.</li>
      <li><strong>Top 20 win</strong> — 1st gets $<?= number_format($prize1st) ?>, 2nd gets $<?= number_format($prize2nd) ?>, 3rd gets $<?= number_format($prize3rd) ?>, remaining 17 each get ~$<?= number_format($prize4to20) ?>.</li>
      <li><strong>Automatic payout</strong> — Prizes credited to wallets on Monday morning.</li>
    </ol>
  </div>
</div>

<!-- ============================================================
     STYLES
     ========================================================= -->
<style>
/* --- Live indicator --- */
.live-badge {
  display:inline-flex;align-items:center;gap:6px;
  font-size:12px;font-weight:600;font-family:'IBM Plex Mono',monospace;
  color:var(--green);background:rgba(138,210,74,.12);
  padding:4px 12px;border-radius:999px;
  vertical-align:middle;margin-left:8px;
  animation:badgePulse 2s ease-in-out infinite;
}
.live-dot {
  width:8px;height:8px;border-radius:50%;
  background:var(--green);
  animation:dotPulse 1.5s ease-in-out infinite;
}
@keyframes dotPulse {
  0%,100% { opacity:1; transform:scale(1); }
  50% { opacity:.5; transform:scale(0.8); }
}
@keyframes badgePulse {
  0%,100% { box-shadow:0 0 0 0 rgba(138,210,74,.2); }
  50% { box-shadow:0 0 0 6px rgba(138,210,74,0); }
}

/* --- Prize card --- */
.prize-card {
  background:linear-gradient(135deg,#0A1B29 0%,#1a2c3a 100%);
  border-radius:16px;padding:28px 24px;margin-bottom:24px;
  text-align:center;color:#EDEFEC;
  position:relative;overflow:hidden;
}
.prize-card-glow {
  position:absolute;top:-60px;right:-60px;
  width:200px;height:200px;border-radius:50%;
  background:radial-gradient(circle,rgba(138,210,74,.08),transparent 70%);
  animation:glowDrift 6s ease-in-out infinite;
}
@keyframes glowDrift {
  0%,100% { transform:translate(0,0) scale(1); opacity:.6; }
  50% { transform:translate(-20px,10px) scale(1.3); opacity:1; }
}
.prize-row {
  display:flex;align-items:center;justify-content:center;
  gap:20px;flex-wrap:wrap;
}
.prize-item {
  min-width:160px;
  animation:fadeInUp 0.6s ease forwards;
  opacity:0;
}
.prize-item:first-child { animation-delay:0.1s; }
.prize-item:last-child { animation-delay:0.2s; }
.prize-icon { font-size:32px;color:var(--amber);display:block;margin-bottom:8px; }
.prize-amount {
  font-size:28px;font-weight:700;font-family:'Space Grotesk',sans-serif;color:#fff;
  background:linear-gradient(90deg,#fff,var(--amber));
  -webkit-background-clip:text;background-clip:text;
  -webkit-text-fill-color:transparent;
}
.prize-label { font-size:12px;color:rgba(237,239,236,.5);margin-top:2px; }
.prize-divider {
  width:1px;height:50px;background:rgba(255,255,255,.1);
}
.prize-week {
  font-size:13px;font-family:'IBM Plex Mono',monospace;
  color:rgba(237,239,236,.5);margin-bottom:4px;
}
.prize-countdown {
  font-size:32px;font-weight:700;
  font-family:'Space Grotesk',sans-serif;
  color:var(--green);letter-spacing:2px;
  animation:countPulse 2s ease-in-out infinite;
}
@keyframes countPulse {
  0%,100% { opacity:1; }
  50% { opacity:.85; }
}
.prize-countdown-label {
  font-size:11px;font-family:'IBM Plex Mono',monospace;
  color:rgba(237,239,236,.4);margin-top:2px;
}
.prize-pending { font-size:18px;font-weight:700;color:var(--amber); }
.prize-progress-wrap {
  margin-top:18px;
  background:rgba(255,255,255,.06);
  border-radius:999px;height:6px;overflow:hidden;
}
.prize-progress-bar {
  height:100%;
  background:linear-gradient(90deg,var(--green),var(--amber));
  border-radius:999px;
  transition:width 1s ease;
  position:relative;
}
.prize-progress-bar::after {
  content:'';position:absolute;top:0;right:0;bottom:0;
  width:20px;background:linear-gradient(90deg,transparent,rgba(255,255,255,.3));
  border-radius:999px;
  animation:progressGlow 2s ease-in-out infinite;
}
@keyframes progressGlow {
  0%,100% { opacity:.5; }
  50% { opacity:1; }
}
.prize-progress-labels {
  display:flex;justify-content:space-between;
  font-size:10.5px;font-family:'IBM Plex Mono',monospace;
  color:rgba(237,239,236,.35);margin-top:5px;
}

/* --- Rank alerts --- */
.rank-alert {
  margin-bottom:20px;
  animation:slideDown 0.4s ease forwards;
  opacity:0;
}
@keyframes slideDown {
  from { opacity:0; transform:translateY(-10px); }
  to { opacity:1; transform:translateY(0); }
}
.alert-cta {
  margin-left:auto;font-weight:600;color:var(--blue);
  white-space:nowrap;
}
.alert-cta:hover { text-decoration:underline; }

/* --- Podium --- */
.podium-section {
  display:flex;align-items:flex-end;justify-content:center;
  gap:16px;margin-bottom:28px;padding:0 8px;
}
.podium-slot {
  display:flex;flex-direction:column;align-items:center;
  text-align:center;position:relative;
  opacity:0;
}
.fade-in-up {
  animation:fadeInUp 0.6s ease forwards;
}
.fade-in-left {
  animation:fadeInLeft 0.5s ease forwards;
}
.fade-in-right {
  animation:fadeInRight 0.5s ease forwards;
}
@keyframes fadeInUp {
  from { opacity:0; transform:translateY(30px); }
  to { opacity:1; transform:translateY(0); }
}
@keyframes fadeInLeft {
  from { opacity:0; transform:translateX(-30px); }
  to { opacity:1; transform:translateX(0); }
}
@keyframes fadeInRight {
  from { opacity:0; transform:translateX(30px); }
  to { opacity:1; transform:translateX(0); }
}
.slot-1 { order:2; padding-top:0; }
.slot-2 { order:1; }
.slot-3 { order:3; }

.podium-avatar-wrap {
  position:relative;margin-bottom:8px;
}
.podium-avatar, .podium-avatar-placeholder {
  width:72px;height:72px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:28px;transition:transform .3s;
}
.podium-avatar:hover { transform:scale(1.08); }
.podium-gold {
  width:88px;height:88px;font-size:32px;
  border:3px solid #FFD700;
  box-shadow:0 0 20px rgba(255,215,0,.3);
  animation:goldGlow 2s ease-in-out infinite;
}
@keyframes goldGlow {
  0%,100% { box-shadow:0 0 20px rgba(255,215,0,.3); }
  50% { box-shadow:0 0 35px rgba(255,215,0,.5); }
}
.podium-silver {
  border:3px solid #C0C0C0;
  box-shadow:0 0 15px rgba(192,192,192,.2);
}
.podium-bronze {
  border:3px solid #CD7F32;
  box-shadow:0 0 15px rgba(205,127,50,.2);
}
.podium-avatar-placeholder {
  background:var(--navy-deep);color:#fff;
}
.podium-medal {
  position:absolute;bottom:-4px;right:-4px;
  width:28px;height:28px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:13px;color:#fff;
  box-shadow:0 2px 8px rgba(0,0,0,.3);
  animation:medalBounce 2s ease-in-out infinite;
}
@keyframes medalBounce {
  0%,100% { transform:translateY(0); }
  50% { transform:translateY(-3px); }
}
.medal-gold { background:linear-gradient(135deg,#FFD700,#F7931A); }
.medal-silver { background:linear-gradient(135deg,#C0C0C0,#A0A0A0); }
.medal-bronze { background:linear-gradient(135deg,#CD7F32,#A0522D); }

.crown-animation {
  position:absolute;top:-20px;font-size:28px;
  animation:crownFloat 3s ease-in-out infinite;
  z-index:2;
}
@keyframes crownFloat {
  0%,100% { transform:translateY(0) rotate(-5deg); }
  50% { transform:translateY(-8px) rotate(5deg); }
}

.podium-name {
  font-family:'Space Grotesk',sans-serif;
  font-weight:700;font-size:15px;
  max-width:120px;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap;
}
.podium-stats {
  font-size:12px;color:var(--ink-soft);margin-top:2px;
}
.podium-refs {
  font-family:'Space Grotesk',monospace;
  font-weight:700;font-size:22px;color:var(--navy-deep);
}
.podium-prize {
  font-family:'IBM Plex Mono',monospace;
  font-size:13px;font-weight:600;
  color:var(--green);margin-top:2px;
}
.podium-you {
  margin-top:4px;display:inline-block;
}
.podium-base {
  width:100%;border-radius:8px 8px 0 0;
  display:flex;align-items:flex-end;
  justify-content:center;padding:12px 0 8px;
  margin-top:12px;transition:height .3s;
}
.base-1 {
  height:100px;
  background:linear-gradient(180deg,#FFD70022,#FFD70008);
  border-top:2px solid rgba(255,215,0,.25);
}
.base-2 {
  height:70px;
  background:linear-gradient(180deg,#C0C0C022,#C0C0C008);
  border-top:2px solid rgba(192,192,192,.2);
}
.base-3 {
  height:50px;
  background:linear-gradient(180deg,#CD7F3222,#CD7F3208);
  border-top:2px solid rgba(205,127,50,.2);
}
.base-label {
  font-family:'IBM Plex Mono',monospace;
  font-size:13px;font-weight:700;text-transform:uppercase;
  letter-spacing:1px;color:var(--ink-soft);
}

@media (max-width:600px) {
  .podium-section { gap:8px; }
  .podium-avatar, .podium-avatar-placeholder { width:56px;height:56px;font-size:20px; }
  .podium-gold { width:68px;height:68px;font-size:24px; }
  .podium-name { font-size:13px; max-width:90px; }
  .podium-refs { font-size:18px; }
  .base-1 { height:80px; }
  .base-2 { height:55px; }
  .base-3 { height:40px; }
}

/* --- Leaderboard table --- */
.leaderboard-card { padding:0;overflow:hidden; }
.count-badge {
  display:inline-flex;align-items:center;justify-content:center;
  min-width:22px;height:22px;padding:0 6px;
  border-radius:999px;background:var(--paper);
  font-family:'IBM Plex Mono',monospace;font-size:11px;
  color:var(--ink-soft);margin-left:8px;vertical-align:middle;
}
.auto-refresh-note {
  font-size:11px;color:var(--ink-soft);
  font-family:'IBM Plex Mono',monospace;
  display:flex;align-items:center;gap:4px;
}

.leaderboard-row {
  opacity:0;
  animation:rowSlideIn 0.4s ease forwards;
}
@keyframes rowSlideIn {
  from { opacity:0; transform:translateX(-10px); }
  to { opacity:1; transform:translateX(0); }
}
.row-podium { background:rgba(138,210,74,.025); }
.row-podium-1 { background:rgba(255,215,0,.04); }
.row-podium-2 { background:rgba(192,192,192,.03); }
.row-podium-3 { background:rgba(205,127,50,.03); }
.row-is-me {
  background:rgba(138,210,74,.08) !important;
  transition:background .3s;
}
.row-is-me:hover { background:rgba(138,210,74,.12) !important; }

.leaderboard-table tbody tr {
  transition:background .2s, transform .15s;
  cursor:default;
}
.leaderboard-table tbody tr:hover {
  transform:scale(1.002);
}

.user-cell { display:flex;align-items:center;gap:10px; }
.lb-avatar { width:34px;height:34px;border-radius:50%;object-fit:cover;border:2px solid var(--paper-line); }
.lb-avatar-placeholder {
  width:34px;height:34px;border-radius:50%;
  background:var(--navy-deep);color:#fff;
  display:inline-flex;align-items:center;justify-content:center;font-size:13px;
}
.user-name { font-size:15px; }

.you-tag {
  font-size:11px;color:var(--green);font-weight:600;margin-left:6px;
  background:rgba(138,210,74,.12);padding:2px 7px;border-radius:999px;
}
.rank-badge {
  display:inline-flex;align-items:center;justify-content:center;
  width:36px;height:36px;border-radius:50%;
  font-weight:700;font-size:14px;
  font-family:'Space Grotesk',monospace;
  transition:transform .2s;
}
.rank-badge:hover { transform:scale(1.12); }
.rank-gold {
  background:linear-gradient(135deg,#FFD700,#F7931A);color:#fff;
  box-shadow:0 2px 12px rgba(255,215,0,.4);
  animation:goldPulse 2s ease-in-out infinite;
}
@keyframes goldPulse {
  0%,100% { box-shadow:0 2px 12px rgba(255,215,0,.4); }
  50% { box-shadow:0 2px 20px rgba(255,215,0,.6); }
}
.rank-silver {
  background:linear-gradient(135deg,#C0C0C0,#A0A0A0);color:#fff;
  box-shadow:0 2px 8px rgba(192,192,192,.3);
}
.rank-bronze {
  background:linear-gradient(135deg,#CD7F32,#A0522D);color:#fff;
  box-shadow:0 2px 8px rgba(205,127,50,.3);
}
.rank-number {
  background:var(--paper);color:var(--ink-soft);
  font-weight:600;font-size:15px;
}

.ref-count {
  font-family:'Space Grotesk',monospace;
  font-weight:700;font-size:20px;color:var(--navy-deep);
  transition:color .2s;
}
tr:hover .ref-count { color:var(--green); }

.bonus-amount {
  font-family:'IBM Plex Mono',monospace;
  font-size:14px;color:var(--green);
}
.prize-badge {
  display:inline-block;
  font-family:'IBM Plex Mono',monospace;
  font-size:11.5px;font-weight:600;
  background:rgba(232,181,74,.15);color:#8a6412;
  padding:3px 9px;border-radius:999px;
  transition:transform .2s, background .2s;
}
.prize-badge:hover { transform:scale(1.1); background:rgba(232,181,74,.25); }
.table-footer-note {
  padding:14px 18px;background:var(--paper);
  border-top:1px solid var(--paper-line);
  font-size:12.5px;color:var(--ink-soft);
  display:flex;align-items:center;gap:8px;flex-wrap:wrap;
}
.empty-state {
  padding:50px 40px;text-align:center;
}
.empty-icon {
  font-size:56px;display:block;margin-bottom:14px;
  color:var(--amber);opacity:.6;
}

/* --- Previous winners --- */
.prev-winners-grid {
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(120px,1fr));
  gap:12px;
}
.prev-winner {
  background:var(--paper);border-radius:12px;padding:14px 8px;
  text-align:center;transition:transform .2s, box-shadow .2s;
}
.prev-winner:hover {
  transform:translateY(-4px);
  box-shadow:0 8px 24px rgba(10,21,32,.08);
}
.prev-rank { font-size:18px;margin-bottom:6px; }
.prev-avatar {
  width:36px;height:36px;border-radius:50%;object-fit:cover;
  margin:0 auto 6px;
}
.prev-avatar-placeholder {
  width:36px;height:36px;border-radius:50%;
  background:var(--navy-deep);color:#fff;
  display:inline-flex;align-items:center;justify-content:center;
  font-size:13px;margin:0 auto 6px;
}
.prev-name {
  font-size:12.5px;font-weight:600;line-height:1.2;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.prev-stats {
  font-size:10.5px;color:var(--ink-soft);margin-top:2px;
  font-family:'IBM Plex Mono',monospace;
}
.prev-cycle-date {
  font-size:12px;font-family:'IBM Plex Mono',monospace;color:var(--ink-soft);
}

/* --- How it works --- */
.prize-stats-grid {
  display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;
}
.prize-stat-box {
  background:var(--paper);border-radius:12px;padding:16px;text-align:center;
}
.prize-stat-num {
  font-size:24px;font-weight:700;
  font-family:'Space Grotesk',sans-serif;color:var(--green);
}
.prize-stat-label {
  font-size:12.5px;color:var(--ink-soft);margin-top:4px;
}
.how-steps {
  margin-top:16px;padding-left:20px;
  font-size:14px;color:var(--ink-soft);line-height:1.8;
}


/* --- Sort indicator --- */
.sort-indicator {
  display:inline-flex;align-items:center;justify-content:center;
  width:20px;height:20px;border-radius:4px;
  background:rgba(138,210,74,.15);color:var(--green);
  font-size:12px;font-weight:700;margin-left:4px;
  cursor:help;vertical-align:middle;
}

/* --- VIP strategy card --- */
.vip-strategy-card {
  background:linear-gradient(135deg,rgba(10,27,41,.03),rgba(138,210,74,.05));
  border:1px solid rgba(138,210,74,.2);
  border-radius:12px;padding:18px 20px;
  margin-bottom:16px;
}
.vip-strategy-title {
  font-family:'Space Grotesk',sans-serif;
  font-size:16px;font-weight:600;
  color:var(--navy-deep);margin-bottom:8px;
}
.vip-strategy-title .fa-lightbulb { color:var(--amber);margin-right:6px; }
.vip-strategy-card p { font-size:13.5px;color:var(--ink-soft);margin:0;line-height:1.6; }
.vip-bonus-table {
  margin:10px 0 0;display:flex;flex-direction:column;gap:6px;
}
.vip-row {
  display:flex;align-items:center;gap:10px;
  padding:8px 12px;border-radius:8px;
  background:rgba(255,255,255,.6);
}
.vip-badge {
  font-family:'IBM Plex Mono',monospace;
  font-size:10.5px;font-weight:600;padding:2px 8px;
  border-radius:999px;white-space:nowrap;
}
.mini-vip3 { background:rgba(232,181,74,.2);color:#8a6412; }
.mini-vip2 { background:rgba(138,210,74,.2);color:#2f5c12; }
.mini-vip1 { background:rgba(46,143,214,.15);color:#1a4f78; }
.vip-earn { font-family:'IBM Plex Mono',monospace;font-size:12.5px;font-weight:600; }
.vip-star {
  margin-left:auto;font-size:11px;
  color:var(--amber);font-weight:600;
}
</style>

<!-- ============================================================
     JAVASCRIPT
     ========================================================= -->
<script>
(function() {
  // ---- Countdown timer ----
  var countdownEl = document.getElementById('cycle-countdown');
  if (countdownEl) {
    var totalSec = parseInt(countdownEl.dataset.seconds, 10);
    function tick() {
      if (totalSec <= 0) {
        countdownEl.textContent = 'Payout time!';
        countdownEl.style.color = 'var(--amber)';
        return;
      }
      var d = Math.floor(totalSec / 86400);
      var h = Math.floor((totalSec % 86400) / 3600);
      var m = Math.floor((totalSec % 3600) / 60);
      var s = totalSec % 60;
      if (d > 0) {
        countdownEl.textContent = d + 'd ' +
          String(h).padStart(2,'0') + ':' +
          String(m).padStart(2,'0') + ':' +
          String(s).padStart(2,'0');
      } else {
        countdownEl.textContent =
          String(h).padStart(2,'0') + ':' +
          String(m).padStart(2,'0') + ':' +
          String(s).padStart(2,'0');
      }
      totalSec--;
      setTimeout(tick, 1000);
    }
    tick();
  }

  // ---- Row hover effects ----
  document.querySelectorAll('.leaderboard-row').forEach(function(row, i) {
    row.style.animationDelay = (0.05 * i) + 's';
  });

  // ---- Live auto-refresh via JSON polling (30s interval) ----
  var refreshInterval = 30000;
  var refreshTimer = null;
  var refreshingNow = false;

  function refreshLeaderboard() {
    if (refreshingNow) return;
    refreshingNow = true;

    var rotateIcon = document.querySelector('.auto-refresh-note .fa-rotate');
    if (rotateIcon) rotateIcon.style.animation = 'spin 0.6s linear';

    fetch(window.location.pathname + '?ajax=1&t=' + Date.now())
      .then(function(r) { return r.json(); })
      .then(function(data) {
        refreshingNow = false;
        if (rotateIcon) rotateIcon.style.animation = '';

        // Update countdown
        var cd = document.getElementById('cycle-countdown');
        if (cd) cd.dataset.seconds = data.seconds_until_reset;

        // Update table rows: match by data-user-id attribute
        var tbody = document.querySelector('#leaderboard-table tbody');
        if (tbody && data.leaderboard.length) {
          var rows = tbody.querySelectorAll('tr');
          data.leaderboard.forEach(function(item, idx) {
            if (rows[idx]) {
              var refCount = rows[idx].querySelector('.ref-count');
              var bonusAmt = rows[idx].querySelector('.bonus-amount');
              if (refCount) {
                var oldVal = parseInt(refCount.textContent, 10);
                refCount.textContent = item.referral_count;
                // Animate if value changed
                if (oldVal !== item.referral_count) {
                  refCount.style.color = 'var(--amber)';
                  refCount.style.transition = 'color .5s';
                  setTimeout(function() { refCount.style.color = ''; }, 1000);
                }
              }
              if (bonusAmt) {
                bonusAmt.textContent = '+$' + item.bonus_earned.toFixed(2);
              }
            }
          });
        }
      })
      .catch(function() {
        refreshingNow = false;
        if (rotateIcon) rotateIcon.style.animation = '';
      });
  }

  // Start after initial animations complete
  setTimeout(function() {
    refreshTimer = setInterval(refreshLeaderboard, refreshInterval);
  }, 5000);

  // Pause when tab hidden
  document.addEventListener('visibilitychange', function() {
    if (document.hidden && refreshTimer) {
      clearInterval(refreshTimer);
      refreshTimer = null;
    } else if (!document.hidden && !refreshTimer) {
      refreshTimer = setInterval(refreshLeaderboard, refreshInterval);
    }
  });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
