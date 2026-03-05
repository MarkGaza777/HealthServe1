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
$date = $_GET['date'] ?? '';

if (empty($date)) {
    echo json_encode(['success' => false, 'message' => 'Date parameter is required']);
    exit;
}

try {
    // Get the doctor_id from doctors table using user_id
    $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doctor) {
        echo json_encode(['success' => true, 'appointments' => [], 'blocks' => []]);
        exit;
    }
    
    $doctor_id = $doctor['id'];
    
    // Get all approved appointments for this date
    // Include appointments assigned to this doctor OR unassigned appointments (doctor_id IS NULL)
    $stmt = $pdo->prepare("
        SELECT 
            a.*,
            CASE 
                WHEN u.id IS NOT NULL AND u.role = 'patient' THEN 
                    CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, ''))
                ELSE 
                    CONCAT(COALESCE(p.first_name, pd.first_name, u.first_name, ''), ' ', COALESCE(p.middle_name, pd.middle_name, u.middle_name, ''), ' ', COALESCE(p.last_name, pd.last_name, u.last_name, ''))
            END as patient_name
        FROM appointments a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN patients pd ON a.patient_id IS NULL 
            AND pd.created_by_user_id = a.user_id 
            AND pd.created_at <= a.created_at
            AND pd.created_at >= DATE_SUB(a.created_at, INTERVAL 1 HOUR)
        WHERE DATE(a.start_datetime) = ?
            AND a.status = 'approved'
            AND (a.doctor_id = ? OR a.doctor_id IS NULL)
        ORDER BY a.start_datetime ASC
    ");
    $stmt->execute([$date, $doctor_id]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format appointments for schedule display
    $scheduleAppointments = [];
    foreach ($appointments as $appt) {
        $start_datetime = new DateTime($appt['start_datetime']);
        $end_datetime = clone $start_datetime;
        $duration = $appt['duration_minutes'] ?? 60; // Default to 60 minutes if not set
        $end_datetime->modify("+{$duration} minutes");
        
        // Convert to 12-hour format for display
        $startTime = $start_datetime->format('g:i A');
        $endTime = $end_datetime->format('g:i A');
        
        // Determine column based on doctor assignment
        // If assigned to this doctor, use column 0 (first column)
        // If unassigned, use column 0 as default
        // In a multi-doctor system, you might want to map doctor_id to column numbers
        $column = 0; // Default to first column
        
        // If you have multiple doctors, you could map them:
        // $column = ($appt['doctor_id'] == $doctor_id) ? 0 : (($appt['doctor_id'] % 3));
        
        $scheduleAppointments[] = [
            'startTime' => $startTime,
            'endTime' => $endTime,
            'column' => $column,
            'patientName' => trim($appt['patient_name']) ?: 'Unknown Patient',
            'appointmentId' => $appt['id'],
            'status' => $appt['status']
        ];
    }
    
    // Get blocked times for this doctor and date
    $blocks = [];
    try {
        // Check if doctor_blocked_times table exists
        $stmt = $pdo->prepare("
            SELECT reason, start_time, end_time
            FROM doctor_blocked_times
            WHERE doctor_id = ?
                AND start_date <= ?
                AND end_date >= ?
        ");
        $stmt->execute([$doctor_id, $date, $date]);
        $blockedTimes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($blockedTimes as $block) {
            // Convert TIME to 12-hour format
            $startTimeObj = DateTime::createFromFormat('H:i:s', $block['start_time']);
            $endTimeObj = DateTime::createFromFormat('H:i:s', $block['end_time']);
            
            if ($startTimeObj && $endTimeObj) {
                $blocks[] = [
                    'startTime' => $startTimeObj->format('g:i A'),
                    'endTime' => $endTimeObj->format('g:i A'),
                    'reason' => $block['reason'],
                    'column' => 0
                ];
            }
        }
    } catch (Exception $e) {
        // Table might not exist yet, that's okay
        error_log("Error fetching blocked times: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true, 
        'appointments' => $scheduleAppointments,
        'blocks' => $blocks
    ]);
    
} catch (Exception $e) {
    error_log("Doctor schedule error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error loading schedule: ' . $e->getMessage()
    ]);
}

