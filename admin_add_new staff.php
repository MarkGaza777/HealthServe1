<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: Login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $last_name = trim($_POST['last_name']);
    $role = $_POST['role'];
    $phone = trim($_POST['phone']);
    $department = $_POST['department'];
    $photo_path = null;
    
    // Handle profile photo upload
    if (!empty($_FILES['profile-photo']['name'])) {
        $f = $_FILES['profile-photo'];
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
        $max_bytes = 5 * 1024 * 1024; // 5 MB
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        
        if ($f['error'] === UPLOAD_ERR_OK && in_array($ext, $allowed_ext) && $f['size'] <= $max_bytes) {
            // Ensure upload directory exists
            $uploadDir = __DIR__ . '/uploads/staff';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Generate unique filename
            $newName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $dst = $uploadDir . '/' . $newName;
            
            if (move_uploaded_file($f['tmp_name'], $dst)) {
                $photo_path = 'uploads/staff/' . $newName;
            }
        }
    }
    
    try {
        // Check if photo_path column exists, if not add it
        $checkColumn = $pdo->query("SHOW COLUMNS FROM staff LIKE 'photo_path'");
        if ($checkColumn->rowCount() == 0) {
            $pdo->exec("ALTER TABLE staff ADD COLUMN photo_path VARCHAR(255) DEFAULT NULL");
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO staff (
                first_name, middle_name, last_name, role, phone, department, 
                photo_path, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())
        ");
        
        $stmt->execute([
            $first_name, $middle_name, $last_name, $role, $phone, $department, $photo_path
        ]);
        
        $staff_id = $pdo->lastInsertId();
        $success = "Staff member successfully added with ID: $staff_id";
        
        // Redirect to staff list after successful creation
        header("Location: admin_staff_management.php?success=1");
        exit();
        
    } catch(PDOException $e) {
        $error = "Error creating staff member: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Staff - HealthServe Admin</title>
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
                <h2 class="page-title">Add New Staff</h2>
            </div>

            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Staff Registration Form -->
            <div class="form-container">
                <!-- Profile Photo Upload Section -->
                <div class="photo-upload-section">
                    <h3 style="color: #2E7D32; margin-bottom: 20px;">Profile Photo</h3>
                    <div class="photo-upload">
                        <div class="upload-icon">
                            <i class="fas fa-camera"></i>
                        </div>
                        <h4>Add Photo</h4>
                        <p>Upload Profile</p>
                        <input type="file" id="profile-photo" name="profile-photo" accept="image/*" style="display: none;">
                    </div>
                </div>

                <form method="POST" id="staffForm" enctype="multipart/form-data">
                    <!-- Full Name Section -->
                    <h3 style="color: #2E7D32; margin: 30px 0 20px;">Full Name</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name *</label>
                            <input type="text" id="first_name" name="first_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="middle_name">Middle Name</label>
                            <input type="text" id="middle_name" name="middle_name" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name *</label>
                            <input type="text" id="last_name" name="last_name" class="form-control" required>
                        </div>
                    </div>

                    <!-- Role and Contact Information -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="role">Role/Position *</label>
                            <select id="role" name="role" class="form-control" required>
                                <option value="">Select Role</option>
                                <option value="physician">Physician</option>
                                <option value="nurse">Nurse</option>
                                <option value="midwife">Midwife</option>
                                <option value="bhw">BHW</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="phone">Contact Number *</label>
                            <input type="tel" id="phone" name="phone" class="form-control" required 
                                   placeholder="09XX-XXX-XXXX">
                        </div>
                    </div>

                    <!-- Department Assignment -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="department">Assign Department *</label>
                            <select id="department" name="department" class="form-control" required>
                                <option value="">Select Department</option>
                                <option value="general">General Medicine</option>
                                <option value="maternal">Maternal Health</option>
                                <option value="pediatric">Pediatric Care</option>
                                <option value="emergency">Emergency Care</option>
                                <option value="dental">Dental Services</option>
                                <option value="pharmacy">Pharmacy</option>
                                <option value="laboratory">Laboratory</option>
                                <option value="administration">Administration</option>
                            </select>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions" style="margin-top: 40px; display: flex; gap: 15px; justify-content: flex-end;">
                        <a href="admin_staff_management.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-user-plus"></i> Save Staff
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Photo upload handling
        document.querySelector('.photo-upload').addEventListener('click', function() {
            document.getElementById('profile-photo').click();
        });
        
        document.getElementById('profile-photo').addEventListener('change', function(e) {
            if (e.target.files[0]) {
                const file = e.target.files[0];
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const uploadDiv = document.querySelector('.photo-upload');
                    uploadDiv.style.backgroundImage = `url(${e.target.result})`;
                    uploadDiv.style.backgroundSize = 'cover';
                    uploadDiv.style.backgroundPosition = 'center';
                    uploadDiv.innerHTML = '<div style="background: rgba(0,0,0,0.5); color: white; padding: 10px; border-radius: 8px;">Click to change photo</div>';
                };
                
                reader.readAsDataURL(file);
            }
        });

        // Form validation
        document.getElementById('staffForm').addEventListener('submit', function(e) {
            const phone = document.getElementById('phone').value;
            
            // Phone number validation
            const phoneRegex = /^09\d{9}$/;
            if (!phoneRegex.test(phone.replace(/[-\s]/g, ''))) {
                alert('Please enter a valid Philippine mobile number (09XXXXXXXXX)');
                e.preventDefault();
                return false;
            }
        });

        // Format phone number as user types
        document.getElementById('phone').addEventListener('input', function() {
            let value = this.value.replace(/\D/g, '');
            if (value.length >= 11) {
                value = value.substring(0, 11);
                this.value = value.substring(0, 4) + '-' + value.substring(4, 7) + '-' + value.substring(7);
            }
        });

    </script>

    <style>
        .photo-upload-section {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .photo-upload {
            width: 200px;
            height: 200px;
            border: 2px dashed #4CAF50;
            border-radius: 50%;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #F1F8E9;
        }
        
        .photo-upload:hover {
            background: #E8F5E8;
            border-color: #388E3C;
        }
        
        .upload-icon {
            width: 60px;
            height: 60px;
            background: #4CAF50;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            margin-bottom: 15px;
        }
        
        .photo-upload h4 {
            margin: 0;
            color: #2E7D32;
            font-size: 16px;
        }
        
        .photo-upload p {
            margin: 5px 0 0;
            color: #666;
            font-size: 14px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
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
    </style>
</body>
</html>