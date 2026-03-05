<?php
session_start();
require 'db.php';
require_once 'appointment_code_helper.php';

header('Content-Type: application/json');

// Check if user is logged in and is an FDO
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'fdo') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get and validate input
    $patient_type = $_POST['patient_type'] ?? ''; // 'registered' or 'dependent'
    $patient_id = !empty($_POST['patient_id']) ? intval($_POST['patient_id']) : null;
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $start_datetime = $_POST['start_datetime'] ?? null;
    $duration_minutes = intval($_POST['duration_minutes'] ?? 20);
    $reason = trim($_POST['reason'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $doctor_id = !empty($_POST['doctor_id']) ? intval($_POST['doctor_id']) : null;
    $fdo_id = $_SESSION['user']['id'];
    
    // Validation
    if (empty($first_name) || empty($last_name)) {
        throw new Exception('First name and last name are required');
    }
    
    if (!$start_datetime || strtotime($start_datetime) === false) {
        throw new Exception('Invalid date and time format');
    }
    
    if (strtotime($start_datetime) < time()) {
        throw new Exception('Appointment time must be in the future');
    }
    
    if ($duration_minutes <= 0 || $duration_minutes > 120) {
        throw new Exception('Invalid duration');
    }
    
    // Check for blocked times if doctor is assigned
    if ($doctor_id) {
        $appointment_date = date('Y-m-d', strtotime($start_datetime));
        $appointment_time = date('H:i:s', strtotime($start_datetime));
        $end_time = date('H:i:s', strtotime($appointment_time . ' +' . $duration_minutes . ' minutes'));
        
        // Check if this time slot is blocked
        try {
            $block_stmt = $pdo->prepare("
                SELECT reason, start_time, end_time
                FROM doctor_blocked_times
                WHERE doctor_id = ?
                    AND start_date <= ?
                    AND end_date >= ?
                    AND (
                        (start_time <= ? AND end_time > ?)
                        OR (start_time < ? AND end_time >= ?)
                        OR (start_time >= ? AND end_time <= ?)
                    )
            ");
            $block_stmt->execute([
                $doctor_id,
                $appointment_date,
                $appointment_date,
                $appointment_time,
                $appointment_time,
                $end_time,
                $end_time,
                $appointment_time,
                $end_time
            ]);
            $blocked = $block_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($blocked) {
                // Get alternative times
                $alternatives = getAlternativeTimes($pdo, $doctor_id, $appointment_date, $appointment_time, $duration_minutes);
                $message = "This time slot is blocked by the doctor (Reason: " . $blocked['reason'] . "). ";
                if (!empty($alternatives)) {
                    $message .= "Suggested alternative times: " . implode(', ', $alternatives);
                } else {
                    $message .= "Please select a different date or time.";
                }
                throw new Exception($message);
            }
        } catch (PDOException $e) {
            // Table might not exist, continue without blocking check
            error_log("Error checking blocked times: " . $e->getMessage());
        } catch (Exception $e) {
            // Re-throw our custom exception
            throw $e;
        }
    }
    
    $pdo->beginTransaction();
    
    $user_id = null;
    $appointment_patient_id = null;
    
    if ($patient_type === 'registered' && $patient_id) {
        // Registered patient - use their user_id
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND role = ?');
        $stmt->execute([$patient_id, 'patient']);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new Exception('Invalid patient selected');
        }
        
        $user_id = $user['id'];
        $appointment_patient_id = $user_id; // For registered patients, patient_id = user_id
    } elseif ($patient_type === 'dependent' && $patient_id) {
        // Dependent - get parent's user_id from patients table
        $stmt = $pdo->prepare('SELECT created_by_user_id FROM patients WHERE id = ?');
        $stmt->execute([$patient_id]);
        $patient = $stmt->fetch();
        
        if (!$patient || !$patient['created_by_user_id']) {
            throw new Exception('Invalid dependent selected');
        }
        
        $user_id = $patient['created_by_user_id'];
        $appointment_patient_id = null; // For dependents, patient_id is NULL
    } else {
        // New patient (not in system) - create as dependent
        // We need a user_id to link to, but we don't have one for new patients
        // For now, we'll create a patient record but can't create appointment without user_id
        // This case should be handled by selecting an existing patient or creating a user account first
        throw new Exception('Please select an existing patient or create a user account first');
    }
    
    // Verify doctor exists if provided
    if ($doctor_id) {
        $stmt = $pdo->prepare('SELECT id FROM doctors WHERE id = ?');
        $stmt->execute([$doctor_id]);
        if (!$stmt->fetch()) {
            $doctor_id = null; // Invalid doctor_id, set to null
        }
    }
    
    // Combine reason and notes
    $combined_notes = '';
    if (!empty($reason)) {
        $combined_notes = 'Reason: ' . $reason;
    }
    if (!empty($notes)) {
        $combined_notes .= ($combined_notes ? "\n" : '') . 'Notes: ' . $notes;
    }
    
    // Create appointment (with unique appointment code if column exists)
    if (appointmentCodeColumnExists($pdo)) {
        $appointment_code = generateUniqueAppointmentCode($pdo);
        $stmt = $pdo->prepare('INSERT INTO appointments (user_id, patient_id, doctor_id, fdo_id, start_datetime, duration_minutes, status, notes, appointment_code) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$user_id, $appointment_patient_id, $doctor_id, $fdo_id, $start_datetime, $duration_minutes, 'pending', $combined_notes, $appointment_code]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO appointments (user_id, patient_id, doctor_id, fdo_id, start_datetime, duration_minutes, status, notes) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$user_id, $appointment_patient_id, $doctor_id, $fdo_id, $start_datetime, $duration_minutes, 'pending', $combined_notes]);
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Appointment created successfully',
        'appointment_id' => $pdo->lastInsertId()
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// Helper function to get alternative available times
function getAlternativeTimes($pdo, $doctor_id, $date, $exclude_time, $duration_minutes) {
    $alternatives = [];
    $time_slots = [
        '07:00:00', '07:30:00', '08:00:00', '08:30:00', '09:00:00', '09:30:00',
        '10:00:00', '10:30:00', '11:00:00', '12:00:00', '12:30:00',
        '13:00:00', '13:30:00', '14:00:00', '14:30:00', '15:00:00'
    ];
    
    // Get blocked times for this doctor and date
    $blocked_times = [];
    try {
        $stmt = $pdo->prepare("
            SELECT start_time, end_time
            FROM doctor_blocked_times
            WHERE doctor_id = ?
                AND start_date <= ?
                AND end_date >= ?
        ");
        $stmt->execute([$doctor_id, $date, $date]);
        $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($blocks as $block) {
            $blocked_times[] = ['start' => $block['start_time'], 'end' => $block['end_time']];
        }
    } catch (PDOException $e) {
        // Table might not exist
    }
    
    // Get existing appointments for this doctor and date
    $appt_times = [];
    try {
        $stmt = $pdo->prepare("
            SELECT start_datetime, duration_minutes
            FROM appointments
            WHERE doctor_id = ?
                AND DATE(start_datetime) = ?
                AND status = 'approved'
        ");
        $stmt->execute([$doctor_id, $date]);
        $appts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($appts as $appt) {
            $start = date('H:i:s', strtotime($appt['start_datetime']));
            $end = date('H:i:s', strtotime($start . ' +' . ($appt['duration_minutes'] ?? 30) . ' minutes'));
            $appt_times[] = ['start' => $start, 'end' => $end];
        }
    } catch (PDOException $e) {
        // Error getting appointments
    }
    
    // Find available slots
    foreach ($time_slots as $slot) {
        if ($slot === $exclude_time) continue;
        
        $slot_end = date('H:i:s', strtotime($slot . ' +' . $duration_minutes . ' minutes'));
        
        // Check if slot conflicts with blocked time
        $is_blocked = false;
        foreach ($blocked_times as $block) {
            if (($slot >= $block['start'] && $slot < $block['end']) ||
                ($slot_end > $block['start'] && $slot_end <= $block['end']) ||
                ($slot <= $block['start'] && $slot_end >= $block['end'])) {
                $is_blocked = true;
                break;
            }
        }
        
        if ($is_blocked) continue;
        
        // Check if slot conflicts with existing appointment
        $has_conflict = false;
        foreach ($appt_times as $appt) {
            if (($slot >= $appt['start'] && $slot < $appt['end']) ||
                ($slot_end > $appt['start'] && $slot_end <= $appt['end']) ||
                ($slot <= $appt['start'] && $slot_end >= $appt['end'])) {
                $has_conflict = true;
                break;
            }
        }
        
        if (!$has_conflict) {
            $formatted_time = date('g:i A', strtotime($slot));
            $alternatives[] = $formatted_time;
            if (count($alternatives) >= 3) break; // Return up to 3 alternatives
        }
    }
    
    return $alternatives;
}

