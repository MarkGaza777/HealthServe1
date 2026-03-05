<?php
/**
 * Script to delete all duplicate "today at 4:00 PM" appointment notifications
 * Run this by visiting: http://your-domain/healthyc/delete_duplicate_notifications.php
 */
require 'db.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $total_deleted = 0;
    
    // Delete ALL notifications with "today at 4:00 PM" message (not just duplicates)
    $stmt = $pdo->prepare("
        DELETE FROM notifications 
        WHERE type = 'appointment' 
        AND message LIKE '%today at 4:00 PM%'
    ");
    $stmt->execute();
    $deleted = $stmt->rowCount();
    $total_deleted += $deleted;
    
    echo "<h2>Cleanup Complete</h2>";
    echo "<p>Deleted <strong>{$deleted}</strong> 'today at 4:00 PM' appointment notifications.</p>";
    
    // Also delete any other exact duplicates (same user, same type, same message, same day)
    $stmt2 = $pdo->prepare("
        DELETE n1 FROM notifications n1
        INNER JOIN notifications n2 
        WHERE n1.notification_id > n2.notification_id
        AND n1.user_id = n2.user_id
        AND n1.type = n2.type
        AND n1.message = n2.message
        AND DATE(n1.created_at) = DATE(n2.created_at)
    ");
    $stmt2->execute();
    $deleted2 = $stmt2->rowCount();
    $total_deleted += $deleted2;
    
    if ($deleted2 > 0) {
        echo "<p>Also deleted <strong>{$deleted2}</strong> other duplicate notifications.</p>";
    }
    
    echo "<p><strong style='color: green;'>Total deleted: {$total_deleted} notifications</strong></p>";
    echo "<p style='margin-top: 20px;'><a href='user_main_dashboard.php' style='padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px;'>Go back to dashboard</a></p>";
    
} catch (PDOException $e) {
    echo "<h2>Error</h2>";
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

