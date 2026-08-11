<?php
header('Content-Type: text/plain');

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

echo "=== Setting Up Leaderboard ===\n\n";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=paynex;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "Connected!\n\n";
    
    // 1. Update leaderboard cycle prize pool (20 winners x $50 = $1000)
    echo "1. Updating leaderboard cycle prize pool...\n";
    $pdo->exec("UPDATE leaderboard_cycles SET 
                total_prize_pool = 1000.00, 
                prize_per_person = 50.00 
                WHERE status = 'active'");
    echo "   Prize pool updated: $1000 total, $50 per winner (top 20)\n\n";
    
    // 2. Create fake users with realistic names
    echo "2. Creating fake users...\n";
    $fakeNames = [
        'Emma Thompson', 'James Wilson', 'Olivia Martinez', 'Liam Anderson',
        'Sophia Garcia', 'Noah Robinson', 'Isabella Clark', 'Mason Lewis',
        'Mia Walker', 'Ethan Hall', 'Charlotte Allen', 'Alexander Young',
        'Amelia King', 'Daniel Wright', 'Harper Scott', 'Matthew Green',
        'Evelyn Adams', 'Sebastian Baker', 'Abigail Nelson', 'Jack Hill',
        'Emily Campbell', 'Henry Mitchell', 'Elizabeth Roberts', 'Aiden Carter',
        'Sofia Phillips', 'Owen Evans', 'Ella Turner', 'Lucas Parker',
        'Chloe Collins', 'Benjamin Edwards', 'Grace Stewart', 'Leo Sanchez',
        'Victoria Morris', 'Mason Rogers', 'Riley Reed', 'Nathan Cook',
        'Zoey Morgan', 'Ryan Bell', 'Lily Murphy', 'Dylan Bailey',
        'Aria Rivera', 'Caleb Cooper', 'Penelope Richardson', 'Hunter Cox',
        'Layla Howard', 'Isaac Ward', 'Nora Torres', 'Joshua Peterson',
        'Hazel Gray', 'Andrew Ramirez'
    ];
    
    $createdUsers = [];
    foreach ($fakeNames as $i => $name) {
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
        
        // Check if user exists
        $check = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $check->execute([':email' => $email]);
        if ($check->fetch()) {
            $createdUsers[] = $pdo->lastInsertId();
            continue;
        }
        
        // Generate referral code
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $refCode = '';
        for ($j = 0; $j < 8; $j++) {
            $refCode .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        // Create user with random VIP level
        $vipLevel = random_int(1, 3);
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, vip_level, referral_code, status, email_verified)
                              VALUES (:name, :email, :hash, 'earner', :vip, :refcode, 'active', 1)");
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':hash' => $hash,
            ':vip' => $vipLevel,
            ':refcode' => $refCode,
        ]);
        $createdUsers[] = $pdo->lastInsertId();
    }
    echo "   Created " . count($createdUsers) . " fake users\n\n";
    
    // 3. Create fake referrals with random counts (11-29)
    echo "3. Creating fake referrals...\n";
    $totalReferrals = 0;
    $currentCycle = $pdo->query("SELECT * FROM leaderboard_cycles WHERE status = 'active' LIMIT 1")->fetch();
    
    foreach ($createdUsers as $userId) {
        // Random referral count between 11-29
        $refCount = random_int(11, 29);
        
        // Check existing referrals
        $existing = $pdo->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_id = :uid AND bonus_paid = 1");
        $existing->execute([':uid' => $userId]);
        $existingCount = (int) $existing->fetchColumn();
        
        $toCreate = $refCount - $existingCount;
        if ($toCreate <= 0) continue;
        
        // Create dummy users to refer
        for ($i = 0; $i < $toCreate; $i++) {
            $dummyEmail = 'referred_' . $userId . '_' . $i . '@example.com';
            
            // Check if dummy user exists
            $check = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $check->execute([':email' => $dummyEmail]);
            if ($check->fetch()) continue;
            
            // Create dummy user
            $dummyRefCode = 'DUMMY' . strtoupper(bin2hex(random_bytes(3)));
            $dummyStmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, vip_level, referral_code, referred_by, status, email_verified)
                                      VALUES (:name, :email, :hash, 'earner', :vip, :refcode, :referred_by, 'active', 1)");
            $dummyStmt->execute([
                ':name' => 'User ' . substr(md5($dummyEmail), 0, 8),
                ':email' => $dummyEmail,
                ':hash' => password_hash('dummy123', PASSWORD_DEFAULT),
                ':vip' => random_int(1, 3),
                ':refcode' => $dummyRefCode,
                ':referred_by' => $userId,
            ]);
            $dummyUserId = $pdo->lastInsertId();
            
            // Create referral record
            $bonusAmount = 1.00; // $1 bonus per referral
            $refStmt = $pdo->prepare("INSERT INTO referrals (referrer_id, referred_id, vip_level, bonus_paid, bonus_amount, created_at)
                                    VALUES (:referrer, :referred, :vip, 1, :bonus, DATE_SUB(NOW(), INTERVAL :days DAY))");
            $refStmt->execute([
                ':referrer' => $userId,
                ':referred' => $dummyUserId,
                ':vip' => random_int(1, 3),
                ':bonus' => $bonusAmount,
                ':days' => random_int(0, 6), // Random day this week
            ]);
            $totalReferrals++;
        }
    }
    echo "   Created $totalReferrals fake referrals\n\n";
    
    // 4. Update leaderboard to reflect new data
    echo "4. Verifying leaderboard data...\n";
    $stmt = $pdo->prepare(
        "SELECT u.id, u.name, COUNT(r.id) AS referral_count, COALESCE(SUM(r.bonus_amount), 0) AS bonus_earned
         FROM users u
         JOIN referrals r ON r.referrer_id = u.id AND r.bonus_paid = 1
         WHERE u.role = 'earner'
         GROUP BY u.id
         ORDER BY bonus_earned DESC
         LIMIT 25"
    );
    $stmt->execute();
    $top25 = $stmt->fetchAll();
    
    echo "   Top 25 users by bonus earned:\n";
    foreach ($top25 as $i => $row) {
        $rank = $i + 1;
        $prize = $rank <= 20 ? '$50' : '-';
        echo "   #$rank: " . $row['name'] . " - " . $row['referral_count'] . " refs, $" . number_format((float)$row['bonus_earned'], 2) . " earned $prize\n";
    }
    
    echo "\n=== Leaderboard Setup Complete! ===\n";
    echo "\nPrize Structure:\n";
    echo "- Top 20 referrers each win $50\n";
    echo "- Total prize pool: $1000\n";
    echo "- Leaderboard resets automatically every Monday\n";
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
