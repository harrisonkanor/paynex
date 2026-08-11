<?php
header('Content-Type: text/plain');

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

echo "=== Leaderboard Data Check ===\n\n";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=paynex;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // Check VIP plans and their referral bonuses
    echo "--- VIP Plans ---\n";
    $plans = $pdo->query("SELECT level, label, referral_bonus FROM vip_plans ORDER BY level")->fetchAll();
    foreach ($plans as $p) {
        echo "VIP " . $p['level'] . " (" . $p['label'] . "): \$" . $p['referral_bonus'] . " per referral\n";
    }
    
    // Check fake users and their VIP levels
    echo "\n--- Fake Users (leaderboard) ---\n";
    $users = $pdo->query("SELECT id, name, vip_level, total_referrals, wallet_balance FROM users WHERE role = 'earner' AND id > 3 ORDER BY total_referrals DESC LIMIT 20")->fetchAll();
    foreach ($users as $u) {
        echo "ID: " . $u['id'] . ", Name: " . $u['name'] . ", VIP: " . $u['vip_level'] . ", Referrals: " . $u['total_referrals'] . ", Balance: \$" . $u['wallet_balance'] . "\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
