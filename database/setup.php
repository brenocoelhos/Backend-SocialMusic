<?php
require_once __DIR__ . '/../config/database.php';

function executeSQLFile($pdo, $file) {
    try {
        // Read SQL file
        $sql = file_get_contents($file);
        
        // Split into statements
        $statements = array_filter(
            array_map('trim', 
                explode(';', $sql)
            )
        );
        
        // Begin transaction
        $pdo->beginTransaction();
        
        foreach($statements as $statement) {
            if (!empty($statement)) {
                $pdo->exec($statement);
                echo "Executed: " . substr($statement, 0, 50) . "...\n";
            }
        }
        
        // Commit transaction
        $pdo->commit();
        echo "\nDatabase setup completed successfully!\n";
        
    } catch (PDOException $e) {
        // Rollback transaction on error
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Exception $rollbackException) {
            // Ignore rollback errors if transaction wasn't started
        }
        echo "Error setting up database: " . $e->getMessage() . "\n";
        exit(1);
    }
}

try {
    // First connect without database name to create it if needed
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
        ]
    );
    
    // Create database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database '" . DB_NAME . "' created or already exists.\n";
    
    // Select the database
    $pdo->exec("USE " . DB_NAME);
    
    // Execute the schema file
    executeSQLFile($pdo, __DIR__ . '/schema.sql');
    
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
    exit(1);
}