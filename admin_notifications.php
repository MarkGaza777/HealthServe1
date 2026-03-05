<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: Login.php");
    exit();
}

// Get admin ID
$admin_id = $_SESSION['user']['id'];

// Function to calculate relative time
function getTimeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 2592000) {
        $weeks = floor($diff / 604800);
        return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', $timestamp);
    }
}

// Fetch announcement-related notifications from database
try {
    // Check if type column exists
    $checkType = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'type'");
    $hasType = $checkType->rowCount() > 0;
    
    if ($hasType) {
        // Filter by type='announcement' or 'residency_verification' for admin
        $stmt = $pdo->prepare("
            SELECT 
                notification_id as id,
                message,
                type,
                status,
                created_at
            FROM notifications 
            WHERE user_id = ? 
            AND (type = 'announcement' OR type = 'residency_verification' OR (type IS NULL AND message LIKE '%announcement%'))
            ORDER BY created_at DESC 
            LIMIT 50
        ");
    } else {
        // If type column doesn't exist, filter by message content
        $stmt = $pdo->prepare("
            SELECT 
                notification_id as id,
                message,
                NULL as type,
                status,
                created_at
            FROM notifications 
            WHERE user_id = ? 
            AND (message LIKE '%announcement%' OR message LIKE '%approved%' OR message LIKE '%posted%' OR message LIKE '%residency verification%')
            ORDER BY created_at DESC 
            LIMIT 50
        ");
    }
    
    $stmt->execute([$admin_id]);
    $notifications_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format notifications
    $notifications = [];
    foreach ($notifications_raw as $notif) {
        // Determine title based on type or message content
        $title = 'Announcement Notification';
        if (isset($notif['type']) && $notif['type'] === 'residency_verification') {
            $title = 'Residency Verification';
        } elseif (stripos($notif['message'], 'approved') !== false) {
            $title = 'Announcement Approved';
        } elseif (stripos($notif['message'], 'posted') !== false || stripos($notif['message'], 'new') !== false) {
            $title = 'New Announcement';
        }
        
        // Determine priority based on read status
        $priority = $notif['status'] === 'unread' ? 'high' : 'low';
        
        $notifications[] = [
            'id' => $notif['id'],
            'type' => 'announcement',
            'title' => $title,
            'message' => $notif['message'],
            'time' => getTimeAgo($notif['created_at']),
            'read' => $notif['status'] === 'read',
            'priority' => $priority
        ];
    }
} catch(PDOException $e) {
    // If notifications table doesn't exist or has different structure
    $notifications = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - HealthServe Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/Style1.css">
    <style>
        .notifications-container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .notifications-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .notification-filters {
            display: flex;
            gap: 10px;
        }

        .filter-btn {
            padding: 8px 16px;
            border: 1px solid #e0e0e0;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-btn:hover,
        .filter-btn.active {
            background: #4CAF50;
            color: white;
            border-color: #4CAF50;
        }

        .notifications-list {
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .notification-item {
            display: flex;
            align-items: flex-start;
            padding: 20px;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.3s ease;
            cursor: pointer;
            background: white;
        }

        .notification-item:hover {
            background-color: #f8f9fa;
        }

        .notification-item.unread {
            background-color: white;
            border-left: 4px solid #FFC107;
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 16px;
            flex-shrink: 0;
        }

        .notification-icon.announcement {
            background: rgba(255, 193, 7, 0.1);
            color: #FFC107;
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .notification-message {
            color: #666;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .notification-time {
            color: #999;
            font-size: 12px;
        }

        .notification-actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-left: 15px;
        }

        .notification-badge {
            background: #E3F2FD;
            color: #1565C0;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 600;
        }

        .notification-badge.high {
            background: #FFEBEE;
            color: #C62828;
        }

        .notification-badge.medium {
            background: #FFF3E0;
            color: #F57C00;
        }

        .notification-badge.low {
            background: #E8F5E8;
            color: #2E7D32;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            min-height: 40px;
            min-width: 120px;
            white-space: nowrap;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            justify-content: center;
            margin: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .action-btn i {
            font-size: 14px;
            width: 16px;
            text-align: center;
        }

        .mark-read-btn {
            background: #e3f2fd;
            color: #1976d2;
            border: 1px solid rgba(25, 118, 210, 0.2);
        }

        .mark-read-btn:hover {
            background: #bbdefb;
            color: #1565c0;
        }

        .delete-btn {
            background: #ffebee;
            color: #d32f2f;
            border: 1px solid rgba(211, 47, 47, 0.2);
        }

        .delete-btn:hover {
            background: #ffcdd2;
            color: #c62828;
        }

        .bulk-actions {
            background: white;
            padding: 20px 25px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 20px;
            color: #81C784;
            opacity: 0.6;
        }
    </style>
    <script src="custom_modal.js"></script>
    <script src="custom_notification.js"></script>
</head>
<body>
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
                    <a href="Admin_dashboard1.php" class="nav-link">
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
                    <a href="admin_notifications.php" class="nav-link active">
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
        <header class="admin-header">
            <div class="header-title">
                <img src="assets/payatas logo.png" alt="Payatas B Logo" class="barangay-seal">
                <div>
                    <h1>HealthServe - Payatas B</h1>
                    <p>Notifications Management</p>
                </div>
            </div>
        </header>

        <div class="content-area">
            <div class="page-header">
                <h2 class="page-title">Notifications</h2>
                <div class="breadcrumb">Dashboard > Notifications</div>
            </div>

            <div class="notifications-container">
                <div class="notifications-header">
                    <div>
                        <h3 style="color:#2E7D32;margin:0 0 8px 0;">All Notifications</h3>
                        <p style="color:#666;margin:0;"><?php echo count(array_filter($notifications, function($n) { return !$n['read']; })); ?> unread notifications</p>
                    </div>
                    <div class="notification-filters">
                        <button class="filter-btn active" data-filter="all">All</button>
                        <button class="filter-btn" data-filter="unread">Unread</button>
                        <button class="filter-btn" data-filter="announcement">Announcements</button>
                    </div>
                </div>

                <div class="bulk-actions">
                    <button class="btn btn-primary" onclick="markAllAsRead()">
                        <i class="fas fa-check-double"></i> Mark All as Read
                    </button>
                    <button class="btn btn-secondary" onclick="clearAllNotifications()">
                        <i class="fas fa-trash"></i> Clear All
                    </button>
                </div>

                <div class="notifications-list">
                    <?php if (empty($notifications)): ?>
                        <div class="empty-state">
                            <i class="fas fa-bell-slash"></i>
                            <p>No notifications found</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item <?php echo !$notification['read'] ? 'unread' : ''; ?>" 
                             data-type="<?php echo $notification['type']; ?>" 
                             data-id="<?php echo $notification['id']; ?>">
                            <div class="notification-icon <?php echo $notification['type']; ?>">
                                <i class="fas fa-bullhorn"></i>
                            </div>
                            
                            <div class="notification-content">
                                <div class="notification-title">
                                    <?php echo $notification['title']; ?>
                                    <?php if (!$notification['read']): ?>
                                    <span class="notification-badge <?php echo $notification['priority']; ?>">
                                        <?php echo strtoupper($notification['priority']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="notification-message">
                                    <?php echo $notification['message']; ?>
                                </div>
                                <div class="notification-time">
                                    <i class="fas fa-clock"></i> <?php echo $notification['time']; ?>
                                </div>
                            </div>
                            
                            <div class="notification-actions">
                                <?php if (!$notification['read']): ?>
                                <button class="action-btn mark-read-btn" onclick="markAsRead(<?php echo $notification['id']; ?>)">
                                    <i class="fas fa-check"></i> Mark as Read
                                </button>
                                <?php endif; ?>
                                <button class="action-btn delete-btn" onclick="deleteNotification(<?php echo $notification['id']; ?>)">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Filter functionality
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                // Update active filter
                document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const filter = this.dataset.filter;
                const notifications = document.querySelectorAll('.notification-item');
                
                notifications.forEach(notification => {
                    if (filter === 'all') {
                        notification.style.display = 'flex';
                    } else if (filter === 'unread') {
                        notification.style.display = notification.classList.contains('unread') ? 'flex' : 'none';
                    } else {
                        notification.style.display = notification.dataset.type === filter ? 'flex' : 'none';
                    }
                });
            });
        });

        // Mark as read functionality
        async function markAsRead(id) {
            try {
                const response = await fetch('admin_mark_notification_read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ notification_id: id })
                });
                
                const data = await response.json();
                if (data.success) {
                    const notification = document.querySelector(`[data-id="${id}"]`);
                    notification.classList.remove('unread');
                    const markReadBtn = notification.querySelector('.mark-read-btn');
                    if (markReadBtn) {
                        markReadBtn.remove();
                    }
                }
            } catch (error) {
                console.error('Error marking notification as read:', error);
                // Still update UI even if API call fails
                const notification = document.querySelector(`[data-id="${id}"]`);
                notification.classList.remove('unread');
                const markReadBtn = notification.querySelector('.mark-read-btn');
                if (markReadBtn) {
                    markReadBtn.remove();
                }
            }
        }

        // Delete notification
        async function deleteNotification(id) {
            const confirmed = await confirm('Are you sure you want to delete this notification?');
            if (confirmed) {
                try {
                    const response = await fetch('admin_delete_notification.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ notification_id: id })
                    });
                    
                    const data = await response.json();
                    if (data.success) {
                        const notification = document.querySelector(`[data-id="${id}"]`);
                        notification.style.opacity = '0';
                        setTimeout(() => {
                            notification.remove();
                        }, 300);
                    } else {
                        alert('Error deleting notification: ' + (data.message || 'Unknown error'));
                    }
                } catch (error) {
                    console.error('Error deleting notification:', error);
                    alert('Error deleting notification');
                }
            }
        }

        // Mark all as read
        async function markAllAsRead() {
            try {
                const response = await fetch('admin_mark_all_notifications_read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                });
                
                const data = await response.json();
                if (data.success) {
                    document.querySelectorAll('.notification-item.unread').forEach(notification => {
                        notification.classList.remove('unread');
                        const markReadBtn = notification.querySelector('.mark-read-btn');
                        if (markReadBtn) {
                            markReadBtn.remove();
                        }
                    });
                }
            } catch (error) {
                console.error('Error marking all notifications as read:', error);
                // Still update UI even if API call fails
                document.querySelectorAll('.notification-item.unread').forEach(notification => {
                    notification.classList.remove('unread');
                    const markReadBtn = notification.querySelector('.mark-read-btn');
                    if (markReadBtn) {
                        markReadBtn.remove();
                    }
                });
            }
        }

        // Clear all notifications
        async function clearAllNotifications() {
            const confirmed = await confirm('Are you sure you want to clear all notifications? This action cannot be undone.');
            if (confirmed) {
                try {
                    const response = await fetch('admin_delete_all_notifications.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        }
                    });
                    
                    const data = await response.json();
                    if (data.success) {
                        document.querySelectorAll('.notification-item').forEach(notification => {
                            notification.style.opacity = '0';
                            setTimeout(() => {
                                notification.remove();
                            }, 300);
                        });
                    } else {
                        alert('Error clearing notifications: ' + (data.message || 'Unknown error'));
                    }
                } catch (error) {
                    console.error('Error clearing notifications:', error);
                    alert('Error clearing notifications');
                }
            }
        }

        // Click notification to view details
        document.querySelectorAll('.notification-item').forEach(notification => {
            notification.addEventListener('click', function(e) {
                // Don't trigger if clicking on action buttons
                if (e.target.classList.contains('action-btn') || e.target.parentElement.classList.contains('action-btn')) {
                    return;
                }
                
                // Mark as read if unread
                if (this.classList.contains('unread')) {
                    const id = this.dataset.id;
                    markAsRead(id);
                }
                
                // Here you could show more details or navigate to relevant page
                console.log('Notification clicked:', this.dataset.id);
            });
        });
    </script>
</body>
</html>