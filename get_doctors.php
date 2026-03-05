<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

try {
    // Get all active doctors
    $stmt = $pdo->prepare("
        SELECT 
            d.id,
            d.specialization,
            d.clinic_room,
            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as doctor_name
        FROM doctors d
        LEFT JOIN users u ON d.user_id = u.id
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute();
    $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Clean up names
    foreach ($doctors as &$doctor) {
        $doctor['doctor_name'] = trim(preg_replace('/\s+/', ' ', $doctor['doctor_name']));
    }
    unset($doctor);
    
    echo json_encode(['success' => true, 'doctors' => $doctors]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

