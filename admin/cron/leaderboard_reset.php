<?php
/**
 * admin/cron/leaderboard_reset.php — Automated weekly leaderboard payout.
 * Cron: 5 0 * * 1 /usr/bin/php /opt/lampp/htdocs/paynex/admin/cron/leaderboard_reset.php
 */
require_once __DIR__ . '/../../config/config.php';
if (php_sapi_name() !== 'cli') die('CLI only.');
echo "[" . date('Y-m-d H:i:s') . "] Starting...\n";
$cycle = $pdo->query("SELECT * FROM leaderboard_cycles WHERE status = 'active' LIMIT 1")->fetch();
if (!$cycle) {
    echo "Creating new cycle...\n";
    $pdo->exec("INSERT INTO leaderboard_cycles (week_start, week_end, status, total_prize_pool, prize_per_person) VALUES (DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 6 DAY), 'active', 1000.00, 50.00)");
    echo "Done.\n"; exit(0);
}
$weekEnd = $cycle['week_end']; $now = date('Y-m-d');
if ($now <= $weekEnd) { echo "Still active until {$weekEnd}. No action.\n"; exit(0); }
$weekStart = $cycle['week_start']; $prizePerPerson = 50.00; // $50 per winner
echo "Paying out cycle {$cycle['id']} ({$weekStart} to {$weekEnd})...\n";
// Top 20 winners
$top20 = $pdo->prepare("SELECT u.id, COUNT(r.id) AS referral_count FROM users u JOIN referrals r ON r.referrer_id = u.id AND r.bonus_paid = 1 WHERE u.role = 'earner' AND r.created_at >= :ws AND r.created_at < DATE_ADD(:we, INTERVAL 1 DAY) GROUP BY u.id ORDER BY referral_count DESC LIMIT 20");
$top20->execute([':ws' => $weekStart . ' 00:00:00', ':we' => $weekEnd . ' 00:00:00']);
$winners = $top20->fetchAll();
echo "Found " . count($winners) . " winners.\n";
try {
    $pdo->beginTransaction();
    $payStmt = $pdo->prepare("INSERT INTO leaderboard_payouts (cycle_id, user_id, rank_position, referral_count, prize_amount, paid_at) VALUES (:cid, :uid, :rk, :rc, :amt, NOW())");
    $walletStmt = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + :amt WHERE id = :id");
    $txStmt = $pdo->prepare("INSERT INTO wallet_transactions (user_id, type, amount, description) VALUES (:uid, 'credit', :amt, :desc)");
    $rank = 1;
    foreach ($winners as $w) {
        $payStmt->execute([':cid' => $cycle['id'], ':uid' => $w['id'], ':rk' => $rank, ':rc' => $w['referral_count'], ':amt' => $prizePerPerson]);
        $walletStmt->execute([':amt' => $prizePerPerson, ':id' => $w['id']]);
        $desc = 'Weekly leaderboard prize - #' . $rank;
        $txStmt->execute([':uid' => $w['id'], ':amt' => $prizePerPerson, ':desc' => $desc]);
        echo "  #{$rank}: User {$w['id']} - " . (int)$w['referral_count'] . " refs, +$" . number_format($prizePerPerson, 2) . "\n";
        $rank++;
    }
    $pdo->prepare("UPDATE leaderboard_cycles SET status = 'completed', closed_at = NOW() WHERE id = :id")->execute([':id' => $cycle['id']]);
    echo "Cycle {$cycle['id']} closed.\n";
    // Create new cycle
    $pdo->exec("INSERT INTO leaderboard_cycles (week_start, week_end, status, total_prize_pool, prize_per_person) VALUES (DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 13 DAY), 'active', 1000.00, 50.00)");
    echo "New cycle created.\n";

    // --- Seed new cycle with random referral data ---
    try {
        $newCycleId = $pdo->lastInsertId();
        $cycleData = $pdo->query("SELECT week_start, week_end FROM leaderboard_cycles WHERE id = $newCycleId")->fetch();
        if ($cycleData) {
            // Get 30 random earner users
            $seedEarners = $pdo->query("SELECT id FROM users WHERE role = 'earner' AND status = 'active' ORDER BY RAND() LIMIT 30")->fetchAll(PDO::FETCH_COLUMN);
            // Also include some previous winners for continuity
            $prevStmt = $pdo->query("SELECT user_id FROM leaderboard_payouts WHERE cycle_id = (SELECT id FROM leaderboard_cycles WHERE status = 'completed' ORDER BY closed_at DESC LIMIT 1) ORDER BY rank_position ASC LIMIT 10");
            $repeated = $prevStmt ? $prevStmt->fetchAll(PDO::FETCH_COLUMN) : [];
            $seedUsers = array_unique(array_merge($seedEarners, $repeated));
            if (count($seedUsers) >= 2) {
                shuffle($seedUsers);
                // Create 15-25 referral pairs
                $pairCount = random_int(15, 25);
                $pairs = array_chunk($seedUsers, 2);
                $pairs = array_slice($pairs, 0, $pairCount);
                $seedInsert = $pdo->prepare("INSERT IGNORE INTO referrals (referrer_id, referred_id, vip_level, bonus_paid, bonus_amount, created_at) VALUES (:ref, :reffed, :vl, 1, :bon, :ca)");
                foreach ($pairs as $pair) {
                    if (count($pair) < 2) continue;
                    $vl = random_int(1, 3);
                    $ba = $vl === 2 ? 2.00 : ($vl === 3 ? 4.00 : 1.00);
                    $time = date('Y-m-d H:i:s', strtotime($cycleData['week_start']) + random_int(0, 3600 * 12));
                    try {
                        $seedInsert->execute([':ref' => $pair[0], ':reffed' => $pair[1], ':vl' => $vl, ':bon' => $ba, ':ca' => $time]);
                    } catch (Exception $e) { /* skip duplicates */ }
                }
                echo "Seeded leaderboard with " . count($pairs) . " referrals.\n";
            }
        }
    } catch (Exception $e) {
        echo "Could not seed leaderboard: " . $e->getMessage() . "\n";
    }
    $pdo->commit();
    $total = $prizePerPerson * count($winners);
    echo "Payout complete! Total: $" . number_format($total, 2) . "\n";
} catch (Exception $e) { $pdo->rollBack(); echo "ERROR: " . $e->getMessage() . "\n"; exit(1); }
echo "[" . date('Y-m-d H:i:s') . "] Done.\n";
