<?php
/**
 * Simplified Admin Settings API Handler
 */

session_start();
require_once 'db.php';
require_once 'admin_helpers_simple.php';

header('Content-Type: application/json');

// Check admin access
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$admin_id = $_SESSION['user']['id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'update_profile':
            handleUpdateProfile($admin_id);
            break;
            
        case 'update_health_center':
            handleUpdateHealthCenter($admin_id);
            break;
            
        case 'change_password':
            handleChangePassword($admin_id);
            break;
            
        case 'toggle_maintenance':
            handleToggleMaintenance($admin_id);
            break;
            
        case 'create_backup':
            handleCreateBackup($admin_id);
            break;
            
        case 'download_backup':
            handleDownloadBackup($admin_id);
            break;
            
        case 'get_system_usage':
            handleGetSystemUsage();
            break;
            
        case 'get_backups':
            handleGetBackups();
            break;
            
        case 'upload_profile_picture':
            handleUploadProfilePicture($admin_id);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit;
}

function handleUpdateProfile($admin_id) {
    global $pdo;
    
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact_no = trim($_POST['contact_no'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    if (empty($first_name) || empty($last_name)) {
        echo json_encode(['success' => false, 'message' => 'First name and last name are required']);
        exit;
    }
    
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET first_name = ?, last_name = ?, middle_name = ?, 
                email = ?, contact_no = ?, address = ?
            WHERE id = ? AND role = 'admin'
        ");
        $stmt->execute([$first_name, $last_name, $middle_name, $email, $contact_no, $address, $admin_id]);
        
        logAuditEvent('Profile Updated', 'user', $admin_id, 'Admin updated profile information');
        
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleUpdateHealthCenter($admin_id) {
    require_once 'admin_helpers_simple.php';
    
    // Validate required fields
    $center_name = trim($_POST['center_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $email = trim($_POST['center_email'] ?? '');
    $hours = trim($_POST['hours'] ?? '');
    
    // Check for empty required fields
    if (empty($center_name)) {
        echo json_encode(['success' => false, 'message' => 'Health center name is required']);
        exit;
    }
    
    if (empty($address)) {
        echo json_encode(['success' => false, 'message' => 'Address is required']);
        exit;
    }
    
    if (empty($contact)) {
        echo json_encode(['success' => false, 'message' => 'Contact number is required']);
        exit;
    }
    
    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Email is required']);
        exit;
    }
    
    if (empty($hours)) {
        echo json_encode(['success' => false, 'message' => 'Operating hours are required']);
        exit;
    }
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit;
    }
    
    $settings = [
        'health_center_name' => $center_name,
        'health_center_address' => $address,
        'health_center_contact' => $contact,
        'health_center_email' => $email,
        'health_center_operating_hours' => $hours
    ];
    
    $saved_settings = [];
    $errors = [];
    
    try {
        // Save all settings and collect any errors
        foreach ($settings as $key => $value) {
            try {
                setSystemSetting($key, $value, $admin_id);
                $saved_settings[] = $key;
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
                error_log("Failed to save setting '$key': " . $e->getMessage());
            }
        }
        
        // If any settings failed to save, return error
        if (!empty($errors)) {
            $error_message = implode('; ', $errors);
            echo json_encode([
                'success' => false, 
                'message' => 'Error updating health center information: ' . $error_message
            ]);
            exit;
        }
        
        // All settings saved successfully
        // Get the updated health center info to return
        $health_center = getHealthCenterInfo();
        
        // Log the update
        try {
            logAuditEvent('Health Center Info Updated', 'system', null, 'Admin updated health center information');
        } catch (Exception $e) {
            // Don't fail the whole operation if audit logging fails
            error_log("Failed to log audit event: " . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Health center information updated successfully',
            'health_center' => $health_center
        ]);
    } catch (Exception $e) {
        error_log("Error updating health center info: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Error updating health center information: ' . $e->getMessage()
        ]);
    }
}

function handleChangePassword($admin_id) {
    global $pdo;
    
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        echo json_encode(['success' => false, 'message' => 'All password fields are required']);
        exit;
    }
    
    if ($new_password !== $confirm_password) {
        echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
        exit;
    }
    
    if (strlen($new_password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long']);
        exit;
    }
    
    try {
        // Verify current password
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? AND role = 'admin'");
        $stmt->execute([$admin_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !password_verify($current_password, $user['password_hash'])) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
            exit;
        }
        
        // Update password
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$new_hash, $admin_id]);
        
        logAuditEvent('Password Changed', 'security', $admin_id, 'Admin changed password');
        
        echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleToggleMaintenance($admin_id) {
    $enabled = isset($_POST['enabled']) ? (int)$_POST['enabled'] : 0;
    
    try {
        setSystemSetting('maintenance_mode', $enabled, $admin_id);
        logAuditEvent('Maintenance Mode ' . ($enabled ? 'Enabled' : 'Disabled'), 'system', null);
        echo json_encode(['success' => true, 'message' => 'Maintenance mode ' . ($enabled ? 'enabled' : 'disabled')]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

function handleCreateBackup($admin_id) {
    global $pdo;
    
    try {
        // Create backup directory if it doesn't exist
        $backup_dir = __DIR__ . '/backups';
        if (!is_dir($backup_dir)) {
            if (!mkdir($backup_dir, 0755, true)) {
                throw new Exception('Failed to create backup directory');
            }
        }
        
        // Generate filename
        $filename = 'backup_' . date('Y-m-d_His') . '.sql';
        $filepath = $backup_dir . '/' . $filename;
        
        // Get database name
        require_once 'db.php';
        global $DB_NAME;
        
        // Open file for writing
        $handle = fopen($filepath, 'w');
        if (!$handle) {
            throw new Exception('Failed to create backup file');
        }
        
        // Write SQL header
        fwrite($handle, "-- HealthServe Database Backup\n");
        fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Database: " . $DB_NAME . "\n\n");
        fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
        fwrite($handle, "SET AUTOCOMMIT = 0;\n");
        fwrite($handle, "START TRANSACTION;\n");
        fwrite($handle, "SET time_zone = \"+00:00\";\n\n");
        
        // Get all tables
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($tables)) {
            fclose($handle);
            unlink($filepath);
            throw new Exception('No tables found in database');
        }
        
        // Backup each table
        foreach ($tables as $table) {
            // Skip backups table to avoid recursion
            if ($table === 'backups') {
                continue;
            }
            
            // Get table structure
            fwrite($handle, "\n-- Table structure for table `{$table}`\n");
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
            
            $create_table = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            if ($create_table) {
                fwrite($handle, $create_table['Create Table'] . ";\n\n");
            }
            
            // Get table data
            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($rows)) {
                fwrite($handle, "-- Dumping data for table `{$table}`\n");
                
                // Get column names
                $columns = array_keys($rows[0]);
                $column_list = '`' . implode('`, `', $columns) . '`';
                
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } else {
                            // Escape the value properly
                            $escaped = $pdo->quote($value);
                            $values[] = $escaped;
                        }
                    }
                    $values_str = implode(', ', $values);
                    fwrite($handle, "INSERT INTO `{$table}` ({$column_list}) VALUES ({$values_str});\n");
                }
                fwrite($handle, "\n");
            }
        }
        
        // Write footer
        fwrite($handle, "COMMIT;\n");
        fwrite($handle, "SET time_zone = \"+08:00\";\n");
        
        fclose($handle);
        
        // Verify file was created
        if (!file_exists($filepath)) {
            throw new Exception('Backup file was not created');
        }
        
        $file_size = filesize($filepath);
        
        if ($file_size === 0) {
            unlink($filepath);
            throw new Exception('Backup file is empty');
        }
        
        // Save backup metadata to database
        $stmt = $pdo->prepare("
            INSERT INTO backups (filename, file_path, file_size, created_by)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$filename, $filepath, $file_size, $admin_id]);
        
        $backup_id = $pdo->lastInsertId();
        
        logAuditEvent('Backup Created', 'backup', $backup_id, "Database backup created: $filename (" . formatBytes($file_size) . ")");
        
        echo json_encode([
            'success' => true,
            'message' => 'Backup created successfully!',
            'filename' => $filename,
            'size' => formatBytes($file_size),
            'backup_id' => $backup_id
        ]);
    } catch (PDOException $e) {
        if (isset($handle) && is_resource($handle)) {
            fclose($handle);
        }
        if (isset($filepath) && file_exists($filepath)) {
            @unlink($filepath);
        }
        error_log("Backup error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        if (isset($handle) && is_resource($handle)) {
            fclose($handle);
        }
        if (isset($filepath) && file_exists($filepath)) {
            @unlink($filepath);
        }
        error_log("Backup error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Backup failed: ' . $e->getMessage()]);
    }
}

function handleDownloadBackup($admin_id) {
    global $pdo;
    
    $backup_id = (int)($_GET['backup_id'] ?? 0);
    
    if ($backup_id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid backup ID']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM backups WHERE id = ?");
        $stmt->execute([$backup_id]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$backup) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Backup record not found']);
            exit;
        }
        
        // Check if file exists
        if (!file_exists($backup['file_path'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Backup file not found on server']);
            exit;
        }
        
        logAuditEvent('Backup Downloaded', 'backup', $backup_id, "Downloaded backup: {$backup['filename']}");
        
        // Send file for download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($backup['filename']) . '"');
        header('Content-Length: ' . filesize($backup['file_path']));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        // Clear any output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        readfile($backup['file_path']);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

function handleGetAuditLogs() {
    $limit = (int)($_GET['limit'] ?? 100);
    $offset = (int)($_GET['offset'] ?? 0);
    
    // Get filter parameters
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $role_filter = $_GET['role'] ?? null;
    
    // Validate date format if provided
    if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $date_from = null;
    }
    if ($date_to && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $date_to = null;
    }
    
    // Get logs with filters
    $logs = getAuditLogs($limit, $offset, $date_from, $date_to, $role_filter);
    $total = getAuditLogsCount($date_from, $date_to, $role_filter);
    
    echo json_encode([
        'success' => true, 
        'logs' => $logs,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ]);
}

function handleExportAuditLogs() {
    // Get filter parameters for export
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $role_filter = $_GET['role'] ?? null;
    
    // Validate date format if provided
    if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $date_from = null;
    }
    if ($date_to && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $date_to = null;
    }
    
    // Get all logs matching filters (up to 50k for export)
    $logs = getAuditLogs(50000, 0, $date_from, $date_to, $role_filter);
    
    // Generate CSV
    $filename = 'audit_logs_' . date('Y-m-d_His');
    if ($date_from || $date_to) {
        $filename .= '_filtered';
    }
    $filename .= '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Write CSV header
    fputcsv($output, ['ID', 'User', 'Role', 'Action', 'Entity Type', 'Entity ID', 'Details', 'IP Address', 'Timestamp']);
    
    // Write log data
    foreach ($logs as $log) {
        $user_name = $log['user_name'] ?? trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?: $log['username'] ?? 'System';
        
        fputcsv($output, [
            $log['id'],
            $user_name,
            $log['user_role'] ?? '',
            $log['action'],
            $log['entity_type'] ?? '',
            $log['entity_id'] ?? '',
            $log['details'] ?? '',
            $log['ip_address'] ?? '',
            $log['created_at']
        ]);
    }
    
    fclose($output);
    exit;
}

function handleGetSystemUsage() {
    // Prevent caching - always return fresh data
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Get filter parameters
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    
    // Validate date format if provided
    if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $date_from = null;
    }
    if ($date_to && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $date_to = null;
    }
    
    // Always fetch fresh data from database (no caching)
    $stats = getSystemUsageStats($date_from, $date_to);
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'timestamp' => time() // Include timestamp to ensure freshness
    ]);
}

function handleGetBackups() {
    $backups = getBackups(50);
    echo json_encode(['success' => true, 'backups' => $backups]);
}

function handleUploadProfilePicture($admin_id) {
    global $pdo;
    
    // Check if photo_path column exists, if not add it
    try {
        $checkColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'photo_path'");
        if ($checkColumn->rowCount() == 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN photo_path VARCHAR(255) DEFAULT NULL");
        }
    } catch(PDOException $e) {
        // Column might already exist, continue
    }
    
    if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error occurred']);
        exit;
    }
    
    $file = $_FILES['profile_picture'];
    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
    $max_bytes = 5 * 1024 * 1024; // 5 MB
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowed_ext)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Please upload JPEG, PNG, or GIF image.']);
        exit;
    }
    
    if ($file['size'] > $max_bytes) {
        echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit.']);
        exit;
    }
    
    try {
        // Ensure upload directory exists
        $uploadDir = __DIR__ . '/uploads/profile_pictures';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Get old photo path to delete it later
        $stmt = $pdo->prepare('SELECT photo_path FROM users WHERE id = ?');
        $stmt->execute([$admin_id]);
        $oldPhoto = $stmt->fetchColumn();
        
        // Generate unique filename
        $newName = 'admin_' . $admin_id . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dst = $uploadDir . '/' . $newName;
        
        if (move_uploaded_file($file['tmp_name'], $dst)) {
            $photo_path = 'uploads/profile_pictures/' . $newName;
            
            // Update database
            $stmt = $pdo->prepare('UPDATE users SET photo_path = ? WHERE id = ?');
            $stmt->execute([$photo_path, $admin_id]);
            
            // Delete old photo if it exists
            if ($oldPhoto && file_exists($oldPhoto)) {
                @unlink($oldPhoto);
            }
            
            logAuditEvent('Profile Picture Updated', 'user', $admin_id, 'Admin updated profile picture');
            
            echo json_encode([
                'success' => true,
                'message' => 'Profile picture updated successfully!',
                'photo_path' => $photo_path
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to upload image. Please try again.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

