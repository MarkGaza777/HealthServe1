<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: Login.php");
    exit();
}

// Get patients statistics
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_patients FROM patients");
    $total_patients = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as new_this_month FROM patients WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $new_this_month = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as active_today FROM appointments WHERE DATE(start_datetime) = CURDATE() AND status IN ('approved', 'completed')");
    $active_today = $stmt->fetchColumn();
    
    // Get patients with search and filter
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? 'all';
    
    $where_clause = "1=1";
    $params = [];
    
    if (!empty($search)) {
        $where_clause .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.phone LIKE ? OR p.philhealth_no LIKE ?)";
        $search_param = "%$search%";
        $params = array_fill(0, 4, $search_param);
    }
    
    if ($status_filter !== 'all') {
        $where_clause .= " AND p.status = ?";
        $params[] = $status_filter;
    }
    
     $stmt = $pdo->prepare("
         SELECT p.*
         FROM patients p 
         WHERE $where_clause 
         ORDER BY p.created_at DESC
         LIMIT 50
     ");
    $stmt->execute($params);
    $patients = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error = "Error fetching patients: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patients Management - HealthServe Admin</title>
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
                    <a href="admin_staff_management.php" class="nav-link">
                        <div class="nav-icon"><i class="fas fa-user-friends"></i></div>
                        Staffs
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_settings.php" class="nav-link">
                        <div class="nav-icon"><i class="fas fa-cog"></i></div>
                        Settings
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
                <h2 class="page-title">Patient Records</h2>
                <div class="breadcrumb">Dashboard > Patients</div>
            </div>

            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Patient added successfully!
                </div>
            <?php endif; ?>

            <!-- Patient Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon patients">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $total_patients; ?></h3>
                        <p>Total Patients</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon appointments">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $new_this_month; ?></h3>
                        <p>New This Month</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon staff">
                        <i class="fas fa-heartbeat"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $active_today; ?></h3>
                        <p>Active Today</p>
                    </div>
                </div>
            </div>

            <!-- Search and Filter -->
            <div class="table-container">
                <div class="table-header">
                    <div class="table-filters">
                        <div class="search-container">
                            <i class="fas fa-search"></i>
                            <input type="text" id="patient-search" placeholder="Search by name, ID or condition" 
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   onkeyup="searchPatients(event)">
                        </div>
                        <button class="filter-btn" onclick="toggleFilter()">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                         <a href="add_patient.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> New Patient
                        </a>
                    </div>
                </div>

                <!-- Filter Panel -->
                <div id="filter-panel" class="filter-panel" style="display: none;">
                    <form method="GET" class="filter-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Search</label>
                                <input type="text" name="search" class="form-control" 
                                       value="<?php echo htmlspecialchars($search); ?>"
                                       placeholder="Patient name, phone, ID">
                            </div>
                            <div class="form-group" style="align-self: end;">
                                <button type="submit" class="btn btn-primary">Apply Filter</button>
                                 <a href="admin_patient management.php" class="btn btn-secondary">Clear</a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Patients Table -->
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Age/Sex</th>
                            <th>Contact Info</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($patients)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px; color: #999;">
                                <i class="fas fa-users" style="font-size: 48px; margin-bottom: 15px;"></i><br>
                                No patients found matching your criteria.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($patients as $patient): ?>
                        <tr>
                            <td>
                                <div class="patient-avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="patient-info">
                                    <strong><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></strong><br>
                                    <small style="color: #666;">ID: <?php echo $patient['id']; ?></small>
                                </div>
                            </td>
                             <td>
                                 <?php 
                                 $age = $patient['dob'] ? date_diff(date_create($patient['dob']), date_create('today'))->y : 'N/A';
                                 $sex = $patient['sex'] ? strtoupper(substr($patient['sex'], 0, 1)) : 'N/A';
                                 echo $age . '/' . $sex;
                                 ?>
                             </td>
                            <td>
                                <?php echo htmlspecialchars($patient['phone']); ?><br>
                                <small style="color: #666;"><?php echo htmlspecialchars(substr($patient['address'], 0, 30) . '...'); ?></small>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $patient['status']; ?>">
                                    <?php echo ucfirst($patient['status']); ?>
                                </span>
                            </td>
                            <td>
                                <button class="action-btn btn-view" onclick="viewPatient(<?php echo $patient['id']; ?>)" title="View Details">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="action-btn btn-edit" onclick="editPatient(<?php echo $patient['id']; ?>)" title="Edit Patient">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <div class="action-dropdown">
                                    <button class="action-btn btn-more" onclick="toggleDropdown(<?php echo $patient['id']; ?>)" title="More Actions">
                                        <i class="fas fa-ellipsis-v"></i> More
                                    </button>
                                    <div id="dropdown-<?php echo $patient['id']; ?>" class="action-dropdown-menu" style="display: none;">
                                        <a href="#" onclick="createAppointment(<?php echo $patient['id']; ?>)">
                                            <i class="fas fa-calendar-plus"></i> Book Appointment
                                        </a>
                                        <a href="#" onclick="viewHistory(<?php echo $patient['id']; ?>)">
                                            <i class="fas fa-history"></i> Medical History
                                        </a>
                                        <a href="#" onclick="printRecord(<?php echo $patient['id']; ?>)">
                                            <i class="fas fa-print"></i> Print Record
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
         function searchPatients(event) {
             if (event.key === 'Enter') {
                 const search = document.getElementById('patient-search').value;
                 window.location.href = `admin_patient management.php?search=${encodeURIComponent(search)}`;
             }
         }
        
        function toggleFilter() {
            const panel = document.getElementById('filter-panel');
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        }
        
        function viewPatient(id) {
            window.location.href = `admin_patient_details.php?id=${id}`;
        }
        
        function editPatient(id) {
            window.location.href = `admin_edit_patient.php?id=${id}`;
        }
        
        function toggleDropdown(id) {
            const dropdown = document.getElementById(`dropdown-${id}`);
            // Close all other dropdowns
            document.querySelectorAll('.action-dropdown-menu').forEach(d => {
                if (d !== dropdown) d.style.display = 'none';
            });
            dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
        }
        
        function createAppointment(patientId) {
            window.location.href = `admin_new_appointment.php?patient_id=${patientId}`;
        }
        
        function viewHistory(patientId) {
            window.location.href = `admin_patient_history.php?id=${patientId}`;
        }
        
        function printRecord(patientId) {
            window.open(`admin_print_patient.php?id=${patientId}`, '_blank');
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.action-dropdown')) {
                document.querySelectorAll('.action-dropdown-menu').forEach(d => {
                    d.style.display = 'none';
                });
            }
        });
    </script>

    <style>
        .search-container {
            position: relative;
            display: flex;
            align-items: center;
            background: white;
            border: 2px solid #E0E0E0;
            border-radius: 25px;
            padding: 8px 15px;
            margin-right: 15px;
            min-width: 300px;
        }
        
        .search-container i {
            color: #999;
            margin-right: 10px;
        }
        
        .search-container input {
            border: none;
            outline: none;
            background: transparent;
            flex: 1;
            font-size: 14px;
        }
        
        .filter-panel {
            padding: 20px 30px;
            background: #F8F9FA;
            border-bottom: 1px solid #E0E0E0;
        }
        
        .patient-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #4CAF50, #66BB6A);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            margin-right: 12px;
            vertical-align: middle;
        }
        
        .patient-info {
            display: inline-block;
            vertical-align: middle;
        }
        
        .action-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .action-dropdown-menu {
            position: absolute;
            right: 0;
            top: 100%;
            background: white;
            border: 1px solid #E0E0E0;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            z-index: 100;
            min-width: 160px;
        }
        
        .action-dropdown-menu a {
            display: block;
            padding: 10px 15px;
            text-decoration: none;
            color: #333;
            font-size: 13px;
            transition: background 0.3s ease;
        }
        
        .action-dropdown-menu a:hover {
            background: #F8F9FA;
        }
        
        .action-dropdown-menu a i {
            margin-right: 8px;
            width: 16px;
        }
        
        /* Action Buttons - Professional styling */
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
        
        .action-btn.btn-more {
            background: #f3e5f5;
            color: #7b1fa2;
            border: 1px solid rgba(123, 31, 162, 0.2);
        }
        
        .action-btn.btn-more:hover {
            background: #e1bee7;
            color: #6a1b9a;
        }
    </style>
</body>
</html>