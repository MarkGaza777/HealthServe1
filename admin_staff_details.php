<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: Login.php");
    exit();
}

$staff_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$staff_id) {
    header("Location: admin_staff_management.php");
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM staff WHERE id = ?");
    $stmt->execute([$staff_id]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$staff) {
        header("Location: admin_staff_management.php");
        exit();
    }
} catch(PDOException $e) {
    $error = "Error fetching staff data: " . $e->getMessage();
    $staff = null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Details - HealthServe Admin</title>
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
                    <a href="Admin_dashboard1.php" class="nav-link">
                        <div class="nav-icon"><i class="fas fa-th-large"></i></div>
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_staff_management.php" class="nav-link active">
                        <div class="nav-icon"><i class="fas fa-user-friends"></i></div>
                        Staffs
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
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
                <h2 class="page-title">Staff Details</h2>
                <a href="admin_staff_management.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Staff List
                </a>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-error" style="padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; background: #FFEBEE; color: #C62828; border: 1px solid #F44336;">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($staff): ?>
            <div class="staff-details-container">
                <!-- Profile Photo Section -->
                <div class="profile-photo-section" style="text-align: center; margin-bottom: 40px;">
                    <div class="staff-photo-large" style="width: 200px; height: 200px; border-radius: 50%; margin: 0 auto 20px; overflow: hidden; border: 4px solid #4CAF50; background: linear-gradient(135deg, #4CAF50, #66BB6A); display: flex; align-items: center; justify-content: center; color: white; font-size: 72px; font-weight: bold;">
                        <?php
                        if (!empty($staff['photo_path']) && file_exists($staff['photo_path'])) {
                            echo '<img src="' . htmlspecialchars($staff['photo_path']) . '" alt="' . htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']) . '" style="width: 100%; height: 100%; object-fit: cover;">';
                        } else {
                            $initials = substr($staff['first_name'], 0, 1) . substr($staff['last_name'], 0, 1);
                            echo strtoupper($initials);
                        }
                        ?>
                    </div>
                    <h2 style="color: #2E7D32; margin: 0;">
                        <?php echo htmlspecialchars($staff['first_name'] . ' ' . ($staff['middle_name'] ? $staff['middle_name'] . ' ' : '') . $staff['last_name']); ?>
                    </h2>
                    <p style="color: #666; margin: 5px 0 0;">Staff ID: <?php echo $staff['id']; ?></p>
                </div>

                <!-- Details Grid -->
                <div class="details-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; margin-bottom: 30px;">
                    <div class="detail-card" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        <div class="detail-label" style="color: #666; font-size: 14px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">First Name</div>
                        <div class="detail-value" style="color: #333; font-size: 18px; font-weight: 600;"><?php echo htmlspecialchars($staff['first_name']); ?></div>
                    </div>

                    <?php if (!empty($staff['middle_name'])): ?>
                    <div class="detail-card" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        <div class="detail-label" style="color: #666; font-size: 14px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">Middle Name</div>
                        <div class="detail-value" style="color: #333; font-size: 18px; font-weight: 600;"><?php echo htmlspecialchars($staff['middle_name']); ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="detail-card" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        <div class="detail-label" style="color: #666; font-size: 14px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">Last Name</div>
                        <div class="detail-value" style="color: #333; font-size: 18px; font-weight: 600;"><?php echo htmlspecialchars($staff['last_name']); ?></div>
                    </div>

                    <div class="detail-card" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        <div class="detail-label" style="color: #666; font-size: 14px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">Role/Position</div>
                        <div class="detail-value" style="color: #333; font-size: 18px; font-weight: 600;">
                            <span class="role-badge role-<?php echo strtolower($staff['role']); ?>" style="padding: 6px 16px; border-radius: 20px; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">
                                <?php echo ucfirst($staff['role']); ?>
                            </span>
                        </div>
                    </div>

                    <div class="detail-card" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        <div class="detail-label" style="color: #666; font-size: 14px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">Contact Number</div>
                        <div class="detail-value" style="color: #333; font-size: 18px; font-weight: 600;"><?php echo htmlspecialchars($staff['phone'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="detail-card" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        <div class="detail-label" style="color: #666; font-size: 14px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">Department</div>
                        <div class="detail-value" style="color: #333; font-size: 18px; font-weight: 600;"><?php echo ucfirst(htmlspecialchars($staff['department'] ?? 'N/A')); ?></div>
                    </div>

                    <div class="detail-card" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        <div class="detail-label" style="color: #666; font-size: 14px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">Status</div>
                        <div class="detail-value" style="color: #333; font-size: 18px; font-weight: 600;">
                            <?php if ($staff['status'] === 'active'): ?>
                                <span class="status-badge status-active" style="padding: 6px 16px; border-radius: 20px; font-size: 14px; background: #E8F5E8; color: #2E7D32;">Active</span>
                            <?php else: ?>
                                <span class="status-badge status-inactive" style="padding: 6px 16px; border-radius: 20px; font-size: 14px; background: #FFEBEE; color: #C62828;">Inactive</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="detail-card" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        <div class="detail-label" style="color: #666; font-size: 14px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">Shift Status</div>
                        <div class="detail-value" style="color: #333; font-size: 18px; font-weight: 600;">
                            <?php if ($staff['shift_status'] === 'on_duty'): ?>
                                <span class="status-badge status-active" style="padding: 6px 16px; border-radius: 20px; font-size: 14px; background: #E3F2FD; color: #1565C0;">On Duty</span>
                            <?php else: ?>
                                <span class="status-badge status-inactive" style="padding: 6px 16px; border-radius: 20px; font-size: 14px; background: #FFF3E0; color: #F57C00;">Off Duty</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="detail-card" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        <div class="detail-label" style="color: #666; font-size: 14px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">Date Added</div>
                        <div class="detail-value" style="color: #333; font-size: 18px; font-weight: 600;"><?php echo date('F d, Y', strtotime($staff['created_at'])); ?></div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="action-buttons" style="display: flex; gap: 15px; justify-content: center; margin-top: 40px;">
                    <a href="admin_edit_staff.php?id=<?php echo $staff['id']; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit Staff
                    </a>
                    <a href="admin_staff_management.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <style>
        .role-badge.role-physician {
            background: #E3F2FD;
            color: #1565C0;
        }
        
        .role-badge.role-nurse {
            background: #E8F5E8;
            color: #2E7D32;
        }
        
        .role-badge.role-midwife {
            background: #FFF3E0;
            color: #F57C00;
        }
        
        .role-badge.role-bhw {
            background: #FCE4EC;
            color: #C2185B;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #4CAF50;
            color: white;
        }
        
        .btn-primary:hover {
            background: #45a049;
        }
        
        .btn-secondary {
            background: #757575;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #616161;
        }
    </style>
</body>
</html>

