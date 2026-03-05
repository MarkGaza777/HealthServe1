<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Check if user is logged in and is an admin
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$doctor_id = $_GET['id'] ?? $_POST['id'] ?? null;

if (!$doctor_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Doctor ID is required']);
    exit;
}

try {
    // Get doctor's user_id before deletion
    $stmt = $pdo->prepare("SELECT user_id FROM doctors WHERE id = ?");
    $stmt->execute([$doctor_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doctor) {
        throw new Exception('Doctor not found');
    }
    
    $user_id = $doctor['user_id'];
    
    // Delete doctor record (this will cascade delete related records if foreign keys are set up)
    $stmt = $pdo->prepare("DELETE FROM doctors WHERE id = ?");
    $stmt->execute([$doctor_id]);
    
    // Optionally delete user account (uncomment if you want to delete the user account too)
    // $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    // $stmt->execute([$user_id]);
    
    echo json_encode(['success' => true, 'message' => 'Doctor deleted successfully!']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

