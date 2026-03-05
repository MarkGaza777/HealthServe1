<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Check if user is logged in
if (empty($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Please log in']);
    exit;
}

$user_id = $_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'];

// Only allow admin, doctor, and pharmacist to delete announcements
if (!in_array($user_role, ['admin', 'doctor', 'pharmacist'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - You do not have permission to delete announcements']);
    exit;
}

// Get announcement ID from request
$data = json_decode(file_get_contents('php://input'), true);
$announcement_id = isset($data['announcement_id']) ? intval($data['announcement_id']) : 0;

if ($announcement_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid announcement ID']);
    exit;
}

try {
    // First, get the announcement details to check ownership and get image path
    $stmt = $pdo->prepare("
        SELECT announcement_id, posted_by, image_path 
        FROM announcements 
        WHERE announcement_id = ?
    ");
    $stmt->execute([$announcement_id]);
    $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$announcement) {
        echo json_encode(['success' => false, 'message' => 'Announcement not found']);
        exit;
    }
    
    // Check if user owns the announcement (or is admin who can delete any)
    if ($user_role !== 'admin' && $announcement['posted_by'] != $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized - You can only delete your own announcements']);
        exit;
    }
    
    // Delete the image file if it exists
    if (!empty($announcement['image_path']) && file_exists($announcement['image_path'])) {
        @unlink($announcement['image_path']);
    }
    
    // Delete the announcement from database
    $delete_stmt = $pdo->prepare("DELETE FROM announcements WHERE announcement_id = ?");
    $delete_stmt->execute([$announcement_id]);
    
    echo json_encode(['success' => true, 'message' => 'Announcement deleted successfully']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error deleting announcement: ' . $e->getMessage()]);
}
?>
