<?php
header('Content-Type: text/plain');

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

echo "Connecting to MySQL...\n";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "Connected!\n\n";
    
    $pdo->exec("USE paynex");
    
    // Check if email_verified column exists
    $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_verified'")->fetch();
    
    if (!$check) {
        echo "Adding email_verified column...\n";
        $pdo->exec("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1");
        echo "Column added successfully!\n";
    } else {
        echo "email_verified column already exists.\n";
    }
    
    // Update all existing users to be verified
    $pdo->exec("UPDATE users SET email_verified = 1 WHERE email_verified = 0 OR email_verified IS NULL");
    echo "All users set to verified.\n\n";
    
    // Show table structure
    echo "Users table columns:\n";
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll();
    foreach ($cols as $col) {
        echo "  - " . $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
