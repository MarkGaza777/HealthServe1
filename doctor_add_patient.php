<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $sex = $_POST['sex'] ?? '';
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $civil_status = $_POST['civil_status'] ?? '';
    $philhealth_no = trim($_POST['philhealth_no'] ?? '');
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');
    $emergency_contact_relationship = trim($_POST['emergency_contact_relationship'] ?? '');
    $medical_history = trim($_POST['medical_history'] ?? '');
    
    // Validation
    if (empty($first_name) || empty($last_name) || empty($sex) || empty($date_of_birth) || empty($phone) || empty($address)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields']);
        exit;
    }
    
    try {
        $emergency_contact = '';
        if (!empty($emergency_contact_name) && !empty($emergency_contact_phone)) {
            $relationship = !empty($emergency_contact_relationship) ? $emergency_contact_relationship : 'Contact';
            $emergency_contact = $emergency_contact_name . ' (' . $relationship . ') - ' . $emergency_contact_phone;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO patients (
                first_name, middle_name, last_name, sex, dob, 
                phone, address, civil_status, philhealth_no, 
                emergency_contact, notes, created_by_user_id, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        
        $stmt->execute([
            $first_name, 
            $middle_name ?: null, 
            $last_name, 
            $sex, 
            $date_of_birth,
            $phone, 
            $address, 
            $civil_status ?: null, 
            $philhealth_no ?: null,
            $emergency_contact ?: null, 
            $medical_history ?: null, 
            $_SESSION['user']['id']
        ]);
        
        $patient_id = $pdo->lastInsertId();
        
        // Log patient creation
        require_once 'admin_helpers_simple.php';
        logAuditEvent('Patient Created', 'Patient Record', $patient_id, "Doctor created patient: {$first_name} {$last_name} (ID: {$patient_id})");
        
        echo json_encode([
            'success' => true, 
            'message' => 'Patient successfully registered',
            'patient_id' => $patient_id
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error creating patient: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>


