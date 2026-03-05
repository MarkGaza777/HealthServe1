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
    if ($action === 'select_followup') {
        $follow_up_id = intval($_POST['follow_up_id'] ?? 0);
        $selected_option = trim($_POST['selected_option'] ?? ''); // 'proposed', '1', '2', or '3'
        
        if ($follow_up_id <= 0) {
            throw new Exception('Invalid follow-up ID');
        }
        
        if (empty($selected_option)) {
            throw new Exception('Please select an option');
        }
        
        // Get follow-up appointment
        $stmt = $pdo->prepare('
            SELECT * FROM follow_up_appointments 
            WHERE id = ? 
            AND (user_id = ? OR patient_id = ?)
            AND status = ?
        ');
        $stmt->execute([$follow_up_id, $user_id, $user_id, 'pending_patient_confirmation']);
        $follow_up = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$follow_up) {
            throw new Exception('Follow-up appointment not found or already processed');
        }
        
        // Determine selected datetime
        $selected_datetime = null;
        if ($selected_option === 'proposed') {
            $selected_datetime = $follow_up['proposed_datetime'];
        } elseif ($selected_option === '1' && !empty($follow_up['alternative_datetime_1'])) {
            $selected_datetime = $follow_up['alternative_datetime_1'];
        } elseif ($selected_option === '2' && !empty($follow_up['alternative_datetime_2'])) {
            $selected_datetime = $follow_up['alternative_datetime_2'];
        } elseif ($selected_option === '3' && !empty($follow_up['alternative_datetime_3'])) {
            $selected_datetime = $follow_up['alternative_datetime_3'];
        } else {
            throw new Exception('Invalid selection option');
        }
        
        // Update follow-up appointment
        $patient_selected_alternative = ($selected_option === 'proposed') ? null : intval($selected_option);
        $stmt = $pdo->prepare('
            UPDATE follow_up_appointments 
            SET selected_datetime = ?, 
                patient_selected_alternative = ?,
                status = ?,
                updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([
            $selected_datetime,
            $patient_selected_alternative,
            'pending_doctor_approval',
            $follow_up_id
        ]);
        
        // Create notification for doctor
        if (!empty($follow_up['doctor_id'])) {
            $stmt = $pdo->prepare('SELECT user_id FROM doctors WHERE id = ?');
            $stmt->execute([$follow_up['doctor_id']]);
            $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($doctor && !empty($doctor['user_id'])) {
                $formatted_date = date('M d, Y', strtotime($selected_datetime));
                $formatted_time = date('g:i A', strtotime($selected_datetime));
                $message = "New follow-up appointment pending approval: {$formatted_date} at {$formatted_time}";
                
                $stmt = $pdo->prepare('INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, ?, ?)');
                $stmt->execute([
                    $doctor['user_id'],
                    $message,
                    'appointment',
                    'unread'
                ]);
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Follow-up appointment selected successfully']);
        
    } elseif ($action === 'request_reschedule') {
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
        
        // Update status to reschedule_requested
        $stmt = $pdo->prepare('
            UPDATE follow_up_appointments 
            SET status = ?,
                updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute(['reschedule_requested', $follow_up_id]);
        
        // Create notification for FDO
        if (!empty($follow_up['fdo_id'])) {
            $formatted_date = date('M d, Y', strtotime($follow_up['proposed_datetime']));
            $formatted_time = date('g:i A', strtotime($follow_up['proposed_datetime']));
            $message = "Patient has requested reschedule for follow-up appointment: {$formatted_date} at {$formatted_time}";
            
            $stmt = $pdo->prepare('INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, ?, ?)');
            $stmt->execute([
                $follow_up['fdo_id'],
                $message,
                'appointment',
                'unread'
            ]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Reschedule request submitted successfully']);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

