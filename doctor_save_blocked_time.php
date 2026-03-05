<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Check if user is logged in and is a doctor
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$doctor_user_id = $_SESSION['user']['id'];

try {
    // Get doctor ID from user_id
    $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
    $stmt->execute([$doctor_user_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doctor) {
        throw new Exception('Doctor record not found');
    }
    
    $doctor_id = $doctor['id'];
    $reason = $_POST['reason'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    
    if (empty($reason) || empty($start_date) || empty($end_date) || empty($start_time) || empty($end_time)) {
        throw new Exception('All fields are required');
    }
    
    // Check if schedules table has reason column, if not, we'll add it via ALTER
    // For now, we'll use a separate table for blocked times with reasons
    // First, check if doctor_blocked_times table exists, if not create it
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS doctor_blocked_times (
            id INT AUTO_INCREMENT PRIMARY KEY,
            doctor_id INT NOT NULL,
            reason VARCHAR(255) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE,
            INDEX idx_doctor_dates (doctor_id, start_date, end_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Insert blocked time
    $stmt = $pdo->prepare("
        INSERT INTO doctor_blocked_times (doctor_id, reason, start_date, end_date, start_time, end_time)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([$doctor_id, $reason, $start_date, $end_date, $start_time, $end_time]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Time blocked successfully!'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

