<?php
header('Content-Type: text/plain');

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

echo "=== Seeding Database ===\n\n";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=paynex;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "Connected!\n\n";
    
    // 1. Create leaderboard_cycles table if not exists
    echo "1. Creating leaderboard_cycles table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS leaderboard_cycles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        week_start DATE NOT NULL,
        week_end DATE NOT NULL,
        status ENUM('active','completed') NOT NULL DEFAULT 'active',
        total_prize_pool DECIMAL(10,2) NOT NULL DEFAULT 100.00,
        prize_per_person DECIMAL(10,2) NOT NULL DEFAULT 10.00,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "   Done!\n\n";
    
    // 2. Create leaderboard_payouts table if not exists
    echo "2. Creating leaderboard_payouts table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS leaderboard_payouts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        cycle_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        rank_position TINYINT NOT NULL,
        referral_count INT NOT NULL DEFAULT 0,
        prize_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        paid_at DATETIME NULL,
        CONSTRAINT fk_lp_cycle FOREIGN KEY (cycle_id) REFERENCES leaderboard_cycles(id) ON DELETE CASCADE,
        CONSTRAINT fk_lp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "   Done!\n\n";
    
    // 3. Create active leaderboard cycle for current week
    echo "3. Creating active leaderboard cycle...\n";
    $check = $pdo->query("SELECT id FROM leaderboard_cycles WHERE status = 'active' LIMIT 1")->fetch();
    if (!$check) {
        $pdo->exec("INSERT INTO leaderboard_cycles (week_start, week_end, status, total_prize_pool, prize_per_person)
                    VALUES (
                        DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY),
                        DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 6 DAY),
                        'active',
                        100.00,
                        10.00
                    )");
        echo "   Active cycle created!\n\n";
    } else {
        echo "   Active cycle already exists.\n\n";
    }
    
    // 4. Add realistic survey tasks
    echo "4. Adding survey tasks...\n";
    $adminId = 1; // Admin user ID
    
    $tasks = [
        // VIP 1 Tasks
        ['title' => 'Consumer Shopping Habits Survey', 'description' => 'Answer 15 questions about your shopping preferences and habits. Takes about 5 minutes.', 'type' => 'survey', 'vip_level' => 1, 'reward' => 0.20, 'ticket_price' => 0.00, 'slots' => 200, 'time_limit' => 60],
        ['title' => 'Social Media Usage Research', 'description' => 'Share how you use social media platforms daily. Quick 10-question survey.', 'type' => 'survey', 'vip_level' => 1, 'reward' => 0.20, 'ticket_price' => 0.00, 'slots' => 150, 'time_limit' => 45],
        ['title' => 'Food Delivery Preferences', 'description' => 'Tell us about your food delivery app usage and preferences.', 'type' => 'survey', 'vip_level' => 1, 'reward' => 0.20, 'ticket_price' => 0.00, 'slots' => 180, 'time_limit' => 50],
        ['title' => 'Fitness App User Feedback', 'description' => 'Share your experience with fitness tracking apps. 12 questions.', 'type' => 'survey', 'vip_level' => 1, 'reward' => 0.20, 'ticket_price' => 0.00, 'slots' => 120, 'time_limit' => 40],
        ['title' => 'Entertainment Streaming Survey', 'description' => 'How do you consume streaming content? Answer 10 quick questions.', 'type' => 'survey', 'vip_level' => 1, 'reward' => 0.20, 'ticket_price' => 0.00, 'slots' => 200, 'time_limit' => 45],
        
        // VIP 2 Tasks
        ['title' => 'Tech gadgets Purchase Intent', 'description' => 'Share your thoughts on upcoming tech purchases. 20 questions, 10 minutes.', 'type' => 'survey', 'vip_level' => 2, 'reward' => 0.50, 'ticket_price' => 0.00, 'slots' => 100, 'time_limit' => 75],
        ['title' => 'Travel Planning Research', 'description' => 'Tell us about your travel planning process and preferences.', 'type' => 'survey', 'vip_level' => 2, 'reward' => 0.50, 'ticket_price' => 0.00, 'slots' => 80, 'time_limit' => 60],
        ['title' => 'Home Insurance Feedback', 'description' => 'Share your experience with home insurance providers. 15 questions.', 'type' => 'survey', 'vip_level' => 2, 'reward' => 0.50, 'ticket_price' => 0.00, 'slots' => 90, 'time_limit' => 55],
        ['title' => 'Online Learning Platform Review', 'description' => 'Rate your online learning experiences. 18 questions.', 'type' => 'survey', 'vip_level' => 2, 'reward' => 0.50, 'ticket_price' => 0.00, 'slots' => 70, 'time_limit' => 50],
        ['title' => 'Banking App Usability Study', 'description' => 'Help us improve banking app interfaces. 20 questions.', 'type' => 'survey', 'vip_level' => 2, 'reward' => 0.50, 'ticket_price' => 0.00, 'slots' => 60, 'time_limit' => 60],
        
        // VIP 3 Tasks
        ['title' => 'Smart Home Device Survey', 'description' => 'Comprehensive survey on smart home adoption. 25 questions, 15 minutes.', 'type' => 'survey', 'vip_level' => 3, 'reward' => 1.00, 'ticket_price' => 0.00, 'slots' => 50, 'time_limit' => 90],
        ['title' => 'Electric Vehicle Interest Study', 'description' => 'Share your views on electric vehicles. In-depth 30-question survey.', 'type' => 'survey', 'vip_level' => 3, 'reward' => 1.00, 'ticket_price' => 0.00, 'slots' => 40, 'time_limit' => 100],
        ['title' => 'Cryptocurrency Adoption Research', 'description' => 'Tell us about your crypto experience. Advanced 25-question survey.', 'type' => 'survey', 'vip_level' => 3, 'reward' => 1.00, 'ticket_price' => 0.00, 'slots' => 45, 'time_limit' => 80],
        ['title' => 'Remote Work Productivity Survey', 'description' => 'Share insights on remote work tools and productivity. 20 questions.', 'type' => 'survey', 'vip_level' => 3, 'reward' => 1.00, 'ticket_price' => 0.00, 'slots' => 55, 'time_limit' => 70],
        ['title' => 'Sustainable Products Feedback', 'description' => 'Help shape eco-friendly product development. 22 questions.', 'type' => 'survey', 'vip_level' => 3, 'reward' => 1.00, 'ticket_price' => 0.00, 'slots' => 50, 'time_limit' => 75],
        
        // Spin Wheel Tasks (all VIP levels)
        ['title' => 'Daily Spin the Wheel', 'description' => 'Spin the wheel for a chance to win bonus rewards! Available once per day.', 'type' => 'spin_wheel', 'vip_level' => 1, 'reward' => 0.00, 'ticket_price' => 0.00, 'slots' => 1000, 'time_limit' => 5],
        ['title' => 'Daily Spin the Wheel', 'description' => 'Spin the wheel for a chance to win bonus rewards! Available once per day.', 'type' => 'spin_wheel', 'vip_level' => 2, 'reward' => 0.00, 'ticket_price' => 0.00, 'slots' => 1000, 'time_limit' => 5],
        ['title' => 'Daily Spin the Wheel', 'description' => 'Spin the wheel for a chance to win bonus rewards! Available once per day.', 'type' => 'spin_wheel', 'vip_level' => 3, 'reward' => 0.00, 'ticket_price' => 0.00, 'slots' => 1000, 'time_limit' => 5],
    ];
    
    $inserted = 0;
    foreach ($tasks as $task) {
        $check = $pdo->prepare("SELECT id FROM tasks WHERE title = :title AND vip_level = :lv LIMIT 1");
        $check->execute([':title' => $task['title'], ':lv' => $task['vip_level']]);
        if (!$check->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO tasks (admin_id, title, description, type, vip_level, reward, ticket_price, slots, time_limit_minutes, available_from, available_until, status)
                                  VALUES (:admin, :title, :desc, :type, :vip, :reward, :ticket, :slots, :time, '09:00:00', '17:00:00', 'open')");
            $stmt->execute([
                ':admin' => $adminId,
                ':title' => $task['title'],
                ':desc' => $task['description'],
                ':type' => $task['type'],
                ':vip' => $task['vip_level'],
                ':reward' => $task['reward'],
                ':ticket' => $task['ticket_price'],
                ':slots' => $task['slots'],
                ':time' => $task['time_limit'],
            ]);
            $inserted++;
        }
    }
    echo "   Inserted $inserted new tasks!\n\n";
    
    // 5. Verify
    echo "5. Verification:\n";
    $taskCount = $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
    echo "   Total tasks: $taskCount\n";
    $cycleCount = $pdo->query("SELECT COUNT(*) FROM leaderboard_cycles")->fetchColumn();
    echo "   Total leaderboard cycles: $cycleCount\n";
    
    echo "\n=== Seeding Complete! ===\n";
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
