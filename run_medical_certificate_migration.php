<?php
/**
 * Migration Script: Add medical_certificates table
 * Run this file once to create the medical_certificates table in your database
 */

require_once 'db.php';

try {
    // Check if table already exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'medical_certificates'");
    if ($stmt->rowCount() > 0) {
        echo "✓ Table 'medical_certificates' already exists.\n";
        exit;
    }
    
    // Read and execute migration SQL
    $migration_file = __DIR__ . '/migrations/add_medical_certificates.sql';
    if (!file_exists($migration_file)) {
        echo "✗ Migration file not found: $migration_file\n";
        exit(1);
    }
    
    $sql = file_get_contents($migration_file);
    
    // Remove comments and execute
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
        }
    }
    
    echo "✓ Successfully created 'medical_certificates' table.\n";
    echo "✓ Medical certificate feature is now ready to use.\n";
    
} catch (PDOException $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

