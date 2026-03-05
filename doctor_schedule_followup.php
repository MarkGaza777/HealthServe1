<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$doctor_id = $_SESSION['user']['id'];
$patient_id = isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
$followup_datetime = $_POST['followup_datetime'] ?? '';
$notes = trim($_POST['notes'] ?? '');

try {
    if ($patient_id <= 0 || empty($followup_datetime)) {
        throw new Exception('Patient and follow-up date/time are required.');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS patient_followups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            doctor_id INT NOT NULL,
            followup_datetime DATETIME NOT NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $pdo->prepare("
        INSERT INTO patient_followups (patient_id, doctor_id, followup_datetime, notes)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$patient_id, $doctor_id, $followup_datetime, $notes]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}





