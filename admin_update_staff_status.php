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

$staff_id = $_POST['staff_id'] ?? null;
$role = $_POST['role'] ?? '';
$shift_status = $_POST['shift_status'] ?? '';

if (!$staff_id || !$role || !$shift_status) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Staff ID, role, and status are required']);
    exit;
}

// Validate shift_status
if (!in_array($shift_status, ['on_duty', 'off_duty'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit;
}

try {
    if ($role === 'doctor') {
        // Doctors don't have shift_status in their table, so we'll skip for now
        // If needed in the future, we can add a shift_status column to doctors table
        echo json_encode(['success' => true, 'message' => 'Status update not available for doctors']);
        exit;
    } else if (in_array($role, ['physician', 'nurse', 'midwife', 'bhw'])) {
        // Update staff from staff table
        $stmt = $pdo->prepare("
            UPDATE staff 
            SET shift_status = ?
            WHERE id = ? AND role = ?
        ");
        $stmt->execute([$shift_status, $staff_id, $role]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Staff member not found or role mismatch');
        }
    } else {
        // For users table staff (pharmacist, fdo, admin), we don't have shift_status column
        // So we'll skip for now or add the column if needed
        echo json_encode(['success' => true, 'message' => 'Status update not available for this staff type']);
        exit;
    }
    
    echo json_encode(['success' => true, 'message' => 'Staff status updated successfully!']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
