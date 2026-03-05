<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: Login.php");
    exit();
}

// Handle appointment status updates
if ($_POST['action'] ?? '' === 'update_status') {
    $appointment_id = $_POST['appointment_id'];
    $status = $_POST['status'];
    $doctor_id = $_POST['doctor_id'] ?? null;
    
    try {
        $stmt = $pdo->prepare("UPDATE appointments SET status = ?, doctor_id = ? WHERE id = ?");
        $stmt->execute([$status, $doctor_id, $appointment_id]);
        $success = "Appointment updated successfully!";
    } catch(PDOException $e) {
        $error = "Error updating appointment: " . $e->getMessage();
    }
}

// Get appointments with patient info
$filter = $_GET['filter'] ?? 'all';
$date_filter = $_GET['date'] ?? 'all';

$where_clause = "1=1";
if ($filter !== 'all') {
    $where_clause .= " AND a.status = '$filter'";
}

if ($date_filter === 'today') {
    $where_clause .= " AND DATE(a.start_datetime) = CURDATE()";
} elseif ($date_filter === 'week') {
    $where_clause .= " AND WEEK(a.start_datetime) = WEEK(CURDATE()) AND YEAR(a.start_datetime) = YEAR(CURDATE())";
}

try {
    $stmt = $pdo->prepare("
        SELECT a.*, p.first_name, p.last_name, p.phone, u.username,
               d.name as doctor_name
        FROM appointments a 
        JOIN patients p ON a.patient_id = p.id 
        JOIN users u ON a.user_id = u.id 
        LEFT JOIN doctors d ON a.doctor_id = d.id
        WHERE $where_clause
        ORDER BY a.start_datetime DESC, a.created_at DESC
    ");
    $stmt->execute();
    $appointments = $stmt->fetchAll();
    
    // Get doctors list
    $stmt = $pdo->query("SELECT * FROM doctors WHERE is_active = 1");
    $doctors_list = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error = "Error fetching appointments: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments Management - HealthServe Admin</title>
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
                <!-- Inventory moved to Pharmacist portal to avoid duplication across roles -->
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
            <div class="header-actions">
                <a href="book_appointment.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Appointment
                </a>
            </div>
        </header>

        <!-- Content Area -->
        <div class="content-area">
            <div class="page-header">
                <h2 class="page-title">Appointments Management</h2>
                <div class="breadcrumb">Dashboard > Appointments</div>
            </div>

            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- Appointments Table -->
            <div class="table-container">
                <div class="table-header">
                    <div class="table-filters">
                        <button class="filter-btn <?php echo $date_filter === 'all' ? 'active' : ''; ?>" 
                                onclick="filterAppointments('all')">All</button>
                        <button class="filter-btn <?php echo $date_filter === 'today' ? 'active' : ''; ?>" 
                                onclick="filterAppointments('today')">Today</button>
                        <button class="filter-btn <?php echo $date_filter === 'week' ? 'active' : ''; ?>" 
                                onclick="filterAppointments('week')">This Week</button>
                        <select onchange="filterByStatus(this.value)" class="form-control" style="width: auto;">
                            <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="completed" <?php echo $filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="declined" <?php echo $filter === 'declined' ? 'selected' : ''; ?>>Declined</option>
                            <option value="missed" <?php echo $filter === 'missed' ? 'selected' : ''; ?>>Missed</option>
                        </select>
                    </div>
                </div>

                <table class="data-table">
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
                    <tbody>
                        <?php foreach ($appointments as $appointment): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']); ?></strong><br>
                                <small><?php echo htmlspecialchars($appointment['phone']); ?></small>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($appointment['start_datetime'])); ?></td>
                            <td><?php echo date('g:i A', strtotime($appointment['start_datetime'])); ?></td>
                            <td>
                                <?php if ($appointment['doctor_name']): ?>
                                    <?php echo htmlspecialchars($appointment['doctor_name']); ?>
                                <?php else: ?>
                                    <span style="color: #999;">Not assigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $appointment['status']; ?>">
                                    <?php echo ucfirst($appointment['status']); ?>
                                </span>
                            </td>
                            <td>
                                <button class="action-btn btn-view" onclick="viewAppointment(<?php echo $appointment['id']; ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="action-btn btn-edit" onclick="editAppointment(<?php echo $appointment['id']; ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <?php if ($appointment['status'] === 'pending'): ?>
                                <button class="action-btn btn-delete" onclick="cancelAppointment(<?php echo $appointment['id']; ?>)">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Edit Appointment Modal -->
    <div id="editModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Appointment</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="appointment_id" id="edit_appointment_id">
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="edit_status" class="form-control">
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="completed">Completed</option>
                        <option value="declined">Declined</option>
                        <option value="missed">Missed</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Assign Staff</label>
                    <select name="doctor_id" id="edit_doctor_id" class="form-control">
                        <option value="">Select Staff</option>
                        <?php foreach ($doctors_list as $doctor): ?>
                        <option value="<?php echo $doctor['id']; ?>">
                            <?php echo htmlspecialchars($doctor['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Update Appointment</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function filterAppointments(dateFilter) {
            window.location.href = `admin_appointments management.php?date=${dateFilter}&filter=<?php echo $filter; ?>`;
        }
        
        function filterByStatus(status) {
            window.location.href = `admin_appointments management.php?filter=${status}&date=<?php echo $date_filter; ?>`;
        }
        
        function viewAppointment(id) {
            window.location.href = `admin_appointment_details.php?id=${id}`;
        }
        
        function editAppointment(id) {
            // In a real implementation, you would fetch the appointment data via AJAX
            document.getElementById('edit_appointment_id').value = id;
            document.getElementById('editModal').style.display = 'block';
        }
        
        async function cancelAppointment(id) {
            const confirmed = await confirm('Are you sure you want to cancel this appointment?');
            if (confirmed) {
                // Submit form to cancel appointment
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="appointment_id" value="${id}">
                    <input type="hidden" name="status" value="cancelled">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }
    </script>

    <style>
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        
        .modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .close {
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #E8F5E8;
            color: #2E7D32;
            border: 1px solid #4CAF50;
        }
        
        .alert-error {
            background: #FFEBEE;
            color: #C62828;
            border: 1px solid #F44336;
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
        
        .action-btn.btn-delete {
            background: #ffebee;
            color: #d32f2f;
            border: 1px solid rgba(211, 47, 47, 0.2);
        }
        
        .action-btn.btn-delete:hover {
            background: #ffcdd2;
            color: #c62828;
        }
    </style>
</body>
</html>