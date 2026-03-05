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
$date = $_GET['date'] ?? '';
$doctor_id = $_GET['doctor_id'] ?? null;

if (empty($date)) {
    echo json_encode(['success' => false, 'message' => 'Date parameter is required']);
    exit;
}

try {
    // Get all approved appointments for this date
    // FDO can see all appointments assigned to them or unassigned
    $sql = "
        SELECT DISTINCT
            a.*,
            COALESCE(p.first_name, u.first_name, '') as first_name,
            COALESCE(p.middle_name, u.middle_name, '') as middle_name,
            COALESCE(p.last_name, u.last_name, '') as last_name,
            CONCAT(COALESCE(p.first_name, u.first_name, ''), ' ', COALESCE(p.middle_name, u.middle_name, ''), ' ', COALESCE(p.last_name, u.last_name, '')) as patient_name,
            d.id as doctor_id,
            CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN doctors d ON a.doctor_id = d.id
        LEFT JOIN users du ON d.user_id = du.id
        WHERE DATE(a.start_datetime) = ?
            AND a.status = 'approved'
            AND (a.fdo_id = ? OR a.fdo_id IS NULL)
    ";
    
    $params = [$date, $fdo_id];
    
    // Add doctor filter if provided
    if ($doctor_id !== null && $doctor_id !== '') {
        $sql .= " AND a.doctor_id = ?";
        $params[] = $doctor_id;
    }
    
    $sql .= " ORDER BY a.start_datetime ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
        // Map doctor_id to column (0, 1, or 2)
        // If unassigned (doctor_id is NULL), use column 0
        $column = 0; // Default to first column
        if (!empty($appt['doctor_id'])) {
            // Map doctor_id to column (you can adjust this logic based on your needs)
            // For now, use modulo 3 to distribute across 3 columns
            $column = ($appt['doctor_id'] % 3);
        }
        
        $scheduleAppointments[] = [
            'startTime' => $startTime,
            'endTime' => $endTime,
            'column' => $column,
            'patientName' => trim($appt['patient_name']) ?: 'Unknown Patient',
            'appointmentId' => $appt['id'],
            'status' => $appt['status'],
            'doctorName' => $appt['doctor_name'] ?: 'Unassigned',
            'doctorId' => $appt['doctor_id'],
            'startDatetime' => $appt['start_datetime'],
            'endDatetime' => $end_datetime->format('Y-m-d H:i:s')
        ];
    }
    
    // Get blocked times for the selected doctor (if doctor_id is provided)
    $blocks = [];
    if ($doctor_id !== null && $doctor_id !== '') {
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
                    // Map doctor_id to column (same logic as appointments)
                    $column = ($doctor_id % 3);
                    
                    $blocks[] = [
                        'startTime' => $startTimeObj->format('g:i A'),
                        'endTime' => $endTimeObj->format('g:i A'),
                        'reason' => $block['reason'],
                        'column' => $column
                    ];
                }
            }
        } catch (Exception $e) {
            // Table might not exist yet, that's okay
            error_log("Error fetching blocked times: " . $e->getMessage());
        }
    }
    
    echo json_encode([
        'success' => true, 
        'appointments' => $scheduleAppointments,
        'blocks' => $blocks
    ]);
    
} catch (Exception $e) {
    error_log("FDO schedule error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error loading schedule: ' . $e->getMessage()
    ]);
}

