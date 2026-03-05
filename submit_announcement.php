<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Check if user is logged in
if (empty($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Please log in again to submit announcements.']);
    exit;
}

$user_id = $_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $category = trim($_POST['category'] ?? 'General');
    $start_date = $_POST['start_date'] ?? null;
    $end_date = $_POST['end_date'] ?? null;
    $schedule = trim($_POST['schedule'] ?? 'Not Applicable');
    
    // Validate required fields
    if (empty($title) || empty($content)) {
        echo json_encode(['success' => false, 'message' => 'Title and content are required']);
        exit;
    }
    
    // Validate required date fields
    if (empty($start_date)) {
        echo json_encode(['success' => false, 'message' => 'Start date and time are required']);
        exit;
    }
    
    if (empty($end_date)) {
        echo json_encode(['success' => false, 'message' => 'End date and time are required']);
        exit;
    }
    
    // Validate image is required
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Image is required']);
        exit;
    }
    
    // Handle image upload (required field)
    $image_path = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/announcements/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (!in_array($file_extension, $allowed_extensions)) {
            echo json_encode(['success' => false, 'message' => 'Invalid image format. Only JPG, PNG, and GIF are allowed.']);
            exit;
        }
        
        $max_file_size = 5 * 1024 * 1024; // 5MB
        if ($_FILES['image']['size'] > $max_file_size) {
            echo json_encode(['success' => false, 'message' => 'Image size exceeds 5MB limit.']);
            exit;
        }
        
        $file_name = uniqid('announcement_', true) . '.' . $file_extension;
        $file_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['image']['tmp_name'], $file_path)) {
            $image_path = $file_path;
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to upload image. Please try again.']);
            exit;
        }
    } else {
        // If image upload failed, provide specific error message
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'Image file exceeds upload_max_filesize directive.',
            UPLOAD_ERR_FORM_SIZE => 'Image file exceeds MAX_FILE_SIZE directive.',
            UPLOAD_ERR_PARTIAL => 'Image file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No image file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write image file to disk.',
            UPLOAD_ERR_EXTENSION => 'Image upload stopped by extension.'
        ];
        $error_code = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
        $error_msg = $error_messages[$error_code] ?? 'Unknown image upload error.';
        echo json_encode(['success' => false, 'message' => 'Image upload failed: ' . $error_msg]);
        exit;
    }
    
    // Determine status based on user role
    // FDO can create approved announcements directly
    // Others need FDO approval
    $status = ($user_role === 'fdo') ? 'approved' : 'pending';
    $fdo_approved_by = ($user_role === 'fdo') ? $user_id : null;
    
    // Set target_audience automatically based on role
    // For pharmacist and other roles with announcements tab, set to 'all' so all roles can see it
    // FDO can still specify audience if needed (from their form)
    $target_audience = 'all';
    if ($user_role === 'fdo' && isset($_POST['audience'])) {
        $target_audience = $_POST['audience'];
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO announcements 
            (posted_by, title, content, category, target_audience, status, fdo_approved_by, start_date, end_date, image_path, schedule, date_posted) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $user_id,
            $title,
            $content,
            $category,
            $target_audience,
            $status,
            $fdo_approved_by,
            $start_date ?: null,
            $end_date ?: null,
            $image_path,
            $schedule
        ]);
        
        $announcement_id = $pdo->lastInsertId();
        
        // Handle notifications (wrap in try-catch to prevent breaking the main flow)
        try {
            // Ensure type column exists in notifications table
            try {
                $pdo->exec("ALTER TABLE notifications ADD COLUMN type VARCHAR(50) NULL AFTER message");
            } catch (PDOException $e) {
                // Column already exists, ignore
            }
            
            // Notify admin about new announcement posted
            try {
                $admin_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin'");
                $admin_stmt->execute();
                $admin_users = $admin_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($admin_users)) {
                    $role_name = ucfirst($user_role);
                    $admin_message = "New announcement '{$title}' posted by {$role_name}.";
                    
                    // Check if type column exists before using it
                    $check_type_col = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'type'");
                    $has_type_col = $check_type_col->rowCount() > 0;
                    
                    if ($has_type_col) {
                        $admin_notif_stmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, message, type, status) 
                            VALUES (?, ?, 'announcement', 'unread')
                        ");
                        foreach ($admin_users as $admin_user) {
                            try {
                                $admin_notif_stmt->execute([$admin_user['id'], $admin_message, 'announcement']);
                            } catch (PDOException $e) {
                                // Log error but continue
                                error_log("Failed to notify admin: " . $e->getMessage());
                            }
                        }
                    } else {
                        $admin_notif_stmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, message, status) 
                            VALUES (?, ?, 'unread')
                        ");
                        foreach ($admin_users as $admin_user) {
                            try {
                                $admin_notif_stmt->execute([$admin_user['id'], $admin_message]);
                            } catch (PDOException $e) {
                                // Log error but continue
                                error_log("Failed to notify admin: " . $e->getMessage());
                            }
                        }
                    }
                }
            } catch (PDOException $e) {
                // Log error but don't break the main flow
                error_log("Error notifying admins: " . $e->getMessage());
            }
            
            // If not FDO, create notification for FDO
            if ($user_role !== 'fdo') {
                try {
                    // Find FDO users
                    $fdo_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'fdo'");
                    $fdo_stmt->execute();
                    $fdo_users = $fdo_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($fdo_users)) {
                        $role_name = ucfirst($user_role);
                        $fdo_message = "New announcement '{$title}' submitted by {$role_name}. Please review and approve.";
                        
                        // Check if type column exists
                        $check_type_col = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'type'");
                        $has_type_col = $check_type_col->rowCount() > 0;
                        
                        if ($has_type_col) {
                            $notif_stmt = $pdo->prepare("
                                INSERT INTO notifications (user_id, message, type, status) 
                                VALUES (?, ?, 'announcement', 'unread')
                            ");
                            foreach ($fdo_users as $fdo_user) {
                                try {
                                    $notif_stmt->execute([$fdo_user['id'], $fdo_message, 'announcement']);
                                } catch (PDOException $e) {
                                    error_log("Failed to notify FDO: " . $e->getMessage());
                                }
                            }
                        } else {
                            $notif_stmt = $pdo->prepare("
                                INSERT INTO notifications (user_id, message, status) 
                                VALUES (?, ?, 'unread')
                            ");
                            foreach ($fdo_users as $fdo_user) {
                                try {
                                    $notif_stmt->execute([$fdo_user['id'], $fdo_message]);
                                } catch (PDOException $e) {
                                    error_log("Failed to notify FDO: " . $e->getMessage());
                                }
                            }
                        }
                    }
                } catch (PDOException $e) {
                    error_log("Error notifying FDOs: " . $e->getMessage());
                }
            } else {
                // FDO posted directly (approved status) - notify patients and pharmacists immediately
                if ($status === 'approved') {
                    try {
                        // Notify pharmacists if target audience includes them
                        if ($target_audience === 'all' || $target_audience === 'pharmacists') {
                            // Ensure reference_id column exists
                            try {
                                $pdo->exec("ALTER TABLE notifications ADD COLUMN reference_id INT NULL AFTER type");
                            } catch (PDOException $e) {
                                // Column already exists, ignore
                            }
                            
                            $pharmacists_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'pharmacist'");
                            $pharmacists_stmt->execute();
                            $pharmacists = $pharmacists_stmt->fetchAll(PDO::FETCH_COLUMN);
                            
                            if (!empty($pharmacists)) {
                                $message = "New Announcement: {$title}";
                                
                                $check_notif = $pdo->prepare("
                                    SELECT notification_id 
                                    FROM notifications 
                                    WHERE user_id = ? 
                                      AND type = 'announcement' 
                                      AND reference_id = ?
                                    LIMIT 1
                                ");
                                
                                $notif_insert = $pdo->prepare("
                                    INSERT INTO notifications (user_id, message, type, reference_id, status) 
                                    VALUES (?, ?, 'announcement', ?, 'unread')
                                ");
                                
                                foreach ($pharmacists as $pharmacist_id) {
                                    try {
                                        // Check if notification already exists for this pharmacist and announcement
                                        $check_notif->execute([$pharmacist_id, $announcement_id]);
                                        if (!$check_notif->fetch()) {
                                            $notif_insert->execute([$pharmacist_id, $message, $announcement_id]);
                                        }
                                    } catch (PDOException $e) {
                                        error_log("Failed to notify pharmacist: " . $e->getMessage());
                                    }
                                }
                            }
                        }
                        
                        // Notify patients if target audience includes them
                        if ($target_audience === 'all' || $target_audience === 'patients') {
                            // Ensure reference_id column exists
                            try {
                                $pdo->exec("ALTER TABLE notifications ADD COLUMN reference_id INT NULL AFTER type");
                            } catch (PDOException $e) {
                                // Column already exists, ignore
                            }
                            
                            $patients_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'patient'");
                            $patients_stmt->execute();
                            $patients = $patients_stmt->fetchAll(PDO::FETCH_COLUMN);
                            
                            if (!empty($patients)) {
                                $message = "Announcement: {$title}";
                                
                                // Check and insert notifications, avoiding duplicates
                                $check_notif = $pdo->prepare("
                                    SELECT notification_id 
                                    FROM notifications 
                                    WHERE user_id = ? 
                                      AND type = 'announcement' 
                                      AND reference_id = ?
                                    LIMIT 1
                                ");
                                
                                $notif_insert = $pdo->prepare("
                                    INSERT INTO notifications (user_id, message, type, reference_id, status) 
                                    VALUES (?, ?, 'announcement', ?, 'unread')
                                ");
                                
                                foreach ($patients as $patient_id) {
                                    try {
                                        // Check if notification already exists for this patient and announcement
                                        $check_notif->execute([$patient_id, $announcement_id]);
                                        if (!$check_notif->fetch()) {
                                            $notif_insert->execute([$patient_id, $message, $announcement_id]);
                                        }
                                    } catch (PDOException $e) {
                                        error_log("Failed to notify patient: " . $e->getMessage());
                                    }
                                }
                            }
                        }
                    } catch (PDOException $e) {
                        error_log("Error notifying users about approved announcement: " . $e->getMessage());
                    }
                }
            }
        } catch (Exception $e) {
            // Log notification errors but don't break the main flow
            error_log("Error in notification handling: " . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true, 
            'message' => $user_role === 'fdo' 
                ? 'Announcement created successfully!' 
                : 'Announcement submitted successfully! It will be published after FDO approval.',
            'announcement_id' => $announcement_id,
            'requires_approval' => $user_role !== 'fdo'
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        // Log the full error for debugging
        error_log("Error submitting announcement: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => 'Error submitting announcement. Please try again.']);
    } catch (Exception $e) {
        http_response_code(500);
        error_log("Unexpected error submitting announcement: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again.']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>

