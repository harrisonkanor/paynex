<?php
header('Content-Type: text/plain');

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

echo "=== Fixing Referral Data ===\n\n";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=paynex;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // Get VIP plan bonuses
    $plans = [];
    $planRows = $pdo->query("SELECT level, referral_bonus FROM vip_plans")->fetchAll();
    foreach ($planRows as $p) {
        $plans[$p['level']] = (float) $p['referral_bonus'];
    }
    echo "VIP Plan Bonuses:\n";
    echo "  VIP 1: \$" . $plans[1] . "\n";
    echo "  VIP 2: \$" . $plans[2] . "\n";
    echo "  VIP 3: \$" . $plans[3] . "\n\n";
    
    // Update all referrals to match referrer's VIP level bonus
    echo "Updating referrals...\n";
    $refs = $pdo->query("SELECT r.id, r.referrer_id, u.vip_level FROM referrals r JOIN users u ON u.id = r.referrer_id")->fetchAll();
    
    $updated = 0;
    foreach ($refs as $r) {
        $vipLevel = (int) $r['vip_level'];
        $bonus = $plans[$vipLevel] ?? 1.00;
        
        // Update the referral bonus amount and vip_level
        $upd = $pdo->prepare("UPDATE referrals SET bonus_amount = ?, vip_level = ? WHERE id = ?");
        $upd->execute([$bonus, $vipLevel, $r['id']]);
        $updated++;
    }
    echo "Updated " . $updated . " referrals\n\n";
    
    // Update user balances based on new bonus amounts
    echo "Updating user balances...\n";
    $users = $pdo->query("SELECT DISTINCT referrer_id FROM referrals")->fetchAll();
    
    $balanceUpdated = 0;
    foreach ($users as $u) {
        $referrerId = $u['referrer_id'];
        
        // Get total bonus for this user from referrals
        $totalStmt = $pdo->prepare("SELECT COALESCE(SUM(bonus_amount), 0) FROM referrals WHERE referrer_id = ?");
        $totalStmt->execute([$referrerId]);
        $totalBonus = (float) $totalStmt->fetchColumn();
        
        // Update user's wallet balance
        $upd = $pdo->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?");
        $upd->execute([$totalBonus, $referrerId]);
        $balanceUpdated++;
    }
    echo "Updated " . $balanceUpdated . " user balances\n\n";
    
    // Verify the changes
    echo "--- Verification ---\n";
    $verify = $pdo->query("SELECT u.name, u.vip_level, u.wallet_balance, COUNT(r.id) as ref_count, SUM(r.bonus_amount) as total_bonus FROM users u LEFT JOIN referrals r ON r.referrer_id = u.id WHERE u.role = 'earner' AND u.id > 3 GROUP BY u.id ORDER BY u.wallet_balance DESC LIMIT 10")->fetchAll();
    foreach ($verify as $v) {
        echo $v['name'] . ": VIP " . $v['vip_level'] . ", Referrals: " . $v['ref_count'] . ", Total Bonus: \$" . $v['total_bonus'] . ", Balance: \$" . $v['wallet_balance'] . "\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
