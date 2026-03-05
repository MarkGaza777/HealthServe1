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

try {
    // Ensure doctor_blocked_times table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS doctor_blocked_times (
            id INT AUTO_INCREMENT PRIMARY KEY,
            doctor_id INT NOT NULL,
            reason VARCHAR(255) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE,
            INDEX idx_doctor_dates (doctor_id, start_date, end_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Get all doctors with their information and patient counts for today
    $stmt = $pdo->prepare("
        SELECT 
            d.id,
            d.specialization,
            d.clinic_room,
            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as doctor_name,
            COALESCE(u.contact_no, '') as contact,
            COALESCE(u.email, '') as email,
            (SELECT COUNT(*) 
             FROM appointments a 
             WHERE a.doctor_id = d.id 
             AND DATE(a.start_datetime) = CURDATE()
             AND a.status IN ('approved', 'completed')) as patients_today
        FROM doctors d
        LEFT JOIN users u ON d.user_id = u.id
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute();
    $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check for leave status for each doctor
    $today = date('Y-m-d');
    
    // Clean up names and format data
    foreach ($doctors as &$doctor) {
        $doctor['doctor_name'] = trim(preg_replace('/\s+/', ' ', $doctor['doctor_name']));
        if (empty($doctor['doctor_name'])) {
            $doctor['doctor_name'] = 'Unknown Doctor';
        }
        
        // Check if doctor is on leave today
        $leaveStmt = $pdo->prepare("
            SELECT reason, start_date, end_date
            FROM doctor_blocked_times
            WHERE doctor_id = ?
            AND reason = 'On Leave'
            AND start_date <= ?
            AND end_date >= ?
            LIMIT 1
        ");
        $leaveStmt->execute([$doctor['id'], $today, $today]);
        $leaveRecord = $leaveStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($leaveRecord) {
            $doctor['status'] = 'on_leave';
            $doctor['leave_start'] = $leaveRecord['start_date'];
            $doctor['leave_end'] = $leaveRecord['end_date'];
        } else {
            $doctor['status'] = 'active';
        }
    }
    unset($doctor);
    
    // Count total doctors
    $total_doctors = count($doctors);
    
    // Count doctors on leave today
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT d.id) as on_leave_count
        FROM doctors d
        INNER JOIN doctor_blocked_times dbt ON d.id = dbt.doctor_id
        WHERE dbt.reason = 'On Leave'
        AND dbt.start_date <= ?
        AND dbt.end_date >= ?
    ");
    $stmt->execute([$today, $today]);
    $on_leave_count = (int)$stmt->fetchColumn();
    
    echo json_encode([
        'success' => true, 
        'doctors' => $doctors,
        'stats' => [
            'total_doctors' => $total_doctors,
            'on_leave' => $on_leave_count
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

