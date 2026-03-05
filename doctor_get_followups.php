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

$user_id = $_SESSION['user']['id'];

try {
    // Get doctor_id from doctors table
    $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doctor) {
        echo json_encode(['success' => true, 'followups' => [], 'message' => 'Doctor record not found']);
        exit;
    }
    
    $doctor_id = $doctor['id'];
    
    // Check if follow_up_appointments table exists
    try {
        $check_table = $pdo->query("SHOW TABLES LIKE 'follow_up_appointments'");
        if ($check_table->rowCount() == 0) {
            echo json_encode(['success' => true, 'followups' => []]);
            exit;
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => true, 'followups' => []]);
        exit;
    }
    
    // Get follow-up appointments pending doctor approval (patient selected from alternatives)
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
            ) as patient_name
        FROM follow_up_appointments f
        LEFT JOIN appointments a ON f.original_appointment_id = a.id
        LEFT JOIN users u ON f.user_id = u.id
        LEFT JOIN patients p ON f.patient_id = p.id
        WHERE f.doctor_id = ?
        AND f.status = 'pending_doctor_approval'
        AND f.selected_datetime IS NOT NULL
        ORDER BY f.selected_datetime ASC
    ");
    $stmt->execute([$doctor_id]);
    $followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get reschedule-requested follow-ups (patient requested reschedule, doctor needs to provide alternatives)
    $stmt_reschedule = $pdo->prepare("
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
            ) as patient_name
        FROM follow_up_appointments f
        LEFT JOIN appointments a ON f.original_appointment_id = a.id
        LEFT JOIN users u ON f.user_id = u.id
        LEFT JOIN patients p ON f.patient_id = p.id
        WHERE f.doctor_id = ?
        AND f.status = 'reschedule_requested'
        ORDER BY f.proposed_datetime ASC
    ");
    $stmt_reschedule->execute([$doctor_id]);
    $reschedule_requests = $stmt_reschedule->fetchAll(PDO::FETCH_ASSOC);
    
    // Get approved follow-up appointments (final schedule)
    $stmt_approved = $pdo->prepare("
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
            ) as patient_name
        FROM follow_up_appointments f
        LEFT JOIN appointments a ON f.original_appointment_id = a.id
        LEFT JOIN users u ON f.user_id = u.id
        LEFT JOIN patients p ON f.patient_id = p.id
        WHERE f.doctor_id = ?
        AND f.status = 'approved'
        AND f.selected_datetime IS NOT NULL
        ORDER BY f.selected_datetime ASC
    ");
    $stmt_approved->execute([$doctor_id]);
    $approved_followups = $stmt_approved->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the data for pending approvals
    foreach ($followups as &$followup) {
        $patient_name = trim($followup['patient_name'] ?? '');
        $patient_name = trim(preg_replace('/\s+/', ' ', $patient_name));
        if (empty($patient_name)) {
            $patient_name = 'Unknown Patient';
        }
        $followup['patient_name'] = $patient_name;
        
        // Format selected datetime (final selected date)
        if (!empty($followup['selected_datetime'])) {
            $dt = new DateTime($followup['selected_datetime']);
            $followup['date'] = $dt->format('F j, Y');
            $followup['time'] = $dt->format('g:i A');
        }
        
        // Format original appointment date
        if (!empty($followup['original_appointment_date'])) {
            $dt = new DateTime($followup['original_appointment_date']);
            $followup['original_date'] = $dt->format('F j, Y');
        }
    }
    unset($followup);
    
    // Format the data for reschedule requests
    foreach ($reschedule_requests as &$request) {
        $patient_name = trim($request['patient_name'] ?? '');
        $patient_name = trim(preg_replace('/\s+/', ' ', $patient_name));
        if (empty($patient_name)) {
            $patient_name = 'Unknown Patient';
        }
        $request['patient_name'] = $patient_name;
        
        // Format proposed datetime
        if (!empty($request['proposed_datetime'])) {
            $dt = new DateTime($request['proposed_datetime']);
            $request['date'] = $dt->format('F j, Y');
            $request['time'] = $dt->format('g:i A');
        }
        
        // Format original appointment date
        if (!empty($request['original_appointment_date'])) {
            $dt = new DateTime($request['original_appointment_date']);
            $request['original_date'] = $dt->format('F j, Y');
        }
    }
    unset($request);
    
    // Format the data for approved follow-ups
    foreach ($approved_followups as &$followup) {
        $patient_name = trim($followup['patient_name'] ?? '');
        $patient_name = trim(preg_replace('/\s+/', ' ', $patient_name));
        if (empty($patient_name)) {
            $patient_name = 'Unknown Patient';
        }
        $followup['patient_name'] = $patient_name;
        
        // Format selected datetime (final approved date)
        if (!empty($followup['selected_datetime'])) {
            $dt = new DateTime($followup['selected_datetime']);
            $followup['date'] = $dt->format('F j, Y');
            $followup['time'] = $dt->format('g:i A');
        }
        
        // Format original appointment date
        if (!empty($followup['original_appointment_date'])) {
            $dt = new DateTime($followup['original_appointment_date']);
            $followup['original_date'] = $dt->format('F j, Y');
        }
    }
    unset($followup);
    
    echo json_encode([
        'success' => true, 
        'followups' => $followups, 
        'approved_followups' => $approved_followups,
        'reschedule_requests' => $reschedule_requests
    ]);
} catch (Exception $e) {
    error_log("Doctor follow-ups error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error loading follow-ups: ' . $e->getMessage()
    ]);
}

