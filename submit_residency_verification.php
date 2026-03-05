<?php
/**
 * Submit Payatas Residency Verification
 * Accepts uploaded ID images, validates (jpg/png), saves to uploads/residency_ids/,
 * inserts verification data into the database.
 */
session_start();
require_once __DIR__ . '/db.php';

// Only patients
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'patient') {
    header('Location: Login.php');
    exit;
}

$user_id = (int)$_SESSION['user']['id'];

function redirect_profile($param, $msg = '') {
    $url = 'user_profile.php';
    if ($param) $url .= '?' . $param;
    if ($msg) $url .= ($param ? '&' : '?') . 'msg=' . urlencode($msg);
    $url .= '#verification';
    header('Location: ' . $url);
    exit;
}

// Ensure tables/columns exist
try {
    $pdo->query("SELECT residency_status FROM users LIMIT 1");
} catch (PDOException $e) {
    @$pdo->exec("ALTER TABLE users ADD COLUMN residency_status ENUM('not_verified','pending','verified','rejected') NOT NULL DEFAULT 'not_verified'");
    @$pdo->exec("ALTER TABLE users ADD COLUMN residency_rejected_reason TEXT NULL");
}
try {
    $pdo->query("SELECT id FROM residency_verification_requests LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS residency_verification_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
            submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
try {
    $pdo->query("SELECT id FROM residency_verification_documents LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS residency_verification_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            document_type ENUM('government_id','barangay_clearance') NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            original_name VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (request_id) REFERENCES residency_verification_requests(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_profile('');
}

// Check no pending request
try {
    $stmt = $pdo->prepare("SELECT id FROM residency_verification_requests WHERE user_id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$user_id]);
    if ($stmt->fetch()) {
        redirect_profile('verification=pending');
    }
} catch (PDOException $e) { /* tables may not exist yet */ }

// Require government ID file
$gov = $_FILES['government_id'] ?? null;
if (!$gov || $gov['error'] !== UPLOAD_ERR_OK) {
    redirect_profile('verification=error', 'Please upload a valid government-issued ID (required).');
}

$allowed_ext = ['jpg', 'jpeg', 'png'];
$max_bytes = 5 * 1024 * 1024; // 5 MB
$ext = strtolower(pathinfo($gov['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_ext)) {
    redirect_profile('verification=error', 'Government ID must be JPG or PNG.');
}
if ($gov['size'] > $max_bytes) {
    redirect_profile('verification=error', 'File too large (max 5MB).');
}

// Optional barangay clearance
$barangay = $_FILES['barangay_clearance'] ?? null;
if ($barangay && $barangay['error'] === UPLOAD_ERR_OK) {
    $ext2 = strtolower(pathinfo($barangay['name'], PATHINFO_EXTENSION));
    if (!in_array($ext2, $allowed_ext) || $barangay['size'] > $max_bytes) {
        redirect_profile('verification=error', 'Barangay clearance must be JPG or PNG, max 5MB.');
    }
}

// Save directory: uploads/residency_ids/{user_id}/
$base_dir = __DIR__ . '/uploads/residency_ids';
if (!is_dir($base_dir)) {
    mkdir($base_dir, 0755, true);
}
$user_dir = $base_dir . '/' . $user_id;
if (!is_dir($user_dir)) {
    mkdir($user_dir, 0750, true);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO residency_verification_requests (user_id, status) VALUES (?, 'pending')");
    $stmt->execute([$user_id]);
    $request_id = (int)$pdo->lastInsertId();

    $save_file = function ($file, $type) use ($request_id, $user_id, $user_dir, $pdo) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $name = $type . '_' . $request_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $path = $user_dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            throw new Exception('Failed to save file');
        }
        $relative = 'uploads/residency_ids/' . $user_id . '/' . $name;
        $stmt = $pdo->prepare("INSERT INTO residency_verification_documents (request_id, document_type, file_path, original_name) VALUES (?, ?, ?, ?)");
        $stmt->execute([$request_id, $type, $relative, $file['name']]);
    };

    $save_file($gov, 'government_id');
    if ($barangay && $barangay['error'] === UPLOAD_ERR_OK) {
        $save_file($barangay, 'barangay_clearance');
    }

    $stmt = $pdo->prepare("UPDATE users SET residency_status = 'pending', residency_rejected_reason = NULL WHERE id = ?");
    $stmt->execute([$user_id]);

    $pdo->commit();

    // Notify all admins about the new residency verification request
    try {
        $name_stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $name_stmt->execute([$user_id]);
        $patient_name = $name_stmt->fetchColumn() ?: 'A patient';
        $admin_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin'");
        $admin_stmt->execute();
        $admins = $admin_stmt->fetchAll(PDO::FETCH_ASSOC);
        $msg = "New residency verification request from " . $patient_name . ". Please review.";
        $check_type = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'type'");
        $has_type = $check_type->rowCount() > 0;
        if ($has_type) {
            $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, 'residency_verification', 'unread')");
            foreach ($admins as $admin) {
                $notif_stmt->execute([$admin['id'], $msg]);
            }
        } else {
            $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, status) VALUES (?, ?, 'unread')");
            foreach ($admins as $admin) {
                $notif_stmt->execute([$admin['id'], $msg]);
            }
        }
    } catch (Exception $e) {
        error_log('submit_residency_verification admin notify: ' . $e->getMessage());
    }

    redirect_profile('verification=submitted');
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('submit_residency_verification: ' . $e->getMessage());
    redirect_profile('verification=error', 'Upload failed. Please try again.');
}
