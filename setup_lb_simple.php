<?php
header('Content-Type: text/plain');

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

echo "=== Quick Leaderboard Setup ===\n\n";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=paynex;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // 1. Update prize pool
    $pdo->exec("UPDATE leaderboard_cycles SET total_prize_pool = 1000.00, prize_per_person = 50.00 WHERE status = 'active'");
    echo "1. Prize pool updated: $1000 total, $50 per winner\n";
    
    // 2. Create 30 fake users quickly
    echo "2. Creating fake users...\n";
    $names = ['Emma Thompson','James Wilson','Olivia Martinez','Liam Anderson','Sophia Garcia',
              'Noah Robinson','Isabella Clark','Mason Lewis','Mia Walker','Ethan Hall',
              'Charlotte Allen','Alexander Young','Amelia King','Daniel Wright','Harper Scott',
              'Matthew Green','Evelyn Adams','Sebastian Baker','Abigail Nelson','Jack Hill',
              'Emily Campbell','Henry Mitchell','Elizabeth Roberts','Aiden Carter','Sofia Phillips',
              'Owen Evans','Ella Turner','Lucas Parker','Chloe Collins','Benjamin Edwards'];
    
    $userIds = [];
    foreach ($names as $name) {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        $check = $pdo->prepare("SELECT id FROM users WHERE email = :e");
        $check->execute([':e' => $email]);
        $existing = $check->fetch();
        if ($existing) { $userIds[] = $existing['id']; continue; }
        
        $code = strtoupper(bin2hex(random_bytes(4)));
        $stmt = $pdo->prepare("INSERT INTO users (name,email,password_hash,role,vip_level,referral_code,status,email_verified)
                              VALUES (:n,:e,:h,'earner',:v,:c,'active',1)");
        $stmt->execute([':n'=>$name,':e'=>$email,':h'=>password_hash('pass123',PASSWORD_DEFAULT),':v'=>random_int(1,3),':c'=>$code]);
        $userIds[] = $pdo->lastInsertId();
    }
    echo "   Created " . count($userIds) . " users\n";
    
    // 3. Create referrals (batch insert for speed)
    echo "3. Creating referrals...\n";
    $totalRefs = 0;
    foreach ($userIds as $uid) {
        $refCount = random_int(11, 29);
        $existing = $pdo->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_id = :u AND bonus_paid = 1");
        $existing->execute([':u'=>$uid]);
        $have = (int)$existing->fetchColumn();
        $need = $refCount - $have;
        if ($need <= 0) continue;
        
        // Batch insert
        $values = [];
        $params = [];
        for ($i=0; $i<$need; $i++) {
            $dummyEmail = 'ref_'.$uid.'_'.time().'_'.$i.'@x.com';
            $dummyStmt = $pdo->prepare("INSERT INTO users (name,email,password_hash,role,vip_level,referral_code,referred_by,status,email_verified)
                                      VALUES (:n,:e,:h,'earner',:v,:c,:r,'active',1)");
            $dummyStmt->execute([':n'=>'User'.rand(1000,9999),':e'=>$dummyEmail,':h'=>password_hash('x',PASSWORD_DEFAULT),':v'=>random_int(1,3),':c'=>'D'.rand(10000,99999),':r'=>$uid]);
            $did = $pdo->lastInsertId();
            
            $days = random_int(0,6);
            $refStmt = $pdo->prepare("INSERT INTO referrals (referrer_id,referred_id,vip_level,bonus_paid,bonus_amount,created_at)
                                    VALUES (:a,:b,:c,1,1.00,DATE_SUB(NOW(),INTERVAL :d DAY))");
            $refStmt->execute([':a'=>$uid,':b'=>$did,':c'=>random_int(1,3),':d'=>$days]);
            $totalRefs++;
        }
    }
    echo "   Created $totalRefs referrals\n";
    
    // 4. Show top 20
    echo "\n4. Top 20 leaderboard:\n";
    $top = $pdo->query("SELECT u.name, COUNT(r.id) AS refs, SUM(r.bonus_amount) AS earned
                       FROM users u JOIN referrals r ON r.referrer_id=u.id AND r.bonus_paid=1
                       WHERE u.role='earner' GROUP BY u.id ORDER BY earned DESC LIMIT 20")->fetchAll();
    foreach ($top as $i=>$row) echo "   #".($i+1).": ".$row['name']." - ".$row['refs']." refs, $".number_format((float)$row['earned'],2)."\n";
    
    echo "\n=== Done! ===\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage();
}
