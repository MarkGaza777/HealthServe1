<?php
/**
 * Migration script to add quantity column to prescription_items table
 * Run this once to update the database schema
 */

require_once 'db.php';

try {
    // Check if quantity column already exists
    $stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'quantity'");
    $columnExists = $stmt->rowCount() > 0;
    
    if (!$columnExists) {
        // Add quantity column
        $pdo->exec("
            ALTER TABLE prescription_items 
            ADD COLUMN quantity INT(11) DEFAULT 1 AFTER duration
        ");
        
        echo "Successfully added quantity column to prescription_items table.\n";
        
        // Update existing records to have quantity = 1 if they don't have it
        $pdo->exec("
            UPDATE prescription_items 
            SET quantity = 1 
            WHERE quantity IS NULL OR quantity = 0
        ");
        
        echo "Updated existing records with default quantity of 1.\n";
    } else {
        echo "Quantity column already exists in prescription_items table.\n";
    }
    
    echo "Migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>

