<?php
/**
 * Payatas Resident Verification Helper
 * Ensures only verified residents of Barangay Payatas, Quezon City can access HealthServe services.
 */

if (!defined('RESIDENCY_VERIFICATION_LOADED')) {
    define('RESIDENCY_VERIFICATION_LOADED', true);
}

require_once __DIR__ . '/db.php';

/** Barangay and city allowed for HealthServe */
const RESIDENCY_ALLOWED_BARANGAY = 'Payatas';
const RESIDENCY_ALLOWED_CITY = 'Quezon City';

/**
 * Ensure residency verification tables and user columns exist.
 */
function ensureResidencyVerificationSchema() {
    global $pdo;
    try {
        foreach (['residency_status', 'residency_rejected_reason', 'barangay', 'city'] as $col) {
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE '$col'");
            if ($stmt->rowCount() === 0) {
                if ($col === 'residency_status') {
                    $pdo->exec("ALTER TABLE users ADD COLUMN residency_status ENUM('not_verified','pending','verified','rejected') NOT NULL DEFAULT 'not_verified'");
                } elseif ($col === 'residency_rejected_reason') {
                    $pdo->exec("ALTER TABLE users ADD COLUMN residency_rejected_reason TEXT NULL");
                } elseif ($col === 'barangay') {
                    $pdo->exec("ALTER TABLE users ADD COLUMN barangay VARCHAR(100) NULL");
                } else {
                    $pdo->exec("ALTER TABLE users ADD COLUMN city VARCHAR(100) NULL");
                }
            }
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS residency_verification_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                rejection_reason TEXT NULL,
                reviewed_by INT NULL,
                reviewed_at TIMESTAMP NULL,
                submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_status (user_id, status),
                INDEX idx_pending (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS residency_verification_documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                request_id INT NOT NULL,
                document_type ENUM('government_id','barangay_clearance') NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                original_name VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (request_id) REFERENCES residency_verification_requests(id) ON DELETE CASCADE,
                INDEX idx_request (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS residency_verification_audit_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                request_id INT NOT NULL,
                user_id INT NOT NULL,
                admin_id INT NOT NULL,
                admin_name VARCHAR(255) NULL,
                action ENUM('approved','rejected') NOT NULL,
                reason TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (request_id) REFERENCES residency_verification_requests(id) ON DELETE CASCADE,
                FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_request (request_id),
                INDEX idx_admin (admin_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            return true;
        }
        error_log("Residency verification schema error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get patient's residency status from users table.
 * @param int $user_id
 * @return array { status, rejected_reason, barangay, city }
 */
function getPatientResidencyStatus($user_id) {
    global $pdo;
    $default = ['status' => 'not_verified', 'rejected_reason' => null, 'barangay' => null, 'city' => null];
    try {
        ensureResidencyVerificationSchema();
        $stmt = $pdo->prepare("
            SELECT COALESCE(residency_status, 'not_verified') AS status,
                   residency_rejected_reason AS rejected_reason,
                   barangay, city
            FROM users WHERE id = ?
        ");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $default;
        }
        return [
            'status' => $row['status'] ?? 'not_verified',
            'rejected_reason' => $row['rejected_reason'] ?? null,
            'barangay' => $row['barangay'] ?? null,
            'city' => $row['city'] ?? null,
        ];
    } catch (Throwable $e) {
        error_log("getPatientResidencyStatus: " . $e->getMessage());
        return $default;
    }
}

/**
 * Check if address is within Barangay Payatas, Quezon City.
 */
function isAddressPayatasQuezonCity($barangay, $city) {
    $b = trim((string)$barangay);
    $c = trim((string)$city);
    return (stripos($b, 'Payatas') !== false || $b === 'Payatas') && (stripos($c, 'Quezon City') !== false || $c === 'Quezon City');
}

/**
 * Whether the patient can access restricted services (book, lab, prescriptions).
 */
function isPatientResidencyVerified($user_id) {
    $s = getPatientResidencyStatus($user_id);
    return ($s['status'] === 'verified');
}

/**
 * Whether the user is allowed to submit a new verification (no pending request).
 */
function canSubmitResidencyVerification($user_id) {
    global $pdo;
    try {
        ensureResidencyVerificationSchema();
        $stmt = $pdo->prepare("SELECT id FROM residency_verification_requests WHERE user_id = ? AND status = 'pending' LIMIT 1");
        $stmt->execute([$user_id]);
        return $stmt->fetch() ? false : true;
    } catch (Throwable $e) {
        error_log("canSubmitResidencyVerification: " . $e->getMessage());
        return true;
    }
}

/**
 * Get the current pending or latest verification request for a user.
 */
function getResidencyVerificationRequest($user_id) {
    global $pdo;
    try {
        ensureResidencyVerificationSchema();
        $stmt = $pdo->prepare("
            SELECT * FROM residency_verification_requests
            WHERE user_id = ? ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log("getResidencyVerificationRequest: " . $e->getMessage());
        return null;
    }
}

/**
 * Get documents for a request.
 */
function getResidencyVerificationDocuments($request_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM residency_verification_documents WHERE request_id = ? ORDER BY document_type, id");
        $stmt->execute([$request_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("getResidencyVerificationDocuments: " . $e->getMessage());
        return [];
    }
}

/** Message shown when a restricted action is clicked (unverified/pending) */
function residencyRestrictedMessage() {
    return 'Only verified residents of Barangay Payatas are allowed to access HealthServe services. Please complete your verification.';
}

/** Ensure notifications table has type column (for residency_verification notifications) */
function ensureNotificationsTypeColumn() {
    global $pdo;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'type'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN type VARCHAR(50) NULL AFTER message");
        }
    } catch (PDOException $e) {
        // ignore
    }
}
