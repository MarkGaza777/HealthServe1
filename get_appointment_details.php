<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

if(empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'patient') { 
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$appointment_id = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;
$user_id = $_SESSION['user']['id'];

if ($appointment_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid appointment ID']);
    exit;
}

try {
    // Get appointment details with patient_instructions
    $stmt = $pdo->prepare("
        SELECT DISTINCT a.*, 
               COALESCE(p.first_name, u.first_name, '') as first_name, 
               COALESCE(p.middle_name, u.middle_name, '') as middle_name,
               COALESCE(p.last_name, u.last_name, '') as last_name, 
               COALESCE(p.phone, u.contact_no, '') as phone,
               CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
        FROM appointments a 
        LEFT JOIN patients p ON a.patient_id = p.id 
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN doctors d ON a.doctor_id = d.id
        LEFT JOIN users du ON du.id = d.user_id
        WHERE a.id = ? 
          AND (a.user_id = ? OR a.patient_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$appointment_id, $user_id, $user_id]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$appointment) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
        exit;
    }
    
    // Clean up doctor_name
    if (!empty($appointment['doctor_name'])) {
        $appointment['doctor_name'] = trim(preg_replace('/\s+/', ' ', $appointment['doctor_name']));
        if (empty($appointment['doctor_name'])) {
            $appointment['doctor_name'] = null;
        }
    }
    
    echo json_encode(['success' => true, 'appointment' => $appointment]);
    
} catch(PDOException $e) {
    error_log("Appointment details error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error fetching appointment details']);
}

