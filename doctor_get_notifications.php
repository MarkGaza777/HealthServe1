<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Check if user is logged in and is a doctor
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user']['id'];
$action = $_GET['action'] ?? 'fetch';
$filter = $_GET['filter'] ?? 'active'; // 'active', 'archived'

try {
    // First, automatically mark announcements as expired if their end_date has passed
    $now = date('Y-m-d H:i:s');
    $expire_stmt = $pdo->prepare("
        UPDATE announcements 
        SET status = 'expired' 
        WHERE status != 'expired' 
        AND end_date IS NOT NULL 
        AND end_date <= ?
    ");
    $expire_stmt->execute([$now]);
    
    // Get the doctor_id from doctors table using user_id
    $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doctor) {
        echo json_encode(['success' => true, 'notifications' => []]);
        exit;
    }
    
    $doctor_id = $doctor['id'];
    
    // Check if doctor_notification_preferences table exists, create if it doesn't
    $has_prefs_table = false;
    try {
        $test_stmt = $pdo->query("SHOW TABLES LIKE 'doctor_notification_preferences'");
        $has_prefs_table = $test_stmt->rowCount() > 0;
        
        // If table doesn't exist, create it
        if (!$has_prefs_table) {
            try {
                // Try to create with foreign key constraint
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `doctor_notification_preferences` (
                      `id` int(11) NOT NULL AUTO_INCREMENT,
                      `user_id` int(11) NOT NULL COMMENT 'Doctor user_id',
                      `notification_type` varchar(50) NOT NULL COMMENT 'Type: appointment or announcement',
                      `reference_id` int(11) NOT NULL COMMENT 'appointment_id or announcement_id',
                      `is_archived` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether archived (1) or not (0)',
                      `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether deleted (1) or not (0)',
                      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                      PRIMARY KEY (`id`),
                      UNIQUE KEY `idx_user_type_ref` (`user_id`, `notification_type`, `reference_id`),
                      KEY `idx_user_archived` (`user_id`, `is_archived`),
                      KEY `idx_user_deleted` (`user_id`, `is_deleted`),
                      CONSTRAINT `fk_doctor_notif_prefs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks archived/deleted doctor notifications'
                ");
                $has_prefs_table = true;
            } catch (PDOException $e) {
                // If foreign key constraint fails, try without it
                if (strpos($e->getMessage(), 'foreign key') !== false || strpos($e->getMessage(), 'FOREIGN KEY') !== false) {
                    try {
                        $pdo->exec("
                            CREATE TABLE IF NOT EXISTS `doctor_notification_preferences` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `user_id` int(11) NOT NULL COMMENT 'Doctor user_id',
                              `notification_type` varchar(50) NOT NULL COMMENT 'Type: appointment or announcement',
                              `reference_id` int(11) NOT NULL COMMENT 'appointment_id or announcement_id',
                              `is_archived` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether archived (1) or not (0)',
                              `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether deleted (1) or not (0)',
                              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                              PRIMARY KEY (`id`),
                              UNIQUE KEY `idx_user_type_ref` (`user_id`, `notification_type`, `reference_id`),
                              KEY `idx_user_archived` (`user_id`, `is_archived`),
                              KEY `idx_user_deleted` (`user_id`, `is_deleted`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks archived/deleted doctor notifications'
                        ");
                        $has_prefs_table = true;
                    } catch (PDOException $e2) {
                        error_log("Error creating doctor_notification_preferences table (without FK): " . $e2->getMessage());
                    }
                } else {
                    error_log("Error creating doctor_notification_preferences table: " . $e->getMessage());
                }
            }
        }
    } catch (PDOException $e) {
        // Table creation failed, log error but continue
        error_log("Error creating doctor_notification_preferences table: " . $e->getMessage());
    }
    
    // Get archived/deleted preferences if table exists
    // Always fetch all preferences to properly filter notifications
    $archived_map = [];
    $deleted_map = [];
    if ($has_prefs_table) {
        $pref_stmt = $pdo->prepare("
            SELECT notification_type, reference_id, is_archived, is_deleted 
            FROM doctor_notification_preferences 
            WHERE user_id = ?
        ");
        $pref_stmt->execute([$user_id]);
        $prefs = $pref_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($prefs as $pref) {
            $key = $pref['notification_type'] . '_' . $pref['reference_id'];
            if (isset($pref['is_archived']) && $pref['is_archived'] == 1) {
                $archived_map[$key] = true;
            }
            if (isset($pref['is_deleted']) && $pref['is_deleted'] == 1) {
                $deleted_map[$key] = true;
            }
        }
    }
    
    $notifications = [];
    
    if ($filter === 'active' || $filter === 'archived') {
        // 1. Get approved appointments assigned to this doctor
        // For archived filter, get all appointments (past and future) that are archived
        // For active filter, get future appointments that are not archived
        
        // Build list of archived appointment IDs for filtering
        $archived_appointment_ids = [];
        foreach ($archived_map as $key => $val) {
            if (strpos($key, 'appointment_') === 0) {
                $id = intval(str_replace('appointment_', '', $key));
                if ($id > 0) {
                    $archived_appointment_ids[] = $id;
                }
            }
        }
        
        if ($filter === 'archived') {
            // Get all appointments (past and future) that are archived
            if (empty($archived_appointment_ids)) {
                // No archived appointments
                $appointments = [];
            } else {
                $placeholders = implode(',', array_fill(0, count($archived_appointment_ids), '?'));
                $stmt = $pdo->prepare("
                    SELECT 
                        a.id as appointment_id,
                        a.start_datetime,
                        a.status,
                        a.created_at,
                        COALESCE(
                            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')),
                            CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.middle_name, ''), ' ', COALESCE(p.last_name, ''))
                        ) as patient_name
                    FROM appointments a
                    LEFT JOIN users u ON a.user_id = u.id AND u.role = 'patient'
                    LEFT JOIN patients p ON a.patient_id = p.id
                    WHERE a.doctor_id = ?
                    AND a.status = 'approved'
                    AND a.id IN ($placeholders)
                    ORDER BY a.start_datetime DESC
                    LIMIT 50
                ");
                $stmt->execute(array_merge([$doctor_id], $archived_appointment_ids));
                $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            // Active filter - get future appointments that are NOT archived
            if (empty($archived_appointment_ids)) {
                // No archived appointments, get all future appointments
                $stmt = $pdo->prepare("
                    SELECT 
                        a.id as appointment_id,
                        a.start_datetime,
                        a.status,
                        a.created_at,
                        COALESCE(
                            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')),
                            CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.middle_name, ''), ' ', COALESCE(p.last_name, ''))
                        ) as patient_name
                    FROM appointments a
                    LEFT JOIN users u ON a.user_id = u.id AND u.role = 'patient'
                    LEFT JOIN patients p ON a.patient_id = p.id
                    WHERE a.doctor_id = ?
                    AND a.status = 'approved'
                    AND a.start_datetime >= NOW()
                    ORDER BY a.start_datetime ASC
                    LIMIT 50
                ");
                $stmt->execute([$doctor_id]);
                $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Exclude archived appointments
                $placeholders = implode(',', array_fill(0, count($archived_appointment_ids), '?'));
                $stmt = $pdo->prepare("
                    SELECT 
                        a.id as appointment_id,
                        a.start_datetime,
                        a.status,
                        a.created_at,
                        COALESCE(
                            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')),
                            CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.middle_name, ''), ' ', COALESCE(p.last_name, ''))
                        ) as patient_name
                    FROM appointments a
                    LEFT JOIN users u ON a.user_id = u.id AND u.role = 'patient'
                    LEFT JOIN patients p ON a.patient_id = p.id
                    WHERE a.doctor_id = ?
                    AND a.status = 'approved'
                    AND a.start_datetime >= NOW()
                    AND a.id NOT IN ($placeholders)
                    ORDER BY a.start_datetime ASC
                    LIMIT 50
                ");
                $stmt->execute(array_merge([$doctor_id], $archived_appointment_ids));
                $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        
        foreach ($appointments as $appt) {
            $key = 'appointment_' . $appt['appointment_id'];
            
            // Skip if deleted
            if (isset($deleted_map[$key])) {
                continue;
            }
            
            // Filter by archive status
            $is_archived = isset($archived_map[$key]);
            if (($filter === 'active' && $is_archived) || ($filter === 'archived' && !$is_archived)) {
                continue;
            }
            
            $dt = new DateTime($appt['start_datetime']);
            $dateStr = $dt->format('F j, Y');
            $timeStr = $dt->format('g:i A');
            $patientName = trim($appt['patient_name']) ?: 'Unknown Patient';
            
            $notifications[] = [
                'type' => 'appointment',
                'title' => 'New Appointment Approved',
                'message' => "{$patientName} has an approved appointment on {$dateStr} at {$timeStr}",
                'patient_name' => $patientName,
                'date' => $dateStr,
                'time' => $timeStr,
                'appointment_id' => $appt['appointment_id'],
                'created_at' => $appt['created_at'],
                'is_archived' => $is_archived
            ];
        }
        
        // 2. Get announcements (approved announcements visible to doctors)
        // Build list of archived announcement IDs for filtering
        $archived_announcement_ids = [];
        foreach ($archived_map as $key => $val) {
            if (strpos($key, 'announcement_') === 0) {
                $id = intval(str_replace('announcement_', '', $key));
                if ($id > 0) {
                    $archived_announcement_ids[] = $id;
                }
            }
        }
        
        if ($filter === 'archived') {
            // Get all announcements that are archived
            if (empty($archived_announcement_ids)) {
                // No archived announcements
                $announcements = [];
            } else {
                $placeholders = implode(',', array_fill(0, count($archived_announcement_ids), '?'));
                    $stmt = $pdo->prepare("
                        SELECT 
                            a.announcement_id,
                            a.title,
                            a.content,
                            a.date_posted,
                            a.target_audience,
                            COALESCE(
                                TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)),
                                u.username
                            ) as posted_by_name
                        FROM announcements a
                        LEFT JOIN users u ON a.posted_by = u.id
                        WHERE a.status = 'approved'
                        AND (a.target_audience = 'all' OR a.target_audience = 'doctors')
                        AND a.announcement_id IN ($placeholders)
                        ORDER BY a.date_posted DESC
                        LIMIT 20
                    ");
                $stmt->execute($archived_announcement_ids);
                $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            // Active filter - get recent announcements that are NOT archived
            if (empty($archived_announcement_ids)) {
                // No archived announcements, get all recent
                $stmt = $pdo->prepare("
                    SELECT 
                        a.announcement_id,
                        a.title,
                        a.content,
                        a.date_posted,
                        a.target_audience,
                        COALESCE(
                            TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)),
                            u.username
                        ) as posted_by_name
                    FROM announcements a
                    LEFT JOIN users u ON a.posted_by = u.id
                    WHERE a.status = 'approved'
                    AND (a.target_audience = 'all' OR a.target_audience = 'doctors')
                    AND a.date_posted >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    ORDER BY a.date_posted DESC
                    LIMIT 20
                ");
                $stmt->execute();
                $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Exclude archived announcements
                $placeholders = implode(',', array_fill(0, count($archived_announcement_ids), '?'));
                $stmt = $pdo->prepare("
                    SELECT 
                        a.announcement_id,
                        a.title,
                        a.content,
                        a.date_posted,
                        a.target_audience,
                        COALESCE(
                            TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)),
                            u.username
                        ) as posted_by_name
                    FROM announcements a
                    LEFT JOIN users u ON a.posted_by = u.id
                    WHERE a.status = 'approved'
                    AND (a.target_audience = 'all' OR a.target_audience = 'doctors')
                    AND a.date_posted >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    AND a.announcement_id NOT IN ($placeholders)
                    ORDER BY a.date_posted DESC
                    LIMIT 20
                ");
                $stmt->execute($archived_announcement_ids);
                $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        
        foreach ($announcements as $ann) {
            $key = 'announcement_' . $ann['announcement_id'];
            
            // Skip if deleted
            if (isset($deleted_map[$key])) {
                continue;
            }
            
            // Filter by archive status
            $is_archived = isset($archived_map[$key]);
            if (($filter === 'active' && $is_archived) || ($filter === 'archived' && !$is_archived)) {
                continue;
            }
            
            $dt = new DateTime($ann['date_posted']);
            $dateStr = $dt->format('F j, Y');
            
            $notifications[] = [
                'type' => 'announcement',
                'title' => 'New Announcement',
                'message' => $ann['title'],
                'content' => $ann['content'],
                'posted_by' => $ann['posted_by_name'] ?: 'Admin',
                'date' => $dateStr,
                'announcement_id' => $ann['announcement_id'],
                'created_at' => $ann['date_posted'],
                'is_archived' => $is_archived
            ];
        }
    }
    
    // Sort all notifications by created_at (most recent first)
    usort($notifications, function($a, $b) {
        $timeA = strtotime($a['created_at']);
        $timeB = strtotime($b['created_at']);
        return $timeB - $timeA; // Descending order
    });
    
    // Limit to most recent 30 notifications
    $notifications = array_slice($notifications, 0, 30);
    
    if ($action === 'fetch') {
        echo json_encode([
            'success' => true,
            'notifications' => $notifications
        ]);
    } elseif ($action === 'archive') {
        $notification_type = $_POST['notification_type'] ?? '';
        $reference_id = intval($_POST['reference_id'] ?? 0);
        
        if ($notification_type && $reference_id > 0) {
            if ($has_prefs_table) {
                // UPDATE: Use INSERT ... ON DUPLICATE KEY UPDATE to set is_archived = 1
                // This ensures the notification is marked as archived, not deleted
                $stmt = $pdo->prepare("
                    INSERT INTO doctor_notification_preferences (user_id, notification_type, reference_id, is_archived, is_deleted)
                    VALUES (?, ?, ?, 1, 0)
                    ON DUPLICATE KEY UPDATE is_archived = 1, is_deleted = 0
                ");
                $result = $stmt->execute([$user_id, $notification_type, $reference_id]);
                
                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Notification archived']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to archive notification']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Archive functionality not available. Please run the migration.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid notification type or reference ID']);
        }
        
    } elseif ($action === 'delete') {
        $notification_type = $_POST['notification_type'] ?? '';
        $reference_id = intval($_POST['reference_id'] ?? 0);
        
        if ($notification_type && $reference_id > 0) {
            if ($has_prefs_table) {
                // Insert or update preference to mark as deleted
                $stmt = $pdo->prepare("
                    INSERT INTO doctor_notification_preferences (user_id, notification_type, reference_id, is_archived, is_deleted)
                    VALUES (?, ?, ?, 0, 1)
                    ON DUPLICATE KEY UPDATE is_deleted = 1, is_archived = 0
                ");
                $stmt->execute([$user_id, $notification_type, $reference_id]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Delete functionality not available. Please run the migration.']);
                exit;
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Notification deleted']);
        
    } elseif ($action === 'restore') {
        $notification_type = $_POST['notification_type'] ?? '';
        $reference_id = intval($_POST['reference_id'] ?? 0);
        
        if ($notification_type && $reference_id > 0) {
            if ($has_prefs_table) {
                // Update preference to unarchive
                $stmt = $pdo->prepare("
                    UPDATE doctor_notification_preferences 
                    SET is_archived = 0 
                    WHERE user_id = ? AND notification_type = ? AND reference_id = ? AND is_archived = 1
                ");
                $stmt->execute([$user_id, $notification_type, $reference_id]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Restore functionality not available. Please run the migration.']);
                exit;
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Notification restored']);
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Doctor notifications error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>

