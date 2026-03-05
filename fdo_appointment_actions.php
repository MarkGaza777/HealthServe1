<?php
session_start();
require 'db.php';
require_once 'appointment_email_service.php';
require_once 'clinic_time_slots.php';

header('Content-Type: application/json');

// Check if user is logged in and is an FDO
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'fdo') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$fdo_id = $_SESSION['user']['id'];
$action = $_POST['action'] ?? '';

try {
    if ($action === 'approve') {
        $appointment_id = intval($_POST['appointment_id'] ?? 0);
        $doctor_id = !empty($_POST['doctor_id']) ? intval($_POST['doctor_id']) : null;
        
        if ($appointment_id <= 0) {
            throw new Exception('Invalid appointment ID');
        }
        
        // Get appointment details first to check for blocked times and current status
        $stmt = $pdo->prepare('SELECT start_datetime, duration_minutes, doctor_id, status FROM appointments WHERE id = ?');
        $stmt->execute([$appointment_id]);
        $appt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$appt) {
            throw new Exception('Appointment not found');
        }
        
        $previous_status = $appt['status'] ?? 'pending';
        $final_doctor_id = $doctor_id ?: $appt['doctor_id'];
        
        // Doctor is required when approving (must be selected in form or already assigned)
        if (empty($final_doctor_id)) {
            throw new Exception('Please assign a doctor before approving the appointment.');
        }
        
        // Check for blocked times if doctor is assigned
        if ($final_doctor_id) {
            $appointment_date = date('Y-m-d', strtotime($appt['start_datetime']));
            $appointment_time = date('H:i:s', strtotime($appt['start_datetime']));
            $duration = $appt['duration_minutes'] ?? 30;
            
            // Calculate end time
            $end_time = date('H:i:s', strtotime($appointment_time . ' +' . $duration . ' minutes'));
            
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
                    $final_doctor_id,
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
                    $alternatives = getAlternativeTimes($pdo, $final_doctor_id, $appointment_date, $appointment_time, $duration);
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
            }
        }
        
        // Update appointment status and assign FDO and doctor if provided
        $sql = 'UPDATE appointments SET status = ?, fdo_id = ?';
        $params = ['approved', $fdo_id];
        
        if ($final_doctor_id) {
            // Verify doctor exists
            $stmt = $pdo->prepare('SELECT id FROM doctors WHERE id = ?');
            $stmt->execute([$final_doctor_id]);
            if ($stmt->fetch()) {
                $sql .= ', doctor_id = ?';
                $params[] = $final_doctor_id;
            }
        }
        
        $sql .= ' WHERE id = ?';
        $params[] = $appointment_id;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        // Audit: log status change
        logAppointmentStatusChange($pdo, $appointment_id, $previous_status, 'approved', 'Appointment approved', null, $fdo_id);
        
        // Create notification for patient with appointment details
        // First get appointment basic info
        $stmt = $pdo->prepare('
            SELECT a.user_id, a.patient_id, a.start_datetime, a.doctor_id
            FROM appointments a
            WHERE a.id = ?
        ');
        $stmt->execute([$appointment_id]);
        $appt = $stmt->fetch(PDO::FETCH_ASSOC);
        $patient_user_id = $appt['user_id'] ?? $appt['patient_id'] ?? null;
        
        if ($patient_user_id && $appt['start_datetime']) {
            $appointment_date = date('M d, Y', strtotime($appt['start_datetime']));
            $appointment_time = date('g:i A', strtotime($appt['start_datetime']));
            
            // Get doctor's name properly - query doctors table separately to ensure we get the right user
            $doctor_name = '';
            if (!empty($appt['doctor_id'])) {
                $doc_stmt = $pdo->prepare('
                    SELECT 
                        d.specialization,
                        u.first_name,
                        u.middle_name,
                        u.last_name
                    FROM doctors d
                    INNER JOIN users u ON d.user_id = u.id
                    WHERE d.id = ?
                ');
                $doc_stmt->execute([$appt['doctor_id']]);
                $doctor = $doc_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($doctor && !empty($doctor['first_name'])) {
                    // Build full name with middle name if available
                    $full_name = trim($doctor['first_name'] . ' ' . (!empty($doctor['middle_name']) ? $doctor['middle_name'] . ' ' : '') . $doctor['last_name']);
                    $doctor_name = 'Dr. ' . $full_name;
                    if (!empty($doctor['specialization'])) {
                        $doctor_name .= ' (' . $doctor['specialization'] . ')';
                    }
                }
            }
            
            $message = "Appointment Reminder: You have a check-up on {$appointment_date} at {$appointment_time}";
            if ($doctor_name) {
                $message .= " with {$doctor_name}";
            }
            
            // Check if notification already exists for this appointment approval
            $check_stmt = $pdo->prepare("
                SELECT notification_id 
                FROM notifications 
                WHERE user_id = ? 
                  AND type = 'appointment' 
                  AND message = ?
                LIMIT 1
            ");
            $check_stmt->execute([$patient_user_id, $message]);
            
            // Only create notification if it doesn't already exist
            if (!$check_stmt->fetch()) {
                $stmt = $pdo->prepare('INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, ?, ?)');
                $stmt->execute([
                    $patient_user_id,
                    $message,
                    'appointment',
                    'unread'
                ]);
            }
        }
        
        // Send email notification
        sendAppointmentEmail($appointment_id, 'approved');
        
        echo json_encode(['success' => true, 'message' => 'Appointment approved successfully']);
        
    } elseif ($action === 'assign_doctor') {
        $appointment_id = intval($_POST['appointment_id'] ?? 0);
        $doctor_id = intval($_POST['doctor_id'] ?? 0);
        
        if ($appointment_id <= 0 || $doctor_id <= 0) {
            throw new Exception('Invalid appointment or doctor ID');
        }
        
        // Verify doctor exists
        $stmt = $pdo->prepare('SELECT id FROM doctors WHERE id = ?');
        $stmt->execute([$doctor_id]);
        if (!$stmt->fetch()) {
            throw new Exception('Doctor not found');
        }
        
        // Update appointment with doctor assignment
        $stmt = $pdo->prepare('UPDATE appointments SET doctor_id = ?, fdo_id = ? WHERE id = ?');
        $stmt->execute([$doctor_id, $fdo_id, $appointment_id]);
        
        echo json_encode(['success' => true, 'message' => 'Doctor assigned successfully']);
        
    } elseif ($action === 'decline') {
        $appointment_id = intval($_POST['appointment_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $suggested_date = trim($_POST['suggested_date'] ?? '');
        $suggested_time = trim($_POST['suggested_time'] ?? '');
        
        if ($appointment_id <= 0) {
            throw new Exception('Invalid appointment ID');
        }
        
        if (empty($reason)) {
            throw new Exception('Decline reason is required');
        }
        
        // Get existing notes and status for duplicates check and audit
        $stmt = $pdo->prepare('SELECT notes, status FROM appointments WHERE id = ?');
        $stmt->execute([$appointment_id]);
        $existing_appt = $stmt->fetch(PDO::FETCH_ASSOC);
        $existing_notes = $existing_appt['notes'] ?? '';
        $previous_status = $existing_appt['status'] ?? 'pending';
        
        // Build notes update with decline reason and suggested schedule
        $decline_note = "\nDeclined: " . $reason;
        $suggested_schedule_note = '';
        if (!empty($suggested_date) && !empty($suggested_time)) {
            $formatted_date = date('M d, Y', strtotime($suggested_date));
            $formatted_time = date('g:i A', strtotime($suggested_time));
            $suggested_schedule_note = "\nSuggested schedule: " . $formatted_date . " at " . $formatted_time;
        }
        
        // Check if the exact same decline note already exists
        if (strpos($existing_notes, $decline_note) !== false) {
            // Note already exists, don't add it again
            $notes_update = '';
        } else {
            $notes_update = $decline_note;
        }
        
        // Check if the exact same suggested schedule note already exists
        if (!empty($suggested_schedule_note) && strpos($existing_notes, $suggested_schedule_note) !== false) {
            // Suggested schedule note already exists, don't add it again
            // Only append if we're adding the decline note
            if (empty($notes_update)) {
                $notes_update = '';
            }
        } else if (!empty($suggested_schedule_note)) {
            // Append suggested schedule note if it doesn't exist
            $notes_update .= $suggested_schedule_note;
        }
        
        // Only update notes if we have something new to add
        if (!empty($notes_update)) {
            // Update appointment status
            $stmt = $pdo->prepare('UPDATE appointments SET status = ?, fdo_id = ?, notes = CONCAT(COALESCE(notes, ""), ?) WHERE id = ?');
            $stmt->execute(['declined', $fdo_id, $notes_update, $appointment_id]);
        } else {
            // Just update status and fdo_id without modifying notes
            $stmt = $pdo->prepare('UPDATE appointments SET status = ?, fdo_id = ? WHERE id = ?');
            $stmt->execute(['declined', $fdo_id, $appointment_id]);
        }
        
        // Audit: log status change (decline reason is the reason for change)
        logAppointmentStatusChange($pdo, $appointment_id, $previous_status, 'declined', $reason, null, $fdo_id);
        
        // Create notification for patient
        $stmt = $pdo->prepare('SELECT user_id, patient_id FROM appointments WHERE id = ?');
        $stmt->execute([$appointment_id]);
        $appt = $stmt->fetch(PDO::FETCH_ASSOC);
        $patient_user_id = $appt['user_id'] ?? $appt['patient_id'] ?? null;
        
        if ($patient_user_id) {
            // Build notification message
            $message = 'Your appointment has been declined. Decline Reason: ' . $reason;
            
            // Add suggested alternative schedule if provided
            if (!empty($suggested_date) && !empty($suggested_time)) {
                // Format the suggested date
                $suggested_datetime = $suggested_date . ' ' . $suggested_time;
                $formatted_date = date('M d, Y', strtotime($suggested_date));
                $formatted_time = date('g:i A', strtotime($suggested_time));
                $message .= '. Suggested Alternative Schedule: ' . $formatted_date . ' at ' . $formatted_time . '. This information is also available in your appointment details.';
            }
            
            $stmt = $pdo->prepare('INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, ?, ?)');
            $stmt->execute([
                $patient_user_id,
                $message,
                'appointment',
                'unread'
            ]);
        }
        
        // Send email notification
        sendAppointmentEmail($appointment_id, 'declined');
        
        echo json_encode(['success' => true, 'message' => 'Appointment declined successfully']);
        
    } elseif ($action === 'update_status') {
        $appointment_id = intval($_POST['appointment_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $reason_for_change = trim($_POST['reason_for_change'] ?? '');
        $reason_details = trim($_POST['reason_details'] ?? '');
        
        if ($appointment_id <= 0) {
            throw new Exception('Invalid appointment ID');
        }
        
        $valid_statuses = ['pending', 'approved', 'completed', 'cancelled', 'rescheduled', 'declined', 'pending_patient_confirmation', 'pending_doctor_approval'];
        if (!in_array($status, $valid_statuses)) {
            throw new Exception('Invalid status');
        }
        
        // Get current status
        $stmt = $pdo->prepare('SELECT status FROM appointments WHERE id = ?');
        $stmt->execute([$appointment_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('Appointment not found');
        }
        $previous_status = $row['status'];
        
        // Status change requires a reason (mandatory for transparency)
        if ($previous_status !== $status) {
            if (empty($reason_for_change) && empty($reason_details)) {
                throw new Exception('Please provide a reason for this status change.');
            }
            // Once finalized (approved or declined), changing status requires a valid reason (already checked above)
        }
        
        // Update appointment status
        $stmt = $pdo->prepare('UPDATE appointments SET status = ?, fdo_id = ? WHERE id = ?');
        $stmt->execute([$status, $fdo_id, $appointment_id]);
        
        // Audit: log status change when status actually changed
        if ($previous_status !== $status) {
            $reason_text = $reason_for_change ?: $reason_details;
            if (empty($reason_text)) {
                $reason_text = 'Status changed';
            }
            logAppointmentStatusChange($pdo, $appointment_id, $previous_status, $status, $reason_for_change ?: null, $reason_details ?: null, $fdo_id);
        }
        
        echo json_encode(['success' => true, 'message' => 'Appointment status updated successfully']);
        
    } elseif ($action === 'schedule_followup') {
        $original_appointment_id = intval($_POST['original_appointment_id'] ?? 0);
        $follow_up_date = trim($_POST['follow_up_date'] ?? '');
        $follow_up_time = trim($_POST['follow_up_time'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $alternatives_json = $_POST['alternatives'] ?? '[]';
        
        if ($original_appointment_id <= 0) {
            throw new Exception('Invalid appointment ID');
        }
        
        if (empty($follow_up_date) || empty($follow_up_time)) {
            throw new Exception('Follow-up date and time are required');
        }
        
        // Get original appointment details
        $stmt = $pdo->prepare('SELECT user_id, patient_id, doctor_id FROM appointments WHERE id = ? AND status = ?');
        $stmt->execute([$original_appointment_id, 'completed']);
        $original_appt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$original_appt) {
            throw new Exception('Original appointment not found or not completed');
        }
        
        // Combine date and time
        $proposed_datetime = $follow_up_date . ' ' . $follow_up_time;
        
        // Parse alternatives
        $alternatives = json_decode($alternatives_json, true);
        if (!is_array($alternatives)) {
            $alternatives = [];
        }
        
        // Validate alternatives are within 1 week
        $today = new DateTime();
        $max_date = clone $today;
        $max_date->modify('+7 days');
        
        $proposed_date_obj = new DateTime($proposed_datetime);
        if ($proposed_date_obj < $today || $proposed_date_obj > $max_date) {
            throw new Exception('Follow-up date must be within 1 week from today');
        }
        
        // Prepare alternative datetimes
        $alt_datetime_1 = null;
        $alt_datetime_2 = null;
        $alt_datetime_3 = null;
        
        if (isset($alternatives[0]) && !empty($alternatives[0]['date']) && !empty($alternatives[0]['time'])) {
            $alt1 = new DateTime($alternatives[0]['date'] . ' ' . $alternatives[0]['time']);
            if ($alt1 >= $today && $alt1 <= $max_date) {
                $alt_datetime_1 = $alt1->format('Y-m-d H:i:s');
            }
        }
        if (isset($alternatives[1]) && !empty($alternatives[1]['date']) && !empty($alternatives[1]['time'])) {
            $alt2 = new DateTime($alternatives[1]['date'] . ' ' . $alternatives[1]['time']);
            if ($alt2 >= $today && $alt2 <= $max_date) {
                $alt_datetime_2 = $alt2->format('Y-m-d H:i:s');
            }
        }
        if (isset($alternatives[2]) && !empty($alternatives[2]['date']) && !empty($alternatives[2]['time'])) {
            $alt3 = new DateTime($alternatives[2]['date'] . ' ' . $alternatives[2]['time']);
            if ($alt3 >= $today && $alt3 <= $max_date) {
                $alt_datetime_3 = $alt3->format('Y-m-d H:i:s');
            }
        }
        
        // Check if follow_up_appointments table exists, if not create it
        try {
            $check_table = $pdo->query("SHOW TABLES LIKE 'follow_up_appointments'");
            if ($check_table->rowCount() == 0) {
                // Table doesn't exist, create it
                $pdo->exec("
                    CREATE TABLE `follow_up_appointments` (
                      `id` int(11) NOT NULL AUTO_INCREMENT,
                      `original_appointment_id` int(11) NOT NULL,
                      `user_id` int(11) DEFAULT NULL,
                      `patient_id` int(11) DEFAULT NULL,
                      `doctor_id` int(11) DEFAULT NULL,
                      `fdo_id` int(11) DEFAULT NULL,
                      `proposed_datetime` datetime NOT NULL,
                      `selected_datetime` datetime DEFAULT NULL,
                      `status` enum('pending_patient_confirmation', 'pending_doctor_approval', 'approved', 'declined', 'cancelled') DEFAULT 'pending_patient_confirmation',
                      `notes` text DEFAULT NULL,
                      `alternative_datetime_1` datetime DEFAULT NULL,
                      `alternative_datetime_2` datetime DEFAULT NULL,
                      `alternative_datetime_3` datetime DEFAULT NULL,
                      `patient_selected_alternative` int(11) DEFAULT NULL,
                      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                      PRIMARY KEY (`id`),
                      KEY `idx_original_appointment` (`original_appointment_id`),
                      KEY `idx_user` (`user_id`),
                      KEY `idx_patient` (`patient_id`),
                      KEY `idx_doctor` (`doctor_id`),
                      KEY `idx_fdo` (`fdo_id`),
                      KEY `idx_status` (`status`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
        } catch (PDOException $e) {
            // Table might already exist, continue
        }
        
        // Insert follow-up appointment
        $stmt = $pdo->prepare('
            INSERT INTO follow_up_appointments 
            (original_appointment_id, user_id, patient_id, doctor_id, fdo_id, proposed_datetime, status, notes, alternative_datetime_1, alternative_datetime_2, alternative_datetime_3)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $original_appointment_id,
            $original_appt['user_id'],
            $original_appt['patient_id'],
            $original_appt['doctor_id'],
            $fdo_id,
            $proposed_datetime,
            'pending_patient_confirmation',
            $notes,
            $alt_datetime_1,
            $alt_datetime_2,
            $alt_datetime_3
        ]);
        
        $follow_up_id = $pdo->lastInsertId();
        
        // Create notification for patient
        $patient_user_id = $original_appt['user_id'] ?? $original_appt['patient_id'] ?? null;
        if ($patient_user_id) {
            $formatted_date = date('M d, Y', strtotime($proposed_datetime));
            $formatted_time = date('g:i A', strtotime($proposed_datetime));
            $message = "Follow-up appointment scheduled: {$formatted_date} at {$formatted_time}";
            
            if (!empty($alternatives)) {
                $message .= ". Alternative options available.";
            }
            
            $stmt = $pdo->prepare('INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, ?, ?)');
            $stmt->execute([
                $patient_user_id,
                $message,
                'appointment',
                'unread'
            ]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Follow-up appointment scheduled successfully', 'follow_up_id' => $follow_up_id]);
        
    } elseif ($action === 'reschedule_followup') {
        $follow_up_id = intval($_POST['follow_up_id'] ?? 0);
        $follow_up_date = trim($_POST['follow_up_date'] ?? '');
        $follow_up_time = trim($_POST['follow_up_time'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $alternatives_json = $_POST['alternatives'] ?? '[]';
        
        if ($follow_up_id <= 0) {
            throw new Exception('Invalid follow-up ID');
        }
        
        if (empty($follow_up_date) || empty($follow_up_time)) {
            throw new Exception('Follow-up date and time are required');
        }
        
        // Get existing follow-up appointment
        $stmt = $pdo->prepare('SELECT * FROM follow_up_appointments WHERE id = ? AND status = ?');
        $stmt->execute([$follow_up_id, 'reschedule_requested']);
        $existing_followup = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existing_followup) {
            throw new Exception('Reschedule request not found or already processed');
        }
        
        // Combine date and time
        $proposed_datetime = $follow_up_date . ' ' . $follow_up_time;
        
        // Parse alternatives
        $alternatives = json_decode($alternatives_json, true);
        if (!is_array($alternatives)) {
            $alternatives = [];
        }
        
        // Validate alternatives are within 1 week
        $today = new DateTime();
        $max_date = clone $today;
        $max_date->modify('+7 days');
        
        $proposed_date_obj = new DateTime($proposed_datetime);
        if ($proposed_date_obj < $today || $proposed_date_obj > $max_date) {
            throw new Exception('Follow-up date must be within 1 week from today');
        }
        
        // Prepare alternative datetimes
        $alt_datetime_1 = null;
        $alt_datetime_2 = null;
        $alt_datetime_3 = null;
        
        if (isset($alternatives[0]) && !empty($alternatives[0]['date']) && !empty($alternatives[0]['time'])) {
            $alt1 = new DateTime($alternatives[0]['date'] . ' ' . $alternatives[0]['time']);
            if ($alt1 >= $today && $alt1 <= $max_date) {
                $alt_datetime_1 = $alt1->format('Y-m-d H:i:s');
            }
        }
        if (isset($alternatives[1]) && !empty($alternatives[1]['date']) && !empty($alternatives[1]['time'])) {
            $alt2 = new DateTime($alternatives[1]['date'] . ' ' . $alternatives[1]['time']);
            if ($alt2 >= $today && $alt2 <= $max_date) {
                $alt_datetime_2 = $alt2->format('Y-m-d H:i:s');
            }
        }
        if (isset($alternatives[2]) && !empty($alternatives[2]['date']) && !empty($alternatives[2]['time'])) {
            $alt3 = new DateTime($alternatives[2]['date'] . ' ' . $alternatives[2]['time']);
            if ($alt3 >= $today && $alt3 <= $max_date) {
                $alt_datetime_3 = $alt3->format('Y-m-d H:i:s');
            }
        }
        
        // Update follow-up appointment with new options
        $update_notes = 'Rescheduled by FDO. ' . ($notes ? $notes : '');
        $stmt = $pdo->prepare('
            UPDATE follow_up_appointments 
            SET proposed_datetime = ?,
                selected_datetime = NULL,
                status = ?,
                notes = ?,
                alternative_datetime_1 = ?,
                alternative_datetime_2 = ?,
                alternative_datetime_3 = ?,
                patient_selected_alternative = NULL,
                updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([
            $proposed_datetime,
            'pending_patient_confirmation',
            $update_notes,
            $alt_datetime_1,
            $alt_datetime_2,
            $alt_datetime_3,
            $follow_up_id
        ]);
        
        // Create notification for patient
        $patient_user_id = $existing_followup['user_id'] ?? $existing_followup['patient_id'] ?? null;
        if ($patient_user_id) {
            $formatted_date = date('M d, Y', strtotime($proposed_datetime));
            $formatted_time = date('g:i A', strtotime($proposed_datetime));
            $message = "Your follow-up appointment has been rescheduled. New date: {$formatted_date} at {$formatted_time}";
            
            if (!empty($alternatives)) {
                $message .= ". Alternative options available.";
            }
            
            $stmt = $pdo->prepare('INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, ?, ?)');
            $stmt->execute([
                $patient_user_id,
                $message,
                'appointment',
                'unread'
            ]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Follow-up appointment rescheduled successfully']);

    } elseif ($action === 'save_triage') {
        $appointment_id = intval($_POST['appointment_id'] ?? 0);
        $patient_id = !empty($_POST['patient_id']) ? intval($_POST['patient_id']) : null;
        $user_id = !empty($_POST['user_id']) ? intval($_POST['user_id']) : null;
        $blood_pressure = trim($_POST['blood_pressure'] ?? '');
        $temperature = isset($_POST['temperature']) && $_POST['temperature'] !== '' ? floatval($_POST['temperature']) : null;
        $weight = isset($_POST['weight']) && $_POST['weight'] !== '' ? floatval($_POST['weight']) : null;
        $pulse_rate = isset($_POST['pulse_rate']) && $_POST['pulse_rate'] !== '' ? intval($_POST['pulse_rate']) : null;
        $oxygen_saturation = isset($_POST['oxygen_saturation']) && $_POST['oxygen_saturation'] !== '' ? floatval($_POST['oxygen_saturation']) : null;
        $for_immediate_referral = !empty($_POST['for_immediate_referral']) && $_POST['for_immediate_referral'] !== '0' ? 1 : 0;
        $recorded_by = $fdo_id;

        if ($appointment_id <= 0) {
            throw new Exception('Appointment ID is required.');
        }
        if (empty($blood_pressure) || $temperature === null || $weight === null) {
            throw new Exception('Blood pressure, temperature, and weight are required.');
        }
        if (!isset($_POST['pulse_rate']) || trim((string)($_POST['pulse_rate'] ?? '')) === '') {
            throw new Exception('Pulse rate is required.');
        }
        if (!isset($_POST['oxygen_saturation']) || trim((string)($_POST['oxygen_saturation'] ?? '')) === '') {
            throw new Exception('Oxygen saturation is required.');
        }

        // Server-side validation: realistic medical ranges
        if (!preg_match('/^\d{2,3}\/\d{2,3}$/', $blood_pressure)) {
            throw new Exception('Blood pressure must be in format systolic/diastolic (e.g. 120/80).');
        }
        $bp_parts = array_map('intval', explode('/', $blood_pressure));
        if ($bp_parts[0] === 0 && $bp_parts[1] === 0) {
            throw new Exception('Invalid blood pressure (000/000).');
        }
        if ($bp_parts[0] < 70 || $bp_parts[0] > 200 || $bp_parts[1] < 40 || $bp_parts[1] > 130 || $bp_parts[1] >= $bp_parts[0]) {
            throw new Exception('Blood pressure must be within range: systolic 70–200 mmHg, diastolic 40–130 mmHg, diastolic < systolic.');
        }
        if ($temperature < 35 || $temperature > 42) {
            throw new Exception('Temperature must be between 35.0°C and 42.0°C (stored in Celsius).');
        }
        if ($weight < 2 || $weight > 300) {
            throw new Exception('Weight must be between 2 and 300 kg.');
        }
        if ($pulse_rate < 30 || $pulse_rate > 220) {
            throw new Exception('Pulse rate must be between 30 and 220 bpm.');
        }
        if ($oxygen_saturation < 50 || $oxygen_saturation > 100) {
            throw new Exception('Oxygen saturation must be between 50 and 100%.');
        }

        $stmt = $pdo->prepare('SELECT id FROM appointments WHERE id = ?');
        $stmt->execute([$appointment_id]);
        if (!$stmt->fetch()) {
            throw new Exception('Appointment not found.');
        }

        try {
            $table_check = $pdo->query("SHOW TABLES LIKE 'triage_records'");
            $table_exists = $table_check->rowCount() > 0;
            if (!$table_exists) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `triage_records` (
                      `id` int(11) NOT NULL AUTO_INCREMENT,
                      `appointment_id` int(11) NOT NULL,
                      `patient_id` int(11) DEFAULT NULL,
                      `user_id` int(11) DEFAULT NULL,
                      `blood_pressure` varchar(20) DEFAULT NULL,
                      `temperature` decimal(5,2) DEFAULT NULL,
                      `weight` decimal(6,2) DEFAULT NULL,
                      `pulse_rate` int(11) DEFAULT NULL,
                      `oxygen_saturation` decimal(5,2) DEFAULT NULL,
                      `for_immediate_referral` tinyint(1) NOT NULL DEFAULT 0,
                      `notes` text DEFAULT NULL,
                      `recorded_by` int(11) DEFAULT NULL,
                      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                      PRIMARY KEY (`id`),
                      KEY `idx_appointment` (`appointment_id`),
                      CONSTRAINT `fk_triage_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
                      CONSTRAINT `fk_triage_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            } else {
                $column_check = $pdo->query("SHOW COLUMNS FROM triage_records LIKE 'oxygen_saturation'");
                if ($column_check->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE triage_records ADD COLUMN oxygen_saturation decimal(5,2) DEFAULT NULL AFTER pulse_rate");
                }
                $ref_column = $pdo->query("SHOW COLUMNS FROM triage_records LIKE 'for_immediate_referral'");
                if ($ref_column->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE triage_records ADD COLUMN for_immediate_referral TINYINT(1) NOT NULL DEFAULT 0 AFTER oxygen_saturation");
                }
            }
        } catch (PDOException $e) {
            error_log("Triage table setup error: " . $e->getMessage());
        }

        $created_at_value = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("SELECT id FROM triage_records WHERE appointment_id = ? LIMIT 1");
        $stmt->execute([$appointment_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $pdo->prepare("
                UPDATE triage_records
                SET blood_pressure = ?, temperature = ?, weight = ?, pulse_rate = ?,
                    oxygen_saturation = ?, for_immediate_referral = ?, recorded_by = ?, created_at = ?
                WHERE appointment_id = ?
            ");
            $stmt->execute([$blood_pressure, $temperature, $weight, $pulse_rate, $oxygen_saturation, $for_immediate_referral, $recorded_by, $created_at_value, $appointment_id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO triage_records (appointment_id, patient_id, user_id, blood_pressure, temperature, weight, pulse_rate, oxygen_saturation, for_immediate_referral, recorded_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$appointment_id, $patient_id, $user_id, $blood_pressure, $temperature, $weight, $pulse_rate, $oxygen_saturation, $for_immediate_referral, $recorded_by, $created_at_value]);
        }

        echo json_encode(['success' => true, 'message' => 'Initial screening saved successfully']);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

/**
 * Log appointment status change for audit and transparency.
 * Stores: previous_status, new_status, reason_for_change, reason_details, changed_by, timestamp.
 */
function logAppointmentStatusChange($pdo, $appointment_id, $previous_status, $new_status, $reason_for_change, $reason_details, $changed_by_user_id) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO appointment_status_audit
            (appointment_id, previous_status, new_status, reason_for_change, reason_details, changed_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $appointment_id,
            $previous_status,
            $new_status,
            $reason_for_change,
            $reason_details,
            $changed_by_user_id
        ]);
    } catch (PDOException $e) {
        // Table may not exist yet if migration not run; log but do not fail the request
        error_log('Appointment status audit log failed: ' . $e->getMessage());
    }
}

// Helper function to get alternative available times
function getAlternativeTimes($pdo, $doctor_id, $date, $exclude_time, $duration_minutes) {
    $alternatives = [];
    // Use master time slot list - NO hardcoded arrays
    $time_slots = getClinicTimeSlotsWithSeconds();
    
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

