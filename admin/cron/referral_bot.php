<?php
/**
 * admin/cron/referral_bot.php — Simulates organic referral growth.
 *
 * Each hour, this script adds 3–5 random referrals between active earners,
 * with random VIP levels and crediting the referrer's wallet.
 *
 * Cron (every hour):
 *   0 * * * * /usr/bin/php /opt/lampp/htdocs/paynex/admin/cron/referral_bot.php
 */
require_once __DIR__ . '/../../config/config.php';
if (php_sapi_name() !== 'cli') die('CLI only.');

echo "[" . date('Y-m-d H:i:s') . "] Referral bot running...\n";

// Get all active earners, shuffled
$earners = $pdo->query(
    "SELECT id, name FROM users WHERE role = 'earner' AND status = 'active' ORDER BY RAND()"
)->fetchAll();

if (count($earners) < 2) {
    echo "Not enough earners to create referrals.\n";
    exit(0);
}

// Add 3-5 referrals this hour
$numReferrals = random_int(3, 5);
$inserted = 0;

for ($i = 0; $i < $numReferrals; $i++) {
    // Pick a random referrer
    $referrer = $earners[array_rand($earners)];

    // Pick a random referred user different from the referrer
    $candidates = array_values(array_filter($earners, fn($u) => (int)$u['id'] !== (int)$referrer['id']));
    if (empty($candidates)) continue;
    $referred = $candidates[array_rand($candidates)];

    // Check if this user is already referred by anyone
    $check = $pdo->prepare("SELECT id FROM referrals WHERE referred_id = :rid");
    $check->execute([':rid' => $referred['id']]);
    if ($check->fetchColumn()) continue; // already referred, skip

    // Random VIP level (1–3)
    $vipLevel = random_int(1, 3);
    $bonusAmount = match ($vipLevel) {
        1 => 1.00,
        2 => 2.00,
        3 => 4.00,
        default => 1.00,
    };

    // Random time within the current leaderboard cycle or last 72 hours
    $randomTime = date('Y-m-d H:i:s', time() - random_int(0, 72 * 3600));

    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            "INSERT INTO referrals (referrer_id, referred_id, vip_level, bonus_paid, bonus_amount, created_at)
             VALUES (:ref, :reffed, :vl, 1, :bonus, :ca)"
        )->execute([
            ':ref'    => $referrer['id'],
            ':reffed' => $referred['id'],
            ':vl'     => $vipLevel,
            ':bonus'  => $bonusAmount,
            ':ca'     => $randomTime,
        ]);

        // Credit referrer's wallet
        $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :id")
            ->execute([':amt' => $bonusAmount, ':id' => $referrer['id']]);

        // Record transaction
        $pdo->prepare(
            "INSERT INTO wallet_transactions (user_id, type, amount, description)
             VALUES (:uid, 'credit', :amt, :desc)"
        )->execute([
            ':uid'  => $referrer['id'],
            ':amt'  => $bonusAmount,
            ':desc' => 'Referral bonus — new VIP ' . $vipLevel . ' member (auto)',
        ]);

        $pdo->commit();
        $inserted++;
        echo "  ✅ Referral #{$i}: {$referrer['name']} → user #{$referred['id']} (VIP {$vipLevel}) +$" . number_format($bonusAmount, 2) . "\n";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "  ❌ Could not insert referral #{$i}: " . $e->getMessage() . "\n";
    }
}

echo "Inserted {$inserted} referrals this cycle.\n";
echo "[" . date('Y-m-d H:i:s') . "] Done.\n";
