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

$staff_id = $_GET['id'] ?? null;
$role = $_GET['role'] ?? null;

if (!$staff_id || !$role) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Staff ID and role are required']);
    exit;
}

try {
    $staff = null;
    
    if ($role === 'doctor') {
        // Get doctor details
        $stmt = $pdo->prepare("
            SELECT 
                d.id,
                d.specialization,
                d.clinic_room,
                CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as staff_name,
                COALESCE(u.contact_no, '') as contact,
                COALESCE(u.email, '') as email,
                u.address,
                u.created_at,
                'doctor' as role,
                d.specialization as department,
                'on_duty' as shift_status
            FROM doctors d
            LEFT JOIN users u ON d.user_id = u.id
            WHERE d.id = ?
        ");
        $stmt->execute([$staff_id]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($staff) {
            // Get today's patient count
            $patientStmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM appointments a
                WHERE a.doctor_id = ?
                AND DATE(a.start_datetime) = CURDATE()
                AND a.status IN ('approved', 'completed')
            ");
            $patientStmt->execute([$staff_id]);
            $patientsToday = $patientStmt->fetchColumn();
            
            // Get total appointments
            $totalApptStmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM appointments a
                WHERE a.doctor_id = ?
            ");
            $totalApptStmt->execute([$staff_id]);
            $totalAppointments = $totalApptStmt->fetchColumn();
            
            $staff['patients_today'] = (int)$patientsToday;
            $staff['total_appointments'] = (int)$totalAppointments;
        }
    } else if (in_array($role, ['physician', 'nurse', 'midwife', 'bhw'])) {
        // Get staff details from staff table (physician, nurse, midwife, bhw)
        $stmt = $pdo->prepare("
            SELECT 
                s.id,
                CONCAT(COALESCE(s.first_name, ''), ' ', COALESCE(s.middle_name, ''), ' ', COALESCE(s.last_name, '')) as staff_name,
                COALESCE(s.phone, '') as contact,
                COALESCE(s.email, '') as email,
                '' as address,
                s.created_at,
                s.role,
                COALESCE(s.department, 'General') as department,
                COALESCE(s.shift_status, 'off_duty') as shift_status
            FROM staff s
            WHERE s.id = ? AND s.role = ?
        ");
        $stmt->execute([$staff_id, $role]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Get staff details from users table (pharmacist, fdo, admin)
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as staff_name,
                COALESCE(u.contact_no, '') as contact,
                COALESCE(u.email, '') as email,
                u.address,
                u.created_at,
                u.role,
                CASE 
                    WHEN u.role = 'pharmacist' THEN 'Pharmacy'
                    WHEN u.role = 'fdo' THEN 'Front Desk'
                    WHEN u.role = 'admin' THEN 'Administration'
                    ELSE 'General'
                END as department,
                'on_duty' as shift_status
            FROM users u
            WHERE u.id = ? AND u.role = ?
        ");
        $stmt->execute([$staff_id, $role]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$staff) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Staff member not found']);
        exit;
    }
    
    $staff['staff_name'] = trim(preg_replace('/\s+/', ' ', $staff['staff_name']));
    
    echo json_encode(['success' => true, 'staff' => $staff]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

