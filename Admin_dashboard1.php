<?php
session_start();
require_once 'db.php';
require_once 'admin_helpers_simple.php';
require_once 'auto_audit_log.php'; // Auto-log page access

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: Login.php");
    exit();
}

// Get dashboard statistics
try {
    // Staff stats
    $stmt = $pdo->query("SELECT COUNT(*) as total_staff FROM staff");
    $total_staff = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as on_duty FROM staff WHERE shift_status = 'on_duty' AND status = 'active'");
    $staff_on_duty = $stmt->fetchColumn();
    
    // Get recent announcements for display
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
        
        // Check if status column exists
        $checkStatus = $pdo->query("SHOW COLUMNS FROM announcements LIKE 'status'");
        if ($checkStatus->rowCount() > 0) {
            // Get recent announcements for display (excluding expired)
            $stmt = $pdo->prepare("
                SELECT a.*, u.username as posted_by_username, u.full_name as posted_by_name
                FROM announcements a
                LEFT JOIN users u ON a.posted_by = u.id
                WHERE a.status = 'approved'
                ORDER BY a.date_posted DESC
                LIMIT 5
            ");
        } else {
            // If status column doesn't exist, treat all as approved
            $stmt = $pdo->prepare("
                SELECT a.*, u.username as posted_by_username, u.full_name as posted_by_name
                FROM announcements a
                LEFT JOIN users u ON a.posted_by = u.id
                ORDER BY a.date_posted DESC
                LIMIT 5
            ");
        }
        $stmt->execute();
        $recent_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        $recent_announcements = [];
    }
    
    // Get notifications - only announcement-related notifications for admin
    $admin_id = $_SESSION['user']['id'];
    try {
        // Check if type column exists in notifications table
        $checkType = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'type'");
        if ($checkType->rowCount() > 0) {
            // Filter by type='announcement' or 'residency_verification' for admin
            $stmt = $pdo->prepare("
                SELECT * FROM notifications 
                WHERE user_id = ? 
                AND (type = 'announcement' OR type = 'residency_verification' OR (type IS NULL AND message LIKE '%announcement%'))
                AND status = 'unread' 
                ORDER BY created_at DESC 
                LIMIT 3
            ");
            $stmt->execute([$admin_id]);
        } else {
            // If type column doesn't exist, filter by message content
            $stmt = $pdo->prepare("
                SELECT * FROM notifications 
                WHERE user_id = ? 
                AND (message LIKE '%announcement%' OR message LIKE '%approved%' OR message LIKE '%posted%' OR message LIKE '%residency verification%')
                AND status = 'unread' 
                ORDER BY created_at DESC 
                LIMIT 3
            ");
            $stmt->execute([$admin_id]);
        }
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        // If notifications table doesn't exist or has different structure
        $notifications = [];
    }
    
} catch(PDOException $e) {
    $error = "Error fetching data: " . $e->getMessage();
    $total_staff = 0;
    $staff_on_duty = 0;
    $recent_announcements = [];
    $notifications = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - HealthServe Barangay Payatas B</title>
    <link rel="stylesheet" href="assets/Style1.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="admin-profile">
            <div class="profile-info">
                <div class="profile-avatar">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="profile-details">
                    <h3>Admin</h3>
                    <div class="profile-status">Online</div>
                </div>
            </div>
        </div>

        <nav class="nav-section">
            <div class="nav-title">General</div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="Admin_dashboard1.php" class="nav-link active">
                        <div class="nav-icon"><i class="fas fa-th-large"></i></div>
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_staff_management.php" class="nav-link">
                        <div class="nav-icon"><i class="fas fa-user-friends"></i></div>
                        Staffs
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_doctors_management.php" class="nav-link">
                        <div class="nav-icon"><i class="fas fa-user-md"></i></div>
                        Doctors
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_residency_verification.php" class="nav-link">
                        <div class="nav-icon"><i class="fas fa-id-card"></i></div>
                        Residency Verification
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_announcements.php" class="nav-link">
                        <div class="nav-icon"><i class="fas fa-bullhorn"></i></div>
                        Announcements
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_notifications.php" class="nav-link">
                        <div class="nav-icon"><i class="fas fa-bell"></i></div>
                        Notifications
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_settings.php" class="nav-link">
                        <div class="nav-icon"><i class="fas fa-cog"></i></div>
                        Settings
                    </a>
                </li>
            </ul>
        </nav>

        <div class="logout-section">
            <a href="logout.php" class="logout-link">
                <div class="nav-icon"><i class="fas fa-sign-out-alt"></i></div>
                Logout
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="admin-header">
            <div class="header-title">
                <img src="assets/payatas logo.png" alt="Payatas B Logo" class="barangay-seal">
                <div>
                    <h1>HealthServe - Payatas B</h1>
                    <p>Barangay Health Center Management System</p>
                </div>
            </div>
        </header>

        <!-- Content Area -->
        <div class="content-area">
            <div class="page-header">
                <h2 class="page-title">Dashboard</h2>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon-wrapper staff">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $total_staff; ?></h3>
                        <p>Total Staffs</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon-wrapper appointments">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $staff_on_duty; ?></h3>
                        <p>Staffs On Duty</p>
                    </div>
                </div>
            </div>

            <div class="two-column-grid">
                <div class="content-section">
                    <h2 style="color: #2E7D32; margin-bottom: 20px; font-size: 20px;">Announcements</h2>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Posted By</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_announcements)): ?>
                                <tr>
                                    <td colspan="3" style="text-align: center; padding: 20px; color: #666;">No announcements yet</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_announcements as $announcement): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($announcement['title']); ?></td>
                                    <td><?php echo htmlspecialchars($announcement['posted_by_name'] ?? $announcement['posted_by_username'] ?? 'Unknown'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($announcement['date_posted'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="content-section">
                    <h2 style="color: #2E7D32; margin-bottom: 20px; font-size: 20px;">Notifications</h2>
                    <?php if (empty($notifications)): ?>
                        <div class="notification-item" style="background: #f5f5f5;">
                            <div class="notification-icon" style="background: #9e9e9e;"><i class="fas fa-info-circle"></i></div>
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #333;">No new notifications</div>
                                <div style="font-size: 12px; color: #666;">You're all caught up!</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notif): 
                            $is_residency = (isset($notif['type']) && $notif['type'] === 'residency_verification');
                            $notif_link = $is_residency ? 'admin_residency_verification.php' : 'admin_announcements.php';
                            $notif_title = $is_residency ? 'Residency Verification' : 'New Notification';
                            $notif_icon = $is_residency ? 'fa-id-card' : 'fa-bell';
                        ?>
                        <a href="<?php echo htmlspecialchars($notif_link); ?>" class="notification-item" style="background: #ffebee; text-decoration: none; color: inherit; display: flex;">
                            <div class="notification-icon" style="background: #ef5350;"><i class="fas <?php echo $notif_icon; ?>"></i></div>
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #333;"><?php echo htmlspecialchars($notif_title); ?></div>
                                <div style="font-size: 12px; color: #666;"><?php echo htmlspecialchars($notif['message'] ?? 'No message'); ?></div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Stats cards specific styling */
        .stat-card {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .stat-icon-wrapper {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .stat-icon-wrapper.appointments {
            background: linear-gradient(135deg, #FF7043, #F4511E);
        }

        .stat-icon-wrapper.patients {
            background: linear-gradient(135deg, #42A5F5, #1E88E5);
        }

        .stat-icon-wrapper.staff {
            background: linear-gradient(135deg, #26A69A, #00897B);
        }

        .stat-details h3 {
            font-size: 32px;
            font-weight: 700;
            color: #2E7D32;
            margin-bottom: 5px;
        }

        .stat-details p {
            color: #666;
            font-size: 14px;
            font-weight: 500;
        }

        /* Notification items */
        .notification-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
        }
        a.notification-item:hover {
            background: #ffcdd2 !important;
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        /* Two column grid */
        .two-column-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 30px;
        }

        .content-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
    </style>
</body>
</html>
