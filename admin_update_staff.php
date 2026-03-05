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
$staff_name = $_POST['staff_name'] ?? '';
$contact = $_POST['contact'] ?? '';
$email = $_POST['email'] ?? '';
$address = $_POST['address'] ?? '';
$department = $_POST['department'] ?? '';

if (!$staff_id || !$role) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Staff ID and role are required']);
    exit;
}

try {
    // Parse staff name
    $nameParts = explode(' ', trim($staff_name));
    $first_name = $nameParts[0] ?? '';
    $last_name = $nameParts[count($nameParts) - 1] ?? '';
    $middle_name = '';
    if (count($nameParts) > 2) {
        $middle_name = implode(' ', array_slice($nameParts, 1, -1));
    }
    
    if ($role === 'doctor') {
        // For doctors, update through doctors table
        $stmt = $pdo->prepare("SELECT user_id FROM doctors WHERE id = ?");
        $stmt->execute([$staff_id]);
        $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$doctor) {
            throw new Exception('Doctor not found');
        }
        
        $user_id = $doctor['user_id'];
        
        // Update users table
        $stmt = $pdo->prepare("
            UPDATE users 
            SET first_name = ?, middle_name = ?, last_name = ?, contact_no = ?, email = ?, address = ?
            WHERE id = ?
        ");
        $stmt->execute([$first_name, $middle_name, $last_name, $contact, $email, $address, $user_id]);
    } else if (in_array($role, ['physician', 'nurse', 'midwife', 'bhw'])) {
        // For staff from staff table (physician, nurse, midwife, bhw), update staff table
        $stmt = $pdo->prepare("
            UPDATE staff 
            SET first_name = ?, middle_name = ?, last_name = ?, phone = ?, email = ?, department = ?
            WHERE id = ? AND role = ?
        ");
        $stmt->execute([$first_name, $middle_name, $last_name, $contact, $email, $department, $staff_id, $role]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Staff member not found or role mismatch');
        }
    } else {
        // For other staff (pharmacist, fdo, admin), update users table directly
        $stmt = $pdo->prepare("
            UPDATE users 
            SET first_name = ?, middle_name = ?, last_name = ?, contact_no = ?, email = ?, address = ?
            WHERE id = ? AND role = ?
        ");
        $stmt->execute([$first_name, $middle_name, $last_name, $contact, $email, $address, $staff_id, $role]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Staff member not found or role mismatch');
        }
    }
    
    echo json_encode(['success' => true, 'message' => 'Staff updated successfully!']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

