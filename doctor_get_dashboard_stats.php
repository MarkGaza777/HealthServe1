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
    // First, get the doctor_id from doctors table using user_id
    $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doctor) {
        echo json_encode([
            'success' => true,
            'stats' => [
                'next_appointment' => ['time' => 'N/A', 'patient' => 'No appointments'],
                'today_count' => 0,
                'week_appointments' => 0
            ]
        ]);
        exit;
    }
    
    $doctor_id = $doctor['id'];
    $today = date('Y-m-d');
    
    // Calculate start and end of current week (Monday to Sunday)
    $dayOfWeek = date('w'); // 0 (Sunday) to 6 (Saturday)
    $daysToMonday = ($dayOfWeek == 0) ? -6 : 1 - $dayOfWeek;
    $startOfWeek = date('Y-m-d', strtotime($today . ' ' . $daysToMonday . ' days'));
    $endOfWeek = date('Y-m-d', strtotime($startOfWeek . ' +6 days'));
    
    // 1. Get Next Appointment (next upcoming approved appointment only)
    // Doctors should only see approved appointments assigned to them
    $stmt = $pdo->prepare("
        SELECT 
            a.start_datetime,
            a.patient_id,
            a.user_id,
            COALESCE(
                CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')),
                CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.middle_name, ''), ' ', COALESCE(p.last_name, ''))
            ) as patient_name
        FROM appointments a
        LEFT JOIN users u ON a.user_id = u.id AND u.role = 'patient'
        LEFT JOIN patients p ON a.patient_id = p.id
        WHERE a.doctor_id = ?
        AND a.status = 'approved'
        AND a.start_datetime >= NOW()
        ORDER BY a.start_datetime ASC
        LIMIT 1
    ");
    $stmt->execute([$doctor_id]);
    $nextAppt = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $nextAppointmentTime = 'N/A';
    $nextAppointmentPatient = 'No appointments';
    
    if ($nextAppt && !empty($nextAppt['start_datetime'])) {
        $dt = new DateTime($nextAppt['start_datetime']);
        $nextAppointmentTime = $dt->format('g:i A');
        $patientName = trim($nextAppt['patient_name'] ?? '');
        $nextAppointmentPatient = !empty($patientName) ? $patientName : 'Unknown Patient';
    }
    
    // 2. Count Today's Appointments (only approved appointments assigned to this doctor)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM appointments
        WHERE doctor_id = ?
        AND DATE(start_datetime) = ?
        AND status = 'approved'
    ");
    $stmt->execute([$doctor_id, $today]);
    $todayResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $todayCount = (int)($todayResult['count'] ?? 0);
    
    // 3. This Week's Appointments (total count of approved appointments assigned to this doctor)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM appointments
        WHERE doctor_id = ?
        AND DATE(start_datetime) >= ?
        AND DATE(start_datetime) <= ?
        AND status = 'approved'
    ");
    $stmt->execute([$doctor_id, $startOfWeek, $endOfWeek]);
    $weekResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $weekAppointments = (int)($weekResult['count'] ?? 0);
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'next_appointment' => [
                'time' => $nextAppointmentTime,
                'patient' => $nextAppointmentPatient
            ],
            'today_count' => $todayCount,
            'week_appointments' => $weekAppointments
        ]
    ]);
} catch (Exception $e) {
    error_log("Doctor dashboard stats error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>

