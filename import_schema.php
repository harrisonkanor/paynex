<?php
/**
 * Improved schema import script for Railway deployment.
 */

header('Content-Type: text/plain');

// Get database credentials from Railway environment variables
$host = getenv('MYSQLHOST') ?: '127.0.0.1';
$port = getenv('MYSQLPORT') ?: '3306';
$dbname = getenv('MYSQLDATABASE') ?: 'railway';
$user = getenv('MYSQLUSER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: '';

echo "Connecting to MySQL at $host:$port as $user...\n";

try {
    // Connect to MySQL without selecting a database first
    $pdo = new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "Connected successfully!\n\n";
    
    // Create database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS paynex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database 'paynex' created/verified.\n\n";
    
    // Switch to the paynex database
    $pdo->exec("USE paynex");
    
    // Check if tables already exist
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Existing tables: " . implode(', ', $tables) . "\n\n";
    
    // Read and execute the schema file
    $schemaPath = __DIR__ . '/database/schema.sql';
    if (!file_exists($schemaPath)) {
        die("Schema file not found at: $schemaPath\n");
    }
    
    $schema = file_get_contents($schemaPath);
    echo "Schema file loaded (" . strlen($schema) . " bytes).\n\n";
    
    // Split by semicolons and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $schema)));
    
    $count = 0;
    $errors = 0;
    foreach ($statements as $stmt) {
        // Skip empty statements and comments
        if (empty($stmt) || preg_match('/^--/', $stmt)) {
            continue;
        }
        
        try {
            $pdo->exec($stmt);
            $count++;
        } catch (PDOException $e) {
            // Only show actual errors, not duplicate table warnings
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "Warning: " . $e->getMessage() . "\n";
                $errors++;
            }
        }
    }
    
    echo "\nImport complete!\n";
    echo "Statements executed: $count\n";
    echo "Errors: $errors\n\n";
    
    // Verify tables were created
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables in database: " . count($tables) . "\n";
    foreach ($tables as $table) {
        echo "  - $table\n";
    }
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
