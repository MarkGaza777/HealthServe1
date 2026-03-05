<?php
// Suppress error output for JSON responses
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to catch any unexpected output
if (!ob_get_level()) {
    ob_start();
}

session_start();

// Try to require db.php, but catch any output
try {
    require_once 'db.php';
} catch (Exception $e) {
    // If db.php outputs HTML, we need to handle it
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit;
}

// Clear any output that might have been generated (from db.php or elsewhere)
ob_clean();

header('Content-Type: application/json');

// Check if user is logged in and is a doctor
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    ob_clean();
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
    ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Doctor record not found']);
    exit;
}
$doctor_id = $doctor_record['id'];

try {
    switch ($action) {
        case 'create_referral':
            $patient_id = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : 0;
            $appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : null;
            $consultation_id = isset($_POST['consultation_id']) ? (int)$_POST['consultation_id'] : null;
            $referred_hospital = isset($_POST['referred_hospital']) ? trim($_POST['referred_hospital']) : '';
            $referred_hospital_address = isset($_POST['referred_hospital_address']) ? trim($_POST['referred_hospital_address']) : null;
            $referred_hospital_contact = isset($_POST['referred_hospital_contact']) ? trim($_POST['referred_hospital_contact']) : null;
            $reason_for_referral = isset($_POST['reason_for_referral']) ? trim($_POST['reason_for_referral']) : '';
            $clinical_notes = isset($_POST['clinical_notes']) ? trim($_POST['clinical_notes']) : null;
            
            if ($patient_id <= 0) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'Patient ID is required']);
                exit;
            }
            
            if (empty($referred_hospital)) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'Referred hospital/facility name is required']);
                exit;
            }
            
            if (empty($reason_for_referral)) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'Reason for referral is required']);
                exit;
            }
            
            // Check if doctor has access to this patient (has approved appointment)
            $access_check = $pdo->prepare("
                SELECT 1 FROM appointments 
                WHERE doctor_id = ? 
                AND status IN ('approved', 'completed')
                AND (
                    (user_id = ? AND patient_id = ?) 
                    OR (user_id = ? AND patient_id IS NULL)
                    OR patient_id = ?
                )
                LIMIT 1
            ");
            $access_check->execute([$doctor_id, $patient_id, $patient_id, $patient_id, $patient_id]);
            
            if (!$access_check->fetch()) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'You do not have access to this patient\'s records']);
                exit;
            }
            
            // Ensure referrals table exists
            @$pdo->exec("
                CREATE TABLE IF NOT EXISTS referrals (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    patient_id INT NOT NULL,
                    doctor_id INT NOT NULL,
                    appointment_id INT NULL,
                    consultation_id INT NULL,
                    referred_hospital VARCHAR(255) NOT NULL,
                    referred_hospital_address TEXT NULL,
                    referred_hospital_contact VARCHAR(100) NULL,
                    reason_for_referral TEXT NOT NULL,
                    clinical_notes TEXT NULL,
                    referral_date DATE NOT NULL,
                    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_patient (patient_id),
                    KEY idx_doctor (doctor_id),
                    KEY idx_appointment (appointment_id),
                    KEY idx_consultation (consultation_id),
                    KEY idx_referral_date (referral_date),
                    KEY idx_status (status),
                    CONSTRAINT fk_referrals_doctor FOREIGN KEY (doctor_id) REFERENCES doctors (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $referral_date = date('Y-m-d');
            
            // Insert referral
            $stmt = $pdo->prepare("
                INSERT INTO referrals (
                    patient_id, doctor_id, appointment_id, consultation_id,
                    referred_hospital, referred_hospital_address, referred_hospital_contact,
                    reason_for_referral, clinical_notes, referral_date, status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([
                $patient_id,
                $doctor_id,
                $appointment_id,
                $consultation_id,
                $referred_hospital,
                $referred_hospital_address,
                $referred_hospital_contact,
                $reason_for_referral,
                $clinical_notes,
                $referral_date
            ]);
            
            $referral_id = $pdo->lastInsertId();
            
            // Clear any output before sending JSON
            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Referral created successfully',
                'referral_id' => $referral_id,
                'referral_date' => $referral_date
            ]);
            break;
            
        case 'get_referrals':
            $patient_id = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : 0;
            
            if ($patient_id <= 0) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'Patient ID is required']);
                exit;
            }
            
            // Check access
            $access_check = $pdo->prepare("
                SELECT 1 FROM appointments 
                WHERE doctor_id = ? 
                AND status IN ('approved', 'completed')
                AND (
                    (user_id = ? AND patient_id = ?) 
                    OR (user_id = ? AND patient_id IS NULL)
                    OR patient_id = ?
                )
                LIMIT 1
            ");
            $access_check->execute([$doctor_id, $patient_id, $patient_id, $patient_id, $patient_id]);
            
            if (!$access_check->fetch()) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'You do not have access to this patient\'s records']);
                exit;
            }
            
            // Get referrals for this patient
            $stmt = $pdo->prepare("
                SELECT 
                    r.*,
                    CASE 
                        WHEN pt.id IS NOT NULL THEN CONCAT(COALESCE(pt.first_name, ''), ' ', COALESCE(pt.middle_name, ''), ' ', COALESCE(pt.last_name, ''))
                        WHEN u.id IS NOT NULL THEN CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, ''))
                        ELSE 'Unknown Patient'
                    END as patient_name
                FROM referrals r
                LEFT JOIN users u ON r.patient_id = u.id
                LEFT JOIN patients pt ON r.patient_id = pt.id
                WHERE r.patient_id = ? AND r.doctor_id = ?
                ORDER BY r.created_at DESC
            ");
            $stmt->execute([$patient_id, $doctor_id]);
            $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'referrals' => $referrals
            ]);
            break;
            
        default:
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit;
}

// End output buffering if it was started
if (ob_get_level() > 0) {
    ob_end_flush();
}

