<?php
header('Content-Type: text/plain');

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

echo "=== Updating VIP 3 Referral Bonus ===\n\n";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=paynex;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // 1. Update VIP 3 plan bonus from $5 to $4
    echo "1. Updating VIP 3 plan bonus...\n";
    $stmt = $pdo->prepare("UPDATE vip_plans SET referral_bonus = 4.00 WHERE level = 3");
    $stmt->execute();
    echo "   VIP 3 bonus changed from \$5.00 to \$4.00\n\n";
    
    // 2. Update all referrals for VIP 3 referrers
    echo "2. Updating VIP 3 referrals...\n";
    $stmt = $pdo->prepare("UPDATE referrals r JOIN users u ON u.id = r.referrer_id SET r.bonus_amount = 4.00 WHERE u.vip_level = 3");
    $stmt->execute();
    echo "   Updated VIP 3 referrals\n\n";
    
    // 3. Update user balances for VIP 3 users
    echo "3. Updating VIP 3 user balances...\n";
    $vip3Users = $pdo->query("SELECT id FROM users WHERE vip_level = 3 AND role = 'earner'")->fetchAll();
    foreach ($vip3Users as $u) {
        $totalStmt = $pdo->prepare("SELECT COALESCE(SUM(bonus_amount), 0) FROM referrals WHERE referrer_id = ?");
        $totalStmt->execute([$u['id']]);
        $totalBonus = (float) $totalStmt->fetchColumn();
        
        $upd = $pdo->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?");
        $upd->execute([$totalBonus, $u['id']]);
    }
    echo "   Updated " . count($vip3Users) . " VIP 3 user balances\n\n";
    
    // 4. Verify changes
    echo "--- Verification ---\n";
    $plans = $pdo->query("SELECT level, referral_bonus FROM vip_plans ORDER BY level")->fetchAll();
    foreach ($plans as $p) {
        echo "VIP " . $p['level'] . ": \$" . $p['referral_bonus'] . " per referral\n";
    }
    
    echo "\nTop VIP 3 users:\n";
    $top = $pdo->query("SELECT name, vip_level, wallet_balance FROM users WHERE vip_level = 3 AND role = 'earner' ORDER BY wallet_balance DESC LIMIT 5")->fetchAll();
    foreach ($top as $t) {
        echo "  " . $t['name'] . ": \$" . $t['wallet_balance'] . "\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
