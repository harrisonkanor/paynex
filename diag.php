<?php
header('Content-Type: text/plain');

echo "=== DIAGNOSTIC ===\n";
echo "__DIR__: " . __DIR__ . "\n";
echo "Schema file: " . __DIR__ . '/database/schema.sql' . "\n";
echo "File exists: " . (file_exists(__DIR__ . '/database/schema.sql') ? 'YES' : 'NO') . "\n";
echo "File size: " . filesize(__DIR__ . '/database/schema.sql') . " bytes\n\n";

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$dbname = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

echo "MYSQLHOST: $host\n";
echo "MYSQLPORT: $port\n";
echo "MYSQLDATABASE: $dbname\n";
echo "MYSQLUSER: $user\n\n";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "Connected to MySQL!\n\n";
    
    $pdo->exec("CREATE DATABASE IF NOT EXISTS paynex CHARACTER SET utf8mb4");
    $pdo->exec("USE paynex");
    
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Current tables (" . count($tables) . "):\n";
    foreach ($tables as $t) echo "  - $t\n";
    echo "\n";
    
    if (count($tables) === 0) {
        echo "No tables found. Running schema import...\n\n";
        $schema = file_get_contents(__DIR__ . '/database/schema.sql');
        $statements = array_filter(array_map('trim', explode(';', $schema)));
        $count = 0;
        $errors = 0;
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if (empty($stmt) || preg_match('/^--/', $stmt)) continue;
            // Skip multi-line comments
            if (strpos($stmt, '/*') !== false && strpos($stmt, '*/') !== false) continue;
            try {
                $pdo->exec($stmt);
                $count++;
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') === false && strpos($e->getMessage(), 'Duplicate') === false) {
                    echo "ERROR: " . substr($e->getMessage(), 0, 200) . "\n";
                    echo "STATEMENT: " . substr($stmt, 0, 200) . "\n\n";
                    $errors++;
                } else {
                    $count++;
                }
            }
        }
        echo "Executed: $count, Errors: $errors\n\n";
        
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "Tables after import (" . count($tables) . "):\n";
        foreach ($tables as $t) echo "  - $t\n";
    }
    
} catch (PDOException $e) {
    echo "DB ERROR: " . $e->getMessage() . "\n";
}
