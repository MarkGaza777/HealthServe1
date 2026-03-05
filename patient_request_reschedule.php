<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Check if user is logged in
if (empty($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user']['id'];
$action = $_POST['action'] ?? '';

try {
    if ($action === 'request_reschedule') {
        $follow_up_id = intval($_POST['follow_up_id'] ?? 0);
        
        if ($follow_up_id <= 0) {
            throw new Exception('Invalid follow-up ID');
        }
        
        // Get follow-up appointment
        $stmt = $pdo->prepare('
            SELECT * FROM follow_up_appointments 
            WHERE id = ? 
            AND (user_id = ? OR patient_id = ?)
            AND status = ?
        ');
        $stmt->execute([$follow_up_id, $user_id, $user_id, 'doctor_set']);
        $follow_up = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$follow_up) {
            throw new Exception('Follow-up appointment not found or cannot be rescheduled');
        }
        
        // Check if reschedule request is at least 1 week before scheduled date
        $scheduled_datetime = new DateTime($follow_up['proposed_datetime']);
        $now = new DateTime();
        $one_week_before = clone $scheduled_datetime;
        $one_week_before->modify('-7 days');
        
        if ($now > $one_week_before) {
            throw new Exception('Reschedule requests must be made at least one week before the scheduled appointment date.');
        }
        
        // Check if reschedule_requested_at column exists
        $has_reschedule_column = false;
        try {
            $check_stmt = $pdo->query("SHOW COLUMNS FROM follow_up_appointments LIKE 'reschedule_requested_at'");
            $has_reschedule_column = $check_stmt->rowCount() > 0;
        } catch (Exception $e) {
            // Column doesn't exist, will add it
            $has_reschedule_column = false;
        }
        
        // If column doesn't exist, try to add it
        if (!$has_reschedule_column) {
            try {
                $pdo->exec("ALTER TABLE follow_up_appointments ADD COLUMN reschedule_requested_at DATETIME DEFAULT NULL COMMENT 'When patient requested reschedule' AFTER patient_selected_alternative");
                $has_reschedule_column = true;
            } catch (Exception $e) {
                // If adding column fails, continue without it
                error_log("Could not add reschedule_requested_at column: " . $e->getMessage());
            }
        }
        
        // Update status to reschedule_requested
        if ($has_reschedule_column) {
            $stmt = $pdo->prepare('
                UPDATE follow_up_appointments 
                SET status = ?,
                    reschedule_requested_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute(['reschedule_requested', $follow_up_id]);
        } else {
            // Fallback: update without reschedule_requested_at column
            $stmt = $pdo->prepare('
                UPDATE follow_up_appointments 
                SET status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute(['reschedule_requested', $follow_up_id]);
        }
        
        // Create notification for doctor
        if (!empty($follow_up['doctor_id'])) {
            $stmt = $pdo->prepare('SELECT user_id FROM doctors WHERE id = ?');
            $stmt->execute([$follow_up['doctor_id']]);
            $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($doctor && !empty($doctor['user_id'])) {
                $formatted_date = date('M d, Y', strtotime($follow_up['proposed_datetime']));
                $formatted_time = date('g:i A', strtotime($follow_up['proposed_datetime']));
                $message = "Patient has requested to reschedule follow-up appointment: {$formatted_date} at {$formatted_time}";
                
                $stmt = $pdo->prepare('INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, ?, ?)');
                $stmt->execute([
                    $doctor['user_id'],
                    $message,
                    'appointment',
                    'unread'
                ]);
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Reschedule request submitted successfully. The doctor will provide alternative schedule options.']);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

