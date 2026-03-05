<?php
session_start();
require_once 'db.php';

// This script checks for low stock and expiring items and creates notifications
// It should be called periodically or on dashboard load

try {
    // Get all pharmacists
    $pharmacist_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'pharmacist'");
    $pharmacist_stmt->execute();
    $pharmacists = $pharmacist_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($pharmacists)) {
        return; // No pharmacists to notify
    }
    
    // Check for low stock items
    $low_stock_stmt = $pdo->prepare("
        SELECT id, item_name, quantity, reorder_level
        FROM inventory
        WHERE quantity > 0 AND quantity <= reorder_level
    ");
    $low_stock_stmt->execute();
    $low_stock_items = $low_stock_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $notif_stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, message, type, status) 
        VALUES (?, ?, 'inventory_low', 'unread')
    ");
    
    $check_notif = $pdo->prepare("
        SELECT notification_id FROM notifications 
        WHERE user_id = ? AND message = ? AND status = 'unread' 
        AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        LIMIT 1
    ");
    
    foreach ($low_stock_items as $item) {
        $message = "Medicine Running Low — {$item['item_name']} ({$item['quantity']} remaining)";
        foreach ($pharmacists as $pharmacist_id) {
            // Check if notification already exists in last 24 hours
            $check_notif->execute([$pharmacist_id, $message]);
            if (!$check_notif->fetch()) {
                $notif_stmt->execute([$pharmacist_id, $message]);
            }
        }
    }
    
    // Check for expiring items (within 30 days)
    $expiring_stmt = $pdo->prepare("
        SELECT id, item_name, expiry_date
        FROM inventory
        WHERE expiry_date IS NOT NULL 
          AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          AND expiry_date >= CURDATE()
    ");
    $expiring_stmt->execute();
    $expiring_items = $expiring_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $expiring_notif_stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, message, type, status) 
        VALUES (?, ?, 'inventory_expiring', 'unread')
    ");
    
    $check_expiring_notif = $pdo->prepare("
        SELECT notification_id FROM notifications 
        WHERE user_id = ? AND message = ? AND status = 'unread' 
        AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        LIMIT 1
    ");
    
    foreach ($expiring_items as $item) {
        $expiry_formatted = date('M j, Y', strtotime($item['expiry_date']));
        $message = "Expiring Medicine — {$item['item_name']} ({$expiry_formatted})";
        foreach ($pharmacists as $pharmacist_id) {
            // Check if notification already exists in last 24 hours
            $check_expiring_notif->execute([$pharmacist_id, $message]);
            if (!$check_expiring_notif->fetch()) {
                $expiring_notif_stmt->execute([$pharmacist_id, $message]);
            }
        }
    }
    
} catch (PDOException $e) {
    // Silently fail - this is a background process
    error_log("Error checking inventory notifications: " . $e->getMessage());
}
?>

