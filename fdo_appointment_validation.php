<?php
session_start();
require 'db.php';
require_once 'admin_helpers_simple.php';

header('Content-Type: application/json');

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'fdo') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$fdo_id = (int) $_SESSION['user']['id'];

// Check if appointment_code column exists
$has_code_column = false;
try {
    $chk = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'appointment_code'");
    $has_code_column = $chk->rowCount() > 0;
} catch (PDOException $e) {}

// Check if validated_at / validated_by_fdo_id exist (validation feature)
$has_validation_columns = false;
try {
    $chk = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'validated_at'");
    $has_validation_columns = $chk->rowCount() > 0;
} catch (PDOException $e) {}

// If validation columns are missing and we're about to need them (POST validate), try to run migration once
if (!$has_validation_columns && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validate') {
    runAppointmentValidationMigration($pdo);
    $chk = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'validated_at'");
    $has_validation_columns = $chk->rowCount() > 0;
}

/**
 * Run the add_appointment_validation migration so validation works without manual SQL.
 * Safe to call multiple times; ignores "already exists" errors.
 */
function runAppointmentValidationMigration(PDO $pdo) {
    try {
        $pdo->exec("ALTER TABLE appointments MODIFY COLUMN status enum('pending','approved','completed','cancelled','rescheduled','declined','validated') DEFAULT 'pending'");
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'validated') === false && strpos($e->getMessage(), 'Duplicate') === false) {
            error_log('Migration (enum): ' . $e->getMessage());
        }
    }
    try {
        $pdo->exec("ALTER TABLE appointments ADD COLUMN validated_at DATETIME NULL DEFAULT NULL AFTER updated_at");
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            error_log('Migration (validated_at): ' . $e->getMessage());
        }
    }
    try {
        $pdo->exec("ALTER TABLE appointments ADD COLUMN validated_by_fdo_id INT(11) NULL DEFAULT NULL AFTER validated_at");
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            error_log('Migration (validated_by_fdo_id): ' . $e->getMessage());
        }
    }
    try {
        $pdo->exec("ALTER TABLE appointments ADD CONSTRAINT fk_appointments_validated_by FOREIGN KEY (validated_by_fdo_id) REFERENCES users(id) ON DELETE SET NULL");
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') === false && strpos($e->getMessage(), 'already exists') === false) {
            error_log('Migration (FK): ' . $e->getMessage());
        }
    }
}

// --- SEARCH BY APPOINTMENT CODE (GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $code = isset($_GET['code']) ? trim($_GET['code']) : '';
    if ($code === '') {
        echo json_encode(['success' => false, 'message' => 'Please enter an appointment code.']);
        exit;
    }
    if (!$has_code_column) {
        echo json_encode(['success' => false, 'message' => 'Appointment codes are not configured.', 'found' => false]);
        exit;
    }

    $select = "a.id, a.appointment_code, a.start_datetime, a.status,
               COALESCE(p.first_name, u.first_name, '') AS first_name,
               COALESCE(p.middle_name, u.middle_name, '') AS middle_name,
               COALESCE(p.last_name, u.last_name, '') AS last_name,
               CONCAT(COALESCE(du.first_name,''), ' ', COALESCE(du.middle_name,''), ' ', COALESCE(du.last_name,'')) AS doctor_name";
    if ($has_validation_columns) {
        $select .= ", a.validated_at, a.validated_by_fdo_id";
    }
    $stmt = $pdo->prepare("
        SELECT $select
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN doctors d ON a.doctor_id = d.id
        LEFT JOIN users du ON d.user_id = du.id
        WHERE a.appointment_code = ?
        LIMIT 1
    ");
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => true, 'found' => false, 'message' => 'No appointment found for this code.']);
        exit;
    }

    $doctor_name = trim(preg_replace('/\s+/', ' ', $row['doctor_name'] ?? ''));
    if ($doctor_name === '') {
        $doctor_name = 'To be assigned';
    }
    $patient_name = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    if ($patient_name === '') {
        $patient_name = '—';
    }

    $appointment_date = $row['start_datetime'] ? date('Y-m-d', strtotime($row['start_datetime'])) : null;
    $today = date('Y-m-d');
    $is_today = ($appointment_date === $today);

    $status = $row['status'] ?? '';
    $already_validated = ($status === 'validated') || ($has_validation_columns && !empty($row['validated_at']));
    $blocked_status = in_array(strtolower($status), ['cancelled', 'completed'], true);
    $can_validate = !$already_validated && !$blocked_status && in_array(strtolower($status), ['pending', 'approved'], true);
    if ($can_validate && $has_validation_columns) {
        $can_validate = $is_today;
    }

    echo json_encode([
        'success' => true,
        'found' => true,
        'appointment' => [
            'id' => (int) $row['id'],
            'appointment_code' => $row['appointment_code'] ?? '',
            'patient_name' => $patient_name,
            'appointment_date' => $row['start_datetime'] ? date('F j, Y', strtotime($row['start_datetime'])) : '—',
            'appointment_time' => $row['start_datetime'] ? date('g:i A', strtotime($row['start_datetime'])) : '—',
            'doctor_name' => $doctor_name,
            'status' => $status,
            'validated_at' => $row['validated_at'] ?? null,
            'can_validate' => $can_validate,
            'already_validated' => $already_validated,
            'blocked_status' => $blocked_status,
            'is_appointment_date' => $is_today,
        ],
    ]);
    exit;
}

// --- VALIDATE APPOINTMENT (POST) ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';
if ($action !== 'validate') {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

if (!$has_validation_columns) {
    echo json_encode(['success' => false, 'message' => 'Validation feature is not configured. Please run the add_appointment_validation migration.']);
    exit;
}

$appointment_id = isset($_POST['appointment_id']) ? (int) $_POST['appointment_id'] : 0;
if ($appointment_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid appointment.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, status, start_datetime, validated_at, validated_by_fdo_id
        FROM appointments
        WHERE id = ?
    ");
    $stmt->execute([$appointment_id]);
    $appt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$appt) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found.']);
        exit;
    }

    $status = $appt['status'] ?? '';
    if (strtolower($status) === 'validated' || !empty($appt['validated_at'])) {
        echo json_encode(['success' => false, 'message' => 'This appointment is already validated.']);
        exit;
    }
    if (in_array(strtolower($status), ['cancelled', 'completed'], true)) {
        echo json_encode(['success' => false, 'message' => 'Cannot validate an appointment that is Cancelled or Completed.']);
        exit;
    }
    if (!in_array(strtolower($status), ['pending', 'approved'], true)) {
        echo json_encode(['success' => false, 'message' => 'This appointment cannot be validated in its current status.']);
        exit;
    }

    $appointment_date = date('Y-m-d', strtotime($appt['start_datetime']));
    $today = date('Y-m-d');
    if ($appointment_date !== $today) {
        echo json_encode(['success' => false, 'message' => 'Validation is only allowed on the appointment date.']);
        exit;
    }

    $previous_status = $status;
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE appointments
        SET status = 'validated', validated_at = NOW(), validated_by_fdo_id = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$fdo_id, $appointment_id]);

    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Update failed.']);
        exit;
    }

    logAppointmentStatusChange(
        $pdo,
        $appointment_id,
        $previous_status,
        'validated',
        'fdo_validation',
        'Appointment validated by FDO at front desk.',
        $fdo_id
    );

    if (function_exists('logAuditEvent')) {
        logAuditEvent('Appointment Validated', 'Appointment', $appointment_id, "FDO validated appointment #{$appointment_id}");
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Appointment validated successfully.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('FDO appointment validation error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function logAppointmentStatusChange($pdo, $appointment_id, $previous_status, $new_status, $reason_for_change, $reason_details, $changed_by_user_id) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO appointment_status_audit
            (appointment_id, previous_status, new_status, reason_for_change, reason_details, changed_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $appointment_id,
            $previous_status,
            $new_status,
            $reason_for_change,
            $reason_details,
            $changed_by_user_id
        ]);
    } catch (PDOException $e) {
        error_log('Appointment status audit log failed: ' . $e->getMessage());
    }
}
