<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Check if user is logged in (patient or public access for viewing slots)
if(empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'patient') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get date range from request (default to current month + next month)
    $start_date = $_GET['start_date'] ?? date('Y-m-d');
    $end_date = $_GET['end_date'] ?? date('Y-m-d', strtotime('+2 months'));
    
    // Capacity limits: 3 slots per 30-min × 3 doctors. AM: 10 slots×3=30, PM: 5 slots×3=15
    $AM_CAPACITY = 30;
    $PM_CAPACITY = 15;
    
    // AM period: 7:00 AM to 12:00 PM (noon)
    // PM period: 12:00 PM to 4:00 PM (16:00)
    
    // Get all appointments in the date range with status that counts toward capacity
    // Count pending, approved, and completed appointments (not cancelled or declined)
    $stmt = $pdo->prepare("
        SELECT 
            DATE(start_datetime) as appointment_date,
            HOUR(start_datetime) as appointment_hour
        FROM appointments
        WHERE DATE(start_datetime) >= ? 
        AND DATE(start_datetime) <= ?
        AND status IN ('pending', 'approved', 'completed')
    ");
    $stmt->execute([$start_date, $end_date]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all doctor blocked times in the date range
    $doctor_blocked_times = [];
    try {
        $stmt = $pdo->prepare("
            SELECT 
                start_date,
                end_date,
                start_time,
                end_time
            FROM doctor_blocked_times
            WHERE start_date <= ?
            AND end_date >= ?
        ");
        $stmt->execute([$end_date, $start_date]);
        $doctor_blocked_times = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table might not exist, that's okay
        error_log("Error fetching doctor blocked times: " . $e->getMessage());
    }
    
    // Get all FDO-blocked times from schedules table (availability = 'blocked')
    $fdo_blocked_times = [];
    try {
        $stmt = $pdo->prepare("
            SELECT 
                date,
                time_start,
                time_end
            FROM schedules
            WHERE date >= ?
            AND date <= ?
            AND availability = 'blocked'
        ");
        $stmt->execute([$start_date, $end_date]);
        $fdo_blocked_times = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table might not exist or column might not exist, that's okay
        error_log("Error fetching FDO blocked times: " . $e->getMessage());
    }
    
    // Organize by date and time period
    $slot_data = [];
    
    // Initialize all dates in range
    $current_date = new DateTime($start_date);
    $end_date_obj = new DateTime($end_date);
    // Set time to midnight to avoid timezone issues
    $current_date->setTime(0, 0, 0);
    $end_date_obj->setTime(23, 59, 59);
    
    $monday_count = 0;
    while ($current_date <= $end_date_obj) {
        $date_str = $current_date->format('Y-m-d');
        $day_of_week = (int)$current_date->format('w'); // 0 = Sunday, 1 = Monday, 6 = Saturday
        
        // Only include weekdays (Monday-Friday) - Explicitly include Monday (1) through Friday (5)
        // Monday = 1, Tuesday = 2, Wednesday = 3, Thursday = 4, Friday = 5
        if ($day_of_week >= 1 && $day_of_week <= 5) {
            if ($day_of_week === 1) {
                $monday_count++;
            }
            // Check if AM period is blocked
            $am_blocked = false;
            $pm_blocked = false;
            
            // Check doctor blocked times
            foreach ($doctor_blocked_times as $block) {
                $block_start = new DateTime($block['start_date']);
                $block_end = new DateTime($block['end_date']);
                $current_check = new DateTime($date_str);
                
                if ($current_check >= $block_start && $current_check <= $block_end) {
                    $block_start_time = DateTime::createFromFormat('H:i:s', $block['start_time']);
                    $block_end_time = DateTime::createFromFormat('H:i:s', $block['end_time']);
                    
                    if ($block_start_time && $block_end_time) {
                        // Convert to minutes for precise comparison
                        $block_start_minutes = (int)$block_start_time->format('H') * 60 + (int)$block_start_time->format('i');
                        $block_end_minutes = (int)$block_end_time->format('H') * 60 + (int)$block_end_time->format('i');
                        
                        // AM period: 7:00 (420 minutes) to 12:00 (720 minutes)
                        // PM period: 12:00 (720 minutes) to 16:00 (960 minutes)
                        $am_start = 7 * 60; // 420
                        $am_end = 12 * 60; // 720
                        $pm_start = 12 * 60; // 720
                        $pm_end = 16 * 60; // 960
                        
                        // Check if block overlaps with AM period
                        if (!($block_end_minutes <= $am_start || $block_start_minutes >= $am_end)) {
                            $am_blocked = true;
                        }
                        
                        // Check if block overlaps with PM period
                        if (!($block_end_minutes <= $pm_start || $block_start_minutes >= $pm_end)) {
                            $pm_blocked = true;
                        }
                    }
                }
            }
            
            // Check FDO blocked times (schedules table)
            foreach ($fdo_blocked_times as $block) {
                if ($block['date'] === $date_str) {
                    $block_start_time = DateTime::createFromFormat('H:i:s', $block['time_start']);
                    $block_end_time = DateTime::createFromFormat('H:i:s', $block['time_end']);
                    
                    if ($block_start_time && $block_end_time) {
                        // Convert to minutes for precise comparison
                        $block_start_minutes = (int)$block_start_time->format('H') * 60 + (int)$block_start_time->format('i');
                        $block_end_minutes = (int)$block_end_time->format('H') * 60 + (int)$block_end_time->format('i');
                        
                        // AM period: 7:00 (420 minutes) to 12:00 (720 minutes)
                        // PM period: 12:00 (720 minutes) to 16:00 (960 minutes)
                        $am_start = 7 * 60; // 420
                        $am_end = 12 * 60; // 720
                        $pm_start = 12 * 60; // 720
                        $pm_end = 16 * 60; // 960
                        
                        // Check if block overlaps with AM period
                        if (!($block_end_minutes <= $am_start || $block_start_minutes >= $am_end)) {
                            $am_blocked = true;
                        }
                        
                        // Check if block overlaps with PM period
                        if (!($block_end_minutes <= $pm_start || $block_start_minutes >= $pm_end)) {
                            $pm_blocked = true;
                        }
                    }
                }
            }
            
            $slot_data[$date_str] = [
                'date' => $date_str,
                'day_name' => $current_date->format('D'),
                'day_number' => $current_date->format('d'),
                'am' => [
                    'booked' => 0,
                    'available' => $AM_CAPACITY,
                    'total' => $AM_CAPACITY,
                    'is_full' => false,
                    'is_blocked' => $am_blocked
                ],
                'pm' => [
                    'booked' => 0,
                    'available' => $PM_CAPACITY,
                    'total' => $PM_CAPACITY,
                    'is_full' => false,
                    'is_blocked' => $pm_blocked
                ]
            ];
        }
        
        $current_date->modify('+1 day');
    }
    
    // Count booked appointments per date and period
    foreach ($appointments as $apt) {
        $date_str = $apt['appointment_date'];
        $hour = (int)$apt['appointment_hour'];
        
        if (isset($slot_data[$date_str])) {
            // AM period: 7:00 (hour 7) to 11:59 (hour 11)
            // PM period: 12:00 (hour 12) to 15:59 (hour 15)
            if ($hour >= 7 && $hour < 12) {
                $slot_data[$date_str]['am']['booked']++;
            } elseif ($hour >= 12 && $hour < 16) {
                $slot_data[$date_str]['pm']['booked']++;
            }
        }
    }
    
    // Calculate available slots and check if full
    foreach ($slot_data as $date => &$data) {
        // If blocked, set booked to capacity to make it unavailable
        if ($data['am']['is_blocked']) {
            $data['am']['booked'] = $AM_CAPACITY;
            $data['am']['available'] = 0;
            $data['am']['is_full'] = true;
        } else {
            $data['am']['available'] = max(0, $AM_CAPACITY - $data['am']['booked']);
            $data['am']['is_full'] = $data['am']['available'] <= 0;
        }
        
        if ($data['pm']['is_blocked']) {
            $data['pm']['booked'] = $PM_CAPACITY;
            $data['pm']['available'] = 0;
            $data['pm']['is_full'] = true;
        } else {
            $data['pm']['available'] = max(0, $PM_CAPACITY - $data['pm']['booked']);
            $data['pm']['is_full'] = $data['pm']['available'] <= 0;
        }
    }
    unset($data);
    
    // Convert to array format for JSON
    $result = array_values($slot_data);
    
    // Debug: Log Monday count
    error_log("Appointment slots: Total weekdays = " . count($result) . ", Mondays = " . $monday_count);
    
    echo json_encode([
        'success' => true,
        'slots' => $result,
        'capacity' => [
            'am' => $AM_CAPACITY,
            'pm' => $PM_CAPACITY
        ],
        'debug' => [
            'monday_count' => $monday_count,
            'total_weekdays' => count($result)
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Get appointment slots error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching appointment slots: ' . $e->getMessage()
    ]);
}
?>

