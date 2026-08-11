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
    if (count($tables) > 0) {
        echo "Tables already exist (" . count($tables) . "). Skipping import.\n";
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
