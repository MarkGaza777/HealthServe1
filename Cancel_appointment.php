<?php
session_start();
require 'db.php';

if(empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'patient') { 
    header('Location: login.php'); 
    exit; 
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['appointment_id'])) {
    $appointment_id = intval($_POST['appointment_id']);
    $user_id = $_SESSION['user']['id'];
    
    // Verify the appointment belongs to the user and is still pending
    $stmt = $pdo->prepare('SELECT id, status FROM appointments WHERE id = ? AND user_id = ? AND status = "pending"');
    $stmt->execute([$appointment_id, $user_id]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($appointment) {
        // Update appointment status to declined (cancelled by user)
        $stmt = $pdo->prepare('UPDATE appointments SET status = "declined" WHERE id = ?');
        $stmt->execute([$appointment_id]);
        
        $_SESSION['booking_success'] = 'Appointment cancelled successfully.';
    } else {
        $_SESSION['booking_error'] = 'Unable to cancel appointment. It may have already been processed.';
    }
} else {
    $_SESSION['booking_error'] = 'Invalid request.';
}

header('Location: user_appointments.php');
exit;
?>