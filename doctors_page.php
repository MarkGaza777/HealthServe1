<?php
session_start();
require 'db.php';
require_once 'admin_helpers_simple.php';
require_once 'auto_audit_log.php'; // Auto-log page access

// Check if user is logged in and is a doctor
if(empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    header('Location: Login.php');
    exit;
}

// Check maintenance mode - redirect to maintenance page
if (isMaintenanceMode()) {
    header('Location: maintenance_mode.php');
    exit;
}

// Get logged-in doctor's information
$user_id = $_SESSION['user']['id'];
$doctor_name = 'Dr. Unknown';
$doctor_specialization = '';

try {
    $stmt = $pdo->prepare("
        SELECT 
            d.specialization,
            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as doctor_name
        FROM doctors d
        LEFT JOIN users u ON d.user_id = u.id
        WHERE d.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($doctor) {
        $doctor_name = 'Dr. ' . trim(preg_replace('/\s+/', ' ', $doctor['doctor_name']));
        $doctor_specialization = $doctor['specialization'] ?? '';
    } else {
        // Fallback: use user's name from session if doctor record doesn't exist
        $user = $_SESSION['user'];
        $name_parts = [];
        if (!empty($user['first_name'])) $name_parts[] = $user['first_name'];
        if (!empty($user['middle_name'])) $name_parts[] = $user['middle_name'];
        if (!empty($user['last_name'])) $name_parts[] = $user['last_name'];
        if (!empty($name_parts)) {
            $doctor_name = 'Dr. ' . implode(' ', $name_parts);
        }
    }
} catch (Exception $e) {
    error_log("Error fetching doctor info: " . $e->getMessage());
    // Use fallback name
    $user = $_SESSION['user'];
    $name_parts = [];
    if (!empty($user['first_name'])) $name_parts[] = $user['first_name'];
    if (!empty($user['middle_name'])) $name_parts[] = $user['middle_name'];
    if (!empty($user['last_name'])) $name_parts[] = $user['last_name'];
    if (!empty($name_parts)) {
        $doctor_name = 'Dr. ' . implode(' ', $name_parts);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HealthServe - Doctors Management</title>
    <link rel="stylesheet" href="assets/Style1.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Additional styles specific to doctors page */
        .doctor-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .doctor-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #66bb6a, #81c784);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }

        .content-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .search-filter {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .search-box {
            display: flex;
            align-items: center;
            background: #f5f5f5;
            padding: 10px 15px;
            border-radius: 10px;
            gap: 10px;
            min-width: 300px;
        }

        .search-box input {
            border: none;
            background: none;
            outline: none;
            flex: 1;
            font-size: 14px;
        }

        .add-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #4CAF50, #388E3C);
            color: white;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
        }

        .add-btn:hover {
            background: linear-gradient(135deg, #388E3C, #2E7D32);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.4);
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            justify-content: center;
            align-items: center;
            z-index: 1000;
            padding: 20px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            padding: 40px;
            border-radius: 20px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }

        .modal-content.modal-large {
            max-width: 1320px;
            width: 98%;
            max-height: 92vh;
            padding: 28px 28px 20px;
        }

        .modal-content iframe {
            border: none;
            width: 100%;
            min-height: 70vh;
            border-radius: 16px;
            background: #f5f6fa;
            transition: height 0.2s ease;
        }
        
        body.modal-open {
            overflow: hidden;
        }

        .modal-header {
            margin-bottom: 25px;
        }

        .modal-header h2 {
            color: #2E7D32;
            font-size: 24px;
        }

        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        .btn-cancel {
            padding: 12px 30px;
            border: 2px solid #E0E0E0;
            background: white;
            color: #666;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-cancel:hover {
            background: #F5F5F5;
            border-color: #4CAF50;
            color: #4CAF50;
        }

        .btn-save {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #4CAF50, #388E3C);
            color: white;
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
        }

        .btn-save:hover {
            background: linear-gradient(135deg, #388E3C, #2E7D32);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.4);
        }

        /* Dashboard stats grid: 3 equal columns for balanced layout */
        .dashboard-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        @media (max-width: 900px) {
            .dashboard-stats-grid {
                grid-template-columns: 1fr;
            }
        }

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

        .stat-icon-wrapper.inventory {
            background: linear-gradient(135deg, #AB47BC, #8E24AA);
        }

        .stat-icon-wrapper.staff {
            background: linear-gradient(135deg, #26A69A, #00897B);
        }

        .stat-icon-wrapper.next-appointment {
            background: linear-gradient(135deg, #5C6BC0, #3F51B5);
        }

        .stat-icon-wrapper.today-appointment {
            background: linear-gradient(135deg, #FF7043, #F4511E);
        }

        .stat-icon-wrapper.week-appointments {
            background: linear-gradient(135deg, #AB47BC, #8E24AA);
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

        /* Announcement cards */
        .announcement-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        /* Action Buttons - Matching Pharmacist Inventory Style */
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
            min-width: 80px;
            white-space: nowrap;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            justify-content: center;
            margin: 2px;
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

        .action-btn.btn-view {
            background: #e3f2fd;
            color: #1976d2;
            border: 1px solid rgba(25, 118, 210, 0.2);
        }

        .action-btn.btn-view:hover {
            background: #bbdefb;
            color: #1565c0;
        }

        .action-btn.btn-consult {
            background: #fff3e0;
            color: #f57c00;
            border: 1px solid rgba(245, 124, 0, 0.2);
        }

        .action-btn.btn-consult:hover {
            background: #ffe0b2;
            color: #ef6c00;
        }

        .action-btn.btn-archive {
            background: #fff3e0;
            color: #f57c00;
            border: 1px solid rgba(245, 124, 0, 0.2);
        }

        .action-btn.btn-archive:hover {
            background: #ffe0b2;
            color: #ef6c00;
        }

        .action-btn.btn-restore {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid rgba(46, 125, 50, 0.2);
        }

        .action-btn.btn-restore:hover {
            background: #c8e6c9;
            color: #1b5e20;
        }

        .action-btn.btn-delete {
            background: #ffebee;
            color: #d32f2f;
            border: 1px solid rgba(211, 47, 47, 0.2);
        }

        .action-btn.btn-delete:hover {
            background: #ffcdd2;
            color: #c62828;
        }

        @media (max-width: 768px) {
            .two-column-grid {
                grid-template-columns: 1fr;
            }

            .search-filter {
                flex-direction: column;
                width: 100%;
            }

            .search-box {
                width: 100%;
            }
        }

        /* Schedule Management Styles */
        .schedule-main-container {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .schedule-grid-section {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .schedule-header {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 1rem;
        }

        .date-selector-wrapper {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .date-selector-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #2E7D32;
            font-weight: 600;
            font-size: 0.95rem;
            margin: 0;
        }

        .date-selector-label i {
            color: #4CAF50;
        }

        .date-input-schedule {
            padding: 0.6rem 1rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 0.9rem;
            color: #333;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            min-width: 180px;
        }

        .date-input-schedule:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        .date-input-schedule:hover {
            border-color: #4CAF50;
        }

        .schedule-table-wrapper {
            display: flex;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            align-items: flex-start;
        }

        .time-column {
            width: 100px;
            background: #f8f9fa;
            border-right: 1px solid #e0e0e0;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }

        .time-slot-header {
            height: 40px;
            border-bottom: 2px solid #2E7D32;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 0 0.8rem;
            box-sizing: border-box;
        }

        .time-slot {
            height: 60px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 0.9rem;
            color: #666;
            font-weight: 500;
            text-align: right;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 0 0.8rem;
            box-sizing: border-box;
            margin: 0;
        }

        .time-slot:last-child {
            border-bottom: none;
        }

        .schedule-column-header {
            height: 40px;
            border-bottom: 2px solid #2E7D32;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            font-weight: 600;
            color: #2E7D32;
            padding: 0.5rem;
            box-sizing: border-box;
        }

        .schedule-grid {
            flex: 1;
            display: flex;
            gap: 1px;
            background: #e0e0e0;
            padding: 0;
        }

        .schedule-column {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: white;
        }

        .schedule-cell {
            height: 60px;
            border-bottom: 1px solid #e0e0e0;
            padding: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.85rem;
            font-weight: 600;
            color: white;
            box-sizing: border-box;
            margin: 0;
        }

        .schedule-cell:hover {
            opacity: 0.9;
            transform: scale(1.02);
        }

        .schedule-cell:last-child {
            border-bottom: none;
        }

        .status-available {
            background: #66BB6A;
        }

        .status-occupied {
            background: #EF5350;
        }

        .status-pending {
            background: #FFB74D;
        }

        .status-blocked {
            background: #78909C;
            opacity: 0.8;
        }

        .schedule-summary {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            height: fit-content;
        }

        .schedule-summary h3 {
            color: #2E7D32;
            font-size: 1.3rem;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }

        .summary-item {
            margin-bottom: 1.5rem;
        }

        .summary-item strong {
            display: block;
            color: #333;
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
        }

        .summary-item p {
            color: #2E7D32;
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
        }

        .schedule-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }

        .btn-primary {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #4CAF50, #388E3C);
            color: white;
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #388E3C, #2E7D32);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.4);
        }

        .btn-secondary {
            padding: 12px 30px;
            border: 2px solid #E0E0E0;
            background: white;
            color: #666;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: #F5F5F5;
            border-color: #4CAF50;
            color: #4CAF50;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .form-input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #E0E0E0;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.3s;
        }

        .form-input:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }
        /* Announcement Modal Styles (matching pharmacist) */
        .announcement-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 2000;
            overflow-y: auto;
            padding: 40px 20px;
        }

        .announcement-modal-box {
            position: relative;
            background: white;
            max-width: 700px;
            margin: 0 auto;
            border-radius: 20px;
            padding: 50px 60px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .announcement-modal-title {
            font-size: 28px;
            font-weight: 700;
            color: #1f2937;
            text-align: center;
            margin-bottom: 12px;
            line-height: 1.3;
        }

        .announcement-modal-subtitle {
            font-size: 16px;
            color: #ff6b6b;
            text-align: center;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .announcement-modal-date {
            font-size: 14px;
            color: #9ca3af;
            text-align: center;
            margin-bottom: 30px;
        }

        .announcement-modal-body {
            color: #333;
            font-size: 15px;
            line-height: 1.8;
            text-align: justify;
            margin-bottom: 20px;
        }

        .announcement-modal-body p {
            margin-bottom: 16px;
        }

        .announcement-modal-body ul {
            margin: 16px 0 16px 24px;
            padding: 0;
        }

        .announcement-modal-body li {
            margin-bottom: 8px;
            color: #333;
        }

        .announcement-modal-body strong {
            color: #1f2937;
        }

        .announcement-modal-close {
            display: block;
            margin: 40px auto 0;
            background: #e5e7eb;
            color: #374151;
            border: none;
            padding: 14px 60px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .announcement-modal-close:hover {
            background: #d1d5db;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        @media (max-width: 768px) {
            .announcement-modal-box {
                padding: 40px 30px;
            }
            
            .announcement-modal-title {
                font-size: 24px;
            }
        }

        /* Breadcrumb link styling to match announcements page */
        .breadcrumb a {
            color: #81C784;
            text-decoration: none;
            font-weight: 500;
        }

        .breadcrumb a:hover {
            color: #66BB6A;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="admin-profile">
            <div class="profile-info">
                <div class="profile-avatar">
                    <i class="fas fa-user-md"></i>
                </div>
                <div class="profile-details">
                    <h3><?php echo htmlspecialchars($doctor_name); ?></h3>
                    <div class="profile-status">Online</div>
                </div>
            </div>
        </div>

        <nav class="nav-section">
            <div class="nav-title">General</div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="#" class="nav-link" data-page="dashboard">
                        <div class="nav-icon"><i class="fas fa-th-large"></i></div>
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link" data-page="appointments">
                        <div class="nav-icon"><i class="fas fa-calendar-check"></i></div>
                        Appointments
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link" data-page="patients">
                        <div class="nav-icon"><i class="fas fa-users"></i></div>
                        Patients
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link" data-page="schedule">
                        <div class="nav-icon"><i class="fas fa-clock"></i></div>
                        Schedule
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link" data-page="notifications">
                        <div class="nav-icon"><i class="fas fa-bell"></i></div>
                        Notifications
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link" data-page="announcements">
                        <div class="nav-icon"><i class="fas fa-bullhorn"></i></div>
                        Announcements
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
                    <p>Doctors Portal</p>
                </div>
            </div>
        </header>

        <!-- Content Area -->
        <div class="content-area" id="mainContent">
            <!-- Content will be loaded here dynamically -->
        </div>
    </div>

    <!-- Add Doctor Modal -->
    <!-- Patient View Modal -->
    <div class="modal" id="viewPatientModal">
        <div class="modal-content modal-large">
            <div class="modal-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h2 style="margin:0;color:#2E7D32;">Patient Profile</h2>
                <button class="btn-cancel" onclick="closePatientModal()" style="border:none;background:#f1f5f9;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;"><i class="fas fa-times"></i></button>
            </div>
            <iframe id="patientProfileFrame" title="Patient Profile"></iframe>
        </div>
    </div>

    <!-- Consultation Modal -->
    <div class="modal" id="consultModal">
        <div class="modal-content modal-large">
            <div class="modal-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h2 style="margin:0;color:#2E7D32;">Consultation Workspace</h2>
                <button class="btn-cancel" onclick="closeConsultModal()" style="border:none;background:#f1f5f9;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;"><i class="fas fa-times"></i></button>
            </div>
            <iframe id="consultFrame" title="Consultation Workspace"></iframe>
        </div>
    </div>

    <!-- Add Patient Modal -->
    <div class="modal" id="addPatientModal">
        <div class="modal-content" style="max-width: 800px; max-height: 90vh; overflow-y: auto;">
            <div class="modal-header" style="display:flex;justify-content:space-between;align-items:center;">
                <h2 style="margin:0;color:#2E7D32;">Add New Patient</h2>
                <button class="btn-cancel" onclick="closeAddPatientModal()" style="border:none;background:#f1f5f9;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;"><i class="fas fa-times"></i></button>
            </div>
            <form id="addPatientForm">
                <!-- Patient Name Section -->
                <h3 style="color: #2E7D32; margin: 20px 0 15px; font-size: 18px;">Patient Name</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="first_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Middle Name (Optional)</label>
                        <input type="text" name="middle_name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" class="form-control" required>
                    </div>
                </div>

                <!-- Basic Information -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Sex *</label>
                        <select name="sex" class="form-control" required>
                            <option value="">Select Sex</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Birthdate *</label>
                        <input type="date" name="date_of_birth" id="patientDateOfBirth" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Age</label>
                        <input type="text" id="patientAge" class="form-control" readonly style="background: #f5f5f5;">
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Contact Number *</label>
                        <input type="tel" name="phone" class="form-control" required placeholder="09XX-XXX-XXXX">
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Address *</label>
                        <input type="text" name="address" class="form-control" required placeholder="Complete address">
                    </div>
                </div>

                <!-- Additional Information -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Civil Status</label>
                        <select name="civil_status" class="form-control">
                            <option value="">Select Status</option>
                            <option value="single">Single</option>
                            <option value="married">Married</option>
                            <option value="divorced">Divorced</option>
                            <option value="widowed">Widowed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>PhilHealth No. (Optional)</label>
                        <input type="text" name="philhealth_no" class="form-control" placeholder="XX-XXXXXXXXX-X">
                    </div>
                </div>

                <!-- Medical History -->
                <h3 style="color: #2E7D32; margin: 20px 0 15px; font-size: 18px;">Medical History / Notes</h3>
                <div class="form-group">
                    <textarea name="medical_history" class="form-control textarea" rows="3" placeholder="Previous medical conditions, allergies, current medications, or other relevant medical information..."></textarea>
                </div>

                <!-- Emergency Contact -->
                <h3 style="color: #2E7D32; margin: 20px 0 15px; font-size: 18px;">Emergency Contact</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>Emergency Contact Name</label>
                        <input type="text" name="emergency_contact_name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Relationship</label>
                        <select name="emergency_contact_relationship" class="form-control">
                            <option value="">Select Relationship</option>
                            <option value="spouse">Spouse</option>
                            <option value="parent">Parent</option>
                            <option value="child">Child</option>
                            <option value="sibling">Sibling</option>
                            <option value="relative">Relative</option>
                            <option value="friend">Friend</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Emergency Contact Number</label>
                        <input type="tel" name="emergency_contact_phone" class="form-control" placeholder="09XX-XXX-XXXX">
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeAddPatientModal()">Cancel</button>
                    <button type="submit" class="btn-save">Save Patient</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Block Time and Date Modal -->
    <div id="blockTime" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Block Time and Date</h2>
            </div>
            <form onsubmit="saveBlockTime(event)">
                <div class="form-group">
                    <label class="form-label">Reason for Blocking</label>
                    <div style="position: relative;">
                        <input type="text" id="blockReasonInput" class="form-input" placeholder="Select reason..." readonly onclick="this.nextElementSibling.style.display='block'">
                        <select id="blockReasonSelect" class="form-input" style="position: absolute; top: 0; left: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer;" onchange="document.getElementById('blockReasonInput').value=this.options[this.selectedIndex].text">
                            <option value="Lunch Break">Lunch Break</option>
                            <option value="Meeting">Meeting</option>
                            <option value="Emergency">Emergency</option>
                            <option value="Personal">Personal</option>
                            <option value="On Leave">On Leave</option>
                            <option value="Other">Other</option>
                        </select>
                        <i class="fas fa-chevron-down" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #999;"></i>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <div style="position: relative;">
                            <input type="date" id="blockStartDate" class="form-input" required>
                            <i class="fas fa-calendar" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #999;"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <div style="position: relative;">
                            <input type="date" id="blockEndDate" class="form-input" required>
                            <i class="fas fa-calendar" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #999;"></i>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start Time</label>
                        <div style="position: relative;">
                            <select id="blockStartTime" class="form-input" required>
                                <option value="">Select time...</option>
                                <option value="07:00">7:00 AM</option>
                                <option value="07:30">7:30 AM</option>
                                <option value="08:00">8:00 AM</option>
                                <option value="08:30">8:30 AM</option>
                                <option value="09:00">9:00 AM</option>
                                <option value="09:30">9:30 AM</option>
                                <option value="10:00">10:00 AM</option>
                                <option value="10:30">10:30 AM</option>
                                <option value="11:00">11:00 AM</option>
                                <option value="12:00">12:00 PM</option>
                                <option value="12:30">12:30 PM</option>
                                <option value="13:00">1:00 PM</option>
                                <option value="13:30">1:30 PM</option>
                                <option value="14:00">2:00 PM</option>
                                <option value="14:30">2:30 PM</option>
                                <option value="15:00">3:00 PM</option>
                            </select>
                            <i class="fas fa-clock" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #999;"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">End Time</label>
                        <div style="position: relative;">
                            <select id="blockEndTime" class="form-input" required>
                                <option value="">Select time...</option>
                                <option value="07:00">7:00 AM</option>
                                <option value="07:30">7:30 AM</option>
                                <option value="08:00">8:00 AM</option>
                                <option value="08:30">8:30 AM</option>
                                <option value="09:00">9:00 AM</option>
                                <option value="09:30">9:30 AM</option>
                                <option value="10:00">10:00 AM</option>
                                <option value="10:30">10:30 AM</option>
                                <option value="11:00">11:00 AM</option>
                                <option value="12:00">12:00 PM</option>
                                <option value="12:30">12:30 PM</option>
                                <option value="13:00">1:00 PM</option>
                                <option value="13:30">1:30 PM</option>
                                <option value="14:00">2:00 PM</option>
                                <option value="14:30">2:30 PM</option>
                                <option value="15:00">3:00 PM</option>
                            </select>
                            <i class="fas fa-clock" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #999;"></i>
                        </div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeScheduleModal('blockTime')">Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>


    <!-- Toast Notification -->
    <div id="toast" class="toast" style="display: none; position: fixed; bottom: 20px; right: 20px; background: #4CAF50; color: white; padding: 15px 25px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); z-index: 10000; transition: all 0.3s ease;">
        <span>✓</span>
        <span id="toastMessage">Schedule updated successfully!</span>
    </div>

    <script>
        // Page templates
        const getPrescriptionPage = (patientName) => `
                <div class="page-header">
                    <h2 class="page-title">Prescription</h2>
                    <div class="breadcrumb">
                        <a href="#" onclick="loadPage('patients'); return false;">Patients</a> > ${patientName} > Prescription
                    </div>
                </div>

                <div class="content-section">
                    <h2 style="color:#2E7D32; margin-bottom:16px; font-size:20px;">Patient: ${patientName}</h2>
                    <p style="margin-bottom:20px; color:#6b7280;">Fill out the prescription details below for this patient.</p>

                    <form id="prescriptionForm">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Diagnosis</label>
                                <textarea class="form-control textarea" name="diagnosis" rows="3" placeholder="Enter diagnosis..."></textarea>
                            </div>
                        </div>

                        <h3 style="color:#2E7D32; margin:20px 0 10px;">Medicines</h3>
                        <div id="medicinesContainer">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Medicine Name</label>
                                    <input type="text" class="form-control" name="medicine_name[]" placeholder="e.g. Paracetamol 500mg">
                                </div>
                                <div class="form-group">
                                    <label>Dosage</label>
                                    <input type="text" class="form-control" name="dosage[]" placeholder="1 tablet">
                                </div>
                                <div class="form-group">
                                    <label>Frequency</label>
                                    <input type="text" class="form-control" name="frequency[]" placeholder="Every 6 hours">
                                </div>
                                <div class="form-group">
                                    <label>Duration</label>
                                    <input type="text" class="form-control" name="duration[]" placeholder="5 days">
                                </div>
                            </div>
                        </div>

                        <button type="button" class="add-btn" style="margin-top:10px;" onclick="addMedicineRow()">
                            <i class="fas fa-plus"></i> Add Another Medicine
                        </button>

                        <div class="form-row" style="margin-top:20px;">
                            <div class="form-group">
                                <label>Additional Instructions</label>
                                <textarea class="form-control textarea" name="instructions" rows="3" placeholder="Diet, activity, or follow-up instructions..."></textarea>
                            </div>
                        </div>

                        <div class="modal-actions" style="margin-top:24px;">
                            <button type="button" class="btn-cancel" onclick="loadPage('patients')">Back to Patients</button>
                            <button type="submit" class="btn-save">Save Prescription</button>
                        </div>
                    </form>
                </div>
        `;

        const pages = {
            dashboard: `
                <div class="page-header">
                    <h2 class="page-title">Dashboard</h2>
                </div>

                <div class="stats-grid dashboard-stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon-wrapper next-appointment">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-details">
                            <h3 id="nextAppointmentTime" style="font-size: 24px; line-height: 1.2;">Loading...<br><small id="nextAppointmentPatient" style="font-size: 14px; color: #666; font-weight: normal;">Loading...</small></h3>
                            <p>Next Appointment</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon-wrapper today-appointment">
                            <i class="fas fa-calendar-plus"></i>
                        </div>
                        <div class="stat-details">
                            <h3 id="todayAppointmentCount">0</h3>
                            <p>Today's Appointment</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon-wrapper week-appointments">
                            <i class="fas fa-calendar"></i>
                        </div>
                        <div class="stat-details">
                            <h3 id="weekAppointmentsCount">0</h3>
                            <p>This Week's Appointments</p>
                        </div>
                    </div>
                </div>

                <div class="two-column-grid">
                    <div class="content-section">
                        <h2 style="color: #2E7D32; margin-bottom: 20px; font-size: 20px;">Upcoming Appointments</h2>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Patient Name</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody id="upcomingAppointmentsBody">
                                <tr><td colspan="2" style="text-align: center; padding: 20px; color: #666;">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="content-section">
                        <h2 style="color: #2E7D32; margin-bottom: 20px; font-size: 20px;">Notifications</h2>
                        <div id="dashboardNotificationsContainer">
                            <div style="text-align: center; padding: 20px; color: #666;">Loading notifications...</div>
                        </div>
                    </div>
                </div>
            `,
            appointments: `
                <div class="page-header">
                    <h2 class="page-title">Appointments</h2>
                    <div class="breadcrumb">
                        <a href="#" onclick="loadPage('dashboard'); return false;">Dashboard</a> > Appointments
                    </div>
                </div>

                <div class="content-section">
                    <div class="section-header">
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <button class="filter-btn" data-range="today">Today</button>
                            <button class="filter-btn" data-range="week">This Week</button>
                            <button class="filter-btn active" data-range="all">All</button>
                            <select id="statusFilter" class="form-control" style="width:auto; min-width:140px;">
                                <option value="all">All Status</option>
                                <option value="approved">Approved</option>
                                <option value="completed">Completed</option>
                                <option value="declined">Declined</option>
                                <option value="missed">Missed</option>
                            </select>
                        </div>
                    </div>

                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="doctorAppointmentsBody">
                            <tr>
                                <td colspan="5" style="text-align:center; padding:24px; color:#6b7280;">
                                    Loading appointments...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="content-section" style="margin-top: 30px;">
                    <div class="section-header">
                        <h3 style="color: #ff9800; margin: 0;">Reschedule Requests</h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Original Appointment</th>
                                <th>Requested Date</th>
                                <th>Requested Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="doctorRescheduleRequestsBody">
                            <tr>
                                <td colspan="5" style="text-align:center; padding:24px; color:#6b7280;">
                                    Loading reschedule requests...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="content-section" style="margin-top: 30px;">
                    <div class="section-header">
                        <h3 style="color: #2E7D32; margin: 0;">Follow-Up Appointments Pending Approval</h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Original Appointment</th>
                                <th>Follow-Up Date</th>
                                <th>Follow-Up Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="doctorFollowUpsBody">
                            <tr>
                                <td colspan="5" style="text-align:center; padding:24px; color:#6b7280;">
                                    Loading follow-up appointments...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="content-section" style="margin-top: 30px;">
                    <div class="section-header">
                        <h3 style="color: #2E7D32; margin: 0;">Approved Follow-Up Appointments</h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Original Appointment</th>
                                <th>Follow-Up Date</th>
                                <th>Follow-Up Time</th>
                            </tr>
                        </thead>
                        <tbody id="doctorApprovedFollowUpsBody">
                            <tr>
                                <td colspan="4" style="text-align:center; padding:24px; color:#6b7280;">
                                    Loading approved follow-up appointments...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            `,
            patients: `
                <div class="page-header">
                    <h2 class="page-title">Patient Records</h2>
                    <div class="breadcrumb">
                        <a href="#" onclick="loadPage('dashboard'); return false;">Dashboard</a> > Patients
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon-wrapper patients">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-details">
                            <h3 id="totalPatientsCount">0</h3>
                            <p>Total Patients</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon-wrapper appointments">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <div class="stat-details">
                            <h3 id="newThisMonthCount">0</h3>
                            <p>New This Month</p>
                        </div>
                    </div>
                </div>

                <div class="content-section">
                    <div class="section-header">
                        <div class="search-filter">
                            <div class="search-box">
                                <i class="fas fa-search" style="color: #81C784;"></i>
                                <input type="text" placeholder="Search" id="patientSearch">
                            </div>
                            <select id="patientSortFilter" class="form-control" style="width:auto; min-width:160px;">
                                <option value="alphabetical">Alphabetical List</option>
                                <option value="a-z">A-Z</option>
                                <option value="z-a">Z-A</option>
                            </select>
                        </div>
                    </div>

                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Age/Sex</th>
                                <th>Last Visit</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="patientsTable">
                            <tr>
                                <td colspan="5" style="text-align:center; padding:24px; color:#6b7280;">
                                    Loading patients...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            `,
            schedule: `
                <div class="page-header">
                    <h1 class="page-title">Schedule Management</h1>
                    <div class="breadcrumb">Dashboard > Schedule</div>
                </div>

                <!-- Schedule Grid and Summary Container -->
                <div class="schedule-main-container">
                    <!-- Schedule Grid Section -->
                    <div class="schedule-grid-section">
                        <div class="schedule-header">
                            <div class="date-selector-wrapper" style="display: flex; gap: 1rem; align-items: center;">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <label class="date-selector-label">
                                        <i class="fas fa-calendar-alt"></i> Select Week:
                                </label>
                                    <input type="week" id="scheduleWeekPicker" class="date-input-schedule" onchange="handleWeekPickerChange(this.value)">
                                </div>
                            </div>
                        </div>
                        <div class="schedule-table-wrapper">
                            <div class="time-column">
                                <!-- Time slots will be dynamically generated -->
                            </div>
                            <div class="schedule-grid" id="scheduleGrid">
                                <!-- Schedule cells will be dynamically generated -->
                            </div>
                        </div>
                    </div>

                    <!-- Summary Panel -->
                    <div class="schedule-summary">
                        <h3>Summary</h3>
                        <div class="summary-item">
                            <strong>Selected Week:</strong>
                            <p id="summarySelectedWeek">-</p>
                        </div>
                        <div class="summary-item">
                            <strong>Next available time:</strong>
                            <p id="summaryNextAvailable">-</p>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="schedule-actions">
                    <button class="btn-secondary" onclick="openScheduleModal('blockTime')">Block Time</button>
                </div>
            `,
            announcements: `
                <div class="page-header">
                    <h2 class="page-title">Announcements</h2>
                    <div class="breadcrumb">Dashboard > Announcements</div>
                </div>

                <!-- FDO Approval Reminder Banner -->
                <div id="fdoApprovalBanner" style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-bottom: 20px; border-radius: 4px; display: none;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-info-circle" style="color: #856404; font-size: 20px;"></i>
                        <div>
                            <strong style="color: #856404;">Pending FDO Approval</strong>
                            <p style="margin: 5px 0 0 0; color: #856404; font-size: 14px;">
                                Your announcement has been submitted and is waiting for Front Desk Officer (FDO) approval before it can be published.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Announcements Management -->
                <div class="content-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="color:#2E7D32;margin:0;">My Announcements</h3>
                        <button class="add-btn" onclick="openAnnouncementModal()">
                            <i class="fas fa-plus"></i> Create Announcement
                        </button>
                    </div>
                    <table class="data-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f5f5f5;">
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Title</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Category</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Date Posted</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Status</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="announcementsTableBody">
                            <tr><td colspan="5" style="text-align: center; padding: 30px;">Loading announcements...</td></tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Published Announcements Display -->
                <div style="padding:24px; margin-top: 20px;">
                    <h3 style="color:#2E7D32;margin:0 0 16px 0;">Published Announcements</h3>
                    <div id="publishedAnnouncementsContainer">
                        <div style="text-align: center; padding: 30px; color: #666;">Loading published announcements...</div>
                    </div>
                </div>

                <!-- Create Announcement Modal -->
                <div id="createAnnouncementModal" class="modal">
                    <div class="modal-content" style="max-width: 700px; max-height: 90vh; overflow-y: auto;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h2 style="margin:0;">Add New Announcement</h2>
                            <button type="button" onclick="closeCreateAnnouncementModal()" style="background:#F3F4F6; border:none; width:36px; height:36px; border-radius:50%; cursor:pointer; font-size: 20px;">&times;</button>
                        </div>
                        
                        <!-- FDO Approval Notice -->
                        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-info-circle" style="color: #856404; font-size: 20px;"></i>
                                <div>
                                    <strong style="color: #856404;">FDO Approval Required</strong>
                                    <p style="margin: 5px 0 0 0; color: #856404; font-size: 14px;">
                                        Your announcement will be submitted for Front Desk Officer (FDO) approval before it can be published. You will be notified once it's approved or rejected.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <form id="announcementForm" onsubmit="submitAnnouncement(event)" enctype="multipart/form-data">
                            <div style="margin-bottom:20px;">
                                <label style="display:block; margin-bottom:8px; font-weight:600;">Announcement Title *</label>
                                <input type="text" class="form-control" id="announcementTitle" name="title" maxlength="100" required placeholder="Enter announcement title..." style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;">
                                <div style="font-size:12px; color:#666; margin-top:4px;"><span id="titleCharCount">0</span>/100 characters</div>
                            </div>

                            <div style="margin-bottom:20px;">
                                <label style="display:block; margin-bottom:8px; font-weight:600;">Announcement Description / Content *</label>
                                <textarea class="form-control" id="announcementContent" name="content" rows="6" required placeholder="Enter the full details of the announcement..." style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; resize:vertical;"></textarea>
                            </div>

                            <div style="margin-bottom:20px;">
                                <label style="display:block; margin-bottom:8px; font-weight:600;">Category</label>
                                <select class="form-control" id="announcementCategory" name="category" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;">
                                    <option value="General">General</option>
                                    <option value="Event">Event</option>
                                    <option value="Health Tip">Health Tip</option>
                                    <option value="Training">Training</option>
                                    <option value="Program">Program</option>
                                    <option value="Reminder">Reminder</option>
                                </select>
                            </div>

                            <div style="margin-bottom:20px;">
                                <label style="display:block; margin-bottom:8px; font-weight:600;">Attach Image *</label>
                                <input type="file" class="form-control" id="announcementImage" name="image" accept="image/*" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;">
                                <div style="font-size:12px; color:#666; margin-top:4px;">Accepted formats: JPG, PNG, GIF (Max 5MB)</div>
                                <div id="imagePreview" style="margin-top:10px; display:none;">
                                    <img id="previewImg" src="" alt="Preview" style="max-width:100%; max-height:200px; border-radius:8px; border:1px solid #ddd;">
                                </div>
                            </div>

                            <div style="margin-bottom:20px;">
                                <label style="display:block; margin-bottom:8px; font-weight:600;">Schedule</label>
                                <select class="form-control" id="announcementSchedule" name="schedule" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;">
                                    <option value="Not Applicable">Not Applicable</option>
                                    <option value="Every Monday | 8 AM - 12 NN">Every Monday | 8 AM - 12 NN</option>
                                    <option value="Every Tuesday | 8 AM - 12 NN">Every Tuesday | 8 AM - 12 NN</option>
                                    <option value="Every Wednesday | 8 AM - 12 NN">Every Wednesday | 8 AM - 12 NN</option>
                                    <option value="Every Thursday | 8 AM - 12 NN">Every Thursday | 8 AM - 12 NN</option>
                                    <option value="Every Friday | 8 AM - 12 NN">Every Friday | 8 AM - 12 NN</option>
                                    <option value="Every Saturday | 8 AM - 12 NN">Every Saturday | 8 AM - 12 NN</option>
                                    <option value="Every Sunday | 8 AM - 12 NN">Every Sunday | 8 AM - 12 NN</option>
                                    <option value="Every Monday & Wednesday | 8 AM - 12 NN">Every Monday & Wednesday | 8 AM - 12 NN</option>
                                    <option value="Every Tuesday & Thursday | 8 AM - 12 NN">Every Tuesday & Thursday | 8 AM - 12 NN</option>
                                    <option value="Every Wednesday & Friday | 8 AM - 12 NN">Every Wednesday & Friday | 8 AM - 12 NN</option>
                                    <option value="Every Monday, Wednesday & Friday | 8 AM - 12 NN">Every Monday, Wednesday & Friday | 8 AM - 12 NN</option>
                                    <option value="Custom">Custom (Enter below)</option>
                                </select>
                                <input type="text" class="form-control" id="announcementScheduleCustom" name="schedule_custom" placeholder="Enter custom schedule (e.g., Every Monday & Friday | 2 PM - 4 PM)" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; margin-top:10px; display:none;">
                            </div>

                            <div style="margin-bottom:20px;">
                                <label style="display:block; margin-bottom:8px; font-weight:600;">Display Start Date & Time *</label>
                                <input type="datetime-local" class="form-control" id="announcementStartDate" name="start_date" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;">
                            </div>

                            <div style="margin-bottom:20px;">
                                <label style="display:block; margin-bottom:8px; font-weight:600;">End Date & Time *</label>
                                <input type="datetime-local" class="form-control" id="announcementEndDate" name="end_date" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;">
                            </div>

                            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:30px;">
                                <button type="button" class="btn-cancel" onclick="closeCreateAnnouncementModal()">Cancel</button>
                                <button type="submit" class="btn-save">Submit for Approval</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- View Announcement Modal (NEW DESIGN) -->
                <div id="viewAnnouncementModal" class="announcement-modal-overlay">
                    <div class="announcement-modal-box">
                        <div id="viewAnnouncementModalBody"></div>
                        <button class="announcement-modal-close" onclick="closeViewAnnouncementModal()">Close</button>
                    </div>
                </div>
            `,
            notifications: `
                <div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <h2 class="page-title">Notifications</h2>
                    <div class="notification-filters" style="display: flex; gap: 8px;">
                        <button class="filter-btn active" data-filter="active" style="padding: 6px 12px; border: 1px solid #2E7D32; background: #2E7D32; color: white; border-radius: 4px; cursor: pointer; font-size: 13px;">Active</button>
                        <button class="filter-btn" data-filter="archived" style="padding: 6px 12px; border: 1px solid #e0e0e0; background: white; color: #666; border-radius: 4px; cursor: pointer; font-size: 13px;">Archived</button>
                    </div>
                </div>

                <div class="content-section">
                    <div id="notificationsPageContainer" style="display: flex; flex-direction: column; gap: 0;">
                        <div style="text-align: center; padding: 20px; color: #666;">Loading notifications...</div>
                    </div>
                </div>
            `

        };

        // Load page function
        function loadPage(pageName) {
            const mainContent = document.getElementById('mainContent');
            mainContent.innerHTML = pages[pageName] || pages.dashboard;
            
            // Update active nav link
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            const activeLink = document.querySelector(`[data-page="${pageName}"]`);
            if (activeLink) {
                activeLink.classList.add('active');
            }
            
            // Re-attach event listeners
            attachPageListeners(pageName);
        }

        // Attach event listeners for specific pages
        function attachPageListeners(pageName) {
            if (pageName === 'patients') {
                // Load patients data
                loadPatients();
                
                // Search input handler
                const searchInput = document.getElementById('patientSearch');
                if (searchInput) {
                    searchInput.addEventListener('input', function(e) {
                        renderPatientsTable();
                    });
                }
                
                // Sort filter handler (A-Z / Z-A)
                const sortFilter = document.getElementById('patientSortFilter');
                if (sortFilter) {
                    sortFilter.addEventListener('change', function() {
                        renderPatientsTable();
                    });
                }
            }

            if (pageName === 'announcements') {
                loadDoctorAnnouncements();
                
                // Character counter for title
                const titleInput = document.getElementById('announcementTitle');
                if (titleInput) {
                    titleInput.addEventListener('input', function() {
                        const counter = document.getElementById('titleCharCount');
                        if (counter) {
                            counter.textContent = this.value.length;
                        }
                    });
                }
            }

            if (pageName === 'dashboard') {
                // Load dashboard statistics
                loadDashboardStats();
                
                // Load dashboard notifications
                loadDashboardNotifications();
                
                // Auto-refresh dashboard stats every 7 seconds for real-time updates when patient creates appointment
                let dashboardRefreshInterval = setInterval(() => {
                    if (currentPage === 'dashboard') {
                        loadDashboardStats();
                        loadDashboardNotifications();
                    }
                }, 7000); // Refresh every 7 seconds for immediate updates
                
                // Store interval ID so we can clear it when needed
                window.dashboardRefreshInterval = dashboardRefreshInterval;
            } else {
                // Clear refresh interval when leaving dashboard page
                if (window.dashboardRefreshInterval) {
                    clearInterval(window.dashboardRefreshInterval);
                    window.dashboardRefreshInterval = null;
                }
            }
            
            if (pageName === 'notifications') {
                // Load notifications page
                loadNotificationsPage('active');
                
                // Bind filter buttons
                setTimeout(() => {
                    const filters = document.querySelectorAll('.filter-btn');
                    filters.forEach(btn => {
                        btn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            const filter = btn.getAttribute('data-filter');
                            filters.forEach(b => {
                                b.classList.remove('active');
                                b.style.background = 'white';
                                b.style.color = '#666';
                                b.style.border = '1px solid #e0e0e0';
                            });
                            btn.classList.add('active');
                            btn.style.background = '#2E7D32';
                            btn.style.color = 'white';
                            btn.style.border = '1px solid #2E7D32';
                            loadNotificationsPage(filter);
                        });
                    });
                }, 100);
                
                // Auto-refresh notifications every 7 seconds for immediate new-appointment visibility
                let notificationsRefreshInterval = setInterval(() => {
                    if (currentPage === 'notifications') {
                        loadNotificationsPage(currentNotificationFilter);
                    }
                }, 7000);
                
                window.notificationsRefreshInterval = notificationsRefreshInterval;
            } else {
                // Clear refresh interval when leaving notifications page
                if (window.notificationsRefreshInterval) {
                    clearInterval(window.notificationsRefreshInterval);
                    window.notificationsRefreshInterval = null;
                }
            }

            if (pageName === 'appointments') {
                // Load real appointments for the logged-in doctor
                loadDoctorAppointments();
                loadDoctorFollowUps();

                // Auto-refresh appointments every 7 seconds for real-time updates when patient creates appointment
                let appointmentsRefreshInterval = setInterval(() => {
                    if (currentPage === 'appointments') {
                        loadDoctorAppointments();
                        loadDoctorFollowUps();
                    }
                }, 7000); // Refresh every 7 seconds for immediate updates

                // Store interval ID so we can clear it when needed
                window.appointmentsRefreshInterval = appointmentsRefreshInterval;

                // Range filter buttons
                document.querySelectorAll('.filter-btn[data-range]').forEach(btn => {
                    btn.addEventListener('click', function () {
                        document.querySelectorAll('.filter-btn[data-range]').forEach(b => b.classList.remove('active'));
                        this.classList.add('active');
                        const range = this.getAttribute('data-range') || 'today';
                        const status = document.getElementById('statusFilter')?.value || 'all';
                        renderDoctorAppointments(range, status);
                    });
                });

                // Status dropdown
                const statusSelect = document.getElementById('statusFilter');
                if (statusSelect) {
                    statusSelect.addEventListener('change', function () {
                        const activeRangeBtn = document.querySelector('.filter-btn[data-range].active');
                        const range = activeRangeBtn ? activeRangeBtn.getAttribute('data-range') : 'today';
                        renderDoctorAppointments(range, this.value || 'all');
                    });
                }
            } else {
                // Clear refresh interval when leaving appointments page
                if (window.appointmentsRefreshInterval) {
                    clearInterval(window.appointmentsRefreshInterval);
                    window.appointmentsRefreshInterval = null;
                }
            }

            if (pageName === 'schedule') {
                setTimeout(() => {
                    initializeSchedule();
                }, 100);
            }

            // Generic highlight behaviour for filter buttons
            document.querySelectorAll('.filter-btn').forEach(btn => {
                if (!btn.dataset.boundHighlight) {
                    btn.dataset.boundHighlight = 'true';
                    btn.addEventListener('click', function () {
                        const group = this.closest('.section-header') || document;
                        group.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                        this.classList.add('active');
                    });
                }
            });
        }

        // --- Dashboard Statistics Functions ---
        async function loadDashboardStats() {
            try {
                const response = await fetch('doctor_get_dashboard_stats.php');
                const data = await response.json();
                
                if (!data.success) {
                    console.error('Dashboard stats error:', data.message);
                    return;
                }
                
                const stats = data.stats;
                
                // Update Next Appointment
                const nextApptTime = document.getElementById('nextAppointmentTime');
                const nextApptPatient = document.getElementById('nextAppointmentPatient');
                if (nextApptTime && nextApptPatient) {
                    if (stats.next_appointment.time !== 'N/A') {
                        nextApptTime.innerHTML = stats.next_appointment.time + '<br><small id="nextAppointmentPatient" style="font-size: 14px; color: #666; font-weight: normal;">' + stats.next_appointment.patient + '</small>';
                    } else {
                        nextApptTime.innerHTML = 'N/A<br><small id="nextAppointmentPatient" style="font-size: 14px; color: #666; font-weight: normal;">' + stats.next_appointment.patient + '</small>';
                    }
                }
                
                // Update Today's Appointment Count
                const todayCount = document.getElementById('todayAppointmentCount');
                if (todayCount) {
                    todayCount.textContent = stats.today_count;
                }
                
                // Update This Week's Appointments
                const weekAppointments = document.getElementById('weekAppointmentsCount');
                if (weekAppointments) {
                    weekAppointments.textContent = stats.week_appointments;
                }
                
                // Load upcoming appointments table
                loadUpcomingAppointments();
                
            } catch (err) {
                console.error('Error loading dashboard stats:', err);
            }
        }
        
        // --- Notifications Functions ---
        async function loadDashboardNotifications() {
            try {
                const response = await fetch('doctor_get_notifications.php');
                const data = await response.json();
                
                if (!data.success) {
                    console.error('Notifications error:', data.message);
                    const container = document.getElementById('dashboardNotificationsContainer');
                    if (container) {
                        container.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;">No notifications available</div>';
                    }
                    return;
                }
                
                const notifications = data.notifications || [];
                const container = document.getElementById('dashboardNotificationsContainer');
                
                if (!container) return;
                
                if (notifications.length === 0) {
                    container.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;">No notifications</div>';
                    return;
                }
                
                // Show only the 3 most recent notifications on dashboard
                const recentNotifications = notifications.slice(0, 3);
                
                container.innerHTML = recentNotifications.map(notif => {
                    let iconClass = 'fas fa-bell';
                    let iconColor = '#4CAF50';
                    let bgColor = '#f0f9f0';
                    
                    if (notif.type === 'appointment') {
                        iconClass = 'fas fa-calendar-check';
                        iconColor = '#2196F3';
                        bgColor = '#e3f2fd';
                    } else if (notif.type === 'announcement') {
                        iconClass = 'fas fa-bullhorn';
                        iconColor = '#4CAF50';
                        bgColor = '#f0f9f0';
                    }
                    
                    const timeAgo = getTimeAgo(notif.created_at);
                    
                    return `
                        <div class="notification-item" style="background: ${bgColor}; margin-bottom: 12px; padding: 16px; border-radius: 8px; display: flex; align-items: flex-start; gap: 12px;">
                            <div class="notification-icon" style="background: ${iconColor}; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; flex-shrink: 0;">
                                <i class="${iconClass}"></i>
                            </div>
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #333; margin-bottom: 4px;">${notif.title}</div>
                                <div style="color: #666; font-size: 14px;">${notif.message}</div>
                            </div>
                            <div style="color: #999; font-size: 12px; white-space: nowrap;">${timeAgo}</div>
                        </div>
                    `;
                }).join('');
                
            } catch (err) {
                console.error('Error loading dashboard notifications:', err);
                const container = document.getElementById('dashboardNotificationsContainer');
                if (container) {
                    container.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc2626;">Error loading notifications</div>';
                }
            }
        }
        
        let currentNotificationFilter = 'active';
        
        async function loadNotificationsPage(filter = 'active') {
            try {
                currentNotificationFilter = filter;
                const response = await fetch(`doctor_get_notifications.php?action=fetch&filter=${filter}`);
                const data = await response.json();
                
                if (!data.success) {
                    console.error('Notifications error:', data.message);
                    const container = document.getElementById('notificationsPageContainer');
                    if (container) {
                        container.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;">No notifications available</div>';
                    }
                    return;
                }
                
                const notifications = data.notifications || [];
                const container = document.getElementById('notificationsPageContainer');
                
                if (!container) return;
                
                if (notifications.length === 0) {
                    container.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;">No notifications</div>';
                    return;
                }
                
                const isArchived = filter === 'archived';
                
                container.innerHTML = notifications.map(notif => {
                    let iconClass = 'fas fa-bell';
                    let iconColor = '#4CAF50';
                    
                    if (notif.type === 'appointment') {
                        iconClass = 'fas fa-calendar-check';
                        iconColor = '#2196F3';
                    } else if (notif.type === 'announcement') {
                        iconClass = 'fas fa-bullhorn';
                        iconColor = '#4CAF50';
                    }
                    
                    const timeAgo = getTimeAgo(notif.created_at);
                    const referenceId = notif.appointment_id || notif.announcement_id;
                    
                    return `
                        <div class="notification-item" style="padding: 16px; border-bottom: 1px solid #e0e0e0; display: flex; align-items: flex-start; gap: 12px; position: relative;">
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                    <i class="${iconClass}" style="color: ${iconColor}; font-size: 20px;"></i>
                                    <span style="font-weight: 600; color: #333;">${notif.title}</span>
                                </div>
                                <div style="color: #666; font-size: 14px;">${notif.message}</div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div style="color: #999; font-size: 12px; white-space: nowrap;">${timeAgo}</div>
                                <div class="notification-actions" style="display: flex; gap: 8px;">
                                    ${isArchived ? 
                                        `<button class="action-btn btn-restore restore-btn" data-type="${notif.type}" data-ref="${referenceId}" title="Restore">
                                            <i class="fas fa-undo"></i> Restore
                                        </button>` :
                                        `<button class="action-btn btn-archive archive-btn" data-type="${notif.type}" data-ref="${referenceId}" title="Archive">
                                            <i class="fas fa-archive"></i> Archive
                                        </button>`
                                    }
                                    <button class="action-btn btn-delete delete-btn" data-type="${notif.type}" data-ref="${referenceId}" title="Delete">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');
                
                // Attach event listeners
                container.querySelectorAll('.archive-btn').forEach(btn => {
                    btn.addEventListener('click', async (e) => {
                        e.stopPropagation();
                        const type = btn.getAttribute('data-type');
                        const refId = btn.getAttribute('data-ref');
                        await archiveDoctorNotification(type, refId);
                    });
                });
                
                container.querySelectorAll('.restore-btn').forEach(btn => {
                    btn.addEventListener('click', async (e) => {
                        e.stopPropagation();
                        const type = btn.getAttribute('data-type');
                        const refId = btn.getAttribute('data-ref');
                        await restoreDoctorNotification(type, refId);
                    });
                });
                
                container.querySelectorAll('.delete-btn').forEach(btn => {
                    btn.addEventListener('click', async (e) => {
                        e.stopPropagation();
                        const type = btn.getAttribute('data-type');
                        const refId = btn.getAttribute('data-ref');
                        const message = btn.closest('.notification-item').querySelector('div[style*="color: #666"]').textContent;
                        confirmDeleteDoctorNotification(type, refId, message);
                    });
                });
                
            } catch (err) {
                console.error('Error loading notifications:', err);
                const container = document.getElementById('notificationsPageContainer');
                if (container) {
                    container.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc2626;">Error loading notifications</div>';
                }
            }
        }
        
        async function archiveDoctorNotification(type, refId) {
            try {
                const formData = new FormData();
                formData.append('notification_type', type);
                formData.append('reference_id', refId);
                const response = await fetch('doctor_get_notifications.php?action=archive', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    // Switch to archived view to show the archived notification
                    currentNotificationFilter = 'archived';
                    
                    // Update filter buttons to show Archived as active
                    const filters = document.querySelectorAll('.filter-btn');
                    filters.forEach(b => {
                        b.classList.remove('active');
                        b.style.background = 'white';
                        b.style.color = '#666';
                        b.style.border = '1px solid #e0e0e0';
                    });
                    const archivedBtn = document.querySelector('.filter-btn[data-filter="archived"]');
                    if (archivedBtn) {
                        archivedBtn.classList.add('active');
                        archivedBtn.style.background = '#2E7D32';
                        archivedBtn.style.color = 'white';
                        archivedBtn.style.border = '1px solid #2E7D32';
                    }
                    
                    // Load archived notifications to show the newly archived notification
                    await loadNotificationsPage('archived');
                    
                    // Also refresh dashboard notifications if dashboard is visible
                    const dashboardContainer = document.getElementById('dashboardNotificationsContainer');
                    if (dashboardContainer) {
                        loadDashboardNotifications();
                    }
                } else {
                    alert('Error: ' + (data.message || 'Failed to archive notification'));
                }
            } catch (e) {
                console.error('Error archiving notification:', e);
                alert('Error archiving notification');
            }
        }
        
        async function restoreDoctorNotification(type, refId) {
            try {
                const formData = new FormData();
                formData.append('notification_type', type);
                formData.append('reference_id', refId);
                const response = await fetch('doctor_get_notifications.php?action=restore', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    await loadNotificationsPage(currentNotificationFilter);
                } else {
                    alert('Error: ' + (data.message || 'Failed to restore notification'));
                }
            } catch (e) {
                console.error('Error restoring notification:', e);
                alert('Error restoring notification');
            }
        }
        
        function confirmDeleteDoctorNotification(type, refId, message) {
            const confirmMsg = `Are you sure you want to permanently delete this notification?\n\n"${message.substring(0, 50)}${message.length > 50 ? '...' : ''}"\n\nThis action cannot be undone.`;
            if (confirm(confirmMsg)) {
                deleteDoctorNotification(type, refId);
            }
        }
        
        async function deleteDoctorNotification(type, refId) {
            try {
                const formData = new FormData();
                formData.append('notification_type', type);
                formData.append('reference_id', refId);
                const response = await fetch('doctor_get_notifications.php?action=delete', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    await loadNotificationsPage(currentNotificationFilter);
                } else {
                    alert('Error: ' + (data.message || 'Failed to delete notification'));
                }
            } catch (e) {
                console.error('Error deleting notification:', e);
                alert('Error deleting notification');
            }
        }
        
        function getTimeAgo(dateString) {
            if (!dateString) return 'Recently';
            const now = new Date();
            const date = new Date(dateString);
            const diffMs = now - date;
            const diffMins = Math.floor(diffMs / 60000);
            const diffHours = Math.floor(diffMs / 3600000);
            const diffDays = Math.floor(diffMs / 86400000);
            
            if (diffMins < 1) return 'Just now';
            if (diffMins < 60) return `${diffMins} minute${diffMins > 1 ? 's' : ''} ago`;
            if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
            if (diffDays < 7) return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`;
            
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }
        
        async function loadUpcomingAppointments() {
            try {
                const response = await fetch('doctor_get_appointments.php');
                const data = await response.json();
                
                if (!data.success || !data.appointments) {
                    const tbody = document.getElementById('upcomingAppointmentsBody');
                    if (tbody) {
                        tbody.innerHTML = '<tr><td colspan="2" style="text-align: center; padding: 20px; color: #666;">No upcoming appointments</td></tr>';
                    }
                    return;
                }
                
                // Filter for upcoming appointments (only approved, future dates)
                // Doctors should only see approved appointments assigned to them
                const now = new Date();
                const upcoming = data.appointments
                    .filter(appt => {
                        if (!appt.start_datetime) return false;
                        const apptDate = new Date(appt.start_datetime);
                        return apptDate >= now && appt.status === 'approved';
                    })
                    .sort((a, b) => new Date(a.start_datetime) - new Date(b.start_datetime))
                    .slice(0, 10); // Show top 10
                
                const tbody = document.getElementById('upcomingAppointmentsBody');
                if (tbody) {
                    if (upcoming.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="2" style="text-align: center; padding: 20px; color: #666;">No upcoming appointments</td></tr>';
                    } else {
                        tbody.innerHTML = upcoming.map(appt => {
                            const dt = new Date(appt.start_datetime);
                            const timeStr = dt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
                            const patientName = appt.patient_name || 'Unknown Patient';
                            return `<tr><td>${patientName}</td><td>${timeStr}</td></tr>`;
                        }).join('');
                    }
                }
            } catch (err) {
                console.error('Error loading upcoming appointments:', err);
                const tbody = document.getElementById('upcomingAppointmentsBody');
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="2" style="text-align: center; padding: 20px; color: #dc2626;">Error loading appointments</td></tr>';
                }
            }
        }

        // --- Doctor appointments filtering (real-time) ---
        let doctorAppointments = [];

        async function loadDoctorAppointments() {
            try {
                const response = await fetch('doctor_get_appointments.php', { method: 'GET' });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                if (!data.success) {
                    console.error('API Error:', data.message, data.error_details);
                    throw new Error(data.message || 'Failed to load appointments');
                }
                
                doctorAppointments = data.appointments || [];
                // Default to showing all appointments with all statuses
                renderDoctorAppointments('all', 'all');
            } catch (err) {
                console.error('Error loading doctor appointments:', err);
                const tbody = document.getElementById('doctorAppointmentsBody');
                if (tbody) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="5" style="text-align:center; padding:24px; color:#dc2626;">
                                Error loading appointments: ${err.message || 'Unknown error'}
                            </td>
                        </tr>`;
                }
            }
        }

        function renderDoctorAppointments(range, status) {
            const tbody = document.getElementById('doctorAppointmentsBody');
            if (!tbody) return;

            if (!Array.isArray(doctorAppointments) || doctorAppointments.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align:center; padding:24px; color:#6b7280;">
                            No appointments found.
                        </td>
                    </tr>`;
                return;
            }

            const now = new Date();
            const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            const endOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1);

            // Start / end of current week (Monday–Sunday)
            const day = startOfToday.getDay(); // 0 (Sun) - 6 (Sat)
            const diffToMonday = (day === 0 ? -6 : 1) - day;
            const startOfWeek = new Date(startOfToday);
            startOfWeek.setDate(startOfToday.getDate() + diffToMonday);
            const endOfWeek = new Date(startOfWeek);
            endOfWeek.setDate(startOfWeek.getDate() + 7);

            let filtered = doctorAppointments.slice().filter(appt => {
                const dt = new Date(appt.start_datetime);

                // Date range filter
                if (range === 'today') {
                    if (dt < startOfToday || dt >= endOfToday) return false;
                } else if (range === 'week') {
                    if (dt < startOfWeek || dt >= endOfWeek) return false;
                } else if (range === 'all') {
                    // Show all appointments regardless of date
                    // No date filtering needed
                }

                // Status filter - only show approved and other non-pending statuses
                // Pending appointments are hidden from doctors (only FDO can see them)
                if (status && status !== 'all') {
                    const apptStatus = (appt.status || '').toLowerCase();
                    // Explicitly exclude pending status
                    if (apptStatus === 'pending') return false;
                    if (apptStatus !== status.toLowerCase()) return false;
                } else {
                    // Even when showing "all", exclude pending appointments
                    const apptStatus = (appt.status || '').toLowerCase();
                    if (apptStatus === 'pending') return false;
                }

                return true;
            });

            // Sort ascending by date/time
            filtered.sort((a, b) => new Date(a.start_datetime) - new Date(b.start_datetime));

            if (filtered.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align:center; padding:24px; color:#6b7280;">
                            No appointments match the selected filters.
                        </td>
                    </tr>`;
                return;
            }

            tbody.innerHTML = filtered.map(appt => {
                const dt = new Date(appt.start_datetime);
                const dateStr = dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
                const timeStr = dt.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
                const patientName = appt.patient_name || appt.patient_username || 'Unknown';
                const statusKey = (appt.status || 'pending').toLowerCase();
                const statusLabel = statusKey.charAt(0).toUpperCase() + statusKey.slice(1);
                
                // Check if appointment is unassigned (no doctor_id)
                const isUnassigned = !appt.doctor_id || appt.doctor_id === null;
                const unassignedNote = isUnassigned ? '<div style="color: #f97316; font-size: 11px; font-weight: 500; margin-top: 2px;">(Unassigned)</div>' : '';
                
                // Format dependent note - show on new line with smaller font and different color
                let dependentNote = '';
                if (appt.is_dependent && appt.parent_name) {
                    dependentNote = `<div style="color: #64748b; font-size: 11px; font-weight: 400; margin-top: 2px;">Dependent of ${appt.parent_name}</div>`;
                }

                return `
                    <tr>
                        <td>
                            <div style="line-height: 1.4;">
                                <div style="font-weight: 500; color: #1f2937;">${patientName}</div>
                                ${dependentNote}
                                ${unassignedNote}
                            </div>
                        </td>
                        <td>${dateStr}</td>
                        <td>${timeStr}</td>
                        <td>
                            <span class="status-badge status-${statusKey}">
                                ${statusLabel}
                            </span>
                        </td>
                    </tr>`;
            }).join('');
        }

        // --- Follow-Up Appointments Functions ---
        async function loadDoctorFollowUps() {
            try {
                const response = await fetch('doctor_get_followups.php');
                const data = await response.json();
                
                if (!data.success) {
                    console.error('Follow-ups error:', data.message);
                    const tbody = document.getElementById('doctorFollowUpsBody');
                    if (tbody) {
                        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:24px; color:#dc2626;">Error loading follow-ups</td></tr>';
                    }
                    return;
                }
                
                // Display reschedule requests
                const rescheduleRequests = data.reschedule_requests || [];
                const rescheduleTbody = document.getElementById('doctorRescheduleRequestsBody');
                
                if (rescheduleTbody) {
                    if (rescheduleRequests.length === 0) {
                        rescheduleTbody.innerHTML = `
                            <tr>
                                <td colspan="5" style="text-align:center; padding:24px; color:#6b7280;">
                                    No reschedule requests.
                                </td>
                            </tr>`;
                    } else {
                        rescheduleTbody.innerHTML = rescheduleRequests.map(request => {
                            const patientName = request.patient_name || 'Unknown Patient';
                            const proposedDate = new Date(request.proposed_datetime);
                            const date = proposedDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                            const time = proposedDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
                            const originalDate = request.original_appointment_date ? 
                                new Date(request.original_appointment_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                            
                            return `
                                <tr>
                                    <td>${patientName}</td>
                                    <td>${originalDate}</td>
                                    <td>${date}</td>
                                    <td>${time}</td>
                                    <td>
                                        <button class="btn-primary" onclick="provideRescheduleAlternatives(${request.id})" style="padding: 6px 16px; font-size: 13px;">Provide Alternatives</button>
                                    </td>
                                </tr>`;
                        }).join('');
                    }
                }
                
                const followups = data.followups || [];
                const tbody = document.getElementById('doctorFollowUpsBody');
                
                if (!tbody) return;
                
                if (followups.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="5" style="text-align:center; padding:24px; color:#6b7280;">
                                No follow-up appointments pending approval.
                            </td>
                        </tr>`;
                } else {
                    tbody.innerHTML = followups.map(followup => {
                        const patientName = followup.patient_name || 'Unknown Patient';
                        const date = followup.date || 'N/A';
                        const time = followup.time || 'N/A';
                        const originalDate = followup.original_date || 'N/A';
                        
                        return `
                            <tr>
                                <td>${patientName}</td>
                                <td>${originalDate}</td>
                                <td>${date}</td>
                                <td>${time}</td>
                                <td>
                                    <button class="btn-primary" onclick="approveSelectedFollowUp(${followup.id})" style="padding: 6px 16px; margin-right: 8px; font-size: 13px;">Approve</button>
                                    <button class="btn-secondary" onclick="declineSelectedFollowUp(${followup.id})" style="padding: 6px 16px; font-size: 13px;">Decline</button>
                                </td>
                            </tr>`;
                    }).join('');
                }
                
                // Display approved follow-ups
                const approvedFollowups = data.approved_followups || [];
                const approvedTbody = document.getElementById('doctorApprovedFollowUpsBody');
                
                if (approvedTbody) {
                    if (approvedFollowups.length === 0) {
                        approvedTbody.innerHTML = `
                            <tr>
                                <td colspan="4" style="text-align:center; padding:24px; color:#6b7280;">
                                    No approved follow-up appointments.
                                </td>
                            </tr>`;
                    } else {
                        approvedTbody.innerHTML = approvedFollowups.map(followup => {
                            const patientName = followup.patient_name || 'Unknown Patient';
                            const date = followup.date || 'N/A';
                            const time = followup.time || 'N/A';
                            const originalDate = followup.original_date || 'N/A';
                            
                            return `
                                <tr>
                                    <td>${patientName}</td>
                                    <td>${originalDate}</td>
                                    <td>${date}</td>
                                    <td>${time}</td>
                                </tr>`;
                        }).join('');
                    }
                }
                
            } catch (err) {
                console.error('Error loading follow-ups:', err);
                const tbody = document.getElementById('doctorFollowUpsBody');
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:24px; color:#dc2626;">Error loading follow-ups</td></tr>';
                }
                const approvedTbody = document.getElementById('doctorApprovedFollowUpsBody');
                if (approvedTbody) {
                    approvedTbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:24px; color:#dc2626;">Error loading approved follow-ups</td></tr>';
                }
            }
        }

        async function provideRescheduleAlternatives(followUpId) {
            const modal = document.createElement('div');
            modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;';
            modal.innerHTML = `
                <div style="background: white; padding: 30px; border-radius: 12px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
                    <h3 style="margin-top: 0; color: #2E7D32;">Provide Alternative Schedule Options</h3>
                    <p style="color: #666; margin-bottom: 20px;">Please provide at least one alternative date and time option for the patient to choose from.</p>
                    <form id="rescheduleAlternativesForm">
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 600;">Alternative Option 1 *</label>
                            <input type="datetime-local" name="alternative_1" required style="width: 100%; padding: 10px; border: 2px solid #e0e0e0; border-radius: 6px;">
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 600;">Alternative Option 2</label>
                            <input type="datetime-local" name="alternative_2" style="width: 100%; padding: 10px; border: 2px solid #e0e0e0; border-radius: 6px;">
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 600;">Alternative Option 3</label>
                            <input type="datetime-local" name="alternative_3" style="width: 100%; padding: 10px; border: 2px solid #e0e0e0; border-radius: 6px;">
                        </div>
                        <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 30px;">
                            <button type="button" onclick="this.closest('div[style*=\"position: fixed\"]').remove()" style="padding: 10px 20px; border: 2px solid #e0e0e0; background: white; border-radius: 6px; cursor: pointer;">Cancel</button>
                            <button type="submit" style="padding: 10px 20px; background: #4CAF50; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">Submit Alternatives</button>
                        </div>
                    </form>
                </div>
            `;
            document.body.appendChild(modal);
            
            modal.querySelector('#rescheduleAlternativesForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const form = e.target;
                const formData = new FormData();
                formData.append('action', 'approve_reschedule');
                formData.append('follow_up_id', followUpId);
                formData.append('alternative_datetime_1', form.alternative_1.value);
                if (form.alternative_2.value) formData.append('alternative_datetime_2', form.alternative_2.value);
                if (form.alternative_3.value) formData.append('alternative_datetime_3', form.alternative_3.value);
                
                try {
                    const response = await fetch('doctor_approve_reschedule.php', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        alert('Alternative schedule options provided to patient successfully!');
                        modal.remove();
                        loadDoctorFollowUps();
                    } else {
                        alert('Error: ' + (data.message || 'Failed to provide alternatives'));
                    }
                } catch (err) {
                    console.error('Error:', err);
                    alert('Error providing alternatives. Please try again.');
                }
            });
        }

        async function approveSelectedFollowUp(followUpId) {
            if (!confirm('Approve this follow-up appointment?')) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'approve_selected');
                formData.append('follow_up_id', followUpId);
                
                const response = await fetch('doctor_approve_reschedule.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Follow-up appointment approved successfully!');
                    loadDoctorFollowUps();
                    loadDoctorAppointments();
                } else {
                    alert('Error: ' + (data.message || 'Failed to approve follow-up'));
                }
            } catch (err) {
                console.error('Error approving follow-up:', err);
                alert('Error approving follow-up appointment. Please try again.');
            }
        }

        async function declineSelectedFollowUp(followUpId) {
            const reason = prompt('Please provide a reason for declining this follow-up appointment:');
            if (!reason || reason.trim() === '') {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'decline_selected');
                formData.append('follow_up_id', followUpId);
                formData.append('decline_reason', reason.trim());
                
                const response = await fetch('doctor_approve_reschedule.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Follow-up appointment declined. Patient can select another option.');
                    loadDoctorFollowUps();
                } else {
                    alert('Error: ' + (data.message || 'Failed to decline follow-up'));
                }
            } catch (err) {
                console.error('Error declining follow-up:', err);
                alert('Error declining follow-up appointment. Please try again.');
            }
        }

        function resizeIframe(frame) {
            try {
                const doc = frame.contentDocument || frame.contentWindow.document;
                if (!doc) return;
                doc.documentElement.style.overflow = 'hidden';
                doc.body.style.margin = '0';
                doc.body.style.overflow = 'hidden';
                const newHeight = Math.max(
                    doc.body.scrollHeight,
                    doc.documentElement.scrollHeight
                );
                frame.style.height = newHeight + 20 + 'px';
            } catch (err) {
                console.error('Unable to resize iframe', err);
            }
        }

        function setIframeContent(frame, url) {
            if (!frame) return;
            frame.style.height = Math.round(window.innerHeight * 0.7) + 'px';
            frame.onload = function() {
                resizeIframe(frame);
                setTimeout(() => resizeIframe(frame), 250);
            };
            frame.src = url;
        }

        function openPatientProfileModal(patientId) {
            const modal = document.getElementById('viewPatientModal');
            const frame = document.getElementById('patientProfileFrame');
            setIframeContent(frame, `patient_record.php${patientId ? `?patient_id=${patientId}` : ''}`);
            modal.classList.add('active');
            document.body.classList.add('modal-open');
        }

        function closePatientModal() {
            const modal = document.getElementById('viewPatientModal');
            const frame = document.getElementById('patientProfileFrame');
            modal.classList.remove('active');
            frame.src = 'about:blank';
            document.body.classList.remove('modal-open');
        }

        function openConsultationModal(patientId) {
            const modal = document.getElementById('consultModal');
            const frame = document.getElementById('consultFrame');
            setIframeContent(frame, `doctor_consultation.php${patientId ? `?patient_id=${patientId}` : ''}`);
            modal.classList.add('active');
            document.body.classList.add('modal-open');
        }

        function closeConsultModal() {
            const modal = document.getElementById('consultModal');
            const frame = document.getElementById('consultFrame');
            modal.classList.remove('active');
            frame.src = 'about:blank';
            document.body.classList.remove('modal-open');
        }

        // --- Patient Management Functions ---
        let patientsData = [];
        let patientsStats = { total_patients: 0, new_this_month: 0, total_dependents: 0 };

        async function loadPatients() {
            try {
                const response = await fetch('doctor_get_patients.php');
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.message || 'Failed to load patients');
                }
                
                patientsData = data.patients || [];
                patientsStats = data.stats || { total_patients: 0, new_this_month: 0, total_dependents: 0 };
                
                // Update stats from server (these now match the actual patient list)
                document.getElementById('totalPatientsCount').textContent = patientsStats.total_patients;
                document.getElementById('newThisMonthCount').textContent = patientsStats.new_this_month;
                
                // Render patients table
                renderPatientsTable();
                
            } catch (err) {
                console.error('Error loading patients:', err);
                const tbody = document.getElementById('patientsTable');
                if (tbody) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="5" style="text-align:center; padding:24px; color:#dc2626;">
                                Error loading patients: ${err.message}
                            </td>
                        </tr>`;
                }
            }
        }

        function renderPatientsTable() {
            const tbody = document.getElementById('patientsTable');
            if (!tbody) return;
            
            if (!Array.isArray(patientsData) || patientsData.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align:center; padding:24px; color:#6b7280;">
                            No patients found.
                        </td>
                    </tr>`;
                return;
            }
            
            // Filter and sort patients
            let filtered = patientsData.slice();
            
            // Apply search filter
            const searchTerm = (document.getElementById('patientSearch')?.value || '').toLowerCase();
            if (searchTerm) {
                filtered = filtered.filter(patient => {
                    const fullName = `${patient.first_name || ''} ${patient.middle_name || ''} ${patient.last_name || ''}`.toLowerCase();
                    const formattedName = `${patient.last_name || ''}, ${patient.first_name || ''} ${patient.middle_name?.[0] || ''}`.toLowerCase();
                    return fullName.includes(searchTerm) || formattedName.includes(searchTerm);
                });
            }
            
            // Apply sort filter
            const sortFilter = document.getElementById('patientSortFilter')?.value || 'alphabetical';
            if (sortFilter === 'a-z' || sortFilter === 'z-a') {
                filtered.sort((a, b) => {
                    const nameA = `${a.last_name || ''}, ${a.first_name || ''} ${a.middle_name?.[0] || ''}`.toLowerCase();
                    const nameB = `${b.last_name || ''}, ${b.first_name || ''} ${b.middle_name?.[0] || ''}`.toLowerCase();
                    if (sortFilter === 'a-z') {
                        return nameA.localeCompare(nameB);
                    } else {
                        return nameB.localeCompare(nameA);
                    }
                });
            }
            
            if (filtered.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align:center; padding:24px; color:#6b7280;">
                            No patients found matching the selected filters.
                        </td>
                    </tr>`;
                return;
            }
            
            tbody.innerHTML = filtered.map(patient => {
                const initials = (patient.first_name?.[0] || '') + (patient.last_name?.[0] || '');
                // Format: Last Name, First Name, Middle Initial
                const middleInitial = patient.middle_name ? (patient.middle_name[0] || '') : '';
                const formattedName = `${patient.last_name || ''}, ${patient.first_name || ''}${middleInitial ? ' ' + middleInitial + '.' : ''}`.trim();
                const statusClass = patient.status === 'active' ? 'status-active' : 'status-inactive';
                const statusText = patient.status === 'active' ? 'Active' : 'Inactive';
                
                // Add dependent label if applicable
                const dependentLabel = patient.is_dependent && patient.parent_name 
                    ? `<div style="color: #64748b; font-size: 11px; font-weight: 400; margin-top: 2px;">Dependent of ${patient.parent_name}</div>` 
                    : '';
                
                return `
                    <tr>
                        <td>
                            <div class="doctor-info">
                                <div class="doctor-avatar">${initials}</div>
                                <div style="line-height: 1.4;">
                                    <div style="font-weight: 500; color: #1f2937;">${formattedName}</div>
                                    ${dependentLabel}
                                </div>
                            </div>
                        </td>
                        <td>${patient.age_sex || 'N/A'}</td>
                        <td>${patient.last_visit || 'No visits yet'}</td>
                        <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                        <td>
                            <div class="action-buttons">
                                <button class="action-btn btn-view" onclick="openPatientProfileModal(${patient.id})" title="View Patient">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="action-btn btn-consult" onclick="openConsultationModal(${patient.id})" title="Consult Patient">
                                    <i class="fas fa-stethoscope"></i> Consult
                                </button>
                            </div>
                        </td>
                    </tr>`;
            }).join('');
        }

        function openAddPatientModal() {
            const modal = document.getElementById('addPatientModal');
            const form = document.getElementById('addPatientForm');
            if (modal && form) {
                form.reset();
                const ageInput = document.getElementById('patientAge');
                if (ageInput) ageInput.value = '';
                modal.classList.add('active');
                document.body.classList.add('modal-open');
                // Setup age calculator after modal is opened
                setTimeout(setupPatientAgeCalculator, 100);
            }
        }

        function closeAddPatientModal() {
            const modal = document.getElementById('addPatientModal');
            const form = document.getElementById('addPatientForm');
            if (modal) {
                modal.classList.remove('active');
                document.body.classList.remove('modal-open');
                if (form) form.reset();
            }
        }

        // Calculate age when birthdate changes (for dynamically loaded modals)
        function setupPatientAgeCalculator() {
            const dobInput = document.getElementById('patientDateOfBirth');
            if (dobInput) {
                dobInput.addEventListener('change', function() {
                    const birthDate = new Date(this.value);
                    if (!isNaN(birthDate.getTime())) {
                        const today = new Date();
                        let age = today.getFullYear() - birthDate.getFullYear();
                        const monthDiff = today.getMonth() - birthDate.getMonth();
                        
                        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                            age--;
                        }
                        
                        const ageInput = document.getElementById('patientAge');
                        if (ageInput) ageInput.value = age + ' years old';
                    }
                });
            }
        }


        // Add medicine row for prescription page
        function addMedicineRow() {
            const container = document.getElementById('medicinesContainer');
            if (!container) return;
            const row = document.createElement('div');
            row.className = 'form-row';
            row.innerHTML = `
                <div class="form-group">
                    <label>Medicine Name</label>
                    <input type="text" class="form-control" name="medicine_name[]" placeholder="e.g. Amoxicillin 500mg">
                </div>
                <div class="form-group">
                    <label>Dosage</label>
                    <input type="text" class="form-control" name="dosage[]" placeholder="1 capsule">
                </div>
                <div class="form-group">
                    <label>Frequency</label>
                    <input type="text" class="form-control" name="frequency[]" placeholder="Every 8 hours">
                </div>
                <div class="form-group">
                    <label>Duration</label>
                    <input type="text" class="form-control" name="duration[]" placeholder="7 days">
                </div>
            `;
            container.appendChild(row);
        }

        // Open prescription view for selected patient
        function openPrescription(patientName) {
            const mainContent = document.getElementById('mainContent');
            mainContent.innerHTML = getPrescriptionPage(patientName);

            const form = document.getElementById('prescriptionForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    alert('Prescription saved for ' + patientName + '. (Backend wiring can be added later.)');
                    loadPage('patients');
                });
            }
        }

        // Modal functions

        // Announcements Functions
        function loadDoctorAnnouncements() {
            fetch('get_announcements.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // My Announcements: Only show announcements created by the current user
                        const myAnnouncements = data.my_announcements || [];
                        
                        // Deduplicate myAnnouncements by announcement_id (safety measure)
                        const myAnnouncementsMap = new Map();
                        myAnnouncements.forEach(ann => {
                            if (ann.announcement_id && !myAnnouncementsMap.has(ann.announcement_id)) {
                                myAnnouncementsMap.set(ann.announcement_id, ann);
                            }
                        });
                        const deduplicatedMyAnnouncements = Array.from(myAnnouncementsMap.values());
                        
                        const tbody = document.getElementById('announcementsTableBody');
                        if (tbody) {
                            if (deduplicatedMyAnnouncements.length === 0) {
                                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 30px;">No announcements found</td></tr>';
                            } else {
                                tbody.innerHTML = deduplicatedMyAnnouncements.map(ann => {
                                    const date = new Date(ann.date_posted);
                                    let statusBadge = '';
                                    
                                    if (ann.status === 'approved') {
                                        statusBadge = '<span style="background:#4CAF50;color:white;padding:4px 12px;border-radius:12px;font-size:12px;">Published</span>';
                                    } else if (ann.status === 'pending') {
                                        statusBadge = '<span style="background:#ffc107;color:#856404;padding:4px 12px;border-radius:12px;font-size:12px;">Pending FDO Approval</span>';
                                    } else if (ann.status === 'rejected') {
                                        statusBadge = '<span style="background:#f44336;color:white;padding:4px 12px;border-radius:12px;font-size:12px;">Rejected</span>';
                                    }
                                    
                                    return `
                                        <tr>
                                            <td style="padding:12px; border-bottom:1px solid #eee;">${ann.title || 'N/A'}</td>
                                            <td style="padding:12px; border-bottom:1px solid #eee;">${ann.category || 'General'}</td>
                                            <td style="padding:12px; border-bottom:1px solid #eee;">${date.toLocaleDateString()}</td>
                                            <td style="padding:12px; border-bottom:1px solid #eee;">${statusBadge}</td>
                                            <td style="padding:12px; border-bottom:1px solid #eee;">
                                                <button onclick="viewAnnouncement(${ann.announcement_id})" style="background:none;border:none;color:#4CAF50;cursor:pointer;text-decoration:underline;margin-right:12px;">View</button>
                                                <button onclick="deleteAnnouncement(${ann.announcement_id})" style="background:none;border:none;color:#f44336;cursor:pointer;text-decoration:underline;">Delete</button>
                                            </td>
                                        </tr>
                                    `;
                                }).join('');
                                
                                // Show banner if there are pending announcements
                                const pendingCount = deduplicatedMyAnnouncements.filter(a => a.status === 'pending').length;
                                const banner = document.getElementById('fdoApprovalBanner');
                                if (banner) {
                                    banner.style.display = pendingCount > 0 ? 'block' : 'none';
                                }
                            }
                        }
                        
                        // Load published announcements: include approved announcements from other users AND from current user
                        // Get approved announcements from myAnnouncements (doctor's own published announcements)
                        const myApprovedAnnouncements = deduplicatedMyAnnouncements.filter(a => a.status === 'approved');
                        
                        // Merge with published announcements from other users
                        const allPublishedAnnouncements = [...(data.published_announcements || []), ...myApprovedAnnouncements];
                        
                        // Deduplicate by announcement_id to avoid showing the same announcement twice
                        const publishedMap = new Map();
                        allPublishedAnnouncements.forEach(ann => {
                            if (ann.announcement_id && !publishedMap.has(ann.announcement_id)) {
                                publishedMap.set(ann.announcement_id, ann);
                            }
                        });
                        const deduplicatedPublished = Array.from(publishedMap.values());
                        
                        // Load published announcements (filter for approved only)
                        loadPublishedAnnouncements(deduplicatedPublished);
                    }
                })
                .catch(error => {
                    console.error('Error loading announcements:', error);
                    const tbody = document.getElementById('announcementsTableBody');
                    if (tbody) {
                        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 30px; color: red;">Error loading announcements</td></tr>';
                    }
                });
        }

        function loadPublishedAnnouncements(allAnnouncements) {
            const published = allAnnouncements.filter(a => a.status === 'approved');
            const container = document.getElementById('publishedAnnouncementsContainer');
            
            if (!container) return;
            
            if (published.length === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 30px; color: #666;">No published announcements yet</div>';
            } else {
                container.innerHTML = published.map(ann => {
                    const date = new Date(ann.date_posted);
                    const startDate = ann.start_date ? new Date(ann.start_date) : null;
                    const endDate = ann.end_date ? new Date(ann.end_date) : null;
                    
                    let dateInfo = '';
                    if (startDate && endDate) {
                        dateInfo = `${startDate.toLocaleDateString()} - ${endDate.toLocaleDateString()}`;
                    } else if (startDate) {
                        dateInfo = `Starting on ${startDate.toLocaleDateString()}`;
                    } else {
                        dateInfo = `Posted on ${date.toLocaleDateString()}`;
                    }
                    
                    return `
                        <div style="background:#fff;border-radius:12px;padding:20px;margin-bottom:16px;box-shadow:0 4px 16px rgba(0,0,0,0.08);border-left:4px solid #66BB6A;">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
                                <div>
                                    <div style="font-size:18px;font-weight:700;color:#1f2937;">${ann.title || 'N/A'}</div>
                                    <div style="color:#6b7280;font-size:14px;">${ann.category || 'General'}</div>
                                </div>
                                <button class="btn btn-primary" type="button" onclick="viewPublishedAnnouncement(${ann.announcement_id})" style="padding:10px 20px; background:#4CAF50; color:white; border:none; border-radius:4px; cursor:pointer;">View More</button>
                            </div>
                            <div style="color:#6b7280;font-size:13px;margin-top:8px;">${dateInfo}</div>
                        </div>
                    `;
                }).join('');
            }
        }

        function openAnnouncementModal() {
            const modal = document.getElementById('createAnnouncementModal');
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
                const form = document.getElementById('announcementForm');
                if (form) {
                    form.reset();
                }
                const counter = document.getElementById('titleCharCount');
                if (counter) {
                    counter.textContent = '0';
                }
                const imagePreview = document.getElementById('imagePreview');
                if (imagePreview) {
                    imagePreview.style.display = 'none';
                }
                const scheduleCustom = document.getElementById('announcementScheduleCustom');
                if (scheduleCustom) {
                    scheduleCustom.style.display = 'none';
                }
                
                // Image preview handler
                const imageInput = document.getElementById('announcementImage');
                if (imageInput) {
                    imageInput.onchange = function(e) {
                        const file = e.target.files[0];
                        if (file) {
                            const reader = new FileReader();
                            reader.onload = function(e) {
                                document.getElementById('previewImg').src = e.target.result;
                                document.getElementById('imagePreview').style.display = 'block';
                            };
                            reader.readAsDataURL(file);
                        } else {
                            document.getElementById('imagePreview').style.display = 'none';
                        }
                    };
                }
                
                // Schedule custom input handler
                const scheduleSelect = document.getElementById('announcementSchedule');
                if (scheduleSelect) {
                    scheduleSelect.onchange = function() {
                        const customInput = document.getElementById('announcementScheduleCustom');
                        if (this.value === 'Custom') {
                            customInput.style.display = 'block';
                            customInput.required = true;
                        } else {
                            customInput.style.display = 'none';
                            customInput.required = false;
                            customInput.value = '';
                        }
                    };
                }
            }
        }

        function closeCreateAnnouncementModal() {
            const modal = document.getElementById('createAnnouncementModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
                const form = document.getElementById('announcementForm');
                if (form) {
                    form.reset();
                }
            }
        }

        function submitAnnouncement(event) {
            event.preventDefault();
            
            const formData = new FormData(document.getElementById('announcementForm'));
            
            // Handle schedule field
            const scheduleSelect = document.getElementById('announcementSchedule');
            const scheduleCustom = document.getElementById('announcementScheduleCustom');
            if (scheduleSelect && scheduleSelect.value === 'Custom' && scheduleCustom && scheduleCustom.value) {
                formData.set('schedule', scheduleCustom.value);
            } else if (scheduleSelect) {
                formData.set('schedule', scheduleSelect.value);
            }
            
            fetch('submit_announcement.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeCreateAnnouncementModal();
                    loadDoctorAnnouncements();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error submitting announcement');
            });
        }

        function viewAnnouncement(id) {
            fetch('get_announcements.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const announcement = data.announcements.find(a => a.announcement_id == id);
                        if (announcement) {
                            const date = new Date(announcement.date_posted);
                            const modal = document.getElementById('viewAnnouncementModal');
                            const body = document.getElementById('viewAnnouncementModalBody');
                            
                            if (modal && body) {
                                body.innerHTML = `
                                    <div style="text-align:center; margin-bottom:16px;">
                                        <div style="font-size:22px;font-weight:800;color:#1f2937;">${announcement.title}</div>
                                        <div style="color:#6b7280; font-size:14px;">${date.toLocaleDateString()}</div>
                                        <div style="color:#6b7280; font-size:14px;">Category: ${announcement.category || 'General'}</div>
                                        <div style="color:#6b7280; font-size:14px;">Status: ${announcement.status}</div>
                                    </div>
                                    <p style="line-height:1.8; color:#333; text-align:justify; white-space:pre-wrap;">${announcement.content}</p>
                                    ${announcement.rejection_reason ? `<div style="background:#ffebee; padding:15px; border-radius:4px; margin-top:20px;"><strong>Rejection Reason:</strong> ${announcement.rejection_reason}</div>` : ''}
                                `;
                                modal.style.display = 'block';
                                document.body.style.overflow = 'hidden';
                            }
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading announcement details');
                });
        }

        function deleteAnnouncement(id) {
            if (!confirm('Are you sure you want to delete this announcement? This action cannot be undone.')) {
                return;
            }
            
            fetch('delete_announcement.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ announcement_id: id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message || 'Announcement deleted successfully');
                    loadDoctorAnnouncements(); // Reload the announcements list
                } else {
                    alert('Error: ' + (data.message || 'Failed to delete announcement'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error deleting announcement');
            });
        }

        function viewPublishedAnnouncement(id) {
            fetch('get_announcements.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const announcement = data.announcements.find(a => a.announcement_id == id);
                        if (announcement) {
                            const date = new Date(announcement.date_posted);
                            const startDate = announcement.start_date ? new Date(announcement.start_date) : null;
                            const endDate = announcement.end_date ? new Date(announcement.end_date) : null;
                            
                            let subtitle = '';
                            let dateInfo = '';
                            
                            if (announcement.schedule && announcement.schedule !== 'Not Applicable') {
                                subtitle = announcement.schedule;
                            } else if (startDate && endDate) {
                                subtitle = `${startDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })} - ${endDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}`;
                            } else if (startDate) {
                                subtitle = startDate.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
                                dateInfo = `Starts on ${startDate.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}`;
                            } else {
                                subtitle = announcement.category || 'General';
                                dateInfo = `Posted on ${date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}`;
                            }
                            
                            let imageHtml = '';
                            if (announcement.image_path) {
                                imageHtml = `<div style="margin-bottom:20px;"><img src="${announcement.image_path}" alt="${announcement.title}" style="width:100%; max-height:400px; object-fit:cover; border-radius:12px; border:1px solid #ddd;"></div>`;
                            }
                            
                            document.getElementById('viewAnnouncementModalBody').innerHTML = `
                                ${imageHtml}
                                <div class="announcement-modal-title">${announcement.title}</div>
                                <div class="announcement-modal-subtitle">${subtitle}</div>
                                ${dateInfo ? `<div class="announcement-modal-date">${dateInfo}</div>` : ''}
                                <div class="announcement-modal-body">${formatAnnouncementContent(announcement.content)}</div>
                            `;
                            
                            document.getElementById('viewAnnouncementModal').style.display = 'block';
                            document.body.style.overflow = 'hidden';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading announcement details');
                });
        }

        function formatAnnouncementContent(content) {
            // Convert line breaks to paragraphs
            return '<p>' + content.replace(/\n\n/g, '</p><p>').replace(/\n/g, '<br>') + '</p>';
        }

        function closeViewAnnouncementModal() {
            const modal = document.getElementById('viewAnnouncementModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Load dashboard page by default
            loadPage('dashboard');

            // Add click handlers to all nav links
            document.querySelectorAll('.nav-link[data-page]').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const page = this.getAttribute('data-page');
                    if (page) {
                        loadPage(page);
                    }
                });
            });

            // Close modal when clicking outside
            window.onclick = function(event) {
                const viewModal = document.getElementById('viewPatientModal');
                if (event.target == viewModal) {
                    closePatientModal();
                }
                const consultModal = document.getElementById('consultModal');
                if (event.target == consultModal) {
                    closeConsultModal();
                }
                const addPatientModal = document.getElementById('addPatientModal');
                if (event.target == addPatientModal) {
                    closeAddPatientModal();
                }
                const createAnnouncementModal = document.getElementById('createAnnouncementModal');
                if (event.target == createAnnouncementModal) {
                    closeCreateAnnouncementModal();
                }
                const viewAnnouncementModal = document.getElementById('viewAnnouncementModal');
                if (event.target == viewAnnouncementModal) {
                    closeViewAnnouncementModal();
                }
            };

            // Close announcement modal when clicking outside
            window.addEventListener('click', function(e) {
                const modal = document.getElementById('viewAnnouncementModal');
                if (e.target === modal) {
                    closeViewAnnouncementModal();
                }
            });

            // Add Patient Form Handler
            const addPatientForm = document.getElementById('addPatientForm');
            if (addPatientForm) {
                addPatientForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const formData = new FormData(addPatientForm);
                    
                    try {
                        const response = await fetch('doctor_add_patient.php', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await response.json();
                        
                        if (!data.success) {
                            throw new Error(data.message || 'Unable to add patient');
                        }
                        
                        alert('Patient added successfully!');
                        closeAddPatientModal();
                        
                        // Reload patients list and stats
                        if (document.getElementById('patientsTable')) {
                            loadPatients();
                        }
                        
                    } catch (err) {
                        console.error('Error:', err);
                        alert('Error: ' + err.message);
                    }
                });
            }

        });

        // Schedule Management Functions - Date-based
        // Store schedule data by date (in a real app, this would come from a server)
        let scheduleData = {};
        
        // Time slots for the schedule - MUST use master time slot list
        // This matches CLINIC_TIME_SLOTS from clinic_time_slots.php
        const timeSlots = [
            '7:00 AM',   // 07:00 (3 slots available)
            '7:30 AM',   // 07:30 (3 slots available)
            '8:00 AM',   // 08:00 (3 slots available)
            '8:30 AM',   // 08:30 (3 slots available)
            '9:00 AM',   // 09:00 (3 slots available)
            '9:30 AM',   // 09:30 (3 slots available)
            '10:00 AM',  // 10:00 (3 slots available)
            '10:30 AM',  // 10:30 (3 slots available)
            '11:00 AM',  // 11:00 (3 slots available)
            '11:30 AM',  // 11:30 (3 slots available)
            '1:00 PM',   // 13:00 (3 slots available)
            '1:30 PM',   // 13:30 (3 slots available)
            '2:00 PM',   // 14:00 (3 slots available)
            '2:30 PM',   // 14:30 (3 slots available)
            '3:00 PM'    // 15:00 (3 slots available)
        ];
        
        // Helper function to generate time slot options HTML
        function generateTimeSlotOptions(selectedValue = '') {
            let options = '<option value="">Select time...</option>';
            timeSlots.forEach(slot => {
                const value = convertTimeTo24Hour(slot);
                const selected = (value === selectedValue) ? 'selected' : '';
                options += `<option value="${value}" ${selected}>${slot}</option>`;
            });
            return options;
        }
        
        // Validate if a time matches the allowed time slots
        function isValidTimeSlot(time24) {
            if (!time24) return false;
            const time12 = formatTimeForDisplay(time24);
            return timeSlots.includes(time12);
        }

        // Initialize schedule page
        function initializeSchedule() {
            // Get current week (Monday of current week)
            const today = new Date();
            const dayOfWeek = today.getDay();
            const diff = today.getDate() - dayOfWeek + (dayOfWeek === 0 ? -6 : 1); // Adjust to Monday
            const monday = new Date(today.setDate(diff));
            const year = monday.getFullYear();
            const week = getWeekNumber(monday);
            const weekValue = `${year}-W${week.toString().padStart(2, '0')}`;
            
            const weekPicker = document.getElementById('scheduleWeekPicker');
            if (weekPicker) {
                weekPicker.value = weekValue;
                loadScheduleForWeek(weekValue);
            }
        }
        
        // Helper function to get week number
        function getWeekNumber(date) {
            const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
            const dayNum = d.getUTCDay() || 7;
            d.setUTCDate(d.getUTCDate() + 4 - dayNum);
            const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
            return Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
        }
        
        // Handle week picker change - ensure only weekdays (Monday-Friday)
        function handleWeekPickerChange(weekValue) {
            if (!weekValue) return;
            
            // Get the Monday of the selected week
            const weekDates = getWeekDates(weekValue);
            if (weekDates.length === 0) return;
            
            // Verify all dates are weekdays (Monday=1 to Friday=5)
            const allWeekdays = weekDates.every(dateStr => {
                const date = new Date(dateStr + 'T00:00:00');
                const dayOfWeek = date.getDay();
                // Monday = 1, Tuesday = 2, ..., Friday = 5
                return dayOfWeek >= 1 && dayOfWeek <= 5;
            });
            
            if (!allWeekdays) {
                // If weekend is included, adjust to the previous Monday-Friday week
                const monday = new Date(weekDates[0] + 'T00:00:00');
                const dayOfWeek = monday.getDay();
                
                // If Monday is not 1, adjust to the previous Monday
                if (dayOfWeek !== 1) {
                    const daysToMonday = dayOfWeek === 0 ? 6 : dayOfWeek - 1;
                    monday.setDate(monday.getDate() - daysToMonday);
                    
                    // Recalculate week value
                    const year = monday.getFullYear();
                    const week = getWeekNumber(monday);
                    const adjustedWeekValue = `${year}-W${week.toString().padStart(2, '0')}`;
                    
                    // Update the picker
                    const weekPicker = document.getElementById('scheduleWeekPicker');
                    if (weekPicker) {
                        weekPicker.value = adjustedWeekValue;
                        loadScheduleForWeek(adjustedWeekValue);
                    }
                    return;
                }
            }
            
            // If valid, load the schedule
            loadScheduleForWeek(weekValue);
        }
        
        // Get dates for a week (Monday to Friday only - no weekends)
        function getWeekDates(weekValue) {
            if (!weekValue) return [];
            
            // Parse week value (format: YYYY-Www)
            const parts = weekValue.split('-W');
            if (parts.length !== 2) return [];
            
            const year = parseInt(parts[0]);
            const week = parseInt(parts[1]);
            
            // Calculate the date of the Monday of the given week
            // Using ISO week date calculation
            const jan4 = new Date(year, 0, 4);
            const jan4Day = jan4.getDay() || 7; // Convert Sunday (0) to 7
            const daysToMonday = jan4Day - 1;
            const mondayOfWeek1 = new Date(jan4);
            mondayOfWeek1.setDate(jan4.getDate() - daysToMonday);
            
            // Calculate Monday of the requested week
            const mondayOfRequestedWeek = new Date(mondayOfWeek1);
            mondayOfRequestedWeek.setDate(mondayOfWeek1.getDate() + (week - 1) * 7);
            
            // Get Monday to Friday only (5 days, excluding Saturday and Sunday)
            const dates = [];
            for (let i = 0; i < 5; i++) {
                const date = new Date(mondayOfRequestedWeek);
                date.setDate(mondayOfRequestedWeek.getDate() + i);
                const dayOfWeek = date.getDay();
                // Only include weekdays (Monday=1 to Friday=5)
                if (dayOfWeek >= 1 && dayOfWeek <= 5) {
                    dates.push(date.toISOString().split('T')[0]);
                }
            }
            
            return dates;
        }
        
        // Get day name from date string
        function getDayName(dateString) {
            const date = new Date(dateString + 'T00:00:00');
            const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            return days[date.getDay()];
        }
        
        // Format date for display
        function formatDateShort(dateString) {
            const date = new Date(dateString + 'T00:00:00');
            const month = date.toLocaleDateString('en-US', { month: 'short' });
            const day = date.getDate();
            return `${month} ${day}`;
        }

        // Load schedule for a specific week
        async function loadScheduleForWeek(weekValue) {
            if (!weekValue) return;
            
            const weekDates = getWeekDates(weekValue);
            
            // Load data for all days in the week
            for (const date of weekDates) {
                // Initialize data for this date if it doesn't exist
                if (!scheduleData[date]) {
                    scheduleData[date] = {
                        blocks: [],
                        appointments: [],
                        availability: []
                    };
                }
                
                // Fetch appointments from database for this date
                // The API already filters by logged-in doctor
                try {
                    const response = await fetch(`doctor_get_schedule.php?date=${date}`);
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update schedule data with appointments from database
                        scheduleData[date].appointments = data.appointments || [];
                        scheduleData[date].blocks = data.blocks || [];
                    } else {
                        console.error('Error loading schedule:', data.message);
                        // Keep existing data if fetch fails
                    }
                } catch (error) {
                    console.error('Error fetching schedule:', error);
                    // Keep existing data if fetch fails
                }
            }
            
            // Render the schedule grid for the week
            renderScheduleGridForWeek(weekValue, weekDates);
            
            // Update summary for the week
            updateScheduleSummaryForWeek(weekValue, weekDates);
        }
        
        // Load schedule for a specific date (kept for backward compatibility if needed)
        async function loadScheduleForDate(date) {
            if (!date) return;
            
            // Initialize data for this date if it doesn't exist
            if (!scheduleData[date]) {
                scheduleData[date] = {
                    blocks: [],
                    appointments: [],
                    availability: []
                };
            }
            
            // Fetch appointments from database for this date
            try {
                const response = await fetch(`doctor_get_schedule.php?date=${date}`);
                const data = await response.json();
                
                if (data.success) {
                    // Update schedule data with appointments from database
                    scheduleData[date].appointments = data.appointments || [];
                    scheduleData[date].blocks = data.blocks || [];
                } else {
                    console.error('Error loading schedule:', data.message);
                    // Keep existing data if fetch fails
                }
            } catch (error) {
                console.error('Error fetching schedule:', error);
                // Keep existing data if fetch fails
            }
        }

        // Render the schedule grid for a week (5 columns: Monday to Friday)
        function renderScheduleGridForWeek(weekValue, weekDates) {
            const grid = document.getElementById('scheduleGrid');
            if (!grid) return;
            
            // Update time column dynamically to match schedule cells
            const timeColumn = document.querySelector('.time-column');
            if (timeColumn) {
                timeColumn.innerHTML = '';
                // Add header
                const header = document.createElement('div');
                header.className = 'time-slot-header';
                timeColumn.appendChild(header);
                // Add time slots
                timeSlots.forEach(time => {
                    const timeSlot = document.createElement('div');
                    timeSlot.className = 'time-slot';
                    timeSlot.textContent = time;
                    timeColumn.appendChild(timeSlot);
                });
            }
            
            const dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
            
            // Create 5 columns (Monday to Friday)
            grid.innerHTML = '';
            for (let col = 0; col < 5; col++) {
                const date = weekDates[col];
                const dayName = dayNames[col];
                const dateShort = formatDateShort(date);
                
                const column = document.createElement('div');
                column.className = 'schedule-column';
                
                // Add column header
                const header = document.createElement('div');
                header.className = 'schedule-column-header';
                header.innerHTML = `<div style="font-size: 0.9rem; line-height: 1.2;">${dayName}</div><div style="font-size: 0.75rem; color: #666; margin-top: 0.2rem; line-height: 1.2;">${dateShort}</div>`;
                column.appendChild(header);
                
                const data = scheduleData[date] || { blocks: [], appointments: [], availability: [] };
                
                // Data is already filtered by doctor_id from the API
                const blocks = data.blocks || [];
                const appointments = data.appointments || [];
                const availability = data.availability || [];
                
                timeSlots.forEach((time, index) => {
                    const cell = document.createElement('div');
                    cell.className = 'schedule-cell';
                    cell.setAttribute('data-time', time);
                    cell.setAttribute('data-column', col);
                    cell.setAttribute('data-date', date);
                    
                    // Check if this time slot is blocked
                    // Convert times to minutes for proper comparison
                    const timeMinutes = timeToMinutes(time);
                    const block = blocks.find(b => {
                        const startMinutes = timeToMinutes(b.startTime);
                        const endMinutes = timeToMinutes(b.endTime);
                        return startMinutes <= timeMinutes && endMinutes > timeMinutes;
                    });
                    
                    // Check if this time slot has an appointment
                    // Convert times to minutes for proper comparison
                    const appointment = appointments.find(a => {
                        const startMinutes = timeToMinutes(a.startTime);
                        const endMinutes = timeToMinutes(a.endTime);
                        return startMinutes <= timeMinutes && endMinutes > timeMinutes;
                    });
                    
                    // Check if this time slot has availability
                    // Convert times to minutes for proper comparison
                    const avail = availability.find(a => {
                        const startMinutes = timeToMinutes(a.startTime);
                        const endMinutes = timeToMinutes(a.endTime);
                        return startMinutes <= timeMinutes && endMinutes > timeMinutes;
                    });
                    
                    if (block) {
                        cell.className += ' status-blocked';
                        cell.textContent = 'Blocked';
                        cell.title = `Blocked: ${block.reason || 'No reason'}`;
                    } else if (appointment) {
                        cell.className += ' status-occupied';
                        cell.textContent = 'Occupied';
                        cell.title = `Occupied: ${appointment.patientName || 'Appointment'}`;
                        // Store appointment data for view modal
                        cell.setAttribute('data-appointment', JSON.stringify(appointment));
                        cell.onclick = () => viewOccupiedSlot(cell, appointment);
                    } else if (avail) {
                        cell.className += ' status-available';
                        cell.textContent = 'Available';
                        cell.title = `Available: ${avail.doctor || 'Doctor'}`;
                    }
                    
                    column.appendChild(cell);
                });
                
                grid.appendChild(column);
            }
        }
        
        // Render the schedule grid for a date (kept for backward compatibility if needed)
        function renderScheduleGrid(date) {
            const grid = document.getElementById('scheduleGrid');
            if (!grid) return;
            
            // Update time column dynamically to match schedule cells
            const timeColumn = document.querySelector('.time-column');
            if (timeColumn) {
                timeColumn.innerHTML = '';
                // Add header
                const header = document.createElement('div');
                header.className = 'time-slot-header';
                timeColumn.appendChild(header);
                // Add time slots
                timeSlots.forEach(time => {
                    const timeSlot = document.createElement('div');
                    timeSlot.className = 'time-slot';
                    timeSlot.textContent = time;
                    timeColumn.appendChild(timeSlot);
                });
            }
            
            const data = scheduleData[date] || { blocks: [], appointments: [], availability: [] };
            
            // Create 3 columns (representing 3 doctors/columns)
            grid.innerHTML = '';
            for (let col = 0; col < 3; col++) {
                const column = document.createElement('div');
                column.className = 'schedule-column';
                
                timeSlots.forEach((time, index) => {
                    const cell = document.createElement('div');
                    cell.className = 'schedule-cell';
                    cell.setAttribute('data-time', time);
                    cell.setAttribute('data-column', col);
                    cell.setAttribute('data-date', date);
                    
                    // Check if this time slot is blocked
                    // Convert times to minutes for proper comparison
                    const timeMinutes = timeToMinutes(time);
                    const block = data.blocks.find(b => {
                        const startMinutes = timeToMinutes(b.startTime);
                        const endMinutes = timeToMinutes(b.endTime);
                        return startMinutes <= timeMinutes && endMinutes > timeMinutes && b.column === col;
                    });
                    
                    // Check if this time slot has an appointment
                    const appointment = data.appointments.find(a => {
                        const startMinutes = timeToMinutes(a.startTime);
                        const endMinutes = timeToMinutes(a.endTime);
                        return startMinutes <= timeMinutes && endMinutes > timeMinutes && a.column === col;
                    });
                    
                    // Check if this time slot has availability
                    const availability = data.availability.find(a => {
                        const startMinutes = timeToMinutes(a.startTime);
                        const endMinutes = timeToMinutes(a.endTime);
                        return startMinutes <= timeMinutes && endMinutes > timeMinutes && a.column === col;
                    });
                    
                    if (block) {
                        cell.className += ' status-blocked';
                        cell.textContent = 'Blocked';
                        cell.title = `Blocked: ${block.reason}`;
                    } else if (appointment) {
                        cell.className += ' status-occupied';
                        cell.textContent = 'Occupied';
                        cell.title = `Occupied: ${appointment.patientName || 'Appointment'}`;
                    } else if (availability) {
                        cell.className += ' status-available';
                        cell.textContent = 'Available';
                        cell.title = `Available: ${availability.doctor || 'Doctor'}`;
                    }
                    column.appendChild(cell);
                });
                
                grid.appendChild(column);
            }
        }

        // Update schedule summary for week
        function updateScheduleSummaryForWeek(weekValue, weekDates) {
            if (!weekDates || weekDates.length === 0) return;
            
            // Format week for display
            const startDate = new Date(weekDates[0] + 'T00:00:00');
            const endDate = new Date(weekDates[4] + 'T00:00:00');
            const formattedWeek = `${startDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${endDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
            
            const summaryWeekElement = document.getElementById('summarySelectedWeek');
            if (summaryWeekElement) {
                summaryWeekElement.textContent = formattedWeek;
            }
            
            // Find next available time across the week (weekdays only - Monday to Friday, excluding Saturday and Sunday)
            let nextAvailable = '-';
            for (const date of weekDates) {
                // Skip weekends - only process weekdays (Monday to Friday)
                const dateObj = new Date(date + 'T00:00:00');
                const dayOfWeek = dateObj.getDay();
                // Skip if it's Saturday (6) or Sunday (0)
                if (dayOfWeek === 0 || dayOfWeek === 6) {
                    continue;
                }
                
                const data = scheduleData[date] || { blocks: [], appointments: [], availability: [] };
                for (let i = 0; i < timeSlots.length - 1; i++) {
                    const slotMinutes = timeToMinutes(timeSlots[i]);
                    const hasBlock = data.blocks.some(b => {
                        const startMinutes = timeToMinutes(b.startTime);
                        const endMinutes = timeToMinutes(b.endTime);
                        return startMinutes <= slotMinutes && endMinutes > slotMinutes;
                    });
                    const hasAppointment = data.appointments.some(a => {
                        const startMinutes = timeToMinutes(a.startTime);
                        const endMinutes = timeToMinutes(a.endTime);
                        return startMinutes <= slotMinutes && endMinutes > slotMinutes;
                    });
                    
                    if (!hasBlock && !hasAppointment) {
                        const dayName = getDayName(date).substring(0, 3);
                        nextAvailable = `${dayName} ${timeSlots[i]} - ${timeSlots[i + 1]}`;
                        break;
                    }
                }
                if (nextAvailable !== '-') break;
            }
            
            const summaryNextAvailable = document.getElementById('summaryNextAvailable');
            if (summaryNextAvailable) {
                summaryNextAvailable.textContent = nextAvailable;
            }
        }
        
        // Update schedule summary (kept for backward compatibility if needed)
        function updateScheduleSummary(date) {
            const data = scheduleData[date] || { blocks: [], appointments: [], availability: [] };
            
            // Format date for display
            const dateObj = new Date(date + 'T00:00:00');
            const formattedDate = dateObj.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            const summarySelectedDate = document.getElementById('summarySelectedDate');
            if (summarySelectedDate) summarySelectedDate.textContent = formattedDate;
            
            // Find next available time
            let nextAvailable = '-';
            for (let i = 0; i < timeSlots.length - 1; i++) {
                const slotMinutes = timeToMinutes(timeSlots[i]);
                const hasBlock = data.blocks.some(b => {
                    const startMinutes = timeToMinutes(b.startTime);
                    const endMinutes = timeToMinutes(b.endTime);
                    return startMinutes <= slotMinutes && endMinutes > slotMinutes;
                });
                const hasAppointment = data.appointments.some(a => {
                    const startMinutes = timeToMinutes(a.startTime);
                    const endMinutes = timeToMinutes(a.endTime);
                    return startMinutes <= slotMinutes && endMinutes > slotMinutes;
                });
                
                if (!hasBlock && !hasAppointment) {
                    nextAvailable = `${timeSlots[i]} - ${timeSlots[i + 1]}`;
                    break;
                }
            }
            
            const summaryNextAvailable = document.getElementById('summaryNextAvailable');
            if (summaryNextAvailable) summaryNextAvailable.textContent = nextAvailable;
            
            const summaryTotalPatients = document.getElementById('summaryTotalPatients');
            if (summaryTotalPatients) summaryTotalPatients.textContent = data.appointments.length;
            
            const summaryWalkIns = document.getElementById('summaryWalkIns');
            if (summaryWalkIns) summaryWalkIns.textContent = data.appointments.filter(a => a.isWalkIn).length;
        }

        // Update modal dates to match selected date
        function updateModalDates(date) {
            const blockStartDateInput = document.getElementById('blockStartDate');
            const blockEndDateInput = document.getElementById('blockEndDate');
            
            if (blockStartDateInput) blockStartDateInput.value = date;
            if (blockEndDateInput) blockEndDateInput.value = date;
        }

        function viewOccupiedSlot(element, appointment) {
            // Highlight the clicked cell
            document.querySelectorAll('.schedule-cell').forEach(cell => {
                cell.style.border = 'none';
            });
            element.style.border = '3px solid #4CAF50';
            
            // Get cell data
            const date = element.getAttribute('data-date');
            
            // Format date for display
            const dateObj = new Date(date + 'T00:00:00');
            const formattedDate = dateObj.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            // Show appointment information
            const patientName = appointment.patientName || 'Unknown Patient';
            alert(`Occupied Slot\n\nDate: ${formattedDate}\nPatient: ${patientName}\nTime: ${appointment.startTime} - ${appointment.endTime}`);
        }
        
        // Convert time from "H:MM AM/PM" to "HH:MM" format
        function convertTimeTo24Hour(time12) {
            if (!time12) return '';
            
            // Handle formats like "11:00 AM" or "1:00 PM"
            const match = time12.match(/(\d+):(\d+)\s*(AM|PM)/i);
            if (!match) return '';
            
            let hours = parseInt(match[1]);
            const minutes = match[2];
            const ampm = match[3].toUpperCase();
            
            if (ampm === 'PM' && hours !== 12) {
                hours += 12;
            } else if (ampm === 'AM' && hours === 12) {
                hours = 0;
            }
            
            return `${hours.toString().padStart(2, '0')}:${minutes}`;
        }

        // Save blocked time
        async function saveBlockTime(event) {
            event.preventDefault();
            
            const startDate = document.getElementById('blockStartDate').value;
            const endDate = document.getElementById('blockEndDate').value;
            const reason = document.getElementById('blockReasonSelect').value;
            const startTime = document.getElementById('blockStartTime').value;
            const endTime = document.getElementById('blockEndTime').value;
            
            if (!startDate || !endDate || !reason || !startTime || !endTime) {
                alert('Please fill in all required fields');
                return;
            }
            
            // Validate dates
            if (new Date(endDate) < new Date(startDate)) {
                alert('End date must be after or equal to start date');
                return;
            }
            
            // Validate time slots match patient appointment slots
            if (!isValidTimeSlot(startTime)) {
                alert('Start time must be one of the allowed appointment time slots');
                return;
            }
            
            if (!isValidTimeSlot(endTime)) {
                alert('End time must be one of the allowed appointment time slots');
                return;
            }
            
            // Validate end time is after start time
            const startIndex = timeSlots.indexOf(formatTimeForDisplay(startTime));
            const endIndex = timeSlots.indexOf(formatTimeForDisplay(endTime));
            if (endIndex <= startIndex) {
                alert('End time must be after start time');
                return;
            }
            
            try {
                // Save to database
                const formData = new FormData();
                formData.append('reason', reason);
                formData.append('start_date', startDate);
                formData.append('end_date', endDate);
                formData.append('start_time', startTime);
                formData.append('end_time', endTime);
                
                const response = await fetch('doctor_save_blocked_time.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showToast('Time blocked successfully!');
                    closeScheduleModal('blockTime');
                    
                    // Refresh the week view
                    const weekPicker = document.getElementById('scheduleWeekPicker');
                    if (weekPicker && weekPicker.value) {
                        loadScheduleForWeek(weekPicker.value);
                    } else {
                        // If no week picker, calculate current week
                        const today = new Date();
                        const dayOfWeek = today.getDay();
                        const diff = today.getDate() - dayOfWeek + (dayOfWeek === 0 ? -6 : 1);
                        const monday = new Date(today.setDate(diff));
                        const year = monday.getFullYear();
                        const week = getWeekNumber(monday);
                        const weekValue = `${year}-W${week.toString().padStart(2, '0')}`;
                        loadScheduleForWeek(weekValue);
                    }
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error saving blocked time. Please try again.');
            }
        }

        // Format time from HH:MM to "H:MM AM/PM"
        function formatTimeForDisplay(time24) {
            const [hours, minutes] = time24.split(':');
            const hour = parseInt(hours);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const hour12 = hour % 12 || 12;
            return `${hour12}:${minutes} ${ampm}`;
        }

        // Convert 12-hour format time string (e.g., "1:00 PM") to minutes since midnight for comparison
        function timeToMinutes(time12) {
            if (!time12) return 0;
            // Handle formats like "1:00 PM" or "10:00 AM"
            const match = time12.match(/(\d+):(\d+)\s*(AM|PM)/i);
            if (!match) return 0;
            
            let hours = parseInt(match[1]);
            const minutes = parseInt(match[2]);
            const ampm = match[3].toUpperCase();
            
            if (ampm === 'PM' && hours !== 12) {
                hours += 12;
            } else if (ampm === 'AM' && hours === 12) {
                hours = 0;
            }
            
            return hours * 60 + minutes;
        }

        // Modal functions for schedule
        function openScheduleModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
                document.body.classList.add('modal-open');
                
                // If opening schedule modals, sync the date with selected date
                if (modalId === 'blockTime') {
                    const datePicker = document.getElementById('scheduleDatePicker');
                    if (datePicker && datePicker.value) {
                        updateModalDates(datePicker.value);
                    }
                }
            }
        }

        function closeScheduleModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                document.body.classList.remove('modal-open');
            }
        }

        // Toast Notification
        function showToast(message) {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            
            if (toast && toastMessage) {
                toastMessage.textContent = message;
                toast.style.display = 'flex';
                toast.classList.add('active');

                setTimeout(() => {
                    toast.classList.remove('active');
                    setTimeout(() => {
                        toast.style.display = 'none';
                    }, 300);
                }, 3000);
            }
        }

        // Close modal when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                    document.body.classList.remove('modal-open');
                }
            });
        });
    </script>
</body>
</html>

