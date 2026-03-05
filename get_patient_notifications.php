<?php
session_start();
require 'db.php';
header('Content-Type: application/json');

// Check if user is logged in and is a patient
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'patient') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user']['id'];
$action = $_GET['action'] ?? 'fetch';
$filter = $_GET['filter'] ?? 'active'; // 'active', 'archived'

try {
    if ($action === 'fetch') {
        // Check if archive/delete columns exist, create if they don't
        $has_archive = false;
        $has_reference_id = false;
        try {
            $test_stmt = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'is_archived'");
            $has_archive = $test_stmt->rowCount() > 0;
            $test_stmt = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'reference_id'");
            $has_reference_id = $test_stmt->rowCount() > 0;
        } catch (PDOException $e) {
            // Columns don't exist
        }
        
        // Auto-create columns if they don't exist
        if (!$has_archive) {
            try {
                $pdo->exec("ALTER TABLE notifications ADD COLUMN `is_archived` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether the notification is archived (1) or not (0)' AFTER `status`");
                $pdo->exec("ALTER TABLE notifications ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether the notification is deleted (1) or not (0)' AFTER `is_archived`");
                $pdo->exec("ALTER TABLE notifications ADD INDEX `idx_archived` (`is_archived`), ADD INDEX `idx_deleted` (`is_deleted`), ADD INDEX `idx_user_archived` (`user_id`, `is_archived`), ADD INDEX `idx_user_deleted` (`user_id`, `is_deleted`)");
                $has_archive = true;
            } catch (PDOException $e) {
                error_log("Error adding archive columns: " . $e->getMessage());
            }
        }
        
        // Build query based on filter
        if ($has_archive) {
            if ($filter === 'archived') {
                // Fetch archived notifications (not deleted)
                $where_clause = "WHERE user_id = ? AND is_archived = 1 AND (is_deleted = 0 OR is_deleted IS NULL)";
            } else {
                // Fetch active notifications (not archived, not deleted)
                $where_clause = "WHERE user_id = ? AND (is_archived = 0 OR is_archived IS NULL) AND (is_deleted = 0 OR is_deleted IS NULL)";
            }
        } else {
            // Fallback: if columns don't exist, show all notifications for active, none for archived
            if ($filter === 'archived') {
                $where_clause = "WHERE user_id = ? AND 1 = 0"; // Return no results
            } else {
                $where_clause = "WHERE user_id = ?";
            }
        }
        
        $select_fields = "notification_id, message, type, status, created_at";
        if ($has_reference_id) {
            $select_fields = "notification_id, message, type, reference_id, status, created_at";
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                $select_fields
            FROM notifications
            $where_clause
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$user_id]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format notifications with relative time
        $formatted = [];
        foreach ($notifications as $notif) {
            $formatted[] = [
                'id' => $notif['notification_id'],
                'message' => $notif['message'],
                'type' => $notif['type'] ?? 'general',
                'reference_id' => ($has_reference_id && isset($notif['reference_id'])) ? $notif['reference_id'] : null,
                'read' => $notif['status'] === 'read',
                'created_at' => $notif['created_at'],
                'time_ago' => getTimeAgo($notif['created_at'])
            ];
        }
        
        echo json_encode(['success' => true, 'notifications' => $formatted]);
        
    } elseif ($action === 'mark_read') {
        $notification_id = intval($_POST['notification_id'] ?? 0);
        
        if ($notification_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE notifications 
                SET status = 'read' 
                WHERE notification_id = ? AND user_id = ?
            ");
            $stmt->execute([$notification_id, $user_id]);
        }
        
        echo json_encode(['success' => true]);
        
    } elseif ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET status = 'read' 
            WHERE user_id = ? AND status = 'unread' 
            AND (is_archived = 0 OR is_archived IS NULL) 
            AND (is_deleted = 0 OR is_deleted IS NULL)
        ");
        $stmt->execute([$user_id]);
        
        echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
        
    } elseif ($action === 'archive') {
        $notification_id = intval($_POST['notification_id'] ?? 0);
        
        if ($notification_id > 0) {
            // Check if is_archived column exists, create if it doesn't
            $has_archive = false;
            try {
                $test_stmt = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'is_archived'");
                $has_archive = $test_stmt->rowCount() > 0;
            } catch (PDOException $e) {
                // Column doesn't exist
            }
            
            if (!$has_archive) {
                try {
                    $pdo->exec("ALTER TABLE notifications ADD COLUMN `is_archived` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether the notification is archived (1) or not (0)' AFTER `status`");
                    $pdo->exec("ALTER TABLE notifications ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether the notification is deleted (1) or not (0)' AFTER `is_archived`");
                    $has_archive = true;
                } catch (PDOException $e) {
                    error_log("Error adding archive columns: " . $e->getMessage());
                }
            }
            
            if ($has_archive) {
                $stmt = $pdo->prepare("
                    UPDATE notifications 
                    SET is_archived = 1 
                    WHERE notification_id = ? AND user_id = ? AND (is_deleted = 0 OR is_deleted IS NULL)
                ");
                $stmt->execute([$notification_id, $user_id]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Archive functionality not available. Please run the migration.']);
                exit;
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Notification archived']);
        
    } elseif ($action === 'delete') {
        $notification_id = intval($_POST['notification_id'] ?? 0);
        
        if ($notification_id > 0) {
            // Check if is_deleted column exists
            $has_delete = false;
            try {
                $test_stmt = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'is_deleted'");
                $has_delete = $test_stmt->rowCount() > 0;
            } catch (PDOException $e) {
                // Column doesn't exist
            }
            
            if ($has_delete) {
                // Permanently delete the notification
                $stmt = $pdo->prepare("
                    DELETE FROM notifications 
                    WHERE notification_id = ? AND user_id = ?
                ");
                $stmt->execute([$notification_id, $user_id]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Delete functionality not available. Please run the migration.']);
                exit;
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Notification deleted']);
        
    } elseif ($action === 'restore') {
        $notification_id = intval($_POST['notification_id'] ?? 0);
        
        if ($notification_id > 0) {
            // Check if is_archived column exists
            $has_archive = false;
            try {
                $test_stmt = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'is_archived'");
                $has_archive = $test_stmt->rowCount() > 0;
            } catch (PDOException $e) {
                // Column doesn't exist
            }
            
            if ($has_archive) {
                $stmt = $pdo->prepare("
                    UPDATE notifications 
                    SET is_archived = 0 
                    WHERE notification_id = ? AND user_id = ? AND is_archived = 1
                ");
                $stmt->execute([$notification_id, $user_id]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Restore functionality not available. Please run the migration.']);
                exit;
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Notification restored']);
        
    } elseif ($action === 'count') {
        // Get count of unread notifications (excluding archived and deleted)
        $has_archive = false;
        try {
            $test_stmt = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'is_archived'");
            $has_archive = $test_stmt->rowCount() > 0;
        } catch (PDOException $e) {
            // Column doesn't exist
        }
        
        if ($has_archive) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM notifications
                WHERE user_id = ? 
                AND status = 'unread'
                AND (is_archived = 0 OR is_archived IS NULL)
                AND (is_deleted = 0 OR is_deleted IS NULL)
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM notifications
                WHERE user_id = ? AND status = 'unread'
            ");
        }
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'count' => intval($result['count'])]);
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

/**
 * Calculate relative time (e.g., "2 hours ago", "1 day ago")
 */
function getTimeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $current = time();
    $diff = $current - $timestamp;
    
    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ($minutes == 1 ? ' minute ago' : ' minutes ago');
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ($hours == 1 ? ' hour ago' : ' hours ago');
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ($days == 1 ? ' day ago' : ' days ago');
    } elseif ($diff < 2592000) {
        $weeks = floor($diff / 604800);
        return $weeks . ($weeks == 1 ? ' week ago' : ' weeks ago');
    } else {
        $months = floor($diff / 2592000);
        return $months . ($months == 1 ? ' month ago' : ' months ago');
    }
}
?>

