<?php
session_start();
require 'db.php';
require_once 'clinic_time_slots.php';

header('Content-Type: application/json');

// Check if user is logged in
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'patient') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $date = $_GET['date'] ?? '';
    $period = $_GET['period'] ?? ''; // 'am' or 'pm'
    
    if (empty($date)) {
        echo json_encode(['success' => false, 'message' => 'Date parameter is required']);
        exit;
    }
    
    // Validate date format
    $date_obj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$date_obj) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format']);
        exit;
    }
    
    // Get master time slot list - NO dynamic generation
    $master_slots = CLINIC_TIME_SLOTS;
    $slot_capacity = getSlotCapacity(); // 3 slots per time
    
    // Filter slots by period if specified - ensure no duplicates
    $filtered_slots = [];
    $seen_slots = []; // Track seen slots to prevent duplicates
    foreach ($master_slots as $time_str) {
        // Skip if already seen
        if (in_array($time_str, $seen_slots)) {
            continue;
        }
        $seen_slots[] = $time_str;
        
        $hour = (int)substr($time_str, 0, 2);
        
        // Filter by period
        if ($period === 'am') {
            if ($hour >= 7 && $hour < 12) {
                $filtered_slots[] = $time_str;
            }
        } elseif ($period === 'pm') {
            if ($hour >= 13 && $hour <= 15) {
                $filtered_slots[] = $time_str;
            }
        } else {
            // No period filter - include all slots
            $filtered_slots[] = $time_str;
        }
    }
    
    // Remove any duplicates from filtered_slots (extra safety)
    $filtered_slots = array_values(array_unique($filtered_slots));
    
    // Build slot data with availability - ensure no duplicates
    $slots = [];
    $seen_times = []; // Track seen times to prevent duplicates
    foreach ($filtered_slots as $time_str) {
        // Skip if we've already processed this time
        if (in_array($time_str, $seen_times)) {
            continue;
        }
        $seen_times[] = $time_str;
        $datetime_str = $date . ' ' . $time_str . ':00';
        
        // Check if slot is in the past or too close (less than 1 hour away)
        $slot_timestamp = strtotime($datetime_str);
        $now_timestamp = time();
        $min_lead_seconds = 60 * 60; // 1 hour
        
        if ($slot_timestamp <= $now_timestamp || $slot_timestamp < ($now_timestamp + $min_lead_seconds)) {
            // Slot is in the past or too close
            $slots[] = [
                'time' => $time_str,
                'available' => 0,
                'capacity' => $slot_capacity,
                'disabled' => true,
                'disabled_reason' => 'past_or_too_close'
            ];
            continue;
        }
        
        // Count booked appointments for this exact time slot
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as booked_count
            FROM appointments
            WHERE DATE(start_datetime) = ?
            AND TIME(start_datetime) = ?
            AND status IN ('pending', 'approved', 'completed')
        ");
        $stmt->execute([$date, $time_str . ':00']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $booked = (int)($result['booked_count'] ?? 0);
        
        // Check if slot is blocked by doctor
        $is_blocked = false;
        try {
            $block_stmt = $pdo->prepare("
                SELECT COUNT(*) as blocked_count
                FROM doctor_blocked_times
                WHERE start_date <= ?
                AND end_date >= ?
                AND start_time <= ?
                AND end_time > ?
            ");
            $block_stmt->execute([$date, $date, $time_str . ':00', $time_str . ':00']);
            $block_result = $block_stmt->fetch(PDO::FETCH_ASSOC);
            if ((int)($block_result['blocked_count'] ?? 0) > 0) {
                $is_blocked = true;
            }
        } catch (Exception $e) {
            // Table might not exist, continue
            error_log("Error checking doctor blocked times: " . $e->getMessage());
        }
        
        // Check if slot is blocked by FDO (schedules table)
        try {
            $schedule_stmt = $pdo->prepare("
                SELECT COUNT(*) as blocked_count
                FROM schedules
                WHERE date = ?
                AND availability = 'blocked'
                AND time_start <= ?
                AND time_end > ?
            ");
            $schedule_stmt->execute([$date, $time_str . ':00', $time_str . ':00']);
            $schedule_result = $schedule_stmt->fetch(PDO::FETCH_ASSOC);
            if ((int)($schedule_result['blocked_count'] ?? 0) > 0) {
                $is_blocked = true;
            }
        } catch (Exception $e) {
            // Table might not exist or column might not exist, continue
            error_log("Error checking FDO blocked times: " . $e->getMessage());
        }
        
        $available = max(0, $slot_capacity - $booked);
        $is_full = ($available <= 0);
        
        $slots[] = [
            'time' => $time_str,
            'available' => $available,
            'capacity' => $slot_capacity,
            'disabled' => $is_blocked || $is_full,
            'disabled_reason' => $is_blocked ? 'blocked' : ($is_full ? 'full' : null)
        ];
    }
    
    // Final deduplication pass - remove any duplicate times (keep first occurrence)
    $final_slots = [];
    $final_seen_times = [];
    foreach ($slots as $slot) {
        $time_key = $slot['time'];
        if (!in_array($time_key, $final_seen_times)) {
            $final_seen_times[] = $time_key;
            $final_slots[] = $slot;
        }
    }
    
    echo json_encode([
        'success' => true,
        'slots' => $final_slots,
        'date' => $date,
        'period' => $period
    ]);
    
} catch (Exception $e) {
    error_log("Get time slot capacities error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching time slot capacities: ' . $e->getMessage()
    ]);
}
?>
