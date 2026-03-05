<?php
session_start();
require_once 'db.php';
require_once 'residency_verification_helper.php';
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'];

// Only patients and doctors can upload lab test results
if ($user_role !== 'patient' && $user_role !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

if ($user_role === 'patient' && !isPatientResidencyVerified($user_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only verified residents of Barangay Payatas can request or upload lab results. Please complete your residency verification.']);
    exit();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'upload_result') {
        $lab_request_id = isset($_POST['lab_request_id']) ? (int) $_POST['lab_request_id'] : 0;
        $lab_test_request_id = isset($_POST['lab_test_request_id']) ? (int) $_POST['lab_test_request_id'] : 0;
        $patient_id = isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
        $notes = trim($_POST['notes'] ?? '');
        $use_lab_request = $lab_request_id > 0;
        
        if (!$use_lab_request && $lab_test_request_id <= 0) {
            throw new Exception('Lab request ID or lab test request ID is required');
        }
        
        $lab_request = null;
        $request_patient_id = null;
        $doctor_id_for_request = null;
        $test_name_for_notif = 'Lab tests';
        
        if ($use_lab_request) {
            $stmt = $pdo->prepare("
                SELECT lr.*, lr.patient_id as request_patient_id
                FROM lab_requests lr
                WHERE lr.id = ?
            ");
            $stmt->execute([$lab_request_id]);
            $lab_request = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lab_request) {
                throw new Exception('Lab request not found');
            }
            $request_patient_id = (int) $lab_request['request_patient_id'];
            $doctor_id_for_request = (int) $lab_request['doctor_id'];
            $stmt = $pdo->prepare("SELECT test_name FROM lab_request_tests WHERE lab_request_id = ? LIMIT 3");
            $stmt->execute([$lab_request_id]);
            $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $test_name_for_notif = $names ? implode(', ', $names) : 'Lab tests';
        } else {
            $stmt = $pdo->prepare("
                SELECT ltr.*, ltr.patient_id as request_patient_id
                FROM lab_test_requests ltr
                WHERE ltr.id = ?
            ");
            $stmt->execute([$lab_test_request_id]);
            $lab_request = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lab_request) {
                throw new Exception('Lab test request not found');
            }
            $request_patient_id = (int) $lab_request['request_patient_id'];
            $doctor_id_for_request = (int) $lab_request['doctor_id'];
            $test_name_for_notif = $lab_request['test_name'] ?? 'Lab test';
        }
        
        // Verify access: patient can only upload for their own requests, doctor can upload for any patient they have access to
        if ($user_role === 'patient') {
            if ($request_patient_id != $user_id) {
                $stmt = $pdo->prepare("SELECT created_by_user_id FROM patients WHERE id = ?");
                $stmt->execute([$request_patient_id]);
                $patient_record = $stmt->fetch(PDO::FETCH_ASSOC);
                $is_dependent = $patient_record && !empty($patient_record['created_by_user_id']);
                if (!$is_dependent || $patient_record['created_by_user_id'] != $user_id) {
                    throw new Exception('You do not have permission to upload results for this lab request');
                }
            }
        } elseif ($user_role === 'doctor') {
            $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $doctor_record = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$doctor_record) {
                throw new Exception('Doctor record not found');
            }
            $doctor_id = $doctor_record['id'];
            $access_check = $pdo->prepare("
                SELECT 1 FROM appointments 
                WHERE doctor_id = ? 
                AND status = 'approved'
                AND (
                    (user_id = ? AND patient_id = ?) 
                    OR (user_id = ? AND patient_id IS NULL)
                    OR patient_id = ?
                )
                LIMIT 1
            ");
            $access_check->execute([$doctor_id, $request_patient_id, $request_patient_id, $request_patient_id, $request_patient_id]);
            if (!$access_check->fetch()) {
                throw new Exception('You do not have access to this patient\'s records');
            }
        }
        
        // Handle file upload
        if (!isset($_FILES['lab_result_file']) || $_FILES['lab_result_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload failed. Please select a valid file.');
        }
        
        $file = $_FILES['lab_result_file'];
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx'];
        $max_file_size = 10 * 1024 * 1024; // 10MB
        
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_extensions)) {
            throw new Exception('Invalid file type. Allowed types: PDF, JPG, PNG, GIF, DOC, DOCX');
        }
        
        if ($file['size'] > $max_file_size) {
            throw new Exception('File size exceeds 10MB limit');
        }
        
        // Create upload directory if it doesn't exist
        $upload_dir = __DIR__ . '/uploads/lab_results/';
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception('Failed to create upload directory. Please check folder permissions.');
            }
        }
        
        // Generate unique filename
        $id_for_file = $use_lab_request ? $lab_request_id : $lab_test_request_id;
        $file_name = 'lab_result_' . $id_for_file . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $file_extension;
        $file_path = $upload_dir . $file_name;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            $error = error_get_last();
            throw new Exception('Failed to save uploaded file. ' . ($error ? $error['message'] : 'Please check folder permissions.'));
        }
        
        // Verify file was saved
        if (!file_exists($file_path)) {
            throw new Exception('File was not saved correctly. Please try again.');
        }
        
        // Save relative path for database (use forward slashes for web compatibility)
        $relative_path = 'uploads/lab_results/' . $file_name;
        
        // Get doctor_id if uploader is a doctor
        $doctor_id = null;
        if ($user_role === 'doctor') {
            $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $doctor_record = $stmt->fetch(PDO::FETCH_ASSOC);
            $doctor_id = $doctor_record['id'] ?? null;
        }
        
        // Check if lab_test_results table exists and has lab_request_id column
        try {
            $check_table = $pdo->query("SHOW TABLES LIKE 'lab_test_results'");
            if ($check_table->rowCount() == 0) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `lab_test_results` (
                      `id` int(11) NOT NULL AUTO_INCREMENT,
                      `lab_test_request_id` int(11) DEFAULT NULL,
                      `lab_request_id` int(11) DEFAULT NULL,
                      `patient_id` int(11) NOT NULL,
                      `doctor_id` int(11) DEFAULT NULL,
                      `file_path` varchar(500) NOT NULL,
                      `file_name` varchar(255) NOT NULL,
                      `file_type` varchar(50) DEFAULT NULL,
                      `file_size` int(11) DEFAULT NULL,
                      `uploaded_by` int(11) NOT NULL,
                      `notes` text DEFAULT NULL,
                      `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
                      PRIMARY KEY (`id`),
                      KEY `idx_lab_request` (`lab_test_request_id`),
                      KEY `idx_lab_request_id` (`lab_request_id`),
                      KEY `idx_patient` (`patient_id`),
                      KEY `idx_doctor` (`doctor_id`),
                      KEY `idx_uploaded_by` (`uploaded_by`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            $has_lr_col = $pdo->query("SHOW COLUMNS FROM lab_test_results LIKE 'lab_request_id'");
            if ($has_lr_col->rowCount() == 0) {
                $pdo->exec("ALTER TABLE lab_test_results ADD COLUMN lab_request_id int(11) DEFAULT NULL AFTER lab_test_request_id, ADD KEY idx_lab_request_id (lab_request_id)");
            }
            // Allow lab_test_request_id to be NULL when upload is for lab_requests (old flow)
            $col = $pdo->query("SHOW COLUMNS FROM lab_test_results WHERE Field = 'lab_test_request_id'")->fetch(PDO::FETCH_ASSOC);
            if ($col && (strtoupper($col['Null'] ?? '') === 'NO')) {
                $pdo->exec("ALTER TABLE lab_test_results MODIFY lab_test_request_id int(11) DEFAULT NULL");
            }
        } catch (PDOException $e) {
            error_log("Lab test results table check error: " . $e->getMessage());
            throw new Exception('Database error: Unable to create lab test results table');
        }
        
        $insert_lab_request_id = $use_lab_request ? $lab_request_id : null;
        $insert_lab_test_request_id = $use_lab_request ? null : $lab_test_request_id;
        
        $stmt = $pdo->prepare("
            INSERT INTO lab_test_results 
            (lab_test_request_id, lab_request_id, patient_id, doctor_id, file_path, file_name, file_type, file_size, uploaded_by, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $insert_lab_test_request_id,
            $insert_lab_request_id,
            $request_patient_id,
            $doctor_id,
            $relative_path,
            $file['name'],
            $file_extension,
            $file['size'],
            $user_id,
            $notes
        ]);
        
        if ($use_lab_request) {
            $update_stmt = $pdo->prepare("UPDATE lab_requests SET status = 'completed', updated_at = NOW() WHERE id = ? AND status = 'pending'");
            $update_stmt->execute([$lab_request_id]);
        } else {
            $update_stmt = $pdo->prepare("UPDATE lab_test_requests SET status = 'completed', updated_at = NOW() WHERE id = ? AND status = 'pending'");
            $update_stmt->execute([$lab_test_request_id]);
        }
        
        if ($user_role === 'patient') {
            $notif_message = "Lab test results have been uploaded for: " . $test_name_for_notif;
            $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, 'lab_test', 'unread')");
            $stmt = $pdo->prepare("SELECT user_id FROM doctors WHERE id = ?");
            $stmt->execute([$doctor_id_for_request]);
            $doctor_user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($doctor_user) {
                $notif_stmt->execute([$doctor_user['user_id'], $notif_message]);
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Lab test result uploaded successfully',
            'file_path' => $relative_path,
            'file_name' => $file['name'],
            'file_size' => (int) $file['size'],
            'uploaded_at' => date('M d, Y h:i A')
        ]);
        
    } elseif ($action === 'get_results') {
        $lab_test_request_id = isset($_GET['lab_test_request_id']) ? (int) $_GET['lab_test_request_id'] : 0;
        
        if ($lab_test_request_id <= 0) {
            throw new Exception('Lab test request ID is required');
        }
        
        // Verify access
        $stmt = $pdo->prepare("
            SELECT ltr.*, ltr.patient_id as request_patient_id
            FROM lab_test_requests ltr
            WHERE ltr.id = ?
        ");
        $stmt->execute([$lab_test_request_id]);
        $lab_request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$lab_request) {
            throw new Exception('Lab test request not found');
        }
        
        // Verify access (same logic as upload)
        if ($user_role === 'patient') {
            if ($lab_request['request_patient_id'] != $user_id) {
                $stmt = $pdo->prepare("SELECT created_by_user_id FROM patients WHERE id = ?");
                $stmt->execute([$lab_request['request_patient_id']]);
                $patient_record = $stmt->fetch(PDO::FETCH_ASSOC);
                $is_dependent = $patient_record && !empty($patient_record['created_by_user_id']);
                
                if (!$is_dependent || $patient_record['created_by_user_id'] != $user_id) {
                    throw new Exception('Access denied');
                }
            }
        } elseif ($user_role === 'doctor') {
            $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $doctor_record = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$doctor_record) {
                throw new Exception('Doctor record not found');
            }
            $doctor_id = $doctor_record['id'];
            
            $access_check = $pdo->prepare("
                SELECT 1 FROM appointments 
                WHERE doctor_id = ? 
                AND status = 'approved'
                AND (
                    (user_id = ? AND patient_id = ?) 
                    OR (user_id = ? AND patient_id IS NULL)
                    OR patient_id = ?
                )
                LIMIT 1
            ");
            $access_check->execute([$doctor_id, $lab_request['request_patient_id'], $lab_request['request_patient_id'], $lab_request['request_patient_id'], $lab_request['request_patient_id']]);
            
            if (!$access_check->fetch()) {
                throw new Exception('Access denied');
            }
        }
        
        // Get results
        $stmt = $pdo->prepare("
            SELECT ltr.*, 
                   CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as uploaded_by_name
            FROM lab_test_results ltr
            LEFT JOIN users u ON u.id = ltr.uploaded_by
            WHERE ltr.lab_test_request_id = ?
            ORDER BY ltr.uploaded_at DESC
        ");
        $stmt->execute([$lab_test_request_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'results' => $results
        ]);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

