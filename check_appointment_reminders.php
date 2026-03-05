<?php
/**
 * This script checks for appointments happening today and creates reminder notifications
 * Should be called periodically (e.g., via cron job every hour) or on page load for patients
 */
require 'db.php';

try {
    // Get all approved appointments happening today
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT 
            a.id,
            a.user_id,
            a.patient_id,
            a.start_datetime,
            a.status,
            u.first_name,
            u.last_name,
            d.specialization,
            doc_user.first_name as doc_first_name,
            doc_user.last_name as doc_last_name
        FROM appointments a
        LEFT JOIN users u ON (a.user_id = u.id OR a.patient_id = u.id)
        LEFT JOIN doctors d ON a.doctor_id = d.id
        LEFT JOIN users doc_user ON d.user_id = doc_user.id
        WHERE DATE(a.start_datetime) = ?
          AND a.status = 'approved'
          AND (a.user_id IS NOT NULL OR a.patient_id IS NOT NULL)
    ");
    $stmt->execute([$today]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($appointments as $appt) {
        $patient_user_id = $appt['user_id'] ?? $appt['patient_id'] ?? null;
        
        if (!$patient_user_id) continue;
        
        $appointment_time = date('g:i A', strtotime($appt['start_datetime']));
        $doctor_name = '';
        
        if (!empty($appt['doc_first_name'])) {
            $doctor_name = 'Dr. ' . $appt['doc_first_name'] . ' ' . $appt['doc_last_name'];
            if (!empty($appt['specialization'])) {
                $doctor_name .= ' (' . $appt['specialization'] . ')';
            }
        }
        
        // Skip creating "today at X PM" type reminders completely to avoid clutter
        // Only create appointment reminders for future dates (not today)
        $appointment_date = date('Y-m-d', strtotime($appt['start_datetime']));
        $is_today = ($appointment_date === $today);
        
        // Don't create any "today" reminders - they're clutter
        // Patients will see their appointments in the appointments page anyway
        if ($is_today) {
            continue; // Skip all today reminders
        }
        
        if ($is_today) {
            $message = "Appointment Reminder: You have a check-up today at {$appointment_time}";
        } else {
            $appointment_date_formatted = date('M d, Y', strtotime($appt['start_datetime']));
            $message = "Appointment Reminder: You have a check-up on {$appointment_date_formatted} at {$appointment_time}";
        }
        
        if ($doctor_name) {
            $message .= " with {$doctor_name}";
        }
        
        // Check if notification already exists for this specific appointment and message today
        $check_stmt = $pdo->prepare("
            SELECT notification_id 
            FROM notifications 
            WHERE user_id = ? 
              AND type = 'appointment' 
              AND message = ?
              AND DATE(created_at) = ?
            LIMIT 1
        ");
        $check_stmt->execute([$patient_user_id, $message, $today]);
        
        // Only create notification if one doesn't already exist for today
        if (!$check_stmt->fetch()) {
            $notif_stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, message, type, status) 
                VALUES (?, ?, 'appointment', 'unread')
            ");
            $notif_stmt->execute([$patient_user_id, $message]);
        }
    }
    
    // Return success (useful if called via AJAX)
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'checked' => count($appointments)]);
    }
    
} catch (PDOException $e) {
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>

