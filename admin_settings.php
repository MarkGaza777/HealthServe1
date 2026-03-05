<?php
session_start();
require_once 'db.php';
require_once 'admin_helpers_simple.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: Login.php");
    exit();
}

$admin_id = $_SESSION['user']['id'];

// Get current admin user data
try {
    // Check if photo_path column exists, if not add it
    try {
        $checkColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'photo_path'");
        if ($checkColumn->rowCount() == 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN photo_path VARCHAR(255) DEFAULT NULL");
        }
    } catch(PDOException $e) {
        // Column might already exist, continue
    }
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$admin_id]);
    $admin_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin_user) {
        header("Location: Login.php");
        exit();
    }
} catch (PDOException $e) {
    $admin_user = ['first_name' => '', 'last_name' => '', 'middle_name' => '', 'email' => '', 'contact_no' => '', 'address' => '', 'photo_path' => null];
}

// Get profile picture path
$profile_picture = 'assets/images/admin-avatar.png'; // Default
if (!empty($admin_user['photo_path']) && file_exists($admin_user['photo_path'])) {
    $profile_picture = $admin_user['photo_path'];
}

// Get system settings
$health_center = getHealthCenterInfo();

// Initialize message variables (for display)
$success_message = '';
$error_message = '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - HealthServe Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/Style1.css">
    <style>
        .settings-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .settings-tabs {
            display: flex;
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            overflow: hidden;
        }

        .settings-nav {
            width: 300px;
            background: #f8f9fa;
            padding: 0;
            border-right: 1px solid #e0e0e0;
        }

        .settings-nav-item {
            display: block;
            padding: 20px 25px;
            color: #374151; /* dark gray for readability */
            text-decoration: none;
            border-bottom: 1px solid #e0e0e0;
            transition: all 0.25s ease;
            font-weight: 500;
        }

        /* Hover state - subtle but visible */
        .settings-nav-item:hover:not(.active) {
            background: #f1f5f9; /* light slate */
            color: #166534;      /* deep green text */
        }

        /* Active state - vibrant and high-contrast */
        .settings-nav-item.active {
            background: #e8f5e9;      /* pale green */
            color: #1b5e20;           /* dark green text */
            font-weight: 600;
            border-left: 4px solid #2e7d32;
            padding-left: 21px;       /* compensate for border-left */
        }

        .settings-nav-item i {
            margin-right: 10px;
            width: 20px;
        }

        .settings-content {
            flex: 1;
            padding: 35px;
            background: #fafafa;
        }

        .settings-section {
            display: none;
        }

        .settings-section.active {
            display: block;
        }

        .settings-group {
            margin-bottom: 40px;
        }

        .settings-group h3 {
            color: #2E7D32;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
            font-size: 20px;
            font-weight: 600;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2E7D32;
            font-size: 14px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #fafafa;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #4CAF50;
            background: white;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .switch-group {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.3s ease;
            border-radius: 8px;
            margin-bottom: 8px;
        }

        .switch-group:hover {
            background-color: #f8f9fa;
        }

        .switch-info {
            flex: 1;
        }

        .switch-info h4 {
            margin: 0 0 5px 0;
            color: #2E7D32;
            font-weight: 600;
        }

        .switch-info p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: #4CAF50;
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        .backup-section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1px solid #f0f0f0;
        }

        .backup-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px;
            border-bottom: 1px solid #e0e0e0;
            transition: background-color 0.3s ease;
            border-radius: 8px;
            margin-bottom: 8px;
        }

        .backup-item:hover {
            background-color: #f8f9fa;
        }

        .backup-item:last-child {
            border-bottom: none;
        }

        .backup-info h4 {
            margin: 0 0 5px 0;
            color: #2E7D32;
            font-weight: 600;
        }

        .backup-info p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .btn-primary {
            background: #4CAF50;
            color: white;
        }

        .btn-primary:hover {
            background: #45a049;
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
            transform: translateY(-1px);
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
            transform: translateY(-1px);
        }

        .alert {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 10px;
            border: 1px solid transparent;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        .profile-picture {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .profile-picture img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid #4CAF50;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            object-fit: cover;
            display: block;
            margin: 0 auto;
        }

        .upload-btn {
            margin-top: 15px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
        }

        .stat-card h3 {
            margin: 0 0 10px 0;
            color: #2E7D32;
            font-size: 24px;
        }

        .usage-stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .usage-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
        }

        .usage-stat-icon-wrapper {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            flex-shrink: 0;
        }

        .usage-stat-icon-wrapper.logins {
            background: linear-gradient(135deg, #4CAF50, #388E3C);
        }

        .usage-stat-icon-wrapper.appointments {
            background: linear-gradient(135deg, #66BB6A, #4CAF50);
        }

        .usage-stat-icon-wrapper.announcements {
            background: linear-gradient(135deg, #81C784, #66BB6A);
        }

        .usage-stat-icon-wrapper.notifications {
            background: linear-gradient(135deg, #A5D6A7, #81C784);
        }

        .usage-stat-details {
            flex: 1;
        }

        .usage-stat-details h3 {
            font-size: 32px;
            font-weight: 700;
            color: #2E7D32;
            margin: 0 0 5px 0;
        }

        .usage-stat-details p {
            color: #666;
            font-size: 14px;
            font-weight: 500;
            margin: 0;
        }

        .usage-stat-breakdown {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e0e0e0;
            font-size: 13px;
            color: #666;
        }

        .usage-stat-breakdown div {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
        }

        .usage-stat-breakdown div:last-child {
            margin-bottom: 0;
        }

        .usage-stat-breakdown strong {
            color: #2E7D32;
            font-weight: 600;
        }

        .audit-log {
            max-height: 600px;
            overflow-y: auto;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .audit-item {
            padding: 18px 20px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            transition: background-color 0.3s ease;
        }

        .audit-item:hover {
            background-color: #f8f9fa;
        }

        .audit-item:last-child {
            border-bottom: none;
        }

        .audit-action {
            font-weight: 600;
            color: #333;
            font-size: 15px;
            margin-bottom: 5px;
        }

        .audit-user {
            color: #2E7D32;
            font-weight: 500;
            font-size: 14px;
            margin-top: 3px;
        }

        .audit-time {
            color: #666;
            font-size: 12px;
            text-align: right;
            white-space: nowrap;
            margin-left: 20px;
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
                    <a href="admin_notifications.php" class="nav-link">
                        <div class="nav-icon"><i class="fas fa-bell"></i></div>
                        Notifications
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_settings.php" class="nav-link active">
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
                    <p>Barangay Health Center Management System</p>
                </div>
            </div>
        </header>

        <div class="content-area">
            <div class="page-header">
                <h2 class="page-title">Settings</h2>
                <div class="breadcrumb">Dashboard > Settings</div>
            </div>

            <div class="settings-container">
                <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
                <?php endif; ?>

                <div class="settings-tabs">
                    <div class="settings-nav">
                        <a href="#" class="settings-nav-item active" data-section="profile">
                            <i class="fas fa-user"></i> Profile Settings
                        </a>
                        <a href="#" class="settings-nav-item" data-section="health_center">
                            <i class="fas fa-hospital"></i> Health Center Info
                        </a>
                        <a href="#" class="settings-nav-item" data-section="usage">
                            <i class="fas fa-chart-bar"></i> System Usage Report
                        </a>
                    </div>

                    <div class="settings-content">
                        <!-- Profile Settings -->
                        <div class="settings-section active" id="profile">
                            <div class="profile-picture">
                                <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" id="profileImg" onerror="this.src='assets/images/admin-avatar.png'">
                                <br>
                                <button class="btn btn-secondary upload-btn" onclick="document.getElementById('profileUpload').click()">
                                    <i class="fas fa-camera"></i> Change Photo
                                </button>
                                <input type="file" id="profileUpload" style="display: none;" accept="image/jpeg,image/jpg,image/png,image/gif">
                            </div>

                            <form id="profileForm" onsubmit="updateProfile(event)">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>First Name</label>
                                        <input type="text" name="first_name" id="first_name" value="<?php echo htmlspecialchars($admin_user['first_name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Last Name</label>
                                        <input type="text" name="last_name" id="last_name" value="<?php echo htmlspecialchars($admin_user['last_name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Middle Name</label>
                                        <input type="text" name="middle_name" id="middle_name" value="<?php echo htmlspecialchars($admin_user['middle_name'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Email Address</label>
                                        <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($admin_user['email'] ?? ''); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Phone Number</label>
                                        <input type="tel" name="contact_no" id="contact_no" value="<?php echo htmlspecialchars($admin_user['contact_no'] ?? ''); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Address</label>
                                        <input type="text" name="address" id="address" value="<?php echo htmlspecialchars($admin_user['address'] ?? ''); ?>">
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Profile
                                </button>
                            </form>

                            <div class="settings-group" style="margin-top: 40px;">
                                <h3>Change Password</h3>
                                <form id="passwordForm" onsubmit="changePassword(event)">
                                    <div class="form-group">
                                        <label>Current Password</label>
                                        <input type="password" name="current_password" id="current_password" required>
                                    </div>
                                    <div class="form-group">
                                        <label>New Password</label>
                                        <input type="password" name="new_password" id="new_password" required minlength="8">
                                        <small style="color: #666; font-size: 12px;">Must be at least 8 characters long</small>
                                </div>
                                <div class="form-group">
                                        <label>Confirm New Password</label>
                                        <input type="password" name="confirm_password" id="confirm_password" required>
                                </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-key"></i> Change Password
                                </button>
                            </form>
                            </div>
                        </div>

                        <!-- Health Center Information -->
                        <div class="settings-section" id="health_center">
                            <form id="healthCenterForm" onsubmit="updateHealthCenter(event)">
                                <div class="settings-group">
                                    <h3>Health Center Information</h3>
                                    <p style="color: #666; margin-bottom: 20px; font-size: 14px;">
                                        Update health center information that will be displayed across the system in headers, reports, and documents.
                                    </p>
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label>Health Center Name</label>
                                            <input type="text" name="center_name" id="center_name" value="<?php echo htmlspecialchars($health_center['name']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Address</label>
                                            <input type="text" name="address" id="address" value="<?php echo htmlspecialchars($health_center['address']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Contact Number</label>
                                            <input type="tel" name="contact" id="contact" value="<?php echo htmlspecialchars($health_center['contact']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Email</label>
                                            <input type="email" name="center_email" id="center_email" value="<?php echo htmlspecialchars($health_center['email']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Operating Hours</label>
                                            <input type="text" name="hours" id="hours" value="<?php echo htmlspecialchars($health_center['operating_hours']); ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary" id="updateHealthCenterBtn">
                                    <i class="fas fa-save"></i> Update Health Center Information
                                </button>
                            </form>
                        </div>

                        <!-- System Usage Report -->
                        <div class="settings-section" id="usage">
                            <div class="settings-group">
                                <h3>System Usage Report</h3>
                                <p style="color: #666; margin-bottom: 20px; font-size: 14px;">
                                    <i class="fas fa-info-circle"></i> View summarized statistics about how the HealthServe system is being used. This report provides an overview of system activity and usage metrics.
                                </p>
                                
                                <!-- Filter Section -->
                                <div class="usage-filters" style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                                    <h4 style="margin-top: 0; margin-bottom: 15px; color: #2e3b4e;">
                                        <i class="fas fa-filter"></i> Filter Report
                                    </h4>
                                    <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: end;">
                                        <div style="display: flex; gap: 10px;">
                                            <button class="btn btn-secondary" onclick="setUsageFilter('today')" style="padding: 8px 15px; font-size: 14px;">
                                                <i class="fas fa-calendar-day"></i> Today
                                            </button>
                                            <button class="btn btn-secondary" onclick="setUsageFilter('week')" style="padding: 8px 15px; font-size: 14px;">
                                                <i class="fas fa-calendar-week"></i> This Week
                                            </button>
                                            <button class="btn btn-secondary" onclick="setUsageFilter('month')" style="padding: 8px 15px; font-size: 14px;">
                                                <i class="fas fa-calendar-alt"></i> This Month
                                            </button>
                                            <button class="btn btn-secondary" onclick="setUsageFilter('all')" style="padding: 8px 15px; font-size: 14px;">
                                                <i class="fas fa-list"></i> All Time
                                            </button>
                                        </div>
                                        <div style="display: flex; gap: 10px; align-items: center;">
                                            <label style="font-size: 14px; color: #666; white-space: nowrap;">Custom Range:</label>
                                            <input type="date" id="usageDateFrom" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                                            <span style="color: #666;">to</span>
                                            <input type="date" id="usageDateTo" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                                            <button class="btn btn-primary" onclick="applyCustomUsageFilter()" style="padding: 8px 15px; font-size: 14px;">
                                                <i class="fas fa-search"></i> Apply
                                            </button>
                                        </div>
                                    </div>
                                    <div id="usageFilterStatus" style="margin-top: 10px; font-size: 13px; color: #666;"></div>
                                </div>
                                
                                <!-- Usage Statistics Cards -->
                                <div id="usageStatsContainer" class="stats-grid">
                                    <div style="text-align: center; padding: 40px; color: #666; grid-column: 1 / -1;">
                                        <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i>
                                        <p style="margin-top: 10px;">Loading usage statistics...</p>
                                    </div>
                                </div>
                                
                                <!-- Refresh Button -->
                                <div style="text-align: center; margin-top: 20px;">
                                    <button class="btn btn-primary" onclick="loadUsageStats()" style="padding: 10px 20px; font-size: 14px;">
                                        <i class="fas fa-sync"></i> Refresh Report
                                    </button>
                                </div>
                            </div>
                        </div>

                                        </div>
                                    </div>
                                </div>
        </div>
    </div>

    <script>
        const API_URL = 'admin_settings_api_simple.php';
        
        // Tab navigation
        document.querySelectorAll('.settings-nav-item').forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Remove active class from all nav items and sections
                document.querySelectorAll('.settings-nav-item').forEach(nav => nav.classList.remove('active'));
                document.querySelectorAll('.settings-section').forEach(section => section.classList.remove('active'));
                
                // Add active class to clicked nav item
                this.classList.add('active');
                
                // Show corresponding section
                const sectionId = this.dataset.section;
                document.getElementById(sectionId).classList.add('active');
                
                // Load data for specific sections
                if (sectionId === 'usage') {
                    loadUsageStats();
                }
            });
        });

        // Profile picture upload
        document.getElementById('profileUpload').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    showMessage('Please select a valid image file (JPEG, PNG, or GIF)', 'error');
                    return;
                }
                
                // Validate file size (5MB max)
                if (file.size > 5 * 1024 * 1024) {
                    showMessage('File size exceeds 5MB limit', 'error');
                    return;
                }
                
                // Show preview immediately
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('profileImg').src = e.target.result;
                };
                reader.readAsDataURL(file);
                
                // Upload the file
                uploadProfilePicture(file);
            }
        });
        
        // Upload Profile Picture
        async function uploadProfilePicture(file) {
            const formData = new FormData();
            formData.append('action', 'upload_profile_picture');
            formData.append('profile_picture', file);
            
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message, 'success');
                    // Update image source to the saved path
                    if (result.photo_path) {
                        document.getElementById('profileImg').src = result.photo_path + '?t=' + new Date().getTime();
                    }
                } else {
                    showMessage(result.message, 'error');
                    // Revert to original image on error
                    location.reload();
                }
            } catch (error) {
                showMessage('Error uploading profile picture: ' + error.message, 'error');
                location.reload();
            }
        }

        // Update Profile
        async function updateProfile(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            formData.append('action', 'update_profile');
            
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message, 'success');
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('Error updating profile: ' + error.message, 'error');
            }
        }

        // Update form values with saved data
        function updateFormValues(healthCenter) {
            if (document.getElementById('center_name')) {
                document.getElementById('center_name').value = healthCenter.name || '';
            }
            if (document.getElementById('address')) {
                document.getElementById('address').value = healthCenter.address || '';
            }
            if (document.getElementById('contact')) {
                document.getElementById('contact').value = healthCenter.contact || '';
            }
            if (document.getElementById('center_email')) {
                document.getElementById('center_email').value = healthCenter.email || '';
            }
            if (document.getElementById('hours')) {
                document.getElementById('hours').value = healthCenter.operating_hours || '';
            }
        }

        // Update Health Center Information (button click only - no auto-save)
        async function updateHealthCenter(e) {
            e.preventDefault();
            
            // Get the form element
            const form = e.target;
            if (!form || form.tagName !== 'FORM') {
                console.error('Form element not found');
                showMessage('Error: Form not found', 'error');
                return;
            }
            
            // Validate form before submission
            if (!form.checkValidity()) {
                form.reportValidity();
                showMessage('Please fill in all required fields correctly', 'error');
                return;
            }
            
            // Collect form data
            const formData = new FormData(form);
            formData.append('action', 'update_health_center');
            
            // Validate required fields
            const centerName = formData.get('center_name')?.trim();
            const address = formData.get('address')?.trim();
            const contact = formData.get('contact')?.trim();
            const email = formData.get('center_email')?.trim();
            const hours = formData.get('hours')?.trim();
            
            if (!centerName || !address || !contact || !email || !hours) {
                showMessage('All fields are required', 'error');
                return;
            }
            
            // Validate email format
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                showMessage('Please enter a valid email address', 'error');
                return;
            }
            
            // Get submit button
            const submitBtn = form.querySelector('button[type="submit"]');
            if (!submitBtn) {
                console.error('Submit button not found');
                showMessage('Error: Submit button not found', 'error');
                return;
            }
            
            // Disable button during save
            const originalBtnText = submitBtn.innerHTML;
            const originalBtnDisabled = submitBtn.disabled;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            
            try {
                // Make API request
                const response = await fetch(API_URL, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                // Check if response is OK
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                // Parse JSON response
                let result;
                try {
                    result = await response.json();
                } catch (parseError) {
                    console.error('JSON parse error:', parseError);
                    throw new Error('Invalid response from server');
                }
                
                if (result.success) {
                    // Show success message
                    showMessage(result.message || 'Health center information updated successfully', 'success');
                    
                    // Immediately update form values with saved data to reflect changes
                    if (result.health_center) {
                        updateFormValues(result.health_center);
                    }
                    
                    // Log success for debugging
                    console.log('Health center information updated successfully', result.health_center);
                } else {
                    const errorMsg = result.message || 'Failed to update health center information';
                    showMessage(errorMsg, 'error');
                    console.error('Update failed:', result);
                }
            } catch (error) {
                console.error('Error updating health center information:', error);
                const errorMsg = error.message || 'An unexpected error occurred. Please try again.';
                showMessage('Error: ' + errorMsg, 'error');
            } finally {
                // Re-enable button
                submitBtn.disabled = originalBtnDisabled;
                submitBtn.innerHTML = originalBtnText;
            }
        }

        // Change Password
        async function changePassword(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            formData.append('action', 'change_password');
            
            // Validate password match
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (newPassword !== confirmPassword) {
                showMessage('New passwords do not match', 'error');
                return;
            }
            
            if (newPassword.length < 8) {
                showMessage('Password must be at least 8 characters long', 'error');
                return;
            }
            
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message, 'success');
                    e.target.reset();
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('Error changing password: ' + error.message, 'error');
            }
        }

        // Current filter state for usage report
        let currentUsageDateFrom = null;
        let currentUsageDateTo = null;

        // Set usage filter (today, week, month, all)
        function setUsageFilter(period) {
            const today = new Date();
            let fromDate = null;
            let toDate = null;
            
            switch(period) {
                case 'today':
                    fromDate = toDate = today.toISOString().split('T')[0];
                    break;
                case 'week':
                    const weekStart = new Date(today);
                    weekStart.setDate(today.getDate() - today.getDay()); // Start of week (Sunday)
                    fromDate = weekStart.toISOString().split('T')[0];
                    toDate = today.toISOString().split('T')[0];
                    break;
                case 'month':
                    fromDate = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
                    toDate = today.toISOString().split('T')[0];
                    break;
                case 'all':
                    fromDate = toDate = null;
                    break;
            }
            
            currentUsageDateFrom = fromDate;
            currentUsageDateTo = toDate;
            
            // Update date inputs
            document.getElementById('usageDateFrom').value = fromDate || '';
            document.getElementById('usageDateTo').value = toDate || '';
            
            // Update filter status
            updateUsageFilterStatus(period);
            
            // Load stats with new filter
            loadUsageStats();
        }

        // Apply custom usage date filter
        function applyCustomUsageFilter() {
            const dateFrom = document.getElementById('usageDateFrom').value;
            const dateTo = document.getElementById('usageDateTo').value;
            
            if (dateFrom && dateTo && dateFrom > dateTo) {
                showMessage('Start date cannot be after end date', 'error');
                return;
            }
            
            currentUsageDateFrom = dateFrom || null;
            currentUsageDateTo = dateTo || null;
            
            updateUsageFilterStatus('custom');
            loadUsageStats();
        }

        // Update usage filter status display
        function updateUsageFilterStatus(period) {
            const statusEl = document.getElementById('usageFilterStatus');
            let statusText = '';
            
            if (period === 'today') {
                statusText = '<i class="fas fa-info-circle"></i> Showing statistics from today';
            } else if (period === 'week') {
                statusText = '<i class="fas fa-info-circle"></i> Showing statistics from this week';
            } else if (period === 'month') {
                statusText = '<i class="fas fa-info-circle"></i> Showing statistics from this month';
            } else if (period === 'custom') {
                if (currentUsageDateFrom && currentUsageDateTo) {
                    statusText = `<i class="fas fa-info-circle"></i> Showing statistics from ${currentUsageDateFrom} to ${currentUsageDateTo}`;
                } else if (currentUsageDateFrom) {
                    statusText = `<i class="fas fa-info-circle"></i> Showing statistics from ${currentUsageDateFrom} onwards`;
                } else if (currentUsageDateTo) {
                    statusText = `<i class="fas fa-info-circle"></i> Showing statistics up to ${currentUsageDateTo}`;
                } else {
                    statusText = '<i class="fas fa-info-circle"></i> Showing all statistics';
                }
            } else {
                statusText = '<i class="fas fa-info-circle"></i> Showing all statistics';
            }
            
            statusEl.innerHTML = statusText;
        }

        // Load System Usage Statistics - Always fetches fresh data from database
        async function loadUsageStats() {
            try {
                const container = document.getElementById('usageStatsContainer');
                const refreshBtn = document.querySelector('button[onclick="loadUsageStats()"]');
                
                // Show loading state
                container.innerHTML = '<div style="text-align: center; padding: 40px; color: #666; grid-column: 1 / -1;"><i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i><p style="margin-top: 10px;">Loading usage statistics...</p></div>';
                
                // Disable refresh button during load
                if (refreshBtn) {
                    refreshBtn.disabled = true;
                    refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
                }
                
                // Build query string with filters - Add timestamp to prevent caching
                let queryParams = 'action=get_system_usage&_t=' + Date.now();
                if (currentUsageDateFrom) {
                    queryParams += '&date_from=' + encodeURIComponent(currentUsageDateFrom);
                }
                if (currentUsageDateTo) {
                    queryParams += '&date_to=' + encodeURIComponent(currentUsageDateTo);
                }
                
                // Fetch fresh data from database (no caching)
                const response = await fetch(API_URL + '?' + queryParams, {
                    method: 'GET',
                    cache: 'no-cache',
                    headers: {
                        'Cache-Control': 'no-cache, no-store, must-revalidate',
                        'Pragma': 'no-cache',
                        'Expires': '0'
                    }
                });
                const result = await response.json();
                
                if (result.success && result.stats) {
                    const stats = result.stats;
                    
                    // Create statistics cards matching HealthServe theme
                    container.innerHTML = `
                        <div class="usage-stat-card">
                            <div class="usage-stat-icon-wrapper logins">
                                <i class="fas fa-sign-in-alt"></i>
                            </div>
                            <div class="usage-stat-details">
                                <h3>${stats.logins.total}</h3>
                                <p>Total Logins</p>
                                <div class="usage-stat-breakdown">
                                    <div>
                                        <span>Admin:</span>
                                        <strong>${stats.logins.admin || 0}</strong>
                                    </div>
                                    <div>
                                        <span>Doctor:</span>
                                        <strong>${stats.logins.doctor || 0}</strong>
                                    </div>
                                    <div>
                                        <span>FDO:</span>
                                        <strong>${stats.logins.fdo || 0}</strong>
                                    </div>
                                    <div>
                                        <span>Pharmacist:</span>
                                        <strong>${stats.logins.pharmacist || 0}</strong>
                                    </div>
                                    <div>
                                        <span>Staff (FDO + Pharmacist):</span>
                                        <strong>${stats.logins.staff || 0}</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="usage-stat-card">
                            <div class="usage-stat-icon-wrapper appointments">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="usage-stat-details">
                                <h3>${stats.appointments}</h3>
                                <p>Appointments Created</p>
                            </div>
                        </div>
                        
                        <div class="usage-stat-card">
                            <div class="usage-stat-icon-wrapper announcements">
                                <i class="fas fa-bullhorn"></i>
                            </div>
                            <div class="usage-stat-details">
                                <h3>${stats.announcements}</h3>
                                <p>Announcements Posted</p>
                            </div>
                        </div>
                        
                        <div class="usage-stat-card">
                            <div class="usage-stat-icon-wrapper notifications">
                                <i class="fas fa-bell"></i>
                            </div>
                            <div class="usage-stat-details">
                                <h3>${stats.notifications}</h3>
                                <p>Notifications Sent</p>
                            </div>
                        </div>
                    `;
                } else {
                    container.innerHTML = '<div style="text-align: center; padding: 40px; color: #dc3545; grid-column: 1 / -1;">Error loading usage statistics</div>';
                }
            } catch (error) {
                console.error('Error loading usage statistics:', error);
                document.getElementById('usageStatsContainer').innerHTML = '<div style="text-align: center; padding: 40px; color: #dc3545; grid-column: 1 / -1;">Error loading usage statistics: ' + error.message + '</div>';
            } finally {
                // Re-enable refresh button
                const refreshBtn = document.querySelector('button[onclick="loadUsageStats()"]');
                if (refreshBtn) {
                    refreshBtn.disabled = false;
                    refreshBtn.innerHTML = '<i class="fas fa-sync"></i> Refresh Report';
                }
            }
        }

        // Show Message
        function showMessage(message, type) {
            const alertClass = type === 'success' ? 'alert-success' : type === 'error' ? 'alert-danger' : 'alert-info';
            const icon = type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle';
            
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert ${alertClass}`;
            alertDiv.innerHTML = `<i class="fas ${icon}"></i> ${message}`;
            alertDiv.style.position = 'fixed';
            alertDiv.style.top = '20px';
            alertDiv.style.right = '20px';
            alertDiv.style.zIndex = '10000';
            alertDiv.style.minWidth = '300px';
            
            document.body.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 5000);
        }

        // Password confirmation validation
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        
        if (confirmPassword && newPassword) {
            confirmPassword.addEventListener('input', function() {
                if (this.value !== newPassword.value) {
                    this.style.borderColor = '#dc3545';
                } else {
                    this.style.borderColor = '#28a745';
                }
            });
        }

        // Make function globally available for inline onsubmit handler
        window.updateHealthCenter = updateHealthCenter;
        
        // Add backup submit listener for extra reliability
        document.addEventListener('DOMContentLoaded', function() {
            const healthCenterForm = document.getElementById('healthCenterForm');
            if (healthCenterForm) {
                // Add submit listener as backup (in addition to inline onsubmit)
                healthCenterForm.addEventListener('submit', function(e) {
                    // Only handle if updateHealthCenter function exists
                    if (typeof updateHealthCenter === 'function') {
                        updateHealthCenter(e);
                    }
                });
            }
        });
    </script>
</body>
</html>