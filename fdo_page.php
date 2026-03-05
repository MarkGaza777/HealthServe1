<?php
session_start();
require 'db.php';
require_once 'admin_helpers_simple.php';
require_once 'auto_audit_log.php'; // Auto-log page access

// Check if user is logged in and is an FDO
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'fdo') {
    header('Location: Login.php');
    exit;
}

// Check maintenance mode - redirect to maintenance page
if (isMaintenanceMode()) {
    header('Location: maintenance_mode.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HealthServe - Front Desk Officer Dashboard</title>
    <!-- Font Awesome for dashboard and sidebar icons (same set as admin) -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            height: 100vh;
            overflow: hidden;
            /* Match global dashboards background */
            background: linear-gradient(135deg, #66BB6A 0%, #4CAF50 100%);
        }

        /* Sidebar */
        .sidebar {
            width: 310px;
            /* Match admin/pharmacist sidebar gradient */
            background: linear-gradient(180deg, #4CAF50 0%, #2E7D32 100%);
            color: white;
            display: flex;
            flex-direction: column;
            box-shadow: 4px 0 10px rgba(0,0,0,0.1);
        }

        .user-profile {
            padding: 2rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: #4a7c59;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
        }

        .user-info h3 {
            font-size: 1rem;
            margin-bottom: 0.2rem;
        }

        .user-status {
            color: #a8f5a8;
            font-size: 0.9rem;
        }

        .nav-section {
            padding: 1.5rem 0;
        }

        .nav-section-title {
            padding: 0 1.5rem;
            font-size: 0.9rem;
            opacity: 0.8;
            margin-bottom: 0.5rem;
        }

        .nav-item {
            padding: 0.9rem 1.8rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
            color: #E8F5E8;
            font-weight: 500;
        }

        .nav-item:hover {
            background: rgba(255,255,255,0.08);
        }

        .nav-item.active {
            background: rgba(255,255,255,0.15);
            border-left-color: #A5D6A7;
            color: #FFFFFF;
        }

        .nav-icon {
            font-size: 1.5rem;
            width: 30px;
            text-align: center;
        }

        .logout {
            margin-top: auto;
            padding: 1.5rem 1.8rem;
        }

        /* Logout styled like text link (no button border) */
        .logout-btn {
            width: 100%;
            padding: 0;
            background: none;
            border: none;
            border-radius: 0;
            color: #FFCDD2;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            justify-content: flex-start;
            transition: color 0.2s ease;
        }

        .logout-btn:hover {
            color: #FFAB91;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Header styled similar to doctor/admin portals (white bar, no green line) */
        .header {
            background: #ffffff;
            padding: 1.2rem 2.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            border-bottom: none;
        }

        .header-logo {
            width: 40px;
            height: 40px;
            background: transparent;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            overflow: hidden;
        }

        .header-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .header-title {
            font-size: 1.3rem;
            color: #2E7D32;
            font-weight: 600;
        }

        .content-area {
            flex: 1;
            overflow-y: auto;
            /* Match admin/pharmacist content background */
            background: rgba(255,255,255,0.95);
            padding: 2rem;
        }

        .page-header {
            margin-bottom: 1rem;
        }

        .page-title {
            font-size: 2rem;
            color: #2d5f3d;
            margin-bottom: 0.3rem;
        }

        .breadcrumb {
            color: #888;
            font-size: 0.95rem;
        }

        /* Dashboard Cards - aligned with Admin dashboard style */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #ffffff;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #ffffff;
        }

        .stat-icon.appointments {
            background: linear-gradient(135deg, #FF7043, #F4511E);
        }

        .stat-icon.walkins {
            background: linear-gradient(135deg, #42A5F5, #1E88E5);
        }

        .stat-icon.announcements {
            background: linear-gradient(135deg, #AB47BC, #8E24AA);
        }

        /* Icons for patient records stats (match system palette) */
        .stat-icon.patients {
            background: linear-gradient(135deg, #42A5F5, #1E88E5);
        }

        .stat-icon.staff {
            background: linear-gradient(135deg, #26A69A, #00897B);
        }

        .stat-details h3 {
            font-size: 32px;
            font-weight: 700;
            color: #2E7D32;
            margin-bottom: 5px;
        }

        .stat-details p {
            color: #666666;
            font-size: 14px;
            font-weight: 500;
        }

        /* Announcements */
        /* Notification badge on sidebar/top */
        .nav-item .notification-badge {
            margin-left: auto;
            background: #e53935;
            color: white;
            font-size: 11px;
            font-weight: 700;
            min-width: 18px;
            height: 18px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
        }
        .nav-item .notification-badge.hidden { display: none; }
        .notification-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 12px;
            background: #f5f5f5;
            cursor: pointer;
            transition: background 0.2s;
        }
        .notification-item:hover { background: #e8f5e9; }
        .notification-item.unread { background: #e3f2fd; }
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
        }

        .announcements-preview {
            margin-top: 2rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .section-title {
            font-size: 1.8rem;
            color: #2d5f3d;
        }

        /* Two-column grid and content cards (match Doctor's dashboard) */
        .two-column-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 30px;
        }
        @media (max-width: 900px) {
            .two-column-grid { grid-template-columns: 1fr; }
        }
        .content-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table th {
            text-align: left;
            padding: 12px 10px;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            border-bottom: 1px solid #e5e7eb;
        }
        .data-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4CAF50, #388E3C);
            color: white;
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(76, 175, 80, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #388E3C, #2E7D32);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.4);
        }

        .announcement-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
        }

        .announcement-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 2.5rem;
        }

        .announcement-card h3 {
            font-size: 1.4rem;
            color: #2d5f3d;
            margin-bottom: 1rem;
        }

        .announcement-card p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 0.5rem;
        }

        /* Tables */
        .table-container {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .filter-tabs {
            display: flex;
            gap: 2rem;
        }

        .filter-tab {
            padding: 0.5rem 1rem;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            color: #666;
            border-bottom: 2px solid transparent;
            transition: all 0.3s;
        }

        .filter-tab.active {
            color: #2d5f3d;
            border-bottom-color: #5a8d66;
            font-weight: 600;
        }

        .dropdown {
            padding: 0.6rem 1rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: white;
            cursor: pointer;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 1rem;
            color: #2d5f3d;
            font-weight: 600;
            border-bottom: 2px solid #e0e0e0;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
        }

        .status-badge {
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-block;
        }

        /* Match status badge colors with global dashboards - orange pero mabasa (good contrast) */
        .status-badge.status-pending {
            background: #FFE0B2;
            color: #BF360C;
        }

        .status-approved,
        .status-active {
            background: #E8F5E8;
            color: #2E7D32;
        }

        .status-completed {
            background: #E3F2FD;
            color: #1565C0;
        }

        .status-inactive {
            background: #FFF3E0;
            color: #F57C00;
        }

        .action-link {
            color: #5a8d66;
            text-decoration: none;
            font-weight: 600;
        }

        .action-link:hover {
            text-decoration: underline;
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

        .action-btn.btn-screening {
            background: #fffde7;
            color: #f57f17;
            border: 1px solid rgba(245, 127, 23, 0.25);
        }

        .action-btn.btn-screening:hover {
            background: #fff9c4;
            color: #e65100;
        }

        .close:hover {
            background: #E0E0E0 !important;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .initial-screening-success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .initial-screening-success-modal {
            background: white;
            border-radius: 12px;
            max-width: 420px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 2.5rem;
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }

        .modal-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .modal-title {
            font-size: 1.8rem;
            color: #2d5f3d;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 600;
        }

        .form-input {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
            background: #f9f9f9;
        }

        .form-input:focus {
            outline: none;
            border-color: #5a8d66;
            background: white;
        }

        /* Initial Screening: inline validation */
        .screening-field-error {
            display: none;
            font-size: 0.85rem;
            color: #c62828;
            margin-top: 0.35rem;
        }
        .screening-field-error.is-visible {
            display: block;
        }
        .form-input.input-invalid {
            border-color: #c62828;
            background: #ffebee;
        }
        .form-input.input-invalid:focus {
            border-color: #b71c1c;
        }
        /* Callout inside text box: warning appears inside the same box as the input */
        .screening-input-callout-box {
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
        }
        .screening-input-callout-box:focus-within {
            border-color: #5a8d66;
            box-shadow: 0 0 0 2px rgba(90, 141, 102, 0.2);
        }
        .screening-input-callout-box .form-input {
            border: none;
            border-radius: 0;
            box-shadow: none;
        }
        .screening-input-callout-box .form-input:focus {
            outline: none;
        }
        .screening-callout-inside {
            display: none;
            padding: 0.3rem 0.5rem 0.35rem 0.6rem;
            font-size: 0.75rem;
            font-weight: 500;
            align-items: center;
            gap: 0.35rem;
            line-height: 1.25;
            border-top: 1px solid;
        }
        .screening-callout-inside.is-visible { display: flex; }
        .screening-callout-inside.alert-emergency,
        .screening-callout-inside.alert-critical,
        .screening-callout-inside.temperature-emergency {
            background: #ffebee;
            border-top-color: #c62828;
            color: #b71c1c;
        }
        .screening-callout-inside.alert-warning {
            background: #fff8e1;
            border-top-color: #f9a825;
            color: #f57f17;
        }
        /* Global emergency modal popup (red) */
        .screening-emergency-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10002;
        }
        .screening-emergency-overlay.is-visible { display: flex; }
        .screening-emergency-modal {
            background: #fff;
            border: 3px solid #c62828;
            border-radius: 12px;
            max-width: 420px;
            width: 90%;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .screening-emergency-modal .emergency-header {
            background: #c62828;
            color: #fff;
            padding: 16px 20px;
            font-weight: 700;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .screening-emergency-modal .emergency-body {
            padding: 20px;
            color: #333;
            line-height: 1.5;
        }
        .screening-emergency-modal .emergency-actions {
            padding: 0 20px 20px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .screening-emergency-modal .btn-emergency-confirm {
            background: #c62828;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        .screening-emergency-modal .btn-emergency-confirm:hover { background: #b71c1c; }
        .screening-emergency-modal .btn-emergency-cancel {
            background: #e0e0e0;
            color: #333;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        #fdo_screening_submit_btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .screening-input-with-unit {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .screening-input-with-unit .form-input {
            flex: 1;
            min-width: 0;
        }
        .screening-unit-inline {
            flex-shrink: 0;
            padding: 0.5rem 0.5rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.9rem;
            background: #f9f9f9;
            color: #333;
        }

        textarea.form-input {
            min-height: 120px;
            resize: vertical;
        }

        .char-counter {
            text-align: right;
            font-size: 0.85rem;
            color: #888;
            margin-top: 0.3rem;
        }

        .file-upload {
            border: 2px dashed #ddd;
            padding: 1.5rem;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .file-upload:hover {
            border-color: #5a8d66;
            background: #f9f9f9;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }

        .btn-secondary {
            background: #e0e0e0;
            color: #333;
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-secondary:hover {
            background: #d0d0d0;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        /* Toast Notification */
        .toast {
            position: fixed;
            top: 2rem;
            right: 2rem;
            background: #d4edda;
            color: #155724;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            z-index: 3000;
            display: none;
            align-items: center;
            gap: 0.5rem;
        }

        .toast.active {
            display: flex;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .page-content {
            display: none;
        }

        .page-content.active {
            display: block;
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

        /* Schedule grid time-slot cells (not the status badge) */
        .schedule-grid .status-pending {
            background: #FFB74D;
            color: #FFF;
        }

        .status-blocked {
            background: #78909C;
            opacity: 0.8;
            font-size: 0.85rem;
            line-height: 1.2;
            padding: 0.3rem 0.5rem;
            text-align: center;
            word-wrap: break-word;
        }
        
        .status-blocked[data-block] {
            cursor: not-allowed !important;
            pointer-events: none !important;
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
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

        .announcement-modal-close-x {
            position: absolute;
            top: 20px;
            right: 20px;
            background: none;
            border: none;
            font-size: 28px;
            color: #666;
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
            z-index: 10;
        }

        .announcement-modal-close-x:hover {
            background: #f0f0f0;
            color: #333;
        }

        @media (max-width: 768px) {
            .announcement-modal-box {
                padding: 40px 30px;
            }
            
            .announcement-modal-title {
                font-size: 24px;
            }
        }
    </style>
    <script src="custom_modal.js"></script>
    <script src="custom_notification.js"></script>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="user-profile">
            <div class="user-avatar">👨‍💼</div>
            <div class="user-info">
                <h3>Front Desk Officer</h3>
                <div class="user-status">Online</div>
            </div>
        </div>

        <nav>
            <div class="nav-section">
                <div class="nav-section-title">General</div>
                <div class="nav-item active" onclick="showPage('dashboard')">
                    <span class="nav-icon">📊</span>
                    <span>Dashboard</span>
                </div>
                <div class="nav-item" onclick="showPage('appointments')">
                    <span class="nav-icon">📅</span>
                    <span>Appointments</span>
                </div>
                <div class="nav-item" onclick="showPage('validation')">
                    <span class="nav-icon"><i class="fas fa-clipboard-check"></i></span>
                    <span>Appointment Validation</span>
                </div>
                <div class="nav-item" onclick="showPage('notifications')">
                    <span class="nav-icon"><i class="fas fa-bell"></i></span>
                    <span>Notifications</span>
                    <span id="fdoNotificationBadge" class="notification-badge hidden">0</span>
                </div>
                <div class="nav-item" onclick="showPage('announcements');">
                    <span class="nav-icon">📢</span>
                    <span>Announcements</span>
                </div>
                <div class="nav-item" onclick="showPage('schedule')">
                    <span class="nav-icon">📆</span>
                    <span>Schedule</span>
                </div>
            </div>
        </nav>

        <div class="logout">
            <button class="logout-btn" onclick="handleLogout()">
                <span>🚪</span>
                <span>Logout</span>
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <header class="header">
            <div class="header-logo">
                <img src="assets/payatas logo.png" alt="Payatas B Logo">
            </div>
            <div class="header-title">HealthServe - Payatas B</div>
        </header>

        <div class="content-area">
            <!-- Dashboard Page -->
            <div id="dashboard" class="page-content active">
                <div class="page-header">
                    <h1 class="page-title">Dashboard</h1>
                </div>

                <!-- Dashboard summary cards (mirroring Admin dashboard style & icons) -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon appointments">
                            <i class="fas fa-calendar-plus"></i>
                        </div>
                        <div class="stat-details">
                            <h3 id="pendingAppointmentsCount">0</h3>
                            <p>Pending Appointments</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon walkins">
                            <i class="fas fa-walking"></i>
                        </div>
                        <div class="stat-details">
                            <h3 id="todayAppointmentsCount">0</h3>
                            <p>Today's Appointment</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon announcements">
                            <i class="fas fa-bullhorn"></i>
                        </div>
                        <div class="stat-details">
                            <h3 id="activeAnnouncementsCount">0</h3>
                            <p>Active Announcements</p>
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
                            <tbody id="dashboardUpcomingAppointmentsBody">
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

                <div class="announcements-preview">
                    <div class="section-header">
                        <h2 class="section-title">Announcements Preview</h2>
                        <button class="btn-primary" onclick="showPage('announcements')">View All</button>
                    </div>
                    <div class="announcement-cards" id="dashboardAnnouncementsPreview">
                        <div style="text-align: center; padding: 30px; color: #666;">Loading announcements...</div>
                    </div>
                </div>
            </div>

            <!-- Notifications Page -->
            <div id="notifications" class="page-content">
                <div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <h1 class="page-title">Notifications</h1>
                    <button type="button" class="btn-primary" id="markAllNotificationsReadBtn" onclick="markAllFdoNotificationsRead()" style="padding: 8px 16px; font-size: 14px;">Mark all as read</button>
                </div>
                <div class="breadcrumb">Appointment-related notifications (newest first)</div>
                <div id="notificationsPageContainer" style="margin-top: 1.5rem;">
                    <div style="text-align: center; padding: 20px; color: #666;">Loading notifications...</div>
                </div>
            </div>

            <!-- Appointment Validation Page (FDO) -->
            <div id="validation" class="page-content">
                <div class="page-header">
                    <h1 class="page-title">Appointment Validation</h1>
                    <div class="breadcrumb">Validate appointments by code at the front desk</div>
                </div>
                <div class="content-section" style="max-width: 640px;">
                    <label for="validationCodeInput" style="display: block; font-weight: 600; color: #333; margin-bottom: 0.5rem;">Appointment Code</label>
                    <div style="display: flex; gap: 0.75rem; margin-bottom: 1.5rem;">
                        <input type="text" id="validationCodeInput" placeholder="e.g. HS-APPT-XXXXXX" style="flex: 1; padding: 0.6rem 0.75rem; border: 1px solid #ccc; border-radius: 6px; font-size: 1rem;" onkeypress="if(event.key==='Enter'){ event.preventDefault(); searchAppointmentByCode(); }">
                        <button type="button" class="btn-primary" id="validationSearchBtn" onclick="searchAppointmentByCode()">Search</button>
                    </div>
                    <div id="validationMessage" style="display: none; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;"></div>
                    <div id="validationResult" style="display: none;">
                        <div style="background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 8px; padding: 1.25rem; margin-bottom: 1rem;">
                            <div style="display: grid; gap: 0.75rem;">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="font-weight: 600; color: #666; min-width: 140px;">Appointment Code</span>
                                    <span id="vCode" style="font-family: monospace; font-weight: 600; color: #2E7D32;"></span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="font-weight: 600; color: #666; min-width: 140px;">Patient</span>
                                    <span id="vPatient"></span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="font-weight: 600; color: #666; min-width: 140px;">Date &amp; Time</span>
                                    <span id="vDateTime"></span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="font-weight: 600; color: #666; min-width: 140px;">Doctor</span>
                                    <span id="vDoctor"></span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="font-weight: 600; color: #666; min-width: 140px;">Status</span>
                                    <span id="vStatus"></span>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn-primary" id="validateAppointmentBtn" style="display: none;" onclick="validateAppointment()">Validate Appointment</button>
                    </div>
                </div>
            </div>

            <!-- Appointments Page -->
            <div id="appointments" class="page-content">
                <div class="page-header">
                    <h1 class="page-title">Appointments Management</h1>
                    <div class="breadcrumb">Appointments</div>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <div class="filter-tabs">
                            <select class="dropdown" id="dateFilterDropdown" onchange="filterByDate(this.value)">
                                <option value="today">Today</option>
                                <option value="week">This Week</option>
                                <option value="month">This Month</option>
                                <option value="all" selected>All Dates</option>
                            </select>
                            <select class="dropdown" id="statusFilterDropdown" onchange="filterByStatus(this.value)">
<option value="all" selected>All Status</option>
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="validated">Validated</option>
                                    <option value="completed">Completed</option>
                                    <option value="declined">Declined</option>
                                    <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Assigned Staff</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="fdoAppointmentsTableBody">
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 30px;">Loading appointments...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Reschedule Requests Section -->
                <div class="content-section" style="margin-top: 30px;">
                    <div class="section-header">
                        <h3 style="color: #2E7D32; margin: 0;">Follow-Up Reschedule Requests</h3>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Original Appointment</th>
                                <th>Requested Follow-Up Date</th>
                                <th>Requested Follow-Up Time</th>
                                <th>Reason</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="fdoRescheduleRequestsBody">
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 30px;">Loading reschedule requests...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Announcements Page -->
            <div id="announcements" class="page-content">
                <div class="page-header">
                    <h1 class="page-title">Announcements Management</h1>
                    <div class="breadcrumb">Dashboard > Announcements</div>
                </div>

                <!-- Pending Approvals Section -->
                <div class="table-container" style="margin-bottom: 2rem;">
                    <div class="table-header">
                        <h3 style="color:#2E7D32;margin:0;">Pending Approvals</h3>
                        <button class="btn-primary" onclick="openModal('addAnnouncement')">+ Create Announcement</button>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Posted By</th>
                                <th>Category</th>
                                <th>Date Submitted</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="pendingAnnouncementsTableBody">
                            <tr><td colspan="5" style="text-align: center; padding: 30px;">Loading pending announcements...</td></tr>
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
            </div>

            <!-- Schedule Page -->
            <div id="schedule" class="page-content">
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
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <label class="date-selector-label">
                                        <i class="fas fa-user-md"></i> Doctor:
                                    </label>
                                    <select id="scheduleDoctorSelect" class="form-input" style="min-width: 200px;" onchange="loadScheduleForWeek(document.getElementById('scheduleWeekPicker').value)">
                                        <option value="">All Doctors</option>
                                        <!-- Doctors will be loaded dynamically -->
                                    </select>
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
                    <button class="btn-secondary" onclick="openModal('editSchedule')">Edit Schedule</button>
                </div>
            </div>

        </div>
    </div>

    <!-- Edit Announcement Modal -->
    <div id="editAnnouncement" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Edit Announcement</h2>
            </div>

            <form onsubmit="saveAnnouncement(event)">
                <div class="form-group">
                    <label class="form-label">Announcement Title</label>
                    <input type="text" class="form-input" value="Prenatal Psychology Training" maxlength="100" oninput="updateCharCount(this, 'titleCount')">
                    <div class="char-counter"><span id="titleCount">31</span>/100 characters</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Announcement Description / Content</label>
                    <textarea class="form-input" placeholder="Enter the full details of the announcement...">Join us for an informative session on prenatal psychology and preparing for parenthood. Learn about maternal mental health, bonding with your baby, and managing stress during pregnancy.</textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Target Audience</label>
                    <select class="form-input">
                        <option>All Patients</option>
                        <option>Senior Citizens</option>
                        <option>PWD</option>
                        <option>Children</option>
                        <option selected>Pregnant Women</option>
                        <option>Teenagers</option>
                        <option>Adults</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Announcement Status</label>
                    <select class="form-input">
                        <option selected>Active</option>
                        <option>Inactive</option>
                        <option>Scheduled</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Display Start Date & Time</label>
                    <input type="datetime-local" class="form-input" value="2025-11-24T14:00">
                </div>

                <div class="form-group">
                    <label class="form-label">End Date & Time (Optional)</label>
                    <input type="datetime-local" class="form-input" value="2025-11-24T16:00">
                </div>

                <div class="form-group">
                    <label class="form-label">Upload Image Banner</label>
                    <div class="file-upload" onclick="document.getElementById('imageUpload').click()">
                        <input type="file" id="imageUpload" accept="image/*" style="display: none;">
                        <p>📷 Click to upload image or drag and drop</p>
                        <p style="font-size: 0.85rem; color: #888; margin-top: 0.5rem;">PNG, JPG, GIF up to 5MB</p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Upload Attachment File</label>
                    <div class="file-upload" onclick="document.getElementById('fileUpload').click()">
                        <input type="file" id="fileUpload" accept=".pdf,.doc,.docx" style="display: none;">
                        <p>📎 Click to upload file or drag and drop</p>
                        <p style="font-size: 0.85rem; color: #888; margin-top: 0.5rem;">PDF, DOC, DOCX up to 10MB</p>
                    </div>
                </div>

                <div class="form-group">
                    <button type="button" class="btn-secondary" style="width: 100%;" onclick="previewAnnouncement('edit')">👁️ Preview Announcement</button>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('editAnnouncement')">Cancel</button>
                    <button type="button" class="btn-danger" onclick="deleteAnnouncement()">Delete Announcement</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Announcement Modal -->
    <div id="addAnnouncement" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Add New Announcement</h2>
            </div>

            <form id="fdoAnnouncementForm" onsubmit="submitFDOAnnouncement(event)" enctype="multipart/form-data">
                <div class="form-group">
                    <label class="form-label">Announcement Title *</label>
                    <input type="text" name="title" class="form-input" placeholder="Enter announcement title..." maxlength="100" required oninput="updateCharCount(this, 'newTitleCount')">
                    <div class="char-counter"><span id="newTitleCount">0</span>/100 characters</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Announcement Description / Content *</label>
                    <textarea name="content" class="form-input" placeholder="Enter the full details of the announcement..." required></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-input">
                        <option value="General" selected>General</option>
                        <option value="Event">Event</option>
                        <option value="Health Tip">Health Tip</option>
                        <option value="Training">Training</option>
                        <option value="Program">Program</option>
                        <option value="Reminder">Reminder</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Target Audience</label>
                    <select name="audience" class="form-input">
                        <option value="all" selected>All Patients</option>
                        <option value="patients">Patients Only</option>
                        <option value="doctors">Doctors Only</option>
                        <option value="pharmacists">Pharmacists Only</option>
                        <option value="fdo">FDO Only</option>
                        <option value="admin">Admin Only</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Display Start Date & Time *</label>
                    <input type="datetime-local" name="start_date" class="form-input" required>
                </div>

                <div class="form-group">
                    <label class="form-label">End Date & Time *</label>
                    <input type="datetime-local" name="end_date" class="form-input" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Attach Image *</label>
                    <div class="file-upload" onclick="document.getElementById('newImageUpload').click()">
                        <input type="file" id="newImageUpload" name="image" accept="image/*" required style="display: none;" onchange="previewFDOImage(this)">
                        <p>📷 Click to upload image or drag and drop</p>
                        <p style="font-size: 0.85rem; color: #888; margin-top: 0.5rem;">PNG, JPG, GIF up to 5MB</p>
                    </div>
                    <div id="fdoImagePreview" style="margin-top:10px; display:none;">
                        <img id="fdoPreviewImg" src="" alt="Preview" style="max-width:100%; max-height:200px; border-radius:8px; border:1px solid #ddd;">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Schedule</label>
                    <select name="schedule" id="fdoAnnouncementSchedule" class="form-input">
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
                    <input type="text" id="fdoAnnouncementScheduleCustom" name="schedule_custom" placeholder="Enter custom schedule (e.g., Every Monday & Friday | 2 PM - 4 PM)" class="form-input" style="margin-top:10px; display:none;">
                </div>

                <div class="form-group">
                    <label class="form-label">Upload Attachment File</label>
                    <div class="file-upload" onclick="document.getElementById('newFileUpload').click()">
                        <input type="file" id="newFileUpload" accept=".pdf,.doc,.docx" style="display: none;">
                        <p>📎 Click to upload file or drag and drop</p>
                        <p style="font-size: 0.85rem; color: #888; margin-top: 0.5rem;">PDF, DOC, DOCX up to 10MB</p>
                    </div>
                </div>

                <div class="form-group">
                    <button type="button" class="btn-secondary" style="width: 100%;" onclick="previewAnnouncement('add')">👁️ Preview Announcement</button>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('addAnnouncement')">Cancel</button>
                    <button type="submit" class="btn-primary">Create Announcement</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Preview Announcement Modal -->
    <div id="previewAnnouncementModal" class="announcement-modal-overlay" style="display: none;">
        <div class="announcement-modal-box">
            <button class="announcement-modal-close-x" onclick="closePreviewAnnouncementModal()" title="Close">×</button>
            <div id="previewAnnouncementModalBody"></div>
        </div>
    </div>

    <!-- New Appointment Modal -->
    <div id="newAppointment" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Create New Appointment</h2>
            </div>

            <form id="fdoAppointmentForm" onsubmit="submitFDOAppointment(event)">
                <div class="form-group">
                    <label class="form-label">Select Patient *</label>
                    <div style="position: relative;">
                        <input type="text" id="patientSearchInput" class="form-input" placeholder="Search for patient..." autocomplete="off" oninput="searchPatients(this.value)">
                        <input type="hidden" id="selectedPatientId" name="patient_id">
                        <input type="hidden" id="selectedPatientType" name="patient_type">
                        <div id="patientDropdown" style="display:none; position:absolute; top:100%; left:0; right:0; background:white; border:1px solid #ddd; border-radius:8px; max-height:300px; overflow-y:auto; z-index:1000; box-shadow:0 4px 12px rgba(0,0,0,0.15); margin-top:4px;">
                            <!-- Patient list will be populated here -->
                        </div>
                    </div>
                    <div id="selectedPatientInfo" style="margin-top:10px; padding:10px; background:#f5f5f5; border-radius:8px; display:none;">
                        <strong>Selected:</strong> <span id="selectedPatientName"></span>
                        <button type="button" onclick="clearPatientSelection()" style="margin-left:10px; padding:4px 8px; background:#ff4444; color:white; border:none; border-radius:4px; cursor:pointer;">Clear</button>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">First Name *</label>
                        <input type="text" id="apptFirstName" name="first_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Middle Name</label>
                        <input type="text" id="apptMiddleName" name="middle_name" class="form-input">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Last Name *</label>
                        <input type="text" id="apptLastName" name="last_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" id="apptPhone" name="phone" class="form-input" maxlength="11">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Appointment Date & Time *</label>
                        <input type="datetime-local" id="apptDateTime" name="start_datetime" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Duration (minutes) *</label>
                        <select id="apptDuration" name="duration_minutes" class="form-input" required>
                            <option value="20">20 minutes</option>
                            <option value="30" selected>30 minutes</option>
                            <option value="45">45 minutes</option>
                            <option value="60">60 minutes</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Chief Complaint / Reason *</label>
                    <select id="apptReason" name="reason" class="form-input" required>
                        <option value="">Select reason...</option>
                        <option value="General Check-up">General Check-up</option>
                        <option value="Follow-up Check-up">Follow-up Check-up</option>
                        <option value="Medical Certificate Request">Medical Certificate Request</option>
                        <option value="Prenatal Care">Prenatal Care</option>
                        <option value="Child Checkup / Pediatrics">Child Checkup / Pediatrics</option>
                        <option value="Medication Refill">Medication Refill</option>
                        <option value="Vital Signs / BP Monitoring">Vital Signs / BP Monitoring</option>
                        <option value="others">Others (Please Specify)</option>
                    </select>
                </div>

                <div class="form-group" id="otherReasonContainer" style="display:none;">
                    <label class="form-label">Specify Other Reason</label>
                    <input type="text" id="apptOtherReason" name="other_reason" class="form-input" placeholder="Enter reason...">
                </div>

                <div class="form-group">
                    <label class="form-label">Assign Doctor (Optional)</label>
                    <select id="apptDoctor" name="doctor_id" class="form-input">
                        <option value="">Unassigned</option>
                        <!-- Doctors will be loaded dynamically -->
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes (Optional)</label>
                    <textarea id="apptNotes" name="notes" class="form-input" rows="3" placeholder="Additional notes..."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('newAppointment')">Cancel</button>
                    <button type="submit" class="btn-primary">Create Appointment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Schedule Modal -->
    <div id="editSchedule" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Edit Schedule</h2>
            </div>
            <form onsubmit="saveScheduleEdit(event)">
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <div style="position: relative;">
                        <input type="date" id="editScheduleDate" class="form-input" required>
                        <i class="fas fa-calendar" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #999;"></i>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start Time</label>
                        <div style="position: relative;">
                            <select id="editScheduleStartTime" class="form-input" required>
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
                            <select id="editScheduleEndTime" class="form-input" required>
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
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select id="editScheduleStatus" class="form-input">
                        <option value="Available">Available</option>
                        <option value="Occupied">Occupied</option>
                        <option value="Pending">Pending</option>
                        <option value="Blocked">Blocked</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Patient Name (if occupied)</label>
                    <input type="text" id="editSchedulePatientName" class="form-input" placeholder="Enter patient name...">
                </div>
                <input type="hidden" id="editScheduleColumn" value="0">
                <input type="hidden" id="editScheduleOriginalTime" value="">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('editSchedule')">Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Occupied Slot Modal -->
    <div id="viewOccupiedSlotModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #E0E0E0; padding-bottom: 15px;">
                <h2 class="modal-title" style="margin: 0;">Appointment Details</h2>
                <button class="close" onclick="closeModal('viewOccupiedSlotModal')" style="font-size: 28px; cursor: pointer; color: #999; background: #F3F4F6; border: none; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.3s ease;">&times;</button>
            </div>
            <div class="form-group">
                <label class="form-label"><strong>Date:</strong></label>
                <p id="viewSlotDate" style="margin: 0.5rem 0; padding: 0.8rem; background: #f8f9fa; border-radius: 8px; color: #333;">-</p>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label"><strong>Start Time:</strong></label>
                    <p id="viewSlotStartTime" style="margin: 0.5rem 0; padding: 0.8rem; background: #f8f9fa; border-radius: 8px; color: #333;">-</p>
                </div>
                <div class="form-group">
                    <label class="form-label"><strong>End Time:</strong></label>
                    <p id="viewSlotEndTime" style="margin: 0.5rem 0; padding: 0.8rem; background: #f8f9fa; border-radius: 8px; color: #333;">-</p>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><strong>Status:</strong></label>
                <p id="viewSlotStatus" style="margin: 0.5rem 0; padding: 0.8rem; background: #f8f9fa; border-radius: 8px; color: #333;">-</p>
            </div>
            <div class="form-group">
                <label class="form-label"><strong>Patient Name:</strong></label>
                <p id="viewSlotPatientName" style="margin: 0.5rem 0; padding: 0.8rem; background: #f8f9fa; border-radius: 8px; color: #333;">-</p>
            </div>
            <div class="form-group">
                <label class="form-label"><strong>Assigned Doctor:</strong></label>
                <p id="viewSlotDoctorName" style="margin: 0.5rem 0; padding: 0.8rem; background: #f8f9fa; border-radius: 8px; color: #333;">-</p>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeModal('viewOccupiedSlotModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast">
        <span>✓</span>
        <span id="toastMessage">Announcement successfully updated!</span>
    </div>

    <!-- Appointment Details Modal -->
    <div id="appointmentDetailsModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #E0E0E0; padding-bottom: 15px;">
                <h2 class="modal-title" style="margin: 0;">Appointment Details</h2>
                <button class="close" onclick="closeAppointmentModal()" style="font-size: 28px; cursor: pointer; color: #999; background: #F3F4F6; border: none; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.3s ease;">&times;</button>
            </div>
            <div id="appointmentDetailsContent" style="padding: 0 10px;">
                <!-- Appointment details will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Schedule Follow-Up Modal -->
    <div id="scheduleFollowUpModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #E0E0E0; padding-bottom: 15px;">
                <h2 class="modal-title" style="margin: 0;">Schedule Follow-Up Appointment</h2>
                <button class="close" onclick="closeScheduleFollowUpModal()" style="font-size: 28px; cursor: pointer; color: #999; background: #F3F4F6; border: none; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.3s ease;">&times;</button>
            </div>
            <div id="scheduleFollowUpContent" style="padding: 0 10px;">
                <form id="scheduleFollowUpForm" onsubmit="saveFollowUpAppointment(event)">
                    <input type="hidden" id="followUpOriginalAppointmentId" name="original_appointment_id">
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Follow-Up Date *</label>
                        <input type="date" id="followUpDate" name="follow_up_date" class="form-input" required style="width: 100%; padding: 10px; border: 2px solid #E0E0E0; border-radius: 8px; font-size: 14px;">
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Follow-Up Time *</label>
                        <input type="time" id="followUpTime" name="follow_up_time" class="form-input" required style="width: 100%; padding: 10px; border: 2px solid #E0E0E0; border-radius: 8px; font-size: 14px;">
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Notes (Optional)</label>
                        <textarea id="followUpNotes" name="follow_up_notes" class="form-input" rows="4" style="width: 100%; padding: 10px; border: 2px solid #E0E0E0; border-radius: 8px; font-size: 14px; resize: vertical;" placeholder="Add any notes about the follow-up appointment..."></textarea>
                    </div>

                    <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #ff9800;">
                        <strong style="color: #666; font-size: 13px;">Alternative Options (Optional)</strong>
                        <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">If the patient is not available, you can offer 2-3 alternative date/time options (within 1 week):</p>
                        
                        <div style="margin-top: 15px;">
                            <div style="margin-bottom: 10px;">
                                <label style="display: block; margin-bottom: 5px; font-size: 12px; color: #666;">Alternative 1:</label>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                    <input type="date" id="altDate1" name="alt_date_1" class="form-input" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px;">
                                    <input type="time" id="altTime1" name="alt_time_1" class="form-input" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px;">
                                </div>
                            </div>
                            <div style="margin-bottom: 10px;">
                                <label style="display: block; margin-bottom: 5px; font-size: 12px; color: #666;">Alternative 2:</label>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                    <input type="date" id="altDate2" name="alt_date_2" class="form-input" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px;">
                                    <input type="time" id="altTime2" name="alt_time_2" class="form-input" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px;">
                                </div>
                            </div>
                            <div style="margin-bottom: 10px;">
                                <label style="display: block; margin-bottom: 5px; font-size: 12px; color: #666;">Alternative 3:</label>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                    <input type="date" id="altDate3" name="alt_date_3" class="form-input" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px;">
                                    <input type="time" id="altTime3" name="alt_time_3" class="form-input" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px; padding-top: 20px; border-top: 2px solid #E0E0E0;">
                        <button type="button" class="btn-secondary" onclick="closeScheduleFollowUpModal()" style="padding: 12px 30px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; background: #F5F5F5; color: #666; border: none;">Cancel</button>
                        <button type="submit" class="btn-primary" style="padding: 12px 30px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; background: #4CAF50; color: white; border: none;">Schedule Follow-Up</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Initial Screening (Triage) Modal - two-column layout matching system forms -->
    <div id="initialScreeningModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #E0E0E0; padding-bottom: 15px;">
                <h2 class="modal-title" style="margin: 0; color: #2d5f3d;"><i class="fas fa-stethoscope"></i> Initial Screening</h2>
                <button class="close" onclick="closeInitialScreeningModal()" style="font-size: 28px; cursor: pointer; color: #999; background: #F3F4F6; border: none; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.3s ease;">&times;</button>
            </div>
            <div id="screeningFormSummaryError" class="screening-field-error" style="margin-bottom: 1rem;" aria-live="polite"></div>
            <div style="padding: 0 10px;">
                <form id="fdoInitialScreeningForm" onsubmit="saveFDOInitialScreening(event)">
                    <input type="hidden" id="fdo_screening_appointment_id" name="appointment_id" value="">
                    <input type="hidden" id="fdo_screening_patient_id" name="patient_id" value="">
                    <input type="hidden" id="fdo_screening_user_id" name="user_id" value="">
                    <input type="hidden" id="fdo_screening_temperature_c" name="temperature" value="">
                    <input type="hidden" id="fdo_screening_weight_kg" name="weight" value="">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="fdo_screening_blood_pressure">Blood Pressure <span style="color:red;">*</span></label>
                            <div class="screening-input-callout-box">
                                <input type="text" id="fdo_screening_blood_pressure" name="blood_pressure" class="form-input" placeholder="e.g., 120/80" autocomplete="off" required>
                                <div id="bp_emergency_wrap" class="screening-callout-inside alert-emergency" role="alert">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Hypertensive Crisis Detected. Patient must be referred immediately to the nearest hospital.</span>
                                </div>
                            </div>
                            <span class="screening-field-error" id="err_blood_pressure" aria-live="polite"></span>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="fdo_screening_temperature_input">Temperature <span style="color:red;">*</span></label>
                            <div class="screening-input-callout-box">
                                <div class="screening-input-with-unit">
                                    <input type="text" id="fdo_screening_temperature_input" class="form-input" placeholder="e.g., 36.5" inputmode="decimal" autocomplete="off" required>
                                    <select id="fdo_screening_temperature_unit" class="screening-unit-inline" aria-label="Temperature unit" title="Unit">
                                        <option value="C">°C</option>
                                        <option value="F">°F</option>
                                    </select>
                                </div>
                                <div id="temperature_emergency_wrap" class="screening-callout-inside temperature-emergency" role="alert">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <span>High Fever Detected. Patient requires urgent medical attention. Please refer to nearest hospital.</span>
                                </div>
                            </div>
                            <span class="screening-field-error" id="err_temperature" aria-live="polite"></span>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="fdo_screening_weight_input">Weight <span style="color:red;">*</span></label>
                            <div class="screening-input-with-unit">
                                <input type="text" id="fdo_screening_weight_input" class="form-input" placeholder="e.g., 70.5" inputmode="decimal" autocomplete="off" required>
                                <select id="fdo_screening_weight_unit" class="screening-unit-inline" aria-label="Weight unit" title="Unit">
                                    <option value="kg">kg</option>
                                    <option value="lbs">lbs</option>
                                </select>
                            </div>
                            <span class="screening-field-error" id="err_weight" aria-live="polite"></span>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="fdo_screening_pulse_rate">Pulse Rate (bpm) <span style="color:red;">*</span></label>
                            <div class="screening-input-callout-box">
                                <input type="text" id="fdo_screening_pulse_rate" name="pulse_rate" class="form-input" inputmode="numeric" placeholder="e.g., 72" required>
                                <div id="pulse_warning_wrap" class="screening-callout-inside alert-warning" role="alert">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <span>Pulse rate outside normal range. Please verify and monitor patient.</span>
                                </div>
                            </div>
                            <span class="screening-field-error" id="err_pulse_rate" aria-live="polite"></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="fdo_screening_oxygen_saturation">Oxygen Saturation (%) <span style="color:red;">*</span></label>
                        <div class="screening-input-callout-box">
                            <input type="text" id="fdo_screening_oxygen_saturation" name="oxygen_saturation" class="form-input" inputmode="decimal" placeholder="e.g., 98.0" required>
                            <div id="oxygen_critical_wrap" class="screening-callout-inside alert-critical" role="alert">
                                <i class="fas fa-exclamation-circle"></i>
                                <span>Low SpO2 detected. Patient requires immediate referral to nearest hospital.</span>
                            </div>
                        </div>
                        <span class="screening-field-error" id="err_oxygen_saturation" aria-live="polite"></span>
                    </div>
                    <div class="modal-actions" style="justify-content: flex-end;">
                        <button type="button" class="btn-secondary" onclick="closeInitialScreeningModal()">Cancel</button>
                        <button type="submit" class="btn-primary" id="fdo_screening_submit_btn"><i class="fas fa-save"></i> Save Initial Screening</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Initial Screening Success Modal -->
    <div id="initialScreeningSuccessOverlay" class="initial-screening-success-overlay" style="display: none;">
        <div class="initial-screening-success-modal">
            <div style="padding: 32px; text-align: center;">
                <div style="font-size: 64px; color: #4CAF50; margin-bottom: 20px;"><i class="fas fa-check-circle"></i></div>
                <div style="color: #333; font-size: 16px; margin-bottom: 28px;">Initial screening information saved successfully.</div>
                <button type="button" class="btn-primary" onclick="closeInitialScreeningSuccessModal()"><i class="fas fa-check"></i> OK</button>
            </div>
        </div>
    </div>

    <!-- Emergency Alert Modal (red popup before save when critical vitals detected) -->
    <div id="screeningEmergencyOverlay" class="screening-emergency-overlay">
        <div class="screening-emergency-modal">
            <div class="emergency-header"><i class="fas fa-exclamation-circle"></i> <span id="screeningEmergencyTitle">Emergency</span></div>
            <div class="emergency-body">
                <p id="screeningEmergencyMessage"></p>
                <p style="margin-top: 12px; font-weight: 600;">This case will be flagged as <strong>FOR IMMEDIATE REFERRAL</strong> in the system.</p>
            </div>
            <div class="emergency-actions">
                <button type="button" class="btn-emergency-cancel" id="screeningEmergencyCancelBtn">Cancel</button>
                <button type="button" class="btn-emergency-confirm" id="screeningEmergencyConfirmBtn">I understand, save and flag for referral</button>
            </div>
        </div>
    </div>

    <script>
        // Page Navigation
        function showPage(pageId) {
            // Hide all pages
            document.querySelectorAll('.page-content').forEach(page => {
                page.classList.remove('active');
            });

            // Remove active state from all nav items
            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });

            // Show selected page
            document.getElementById(pageId).classList.add('active');

            // Set active nav item
            if (event && event.currentTarget) {
                event.currentTarget.classList.add('active');
            }

            // Load data for specific pages
            if (pageId === 'dashboard') {
                startDashboardAutoRefresh();
            } else {
                // Stop dashboard auto-refresh when leaving dashboard
                stopDashboardAutoRefresh();
            }
            
            if (pageId === 'announcements') {
                loadFDOAnnouncements(true); // Show loading on manual page switch
                startAnnouncementsAutoRefresh();
            } else {
                // Stop auto-refresh when leaving announcements page
                stopAnnouncementsAutoRefresh();
                lastAnnouncementsData = null; // Reset data cache when leaving
            }
            if (pageId === 'appointments') {
                // Check URL parameters for filters
                const urlParams = new URLSearchParams(window.location.search);
                const dateFilter = urlParams.get('date') || 'all';
                const statusFilter = urlParams.get('status') || 'all';
                
                if (dateFilter !== currentDateFilter || statusFilter !== currentStatusFilter) {
                    currentDateFilter = dateFilter;
                    currentStatusFilter = statusFilter;
                    
                    // Update UI to reflect filters
                    const dateDropdown = document.getElementById('dateFilterDropdown');
                    if (dateDropdown) {
                        dateDropdown.value = dateFilter;
                    }
                    
                    const statusDropdown = document.getElementById('statusFilterDropdown');
                    if (statusDropdown) {
                        statusDropdown.value = statusFilter;
                    }
                }
                
                loadFDOAppointments();
                loadRescheduleRequests();
                startAppointmentsAutoRefresh();
            } else {
                stopAppointmentsAutoRefresh();
            }
            if (pageId === 'schedule') {
                setTimeout(() => {
                    initializeSchedule();
                }, 100);
            }
            if (pageId === 'validation') {
                document.getElementById('validationMessage').style.display = 'none';
                document.getElementById('validationResult').style.display = 'none';
                document.getElementById('validationCodeInput').value = '';
            }
            if (pageId === 'notifications') {
                loadNotificationsPage();
                updateFdoNotificationBadge();
                startFdoNotificationsAutoRefresh();
            } else {
                stopFdoNotificationsAutoRefresh();
            }
            if (pageId === 'dashboard') {
                loadDashboardNotifications();
                updateFdoNotificationBadge();
            }
        }

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            
            // If opening schedule modals, sync the date with selected week (use Monday of the week)
            if (modalId === 'editSchedule') {
                const weekPicker = document.getElementById('scheduleWeekPicker');
                if (weekPicker && weekPicker.value) {
                    const weekDates = getWeekDates(weekPicker.value);
                    if (weekDates && weekDates.length > 0) {
                        // Use Monday (first day) as default date for modals
                        updateModalDates(weekDates[0]);
                    }
                }
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // Close modal when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });

        // Character Counter
        function updateCharCount(input, counterId) {
            const count = input.value.length;
            document.getElementById(counterId).textContent = count;
        }

        function previewFDOImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('fdoPreviewImg').src = e.target.result;
                    document.getElementById('fdoImagePreview').style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            } else {
                document.getElementById('fdoImagePreview').style.display = 'none';
            }
        }

        // Save Announcement (FDO can create directly approved)
        function submitFDOAnnouncement(event) {
            event.preventDefault();
            
            // Validate required fields
            const imageInput = document.getElementById('newImageUpload');
            const startDate = document.querySelector('[name="start_date"]');
            const endDate = document.querySelector('[name="end_date"]');
            
            if (!imageInput || !imageInput.files || imageInput.files.length === 0) {
                alert('Please attach an image');
                return;
            }
            
            if (!startDate || !startDate.value) {
                alert('Please select a start date and time');
                return;
            }
            
            if (!endDate || !endDate.value) {
                alert('Please select an end date and time');
                return;
            }
            
            const formData = new FormData(document.getElementById('fdoAnnouncementForm'));
            
            // Handle schedule field
            const scheduleSelect = document.getElementById('fdoAnnouncementSchedule');
            const scheduleCustom = document.getElementById('fdoAnnouncementScheduleCustom');
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
                    showToast(data.message);
                    closeModal('addAnnouncement');
                    loadFDOAnnouncements();
                    
                    // Refresh dashboard stats and announcements preview if dashboard is active
                    const dashboardPage = document.getElementById('dashboard');
                    if (dashboardPage && dashboardPage.classList.contains('active')) {
                        loadDashboardStats();
                        loadDashboardAnnouncementsPreview();
                    }
                    
                    document.getElementById('fdoAnnouncementForm').reset();
                    document.getElementById('fdoImagePreview').style.display = 'none';
                    document.getElementById('fdoAnnouncementScheduleCustom').style.display = 'none';
                    document.getElementById('fdoImagePreview').style.display = 'none';
                    document.getElementById('fdoAnnouncementScheduleCustom').style.display = 'none';
                    
                    // Schedule custom input handler
                    const scheduleSelect = document.getElementById('fdoAnnouncementSchedule');
                    if (scheduleSelect) {
                        scheduleSelect.onchange = function() {
                            const customInput = document.getElementById('fdoAnnouncementScheduleCustom');
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
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error submitting announcement');
            });
        }

        // Delete Announcement
        async function deleteAnnouncement() {
            const confirmed = await confirm('Are you sure you want to delete this announcement? This action cannot be undone.');
            if (confirmed) {
                closeModal('editAnnouncement');
                showToast('Announcement successfully deleted!');
            }
        }

        // Preview Announcement
        function previewAnnouncement(mode) {
            let form;
            let imageInput;
            let imagePreview;
            
            if (mode === 'add') {
                form = document.getElementById('fdoAnnouncementForm');
                imageInput = document.getElementById('newImageUpload');
                imagePreview = document.getElementById('fdoPreviewImg');
            } else if (mode === 'edit') {
                form = document.querySelector('#editAnnouncement form');
                imageInput = document.getElementById('imageUpload');
                // Check for image preview in edit form
                const editImagePreview = document.querySelector('#editAnnouncement img');
                imagePreview = editImagePreview || null;
            }
            
            if (!form) {
                alert('Form not found');
                return;
            }
            
            // Get form values - try name attributes first, then fallback to type/position
            let title = form.querySelector('[name="title"]')?.value || '';
            let content = form.querySelector('[name="content"]')?.value || '';
            let category = form.querySelector('[name="category"]')?.value || '';
            let audience = form.querySelector('[name="audience"]')?.value || '';
            let startDate = form.querySelector('[name="start_date"]')?.value || '';
            let endDate = form.querySelector('[name="end_date"]')?.value || '';
            
            // Fallback for edit form which may not have name attributes
            if (!title) {
                const titleInput = form.querySelector('input[type="text"]');
                if (titleInput) title = titleInput.value || '';
            }
            if (!content) {
                const contentTextarea = form.querySelector('textarea');
                if (contentTextarea) content = contentTextarea.value || '';
            }
            if (!category) {
                const categorySelect = form.querySelectorAll('select');
                if (categorySelect.length > 0) {
                    // Try to find category select (usually the first one that's not audience)
                    for (let sel of categorySelect) {
                        if (sel.querySelector('option[value="General"]') || sel.querySelector('option[value="Event"]')) {
                            category = sel.value || 'General';
                            break;
                        }
                    }
                }
            }
            if (!audience) {
                const audienceSelect = form.querySelector('[name="audience"]') || 
                                     (form.querySelectorAll('select').length > 1 ? form.querySelectorAll('select')[1] : null);
                if (audienceSelect) {
                    audience = audienceSelect.value || '';
                }
            }
            if (!startDate) {
                const startDateInput = form.querySelector('input[type="datetime-local"]');
                if (startDateInput) startDate = startDateInput.value || '';
            }
            if (!endDate) {
                const dateInputs = form.querySelectorAll('input[type="datetime-local"]');
                if (dateInputs.length > 1) endDate = dateInputs[1].value || '';
            }
            
            const scheduleSelect = document.getElementById('fdoAnnouncementSchedule') || form.querySelector('[name="schedule"]');
            const scheduleCustom = document.getElementById('fdoAnnouncementScheduleCustom') || form.querySelector('[name="schedule_custom"]');
            let schedule = 'Not Applicable';
            
            if (scheduleSelect) {
                if (scheduleSelect.value === 'Custom' && scheduleCustom && scheduleCustom.value) {
                    schedule = scheduleCustom.value;
                } else if (scheduleSelect.value && scheduleSelect.value !== 'Not Applicable') {
                    schedule = scheduleSelect.value;
                }
            }
            
            // Get image preview
            let imageHtml = '';
            if (imageInput && imageInput.files && imageInput.files.length > 0) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    imageHtml = `<div style="margin-bottom:20px;"><img src="${e.target.result}" alt="${title}" style="width:100%; max-height:400px; object-fit:cover; border-radius:12px; border:1px solid #ddd;"></div>`;
                    displayPreview(title, content, category, audience, startDate, endDate, schedule, imageHtml);
                };
                reader.readAsDataURL(imageInput.files[0]);
            } else if (imagePreview && imagePreview.src && !imagePreview.src.includes('data:image/svg')) {
                imageHtml = `<div style="margin-bottom:20px;"><img src="${imagePreview.src}" alt="${title}" style="width:100%; max-height:400px; object-fit:cover; border-radius:12px; border:1px solid #ddd;"></div>`;
                displayPreview(title, content, category, audience, startDate, endDate, schedule, imageHtml);
            } else {
                displayPreview(title, content, category, audience, startDate, endDate, schedule, imageHtml);
            }
        }
        
        function displayPreview(title, content, category, audience, startDate, endDate, schedule, imageHtml) {
            // Format dates
            let dateInfo = '';
            let subtitle = category || 'General';
            
            if (startDate) {
                const start = new Date(startDate);
                const end = endDate ? new Date(endDate) : null;
                
                if (end) {
                    dateInfo = `Starting on ${start.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })} from ${start.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })} to ${end.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}`;
                } else {
                    dateInfo = `Starting on ${start.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })} at ${start.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}`;
                }
            }
            
            // Format schedule info
            if (schedule && schedule !== 'Not Applicable') {
                subtitle = schedule;
            }
            
            // Format audience
            let audienceText = '';
            if (audience) {
                const audienceMap = {
                    'all': 'All Patients',
                    'patients': 'Patients Only',
                    'doctors': 'Doctors Only',
                    'pharmacists': 'Pharmacists Only',
                    'fdo': 'FDO Only',
                    'admin': 'Admin Only'
                };
                audienceText = audienceMap[audience] || audience;
            }
            
            // Build preview HTML
            const previewHTML = `
                ${imageHtml}
                <div class="announcement-modal-title">${title || 'Untitled Announcement'}</div>
                <div class="announcement-modal-subtitle">${subtitle}</div>
                ${audienceText ? `<div style="text-align: center; color: #666; font-size: 14px; margin-bottom: 8px;">Target: ${audienceText}</div>` : ''}
                ${dateInfo ? `<div class="announcement-modal-date">${dateInfo}</div>` : ''}
                <div class="announcement-modal-body">${formatAnnouncementContent(content || 'No content provided.')}</div>
            `;
            
            // Display preview modal
            const modal = document.getElementById('previewAnnouncementModal');
            const modalBody = document.getElementById('previewAnnouncementModalBody');
            
            if (modal && modalBody) {
                modalBody.innerHTML = previewHTML;
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            }
        }
        
        function closePreviewAnnouncementModal() {
            const modal = document.getElementById('previewAnnouncementModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        }
        
        // Close preview modal when clicking outside
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('previewAnnouncementModal');
            if (modal && e.target === modal) {
                closePreviewAnnouncementModal();
            }
        });

        // Toast Notification
        function showToast(message) {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            
            toastMessage.textContent = message;
            toast.classList.add('active');

            setTimeout(() => {
                toast.classList.remove('active');
            }, 3000);
        }

        // Handle Logout
        function handleLogout() {
            // Directly redirect to logout script (no confirmation popup)
            window.location.href = 'logout.php';
        }

        // File upload handlers
        document.getElementById('imageUpload')?.addEventListener('change', function(e) {
            if (e.target.files.length > 0) {
                const fileName = e.target.files[0].name;
                showToast(`Image uploaded: ${fileName}`);
            }
        });

        document.getElementById('fileUpload')?.addEventListener('change', function(e) {
            if (e.target.files.length > 0) {
                const fileName = e.target.files[0].name;
                showToast(`File uploaded: ${fileName}`);
            }
        });

        document.getElementById('newImageUpload')?.addEventListener('change', function(e) {
            if (e.target.files.length > 0) {
                const fileName = e.target.files[0].name;
                showToast(`Image uploaded: ${fileName}`);
            }
        });

        document.getElementById('newFileUpload')?.addEventListener('change', function(e) {
            if (e.target.files.length > 0) {
                const fileName = e.target.files[0].name;
                showToast(`File uploaded: ${fileName}`);
            }
        });

        // Auto-refresh interval for announcements
        let announcementsRefreshInterval = null;
        let lastAnnouncementsData = null; // Store last data to compare
        let isInitialLoad = true; // Track if this is the first load

        // Dashboard statistics auto-refresh interval
        let dashboardStatsInterval = null;
        // Auto-refresh for appointments list (when patient creates appointment)
        let appointmentsRefreshInterval = null;
        // Auto-refresh for notifications page
        let fdoNotificationsRefreshInterval = null;

        // Load dashboard statistics
        async function loadDashboardUpcomingAppointments() {
            const tbody = document.getElementById('dashboardUpcomingAppointmentsBody');
            if (!tbody) return;
            try {
                const res = await fetch('fdo_get_appointments.php?date_filter=today&status_filter=all');
                const data = await res.json();
                if (!data.success || !data.appointments) {
                    tbody.innerHTML = '<tr><td colspan="2" style="text-align: center; padding: 20px; color: #666;">No upcoming appointments</td></tr>';
                    return;
                }
                const now = new Date();
                const today = now.toISOString().slice(0, 10);
                const upcoming = (data.appointments || []).filter(function(a) {
                    const start = (a.start_datetime || '').replace(' ', 'T');
                    return start && start.slice(0, 10) === today && new Date(start) >= now;
                }).sort(function(a, b) {
                    return (a.start_datetime || '').localeCompare(b.start_datetime || '');
                }).slice(0, 10);
                if (upcoming.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="2" style="text-align: center; padding: 20px; color: #666;">No upcoming appointments</td></tr>';
                    return;
                }
                tbody.innerHTML = upcoming.map(function(a) {
                    var dt = a.start_datetime || '';
                    var timeStr = dt;
                    if (dt.length >= 16) {
                        var h = parseInt(dt.slice(11, 13), 10);
                        var m = dt.slice(14, 16);
                        timeStr = (h % 12 || 12) + ':' + m + (h < 12 ? ' AM' : ' PM');
                    }
                    return '<tr><td>' + escapeHtml(a.patient_name || '—') + '</td><td>' + escapeHtml(timeStr) + '</td></tr>';
                }).join('');
            } catch (e) {
                tbody.innerHTML = '<tr><td colspan="2" style="text-align: center; padding: 20px; color: #666;">No upcoming appointments</td></tr>';
            }
        }

        async function loadDashboardStats() {
            try {
                const response = await fetch('fdo_get_dashboard_stats.php');
                const data = await response.json();
                
                if (data.success && data.stats) {
                    // Update the stat cards
                    const pendingEl = document.getElementById('pendingAppointmentsCount');
                    const todayEl = document.getElementById('todayAppointmentsCount');
                    const activeEl = document.getElementById('activeAnnouncementsCount');
                    
                    if (pendingEl) pendingEl.textContent = data.stats.pending_appointments || 0;
                    if (todayEl) todayEl.textContent = data.stats.today_appointments || 0;
                    if (activeEl) activeEl.textContent = data.stats.active_announcements || 0;
                }
            } catch (error) {
                console.error('Error loading dashboard stats:', error);
            }
        }

        // Load announcements preview for dashboard
        async function loadDashboardAnnouncementsPreview() {
            try {
                const response = await fetch('get_announcements.php');
                const data = await response.json();
                
                if (data.success && data.announcements) {
                    // Filter to only approved announcements
                    const approved = data.announcements.filter(a => a.status === 'approved');
                    const container = document.getElementById('dashboardAnnouncementsPreview');
                    
                    if (!container) return;
                    
                    if (approved.length === 0) {
                        container.innerHTML = '<div style="text-align: center; padding: 30px; color: #666;">No active announcements</div>';
                    } else {
                        // Show up to 2 most recent approved announcements
                        const recent = approved.slice(0, 2);
                        container.innerHTML = recent.map(ann => {
                            const startDate = ann.start_date ? new Date(ann.start_date) : null;
                            const endDate = ann.end_date ? new Date(ann.end_date) : null;
                            
                            let scheduleText = '';
                            if (ann.schedule && ann.schedule !== 'Not Applicable') {
                                scheduleText = `<p><strong>${ann.schedule}</strong></p>`;
                            } else if (startDate && endDate) {
                                scheduleText = `<p><strong>${startDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })} | ${startDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })} - ${endDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}</strong></p>`;
                            } else if (startDate) {
                                scheduleText = `<p><strong>${startDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })} | ${startDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}</strong></p>`;
                            }
                            
                            let locationText = '';
                            if (ann.content) {
                                // Try to extract location from content (look for "At" or location keywords)
                                const locationMatch = ann.content.match(/[Aa]t\s+([^\.\n]+)/);
                                if (locationMatch) {
                                    locationText = `<p>${locationMatch[1]}</p>`;
                                }
                            }
                            
                            return `
                                <div class="announcement-card">
                                    <h3>${ann.title || 'N/A'}</h3>
                                    ${scheduleText}
                                    ${locationText || '<p style="color: #999; font-style: italic;">No location specified</p>'}
                                </div>
                            `;
                        }).join('');
                    }
                }
            } catch (error) {
                console.error('Error loading dashboard announcements preview:', error);
                const container = document.getElementById('dashboardAnnouncementsPreview');
                if (container) {
                    container.innerHTML = '<div style="text-align: center; padding: 30px; color: #dc2626;">Error loading announcements</div>';
                }
            }
        }

        // FDO Notifications
        async function updateFdoNotificationBadge() {
            try {
                const res = await fetch('fdo_get_notifications.php?action=unread_count');
                const data = await res.json();
                const badge = document.getElementById('fdoNotificationBadge');
                if (!badge) return;
                const count = data.success ? (data.unread_count || 0) : 0;
                badge.textContent = count > 99 ? '99+' : String(count);
                badge.classList.toggle('hidden', count === 0);
            } catch (e) { /* ignore */ }
        }
        async function loadDashboardNotifications() {
            const container = document.getElementById('dashboardNotificationsContainer');
            if (!container) return;
            try {
                const res = await fetch('fdo_get_notifications.php?action=fetch');
                const data = await res.json();
                if (!data.success) {
                    container.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;">Unable to load notifications</div>';
                    return;
                }
                const list = data.notifications || [];
                if (list.length === 0) {
                    container.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;">No notifications</div>';
                    return;
                }
                const recent = list.slice(0, 3);
                container.innerHTML = recent.map(function(n) {
                    var iconCls = 'fas fa-calendar-check';
                    var iconBg = '#4CAF50';
                    var rowCls = n.is_read ? 'notification-item' : 'notification-item unread';
                    return '<div class="' + rowCls + '" data-notif-id="' + n.id + '" onclick="markFdoNotificationRead(' + n.id + '); showPage(\'notifications\');">' +
                        '<div class="notification-icon" style="background:' + iconBg + ';"><i class="' + iconCls + '"></i></div>' +
                        '<div style="flex:1;">' +
                        '<div style="font-weight:600;color:#333;">' + escapeHtml(n.patient_name) + '</div>' +
                        '<div style="font-size:13px;color:#666;">' + n.date + ' at ' + n.time + ' · ' + n.status + '</div>' +
                        (n.complaint && n.complaint !== '—' ? '<div style="font-size:12px;color:#888;margin-top:4px;">' + escapeHtml(n.complaint) + '</div>' : '') +
                        '</div></div>';
                }).join('');
            } catch (e) {
                container.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc2626;">Error loading notifications</div>';
            }
        }
        async function loadNotificationsPage() {
            const container = document.getElementById('notificationsPageContainer');
            if (!container) return;
            container.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;">Loading notifications...</div>';
            try {
                const res = await fetch('fdo_get_notifications.php?action=fetch');
                const data = await res.json();
                if (!data.success) {
                    container.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;">Unable to load notifications</div>';
                    return;
                }
                const list = data.notifications || [];
                if (list.length === 0) {
                    container.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;">No notifications</div>';
                    return;
                }
                container.innerHTML = list.map(function(n) {
                    var rowCls = n.is_read ? 'notification-item' : 'notification-item unread';
                    return '<div class="' + rowCls + '" data-notif-id="' + n.id + '" onclick="markFdoNotificationRead(' + n.id + ')">' +
                        '<div class="notification-icon" style="background:#4CAF50;"><i class="fas fa-calendar-check"></i></div>' +
                        '<div style="flex:1;">' +
                        '<div style="font-weight:600;color:#333;">' + escapeHtml(n.patient_name) + '</div>' +
                        '<div style="font-size:13px;color:#666;">' + n.date + ' at ' + n.time + '</div>' +
                        '<div style="font-size:13px;color:#555;margin-top:4px;">Type/Complaint: ' + escapeHtml(n.complaint) + '</div>' +
                        '<div style="margin-top:6px;"><span style="display:inline-block;padding:4px 8px;border-radius:4px;font-size:12px;background:#e8f5e9;color:#2E7D32;">' + escapeHtml(n.status) + '</span></div>' +
                        '</div></div>';
                }).join('');
                updateFdoNotificationBadge();
            } catch (e) {
                container.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc2626;">Error loading notifications</div>';
            }
        }
        async function markFdoNotificationRead(id) {
            if (!id) return;
            try {
                var fd = new FormData();
                fd.append('id', id);
                await fetch('fdo_get_notifications.php?action=mark_read', { method: 'POST', body: fd });
                var row = document.querySelector('[data-notif-id="' + id + '"]');
                if (row) row.classList.remove('unread');
                updateFdoNotificationBadge();
            } catch (e) { /* ignore */ }
        }
        async function markAllFdoNotificationsRead() {
            try {
                await fetch('fdo_get_notifications.php?action=mark_all_read', { method: 'POST' });
                document.querySelectorAll('.notification-item.unread').forEach(function(el) { el.classList.remove('unread'); });
                loadNotificationsPage();
                loadDashboardNotifications();
                updateFdoNotificationBadge();
            } catch (e) {
                alert('Failed to mark all as read');
            }
        }
        function escapeHtml(s) {
            if (s == null) return '';
            var div = document.createElement('div');
            div.textContent = s;
            return div.innerHTML;
        }

        // Start dashboard auto-refresh
        function startDashboardAutoRefresh() {
            // Clear any existing interval
            if (dashboardStatsInterval) {
                clearInterval(dashboardStatsInterval);
            }
            
            // Load immediately
            loadDashboardStats();
            loadDashboardAnnouncementsPreview();
            loadDashboardUpcomingAppointments();
            loadDashboardNotifications();
            updateFdoNotificationBadge();
            
            // Set up interval to refresh every 7 seconds (real-time when patient creates appointment)
            dashboardStatsInterval = setInterval(function() {
                const dashboardPage = document.getElementById('dashboard');
                if (dashboardPage && dashboardPage.classList.contains('active')) {
                    loadDashboardStats();
                    loadDashboardAnnouncementsPreview();
                    loadDashboardUpcomingAppointments();
                    loadDashboardNotifications();
                    updateFdoNotificationBadge();
                }
            }, 7000); // Refresh every 7 seconds for immediate updates
        }

        // Stop dashboard auto-refresh
        function stopDashboardAutoRefresh() {
            if (dashboardStatsInterval) {
                clearInterval(dashboardStatsInterval);
                dashboardStatsInterval = null;
            }
        }

        // Initialize character counters and load announcements
        document.addEventListener('DOMContentLoaded', function() {
            // Set initial count for edit form
            const editTitleInput = document.querySelector('#editAnnouncement input[type="text"]');
            if (editTitleInput) {
                updateCharCount(editTitleInput, 'titleCount');
            }
            
            // Load dashboard stats if dashboard is active
            const dashboardPage = document.getElementById('dashboard');
            if (dashboardPage && dashboardPage.classList.contains('active')) {
                startDashboardAutoRefresh();
            }
            
            // Update FDO notification badge on load and periodically (dynamic when new appointments are created)
            updateFdoNotificationBadge();
            setInterval(updateFdoNotificationBadge, 7000); // Every 7s for immediate new-appointment visibility
            
            // Load announcements when announcements page is shown
            const announcementsPage = document.getElementById('announcements');
            if (announcementsPage && announcementsPage.classList.contains('active')) {
                loadFDOAnnouncements(true);
                startAnnouncementsAutoRefresh();
            }
        });

        // Start auto-refresh for appointments list (when patient creates appointment)
        function startAppointmentsAutoRefresh() {
            if (appointmentsRefreshInterval) {
                clearInterval(appointmentsRefreshInterval);
            }
            appointmentsRefreshInterval = setInterval(function() {
                const appointmentsPage = document.getElementById('appointments');
                if (appointmentsPage && appointmentsPage.classList.contains('active')) {
                    loadFDOAppointments();
                    loadRescheduleRequests();
                }
            }, 7000); // Poll every 7 seconds for immediate updates
        }
        function stopAppointmentsAutoRefresh() {
            if (appointmentsRefreshInterval) {
                clearInterval(appointmentsRefreshInterval);
                appointmentsRefreshInterval = null;
            }
        }

        // Start auto-refresh for notifications page
        function startFdoNotificationsAutoRefresh() {
            if (fdoNotificationsRefreshInterval) {
                clearInterval(fdoNotificationsRefreshInterval);
            }
            fdoNotificationsRefreshInterval = setInterval(function() {
                const notificationsPage = document.getElementById('notifications');
                if (notificationsPage && notificationsPage.classList.contains('active')) {
                    loadNotificationsPage();
                    updateFdoNotificationBadge();
                }
            }, 7000); // Poll every 7 seconds for new appointment notifications
        }
        function stopFdoNotificationsAutoRefresh() {
            if (fdoNotificationsRefreshInterval) {
                clearInterval(fdoNotificationsRefreshInterval);
                fdoNotificationsRefreshInterval = null;
            }
        }

        // Start auto-refresh for announcements (every 10 seconds to reduce blinking)
        function startAnnouncementsAutoRefresh() {
            // Clear any existing interval
            if (announcementsRefreshInterval) {
                clearInterval(announcementsRefreshInterval);
            }
            
            // Load immediately first
            loadFDOAnnouncements(true);
            
            // Set up new interval to refresh every 10 seconds (reduced frequency)
            announcementsRefreshInterval = setInterval(function() {
                // Only refresh if announcements page is currently active
                const announcementsPage = document.getElementById('announcements');
                if (announcementsPage && announcementsPage.classList.contains('active')) {
                    loadFDOAnnouncements(false); // false = silent refresh, no loading message
                }
            }, 10000); // Refresh every 10 seconds to reduce blinking
        }

        // Stop auto-refresh when leaving announcements page
        function stopAnnouncementsAutoRefresh() {
            if (announcementsRefreshInterval) {
                clearInterval(announcementsRefreshInterval);
                announcementsRefreshInterval = null;
            }
        }

        // Load announcements for FDO
        // showLoading: true = show loading message, false = silent update
        function loadFDOAnnouncements(showLoading = false) {
            const pendingTbody = document.getElementById('pendingAnnouncementsTableBody');
            
            // Only show loading state on initial load or manual refresh
            if (showLoading) {
                if (pendingTbody) {
                    pendingTbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 30px;">Loading pending announcements...</td></tr>';
                }
            }
            
            fetch('get_announcements.php')
                .then(response => {
                    if (!response.ok) {
                        // Try to get error message from response
                        return response.text().then(text => {
                            try {
                                const json = JSON.parse(text);
                                throw new Error(json.message || 'Network response was not ok');
                            } catch (e) {
                                throw new Error('HTTP ' + response.status + ': ' + text);
                            }
                        });
                    }
                    return response.text().then(text => {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('Invalid JSON response:', text);
                            throw new Error('Invalid response from server: ' + text.substring(0, 100));
                        }
                    });
                })
                .then(data => {
                    if (data && data.success) {
                        // Filter pending announcements
                        // Make status check case-insensitive and handle null/undefined
                        const pending = data.announcements.filter(a => {
                            const status = (a.status || '').toLowerCase();
                            return status === 'pending';
                        });
                        
                        // Create a simple hash to compare data (check if anything changed)
                        const currentDataHash = JSON.stringify({
                            pendingCount: pending.length,
                            pendingIds: pending.map(a => a.announcement_id).sort().join(',')
                        });
                        
                        // Only update DOM if data has changed (prevents unnecessary re-renders)
                        if (lastAnnouncementsData !== currentDataHash || showLoading) {
                            lastAnnouncementsData = currentDataHash;
                            
                            // Load pending approvals
                            if (pendingTbody) {
                                if (pending.length === 0) {
                                    pendingTbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 30px;">No pending approvals</td></tr>';
                                } else {
                                    pendingTbody.innerHTML = pending.map(ann => {
                                        const date = new Date(ann.date_posted);
                                        return `
                                            <tr>
                                                <td>${ann.title || 'N/A'}</td>
                                                <td>${ann.posted_by_name || ann.posted_by_username || ann.posted_by_role || 'N/A'}</td>
                                                <td>${ann.category || 'General'}</td>
                                                <td>${date.toLocaleDateString()}</td>
                                                <td>
                                                    <button onclick="viewAnnouncementForApproval(${ann.announcement_id})" class="action-btn btn-view" style="margin-right: 8px;">
                                                        <i class="fas fa-eye"></i> Review
                                                    </button>
                                                </td>
                                            </tr>
                                        `;
                                    }).join('');
                                }
                            }
                            
                            // Load published announcements
                            loadPublishedAnnouncements(data.announcements);
                        }
                    } else {
                        const errorMsg = (data && data.message) ? data.message : 'Unknown error occurred';
                        console.error('Failed to load announcements:', errorMsg);
                        if (pendingTbody) {
                            pendingTbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 30px; color: red;">Error: ' + errorMsg + '</td></tr>';
                        }
                    }
                })
                .catch(error => {
                    const errorMsg = error.message || 'Unknown error';
                    console.error('Error loading announcements:', error);
                    console.error('Full error:', error);
                    if (pendingTbody) {
                        pendingTbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 30px; color: red;">Error: ' + errorMsg + '<br><small>Check browser console for details</small></td></tr>';
                    }
                });
        }

        let currentReviewAnnouncementId = null;

        function viewAnnouncementForApproval(id) {
            currentReviewAnnouncementId = id;
            fetch('get_announcements.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const announcement = data.announcements.find(a => a.announcement_id == id);
                        if (announcement) {
                            const date = new Date(announcement.date_posted);
                            const modal = document.getElementById('editAnnouncement');
                            const modalContent = modal.querySelector('.modal-content');
                            
                            // Update modal to show review/approval interface
                            let imageHtml = '';
                            if (announcement.image_path) {
                                imageHtml = `
                                    <div style="margin-bottom: 20px;">
                                        <strong>Image:</strong>
                                        <div style="margin-top:8px;">
                                            <img src="${announcement.image_path}" alt="${announcement.title}" style="max-width:100%; max-height:400px; border-radius:8px; border:1px solid #ddd; object-fit:cover;">
                                        </div>
                                    </div>
                                `;
                            }
                            
                            let scheduleHtml = '';
                            if (announcement.schedule && announcement.schedule !== 'Not Applicable') {
                                scheduleHtml = `
                                    <div style="margin-bottom: 20px;">
                                        <strong>Schedule:</strong> ${announcement.schedule}
                                    </div>
                                `;
                            }
                            
                            let dateInfoHtml = '';
                            if (announcement.start_date || announcement.end_date) {
                                const startDate = announcement.start_date ? new Date(announcement.start_date) : null;
                                const endDate = announcement.end_date ? new Date(announcement.end_date) : null;
                                let dateInfo = '';
                                if (startDate && endDate) {
                                    dateInfo = `${startDate.toLocaleDateString()} ${startDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})} - ${endDate.toLocaleDateString()} ${endDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}`;
                                } else if (startDate) {
                                    dateInfo = `Starts: ${startDate.toLocaleDateString()} ${startDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}`;
                                } else if (endDate) {
                                    dateInfo = `Ends: ${endDate.toLocaleDateString()} ${endDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}`;
                                }
                                if (dateInfo) {
                                    dateInfoHtml = `
                                        <div style="margin-bottom: 20px;">
                                            <strong>Display Period:</strong> ${dateInfo}
                                        </div>
                                    `;
                                }
                            }
                            
                            modalContent.innerHTML = `
                                <div class="modal-header">
                                    <h2 class="modal-title">Review Announcement</h2>
                                    <button class="modal-close" onclick="closeModal('editAnnouncement')">&times;</button>
                                </div>
                                <div style="padding: 20px;">
                                    ${imageHtml}
                                    <div style="margin-bottom: 20px;">
                                        <strong>Title:</strong> ${announcement.title}
                                    </div>
                                    <div style="margin-bottom: 20px;">
                                        <strong>Posted By:</strong> ${announcement.posted_by_name || announcement.posted_by_username || announcement.posted_by_role || 'N/A'}
                                    </div>
                                    <div style="margin-bottom: 20px;">
                                        <strong>Category:</strong> ${announcement.category || 'General'}
                                    </div>
                                    ${scheduleHtml}
                                    ${dateInfoHtml}
                                    <div style="margin-bottom: 20px;">
                                        <strong>Date Submitted:</strong> ${date.toLocaleDateString()}
                                    </div>
                                    <div style="margin-bottom: 20px;">
                                        <strong>Content:</strong>
                                        <div style="background:#f5f5f5; padding:15px; border-radius:4px; margin-top:8px; white-space:pre-wrap;">${announcement.content}</div>
                                    </div>
                                    ${announcement.status === 'pending' ? `
                                        <div style="margin-bottom: 20px; display:none;" id="rejectionReasonSection">
                                            <label class="form-label">Rejection Reason *</label>
                                            <textarea class="form-control textarea" id="rejectionReason" rows="3" placeholder="Enter reason for rejecting this announcement"></textarea>
                                        </div>
                                        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:30px;">
                                            <button onclick="showRejectionReason()" class="btn" style="padding:10px 20px; background:#f44336; color:white; border:none; border-radius:4px; cursor:pointer;">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                            <button onclick="handleAnnouncementApproval('approve')" class="btn btn-primary" style="padding:10px 20px; background:#4CAF50; color:white; border:none; border-radius:4px; cursor:pointer;">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                        </div>
                                        <div style="display:none; margin-top:10px;" id="rejectSubmitSection">
                                            <button onclick="handleAnnouncementApproval('reject')" class="btn" style="padding:10px 20px; background:#f44336; color:white; border:none; border-radius:4px; cursor:pointer;">
                                                <i class="fas fa-times"></i> Submit Rejection
                                            </button>
                                            <button onclick="cancelRejection()" class="btn" style="padding:10px 20px; background:#f5f5f5; border:none; border-radius:4px; cursor:pointer; margin-left:10px;">Cancel</button>
                                        </div>
                                    ` : ''}
                                </div>
                            `;
                            modal.classList.add('active');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading announcement details');
                });
        }

        function showRejectionReason() {
            document.getElementById('rejectionReasonSection').style.display = 'block';
            document.getElementById('rejectSubmitSection').style.display = 'block';
        }

        function cancelRejection() {
            document.getElementById('rejectionReasonSection').style.display = 'none';
            document.getElementById('rejectSubmitSection').style.display = 'none';
            document.getElementById('rejectionReason').value = '';
        }

        function handleAnnouncementApproval(action) {
            if (!currentReviewAnnouncementId) return;
            
            if (action === 'reject') {
                const reason = document.getElementById('rejectionReason').value.trim();
                if (!reason) {
                    alert('Please provide a reason for rejection');
                    return;
                }
            }
            
            const formData = new FormData();
            formData.append('action', action);
            formData.append('announcement_id', currentReviewAnnouncementId);
            if (action === 'reject') {
                formData.append('rejection_reason', document.getElementById('rejectionReason').value);
            }
            
            fetch('approve_announcement.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeModal('editAnnouncement');
                    loadFDOAnnouncements();
                    
                    // Refresh dashboard stats and announcements preview if dashboard is active
                    const dashboardPage = document.getElementById('dashboard');
                    if (dashboardPage && dashboardPage.classList.contains('active')) {
                        loadDashboardStats();
                        loadDashboardAnnouncementsPreview();
                    }
                    
                    currentReviewAnnouncementId = null;
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error processing approval');
            });
        }

        function approveAnnouncement(id) {
            currentReviewAnnouncementId = id;
            handleAnnouncementApproval('approve');
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
                            
                            // Create modal if it doesn't exist
                            let modal = document.getElementById('viewPublishedAnnouncementModal');
                            if (!modal) {
                                modal = document.createElement('div');
                                modal.id = 'viewPublishedAnnouncementModal';
                                modal.className = 'announcement-modal-overlay';
                                modal.innerHTML = `
                                    <div class="announcement-modal-box">
                                        <div id="viewPublishedAnnouncementModalBody"></div>
                                        <button class="announcement-modal-close" onclick="closeViewPublishedAnnouncementModal()">Close</button>
                                    </div>
                                `;
                                document.body.appendChild(modal);
                            }
                            
                            document.getElementById('viewPublishedAnnouncementModalBody').innerHTML = `
                                ${imageHtml}
                                <div class="announcement-modal-title">${announcement.title}</div>
                                <div class="announcement-modal-subtitle">${subtitle}</div>
                                ${dateInfo ? `<div class="announcement-modal-date">${dateInfo}</div>` : ''}
                                <div class="announcement-modal-body">${formatAnnouncementContent(announcement.content)}</div>
                            `;
                            
                            modal.style.display = 'block';
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

        function closeViewPublishedAnnouncementModal() {
            const modal = document.getElementById('viewPublishedAnnouncementModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('viewPublishedAnnouncementModal');
            if (e.target === modal) {
                closeViewPublishedAnnouncementModal();
            }
        });

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
                    
                    // Schedule/Short description - match Pharmacist layout
                    let scheduleText = '';
                    if (ann.schedule && ann.schedule !== 'Not Applicable') {
                        scheduleText = ann.schedule;
                    } else if (startDate && endDate) {
                        scheduleText = `${startDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })} | ${startDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })} - ${endDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}`;
                    } else if (startDate) {
                        scheduleText = `${startDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })} | ${startDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}`;
                    } else {
                        scheduleText = ann.category || 'General';
                    }
                    
                    // Date information
                    let dateInfo = '';
                    if (startDate) {
                        dateInfo = `Starting on ${startDate.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}`;
                    } else {
                        dateInfo = `Posted on ${date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}`;
                    }
                    
                    return `
                        <div style="background:#fff;border-radius:12px;padding:20px;margin-bottom:16px;box-shadow:0 4px 16px rgba(0,0,0,0.08);border-left:4px solid #66BB6A;">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
                                <div>
                                    <div style="font-size:18px;font-weight:700;color:#1f2937;">${ann.title || 'N/A'}</div>
                                    <div style="color:#6b7280;font-size:14px;">${scheduleText}</div>
                                </div>
                                <button class="btn btn-primary" type="button" onclick="viewPublishedAnnouncement(${ann.announcement_id})">View More</button>
                            </div>
                            <div style="color:#6b7280;font-size:13px;margin-top:8px;">${dateInfo}</div>
                        </div>
                    `;
                }).join('');
            }
        }

        function rejectAnnouncement(id) {
            currentReviewAnnouncementId = id;
            viewAnnouncementForApproval(id);
            setTimeout(() => {
                showRejectionReason();
            }, 100);
        }

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

        // Store doctor mapping
        let doctorMapping = {}; // Maps doctor_id to doctor_name
        
        // Load doctors for schedule dropdown
        async function loadScheduleDoctors() {
            try {
                const response = await fetch('get_doctors.php');
                const data = await response.json();
                
                if (data.success && data.doctors) {
                    const select = document.getElementById('scheduleDoctorSelect');
                    if (select) {
                        // Keep "All Doctors" option
                        select.innerHTML = '<option value="">All Doctors</option>';
                        
                        // Add all doctors
                        data.doctors.forEach(doctor => {
                            const option = document.createElement('option');
                            option.value = doctor.id;
                            const doctorName = `Dr. ${doctor.doctor_name}`;
                            option.textContent = doctorName;
                            select.appendChild(option);
                            
                            // Store mapping
                            doctorMapping[doctor.id] = doctorName;
                        });
                    }
                }
            } catch (error) {
                console.error('Error loading doctors:', error);
            }
        }

        // Initialize schedule page
        function initializeSchedule() {
            // Load doctors first
            loadScheduleDoctors().then(() => {
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
            });
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
        
        // Get day name from date
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
            const selectedDoctorId = document.getElementById('scheduleDoctorSelect')?.value || '';
            
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
                try {
                    const url = `fdo_get_schedule.php?date=${date}${selectedDoctorId ? '&doctor_id=' + encodeURIComponent(selectedDoctorId) : ''}`;
                    const response = await fetch(url);
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update schedule data with appointments from database
                        // The API already filters by doctor_id if provided
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
            renderScheduleGridForWeek(weekValue, weekDates, selectedDoctorId);
            
            // Update summary
            updateScheduleSummaryForWeek(weekValue, weekDates);
        }
        
        // Load schedule for a specific date (kept for backward compatibility with modals)
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
                const response = await fetch(`fdo_get_schedule.php?date=${date}`);
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
        function renderScheduleGridForWeek(weekValue, weekDates, selectedDoctorId) {
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
                        // Display the reason in the cell text
                        const reasonText = block.reason || 'No reason';
                        cell.textContent = `Blocked: ${reasonText}`;
                        cell.title = `Blocked: ${reasonText}\n\nThis time is blocked by the doctor and cannot be edited.`;
                        // Make it non-clickable and show not-allowed cursor
                        cell.style.cursor = 'not-allowed';
                        // Don't set onclick handler - this prevents editing
                        // Store block data for reference
                        cell.setAttribute('data-block', JSON.stringify(block));
                        // Add a click handler that shows an alert instead of editing
                        cell.onclick = () => {
                            alert(`This time is blocked by the doctor.\n\nReason: ${reasonText}\n\nFDO cannot edit doctor-blocked times.`);
                        };
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
                        cell.onclick = () => editScheduleBlock(cell);
                    } else {
                        cell.onclick = () => editScheduleBlock(cell);
                    }
                    
                    column.appendChild(cell);
                });
                
                grid.appendChild(column);
            }
        }
        
        // Render the schedule grid for a date (kept for backward compatibility)
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
                    
                    cell.onclick = () => editScheduleBlock(cell);
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
        
        // Update schedule summary (kept for backward compatibility)
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
            
            const summaryDateElement = document.getElementById('summarySelectedDate');
            if (summaryDateElement) {
                summaryDateElement.textContent = formattedDate;
            }
            
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
            if (summaryNextAvailable) {
                summaryNextAvailable.textContent = nextAvailable;
            }
        }

        // Update modal dates to match selected date
        function updateModalDates(date) {
            const editScheduleDateInput = document.getElementById('editScheduleDate');
            
            if (editScheduleDateInput) editScheduleDateInput.value = date;
        }

        // View occupied slot details
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
            
            // Populate modal with appointment details
            document.getElementById('viewSlotDate').textContent = formattedDate;
            document.getElementById('viewSlotStartTime').textContent = appointment.startTime || '-';
            document.getElementById('viewSlotEndTime').textContent = appointment.endTime || '-';
            document.getElementById('viewSlotStatus').textContent = appointment.status || 'Occupied';
            document.getElementById('viewSlotPatientName').textContent = appointment.patientName || 'Unknown Patient';
            document.getElementById('viewSlotDoctorName').textContent = appointment.doctorName || 'Unassigned';
            
            // Open the modal
            openModal('viewOccupiedSlotModal');
        }
        
        function editScheduleBlock(element) {
            // Check if this is a doctor-blocked time (non-editable)
            const blockData = element.getAttribute('data-block');
            if (blockData) {
                try {
                    const block = JSON.parse(blockData);
                    // If it has a reason from doctor_blocked_times, it's not editable
                    if (block.reason && block.reason !== 'Edited Block') {
                        alert(`This time is blocked by the doctor. Reason: ${block.reason}\n\nFDO cannot edit doctor-blocked times.`);
                        return;
                    }
                } catch (e) {
                    // If parsing fails, continue normally
                }
            }
            
            // Highlight the clicked block
            document.querySelectorAll('.schedule-cell').forEach(cell => {
                cell.style.border = 'none';
            });
            element.style.border = '3px solid #4CAF50';
            
            // Get cell data
            const time = element.getAttribute('data-time');
            const column = element.getAttribute('data-column');
            const date = element.getAttribute('data-date');
            
            // Open edit modal and populate with current data
            openEditScheduleModal(time, column, date, element);
        }
        
        // Open edit schedule modal with pre-filled data
        function openEditScheduleModal(time, column, date, cellElement) {
            const data = scheduleData[date] || { blocks: [], appointments: [], availability: [] };
            
            // Data is already filtered by doctor_id from the API
            const blocks = data.blocks || [];
            const appointments = data.appointments || [];
            const availability = data.availability || [];
            
            // Find the schedule entry for this time slot
            // Note: In week view, column represents day (0-4), not doctor, so we don't filter by column
            let scheduleEntry = null;
            let entryType = null;
            
            // Check blocks
            // Convert times to minutes for proper comparison
            const timeMinutes = timeToMinutes(time);
            const block = blocks.find(b => {
                const startMinutes = timeToMinutes(b.startTime);
                const endMinutes = timeToMinutes(b.endTime);
                return startMinutes <= timeMinutes && endMinutes > timeMinutes;
            });
            if (block) {
                // Check if this is a doctor-blocked time (from doctor_blocked_times table)
                // Doctor-blocked times have a reason and should not be editable by FDO
                if (block.reason && block.reason !== 'Edited Block') {
                    alert(`This time is blocked by the doctor. Reason: ${block.reason}\n\nFDO cannot edit doctor-blocked times.`);
                    return;
                }
                scheduleEntry = block;
                entryType = 'block';
            }
            
            // Check appointments (but don't edit occupied slots - they should use view modal)
            const appointment = appointments.find(a => {
                const startMinutes = timeToMinutes(a.startTime);
                const endMinutes = timeToMinutes(a.endTime);
                return startMinutes <= timeMinutes && endMinutes > timeMinutes;
            });
            if (appointment) {
                // If it's an occupied slot, open view modal instead
                viewOccupiedSlot(cellElement, appointment);
                return;
            }
            
            // Check availability
            const avail = availability.find(a => {
                const startMinutes = timeToMinutes(a.startTime);
                const endMinutes = timeToMinutes(a.endTime);
                return startMinutes <= timeMinutes && endMinutes > timeMinutes;
            });
            if (avail) {
                scheduleEntry = avail;
                entryType = 'availability';
            }
            
            // Populate modal fields
            const dateInput = document.getElementById('editScheduleDate');
            const startTimeInput = document.getElementById('editScheduleStartTime');
            const endTimeInput = document.getElementById('editScheduleEndTime');
            const statusSelect = document.getElementById('editScheduleStatus');
            const patientNameInput = document.getElementById('editSchedulePatientName');
            const columnInput = document.getElementById('editScheduleColumn');
            const originalTimeInput = document.getElementById('editScheduleOriginalTime');
            
            if (dateInput) dateInput.value = date;
            if (columnInput) columnInput.value = column;
            
            if (scheduleEntry) {
                // Convert time from "H:MM AM/PM" to "HH:MM" for select dropdowns
                const startTime24 = convertTimeTo24Hour(scheduleEntry.startTime);
                const endTime24 = convertTimeTo24Hour(scheduleEntry.endTime);
                
                if (startTimeInput) {
                    startTimeInput.value = startTime24;
                    // Ensure the value is valid, if not set to empty
                    if (!isValidTimeSlot(startTime24)) {
                        startTimeInput.value = '';
                    }
                }
                if (endTimeInput) {
                    endTimeInput.value = endTime24;
                    // Ensure the value is valid, if not set to empty
                    if (!isValidTimeSlot(endTime24)) {
                        endTimeInput.value = '';
                    }
                }
                if (originalTimeInput) originalTimeInput.value = `${scheduleEntry.startTime}|${scheduleEntry.endTime}|${column}`;
                
                // Set status based on entry type
                if (statusSelect) {
                    if (entryType === 'block') {
                        statusSelect.value = 'Blocked';
                    } else if (entryType === 'appointment') {
                        statusSelect.value = scheduleEntry.status || 'Occupied';
                    } else if (entryType === 'availability') {
                        statusSelect.value = 'Available';
                    }
                }
                
                // Set patient name if it's an appointment
                if (patientNameInput && entryType === 'appointment') {
                    patientNameInput.value = scheduleEntry.patientName || '';
                }
            } else {
                // Default values if no entry found
                const time24 = convertTimeTo24Hour(time);
                if (startTimeInput) {
                    startTimeInput.value = isValidTimeSlot(time24) ? time24 : '';
                }
                // Default end time to next slot (30 minutes later)
                const nextTimeIndex = timeSlots.indexOf(time);
                if (nextTimeIndex >= 0 && nextTimeIndex < timeSlots.length - 1) {
                    const nextTime24 = convertTimeTo24Hour(timeSlots[nextTimeIndex + 1]);
                    if (endTimeInput) {
                        endTimeInput.value = isValidTimeSlot(nextTime24) ? nextTime24 : '';
                    }
                } else if (endTimeInput) {
                    endTimeInput.value = '';
                }
                if (statusSelect) statusSelect.value = 'Available';
                if (patientNameInput) patientNameInput.value = '';
                if (originalTimeInput) originalTimeInput.value = '';
            }
            
            // Open the modal
            openModal('editSchedule');
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

        // Save schedule edit
        function saveScheduleEdit(event) {
            event.preventDefault();
            
            const date = document.getElementById('editScheduleDate').value;
            const startTime = document.getElementById('editScheduleStartTime').value;
            const endTime = document.getElementById('editScheduleEndTime').value;
            const status = document.getElementById('editScheduleStatus').value;
            const patientName = document.getElementById('editSchedulePatientName').value;
            const column = parseInt(document.getElementById('editScheduleColumn').value) || 0;
            const originalTime = document.getElementById('editScheduleOriginalTime').value;
            
            if (!date || !startTime || !endTime || !status) {
                alert('Please fill in all required fields');
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
            
            // Convert time format (HH:MM to "H:MM AM/PM")
            const startTimeFormatted = formatTimeForDisplay(startTime);
            const endTimeFormatted = formatTimeForDisplay(endTime);
            
            // Initialize data for this date if needed
            if (!scheduleData[date]) {
                scheduleData[date] = { blocks: [], appointments: [], availability: [] };
            }
            
            // If there was an original entry, remove it first
            if (originalTime) {
                const [origStart, origEnd, origColumn] = originalTime.split('|');
                const origColumnNum = parseInt(origColumn) || 0;
                
                // Remove from blocks
                scheduleData[date].blocks = scheduleData[date].blocks.filter(b => 
                    !(b.startTime === origStart && b.endTime === origEnd && b.column === origColumnNum)
                );
                
                // Remove from appointments
                scheduleData[date].appointments = scheduleData[date].appointments.filter(a => 
                    !(a.startTime === origStart && a.endTime === origEnd && a.column === origColumnNum)
                );
                
                // Remove from availability
                scheduleData[date].availability = scheduleData[date].availability.filter(a => 
                    !(a.startTime === origStart && a.endTime === origEnd && a.column === origColumnNum)
                );
            }
            
            // Add the new/updated entry based on status
            if (status === 'Blocked') {
                scheduleData[date].blocks.push({
                    reason: 'Edited Block',
                    startTime: startTimeFormatted,
                    endTime: endTimeFormatted,
                    column: column
                });
            } else if (status === 'Occupied' || status === 'Pending') {
                scheduleData[date].appointments.push({
                    patientName: patientName || 'Patient',
                    status: status,
                    startTime: startTimeFormatted,
                    endTime: endTimeFormatted,
                    column: column,
                    isWalkIn: false
                });
            } else if (status === 'Available') {
                scheduleData[date].availability.push({
                    doctor: 'Doctor',
                    startTime: startTimeFormatted,
                    endTime: endTimeFormatted,
                    column: column
                });
            }
            
            showToast('Schedule updated successfully!');
            closeModal('editSchedule');
            
            // Refresh the grid to show changes
            loadScheduleForDate(date);
        }

        // Initialize schedule when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize schedule if we're on the schedule page
            if (document.getElementById('schedule') && document.getElementById('schedule').classList.contains('active')) {
                initializeSchedule();
            }
        });

        // Store appointment data globally (loaded from database)
        let appointmentDataStore = {};
        
        // Global filter state
        let currentDateFilter = 'all';
        let currentStatusFilter = 'all';
        
        // Appointment Validation: current appointment id for validate action
        let currentValidationAppointmentId = null;
        
        async function searchAppointmentByCode() {
            const input = document.getElementById('validationCodeInput');
            const code = (input && input.value) ? input.value.trim() : '';
            const msgEl = document.getElementById('validationMessage');
            const resultEl = document.getElementById('validationResult');
            const searchBtn = document.getElementById('validationSearchBtn');
            if (!code) {
                msgEl.style.display = 'block';
                msgEl.style.background = '#fff3cd';
                msgEl.style.color = '#856404';
                msgEl.textContent = 'Please enter an appointment code.';
                resultEl.style.display = 'none';
                return;
            }
            msgEl.style.display = 'none';
            resultEl.style.display = 'none';
            currentValidationAppointmentId = null;
            if (searchBtn) searchBtn.disabled = true;
            try {
                const response = await fetch('fdo_appointment_validation.php?code=' + encodeURIComponent(code));
                const data = await response.json();
                if (!data.success) {
                    msgEl.style.display = 'block';
                    msgEl.style.background = '#f8d7da';
                    msgEl.style.color = '#721c24';
                    msgEl.textContent = data.message || 'Search failed.';
                    return;
                }
                if (!data.found) {
                    msgEl.style.display = 'block';
                    msgEl.style.background = '#f8d7da';
                    msgEl.style.color = '#721c24';
                    msgEl.textContent = data.message || 'No appointment found for this code.';
                    return;
                }
                const apt = data.appointment;
                currentValidationAppointmentId = apt.id;
                document.getElementById('vCode').textContent = apt.appointment_code || '—';
                document.getElementById('vPatient').textContent = apt.patient_name || '—';
                document.getElementById('vDateTime').textContent = (apt.appointment_date || '') + ' at ' + (apt.appointment_time || '');
                document.getElementById('vDoctor').textContent = apt.doctor_name || '—';
                const statusSpan = document.getElementById('vStatus');
                statusSpan.textContent = (apt.status || '').toUpperCase();
                statusSpan.style.padding = '0.25rem 0.5rem';
                statusSpan.style.borderRadius = '4px';
                statusSpan.style.fontWeight = '600';
                if (apt.status === 'validated') {
                    statusSpan.style.background = '#d4edda';
                    statusSpan.style.color = '#155724';
                } else if (apt.status === 'approved' || apt.status === 'pending') {
                    statusSpan.style.background = '#cce5ff';
                    statusSpan.style.color = '#004085';
                } else {
                    statusSpan.style.background = '#f8f9fa';
                    statusSpan.style.color = '#333';
                }
                const validateBtn = document.getElementById('validateAppointmentBtn');
                if (apt.can_validate) {
                    validateBtn.style.display = 'inline-block';
                    validateBtn.disabled = false;
                } else {
                    validateBtn.style.display = 'none';
                    if (apt.already_validated) {
                        msgEl.style.display = 'block';
                        msgEl.style.background = '#d4edda';
                        msgEl.style.color = '#155724';
                        msgEl.textContent = 'This appointment is already validated.';
                    } else if (apt.blocked_status) {
                        msgEl.style.display = 'block';
                        msgEl.style.background = '#fff3cd';
                        msgEl.style.color = '#856404';
                        msgEl.textContent = 'Cannot validate: appointment is ' + (apt.status || '').toLowerCase() + '.';
                    } else if (!apt.is_appointment_date) {
                        msgEl.style.display = 'block';
                        msgEl.style.background = '#fff3cd';
                        msgEl.style.color = '#856404';
                        msgEl.textContent = 'Validation is only allowed on the appointment date.';
                    }
                }
                resultEl.style.display = 'block';
            } catch (e) {
                msgEl.style.display = 'block';
                msgEl.style.background = '#f8d7da';
                msgEl.style.color = '#721c24';
                msgEl.textContent = 'Error searching. Please try again.';
            } finally {
                if (searchBtn) searchBtn.disabled = false;
            }
        }
        
        async function validateAppointment() {
            if (!currentValidationAppointmentId) return;
            const btn = document.getElementById('validateAppointmentBtn');
            const msgEl = document.getElementById('validationMessage');
            if (btn) btn.disabled = true;
            const form = new FormData();
            form.append('action', 'validate');
            form.append('appointment_id', currentValidationAppointmentId);
            try {
                const response = await fetch('fdo_appointment_validation.php', { method: 'POST', body: form });
                const data = await response.json();
                if (data.success) {
                    msgEl.style.display = 'block';
                    msgEl.style.background = '#d4edda';
                    msgEl.style.color = '#155724';
                    msgEl.textContent = data.message || 'Appointment validated successfully.';
                    document.getElementById('vStatus').textContent = 'VALIDATED';
                    document.getElementById('vStatus').style.background = '#d4edda';
                    document.getElementById('vStatus').style.color = '#155724';
                    document.getElementById('validateAppointmentBtn').style.display = 'none';
                } else {
                    msgEl.style.display = 'block';
                    msgEl.style.background = '#f8d7da';
                    msgEl.style.color = '#721c24';
                    msgEl.textContent = data.message || 'Validation failed.';
                }
            } catch (e) {
                msgEl.style.display = 'block';
                msgEl.style.background = '#f8d7da';
                msgEl.style.color = '#721c24';
                msgEl.textContent = 'Error. Please try again.';
            } finally {
                if (btn) btn.disabled = false;
            }
        }
        
        // Initialize filters on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Set initial date filter dropdown value
            const dateDropdown = document.getElementById('dateFilterDropdown');
            if (dateDropdown && !dateDropdown.value) {
                dateDropdown.value = currentDateFilter || 'all';
            }
        });
        
        // Load appointments from database
        async function loadFDOAppointments() {
            try {
                const url = `fdo_get_appointments.php?date_filter=${currentDateFilter}&status_filter=${currentStatusFilter}`;
                const response = await fetch(url);
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.message || 'Failed to load appointments');
                }
                
                // Store appointments in global store
                appointmentDataStore = {};
                data.appointments.forEach(appt => {
                    // Use dependent_note field from backend (only set for actual dependents)
                    const patientName = appt.patient_name || 'Unknown Patient';
                    const dependentNote = appt.dependent_note || '';
                    
                    appointmentDataStore[appt.id] = {
                        id: appt.id,
                        patientName: patientName,
                        dependentNote: dependentNote,
                        date: appt.date || new Date(appt.start_datetime).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }),
                        time: appt.time || new Date(appt.start_datetime).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }),
                        assignedStaff: appt.doctor_name || 'Unassigned',
                        status: appt.status || 'pending',
                        notes: appt.notes || '',
                        phone: appt.patient_contact || 'N/A',
                        email: appt.patient_email || 'N/A',
                        declineReason: '',
                        doctor_id: appt.doctor_id || null,
                        user_id: appt.user_id || null,
                        patient_id: appt.patient_id || null
                    };
                });
                
                // Render appointments table
                renderFDOAppointmentsTable(data.appointments);
                
            } catch (error) {
                console.error('Error loading appointments:', error);
                const tbody = document.getElementById('fdoAppointmentsTableBody');
                if (tbody) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 30px; color: #dc2626;">
                                Error loading appointments: ${error.message}
                            </td>
                        </tr>`;
                }
            }
        }
        
        // Render appointments table
        function renderFDOAppointmentsTable(appointments) {
            const tbody = document.getElementById('fdoAppointmentsTableBody');
            if (!tbody) return;
            
            if (!Array.isArray(appointments) || appointments.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 30px; color: #6b7280;">
                            No appointments found.
                        </td>
                    </tr>`;
                return;
            }
            
            tbody.innerHTML = appointments.map(appt => {
                const date = appt.date || new Date(appt.start_datetime).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                const time = appt.time || new Date(appt.start_datetime).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
                const patientName = appt.patient_name || 'Unknown Patient';
                // Use dependent_note field from backend (only set for actual dependents)
                const dependentNote = appt.dependent_note || '';
                
                const doctorName = appt.doctor_name || 'Unassigned';
                const status = appt.status || 'pending';
                
                let statusBadge = '';
                if (status === 'pending') {
                    statusBadge = '<span class="status-badge status-pending">Pending</span>';
                } else if (status === 'approved') {
                    statusBadge = '<span class="status-badge status-approved">Approved</span>';
                } else if (status === 'completed') {
                    statusBadge = '<span class="status-badge status-completed">Completed</span>';
                } else if (status === 'declined') {
                    statusBadge = '<span class="status-badge" style="background: #ffebee; color: #c62828;">Declined</span>';
                } else if (status === 'cancelled') {
                    statusBadge = '<span class="status-badge" style="background: #f5f5f5; color: #666;">Cancelled</span>';
                } else {
                    statusBadge = `<span class="status-badge">${status}</span>`;
                }
                
                return `
                    <tr>
                        <td>
                            <div>${patientName}</div>
                            ${dependentNote ? `<div style="font-size: 0.75rem; color: #666; margin-top: 2px;">(Dependent of ${dependentNote})</div>` : ''}
                        </td>
                        <td>${date}</td>
                        <td>${time}</td>
                        <td>${doctorName}</td>
                        <td>${statusBadge}</td>
                        <td>
                            <button class="action-btn btn-view" onclick="viewAppointment(${appt.id})" title="View Appointment">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button class="action-btn btn-screening" onclick="openInitialScreeningModal(${appt.id})" title="Initial Screening" style="margin-left: 6px;">
                                <i class="fas fa-stethoscope"></i> Initial Screening
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // Initial Screening modal - open and load existing triage if any
        async function openInitialScreeningModal(appointmentId) {
            const appt = appointmentDataStore[appointmentId];
            if (!appt) {
                document.getElementById('screeningFormSummaryError').textContent = 'Appointment not found. Please refresh the page.';
                document.getElementById('screeningFormSummaryError').classList.add('is-visible');
                return;
            }
            document.getElementById('screeningFormSummaryError').classList.remove('is-visible');
            document.getElementById('screeningFormSummaryError').textContent = '';
            const modal = document.getElementById('initialScreeningModal');
            const form = document.getElementById('fdoInitialScreeningForm');
            if (!modal || !form) return;
            form.querySelector('#fdo_screening_appointment_id').value = appointmentId;
            form.querySelector('#fdo_screening_patient_id').value = appt.patient_id || '';
            form.querySelector('#fdo_screening_user_id').value = appt.user_id || '';
            form.querySelector('#fdo_screening_blood_pressure').value = '';
            form.querySelector('#fdo_screening_temperature_input').value = '';
            form.querySelector('#fdo_screening_temperature_c').value = '';
            form.querySelector('#fdo_screening_weight_input').value = '';
            form.querySelector('#fdo_screening_weight_kg').value = '';
            form.querySelector('#fdo_screening_temperature_unit').value = 'C';
            form.querySelector('#fdo_screening_weight_unit').value = 'kg';
            form.querySelector('#fdo_screening_pulse_rate').value = '';
            form.querySelector('#fdo_screening_oxygen_saturation').value = '';
            screeningClearFieldErrors();
            screeningUpdateTemperatureEmergency();
            try {
                const response = await fetch(`fdo_get_triage.php?appointment_id=${appointmentId}`);
                const data = await response.json();
                if (data.success && data.triage) {
                    const t = data.triage;
                    form.querySelector('#fdo_screening_blood_pressure').value = (t.blood_pressure || '').toString();
                    form.querySelector('#fdo_screening_temperature_input').value = (t.temperature != null && t.temperature !== '') ? String(t.temperature) : '';
                    form.querySelector('#fdo_screening_weight_input').value = (t.weight != null && t.weight !== '') ? String(t.weight) : '';
                    form.querySelector('#fdo_screening_pulse_rate').value = (t.pulse_rate != null && t.pulse_rate !== '') ? String(t.pulse_rate) : '';
                    form.querySelector('#fdo_screening_oxygen_saturation').value = (t.oxygen_saturation != null && t.oxygen_saturation !== '') ? String(t.oxygen_saturation) : '';
                    form.querySelector('#fdo_screening_temperature_unit').value = 'C';
                    form.querySelector('#fdo_screening_weight_unit').value = 'kg';
                }
            } catch (e) {
                console.error('Error loading triage:', e);
            }
            screeningUpdateBPEmergency();
            screeningUpdateTemperatureEmergency();
            screeningUpdatePulseWarning();
            screeningUpdateOxygenCritical();
            screeningUpdateSaveButtonState();
            document.getElementById('screeningEmergencyOverlay').classList.remove('is-visible');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeInitialScreeningModal() {
            const modal = document.getElementById('initialScreeningModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
            document.getElementById('screeningEmergencyOverlay').classList.remove('is-visible');
        }

        function screeningClearFieldErrors() {
            ['err_blood_pressure','err_temperature','err_weight','err_pulse_rate','err_oxygen_saturation'].forEach(function(id) {
                const el = document.getElementById(id);
                if (el) { el.classList.remove('is-visible'); el.textContent = ''; }
            });
            document.querySelectorAll('#fdoInitialScreeningForm .form-input.input-invalid').forEach(function(inp) { inp.classList.remove('input-invalid'); });
            document.getElementById('screeningFormSummaryError').classList.remove('is-visible');
            document.getElementById('screeningFormSummaryError').textContent = '';
        }

        function screeningShowError(fieldId, message) {
            const errEl = document.getElementById('err_' + fieldId);
            const inputIds = { blood_pressure: 'fdo_screening_blood_pressure', temperature: 'fdo_screening_temperature_input', weight: 'fdo_screening_weight_input', pulse_rate: 'fdo_screening_pulse_rate', oxygen_saturation: 'fdo_screening_oxygen_saturation' };
            const inputEl = document.getElementById(inputIds[fieldId] || ('fdo_screening_' + fieldId));
            if (errEl) { errEl.textContent = message; errEl.classList.add('is-visible'); }
            if (inputEl) inputEl.classList.add('input-invalid');
        }

        // Blood pressure: systolic 70–200, diastolic 40–130; auto slash after 2–3 digits; crisis at sys≥180 or dia≥120
        function screeningFormatBloodPressure(value) {
            var s = (value + '').replace(/[^\d\/]/g, '');
            var parts = s.split('/');
            if (parts.length > 2) parts = [parts[0], parts.slice(1).join('')];
            var sysPart = (parts[0] || '').slice(0, 3);
            var diaPart = (parts.length === 2 ? parts[1] : '');
            if (parts.length === 1 && sysPart.length === 3) {
                diaPart = '';
            } else if (parts.length === 1 && s.length > 3) {
                diaPart = s.slice(3).replace(/\D/g, '').slice(0, 3);
            }
            diaPart = diaPart.slice(0, 3);
            if (sysPart === '000') sysPart = '00';
            if (diaPart === '000') diaPart = '00';
            var sys = parseInt(sysPart, 10) || 0, dia = parseInt(diaPart, 10) || 0;
            if (sysPart && sys > 200) sysPart = '200';
            if (diaPart && dia > 130) diaPart = '130';
            if (sysPart && diaPart && dia >= (parseInt(sysPart, 10) || 0)) {
                var sysNum = parseInt(sysPart, 10);
                if (sysNum <= 40) diaPart = '';
                else diaPart = String(Math.min(parseInt(diaPart, 10), sysNum - 1)).slice(0, 3);
            }
            // Auto-add slash after 2 or 3 systolic digits so user can type diastolic without typing /
            if (diaPart) return sysPart + '/' + diaPart;
            if (sysPart.length >= 2) return sysPart + '/';
            return sysPart;
        }

        function screeningValidateBloodPressure() {
            var raw = document.getElementById('fdo_screening_blood_pressure').value.trim();
            if (!raw) return { valid: false, message: 'Blood pressure is required.' };
            var parts = raw.split('/');
            if (parts.length !== 2) return { valid: false, message: 'Enter blood pressure as systolic/diastolic (e.g. 120/80).' };
            var sys = parseInt(parts[0], 10), dia = parseInt(parts[1], 10);
            if (isNaN(sys) || isNaN(dia)) return { valid: false, message: 'Only numbers are allowed (e.g. 120/80).' };
            if (sys < 70 || sys > 200) return { valid: false, message: 'Systolic must be between 70 and 200 mmHg.' };
            if (dia < 40 || dia > 130) return { valid: false, message: 'Diastolic must be between 40 and 130 mmHg.' };
            if (dia >= sys) return { valid: false, message: 'Diastolic must be lower than systolic.' };
            return { valid: true, systolic: sys, diastolic: dia };
        }

        // Temperature: 35.0–42.0°C only; emergency (high fever) at ≥39°C
        function screeningFormatTemperature(value) {
            var unit = document.getElementById('fdo_screening_temperature_unit').value;
            var s = (value + '').replace(',', '.').replace(/[^\d.]/g, '');
            if ((s.match(/\./g) || []).length > 1) s = s.slice(0, s.indexOf('.') + 1) + s.slice(s.indexOf('.') + 1).replace(/\./g, '');
            var parts = s.split('.');
            var intPart = (parts[0] || '');
            var maxIntLen = unit === 'C' ? 2 : 3;
            if (intPart.length > 1 && intPart[0] === '0') intPart = '0' + intPart.slice(1).replace(/^0+/, '');
            intPart = intPart.slice(0, maxIntLen);
            var decPart = (parts[1] || '').slice(0, 1);
            var num = parseFloat(intPart + (decPart ? '.' + decPart : ''));
            if (!isNaN(num)) {
                var max = unit === 'C' ? 42 : 107.6;
                if (num > max) {
                    intPart = String(Math.floor(max));
                    decPart = unit === 'C' ? '0' : '6';
                }
            }
            return s.indexOf('.') !== -1 ? intPart + '.' + decPart : intPart;
        }

        function screeningValidateTemperature() {
            var unit = document.getElementById('fdo_screening_temperature_unit').value;
            var raw = document.getElementById('fdo_screening_temperature_input').value.trim().replace(',','.');
            if (!raw) return { valid: false, message: 'Temperature is required.' };
            var num = parseFloat(raw);
            if (isNaN(num) || raw.match(/\.\d{2,}/)) return { valid: false, message: 'Enter a valid number with at most one decimal (e.g. 36.5).' };
            var valueC = unit === 'C' ? num : (num - 32) * 5/9;
            if (valueC < 35 || valueC > 42) return { valid: false, message: 'Temperature must be between 35.0°C and 42.0°C.' };
            return { valid: true, valueC: valueC };
        }

        function screeningUpdateTemperatureEmergency() {
            var unit = document.getElementById('fdo_screening_temperature_unit').value;
            var raw = document.getElementById('fdo_screening_temperature_input').value.trim().replace(',','.');
            var wrap = document.getElementById('temperature_emergency_wrap');
            if (!wrap) return;
            var num = parseFloat(raw);
            if (isNaN(num) || raw === '') { wrap.classList.remove('is-visible'); return; }
            var c = unit === 'C' ? num : (num - 32) * 5/9;
            if (c >= 39) wrap.classList.add('is-visible');
            else wrap.classList.remove('is-visible');
        }

        // Weight: 2–300 kg only (lbs ~4.4–661)
        function screeningFormatWeight(value) {
            var unit = document.getElementById('fdo_screening_weight_unit').value;
            var s = (value + '').replace(',', '.').replace(/[^\d.]/g, '');
            if ((s.match(/\./g) || []).length > 1) s = s.slice(0, s.indexOf('.') + 1) + s.slice(s.indexOf('.') + 1).replace(/\./g, '');
            var parts = s.split('.');
            var intPart = (parts[0] || '');
            var maxIntLen = unit === 'kg' ? 3 : 3;
            if (intPart.length > 1 && intPart[0] === '0') intPart = '0' + intPart.slice(1).replace(/^0+/, '');
            intPart = intPart.slice(0, maxIntLen);
            var decPart = (parts[1] || '').slice(0, 1);
            var num = parseFloat(intPart + (decPart ? '.' + decPart : ''));
            if (!isNaN(num)) {
                var max = unit === 'kg' ? 300 : 661;
                if (num > max) {
                    intPart = String(max);
                    decPart = '';
                }
            }
            return s.indexOf('.') !== -1 ? intPart + '.' + decPart : intPart;
        }

        function screeningValidateWeight() {
            var unit = document.getElementById('fdo_screening_weight_unit').value;
            var raw = document.getElementById('fdo_screening_weight_input').value.trim().replace(',','.');
            if (!raw) return { valid: false, message: 'Weight is required.' };
            var num = parseFloat(raw);
            if (isNaN(num) || raw.match(/\.\d{2,}/)) return { valid: false, message: 'Enter a valid number with at most one decimal (e.g. 70.5).' };
            var minKg = 2, maxKg = 300, minLbs = 4.4, maxLbs = 661;
            if (unit === 'kg') {
                if (num < minKg || num > maxKg) return { valid: false, message: 'Weight must be between 2 and 300 kg.' };
            } else {
                if (num < minLbs || num > maxLbs) return { valid: false, message: 'Weight must be between 4.4 and 661 lbs.' };
            }
            var kg = unit === 'kg' ? num : num * 0.453592;
            return { valid: true, valueKg: kg };
        }

        // Pulse: 30–220 bpm; warning if <40 or >150
        function screeningFormatPulse(value) {
            var s = (value + '').replace(/\D/g, '').slice(0, 3);
            if (s.length > 1 && s[0] === '0') s = '0' + s.slice(1).replace(/^0+/, '');
            var num = parseInt(s, 10);
            if (!isNaN(num) && num > 220) s = '220';
            return s;
        }

        function screeningValidatePulse() {
            var raw = document.getElementById('fdo_screening_pulse_rate').value.trim();
            if (!raw) return { valid: false, message: 'Pulse rate is required.' };
            var num = parseInt(raw, 10);
            if (!/^\d+$/.test(raw) || isNaN(num)) return { valid: false, message: 'Pulse rate must be a number.' };
            if (num < 30 || num > 220) return { valid: false, message: 'Pulse rate must be between 30 and 220 bpm.' };
            return { valid: true, value: num };
        }

        function screeningUpdatePulseWarning() {
            var raw = document.getElementById('fdo_screening_pulse_rate').value.trim();
            var wrap = document.getElementById('pulse_warning_wrap');
            if (!wrap) return;
            var num = parseInt(raw, 10);
            if (raw === '' || isNaN(num)) { wrap.classList.remove('is-visible'); return; }
            if (num < 40 || num > 150) wrap.classList.add('is-visible');
            else wrap.classList.remove('is-visible');
        }

        // Oxygen (SpO2): 50–100%; critical at ≤90%. Only cap at 100 while typing (do not force 50 so user can type e.g. 98)
        function screeningFormatOxygen(value) {
            var s = (value + '').replace(',', '.').replace(/[^\d.]/g, '');
            if ((s.match(/\./g) || []).length > 1) s = s.slice(0, s.indexOf('.') + 1) + s.slice(s.indexOf('.') + 1).replace(/\./g, '');
            var parts = s.split('.');
            var intPart = (parts[0] || '').slice(0, 3);
            if (intPart.length > 1 && intPart[0] === '0') intPart = '0' + intPart.slice(1).replace(/^0+/, '');
            var decPart = (parts[1] || '').slice(0, 1);
            var num = parseFloat(intPart + (decPart ? '.' + decPart : ''));
            if (!isNaN(num) && num > 100) { intPart = '100'; decPart = ''; }
            return decPart ? intPart + '.' + decPart : intPart;
        }

        function screeningValidateOxygen() {
            var raw = document.getElementById('fdo_screening_oxygen_saturation').value.trim().replace(',','.');
            if (!raw) return { valid: false, message: 'Oxygen saturation is required.' };
            var num = parseFloat(raw);
            if (!/^\d+(\.\d{1,2})?$/.test(raw) || isNaN(num)) return { valid: false, message: 'Enter a number 50–100 (e.g. 98 or 98.5).' };
            if (num < 50 || num > 100) return { valid: false, message: 'Oxygen saturation must be between 50 and 100%.' };
            return { valid: true, value: num };
        }

        function screeningUpdateOxygenCritical() {
            var raw = document.getElementById('fdo_screening_oxygen_saturation').value.trim().replace(',','.');
            var wrap = document.getElementById('oxygen_critical_wrap');
            if (!wrap) return;
            var num = parseFloat(raw);
            if (raw === '' || isNaN(num)) { wrap.classList.remove('is-visible'); return; }
            if (num <= 90) wrap.classList.add('is-visible');
            else wrap.classList.remove('is-visible');
        }

        function screeningUpdateBPEmergency() {
            var raw = document.getElementById('fdo_screening_blood_pressure').value.trim();
            var wrap = document.getElementById('bp_emergency_wrap');
            if (!wrap) return;
            var parts = raw.split('/');
            if (parts.length !== 2) { wrap.classList.remove('is-visible'); return; }
            var sys = parseInt(parts[0], 10), dia = parseInt(parts[1], 10);
            if (isNaN(sys) || isNaN(dia)) { wrap.classList.remove('is-visible'); return; }
            if (sys >= 180 || dia >= 120) wrap.classList.add('is-visible');
            else wrap.classList.remove('is-visible');
        }

        function screeningValidateForm() {
            screeningClearFieldErrors();
            var hasError = false;
            var bp = screeningValidateBloodPressure();
            if (!bp.valid) { screeningShowError('blood_pressure', bp.message); hasError = true; }
            var temp = screeningValidateTemperature();
            if (!temp.valid) { screeningShowError('temperature', temp.message); hasError = true; }
            var weight = screeningValidateWeight();
            if (!weight.valid) { screeningShowError('weight', weight.message); hasError = true; }
            var pulse = screeningValidatePulse();
            if (!pulse.valid) { screeningShowError('pulse_rate', pulse.message); hasError = true; }
            var ox = screeningValidateOxygen();
            if (!ox.valid) { screeningShowError('oxygen_saturation', ox.message); hasError = true; }
            if (hasError) {
                document.getElementById('screeningFormSummaryError').textContent = 'Please correct the errors below.';
                document.getElementById('screeningFormSummaryError').classList.add('is-visible');
            }
            return !hasError;
        }

        function screeningIsFormValid() {
            var bp = screeningValidateBloodPressure();
            var temp = screeningValidateTemperature();
            var weight = screeningValidateWeight();
            var pulse = screeningValidatePulse();
            var ox = screeningValidateOxygen();
            return bp.valid && temp.valid && weight.valid && pulse.valid && ox.valid;
        }

        function screeningIsFormFilled() {
            var bp = (document.getElementById('fdo_screening_blood_pressure').value || '').trim();
            var temp = (document.getElementById('fdo_screening_temperature_input').value || '').trim();
            var weight = (document.getElementById('fdo_screening_weight_input').value || '').trim();
            var pulse = (document.getElementById('fdo_screening_pulse_rate').value || '').trim();
            var ox = (document.getElementById('fdo_screening_oxygen_saturation').value || '').trim();
            var bpOk = bp.indexOf('/') !== -1 && bp.split('/').length === 2;
            return bpOk && temp !== '' && weight !== '' && pulse !== '' && ox !== '';
        }

        function screeningUpdateSaveButtonState() {
            var btn = document.getElementById('fdo_screening_submit_btn');
            if (btn) btn.disabled = !screeningIsFormFilled();
        }

        (function initScreeningInputs() {
            var bp = document.getElementById('fdo_screening_blood_pressure');
            if (bp) {
                bp.addEventListener('input', function() {
                    this.value = screeningFormatBloodPressure(this.value);
                    document.getElementById('err_blood_pressure').classList.remove('is-visible');
                    this.classList.remove('input-invalid');
                    screeningUpdateBPEmergency();
                    screeningUpdateSaveButtonState();
                });
            }
            var tempInp = document.getElementById('fdo_screening_temperature_input');
            var tempUnit = document.getElementById('fdo_screening_temperature_unit');
            if (tempInp) {
                tempInp.addEventListener('input', function() {
                    this.value = screeningFormatTemperature(this.value);
                    screeningUpdateTemperatureEmergency();
                    document.getElementById('err_temperature').classList.remove('is-visible');
                    this.classList.remove('input-invalid');
                    screeningUpdateSaveButtonState();
                });
            }
            if (tempUnit) tempUnit.addEventListener('change', function() {
                var inp = document.getElementById('fdo_screening_temperature_input');
                inp.value = screeningFormatTemperature(inp.value);
                screeningUpdateTemperatureEmergency();
                screeningUpdateSaveButtonState();
            });
            var weightInp = document.getElementById('fdo_screening_weight_input');
            var weightUnit = document.getElementById('fdo_screening_weight_unit');
            if (weightInp) {
                weightInp.addEventListener('input', function() {
                    this.value = screeningFormatWeight(this.value);
                    document.getElementById('err_weight').classList.remove('is-visible');
                    this.classList.remove('input-invalid');
                    screeningUpdateSaveButtonState();
                });
            }
            if (weightUnit) weightUnit.addEventListener('change', function() {
                var inp = document.getElementById('fdo_screening_weight_input');
                inp.value = screeningFormatWeight(inp.value);
                screeningUpdateSaveButtonState();
            });
            var pulseInp = document.getElementById('fdo_screening_pulse_rate');
            if (pulseInp) {
                pulseInp.addEventListener('input', function() {
                    this.value = screeningFormatPulse(this.value);
                    document.getElementById('err_pulse_rate').classList.remove('is-visible');
                    this.classList.remove('input-invalid');
                    screeningUpdatePulseWarning();
                    screeningUpdateSaveButtonState();
                });
            }
            var oxInp = document.getElementById('fdo_screening_oxygen_saturation');
            if (oxInp) {
                oxInp.addEventListener('input', function() {
                    this.value = screeningFormatOxygen(this.value);
                    document.getElementById('err_oxygen_saturation').classList.remove('is-visible');
                    this.classList.remove('input-invalid');
                    screeningUpdateOxygenCritical();
                    screeningUpdateSaveButtonState();
                });
            }
        })();

        function getScreeningEmergencyInfo() {
            var parts = [];
            var bp = screeningValidateBloodPressure();
            if (bp.valid && (bp.systolic >= 180 || bp.diastolic >= 120))
                parts.push('Hypertensive Crisis Detected. Patient must be referred immediately to the nearest hospital.');
            var temp = screeningValidateTemperature();
            if (temp.valid && temp.valueC >= 39)
                parts.push('High Fever Detected. Patient requires urgent medical attention. Please refer to nearest hospital.');
            var ox = screeningValidateOxygen();
            if (ox.valid && ox.value <= 90)
                parts.push('Low SpO2 detected. Patient requires immediate referral to nearest hospital.');
            if (parts.length === 0) return { emergency: false };
            return { emergency: true, title: 'Emergency – Immediate Referral Required', message: parts.join(' ') };
        }

        async function performFDOInitialScreeningSave(forImmediateReferral) {
            const form = document.getElementById('fdoInitialScreeningForm');
            if (!form) return;
            var tempRes = screeningValidateTemperature();
            var weightRes = screeningValidateWeight();
            document.getElementById('fdo_screening_temperature_c').value = tempRes.valueC != null ? tempRes.valueC : (function(){
                var u = document.getElementById('fdo_screening_temperature_unit').value;
                var v = parseFloat(document.getElementById('fdo_screening_temperature_input').value);
                return u === 'C' ? v : (v - 32) * 5/9;
            })();
            document.getElementById('fdo_screening_weight_kg').value = weightRes.valueKg != null ? weightRes.valueKg : (function(){
                var u = document.getElementById('fdo_screening_weight_unit').value;
                var v = parseFloat(document.getElementById('fdo_screening_weight_input').value);
                return u === 'kg' ? v : v * 0.453592;
            })();
            const formData = new FormData(form);
            formData.append('action', 'save_triage');
            formData.append('for_immediate_referral', forImmediateReferral ? '1' : '0');
            var pulseVal = document.getElementById('fdo_screening_pulse_rate').value.trim();
            var oxVal = document.getElementById('fdo_screening_oxygen_saturation').value.trim();
            if (pulseVal) formData.set('pulse_rate', pulseVal);
            if (oxVal) formData.set('oxygen_saturation', oxVal);
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalHtml = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            screeningClearFieldErrors();
            document.getElementById('screeningEmergencyOverlay').classList.remove('is-visible');
            try {
                const response = await fetch('fdo_appointment_actions.php', { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success) {
                    closeInitialScreeningModal();
                    document.getElementById('initialScreeningSuccessOverlay').style.display = 'flex';
                    var patientId = (form.querySelector('#fdo_screening_patient_id').value || form.querySelector('#fdo_screening_user_id').value || '').trim();
                    if (patientId) {
                        setTimeout(function() {
                            window.location.href = 'doctor_consultation.php?patient_id=' + encodeURIComponent(patientId);
                        }, 1200);
                    }
                } else {
                    document.getElementById('screeningFormSummaryError').textContent = data.message || 'Failed to save.';
                    document.getElementById('screeningFormSummaryError').classList.add('is-visible');
                }
            } catch (err) {
                document.getElementById('screeningFormSummaryError').textContent = err.message || 'Failed to save initial screening.';
                document.getElementById('screeningFormSummaryError').classList.add('is-visible');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalHtml;
            }
        }

        async function saveFDOInitialScreening(event) {
            event.preventDefault();
            const form = document.getElementById('fdoInitialScreeningForm');
            if (!form) return;
            if (!screeningValidateForm()) return;
            var emergencyInfo = getScreeningEmergencyInfo();
            if (emergencyInfo.emergency) {
                document.getElementById('screeningEmergencyTitle').textContent = emergencyInfo.title;
                document.getElementById('screeningEmergencyMessage').textContent = emergencyInfo.message;
                document.getElementById('screeningEmergencyOverlay').classList.add('is-visible');
                return;
            }
            await performFDOInitialScreeningSave(false);
        }

        function closeInitialScreeningSuccessModal() {
            const overlay = document.getElementById('initialScreeningSuccessOverlay');
            if (overlay) {
                overlay.style.display = 'none';
                document.body.style.overflow = '';
            }
        }

        // Load reschedule requests
        async function loadRescheduleRequests() {
            try {
                const response = await fetch('fdo_get_reschedule_requests.php');
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.message || 'Failed to load reschedule requests');
                }
                
                const tbody = document.getElementById('fdoRescheduleRequestsBody');
                if (!tbody) return;
                
                if (data.requests.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 30px; color: #666;">No reschedule requests.</td></tr>';
                    return;
                }
                
                tbody.innerHTML = data.requests.map(request => {
                    const patientName = request.patient_name || 'Unknown Patient';
                    const originalDate = request.original_date || 'N/A';
                    const proposedDate = request.proposed_date || 'N/A';
                    const proposedTime = request.proposed_time || 'N/A';
                    const reason = request.follow_up_reason || 'N/A';
                    
                    return `
                        <tr>
                            <td>${patientName}</td>
                            <td>${originalDate}</td>
                            <td>${proposedDate}</td>
                            <td>${proposedTime}</td>
                            <td>${reason}</td>
                            <td>
                                <button class="action-btn btn-view" onclick="handleRescheduleRequest(${request.id})" title="Handle Reschedule">
                                    <i class="fas fa-calendar-check"></i> Handle Reschedule
                                </button>
                            </td>
                        </tr>
                    `;
                }).join('');
                
            } catch (error) {
                console.error('Error loading reschedule requests:', error);
                const tbody = document.getElementById('fdoRescheduleRequestsBody');
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 30px; color: #dc2626;">Error loading reschedule requests</td></tr>';
                }
            }
        }

        // Handle reschedule request - opens the same modal but pre-filled with reschedule request data
        async function handleRescheduleRequest(followUpId) {
            try {
                // Get reschedule request details
                const response = await fetch(`fdo_get_reschedule_requests.php`);
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.message || 'Failed to load reschedule request');
                }
                
                const request = data.requests.find(r => r.id === followUpId);
                if (!request) {
                    alert('Reschedule request not found.');
                    return;
                }
                
                // Set the follow-up ID in a hidden field
                document.getElementById('followUpOriginalAppointmentId').value = request.original_appointment_id || '';
                
                // Pre-fill the form with original proposed date/time
                if (request.proposed_datetime) {
                    const dt = new Date(request.proposed_datetime);
                    const dateStr = dt.toISOString().split('T')[0];
                    const timeStr = dt.toTimeString().slice(0, 5);
                    document.getElementById('followUpDate').value = dateStr;
                    document.getElementById('followUpTime').value = timeStr;
                }
                
                // Add a hidden field to track this is a reschedule
                let hiddenField = document.getElementById('rescheduleFollowUpId');
                if (!hiddenField) {
                    hiddenField = document.createElement('input');
                    hiddenField.type = 'hidden';
                    hiddenField.id = 'rescheduleFollowUpId';
                    document.getElementById('scheduleFollowUpForm').appendChild(hiddenField);
                }
                hiddenField.value = followUpId;
                
                // Open modal
                openScheduleFollowUpModal(request.original_appointment_id);
                
            } catch (error) {
                console.error('Error handling reschedule request:', error);
                alert('Error loading reschedule request. Please try again.');
            }
        }

        // View Appointment Details
        function viewAppointment(appointmentId) {
            const appointment = appointmentDataStore[appointmentId];
            if (!appointment) {
                alert('Appointment not found. Please refresh the page.');
                return;
            }

            const modal = document.getElementById('appointmentDetailsModal');
            const content = document.getElementById('appointmentDetailsContent');

            // Parse notes to extract reason and notes separately
            let appointmentReason = '';
            let appointmentNotesOnly = '';
            if (appointment.notes) {
                const reasonMatch = appointment.notes.match(/Reason:\s*([^\n]+)/i);
                if (reasonMatch) {
                    appointmentReason = reasonMatch[1].trim();
                }
                const notesMatch = appointment.notes.match(/Notes:\s*(.+)/is);
                if (notesMatch) {
                    appointmentNotesOnly = notesMatch[1].trim();
                    // Remove "[Dependent of: ...]" from notes if present
                    appointmentNotesOnly = appointmentNotesOnly.replace(/\s*\[Dependent of:\s*[^\]]+\]\s*/gi, '').trim();
                }
            }
            
            // Build content HTML
            let html = `
                <form id="appointmentStatusForm" onsubmit="${appointment.status === 'completed' ? 'event.preventDefault(); alert(\'Cannot modify completed appointments.\'); return false;' : 'saveAppointmentStatus(event, ' + appointmentId + ')'}">
                    <div style="margin-bottom: 20px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                            <div>
                                <strong style="color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Patient Name</strong>
                                <div style="margin: 8px 0 0 0;">
                                    <p style="margin: 0; font-size: 16px; color: #333; font-weight: 600;">${appointment.patientName}</p>
                                    ${appointment.dependentNote ? `<p style="margin: 4px 0 0 0; font-size: 12px; color: #888; font-style: italic;">(Dependent of ${appointment.dependentNote})</p>` : ''}
                                </div>
                            </div>
                            <div>
                                <strong style="color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Select Status</strong>
                                <input type="hidden" id="currentAppointmentStatus" value="${appointment.status}">
                                ${appointment.status === 'completed' ? `
                                    <select id="appointmentStatusSelect" name="status" class="form-input" disabled style="margin-top: 8px; width: 100%; padding: 10px; border: 2px solid #E0E0E0; border-radius: 8px; font-size: 14px; background: #E3F2FD; cursor: not-allowed; color: #1565C0; font-weight: 600;">
                                        <option value="completed" selected>Completed</option>
                                    </select>
                                    <small style="color: #1565C0; font-size: 11px; margin-top: 4px; display: block; font-style: italic;">
                                        <i class="fas fa-lock"></i> This appointment is completed and cannot be modified.
                                    </small>
                                ` : (appointment.status === 'approved' || appointment.status === 'declined') ? `
                                    <div id="finalizedStatusNote" style="margin-top: 8px; padding: 12px; background: #fff8e1; border: 2px solid #ffc107; border-radius: 8px; color: #5d4200;">
                                        <i class="fas fa-lock"></i> <strong>Status is finalized</strong> (${appointment.status === 'approved' ? 'Approved' : 'Declined'}). To change status, provide a reason below.
                                    </div>
                                    <select id="appointmentStatusSelect" name="status" class="form-input" disabled style="margin-top: 8px; width: 100%; padding: 10px; border: 2px solid #E0E0E0; border-radius: 8px; font-size: 14px; background: #f5f5f5; cursor: not-allowed;" onchange="toggleDeclineReason(this.value); toggleStatusChangeReason();">
                                        <option value="pending" ${appointment.status === 'pending' ? 'selected' : ''}>Pending</option>
                                        <option value="approved" ${appointment.status === 'approved' ? 'selected' : ''}>Approve Appointment</option>
                                        <option value="declined" ${appointment.status === 'declined' ? 'selected' : ''}>Decline Appointment</option>
                                    </select>
                                ` : `
                                    <select id="appointmentStatusSelect" name="status" class="form-input" style="margin-top: 8px; width: 100%; padding: 10px; border: 2px solid #E0E0E0; border-radius: 8px; font-size: 14px; background: #F8F9FA; cursor: pointer; transition: all 0.3s ease;" onchange="toggleDeclineReason(this.value); toggleStatusChangeReason();">
                                        <option value="pending" ${appointment.status === 'pending' ? 'selected' : ''}>Pending</option>
                                        <option value="approved" ${appointment.status === 'approved' ? 'selected' : ''}>Approve Appointment</option>
                                        <option value="declined" ${appointment.status === 'declined' ? 'selected' : ''}>Decline Appointment</option>
                                    </select>
                                `}
                            </div>
                        </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div>
                            <strong style="color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Appointment Date</strong>
                            <p style="margin: 8px 0 0 0; font-size: 16px; color: #333;">${appointment.date}</p>
                        </div>
                        <div>
                            <strong style="color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Time</strong>
                            <p style="margin: 8px 0 0 0; font-size: 16px; color: #333;">${appointment.time}</p>
                        </div>
                    </div>

                    ${appointmentReason ? `
                    <div style="margin-bottom: 20px;">
                        <strong style="color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Reason for Appointment</strong>
                        <p style="margin: 8px 0 0 0; padding: 15px; background: #f8f9fa; border-radius: 8px; color: #333;">${appointmentReason}</p>
                    </div>
                    ` : ''}

                    <div style="margin-bottom: 20px;">
                        <strong style="color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Assign Doctor</strong>
                        ${appointment.status === 'completed' ? `
                            <select id="assignDoctorSelect" name="doctor_id" class="form-input" disabled style="margin-top: 8px; width: 100%; padding: 10px; border: 2px solid #E0E0E0; border-radius: 8px; font-size: 14px; background: #E3F2FD; cursor: not-allowed;">
                                <option value="" selected>${appointment.assignedStaff || 'Unassigned'}</option>
                            </select>
                            <small style="color: #1565C0; font-size: 11px; margin-top: 4px; display: block;">Current: ${appointment.assignedStaff || 'Unassigned'}</small>
                        ` : `
                            <select id="assignDoctorSelect" name="doctor_id" class="form-input" style="margin-top: 8px; width: 100%; padding: 10px; border: 2px solid #E0E0E0; border-radius: 8px; font-size: 14px; background: #F8F9FA; cursor: pointer;">
                                <option value="">${appointment.assignedStaff === 'Unassigned' ? 'Select Doctor...' : appointment.assignedStaff}</option>
                            </select>
                            <small style="color: #999; font-size: 11px; margin-top: 4px; display: block;">Current: ${appointment.assignedStaff}</small>
                        `}
                    </div>

                    <div style="margin-bottom: 20px;">
                        <strong style="color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Contact Information</strong>
                        <div style="margin-top: 8px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                            <p style="margin: 5px 0; color: #333;"><i class="fas fa-phone" style="color: #4CAF50; margin-right: 8px;"></i>${appointment.phone}</p>
                            <p style="margin: 5px 0; color: #333;"><i class="fas fa-envelope" style="color: #4CAF50; margin-right: 8px;"></i>${appointment.email}</p>
                        </div>
                    </div>
                    ${appointmentNotesOnly ? `
                    <div style="margin-bottom: 20px;">
                        <strong style="color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Notes</strong>
                        <p style="margin: 8px 0 0 0; padding: 15px; background: #f8f9fa; border-radius: 8px; color: #333;">${appointmentNotesOnly}</p>
                    </div>
                    ` : ''}
                    ${!appointmentReason && !appointmentNotesOnly ? `
                    <div style="margin-bottom: 20px;">
                        <strong style="color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">Notes</strong>
                        <p style="margin: 8px 0 0 0; padding: 15px; background: #f8f9fa; border-radius: 8px; color: #999; font-style: italic;">No notes available</p>
                    </div>
                    ` : ''}

                    <!-- Decline Reason Field (shown when status is declined, hidden for completed) -->
                    <div id="declineReasonSection" style="margin-bottom: 20px; ${appointment.status === 'declined' && appointment.status !== 'completed' ? '' : 'display: none;'}">
                        <strong style="color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 8px;">Decline Reason *</strong>
                        <select id="declineReasonInput" name="declineReason" class="form-input" style="width: 100%; padding: 12px; border: 2px solid #E0E0E0; border-radius: 8px; font-size: 14px; background: #F8F9FA; cursor: pointer; transition: all 0.3s ease;" required>
                            <option value="">Select a reason...</option>
                            <option value="Doctor unavailable" ${appointment.declineReason === 'Doctor unavailable' ? 'selected' : ''}>Doctor unavailable</option>
                            <option value="Schedule conflict" ${appointment.declineReason === 'Schedule conflict' ? 'selected' : ''}>Schedule conflict</option>
                            <option value="Fully booked" ${appointment.declineReason === 'Fully booked' ? 'selected' : ''}>Fully booked</option>
                            <option value="Emergency situation" ${appointment.declineReason === 'Emergency situation' ? 'selected' : ''}>Emergency situation</option>
                            <option value="Requested time not available" ${appointment.declineReason === 'Requested time not available' ? 'selected' : ''}>Requested time not available</option>
                            <option value="Service not available" ${appointment.declineReason === 'Service not available' ? 'selected' : ''}>Service not available</option>
                            <option value="Other" ${appointment.declineReason && !['Doctor unavailable', 'Schedule conflict', 'Fully booked', 'Emergency situation', 'Requested time not available', 'Service not available'].includes(appointment.declineReason) ? 'selected' : ''}>Other</option>
                        </select>
                        <small style="color: #999; font-size: 12px; margin-top: 5px; display: block;">This reason will be visible to the patient.</small>
                        
                        <!-- Optional Alternative Schedule -->
                        <div id="alternativeScheduleSection" style="margin-top: 20px;">
                            <strong style="color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 8px;">Suggest Alternative Schedule (Optional)</strong>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div>
                                    <label style="color: #666; font-size: 12px; display: block; margin-bottom: 5px;">Date</label>
                                    <input type="date" id="alternativeDateInput" name="alternativeDate" class="form-input" style="width: 100%; padding: 10px; border: 2px solid #E0E0E0; border-radius: 8px; font-size: 14px; background: #F8F9FA;">
                                </div>
                                <div>
                                    <label style="color: #666; font-size: 12px; display: block; margin-bottom: 5px;">Time</label>
                                    <select id="alternativeTimeInput" name="alternativeTime" class="form-input" style="width: 100%; padding: 10px; border: 2px solid #E0E0E0; border-radius: 8px; font-size: 14px; background: #F8F9FA; cursor: pointer;">
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
                                        <option value="11:30">11:30 AM</option>
                                        <option value="13:00">1:00 PM</option>
                                        <option value="13:30">1:30 PM</option>
                                        <option value="14:00">2:00 PM</option>
                                        <option value="14:30">2:30 PM</option>
                                        <option value="15:00">3:00 PM</option>
                                    </select>
                                    <small style="color: #999; font-size: 11px; margin-top: 4px; display: block;">Available: 7:00 AM - 3:00 PM</small>
                                </div>
                            </div>
                            <small style="color: #999; font-size: 12px; margin-top: 5px; display: block;">If provided, this will be included in the notification to the patient.</small>
                        </div>
                    </div>

                    <!-- Display existing decline reason if declined -->
                    ${appointment.status === 'declined' && appointment.declineReason ? `
                        <div style="margin-bottom: 20px; padding: 15px; background: #ffebee; border-left: 4px solid #c62828; border-radius: 8px;">
                            <strong style="color: #c62828; font-size: 14px; display: block; margin-bottom: 8px;">Current Decline Reason:</strong>
                            <p style="margin: 0; color: #721c24;">${appointment.declineReason}</p>
                        </div>
                    ` : ''}

                    <!-- Reason for Status Change (required when changing status or when status is finalized) -->
                    <div id="statusChangeReasonSection" style="margin-bottom: 20px; ${(appointment.status === 'approved' || appointment.status === 'declined') ? '' : 'display: none;'}">
                        <strong style="color: #666; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 8px;">Reason for Status Change *</strong>
                        <p id="statusChangeReasonHelp" style="color: #666; font-size: 12px; margin-bottom: 8px;">Required when changing appointment status. This is recorded for transparency and patient communication.</p>
                        <select id="statusChangeReasonSelect" name="reason_for_change" class="form-input" style="width: 100%; padding: 12px; border: 2px solid #E0E0E0; border-radius: 8px; font-size: 14px; background: #F8F9FA; margin-bottom: 10px;" onchange="toggleStatusChangeReason();">
                            <option value="">Select a reason...</option>
                            <option value="Doctor unavailable">Doctor unavailable</option>
                            <option value="Schedule conflict">Schedule conflict</option>
                            <option value="Patient requested reschedule">Patient requested reschedule</option>
                            <option value="Emergency / clinic-related issue">Emergency / clinic-related issue</option>
                            <option value="System or administrative correction">System or administrative correction</option>
                            <option value="Other">Other</option>
                        </select>
                        <label style="color: #666; font-size: 12px; display: block; margin-bottom: 4px;">Additional explanation (optional)</label>
                        <textarea id="statusChangeReasonDetails" name="reason_details" rows="2" class="form-input" placeholder="Add any additional details..." style="width: 100%; padding: 10px; border: 2px solid #E0E0E0; border-radius: 8px; font-size: 14px; background: #F8F9FA; resize: none;" oninput="toggleStatusChangeReason();"></textarea>
                    </div>

                    <!-- Action Buttons -->
                    <div style="margin-top: 25px; padding-top: 20px; border-top: 2px solid #e0e0e0; display: flex; gap: 10px; justify-content: flex-end;">
                        <button type="button" class="btn-secondary" onclick="closeAppointmentModal()" style="padding: 12px 30px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; background: #F5F5F5; color: #666; border: none;">Cancel</button>
                        ${appointment.status === 'completed' ? `
                            <button type="button" disabled class="btn-primary" style="padding: 12px 30px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: not-allowed; background: #BBDEFB; color: #1565C0; border: none; opacity: 0.7;">
                                <i class="fas fa-lock"></i> Cannot Modify Completed Appointment
                            </button>
                        ` : `
                            <button type="submit" class="btn-primary" style="padding: 12px 30px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; background: linear-gradient(135deg, #4CAF50, #388E3C); color: white; border: none;">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        `}
                    </div>
                </form>
            `;

            content.innerHTML = html;
            modal.classList.add('active');
            
            // Load doctors list for assignment and pre-select current doctor
            loadDoctorsForAssignment(appointment.doctor_id || null);
            // Apply status lock / reason section state (e.g. disable status when finalized)
            setTimeout(function() { toggleStatusChangeReason(); }, 100);
            
            // Set date picker minimum date based on current time
            // If current time is 4:00 PM or later, disable today and set minDate to tomorrow
            const alternativeDateInput = document.getElementById('alternativeDateInput');
            if (alternativeDateInput) {
                const now = new Date();
                const currentHour = now.getHours();
                const currentMinute = now.getMinutes();
                const currentTime = currentHour * 60 + currentMinute; // Convert to minutes
                const fourPM = 16 * 60; // 4:00 PM in minutes
                
                if (currentTime >= fourPM) {
                    // Current time is 4:00 PM or later, set minDate to tomorrow
                    const tomorrow = new Date(now);
                    tomorrow.setDate(tomorrow.getDate() + 1);
                    const tomorrowStr = tomorrow.toISOString().split('T')[0];
                    alternativeDateInput.setAttribute('min', tomorrowStr);
                } else {
                    // Current time is before 4:00 PM, allow today
                    const todayStr = now.toISOString().split('T')[0];
                    alternativeDateInput.setAttribute('min', todayStr);
                }
            }
        }
        
        // Load doctors for assignment dropdown; optional currentDoctorId pre-selects that doctor
        async function loadDoctorsForAssignment(currentDoctorId) {
            try {
                const response = await fetch('get_doctors.php');
                const data = await response.json();
                
                if (data.success && data.doctors) {
                    const select = document.getElementById('assignDoctorSelect');
                    if (select) {
                        // Keep the first option (current/placeholder)
                        const firstOption = select.options[0];
                        select.innerHTML = '';
                        select.appendChild(firstOption);
                        
                        // Add all doctors
                        data.doctors.forEach(doctor => {
                            const option = document.createElement('option');
                            option.value = doctor.id;
                            option.textContent = `Dr. ${doctor.doctor_name}${doctor.specialization ? ' - ' + doctor.specialization : ''}`;
                            select.appendChild(option);
                        });
                        // Pre-select current doctor if provided
                        if (currentDoctorId && select.querySelector('option[value="' + currentDoctorId + '"]')) {
                            select.value = currentDoctorId;
                        }
                    }
                }
            } catch (error) {
                console.error('Error loading doctors:', error);
            }
        }

        // Toggle decline reason field visibility
        function toggleDeclineReason(status) {
            const declineSection = document.getElementById('declineReasonSection');
            const declineInput = document.getElementById('declineReasonInput');
            const alternativeDateInput = document.getElementById('alternativeDateInput');
            const alternativeTimeInput = document.getElementById('alternativeTimeInput');
            
            if (status === 'declined') {
                declineSection.style.display = 'block';
                declineInput.required = true;
            } else {
                declineSection.style.display = 'none';
                declineInput.required = false;
                declineInput.value = '';
                if (alternativeDateInput) alternativeDateInput.value = '';
                if (alternativeTimeInput) alternativeTimeInput.value = '';
            }
        }

        // Toggle "Reason for status change" section and enable/disable status dropdown when finalized
        function toggleStatusChangeReason() {
            const currentInput = document.getElementById('currentAppointmentStatus');
            const statusSelect = document.getElementById('appointmentStatusSelect');
            const reasonSection = document.getElementById('statusChangeReasonSection');
            const reasonSelect = document.getElementById('statusChangeReasonSelect');
            const reasonDetails = document.getElementById('statusChangeReasonDetails');
            if (!currentInput || !statusSelect) return;
            const currentStatus = currentInput.value;
            const selectedStatus = statusSelect.value;
            const isFinalized = (currentStatus === 'approved' || currentStatus === 'declined');
            const isChanging = (selectedStatus !== currentStatus);
            const hasReason = (reasonSelect && reasonSelect.value) || (reasonDetails && reasonDetails.value.trim());
            // When status is finalized: show reason section, disable status until reason is provided
            if (isFinalized) {
                if (reasonSection) reasonSection.style.display = 'block';
                statusSelect.disabled = !hasReason;
                statusSelect.style.cursor = hasReason ? 'pointer' : 'not-allowed';
                statusSelect.style.background = hasReason ? '#F8F9FA' : '#f5f5f5';
            } else {
                // When pending: first-time status change (Pending → Approve/Decline) does not show reason section; only 2nd+ change does (when current is already approved/declined, handled above)
                if (reasonSection) reasonSection.style.display = 'none';
            }
        }

        // Save appointment status
        function saveAppointmentStatus(event, appointmentId) {
            event.preventDefault();
            
            // Prevent changes to completed appointments
            const appointment = appointmentDataStore[appointmentId];
            if (appointment && appointment.status === 'completed') {
                alert('Cannot modify completed appointments. The status cannot be changed once an appointment is marked as completed.');
                return;
            }
            
            const status = document.getElementById('appointmentStatusSelect').value;
            const currentStatusInput = document.getElementById('currentAppointmentStatus');
            const currentStatus = currentStatusInput ? currentStatusInput.value : appointment.status;
            const declineReason = document.getElementById('declineReasonInput') ? document.getElementById('declineReasonInput').value : '';
            const alternativeDate = document.getElementById('alternativeDateInput') ? document.getElementById('alternativeDateInput').value : '';
            const alternativeTime = document.getElementById('alternativeTimeInput') ? document.getElementById('alternativeTimeInput').value : '';
            const reasonForChange = document.getElementById('statusChangeReasonSelect') ? document.getElementById('statusChangeReasonSelect').value : '';
            const reasonDetails = document.getElementById('statusChangeReasonDetails') ? document.getElementById('statusChangeReasonDetails').value.trim() : '';
            
            // Validate that a status is selected
            if (!status || status === '') {
                alert('Please select a status.');
                return;
            }
            
            // Doctor required when approving
            const doctorSelect = document.getElementById('assignDoctorSelect');
            const doctorId = doctorSelect ? doctorSelect.value : null;
            const hasDoctor = doctorId && doctorId !== '' || (appointment && appointment.doctor_id);
            if (status === 'approved' && !hasDoctor) {
                alert('Please assign a doctor before approving the appointment.');
                return;
            }
            
            // Prevent changing status to anything other than completed if already completed
            if (appointment && appointment.status === 'completed' && status !== 'completed') {
                alert('Cannot change the status of a completed appointment.');
                return;
            }
            
            // Validate decline reason if status is declined
            if (status === 'declined' && !declineReason.trim()) {
                alert('Please select a reason for declining this appointment.');
                return;
            }
            
            // Require reason only for 2nd (or later) status change: when current status is already approved/declined and user is changing it again
            const isChangingStatus = (status !== currentStatus);
            const isSecondOrLaterChange = (currentStatus === 'approved' || currentStatus === 'declined');
            if (isChangingStatus && isSecondOrLaterChange) {
                if (!reasonForChange && !reasonDetails) {
                    alert('Please provide a reason for this status change (select a reason and/or add an explanation).');
                    return;
                }
            }
            
            // Send data to server
            const formData = new FormData();
            
            // If approving and doctor is selected, use approve action with doctor assignment
            if (status === 'approved' && doctorId) {
                formData.append('action', 'approve');
                formData.append('appointment_id', appointmentId);
                formData.append('doctor_id', doctorId);
            } else if (status === 'approved' && appointment && appointment.doctor_id) {
                formData.append('action', 'approve');
                formData.append('appointment_id', appointmentId);
                formData.append('doctor_id', appointment.doctor_id);
            } else if (status === 'declined') {
                formData.append('action', 'decline');
                formData.append('appointment_id', appointmentId);
                formData.append('reason', declineReason);
                // Add optional alternative schedule if provided
                if (alternativeDate && alternativeTime) {
                    formData.append('suggested_date', alternativeDate);
                    formData.append('suggested_time', alternativeTime);
                }
            } else if (doctorId && status !== 'declined') {
                // Assign doctor separately if status is not approve/decline
                formData.append('action', 'assign_doctor');
                formData.append('appointment_id', appointmentId);
                formData.append('doctor_id', doctorId);
            } else {
                formData.append('action', 'update_status');
                formData.append('appointment_id', appointmentId);
                formData.append('status', status);
                if (isChangingStatus) {
                    formData.append('reason_for_change', reasonForChange);
                    formData.append('reason_details', reasonDetails);
                }
            }
            
            fetch('fdo_appointment_actions.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Update appointment data store
                    if (appointmentDataStore[appointmentId]) {
                        appointmentDataStore[appointmentId].status = status;
                        if (doctorId) {
                            appointmentDataStore[appointmentId].doctor_id = doctorId;
                        }
                        if (status === 'declined') {
                            appointmentDataStore[appointmentId].declineReason = declineReason;
                        } else {
                            appointmentDataStore[appointmentId].declineReason = '';
                        }
                    }
                    
                    // Reload appointments to get updated data
                    loadFDOAppointments();
                    
                    // Refresh dashboard stats if dashboard is active
                    const dashboardPage = document.getElementById('dashboard');
                    if (dashboardPage && dashboardPage.classList.contains('active')) {
                        loadDashboardStats();
                    }
                    
                    // Refresh schedule if schedule page is active and appointment was approved
                    const schedulePage = document.getElementById('schedule');
                    if (schedulePage && schedulePage.classList.contains('active') && status === 'approved') {
                        const weekPicker = document.getElementById('scheduleWeekPicker');
                        if (weekPicker && weekPicker.value) {
                            loadScheduleForWeek(weekPicker.value);
                        }
                    }
                    
                    showToast(data.message || 'Appointment status updated successfully!');
                    closeAppointmentModal();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating appointment status. Please try again. ' + error.message);
            });
        }

        // Update table row status badge
        function updateTableRowStatus(appointmentId, newStatus) {
            // Find the table row and update the status badge
            const table = document.querySelector('#appointments table tbody');
            if (!table) return;
            
            const rows = table.querySelectorAll('tr');
            rows.forEach((row) => {
                const viewButton = row.querySelector(`button[onclick*="viewAppointment(${appointmentId})"]`);
                if (viewButton) {
                    // Find the status cell (5th column, index 4)
                    const statusCell = row.cells[4];
                    if (statusCell) {
                        let statusBadge = '';
                        let badgeClass = '';
                        
                        if (newStatus === 'pending') {
                            statusBadge = 'Pending';
                            badgeClass = 'status-pending';
                        } else if (newStatus === 'approved') {
                            statusBadge = 'Approved';
                            badgeClass = 'status-approved';
                        } else if (newStatus === 'declined') {
                            statusBadge = 'Declined';
                            badgeClass = '';
                        } else if (newStatus === 'completed') {
                            statusBadge = 'Completed';
                            badgeClass = 'status-completed';
                        }
                        
                        if (newStatus === 'declined') {
                            statusCell.innerHTML = `<span class="status-badge" style="background: #ffebee; color: #c62828; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600;">${statusBadge}</span>`;
                        } else {
                            statusCell.innerHTML = `<span class="status-badge ${badgeClass}">${statusBadge}</span>`;
                        }
                    }
                }
            });
        }

        function closeAppointmentModal() {
            const modal = document.getElementById('appointmentDetailsModal');
            modal.classList.remove('active');
        }

        // Schedule Follow-Up Functions
        function openScheduleFollowUpModal(appointmentId) {
            const appointment = appointmentDataStore[appointmentId];
            if (!appointment) {
                alert('Appointment not found. Please refresh the page.');
                return;
            }

            // Set the original appointment ID
            document.getElementById('followUpOriginalAppointmentId').value = appointmentId;

            // Set minimum date to today
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('followUpDate').setAttribute('min', today);
            document.getElementById('altDate1').setAttribute('min', today);
            document.getElementById('altDate2').setAttribute('min', today);
            document.getElementById('altDate3').setAttribute('min', today);

            // Set maximum date to 1 week from today
            const maxDate = new Date();
            maxDate.setDate(maxDate.getDate() + 7);
            const maxDateStr = maxDate.toISOString().split('T')[0];
            document.getElementById('followUpDate').setAttribute('max', maxDateStr);
            document.getElementById('altDate1').setAttribute('max', maxDateStr);
            document.getElementById('altDate2').setAttribute('max', maxDateStr);
            document.getElementById('altDate3').setAttribute('max', maxDateStr);

            // Clear form
            document.getElementById('scheduleFollowUpForm').reset();
            document.getElementById('followUpOriginalAppointmentId').value = appointmentId;

            // Open modal
            const modal = document.getElementById('scheduleFollowUpModal');
            modal.classList.add('active');
        }

        function closeScheduleFollowUpModal() {
            const modal = document.getElementById('scheduleFollowUpModal');
            modal.classList.remove('active');
            document.getElementById('scheduleFollowUpForm').reset();
        }

        async function saveFollowUpAppointment(event) {
            event.preventDefault();

            const originalAppointmentId = document.getElementById('followUpOriginalAppointmentId').value;
            const rescheduleFollowUpId = document.getElementById('rescheduleFollowUpId')?.value || null;
            const followUpDate = document.getElementById('followUpDate').value;
            const followUpTime = document.getElementById('followUpTime').value;
            const notes = document.getElementById('followUpNotes').value;
            const altDate1 = document.getElementById('altDate1').value;
            const altTime1 = document.getElementById('altTime1').value;
            const altDate2 = document.getElementById('altDate2').value;
            const altTime2 = document.getElementById('altTime2').value;
            const altDate3 = document.getElementById('altDate3').value;
            const altTime3 = document.getElementById('altTime3').value;

            if (!followUpDate || !followUpTime) {
                alert('Please select a follow-up date and time.');
                return;
            }

            // Validate that dates are within 1 week
            const selectedDate = new Date(followUpDate);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const maxDate = new Date();
            maxDate.setDate(maxDate.getDate() + 7);
            maxDate.setHours(23, 59, 59, 999);

            if (selectedDate < today || selectedDate > maxDate) {
                alert('Follow-up date must be within 1 week from today.');
                return;
            }

            // Validate alternatives if provided
            const alternatives = [];
            if (altDate1 && altTime1) {
                const alt1Date = new Date(altDate1);
                if (alt1Date < today || alt1Date > maxDate) {
                    alert('Alternative 1 date must be within 1 week from today.');
                    return;
                }
                alternatives.push({ date: altDate1, time: altTime1 });
            }
            if (altDate2 && altTime2) {
                const alt2Date = new Date(altDate2);
                if (alt2Date < today || alt2Date > maxDate) {
                    alert('Alternative 2 date must be within 1 week from today.');
                    return;
                }
                alternatives.push({ date: altDate2, time: altTime2 });
            }
            if (altDate3 && altTime3) {
                const alt3Date = new Date(altDate3);
                if (alt3Date < today || alt3Date > maxDate) {
                    alert('Alternative 3 date must be within 1 week from today.');
                    return;
                }
                alternatives.push({ date: altDate3, time: altTime3 });
            }

            // Prepare form data
            const formData = new FormData();
            if (rescheduleFollowUpId) {
                formData.append('action', 'reschedule_followup');
                formData.append('follow_up_id', rescheduleFollowUpId);
            } else {
                formData.append('action', 'schedule_followup');
                formData.append('original_appointment_id', originalAppointmentId);
            }
            formData.append('follow_up_date', followUpDate);
            formData.append('follow_up_time', followUpTime);
            formData.append('notes', notes);
            formData.append('alternatives', JSON.stringify(alternatives));

            try {
                const response = await fetch('fdo_appointment_actions.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                if (data.success) {
                    showToast(data.message || 'Follow-up appointment scheduled successfully!');
                    closeScheduleFollowUpModal();
                    loadFDOAppointments();
                    loadRescheduleRequests();
                    // Clear reschedule follow-up ID if it was set
                    const rescheduleField = document.getElementById('rescheduleFollowUpId');
                    if (rescheduleField) {
                        rescheduleField.value = '';
                    }
                } else {
                    alert('Error: ' + (data.message || 'Failed to schedule follow-up appointment'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error scheduling follow-up appointment. Please try again. ' + error.message);
            }
        }
        
        // Filter functions
        function filterByDate(dateFilter) {
            currentDateFilter = dateFilter;
            
            // Update dropdown value
            const dateDropdown = document.getElementById('dateFilterDropdown');
            if (dateDropdown) {
                dateDropdown.value = dateFilter;
            }
            
            // Update URL without reloading page
            updateURLFilters();
            
            // Reload appointments
            loadFDOAppointments();
        }
        
        function filterByStatus(statusFilter) {
            currentStatusFilter = statusFilter;
            
            // Update URL without reloading page
            updateURLFilters();
            
            // Reload appointments
            loadFDOAppointments();
        }
        
        // Update URL with current filters
        function updateURLFilters() {
            const url = new URL(window.location);
            url.searchParams.set('date', currentDateFilter);
            url.searchParams.set('status', currentStatusFilter);
            window.history.pushState({}, '', url);
        }


        // Close modal when clicking outside
        document.getElementById('appointmentDetailsModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeAppointmentModal();
            }
        });

        // Close follow-up modal when clicking outside
        document.getElementById('scheduleFollowUpModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeScheduleFollowUpModal();
            }
        });

        document.getElementById('initialScreeningModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeInitialScreeningModal();
            }
        });

        document.getElementById('screeningEmergencyCancelBtn')?.addEventListener('click', function() {
            document.getElementById('screeningEmergencyOverlay').classList.remove('is-visible');
        });
        document.getElementById('screeningEmergencyConfirmBtn')?.addEventListener('click', function() {
            performFDOInitialScreeningSave(true);
        });
        document.getElementById('screeningEmergencyOverlay')?.addEventListener('click', function(e) {
            if (e.target === this) document.getElementById('screeningEmergencyOverlay').classList.remove('is-visible');
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const screeningModal = document.getElementById('initialScreeningModal');
                if (screeningModal && screeningModal.style.display === 'flex') {
                    closeInitialScreeningModal();
                }
                const successOverlay = document.getElementById('initialScreeningSuccessOverlay');
                if (successOverlay && successOverlay.style.display === 'flex') {
                    closeInitialScreeningSuccessModal();
                }
                const emergencyOverlay = document.getElementById('screeningEmergencyOverlay');
                if (emergencyOverlay && emergencyOverlay.classList.contains('is-visible')) {
                    emergencyOverlay.classList.remove('is-visible');
                }
            }
        });

        // New Appointment Functions
        let patientSearchTimeout;
        let allPatients = [];
        let allDoctors = [];

        // Load doctors when modal opens
        document.getElementById('newAppointment')?.addEventListener('click', function(e) {
            if (e.target === this || e.target.closest('.modal-content') === null) {
                loadDoctors();
            }
        });

        async function loadDoctors() {
            try {
                const response = await fetch('get_doctors.php');
                const data = await response.json();
                if (data.success) {
                    allDoctors = data.doctors;
                    const select = document.getElementById('apptDoctor');
                    if (select) {
                        select.innerHTML = '<option value="">Unassigned</option>';
                        data.doctors.forEach(doctor => {
                            const option = document.createElement('option');
                            option.value = doctor.id;
                            option.textContent = doctor.doctor_name || `Dr. ${doctor.specialization || 'Unknown'}`;
                            select.appendChild(option);
                        });
                    }
                }
            } catch (error) {
                console.error('Error loading doctors:', error);
            }
        }

        function searchPatients(query) {
            clearTimeout(patientSearchTimeout);
            const dropdown = document.getElementById('patientDropdown');
            
            if (!query || query.length < 2) {
                dropdown.style.display = 'none';
                return;
            }

            patientSearchTimeout = setTimeout(async () => {
                try {
                    const response = await fetch(`fdo_get_patients.php?search=${encodeURIComponent(query)}`);
                    const data = await response.json();
                    
                    if (data.success && data.patients) {
                        allPatients = data.patients;
                        displayPatientResults(data.patients);
                    }
                } catch (error) {
                    console.error('Error searching patients:', error);
                }
            }, 300);
        }

        function displayPatientResults(patients) {
            const dropdown = document.getElementById('patientDropdown');
            dropdown.innerHTML = '';
            
            if (patients.length === 0) {
                dropdown.innerHTML = '<div style="padding:10px; color:#999;">No patients found</div>';
                dropdown.style.display = 'block';
                return;
            }

            patients.forEach(patient => {
                const item = document.createElement('div');
                item.style.cssText = 'padding:10px; cursor:pointer; border-bottom:1px solid #eee;';
                item.onmouseover = () => item.style.background = '#f5f5f5';
                item.onmouseout = () => item.style.background = 'white';
                item.onclick = () => selectPatient(patient);
                
                const name = document.createElement('div');
                name.style.fontWeight = '600';
                name.textContent = patient.full_name;
                
                const info = document.createElement('div');
                info.style.fontSize = '12px';
                info.style.color = '#666';
                info.style.marginTop = '4px';
                if (patient.patient_type === 'dependent' && patient.parent_name) {
                    info.textContent = `Dependent of ${patient.parent_name}`;
                } else {
                    info.textContent = 'Registered Patient';
                }
                
                item.appendChild(name);
                item.appendChild(info);
                dropdown.appendChild(item);
            });
            
            dropdown.style.display = 'block';
        }

        function selectPatient(patient) {
            document.getElementById('selectedPatientId').value = patient.id;
            document.getElementById('selectedPatientType').value = patient.patient_type;
            document.getElementById('apptFirstName').value = patient.first_name || '';
            document.getElementById('apptMiddleName').value = patient.middle_name || '';
            document.getElementById('apptLastName').value = patient.last_name || '';
            document.getElementById('apptPhone').value = patient.phone || '';
            
            document.getElementById('patientSearchInput').value = patient.full_name;
            document.getElementById('selectedPatientName').textContent = patient.full_name + (patient.patient_type === 'dependent' && patient.parent_name ? ` (Dependent of ${patient.parent_name})` : '');
            document.getElementById('selectedPatientInfo').style.display = 'block';
            document.getElementById('patientDropdown').style.display = 'none';
        }

        function clearPatientSelection() {
            document.getElementById('selectedPatientId').value = '';
            document.getElementById('selectedPatientType').value = '';
            document.getElementById('patientSearchInput').value = '';
            document.getElementById('selectedPatientInfo').style.display = 'none';
            document.getElementById('apptFirstName').value = '';
            document.getElementById('apptMiddleName').value = '';
            document.getElementById('apptLastName').value = '';
            document.getElementById('apptPhone').value = '';
        }

        // Close patient dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const dropdown = document.getElementById('patientDropdown');
            const input = document.getElementById('patientSearchInput');
            if (dropdown && input && !dropdown.contains(e.target) && !input.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });

        // Handle reason selection
        document.getElementById('apptReason')?.addEventListener('change', function() {
            const otherContainer = document.getElementById('otherReasonContainer');
            if (this.value === 'others') {
                otherContainer.style.display = 'block';
                document.getElementById('apptOtherReason').required = true;
            } else {
                otherContainer.style.display = 'none';
                document.getElementById('apptOtherReason').required = false;
                document.getElementById('apptOtherReason').value = '';
            }
        });

        // Submit FDO appointment form
        async function submitFDOAppointment(event) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            
            // Get reason value
            const reason = formData.get('reason');
            if (reason === 'others') {
                formData.set('reason', formData.get('other_reason') || '');
            }
            formData.delete('other_reason');
            
            try {
                const response = await fetch('fdo_create_appointment.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showToast('Appointment created successfully!');
                    closeModal('newAppointment');
                    form.reset();
                    clearPatientSelection();
                    document.getElementById('otherReasonContainer').style.display = 'none';
                    loadFDOAppointments(); // Refresh appointments list
                    
                    // Refresh dashboard stats if dashboard is active
                    const dashboardPage = document.getElementById('dashboard');
                    if (dashboardPage && dashboardPage.classList.contains('active')) {
                        loadDashboardStats();
                    }
                } else {
                    alert('Error: ' + (data.message || 'Failed to create appointment'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error creating appointment. Please try again.');
            }
        }
    </script>
</body>
</html>