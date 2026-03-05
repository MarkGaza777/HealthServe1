<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$doctor_user_id = $_SESSION['user']['id'];
$action = $_POST['action'] ?? '';

// Get doctor_id from doctors table
$stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
$stmt->execute([$doctor_user_id]);
$doctor_record = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doctor_record) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Doctor record not found']);
    exit();
}
$doctor_id = $doctor_record['id'];

try {
    if ($action === 'approve_reschedule') {
        $follow_up_id = isset($_POST['follow_up_id']) ? (int) $_POST['follow_up_id'] : 0;
        $alternative_datetime_1 = trim($_POST['alternative_datetime_1'] ?? '');
        $alternative_datetime_2 = trim($_POST['alternative_datetime_2'] ?? '');
        $alternative_datetime_3 = trim($_POST['alternative_datetime_3'] ?? '');
        
        if ($follow_up_id <= 0) {
            throw new Exception('Invalid follow-up ID');
        }
        
        // Get follow-up appointment
        $stmt = $pdo->prepare('
            SELECT * FROM follow_up_appointments 
            WHERE id = ? 
            AND doctor_id = ?
            AND status = ?
        ');
        $stmt->execute([$follow_up_id, $doctor_id, 'reschedule_requested']);
        $follow_up = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$follow_up) {
            throw new Exception('Follow-up appointment not found or not pending reschedule approval');
        }
        
        // Validate at least one alternative datetime is provided
        $alternatives = [];
        if (!empty($alternative_datetime_1)) {
            $alternatives[] = $alternative_datetime_1;
        }
        if (!empty($alternative_datetime_2)) {
            $alternatives[] = $alternative_datetime_2;
        }
        if (!empty($alternative_datetime_3)) {
            $alternatives[] = $alternative_datetime_3;
        }
        
        if (empty($alternatives)) {
            throw new Exception('Please provide at least one alternative date and time option');
        }
        
        // Update follow-up with alternative options and change status to pending_patient_confirmation
        $stmt = $pdo->prepare('
            UPDATE follow_up_appointments 
            SET alternative_datetime_1 = ?,
                alternative_datetime_2 = ?,
                alternative_datetime_3 = ?,
                status = ?,
                updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([
            $alternatives[0] ?? null,
            $alternatives[1] ?? null,
            $alternatives[2] ?? null,
            'pending_patient_confirmation',
            $follow_up_id
        ]);
        
        // Create notification for patient
        $patient_user_id = $follow_up['user_id'] ?? $follow_up['patient_id'];
        if ($patient_user_id) {
            $formatted_date = date('M d, Y', strtotime($follow_up['proposed_datetime']));
            $message = "Your doctor has provided alternative schedule options for your follow-up appointment. Please select your preferred date and time.";
            
            $stmt = $pdo->prepare('INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, ?, ?)');
            $stmt->execute([
                $patient_user_id,
                $message,
                'appointment',
                'unread'
            ]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Alternative schedule options provided to patient']);
        
    } elseif ($action === 'approve_selected') {
        $follow_up_id = isset($_POST['follow_up_id']) ? (int) $_POST['follow_up_id'] : 0;
        
        if ($follow_up_id <= 0) {
            throw new Exception('Invalid follow-up ID');
        }
        
        // Get follow-up appointment
        $stmt = $pdo->prepare('
            SELECT * FROM follow_up_appointments 
            WHERE id = ? 
            AND doctor_id = ?
            AND status = ?
            AND selected_datetime IS NOT NULL
        ');
        $stmt->execute([$follow_up_id, $doctor_id, 'pending_doctor_approval']);
        $follow_up = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$follow_up) {
            throw new Exception('Follow-up appointment not found or not pending approval');
        }
        
        // Approve the selected datetime
        $stmt = $pdo->prepare('
            UPDATE follow_up_appointments 
            SET status = ?,
                updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute(['approved', $follow_up_id]);
        
        // Create notification for patient
        $patient_user_id = $follow_up['user_id'] ?? $follow_up['patient_id'];
        if ($patient_user_id) {
            $formatted_date = date('M d, Y', strtotime($follow_up['selected_datetime']));
            $formatted_time = date('g:i A', strtotime($follow_up['selected_datetime']));
            $message = "Your follow-up appointment has been approved: {$formatted_date} at {$formatted_time}";
            
            $stmt = $pdo->prepare('INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, ?, ?)');
            $stmt->execute([
                $patient_user_id,
                $message,
                'appointment',
                'unread'
            ]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Follow-up appointment approved']);
        
    } elseif ($action === 'decline_selected') {
        $follow_up_id = isset($_POST['follow_up_id']) ? (int) $_POST['follow_up_id'] : 0;
        $decline_reason = trim($_POST['decline_reason'] ?? '');
        
        if ($follow_up_id <= 0) {
            throw new Exception('Invalid follow-up ID');
        }
        
        // Get follow-up appointment
        $stmt = $pdo->prepare('
            SELECT * FROM follow_up_appointments 
            WHERE id = ? 
            AND doctor_id = ?
            AND status = ?
        ');
        $stmt->execute([$follow_up_id, $doctor_id, 'pending_doctor_approval']);
        $follow_up = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$follow_up) {
            throw new Exception('Follow-up appointment not found or not pending approval');
        }
        
        // Decline and reset to pending_patient_confirmation to allow patient to select another option
        $stmt = $pdo->prepare('
            UPDATE follow_up_appointments 
            SET status = ?,
                selected_datetime = NULL,
                patient_selected_alternative = NULL,
                notes = CONCAT(COALESCE(notes, ""), " Declined by doctor: ", ?),
                updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute(['pending_patient_confirmation', $decline_reason, $follow_up_id]);
        
        // Create notification for patient
        $patient_user_id = $follow_up['user_id'] ?? $follow_up['patient_id'];
        if ($patient_user_id) {
            $message = "Your selected follow-up appointment time was not available. Please select another option.";
            
            $stmt = $pdo->prepare('INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, ?, ?)');
            $stmt->execute([
                $patient_user_id,
                $message,
                'appointment',
                'unread'
            ]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Follow-up appointment declined']);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

