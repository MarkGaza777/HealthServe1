<?php
session_start();
require 'db.php';
require_once 'appointment_email_service.php';
require_once 'appointment_code_helper.php';

header('Content-Type: application/json');

// Check if user is logged in and is a doctor
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
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
    exit;
}
$doctor_id = $doctor_record['id'];

try {
    // Handle follow-up appointments
    if ($action === 'approve_followup' || $action === 'decline_followup') {
        $follow_up_id = intval($_POST['follow_up_id'] ?? 0);
        
        if ($follow_up_id <= 0) {
            throw new Exception('Invalid follow-up ID');
        }
        
        // Verify follow-up exists and belongs to this doctor
        $stmt = $pdo->prepare("SELECT * FROM follow_up_appointments WHERE id = ? AND doctor_id = ? AND status = 'pending_doctor_approval'");
        $stmt->execute([$follow_up_id, $doctor_id]);
        $follow_up = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$follow_up) {
            throw new Exception('Follow-up appointment not found or already processed');
        }
        
        if ($action === 'approve_followup') {
            // Update follow-up status
            $stmt = $pdo->prepare("UPDATE follow_up_appointments SET status = 'approved', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$follow_up_id]);
            
            // Create a new appointment from the follow-up (with unique appointment code if column exists)
            $selected_datetime = $follow_up['selected_datetime'];
            if (appointmentCodeColumnExists($pdo)) {
                $appointment_code = generateUniqueAppointmentCode($pdo);
                $stmt = $pdo->prepare("
                    INSERT INTO appointments 
                    (user_id, patient_id, doctor_id, fdo_id, start_datetime, status, notes, appointment_code, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, 'approved', ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $follow_up['user_id'],
                    $follow_up['patient_id'],
                    $follow_up['doctor_id'],
                    $follow_up['fdo_id'],
                    $selected_datetime,
                    'Follow-up appointment: ' . ($follow_up['notes'] ?? ''),
                    $appointment_code
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO appointments 
                    (user_id, patient_id, doctor_id, fdo_id, start_datetime, status, notes, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, 'approved', ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $follow_up['user_id'],
                    $follow_up['patient_id'],
                    $follow_up['doctor_id'],
                    $follow_up['fdo_id'],
                    $selected_datetime,
                    'Follow-up appointment: ' . ($follow_up['notes'] ?? '')
                ]);
            }
            
            $new_appointment_id = $pdo->lastInsertId();
            
            // Create notification for patient
            $patient_user_id = $follow_up['user_id'] ?? $follow_up['patient_id'] ?? null;
            if ($patient_user_id) {
                $formatted_date = date('M d, Y', strtotime($selected_datetime));
                $formatted_time = date('g:i A', strtotime($selected_datetime));
                $message = "Your follow-up appointment has been approved: {$formatted_date} at {$formatted_time}";
                
                $stmt = $pdo->prepare('INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, ?, ?)');
                $stmt->execute([
                    $patient_user_id,
                    $message,
                    'appointment',
                    'unread'
                ]);
            }
            
            echo json_encode(['success' => true, 'message' => 'Follow-up appointment approved and scheduled']);
            
        } elseif ($action === 'decline_followup') {
            $reason = trim($_POST['reason'] ?? '');
            if (empty($reason)) {
                throw new Exception('Decline reason is required');
            }
            
            // Update follow-up status
            $stmt = $pdo->prepare("UPDATE follow_up_appointments SET status = 'declined', notes = CONCAT(COALESCE(notes, ''), '\nDeclined by doctor: ', ?), updated_at = NOW() WHERE id = ?");
            $stmt->execute([$reason, $follow_up_id]);
            
            // Create notification for patient
            $patient_user_id = $follow_up['user_id'] ?? $follow_up['patient_id'] ?? null;
            if ($patient_user_id) {
                $formatted_date = date('M d, Y', strtotime($follow_up['selected_datetime']));
                $formatted_time = date('g:i A', strtotime($follow_up['selected_datetime']));
                $message = "Your follow-up appointment for {$formatted_date} at {$formatted_time} has been declined. Reason: {$reason}";
                
                $stmt = $pdo->prepare('INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, ?, ?)');
                $stmt->execute([
                    $patient_user_id,
                    $message,
                    'appointment',
                    'unread'
                ]);
            }
            
            echo json_encode(['success' => true, 'message' => 'Follow-up appointment declined']);
        }
        
    } else {
        // Handle regular appointments
        $appointment_id = $_POST['appointment_id'] ?? null;
        
        if (!$appointment_id) {
            echo json_encode(['success' => false, 'message' => 'Appointment ID required']);
            exit;
        }
        
        // Verify appointment exists and doctor has access
        $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ? AND doctor_id = ?");
        $stmt->execute([$appointment_id, $doctor_id]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$appointment) {
            echo json_encode(['success' => false, 'message' => 'Appointment not found']);
            exit;
        }
        
        switch ($action) {
            case 'approve':
                $stmt = $pdo->prepare("UPDATE appointments SET status = 'approved', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$appointment_id]);
                
                // Send email notification
                sendAppointmentEmail($appointment_id, 'approved');
                
                echo json_encode(['success' => true, 'message' => 'Appointment approved']);
                break;
                
            case 'decline':
                $reason = $_POST['reason'] ?? '';
                if (empty($reason)) {
                    echo json_encode(['success' => false, 'message' => 'Decline reason is required']);
                    exit;
                }
                $stmt = $pdo->prepare("UPDATE appointments SET status = 'declined', notes = CONCAT(COALESCE(notes, ''), '\nDeclined: ', ?), updated_at = NOW() WHERE id = ?");
                $stmt->execute([$reason, $appointment_id]);
                
                // Send email notification
                sendAppointmentEmail($appointment_id, 'declined');
                
                echo json_encode(['success' => true, 'message' => 'Appointment declined']);
                break;
                
            case 'complete':
                $stmt = $pdo->prepare("UPDATE appointments SET status = 'completed', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$appointment_id]);
                echo json_encode(['success' => true, 'message' => 'Appointment marked as completed']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

