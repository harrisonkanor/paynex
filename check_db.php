<?php
header('Content-Type: text/plain');

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

echo "=== Database Check ===\n\n";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=paynex;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "Connected!\n\n";
    
    // Check tables
    echo "Tables:\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) echo "  - $t\n";
    
    // Check if leaderboard_cycles exists
    echo "\n--- Leaderboard Cycles ---\n";
    if (in_array('leaderboard_cycles', $tables)) {
        $cycles = $pdo->query("SELECT * FROM leaderboard_cycles ORDER BY id DESC LIMIT 5")->fetchAll();
        if ($cycles) {
            foreach ($cycles as $c) echo "  ID: " . $c['id'] . ", Status: " . $c['status'] . ", Start: " . $c['week_start'] . ", End: " . $c['week_end'] . "\n";
        } else {
            echo "  No cycles found\n";
        }
    } else {
        echo "  Table does not exist!\n";
    }
    
    // Check tasks
    echo "\n--- Tasks ---\n";
    $tasks = $pdo->query("SELECT id, title, type, vip_level, status, reward FROM tasks ORDER BY id DESC LIMIT 10")->fetchAll();
    if ($tasks) {
        foreach ($tasks as $t) echo "  ID: " . $t['id'] . ", Title: " . $t['title'] . ", Type: " . $t['type'] . ", VIP: " . $t['vip_level'] . ", Status: " . $t['status'] . ", Reward: $" . $t['reward'] . "\n";
    } else {
        echo "  No tasks found!\n";
    }
    
    // Check users
    echo "\n--- Users ---\n";
    $users = $pdo->query("SELECT id, name, email, role, vip_level FROM users LIMIT 10")->fetchAll();
    if ($users) {
        foreach ($users as $u) echo "  ID: " . $u['id'] . ", Name: " . $u['name'] . ", Role: " . $u['role'] . ", VIP: " . ($u['vip_level'] ?: 'none') . "\n";
    }
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
