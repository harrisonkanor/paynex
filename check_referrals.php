<?php
header('Content-Type: text/plain');

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

echo "=== Referral Data Check ===\n\n";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=paynex;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // Check VIP plans
    echo "--- VIP Plans ---\n";
    $plans = $pdo->query("SELECT level, label, referral_bonus FROM vip_plans ORDER BY level")->fetchAll();
    foreach ($plans as $p) {
        echo "VIP " . $p['level'] . ": \$" . $p['referral_bonus'] . " per referral\n";
    }
    
    // Check referrals table structure
    echo "\n--- Referrals Table Structure ---\n";
    $cols = $pdo->query("SHOW COLUMNS FROM referrals")->fetchAll();
    foreach ($cols as $c) {
        echo $c['Field'] . " (" . $c['Type'] . ")\n";
    }
    
    // Check sample referrals
    echo "\n--- Sample Referrals ---\n";
    $refs = $pdo->query("SELECT r.*, u.name as referrer_name, u.vip_level as referrer_vip FROM referrals r JOIN users u ON u.id = r.referrer_id LIMIT 10")->fetchAll();
    foreach ($refs as $r) {
        echo "Referrer: " . $r['referrer_name'] . " (VIP " . $r['referrer_vip'] . "), Referred: User ID " . $r['referred_id'] . ", Bonus: \$" . $r['bonus_amount'] . "\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
