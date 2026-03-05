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
$data = json_decode(file_get_contents('php://input'), true);
$notification_id = isset($data['notification_id']) ? intval($data['notification_id']) : 0;

if ($notification_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE notifications 
        SET status = 'read' 
        WHERE notification_id = ? AND user_id = ?
    ");
    $stmt->execute([$notification_id, $admin_id]);
    
    echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

