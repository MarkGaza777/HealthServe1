<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: Login.php");
    exit();
}

// Get doctor statistics
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_doctors FROM doctors");
    $total_doctors = $stmt->fetchColumn();
    
    // Ensure doctor_blocked_times table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS doctor_blocked_times (
            id INT AUTO_INCREMENT PRIMARY KEY,
            doctor_id INT NOT NULL,
            reason VARCHAR(255) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE,
            INDEX idx_doctor_dates (doctor_id, start_date, end_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Count doctors on leave today
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT d.id) as on_leave_count
        FROM doctors d
        INNER JOIN doctor_blocked_times dbt ON d.id = dbt.doctor_id
        WHERE dbt.reason = 'On Leave'
        AND dbt.start_date <= ?
        AND dbt.end_date >= ?
    ");
    $stmt->execute([$today, $today]);
    $on_leave_count = $stmt->fetchColumn();
    
} catch(PDOException $e) {
    $error = "Error fetching doctor data: " . $e->getMessage();
    $total_doctors = 0;
    $on_leave_count = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctors Management - HealthServe Admin</title>
    <link rel="stylesheet" href="assets/Style1.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="custom_modal.js"></script>
    <script src="custom_notification.js"></script>
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
                    <a href="admin_staff_management.php" class="nav-link">
                        <div class="nav-icon"><i class="fas fa-user-friends"></i></div>
                        Staffs
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_doctors_management.php" class="nav-link active">
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
                <h2 class="page-title">Doctors Management</h2>
                <div class="breadcrumb">Dashboard > Doctors</div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon-wrapper staff">
                        <i class="fas fa-user-md"></i>
                    </div>
                    <div class="stat-details">
                        <h3 id="totalDoctorsCount"><?php echo $total_doctors; ?></h3>
                        <p>Total Doctors</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon-wrapper patients">
                        <i class="fas fa-calendar-times"></i>
                    </div>
                    <div class="stat-details">
                        <h3 id="onLeaveCount"><?php echo $on_leave_count ?? 0; ?></h3>
                        <p>On Leave</p>
                    </div>
                </div>
            </div>

            <!-- Doctors Table Section -->
            <div class="content-section">
                <div class="section-header">
                    <div class="search-filter">
                        <div class="search-box">
                            <i class="fas fa-search" style="color: #81C784;"></i>
                            <input type="text" placeholder="Search by name, specialty, or ID" id="doctorSearch">
                        </div>
                    </div>
                    <button class="add-btn" onclick="openNewDoctorModal()">
                        <i class="fas fa-plus"></i>
                        <span>New Doctor</span>
                    </button>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Specialty</th>
                            <th>Contact</th>
                            <th>Patients Today</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="doctorsTable">
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 30px; color: #666;">
                                Loading doctors...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <style>
        /* Doctor-specific styles */
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
            flex-shrink: 0;
        }

        .content-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            margin-top: 30px;
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

        .filter-btn {
            padding: 10px 20px;
            border: 2px solid #4CAF50;
            background: white;
            color: #4CAF50;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-btn:hover {
            background: #4CAF50;
            color: white;
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

        .stat-icon-wrapper.staff {
            background: linear-gradient(135deg, #26A69A, #00897B);
        }

        .stat-icon-wrapper.patients {
            background: linear-gradient(135deg, #42A5F5, #1E88E5);
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

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
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

        .action-btn.btn-edit {
            background: #fff3e0;
            color: #f57c00;
            border: 1px solid rgba(245, 124, 0, 0.2);
        }

        .action-btn.btn-edit:hover {
            background: #ffe0b2;
            color: #ef6c00;
        }

        .action-btn.btn-delete {
            background: #ffebee;
            color: #c62828;
            border: 1px solid rgba(198, 40, 40, 0.2);
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
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            overflow-y: auto;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content-box {
            position: relative;
            background: white;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.25);
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #E0E0E0;
            padding-bottom: 15px;
        }
        
        .modal-header h3 {
            color: #2E7D32;
            margin: 0;
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-close {
            font-size: 28px;
            cursor: pointer;
            color: #999;
            background: #F3F4F6;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .modal-close:hover {
            background: #E0E0E0;
        }
        
        .modal-body {
            margin-bottom: 20px;
        }
        
        .info-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid #F0F0F0;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #666;
            width: 150px;
            flex-shrink: 0;
        }
        
        .info-value {
            color: #333;
            flex: 1;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #E0E0E0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #F8F9FA;
            box-sizing: border-box;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #66BB6A;
            background: white;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid #E0E0E0;
        }
        
        .btn-save {
            background: #2E7D32;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-save:hover {
            background: #388E3C;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 125, 50, 0.3);
        }
        
        .btn-cancel {
            background: #F5F5F5;
            color: #666;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-cancel:hover {
            background: #E0E0E0;
        }
        
        .leave-dates-list {
            margin-top: 10px;
        }
        
        .leave-date-item {
            background: #FFF3E0;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 8px;
            font-size: 13px;
            color: #F57C00;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-badge.status-active {
            background: #E8F5E8;
            color: #2E7D32;
        }

        .status-badge.status-inactive {
            background: #FFF3E0;
            color: #F57C00;
        }
    </style>

    <script>
        // Load doctors data
        async function loadDoctors() {
            try {
                const response = await fetch('admin_get_doctors.php');
                const data = await response.json();
                
                if (data.success) {
                    // Update stats
                    if (data.stats) {
                        document.getElementById('totalDoctorsCount').textContent = data.stats.total_doctors || 0;
                        document.getElementById('onLeaveCount').textContent = data.stats.on_leave || 0;
                    }
                    
                    // Render doctors table
                    renderDoctorsTable(data.doctors);
                } else {
                    document.getElementById('doctorsTable').innerHTML = `
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 30px; color: #dc2626;">
                                Error: ${data.message || 'Failed to load doctors'}
                            </td>
                        </tr>
                    `;
                }
            } catch (error) {
                console.error('Error loading doctors:', error);
                document.getElementById('doctorsTable').innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 30px; color: #dc2626;">
                            Error loading doctors. Please refresh the page.
                        </td>
                    </tr>
                `;
            }
        }

        // Render doctors table
        function renderDoctorsTable(doctors) {
            const tbody = document.getElementById('doctorsTable');
            
            if (!doctors || doctors.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 30px; color: #666;">
                            No doctors found.
                        </td>
                    </tr>
                `;
                return;
            }
            
            tbody.innerHTML = doctors.map(doctor => {
                // Get initials for avatar
                const nameParts = (doctor.doctor_name || '').split(' ').filter(p => p);
                let initials = '';
                if (nameParts.length >= 2) {
                    initials = (nameParts[0][0] || '') + (nameParts[nameParts.length - 1][0] || '');
                } else if (nameParts.length === 1) {
                    initials = nameParts[0].substring(0, 2).toUpperCase();
                } else {
                    initials = 'DR';
                }
                initials = initials.toUpperCase();
                
                // Format doctor name
                const formattedName = doctor.doctor_name ? `Dr. ${doctor.doctor_name}` : 'Unknown Doctor';
                
                // Status badge
                const status = (doctor.status || 'active').toLowerCase();
                let statusClass, statusText, statusTooltip = '';
                if (status === 'on_leave') {
                    statusClass = 'status-inactive';
                    statusText = 'On Leave';
                    if (doctor.leave_start && doctor.leave_end) {
                        const startDate = new Date(doctor.leave_start).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                        const endDate = new Date(doctor.leave_end).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                        statusTooltip = ` title="Leave: ${startDate} - ${endDate}"`;
                    }
                } else {
                    statusClass = 'status-active';
                    statusText = 'Active';
                }
                
                // Patients today
                const patientsToday = doctor.patients_today || 0;
                
                return `
                    <tr>
                        <td>
                            <div class="doctor-info">
                                <div class="doctor-avatar">${initials}</div>
                                <span>${formattedName}</span>
                            </div>
                        </td>
                        <td>${doctor.specialization || 'N/A'}</td>
                        <td>${doctor.contact || 'N/A'}</td>
                        <td>${patientsToday}</td>
                        <td><span class="status-badge ${statusClass}"${statusTooltip}>${statusText}</span></td>
                        <td>
                            <div class="action-buttons">
                                <button class="action-btn btn-view" onclick="viewDoctor(${doctor.id})" title="View Doctor">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="action-btn btn-edit" onclick="editDoctor(${doctor.id})" title="Edit Doctor">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="action-btn btn-delete" onclick="deleteDoctor(${doctor.id}, '${formattedName}')" title="Delete Doctor">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // Search functionality
        document.getElementById('doctorSearch')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#doctorsTable tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // Action functions
        async function viewDoctor(id) {
            try {
                const response = await fetch(`admin_get_doctor_details.php?id=${id}`);
                const data = await response.json();
                
                if (data.success) {
                    const doctor = data.doctor;
                    const formattedName = doctor.doctor_name ? `Dr. ${doctor.doctor_name}` : 'Unknown Doctor';
                    
                    // Format leave dates
                    let leaveDatesHtml = '';
                    if (doctor.leave_dates && doctor.leave_dates.length > 0) {
                        leaveDatesHtml = '<div class="leave-dates-list">';
                        doctor.leave_dates.forEach(leave => {
                            const startDate = new Date(leave.start_date).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                            const endDate = new Date(leave.end_date).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                            leaveDatesHtml += `<div class="leave-date-item">${startDate} - ${endDate}</div>`;
                        });
                        leaveDatesHtml += '</div>';
                    } else {
                        leaveDatesHtml = '<div style="color: #999; font-style: italic;">No upcoming leave dates</div>';
                    }
                    
                    const modalContent = `
                        <div class="modal-header">
                            <h3><i class="fas fa-user-md"></i> Doctor Details</h3>
                            <button class="modal-close" onclick="closeModal('viewDoctorModal')">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="info-row">
                                <div class="info-label">Name:</div>
                                <div class="info-value">${formattedName}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Specialty:</div>
                                <div class="info-value">${doctor.specialization || 'N/A'}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Clinic Room:</div>
                                <div class="info-value">${doctor.clinic_room || 'N/A'}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Contact:</div>
                                <div class="info-value">${doctor.contact || 'N/A'}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Email:</div>
                                <div class="info-value">${doctor.email || 'N/A'}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Address:</div>
                                <div class="info-value">${doctor.address || 'N/A'}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Patients Today:</div>
                                <div class="info-value">${doctor.patients_today || 0}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Total Appointments:</div>
                                <div class="info-value">${doctor.total_appointments || 0}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Upcoming Leave Dates:</div>
                                <div class="info-value">${leaveDatesHtml}</div>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn-cancel" onclick="closeModal('viewDoctorModal')">Close</button>
                        </div>
                    `;
                    
                    document.getElementById('viewDoctorModalContent').innerHTML = modalContent;
                    document.getElementById('viewDoctorModal').classList.add('active');
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error loading doctor details');
            }
        }

        async function editDoctor(id) {
            try {
                const response = await fetch(`admin_get_doctor_details.php?id=${id}`);
                const data = await response.json();
                
                if (data.success) {
                    const doctor = data.doctor;
                    document.getElementById('editDoctorId').value = doctor.id;
                    document.getElementById('editDoctorName').value = doctor.doctor_name || '';
                    document.getElementById('editSpecialty').value = doctor.specialization || '';
                    document.getElementById('editClinicRoom').value = doctor.clinic_room || '';
                    document.getElementById('editContact').value = doctor.contact || '';
                    document.getElementById('editEmail').value = doctor.email || '';
                    document.getElementById('editAddress').value = doctor.address || '';
                    
                    document.getElementById('editDoctorModal').classList.add('active');
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error loading doctor details');
            }
        }

        function deleteDoctor(id, name) {
            const modalContent = `
                <div class="modal-header">
                    <h3><i class="fas fa-exclamation-triangle" style="color: #c62828;"></i> Delete Doctor</h3>
                    <button class="modal-close" onclick="closeModal('deleteDoctorModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <p style="margin-bottom: 20px; color: #333; line-height: 1.6;">
                        Are you sure you want to delete <strong>${name}</strong>?<br><br>
                        This action cannot be undone. All associated appointments and records will be affected.
                    </p>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('deleteDoctorModal')">Cancel</button>
                    <button type="button" class="btn-save" style="background: #c62828;" onclick="confirmDeleteDoctor(${id})">
                        <i class="fas fa-trash"></i> Delete Doctor
                    </button>
                </div>
            `;
            
            document.getElementById('deleteDoctorModalContent').innerHTML = modalContent;
            document.getElementById('deleteDoctorModal').classList.add('active');
        }
        
        async function confirmDeleteDoctor(id) {
            try {
                const response = await fetch(`admin_delete_doctor.php?id=${id}`, {
                    method: 'POST'
                });
                const data = await response.json();
                
                if (data.success) {
                    alert('Doctor deleted successfully!');
                    closeModal('deleteDoctorModal');
                    loadDoctors();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error deleting doctor');
            }
        }

        function openNewDoctorModal() {
            document.getElementById('newDoctorModal').classList.add('active');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // Load doctors on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadDoctors();
        });
        
        // Close modals when clicking outside
        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active');
            }
        });
        
        // Save edited doctor
        async function saveDoctorEdit(event) {
            event.preventDefault();
            
            const formData = new FormData();
            formData.append('doctor_id', document.getElementById('editDoctorId').value);
            formData.append('doctor_name', document.getElementById('editDoctorName').value);
            formData.append('specialization', document.getElementById('editSpecialty').value);
            formData.append('clinic_room', document.getElementById('editClinicRoom').value);
            formData.append('contact', document.getElementById('editContact').value);
            formData.append('email', document.getElementById('editEmail').value);
            formData.append('address', document.getElementById('editAddress').value);
            
            try {
                const response = await fetch('admin_update_doctor.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    alert('Doctor updated successfully!');
                    closeModal('editDoctorModal');
                    loadDoctors();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error updating doctor');
            }
        }
        
        // Save new doctor
        async function saveNewDoctor(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            
            try {
                const response = await fetch('admin_create_doctor.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    alert('Doctor created successfully!');
                    closeModal('newDoctorModal');
                    event.target.reset();
                    loadDoctors();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error creating doctor');
            }
        }
        
        async function confirmDeleteDoctor(id) {
            try {
                const response = await fetch(`admin_delete_doctor.php?id=${id}`, {
                    method: 'POST'
                });
                const data = await response.json();
                
                if (data.success) {
                    alert('Doctor deleted successfully!');
                    closeModal('deleteDoctorModal');
                    loadDoctors();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error deleting doctor');
            }
        }
    </script>
    
    <!-- View Doctor Modal -->
    <div id="viewDoctorModal" class="modal">
        <div class="modal-content-box">
            <div id="viewDoctorModalContent"></div>
        </div>
    </div>
    
    <!-- Edit Doctor Modal -->
    <div id="editDoctorModal" class="modal">
        <div class="modal-content-box">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Doctor</h3>
                <button class="modal-close" onclick="closeModal('editDoctorModal')">&times;</button>
            </div>
            <form id="editDoctorForm" onsubmit="saveDoctorEdit(event)">
                <input type="hidden" id="editDoctorId" name="doctor_id">
                
                <div class="form-group">
                    <label>Doctor Name *</label>
                    <input type="text" id="editDoctorName" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Specialty *</label>
                    <input type="text" id="editSpecialty" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Clinic Room</label>
                    <input type="text" id="editClinicRoom" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="tel" id="editContact" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="editEmail" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Address</label>
                    <textarea id="editAddress" class="form-control" rows="3"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('editDoctorModal')">Cancel</button>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Delete Doctor Modal -->
    <div id="deleteDoctorModal" class="modal">
        <div class="modal-content-box">
            <div id="deleteDoctorModalContent"></div>
        </div>
    </div>
    
    <!-- New Doctor Modal -->
    <div id="newDoctorModal" class="modal">
        <div class="modal-content-box">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add New Doctor</h3>
                <button class="modal-close" onclick="closeModal('newDoctorModal')">&times;</button>
            </div>
            <form id="newDoctorForm" onsubmit="saveNewDoctor(event)">
                <div class="form-group">
                    <label>First Name *</label>
                    <input type="text" name="first_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" name="middle_name" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Specialty *</label>
                    <input type="text" name="specialization" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Clinic Room</label>
                    <input type="text" name="clinic_room" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="tel" name="contact" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Username *</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('newDoctorModal')">Cancel</button>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Create Doctor
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

