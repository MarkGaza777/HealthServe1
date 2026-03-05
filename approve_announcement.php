<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Check if user is logged in and is FDO
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'fdo') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - FDO access required']);
    exit;
}

$fdo_id = $_SESSION['user']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $announcement_id = intval($_POST['announcement_id'] ?? 0);
    $action = $_POST['action'] ?? ''; // 'approve' or 'reject'
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');
    
    if (!$announcement_id || !in_array($action, ['approve', 'reject'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
    
    if ($action === 'reject' && empty($rejection_reason)) {
        echo json_encode(['success' => false, 'message' => 'Rejection reason is required']);
        exit;
    }
    
    try {
        if ($action === 'approve') {
            $stmt = $pdo->prepare("
                UPDATE announcements 
                SET status = 'approved', 
                    fdo_approved_by = ?, 
                    fdo_approved_at = NOW(),
                    rejection_reason = NULL
                WHERE announcement_id = ?
            ");
            $stmt->execute([$fdo_id, $announcement_id]);
            
            // Get announcement details
            $announcement_stmt = $pdo->prepare("
                SELECT posted_by, title, target_audience 
                FROM announcements 
                WHERE announcement_id = ?
            ");
            $announcement_stmt->execute([$announcement_id]);
            $announcement = $announcement_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($announcement) {
                // Notify the original poster (if not the FDO)
                if ($announcement['posted_by'] != $fdo_id) {
                    $message = "Your announcement '{$announcement['title']}' has been approved and published.";
                    
                    // Check if notification already exists
                    $check_notif = $pdo->prepare("
                        SELECT notification_id 
                        FROM notifications 
                        WHERE user_id = ? 
                          AND type = 'announcement' 
                          AND message = ?
                        LIMIT 1
                    ");
                    $check_notif->execute([$announcement['posted_by'], $message]);
                    
                    if (!$check_notif->fetch()) {
                        $notif_insert = $pdo->prepare("
                            INSERT INTO notifications (user_id, message, type, status) 
                            VALUES (?, ?, 'announcement', 'unread')
                        ");
                        $notif_insert->execute([$announcement['posted_by'], $message]);
                    }
                    
                    // If the poster is an admin, they already got the notification above
                    // But we also want to notify all admins about approved announcements
                    $poster_stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                    $poster_stmt->execute([$announcement['posted_by']]);
                    $poster = $poster_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($poster && $poster['role'] !== 'admin') {
                        // Notify all admins about the approved announcement
                        $admin_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin'");
                        $admin_stmt->execute();
                        $admin_users = $admin_stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        $admin_message = "Announcement '{$announcement['title']}' has been approved and published.";
                        
                        $admin_notif_insert = $pdo->prepare("
                            INSERT INTO notifications (user_id, message, type, status) 
                            VALUES (?, ?, 'announcement', 'unread')
                        ");
                        
                        foreach ($admin_users as $admin_user) {
                            // Check if notification already exists
                            $check_admin_notif = $pdo->prepare("
                                SELECT notification_id 
                                FROM notifications 
                                WHERE user_id = ? 
                                  AND type = 'announcement' 
                                  AND message = ?
                                LIMIT 1
                            ");
                            $check_admin_notif->execute([$admin_user['id'], $admin_message]);
                            
                            if (!$check_admin_notif->fetch()) {
                                $admin_notif_insert->execute([$admin_user['id'], $admin_message]);
                            }
                        }
                    }
                } else {
                    // FDO approved their own announcement - notify admins
                    $admin_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin'");
                    $admin_stmt->execute();
                    $admin_users = $admin_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $admin_message = "Announcement '{$announcement['title']}' has been approved and published.";
                    
                    $admin_notif_insert = $pdo->prepare("
                        INSERT INTO notifications (user_id, message, type, status) 
                        VALUES (?, ?, 'announcement', 'unread')
                    ");
                    
                    foreach ($admin_users as $admin_user) {
                        // Check if notification already exists
                        $check_admin_notif = $pdo->prepare("
                            SELECT notification_id 
                            FROM notifications 
                            WHERE user_id = ? 
                              AND type = 'announcement' 
                              AND message = ?
                            LIMIT 1
                        ");
                        $check_admin_notif->execute([$admin_user['id'], $admin_message]);
                        
                        if (!$check_admin_notif->fetch()) {
                            $admin_notif_insert->execute([$admin_user['id'], $admin_message]);
                        }
                    }
                }
                
                // Notify all pharmacists when an announcement is approved (if target audience includes them)
                if ($announcement['target_audience'] === 'all' || $announcement['target_audience'] === 'pharmacists') {
                    $pharmacists_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'pharmacist'");
                    $pharmacists_stmt->execute();
                    $pharmacists = $pharmacists_stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // Ensure reference_id column exists
                    try {
                        $pdo->exec("ALTER TABLE notifications ADD COLUMN reference_id INT NULL AFTER type");
                    } catch (PDOException $e) {
                        // Column already exists, ignore
                    }
                    
                    $message = "New Announcement: {$announcement['title']}";
                    
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
                        // Check if notification already exists for this pharmacist and announcement
                        $check_notif->execute([$pharmacist_id, $announcement_id]);
                        if (!$check_notif->fetch()) {
                            $notif_insert->execute([$pharmacist_id, $message, $announcement_id]);
                        }
                    }
                }
                
                // Notify all doctors when an announcement is approved (if target audience includes them)
                if ($announcement['target_audience'] === 'all' || $announcement['target_audience'] === 'doctors') {
                    $doctors_stmt = $pdo->prepare("SELECT u.id FROM users u INNER JOIN doctors d ON d.user_id = u.id WHERE u.role = 'doctor'");
                    $doctors_stmt->execute();
                    $doctors = $doctors_stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // Ensure reference_id column exists
                    try {
                        $pdo->exec("ALTER TABLE notifications ADD COLUMN reference_id INT NULL AFTER type");
                    } catch (PDOException $e) {
                        // Column already exists, ignore
                    }
                    
                    $message = "New Announcement: {$announcement['title']}";
                    
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
                    
                    foreach ($doctors as $doctor_id) {
                        // Check if notification already exists for this doctor and announcement
                        $check_notif->execute([$doctor_id, $announcement_id]);
                        if (!$check_notif->fetch()) {
                            $notif_insert->execute([$doctor_id, $message, $announcement_id]);
                        }
                    }
                }
                
                // Notify all patients if target audience is 'all' or 'patients'
                if ($announcement['target_audience'] === 'all' || $announcement['target_audience'] === 'patients') {
                    $patients_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'patient'");
                    $patients_stmt->execute();
                    $patients = $patients_stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    $message = "Announcement: {$announcement['title']}";
                    
                    // Ensure reference_id column exists
                    try {
                        $pdo->exec("ALTER TABLE notifications ADD COLUMN reference_id INT NULL AFTER type");
                    } catch (PDOException $e) {
                        // Column already exists, ignore
                    }
                    
                    // Check and insert notifications, avoiding duplicates
                    // Check if this exact announcement notification already exists (regardless of date)
                    $check_notif = $pdo->prepare("
                        SELECT notification_id 
                        FROM notifications 
                        WHERE user_id = ? 
                          AND type = 'announcement' 
                          AND reference_id = ?
                        LIMIT 1
                    ");
                    
                    // Prepare insert statement with reference_id
                    $notif_insert = $pdo->prepare("
                        INSERT INTO notifications (user_id, message, type, reference_id, status) 
                        VALUES (?, ?, 'announcement', ?, 'unread')
                    ");
                    
                    foreach ($patients as $patient_id) {
                        // Check if notification already exists for this patient and announcement
                        $check_notif->execute([$patient_id, $announcement_id]);
                        if (!$check_notif->fetch()) {
                            $notif_insert->execute([$patient_id, $message, $announcement_id]);
                        }
                    }
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Announcement approved successfully']);
            
        } else { // reject
            $stmt = $pdo->prepare("
                UPDATE announcements 
                SET status = 'rejected', 
                    fdo_approved_by = ?, 
                    fdo_approved_at = NOW(),
                    rejection_reason = ?
                WHERE announcement_id = ?
            ");
            $stmt->execute([$fdo_id, $rejection_reason, $announcement_id]);
            
            // Notify the original poster
            $notif_stmt = $pdo->prepare("
                SELECT posted_by FROM announcements WHERE announcement_id = ?
            ");
            $notif_stmt->execute([$announcement_id]);
            $announcement = $notif_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($announcement && $announcement['posted_by'] != $fdo_id) {
                $title_stmt = $pdo->prepare("SELECT title FROM announcements WHERE announcement_id = ?");
                $title_stmt->execute([$announcement_id]);
                $title_data = $title_stmt->fetch(PDO::FETCH_ASSOC);
                
                $notif_insert = $pdo->prepare("
                    INSERT INTO notifications (user_id, message, status) 
                    VALUES (?, ?, 'unread')
                ");
                $message = "Your announcement '{$title_data['title']}' has been rejected. Reason: {$rejection_reason}";
                $notif_insert->execute([$announcement['posted_by'], $message]);
            }
            
            echo json_encode(['success' => true, 'message' => 'Announcement rejected']);
        }
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>

