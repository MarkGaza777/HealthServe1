<?php
session_start();
require 'db.php';
require_once 'clinic_time_slots.php';

header('Content-Type: application/json');

// Check if user is logged in
if (empty($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $follow_up_id = isset($_GET['follow_up_id']) ? intval($_GET['follow_up_id']) : 0;
    
    if ($follow_up_id <= 0) {
        throw new Exception('Invalid follow-up ID');
    }
    
    // Get follow-up appointment to check original date
    $stmt = $pdo->prepare('
        SELECT proposed_datetime, doctor_id 
        FROM follow_up_appointments 
        WHERE id = ?
    ');
    $stmt->execute([$follow_up_id]);
    $follow_up = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$follow_up) {
        throw new Exception('Follow-up appointment not found');
    }
    
    $original_date = new DateTime($follow_up['proposed_datetime']);
    $start_date = clone $original_date;
    $start_date->modify('-3 days'); // Start 3 days before original
    $end_date = clone $original_date;
    $end_date->modify('+14 days'); // End 14 days after original
    
    // Capacity limits
    $AM_CAPACITY = 250;
    $PM_CAPACITY = 250;
    
    // Get booked appointments in the date range
    $stmt = $pdo->prepare("
        SELECT 
            DATE(start_datetime) as appointment_date,
            HOUR(start_datetime) as appointment_hour
        FROM appointments
        WHERE DATE(start_datetime) >= ? 
        AND DATE(start_datetime) <= ?
        AND status IN ('pending', 'approved', 'completed')
    ");
    $stmt->execute([$start_date->format('Y-m-d'), $end_date->format('Y-m-d')]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get existing follow-up appointments (excluding the current one)
    $stmt = $pdo->prepare("
        SELECT 
            DATE(COALESCE(selected_datetime, proposed_datetime)) as appointment_date,
            HOUR(COALESCE(selected_datetime, proposed_datetime)) as appointment_hour
        FROM follow_up_appointments
        WHERE id != ?
        AND status IN ('approved', 'doctor_set', 'pending_doctor_approval')
        AND DATE(COALESCE(selected_datetime, proposed_datetime)) >= ?
        AND DATE(COALESCE(selected_datetime, proposed_datetime)) <= ?
    ");
    $stmt->execute([$follow_up_id, $start_date->format('Y-m-d'), $end_date->format('Y-m-d')]);
    $followup_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Combine appointments
    $all_appointments = array_merge($appointments, $followup_appointments);
    
    // Organize by date and time period
    $slot_data = [];
    $current_date = clone $start_date;
    
    while ($current_date <= $end_date) {
        $day_of_week = $current_date->format('w'); // 0 = Sunday, 6 = Saturday
        
        // Only include weekdays (Monday-Friday)
        if ($day_of_week != 0 && $day_of_week != 6) {
            $date_str = $current_date->format('Y-m-d');
            $slot_data[$date_str] = [
                'date' => $date_str,
                'formatted_date' => $current_date->format('F j, Y'),
                'day_name' => $current_date->format('D'),
                'am' => [
                    'booked' => 0,
                    'available' => $AM_CAPACITY,
                    'total' => $AM_CAPACITY,
                    'is_full' => false,
                    'slots' => []
                ],
                'pm' => [
                    'booked' => 0,
                    'available' => $PM_CAPACITY,
                    'total' => $PM_CAPACITY,
                    'is_full' => false,
                    'slots' => []
                ]
            ];
        }
        
        $current_date->modify('+1 day');
    }
    
    // Count booked appointments per date and period
    foreach ($all_appointments as $apt) {
        $date_str = $apt['appointment_date'];
        $hour = (int)$apt['appointment_hour'];
        
        if (isset($slot_data[$date_str])) {
            if ($hour >= 7 && $hour < 12) {
                $slot_data[$date_str]['am']['booked']++;
            } elseif ($hour >= 12 && $hour < 16) {
                $slot_data[$date_str]['pm']['booked']++;
            }
        }
    }
    
    // Generate available time slots for each date using master time slot list
    foreach ($slot_data as $date => &$data) {
        // Use master time slot list - NO dynamic generation
        foreach (CLINIC_TIME_SLOTS as $time_str) {
            $datetime_str = $date . ' ' . $time_str . ':00';
            $datetime_obj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime_str);
            
            if ($datetime_obj) {
                $hour = (int)$datetime_obj->format('H');
                
                // Determine if AM or PM period
                $period = ($hour >= 7 && $hour < 12) ? 'am' : 'pm';
                
                $slot = [
                    'datetime' => $datetime_str,
                    'formatted_time' => $datetime_obj->format('g:i A'),
                    'available' => true
                ];
                
                $data[$period]['slots'][] = $slot;
            }
        }
        
        // Calculate available slots
        $data['am']['available'] = $AM_CAPACITY - $data['am']['booked'];
        $data['pm']['available'] = $PM_CAPACITY - $data['pm']['booked'];
        $data['am']['is_full'] = $data['am']['available'] <= 0;
        $data['pm']['is_full'] = $data['pm']['available'] <= 0;
    }
    
    // Filter out dates that are in the past or too far in the future
    $now = new DateTime();
    $filtered_slots = [];
    foreach ($slot_data as $date => $data) {
        $date_obj = new DateTime($date);
        if ($date_obj >= $now) {
            $filtered_slots[$date] = $data;
        }
    }
    
    echo json_encode([
        'success' => true,
        'slots' => array_values($filtered_slots),
        'original_date' => $original_date->format('Y-m-d'),
        'original_time' => $original_date->format('H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

