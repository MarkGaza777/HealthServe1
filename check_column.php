<?php
/**
 * Quick script to check if patient_instructions column exists
 */

require_once 'db.php';

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'patient_instructions'");
    if ($stmt->rowCount() > 0) {
        echo "✓ Column 'patient_instructions' EXISTS in appointments table.\n";
        echo "The migration was successful - you can ignore the duplicate column error.\n";
    } else {
        echo "✗ Column 'patient_instructions' does NOT exist.\n";
        echo "You need to run the migration.\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

