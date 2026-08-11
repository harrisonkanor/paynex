<?php
/**
 * One-time schema import script for Railway deployment.
 * Run this once after first deployment to set up the database.
 */

// Get database credentials from Railway environment variables
$host = getenv('MYSQLHOST') ?: '127.0.0.1';
$port = getenv('MYSQLPORT') ?: '3306';
$dbname = getenv('MYSQLDATABASE') ?: 'railway';
$user = getenv('MYSQLUSER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: '';

try {
    // Connect to MySQL without selecting a database first
    $pdo = new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "Connected to MySQL server.\n";
    
    // Create database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS paynex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database 'paynex' created/verified.\n";
    
    // Switch to the paynex database
    $pdo->exec("USE paynex");
    
    // Read and execute the schema file
    $schema = file_get_contents(__DIR__ . '/database/schema.sql');
    
    // Split by semicolons and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $schema)));
    
    $count = 0;
    foreach ($statements as $stmt) {
        if (!empty($stmt) && !preg_match('/^--/', $stmt)) {
            try {
                $pdo->exec($stmt);
                $count++;
            } catch (PDOException $e) {
                echo "Warning: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "Imported $count statements successfully.\n";
    echo "Schema import complete!\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
