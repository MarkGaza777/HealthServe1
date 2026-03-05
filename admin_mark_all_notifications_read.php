<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$admin_id = $_SESSION['user']['id'];

try {
    // Check if type column exists
    $checkType = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'type'");
    $hasType = $checkType->rowCount() > 0;
    
    if ($hasType) {
        // Mark all announcement notifications as read
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET status = 'read' 
            WHERE user_id = ? 
            AND (type = 'announcement' OR type IS NULL AND message LIKE '%announcement%')
            AND status = 'unread'
        ");
    } else {
        // If type column doesn't exist, filter by message content
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET status = 'read' 
            WHERE user_id = ? 
            AND (message LIKE '%announcement%' OR message LIKE '%approved%' OR message LIKE '%posted%')
            AND status = 'unread'
        ");
    }
    
    $stmt->execute([$admin_id]);
    
    echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

