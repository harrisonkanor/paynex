<?php
header('Content-Type: text/plain');

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

echo "=== Fixing Leaderboard Data ===\n\n";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=paynex;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // 1. First, update VIP plans with different referral bonuses
    echo "1. Updating VIP plan referral bonuses...\n";
    $pdo->exec("UPDATE vip_plans SET referral_bonus = 1.00 WHERE level = 1");
    $pdo->exec("UPDATE vip_plans SET referral_bonus = 2.00 WHERE level = 2");
    $pdo->exec("UPDATE vip_plans SET referral_bonus = 5.00 WHERE level = 3");
    echo "   VIP 1: \$1.00, VIP 2: \$2.00, VIP 3: \$5.00\n\n";
    
    // 2. Get all fake users (id > 3)
    echo "2. Updating fake user VIP levels and balances...\n";
    $users = $pdo->query("SELECT id, name FROM users WHERE role = 'earner' AND id > 3")->fetchAll();
    
    $updated = 0;
    foreach ($users as $u) {
        // Random VIP level (1, 2, or 3)
        $vipLevel = rand(1, 3);
        
        // Get referral bonus for this VIP level
        $plan = $pdo->prepare("SELECT referral_bonus FROM vip_plans WHERE level = ?");
        $plan->execute([$vipLevel]);
        $bonus = (float) $plan->fetchColumn();
        
        // Count referrals for this user
        $refCount = $pdo->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_id = ?");
        $refCount->execute([$u['id']]);
        $numRefs = (int) $refCount->fetchColumn();
        
        // Calculate balance based on referrals and VIP level bonus
        $balance = $numRefs * $bonus;
        
        // Update user
        $upd = $pdo->prepare("UPDATE users SET vip_level = ?, wallet_balance = ? WHERE id = ?");
        $upd->execute([$vipLevel, $balance, $u['id']]);
        
        echo "   " . $u['name'] . ": VIP " . $vipLevel . ", Referrals: " . $numRefs . ", Bonus/ref: \$" . $bonus . ", Balance: \$" . $balance . "\n";
        $updated++;
    }
    
    echo "\n3. Updated " . $updated . " users\n";
    
    // 4. Verify the changes
    echo "\n--- Verification ---\n";
    $verify = $pdo->query("SELECT u.name, u.vip_level, u.wallet_balance, COUNT(r.id) as ref_count FROM users u LEFT JOIN referrals r ON r.referrer_id = u.id WHERE u.role = 'earner' AND u.id > 3 GROUP BY u.id ORDER BY u.wallet_balance DESC LIMIT 10")->fetchAll();
    foreach ($verify as $v) {
        echo $v['name'] . ": VIP " . $v['vip_level'] . ", Referrals: " . $v['ref_count'] . ", Balance: \$" . $v['wallet_balance'] . "\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
