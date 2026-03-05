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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'update_status') {
            $shift_status = $_POST['shift_status'];
            
            try {
                // Check if shift_status enum includes 'on_leave', if not, we'll use 'off_duty' for on leave
                $stmt = $pdo->prepare("UPDATE staff SET shift_status = ? WHERE id = ?");
                $stmt->execute([$shift_status, $staff_id]);
                
                $success = "Staff status updated successfully.";
                header("Location: admin_edit_staff.php?id=$staff_id&success=1");
                exit();
            } catch(PDOException $e) {
                $error = "Error updating staff status: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'delete') {
            // Delete confirmation is handled by JavaScript, but we process it here
            try {
                // Delete profile photo if exists
                $stmt = $pdo->prepare("SELECT photo_path FROM staff WHERE id = ?");
                $stmt->execute([$staff_id]);
                $staff = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($staff && !empty($staff['photo_path']) && file_exists($staff['photo_path'])) {
                    unlink($staff['photo_path']);
                }
                
                // Delete staff record
                $stmt = $pdo->prepare("DELETE FROM staff WHERE id = ?");
                $stmt->execute([$staff_id]);
                
                header("Location: admin_staff_management.php?deleted=1");
                exit();
            } catch(PDOException $e) {
                $error = "Error deleting staff member: " . $e->getMessage();
            }
        }
    }
}

// Fetch staff data
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

// Check if shift_status enum supports 'on_leave'
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM staff WHERE Field = 'shift_status'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    $has_on_leave = false;
    if ($column && isset($column['Type'])) {
        $has_on_leave = strpos($column['Type'], 'on_leave') !== false;
    }
} catch(PDOException $e) {
    $has_on_leave = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Staff - HealthServe Admin</title>
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
                <h2 class="page-title">Edit Staff</h2>
                <a href="admin_staff_management.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Staff List
                </a>
            </div>

            <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
                <div class="alert alert-success" style="padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; background: #E8F5E8; color: #2E7D32; border: 1px solid #4CAF50;">
                    <i class="fas fa-check-circle"></i> Staff status updated successfully!
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-error" style="padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; background: #FFEBEE; color: #C62828; border: 1px solid #F44336;">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($staff): ?>
            <div class="edit-staff-container">
                <!-- Staff Info Display -->
                <div class="staff-info-header" style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 30px; display: flex; align-items: center; gap: 20px;">
                    <div class="staff-avatar-large" style="width: 100px; height: 100px; border-radius: 50%; overflow: hidden; border: 3px solid #4CAF50; background: linear-gradient(135deg, #4CAF50, #66BB6A); display: flex; align-items: center; justify-content: center; color: white; font-size: 36px; font-weight: bold; flex-shrink: 0;">
                        <?php
                        if (!empty($staff['photo_path']) && file_exists($staff['photo_path'])) {
                            echo '<img src="' . htmlspecialchars($staff['photo_path']) . '" alt="' . htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']) . '" style="width: 100%; height: 100%; object-fit: cover;">';
                        } else {
                            $initials = substr($staff['first_name'], 0, 1) . substr($staff['last_name'], 0, 1);
                            echo strtoupper($initials);
                        }
                        ?>
                    </div>
                    <div>
                        <h3 style="color: #2E7D32; margin: 0 0 5px 0;">
                            <?php echo htmlspecialchars($staff['first_name'] . ' ' . ($staff['middle_name'] ? $staff['middle_name'] . ' ' : '') . $staff['last_name']); ?>
                        </h3>
                        <p style="color: #666; margin: 0;"><?php echo ucfirst($staff['role']); ?> • ID: <?php echo $staff['id']; ?></p>
                    </div>
                </div>

                <!-- Status Management Form -->
                <div class="form-container" style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 30px;">
                    <h3 style="color: #2E7D32; margin-bottom: 25px;">Staff Status Management</h3>
                    
                    <form method="POST" id="statusForm">
                        <input type="hidden" name="action" value="update_status">
                        
                        <div class="form-group" style="margin-bottom: 25px;">
                            <label for="shift_status" style="display: block; margin-bottom: 10px; color: #333; font-weight: 600;">Current Status</label>
                            <select id="shift_status" name="shift_status" class="form-control" required style="width: 100%; padding: 12px; border: 2px solid #E0E0E0; border-radius: 8px; font-size: 16px;">
                                <option value="on_duty" <?php echo ($staff['shift_status'] === 'on_duty') ? 'selected' : ''; ?>>On Duty</option>
                                <option value="off_duty" <?php echo ($staff['shift_status'] === 'off_duty') ? 'selected' : ''; ?>>On Leave</option>
                            </select>
                            <small style="color: #666; display: block; margin-top: 8px;">Select "On Duty" if the staff is currently working, or "On Leave" if they are on leave.</small>
                        </div>

                        <div class="form-actions" style="display: flex; gap: 15px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Status
                            </button>
                            <a href="admin_staff_details.php?id=<?php echo $staff['id']; ?>" class="btn btn-secondary">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Delete Section -->
                <div class="delete-section" style="background: #FFEBEE; padding: 30px; border-radius: 12px; border: 2px solid #F44336;">
                    <h3 style="color: #C62828; margin-bottom: 15px;">Danger Zone</h3>
                    <p style="color: #666; margin-bottom: 20px;">Once you delete a staff member, there is no going back. Please be certain.</p>
                    
                    <form method="POST" id="deleteForm" onsubmit="event.preventDefault(); confirmDeleteStaff();">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="btn btn-danger" style="background: #F44336; color: white; padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 500;">
                            <i class="fas fa-trash-alt"></i> Delete Staff Member
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="custom_modal.js"></script>
    <script src="custom_notification.js"></script>
    <script>
        function confirmDeleteStaff() {
            // Get staff name from the page
            const staffName = document.querySelector('h2')?.textContent || 'this staff member';
            showDeleteConfirm(staffName, 'Staff Member', function() {
                document.getElementById('deleteForm').submit();
            }, 'This action cannot be undone. All associated records will be affected.');
        }
    </script>

    <style>
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
        
        .btn-danger {
            background: #F44336;
            color: white;
        }
        
        .btn-danger:hover {
            background: #D32F2F;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #4CAF50;
        }
    </style>
</body>
</html>

