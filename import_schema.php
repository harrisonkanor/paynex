<?php
header('Content-Type: text/plain');

echo "=== Schema Import ===\n\n";

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

echo "Connecting to MySQL at $host:$port...\n";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "Connected!\n\n";
    
    $pdo->exec("CREATE DATABASE IF NOT EXISTS paynex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database 'paynex' ready.\n\n";
    
    $pdo->exec("USE paynex");
    
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    // Run migrations for existing databases
    if (count($tables) > 0) {
        echo "Tables already exist (" . count($tables) . "). Running migrations...\n";
        
        // Check and add missing columns to users table
        if (in_array('users', $tables)) {
            $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            
            $userMigrations = [
                ['email_verified', 'ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_photo'],
                ['verification_code', 'ALTER TABLE users ADD COLUMN verification_code VARCHAR(6) NULL AFTER email_verified'],
                ['total_referrals', 'ALTER TABLE users ADD COLUMN total_referrals INT UNSIGNED NOT NULL DEFAULT 0 AFTER verification_code'],
            ];
            
            foreach ($userMigrations as [$col, $sql]) {
                if (!in_array($col, $columns)) {
                    echo "  Adding $col column to users...";
                    try { $pdo->exec($sql); echo " Done\n"; } catch (PDOException $e) { echo " (" . $e->getMessage() . ")\n"; }
                }
            }
        }
        
        // Check and add missing columns to task_submissions table
        if (in_array('task_submissions', $tables)) {
            $subColumns = $pdo->query("SHOW COLUMNS FROM task_submissions")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('screenshot_path', $subColumns)) {
                echo "  Adding screenshot_path to task_submissions...";
                try { $pdo->exec("ALTER TABLE task_submissions ADD COLUMN screenshot_path VARCHAR(255) NULL AFTER spin_result"); echo " Done\n"; } catch (PDOException $e) { echo " (" . $e->getMessage() . ")\n"; }
            }
        }
        
        // Sync wallet_balance from wallet_transactions (excluding deposits)
        // Deposits are for VIP activation only, not available balance
        if (in_array('wallet_transactions', $tables) && in_array('users', $tables)) {
            echo "\nSyncing wallet balances (excluding deposits)...\n";
            $syncSql = "UPDATE users u SET wallet_balance = (
                SELECT COALESCE(SUM(CASE WHEN wt.type = 'credit' THEN wt.amount ELSE -wt.amount END), 0)
                FROM wallet_transactions wt WHERE wt.user_id = u.id AND wt.type != 'deposit'
            ) WHERE u.id IN (
                SELECT wt2.user_id FROM wallet_transactions wt2 GROUP BY wt2.user_id
                HAVING ABS(SUM(CASE WHEN wt2.type = 'credit' AND wt2.type != 'deposit' THEN wt2.amount ELSE -wt2.amount END) - (
                    SELECT wallet_balance FROM users WHERE id = wt2.user_id
                )) > 0.001
            )";
            try {
                $pdo->exec($syncSql);
                $affected = $pdo->rowCount();
                echo "  Synced $affected user(s) wallet balances.\n";
            } catch (PDOException $e) {
                echo "  Sync skipped: " . $e->getMessage() . "\n";
            }
        }
        
        echo "\nMigrations complete.\n";
        foreach ($tables as $t) echo "  - $t\n";
        exit(0);
    }
    
    $schemaPath = __DIR__ . '/database/schema.sql';
    if (!file_exists($schemaPath)) {
        die("ERROR: Schema file not found!\n");
    }
    
    $schema = file_get_contents($schemaPath);
    echo "Schema loaded (" . strlen($schema) . " bytes).\n\n";
    
    // Remove multi-line comments /* ... */
    $schema = preg_replace('#/\*.*?\*/#s', '', $schema);
    // Remove single-line comments -- ...
    $schema = preg_replace('#--[^
]*#', '', $schema);
    // Remove empty lines
    $schema = preg_replace('#\n\s*\n#', "\n", $schema);
    
    // Split by semicolons
    $statements = explode(';', $schema);
    
    $count = 0;
    $errors = 0;
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (empty($stmt)) continue;
        
        try {
            $pdo->exec($stmt);
            $count++;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'already exists') !== false || strpos($msg, 'Duplicate') !== false) {
                $count++;
            } else {
                echo "ERROR: " . substr($msg, 0, 200) . "\n";
                echo "  In: " . substr($stmt, 0, 150) . "\n\n";
                $errors++;
            }
        }
    }
    
    echo "Statements executed: $count\n";
    echo "Errors: $errors\n\n";
    
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables created: " . count($tables) . "\n";
    foreach ($tables as $t) echo "  - $t\n";
    
} catch (PDOException $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
}
