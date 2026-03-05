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

$doctor_id = $_GET['id'] ?? null;

if (!$doctor_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Doctor ID is required']);
    exit;
}

try {
    // Get doctor details
    $stmt = $pdo->prepare("
        SELECT 
            d.id,
            d.specialization,
            d.clinic_room,
            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as doctor_name,
            COALESCE(u.contact_no, '') as contact,
            COALESCE(u.email, '') as email,
            u.address,
            u.created_at
        FROM doctors d
        LEFT JOIN users u ON d.user_id = u.id
        WHERE d.id = ?
    ");
    $stmt->execute([$doctor_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doctor) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Doctor not found']);
        exit;
    }
    
    // Get leave dates
    $leaveStmt = $pdo->prepare("
        SELECT reason, start_date, end_date, start_time, end_time
        FROM doctor_blocked_times
        WHERE doctor_id = ?
        AND reason = 'On Leave'
        AND end_date >= CURDATE()
        ORDER BY start_date ASC
    ");
    $leaveStmt->execute([$doctor_id]);
    $leaveDates = $leaveStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get today's patient count
    $patientStmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM appointments a
        WHERE a.doctor_id = ?
        AND DATE(a.start_datetime) = CURDATE()
        AND a.status IN ('approved', 'completed')
    ");
    $patientStmt->execute([$doctor_id]);
    $patientsToday = $patientStmt->fetchColumn();
    
    // Get total appointments
    $totalApptStmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM appointments a
        WHERE a.doctor_id = ?
    ");
    $totalApptStmt->execute([$doctor_id]);
    $totalAppointments = $totalApptStmt->fetchColumn();
    
    $doctor['doctor_name'] = trim(preg_replace('/\s+/', ' ', $doctor['doctor_name']));
    $doctor['patients_today'] = (int)$patientsToday;
    $doctor['total_appointments'] = (int)$totalAppointments;
    $doctor['leave_dates'] = $leaveDates;
    
    echo json_encode(['success' => true, 'doctor' => $doctor]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

