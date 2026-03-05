<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Check if user is logged in and is an FDO
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'fdo') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$fdo_id = $_SESSION['user']['id'];

try {
    // Check if follow_up_appointments table exists
    try {
        $check_table = $pdo->query("SHOW TABLES LIKE 'follow_up_appointments'");
        if ($check_table->rowCount() == 0) {
            echo json_encode(['success' => true, 'requests' => []]);
            exit;
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => true, 'requests' => []]);
        exit;
    }
    
    // Get reschedule requests (status = 'reschedule_requested')
    $stmt = $pdo->prepare("
        SELECT 
            f.*,
            a.start_datetime as original_appointment_date,
            COALESCE(u.first_name, p.first_name, '') as first_name,
            COALESCE(u.middle_name, p.middle_name, '') as middle_name,
            COALESCE(u.last_name, p.last_name, '') as last_name,
            CONCAT(
                COALESCE(u.first_name, p.first_name, ''), ' ',
                COALESCE(u.middle_name, p.middle_name, ''), ' ',
                COALESCE(u.last_name, p.last_name, '')
            ) as patient_name,
            CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
        FROM follow_up_appointments f
        LEFT JOIN appointments a ON f.original_appointment_id = a.id
        LEFT JOIN users u ON f.user_id = u.id
        LEFT JOIN patients p ON f.patient_id = p.id
        LEFT JOIN doctors d ON f.doctor_id = d.id
        LEFT JOIN users du ON d.user_id = du.id
        WHERE f.status = 'reschedule_requested'
        AND (f.fdo_id = ? OR f.fdo_id IS NULL)
        ORDER BY f.created_at DESC
    ");
    $stmt->execute([$fdo_id]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the data
    foreach ($requests as &$request) {
        $patient_name = trim($request['patient_name'] ?? '');
        $patient_name = trim(preg_replace('/\s+/', ' ', $patient_name));
        if (empty($patient_name)) {
            $patient_name = 'Unknown Patient';
        }
        $request['patient_name'] = $patient_name;
        
        // Format proposed datetime
        if (!empty($request['proposed_datetime'])) {
            $dt = new DateTime($request['proposed_datetime']);
            $request['proposed_date'] = $dt->format('F j, Y');
            $request['proposed_time'] = $dt->format('g:i A');
        }
        
        // Format original appointment date
        if (!empty($request['original_appointment_date'])) {
            $dt = new DateTime($request['original_appointment_date']);
            $request['original_date'] = $dt->format('F j, Y');
        }
    }
    unset($request);
    
    echo json_encode(['success' => true, 'requests' => $requests]);
} catch (Exception $e) {
    error_log("FDO reschedule requests error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error loading reschedule requests: ' . $e->getMessage()
    ]);
}

