<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: Login.php");
    exit();
}

// Auto-update staff names and phone numbers to ensure correct data
try {
    // Update Pharmacist: Michelle Honrubia - 09773238989
    $stmt = $pdo->prepare("
        UPDATE users 
        SET first_name = 'Michelle', 
            last_name = 'Honrubia',
            middle_name = NULL,
            contact_no = '09773238989'
        WHERE role = 'pharmacist'
        LIMIT 1
    ");
    $stmt->execute();
    
    // Update Admin: Jerry Sandoval - 09896531827
    $stmt = $pdo->prepare("
        UPDATE users 
        SET first_name = 'Jerry', 
            last_name = 'Sandoval',
            middle_name = NULL,
            contact_no = '09896531827'
        WHERE role = 'admin'
        LIMIT 1
    ");
    $stmt->execute();
    
    // Update FDO: Christine Joy Juanir - 09128734275
    $stmt = $pdo->prepare("
        UPDATE users 
        SET first_name = 'Christine', 
            middle_name = 'Joy',
            last_name = 'Juanir',
            contact_no = '09128734275'
        WHERE role = 'fdo'
        LIMIT 1
    ");
    $stmt->execute();
} catch(PDOException $e) {
    // Silently continue if update fails
}

// Get staff statistics and members
try {
    // Get all staff members from different sources
    $all_staff = [];
    
    // 1. Get Doctors (from doctors table joined with users)
    $stmt = $pdo->prepare("
        SELECT 
            d.id,
            u.first_name,
            u.middle_name,
            u.last_name,
            'doctor' as role,
            u.contact_no as phone,
            u.email,
            d.specialization as department,
            'active' as status,
            'on_duty' as shift_status,
            NULL as photo_path,
            u.created_at,
            CONCAT('DOC-', d.id) as staff_id
        FROM doctors d
        LEFT JOIN users u ON d.user_id = u.id
        WHERE u.role = 'doctor'
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute();
    $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($doctors as $doctor) {
        $all_staff[] = $doctor;
    }
    
    // 2. Get Pharmacist (from users table)
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.first_name,
            u.middle_name,
            u.last_name,
            'pharmacist' as role,
            u.contact_no as phone,
            u.email,
            'Pharmacy' as department,
            'active' as status,
            'on_duty' as shift_status,
            NULL as photo_path,
            u.created_at,
            CONCAT('PHARM-', u.id) as staff_id
        FROM users u
        WHERE u.role = 'pharmacist'
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute();
    $pharmacists = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pharmacists as $pharmacist) {
        $all_staff[] = $pharmacist;
    }
    
    // 3. Get FDO (from users table)
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.first_name,
            u.middle_name,
            u.last_name,
            'fdo' as role,
            u.contact_no as phone,
            u.email,
            'Front Desk' as department,
            'active' as status,
            'on_duty' as shift_status,
            NULL as photo_path,
            u.created_at,
            CONCAT('FDO-', u.id) as staff_id
        FROM users u
        WHERE u.role = 'fdo'
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute();
    $fdos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fdos as $fdo) {
        $all_staff[] = $fdo;
    }
    
    // 4. Get Admin (from users table)
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.first_name,
            u.middle_name,
            u.last_name,
            'admin' as role,
            u.contact_no as phone,
            u.email,
            'Administration' as department,
            'active' as status,
            'on_duty' as shift_status,
            NULL as photo_path,
            u.created_at,
            CONCAT('ADMIN-', u.id) as staff_id
        FROM users u
        WHERE u.role = 'admin'
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($admins as $admin) {
        $all_staff[] = $admin;
    }
    
    // 5. Get Staff from staff table (physician, nurse, midwife, bhw)
    $stmt = $pdo->prepare("
        SELECT 
            s.id,
            s.first_name,
            s.middle_name,
            s.last_name,
            s.role,
            s.phone,
            s.email,
            s.department,
            s.status,
            s.shift_status,
            s.photo_path,
            s.created_at,
            CONCAT('STAFF-', s.id) as staff_id
        FROM staff s
        WHERE s.status = 'active'
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->execute();
    $staff_table = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($staff_table as $staff_member) {
        $all_staff[] = $staff_member;
    }
    
    // Calculate statistics
    $total_staff = count($all_staff);
    $on_duty = count(array_filter($all_staff, function($s) { return $s['shift_status'] === 'on_duty'; }));
    $new_this_month = count(array_filter($all_staff, function($s) { 
        $created = new DateTime($s['created_at']);
        $now = new DateTime();
        return $created->format('Y-m') === $now->format('Y-m');
    }));
    
    // Sort staff members
    usort($all_staff, function($a, $b) {
        // Sort by role first, then by last name
        $role_order = ['admin' => 1, 'doctor' => 2, 'physician' => 2, 'pharmacist' => 3, 'fdo' => 4, 'nurse' => 5, 'midwife' => 6, 'bhw' => 7];
        $role_a = $role_order[$a['role']] ?? 99;
        $role_b = $role_order[$b['role']] ?? 99;
        
        if ($role_a !== $role_b) {
            return $role_a <=> $role_b;
        }
        
        $last_a = $a['last_name'] ?? '';
        $last_b = $b['last_name'] ?? '';
        return strcasecmp($last_a, $last_b);
    });
    
    $staff_members = $all_staff;
    
} catch(PDOException $e) {
    $error = "Error fetching staff data: " . $e->getMessage();
    $total_staff = 0;
    $on_duty = 0;
    $new_this_month = 0;
    $staff_members = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management - HealthServe Admin</title>
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
                    <a href="admin_staff_management.php" class="nav-link active">
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
                <h2 class="page-title">Staffs Management</h2>
                <div class="breadcrumb">Dashboard > Staffs</div>
            </div>

            <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
                <div class="alert alert-success" style="padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; background: #E8F5E8; color: #2E7D32; border: 1px solid #4CAF50;">
                    <i class="fas fa-check-circle"></i> Staff member successfully added!
                </div>
            <?php endif; ?>

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
                    <div class="stat-icon-wrapper patients">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $on_duty; ?></h3>
                        <p>On Duty</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon-wrapper patients">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $new_this_month; ?></h3>
                        <p>New This Month</p>
                    </div>
                </div>
            </div>

            <!-- Staff Table Section -->
            <div class="content-section">
                <div class="section-header">
                    <div class="search-filter">
                        <div class="search-box">
                            <i class="fas fa-search" style="color: #81C784;"></i>
                            <input type="text" placeholder="Search by name, role, or ID" id="staffSearch">
                        </div>
                    </div>
                    <a href="admin_add_new staff.php" class="add-btn">
                        <i class="fas fa-plus"></i>
                        <span>New Staff</span>
                    </a>
                </div>

                <!-- Staff Table -->
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Contact</th>
                            <th>Department</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($staff_members)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: #999;">
                                <i class="fas fa-user-friends" style="font-size: 48px; margin-bottom: 15px;"></i><br>
                                No staff members found.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($staff_members as $staff): ?>
                        <tr>
                            <td>
                                <div class="staff-info">
                                    <div class="staff-avatar">
                                        <?php
                                        if (!empty($staff['photo_path']) && file_exists($staff['photo_path'])) {
                                            echo '<img src="' . htmlspecialchars($staff['photo_path']) . '" alt="' . htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']) . '">';
                                        } else {
                                            $initials = '';
                                            if (!empty($staff['first_name'])) {
                                                $initials .= substr($staff['first_name'], 0, 1);
                                            }
                                            if (!empty($staff['last_name'])) {
                                                $initials .= substr($staff['last_name'], 0, 1);
                                            }
                                            if (empty($initials)) {
                                                $initials = '??';
                                            }
                                            echo strtoupper($initials);
                                        }
                                        ?>
                                    </div>
                                    <div class="staff-details">
                                        <strong><?php 
                                            $full_name = trim(($staff['first_name'] ?? '') . ' ' . ($staff['middle_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''));
                                            $full_name = preg_replace('/\s+/', ' ', $full_name);
                                            echo htmlspecialchars($full_name ?: 'Unknown');
                                        ?></strong>
                                        <br><small style="color: #666;">ID: <?php echo htmlspecialchars($staff['staff_id'] ?? $staff['id']); ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="role-badge role-<?php echo strtolower($staff['role']); ?>">
                                    <?php 
                                    $role_display = [
                                        'doctor' => 'Doctor',
                                        'physician' => 'Physician',
                                        'pharmacist' => 'Pharmacist',
                                        'fdo' => 'FDO',
                                        'admin' => 'Admin',
                                        'nurse' => 'Nurse',
                                        'midwife' => 'Midwife',
                                        'bhw' => 'BHW'
                                    ];
                                    echo $role_display[$staff['role']] ?? ucfirst($staff['role']); 
                                    ?>
                                </span>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($staff['phone'] ?? 'N/A'); ?><br>
                                <small style="color: #666;"><?php echo htmlspecialchars($staff['email'] ?? 'N/A'); ?></small>
                            </td>
                            <td><?php 
                                $dept = $staff['department'] ?? 'General';
                                // Capitalize first letter of each word
                                echo ucwords(str_replace('_', ' ', htmlspecialchars($dept))); 
                            ?></td>
                            <td>
                                <?php if ($staff['shift_status'] === 'on_duty'): ?>
                                    <span class="status-badge status-active">On Duty</span>
                                <?php else: ?>
                                    <span class="status-badge status-inactive">On Leave</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn btn-view" onclick="viewStaff(<?php echo $staff['id']; ?>, '<?php echo $staff['role']; ?>', '<?php echo htmlspecialchars($staff['staff_id'] ?? $staff['id']); ?>')" title="View Details">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <?php if ($staff['role'] === 'doctor'): ?>
                                    <button class="action-btn btn-edit" onclick="window.location.href='admin_doctors_management.php'" title="Edit Doctor">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <?php else: ?>
                                    <button class="action-btn btn-edit" onclick="editStaff(<?php echo $staff['id']; ?>, '<?php echo $staff['role']; ?>', '<?php echo htmlspecialchars($staff['staff_id'] ?? $staff['id']); ?>')" title="Edit Staff">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <?php endif; ?>
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
        async function viewStaff(id, role, staffId) {
            try {
                const response = await fetch(`admin_get_staff_details.php?id=${id}&role=${role}`);
                const data = await response.json();
                
                if (data.success) {
                    const staff = data.staff;
                    const formattedName = staff.staff_name || 'Unknown Staff';
                    
                    // Determine role icon and title
                    let roleIcon = 'fas fa-user';
                    let roleTitle = 'Staff Details';
                    if (role === 'doctor') {
                        roleIcon = 'fas fa-user-md';
                        roleTitle = 'Doctor Details';
                    } else if (role === 'pharmacist') {
                        roleIcon = 'fas fa-pills';
                        roleTitle = 'Pharmacist Details';
                    } else if (role === 'fdo') {
                        roleIcon = 'fas fa-headset';
                        roleTitle = 'FDO Details';
                    } else if (role === 'admin') {
                        roleIcon = 'fas fa-user-shield';
                        roleTitle = 'Admin Details';
                    }
                    
                    // Build modal content based on role
                    let modalContent = `
                        <div class="modal-header">
                            <h3><i class="${roleIcon}"></i> ${roleTitle}</h3>
                            <button class="modal-close" onclick="closeModal('viewStaffModal')">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="info-row">
                                <div class="info-label">Name:</div>
                                <div class="info-value">${formattedName}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Role:</div>
                                <div class="info-value">${role.charAt(0).toUpperCase() + role.slice(1)}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Department:</div>
                                <div class="info-value">${staff.department || 'N/A'}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Contact:</div>
                                <div class="info-value">${staff.contact || 'N/A'}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Email:</div>
                                <div class="info-value">${staff.email || 'N/A'}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Address:</div>
                                <div class="info-value">${staff.address || 'N/A'}</div>
                            </div>`;
                    
                    // Add role-specific information
                    if (role === 'doctor') {
                        modalContent += `
                            <div class="info-row">
                                <div class="info-label">Specialty:</div>
                                <div class="info-value">${staff.specialization || 'N/A'}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Clinic Room:</div>
                                <div class="info-value">${staff.clinic_room || 'N/A'}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Patients Today:</div>
                                <div class="info-value">${staff.patients_today || 0}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Total Appointments:</div>
                                <div class="info-value">${staff.total_appointments || 0}</div>
                            </div>`;
                    }
                    
                    modalContent += `
                            <div class="info-row">
                                <div class="info-label">Member Since:</div>
                                <div class="info-value">${staff.created_at ? new Date(staff.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A'}</div>
                            </div>
                            <div class="info-row" style="border-top: 2px solid #E0E0E0; padding-top: 20px; margin-top: 10px;">
                                <div class="info-label">Status:</div>
                                <div class="info-value">
                                    <select id="statusSelect" class="status-select" style="padding: 8px 15px; border: 2px solid #E0E0E0; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; background: ${staff.shift_status === 'on_duty' ? '#E8F5E8' : '#FFF3E0'}; color: ${staff.shift_status === 'on_duty' ? '#2E7D32' : '#F57C00'};">
                                        <option value="on_duty" ${staff.shift_status === 'on_duty' ? 'selected' : ''}>On Duty</option>
                                        <option value="off_duty" ${staff.shift_status === 'off_duty' ? 'selected' : ''}>On Leave</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn-cancel" onclick="closeModal('viewStaffModal')">Close</button>
                            ${(role === 'physician' || role === 'nurse' || role === 'midwife' || role === 'bhw') ? `
                            <button type="button" class="btn-save" onclick="updateStaffStatus(${id}, '${role}')" style="margin-left: 10px;">
                                <i class="fas fa-save"></i> Update Status
                            </button>
                            ` : ''}
                        </div>
                    `;
                    
                    document.getElementById('viewStaffModalContent').innerHTML = modalContent;
                    document.getElementById('viewStaffModal').classList.add('active');
                    document.body.style.overflow = 'hidden';
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error loading staff details');
            }
        }
        
        async function editStaff(id, role, staffId) {
            // For doctors, redirect to doctors management page
            if (role === 'doctor') {
                window.location.href = `admin_doctors_management.php`;
                return;
            }
            
            try {
                const response = await fetch(`admin_get_staff_details.php?id=${id}&role=${role}`);
                const data = await response.json();
                
                if (data.success) {
                    const staff = data.staff;
                    
                    // Populate form fields
                    document.getElementById('editStaffId').value = id;
                    document.getElementById('editStaffRole').value = role;
                    document.getElementById('editStaffName').value = staff.staff_name || '';
                    document.getElementById('editStaffContact').value = staff.contact || '';
                    document.getElementById('editStaffEmail').value = staff.email || '';
                    document.getElementById('editStaffAddress').value = staff.address || '';
                    
                    // Show role-specific fields
                    const departmentField = document.getElementById('editStaffDepartmentGroup');
                    if (departmentField) {
                        departmentField.style.display = 'block';
                        document.getElementById('editStaffDepartment').value = staff.department || '';
                    }
                    
                    // Open modal
                    document.getElementById('editStaffModal').classList.add('active');
                    document.body.style.overflow = 'hidden';
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error loading staff details');
            }
        }
        
        async function saveStaffEdit(event) {
            event.preventDefault();
            
            const formData = new FormData();
            formData.append('staff_id', document.getElementById('editStaffId').value);
            formData.append('role', document.getElementById('editStaffRole').value);
            formData.append('staff_name', document.getElementById('editStaffName').value);
            formData.append('contact', document.getElementById('editStaffContact').value);
            formData.append('email', document.getElementById('editStaffEmail').value);
            formData.append('address', document.getElementById('editStaffAddress').value);
            formData.append('department', document.getElementById('editStaffDepartment').value);
            
            try {
                const response = await fetch('admin_update_staff.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    alert('Staff updated successfully!');
                    closeModal('editStaffModal');
                    location.reload(); // Reload to show updated data
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error updating staff');
            }
        }
        
        // Search functionality - matching doctor page
        document.getElementById('staffSearch')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('.data-table tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
        
        // Close modal function - matching doctor modal behavior
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }
        
        // Close modal when clicking outside - matching doctor modal behavior
        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
        
        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modal = document.getElementById('viewStaffModal');
                if (modal && modal.classList.contains('active')) {
                    closeModal('viewStaffModal');
                }
            }
        });
        
        // Update staff status
        async function updateStaffStatus(id, role) {
            const statusSelect = document.getElementById('statusSelect');
            if (!statusSelect) {
                alert('Status select not found');
                return;
            }
            
            const shift_status = statusSelect.value;
            
            if (!confirm(`Are you sure you want to change the status to ${shift_status === 'on_duty' ? 'On Duty' : 'On Leave'}?`)) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('staff_id', id);
                formData.append('role', role);
                formData.append('shift_status', shift_status);
                
                const response = await fetch('admin_update_staff_status.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Status updated successfully!');
                    closeModal('viewStaffModal');
                    location.reload(); // Reload to show updated status
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error updating staff status');
            }
        }
        
        // Update status select styling when changed
        document.addEventListener('change', function(e) {
            if (e.target.id === 'statusSelect') {
                const select = e.target;
                if (select.value === 'on_duty') {
                    select.style.background = '#E8F5E8';
                    select.style.color = '#2E7D32';
                } else {
                    select.style.background = '#FFF3E0';
                    select.style.color = '#F57C00';
                }
            }
        });
    </script>

    <style>
        /* Staff-specific styles - Matching Doctor page */
        .staff-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .staff-avatar {
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
            overflow: hidden;
        }
        
        .staff-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
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
            text-decoration: none;
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
        
        .role-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .role-doctor {
            background: #E3F2FD;
            color: #1565C0;
        }
        
        .role-pharmacist {
            background: #FFF3E0;
            color: #F57C00;
        }
        
        .role-fdo {
            background: #E8F5E8;
            color: #2E7D32;
        }
        
        .role-admin {
            background: #F3E5F5;
            color: #7B1FA2;
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
        
        /* Modal Styles - Matching Doctor Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
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
            margin: 20px;
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
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid #E0E0E0;
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
        
        .status-select {
            transition: all 0.3s ease;
        }
        
        .status-select:hover {
            border-color: #66BB6A !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .status-select:focus {
            outline: none;
            border-color: #4CAF50 !important;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }
    </style>
    
    <!-- View Staff Modal -->
    <div id="viewStaffModal" class="modal">
        <div class="modal-content-box">
            <div id="viewStaffModalContent"></div>
        </div>
    </div>
    
    <!-- Edit Staff Modal -->
    <div id="editStaffModal" class="modal">
        <div class="modal-content-box">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Staff</h3>
                <button class="modal-close" onclick="closeModal('editStaffModal')">&times;</button>
            </div>
            <form id="editStaffForm" onsubmit="saveStaffEdit(event)">
                <input type="hidden" id="editStaffId" name="staff_id">
                <input type="hidden" id="editStaffRole" name="role">
                
                <div class="form-group">
                    <label>Name *</label>
                    <input type="text" id="editStaffName" class="form-control" required>
                </div>
                
                <div class="form-group" id="editStaffDepartmentGroup">
                    <label>Department</label>
                    <input type="text" id="editStaffDepartment" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="tel" id="editStaffContact" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="editStaffEmail" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Address</label>
                    <textarea id="editStaffAddress" class="form-control" rows="3"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('editStaffModal')">Cancel</button>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>