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

$first_name = $_POST['first_name'] ?? '';
$middle_name = $_POST['middle_name'] ?? '';
$last_name = $_POST['last_name'] ?? '';
$specialization = $_POST['specialization'] ?? '';
$clinic_room = $_POST['clinic_room'] ?? '';
$contact = $_POST['contact'] ?? '';
$email = $_POST['email'] ?? '';
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($first_name) || empty($last_name) || empty($specialization) || empty($email) || empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
    exit;
}

try {
    // Check if username already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        throw new Exception('Username already exists');
    }
    
    // Check if email already exists
    if (!empty($email)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception('Email already exists');
        }
    }
    
    // Create user account
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT INTO users (username, password_hash, email, role, first_name, middle_name, last_name, contact_no, created_at)
        VALUES (?, ?, ?, 'doctor', ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$username, $password_hash, $email, $first_name, $middle_name, $last_name, $contact]);
    
    $user_id = $pdo->lastInsertId();
    
    // Create doctor record
    $stmt = $pdo->prepare("
        INSERT INTO doctors (user_id, specialization, clinic_room, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$user_id, $specialization, $clinic_room]);
    
    echo json_encode(['success' => true, 'message' => 'Doctor created successfully!']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

