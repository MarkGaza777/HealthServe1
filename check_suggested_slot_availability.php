<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

if(empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'patient') { 
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $suggested_date = $_GET['date'] ?? '';
    $suggested_time = $_GET['time'] ?? '';
    
    if (empty($suggested_date) || empty($suggested_time)) {
        echo json_encode(['success' => false, 'message' => 'Date and time are required']);
        exit;
    }
    
    // Combine date and time into datetime
    $suggested_datetime = $suggested_date . ' ' . $suggested_time;
    
    // Validate datetime format
    $datetime_obj = DateTime::createFromFormat('Y-m-d H:i:s', $suggested_datetime);
    if (!$datetime_obj) {
        // Try alternative format
        $datetime_obj = DateTime::createFromFormat('Y-m-d H:i', $suggested_datetime);
    }
    
    if (!$datetime_obj) {
        echo json_encode(['success' => false, 'message' => 'Invalid date or time format']);
        exit;
    }
    
    $start_datetime = $datetime_obj->format('Y-m-d H:i:s');
    
    // Check slot availability: AM 30 (10×30min×3 doctors), PM 15 (5×30min×3 doctors)
    $AM_CAPACITY = 30;
    $PM_CAPACITY = 15;
    
    $appointment_date = date('Y-m-d', strtotime($start_datetime));
    $appointment_hour = (int)date('H', strtotime($start_datetime));
    
    // Determine period (AM: 7-11, PM: 12-15)
    $period = ($appointment_hour >= 7 && $appointment_hour < 12) ? 'am' : 'pm';
    $capacity = $period === 'am' ? $AM_CAPACITY : $PM_CAPACITY;
    
    // Count booked appointments for this date and period
    $hour_start = $period === 'am' ? 7 : 12;
    $hour_end = $period === 'am' ? 12 : 16;
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as booked_count
        FROM appointments
        WHERE DATE(start_datetime) = ?
        AND HOUR(start_datetime) >= ?
        AND HOUR(start_datetime) < ?
        AND status IN ('pending', 'approved', 'completed')
    ");
    $stmt->execute([$appointment_date, $hour_start, $hour_end]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $booked = (int)($result['booked_count'] ?? 0);
    
    $is_available = $booked < $capacity;
    
    echo json_encode([
        'success' => true,
        'available' => $is_available,
        'booked' => $booked,
        'capacity' => $capacity,
        'period' => $period,
        'datetime' => $start_datetime
    ]);
    
} catch (Exception $e) {
    error_log("Check suggested slot availability error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error checking slot availability: ' . $e->getMessage()
    ]);
}
?>

