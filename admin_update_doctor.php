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

$doctor_id = $_POST['doctor_id'] ?? null;
$doctor_name = $_POST['doctor_name'] ?? '';
$specialization = $_POST['specialization'] ?? '';
$clinic_room = $_POST['clinic_room'] ?? '';
$contact = $_POST['contact'] ?? '';
$email = $_POST['email'] ?? '';
$address = $_POST['address'] ?? '';

if (!$doctor_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Doctor ID is required']);
    exit;
}

try {
    // Get doctor's user_id
    $stmt = $pdo->prepare("SELECT user_id FROM doctors WHERE id = ?");
    $stmt->execute([$doctor_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doctor) {
        throw new Exception('Doctor not found');
    }
    
    $user_id = $doctor['user_id'];
    
    // Parse doctor name
    $nameParts = explode(' ', trim($doctor_name));
    $first_name = $nameParts[0] ?? '';
    $last_name = $nameParts[count($nameParts) - 1] ?? '';
    $middle_name = '';
    if (count($nameParts) > 2) {
        $middle_name = implode(' ', array_slice($nameParts, 1, -1));
    }
    
    // Update users table
    $stmt = $pdo->prepare("
        UPDATE users 
        SET first_name = ?, middle_name = ?, last_name = ?, contact_no = ?, email = ?, address = ?
        WHERE id = ?
    ");
    $stmt->execute([$first_name, $middle_name, $last_name, $contact, $email, $address, $user_id]);
    
    // Update doctors table
    $stmt = $pdo->prepare("
        UPDATE doctors 
        SET specialization = ?, clinic_room = ?
        WHERE id = ?
    ");
    $stmt->execute([$specialization, $clinic_room, $doctor_id]);
    
    echo json_encode(['success' => true, 'message' => 'Doctor updated successfully!']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

