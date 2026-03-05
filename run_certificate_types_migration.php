<?php
/**
 * Migration Script: Add Certificate Types and Fit Status
 * Run this file once to add certificate_type, certificate_subtype, and fit_status columns
 */

require_once 'db.php';

try {
    // Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'medical_certificates'");
    if ($stmt->rowCount() === 0) {
        echo "✗ Table 'medical_certificates' does not exist. Please run the main migration first.\n";
        exit(1);
    }
    
    // Check if columns already exist
    $stmt = $pdo->query("SHOW COLUMNS FROM medical_certificates LIKE 'certificate_type'");
    if ($stmt->rowCount() > 0) {
        echo "✓ Certificate type columns already exist.\n";
        exit;
    }
    
    // Read and execute migration SQL
    $migration_file = __DIR__ . '/migrations/add_certificate_types.sql';
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
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                // Ignore if column already exists
                if (strpos($e->getMessage(), 'Duplicate column name') === false) {
                    throw $e;
                }
            }
        }
    }
    
    echo "✓ Successfully added certificate type columns to 'medical_certificates' table.\n";
    echo "✓ Certificate types feature is now ready to use.\n";
    
} catch (PDOException $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

