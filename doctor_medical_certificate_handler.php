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
        case 'generate_certificate':
            $patient_id = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : 0;
            $appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : null;
            $consultation_id = isset($_POST['consultation_id']) ? (int)$_POST['consultation_id'] : null;
            $validity_period_input = isset($_POST['validity_period_days']) ? trim($_POST['validity_period_days']) : '7';
            $certificate_type = isset($_POST['certificate_type']) ? trim($_POST['certificate_type']) : null;
            $certificate_subtype = isset($_POST['certificate_subtype']) ? trim($_POST['certificate_subtype']) : null;
            $fit_status = isset($_POST['fit_status']) ? trim($_POST['fit_status']) : null;
            $custom_expiration_date = isset($_POST['custom_expiration_date']) ? trim($_POST['custom_expiration_date']) : null;
            
            $date_issued = date('Y-m-d');
            $validity_period_days = null;
            $expiration_date = null;
            
            // Handle custom expiration date
            if ($validity_period_input === 'custom' && $custom_expiration_date) {
                $expiration_date = $custom_expiration_date;
                // Calculate validity period days from custom date
                $date1 = new DateTime($date_issued);
                $date2 = new DateTime($expiration_date);
                $diff = $date1->diff($date2);
                $validity_period_days = (int)$diff->days;
            } else {
                // Validate validity period (allow 7, 14, or 30 days)
                $validity_period_days = (int)$validity_period_input;
                if (!in_array($validity_period_days, [7, 14, 30])) {
                    $validity_period_days = 7; // Default to 7 days
                }
                // Calculate expiration date
                $expiration_date = date('Y-m-d', strtotime("+{$validity_period_days} days"));
            }
            
            // Validate certificate type
            $valid_types = ['work_related', 'education', 'travel', 'licensing', 'general'];
            if ($certificate_type && !in_array($certificate_type, $valid_types)) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'Invalid certificate type']);
                exit;
            }
            
            // Validate fit_status (only for work_related certificates)
            if ($certificate_type === 'work_related' && $fit_status && !in_array($fit_status, ['fit', 'unfit'])) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'Invalid fit status']);
                exit;
            }
            
            if ($patient_id <= 0) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'Patient ID is required']);
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
            
            // Ensure medical_certificates table exists with all columns
            // Suppress any warnings from table creation
            @$pdo->exec("
                CREATE TABLE IF NOT EXISTS medical_certificates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    patient_id INT NOT NULL,
                    doctor_id INT NOT NULL,
                    appointment_id INT NULL,
                    consultation_id INT NULL,
                    certificate_type VARCHAR(100) DEFAULT NULL,
                    certificate_subtype VARCHAR(100) DEFAULT NULL,
                    fit_status ENUM('fit', 'unfit') DEFAULT NULL,
                    validity_period_days INT NOT NULL,
                    issued_date DATE NOT NULL,
                    expiration_date DATE NOT NULL,
                    status ENUM('active', 'expired') DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_patient (patient_id),
                    KEY idx_doctor (doctor_id),
                    KEY idx_appointment (appointment_id),
                    KEY idx_consultation (consultation_id),
                    KEY idx_expiration_date (expiration_date),
                    KEY idx_status (status),
                    KEY idx_certificate_type (certificate_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Check and add new columns if they don't exist
            // Use @ to suppress warnings
            try {
                @$pdo->exec("ALTER TABLE medical_certificates ADD COLUMN certificate_type VARCHAR(100) DEFAULT NULL AFTER consultation_id");
            } catch (PDOException $e) {
                // Column already exists, ignore
            }
            try {
                @$pdo->exec("ALTER TABLE medical_certificates ADD COLUMN certificate_subtype VARCHAR(100) DEFAULT NULL AFTER certificate_type");
            } catch (PDOException $e) {
                // Column already exists, ignore
            }
            try {
                @$pdo->exec("ALTER TABLE medical_certificates ADD COLUMN fit_status ENUM('fit', 'unfit') DEFAULT NULL AFTER certificate_subtype");
            } catch (PDOException $e) {
                // Column already exists, ignore
            }
            
            // Insert medical certificate (dates already calculated above)
            $stmt = $pdo->prepare("
                INSERT INTO medical_certificates (
                    patient_id, doctor_id, appointment_id, consultation_id,
                    certificate_type, certificate_subtype, fit_status,
                    validity_period_days, issued_date, expiration_date, status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([
                $patient_id,
                $doctor_id,
                $appointment_id,
                $consultation_id,
                $certificate_type,
                $certificate_subtype,
                $fit_status,
                $validity_period_days,
                $date_issued,
                $expiration_date
            ]);
            
            $certificate_id = $pdo->lastInsertId();
            
            // Clear any output before sending JSON
            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Medical certificate generated successfully',
                'certificate_id' => $certificate_id,
                'issued_date' => $date_issued,
                'expiration_date' => $expiration_date
            ]);
            break;
            
        case 'get_certificates':
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
            
            // Get certificates for this patient
            $stmt = $pdo->prepare("
                SELECT 
                    mc.*,
                    CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as patient_name
                FROM medical_certificates mc
                LEFT JOIN users u ON mc.patient_id = u.id
                WHERE mc.patient_id = ? AND mc.doctor_id = ?
                ORDER BY mc.created_at DESC
            ");
            $stmt->execute([$patient_id, $doctor_id]);
            $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'certificates' => $certificates
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

