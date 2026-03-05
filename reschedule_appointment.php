<?php
session_start();
require 'db.php';
require_once 'appointment_email_service.php';

if(empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'patient') { 
    header('Location: Login.php'); 
    exit; 
}

// Check if rescheduled column exists, if not add it
try {
    $check_column = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'rescheduled'");
    if($check_column->rowCount() == 0) {
        $pdo->exec("ALTER TABLE appointments ADD COLUMN rescheduled TINYINT(1) DEFAULT 0");
    }
} catch(PDOException $e) {
    // Column might already exist or table structure issue, continue anyway
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['appointment_id']) && isset($_POST['new_date']) && isset($_POST['new_time'])) {
    $appointment_id = intval($_POST['appointment_id']);
    $user_id = $_SESSION['user']['id'];
    $new_date = $_POST['new_date'];
    $new_time = $_POST['new_time'];
    
    // Verify the appointment belongs to the user and is pending or approved
    $stmt = $pdo->prepare('SELECT id, status, start_datetime, COALESCE(rescheduled, 0) as rescheduled FROM appointments WHERE id = ? AND user_id = ? AND (status = "pending" OR status = "approved")');
    $stmt->execute([$appointment_id, $user_id]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($appointment) {
        // Check if already rescheduled
        if($appointment['rescheduled'] == 1) {
            $_SESSION['booking_error'] = 'This appointment has already been rescheduled once. You cannot reschedule it again.';
            header('Location: user_appointments.php');
            exit;
        }
        
        // Check if current appointment is more than 1 hour away
        $current_datetime = new DateTime($appointment['start_datetime']);
        $now = new DateTime();
        $time_diff = $current_datetime->getTimestamp() - $now->getTimestamp();
        $hours_away = $time_diff / 3600;
        
        if($hours_away <= 1) {
            $_SESSION['booking_error'] = 'Cannot reschedule appointments that are less than 1 hour away.';
            header('Location: user_appointments.php');
            exit;
        }
        
        // Validate new date and time
        $new_datetime_str = $new_date . ' ' . $new_time . ':00';
        $new_datetime = DateTime::createFromFormat('Y-m-d H:i:s', $new_datetime_str);
        
        if(!$new_datetime) {
            $_SESSION['booking_error'] = 'Invalid date or time format.';
            header('Location: user_appointments.php');
            exit;
        }
        
        // Check if new date/time is more than 2 hours away
        $new_time_diff = $new_datetime->getTimestamp() - $now->getTimestamp();
        $new_hours_away = $new_time_diff / 3600;
        
        if($new_hours_away < 2) {
            $_SESSION['booking_error'] = 'The new appointment time must be at least 2 hours away from now.';
            header('Location: user_appointments.php');
            exit;
        }
        
        // Check if it's a weekday
        $day_of_week = $new_datetime->format('w'); // 0 = Sunday, 6 = Saturday
        if($day_of_week == 0 || $day_of_week == 6) {
            $_SESSION['booking_error'] = 'Appointments can only be scheduled on weekdays (Monday to Friday).';
            header('Location: user_appointments.php');
            exit;
        }
        
        // Update appointment with new date/time and mark as rescheduled
        // Keep the status as pending or approved (don't change it)
        $new_datetime_formatted = $new_datetime->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare('UPDATE appointments SET start_datetime = ?, rescheduled = 1 WHERE id = ?');
        $stmt->execute([$new_datetime_formatted, $appointment_id]);
        
        // Send email notification
        sendAppointmentEmail($appointment_id, 'rescheduled');
        
        $_SESSION['booking_success'] = 'Appointment rescheduled successfully.';
        // Redirect with filter=all so the rescheduled appointment shows up regardless of date
        header('Location: user_appointments.php?filter=all&date=all');
        exit;
    } else {
        $_SESSION['booking_error'] = 'Unable to reschedule appointment. It may have already been processed or cancelled.';
    }
} else {
    $_SESSION['booking_error'] = 'Invalid request.';
}

header('Location: user_appointments.php');
exit;
?>