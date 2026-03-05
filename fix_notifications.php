<?php
/**
 * Script to fix duplicate notifications and add reference_id column
 * Run this once to clean up and update the database
 */
require 'db.php';

try {
    // Add reference_id column if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN reference_id INT NULL AFTER type");
        echo "Added reference_id column to notifications table.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            throw $e;
        }
        echo "reference_id column already exists.\n";
    }
    
    // Delete duplicate "today at 4:00 PM" notifications
    $stmt = $pdo->prepare("
        DELETE n1 FROM notifications n1
        INNER JOIN notifications n2 
        WHERE n1.notification_id > n2.notification_id
        AND n1.user_id = n2.user_id
        AND n1.type = n2.type
        AND n1.message = n2.message
        AND n1.message LIKE '%today at 4:00 PM%'
    ");
    $stmt->execute();
    $deleted = $stmt->rowCount();
    echo "Deleted {$deleted} duplicate 'today at 4:00 PM' notifications.\n";
    
    // Also delete any other exact duplicates
    $stmt = $pdo->prepare("
        DELETE n1 FROM notifications n1
        INNER JOIN notifications n2 
        WHERE n1.notification_id > n2.notification_id
        AND n1.user_id = n2.user_id
        AND n1.type = n2.type
        AND n1.message = n2.message
        AND DATE(n1.created_at) = DATE(n2.created_at)
    ");
    $stmt->execute();
    $deleted2 = $stmt->rowCount();
    echo "Deleted {$deleted2} other duplicate notifications.\n";
    
    echo "Done! Database updated successfully.\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

