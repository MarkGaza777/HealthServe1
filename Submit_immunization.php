<?php
session_start();
require 'db.php';

if(empty($_SESSION['user'])) { 
    header('Location: login.php'); 
    exit; 
}

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $parent_name = trim($_POST['parent_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $child_name = trim($_POST['child_name'] ?? '');
    $child_age = intval($_POST['child_age'] ?? 0);
    $selected_date = $_POST['select_date'] ?? '';
    $selected_time = $_POST['select_time'] ?? '';
    $confirm_info = isset($_POST['confirm_info']);
    
    $err = '';
    
    // Validation
    if(empty($parent_name) || empty($address) || empty($mobile) || empty($child_name)) {
        $err = 'All required fields must be filled.';
    } elseif($child_age <= 0 || $child_age > 18) {
        $err = 'Please enter a valid age for the child (0-18 years).';
    } elseif(empty($selected_date) || empty($selected_time)) {
        $err = 'Please select both date and time.';
    } elseif(!$confirm_info) {
        $err = 'Please confirm that the information is correct.';
    } else {
        // Validate that selected date is Wednesday or Friday
        $date = new DateTime($selected_date);
        $dayOfWeek = $date->format('w');
        
        if($dayOfWeek != 3 && $dayOfWeek != 5) { // 3=Wednesday, 5=Friday
            $err = 'Immunization program is only available on Wednesdays and Fridays.';
        } elseif($date < new DateTime('today')) {
            $err = 'Please select a future date.';
        }
    }
    
    if($err) {
        $_SESSION['immunization_error'] = $err;
    } else {
        try {
            // Get the immunization program ID
            $stmt = $pdo->prepare('SELECT id FROM health_programs WHERE program_type = "immunization" AND is_active = 1 LIMIT 1');
            $stmt->execute();
            $program = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if(!$program) {
                // Create the program if it doesn't exist
                $stmt = $pdo->prepare('INSERT INTO health_programs (title, program_type, is_active) VALUES (?, "immunization", 1)');
                $stmt->execute(['Children Immunization Program']);
                $program_id = $pdo->lastInsertId();
            } else {
                $program_id = $program['id'];
            }
            
            // Check if user already registered for this date/time
            $stmt = $pdo->prepare('SELECT id FROM program_registrations WHERE program_id = ? AND user_id = ? AND selected_date = ? AND selected_time = ? AND status != "cancelled"');
            $stmt->execute([$program_id, $_SESSION['user']['id'], $selected_date, $selected_time]);
            
            if($stmt->fetch()) {
                $_SESSION['immunization_error'] = 'You already have a registration for this date and time.';
            } else {
                // Insert registration
                $stmt = $pdo->prepare('INSERT INTO program_registrations (program_id, user_id, parent_name, child_name, child_age, contact_phone, address, selected_date, selected_time, status) VALUES (?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$program_id, $_SESSION['user']['id'], $parent_name, $child_name, $child_age, $mobile, $address, $selected_date, $selected_time, 'confirmed']);
                
                $_SESSION['immunization_success'] = 'Registration successful! Please bring the required documents on ' . date('F j, Y', strtotime($selected_date)) . ' at ' . date('g:i A', strtotime($selected_time)) . '.';
            }
        } catch(Exception $e) {
            $_SESSION['immunization_error'] = 'Registration failed. Please try again.';
        }
    }
}

header('Location: health_tips.php');
exit;
?>