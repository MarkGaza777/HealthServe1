<?php
/**
 * Migration Script: Add patient_instructions field to appointments table
 * Run this file once to add the patient_instructions column to your database
 */

require_once 'db.php';

try {
    // Check if column already exists
    $stmt = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'patient_instructions'");
    if ($stmt->rowCount() > 0) {
        echo "✓ Column 'patient_instructions' already exists in appointments table.\n";
        exit;
    }
    
    // Add the column
    $pdo->exec("ALTER TABLE appointments 
                ADD COLUMN patient_instructions TEXT NULL DEFAULT NULL 
                AFTER diagnosis");
    
    echo "✓ Successfully added 'patient_instructions' column to appointments table.\n";
    
} catch (PDOException $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

