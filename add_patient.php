<?php
session_start();
require_once 'db.php';
require_once 'admin_helpers_simple.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: Login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $last_name = trim($_POST['last_name']);
    $sex = $_POST['sex'];
    $date_of_birth = $_POST['date_of_birth'];
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $civil_status = $_POST['civil_status'];
    $philhealth_no = trim($_POST['philhealth_no']);
    $emergency_contact_name = trim($_POST['emergency_contact_name']);
    $emergency_contact_phone = trim($_POST['emergency_contact_phone']);
    $emergency_contact_relationship = trim($_POST['emergency_contact_relationship']);
    $medical_history = trim($_POST['medical_history']);
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO patients (
                first_name, middle_name, last_name, sex, dob, 
                phone, address, civil_status, philhealth_no, 
                emergency_contact, notes, created_by_user_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $emergency_contact = $emergency_contact_name . ' (' . $emergency_contact_relationship . ') - ' . $emergency_contact_phone;
        
        $stmt->execute([
            $first_name, $middle_name, $last_name, $sex, $date_of_birth,
            $phone, $address, $civil_status, $philhealth_no,
            $emergency_contact, $medical_history, $_SESSION['user']['id']
        ]);
        
        $patient_id = $pdo->lastInsertId();
        $success = "Patient successfully registered with ID: $patient_id";
        
        // Log patient creation
        logAuditEvent('Patient Created', 'Patient Record', $patient_id, "Admin created patient: {$first_name} {$last_name} (ID: {$patient_id})");
        
        // Redirect to patient management page after successful creation
        header("Location: admin_patient management.php?success=1");
        exit();
        
    } catch(PDOException $e) {
        $error = "Error creating patient: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Patient - HealthServe Admin</title>
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
                <h2 class="page-title">Add New Patient</h2>
                <div class="breadcrumb">Dashboard > Patients > Add New Patient</div>
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

            <!-- Patient Registration Form -->
            <div class="form-container">
                <form method="POST" id="patientForm">
                    <!-- Patient Name Section -->
                    <h3 style="color: #2E7D32; margin-bottom: 20px;">Patient Name</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name *</label>
                            <input type="text" id="first_name" name="first_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="middle_name">Middle Name (Optional)</label>
                            <input type="text" id="middle_name" name="middle_name" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name *</label>
                            <input type="text" id="last_name" name="last_name" class="form-control" required>
                        </div>
                    </div>

                    <!-- Basic Information -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="sex">Sex *</label>
                            <select id="sex" name="sex" class="form-control" required>
                                <option value="">Select Sex</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="date_of_birth">Birthdate *</label>
                            <input type="date" id="date_of_birth" name="date_of_birth" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="age">Age</label>
                            <input type="text" id="age" class="form-control" readonly style="background: #f5f5f5;">
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="phone">Contact Number *</label>
                            <input type="tel" id="phone" name="phone" class="form-control" required 
                                   placeholder="09XX-XXX-XXXX">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label for="address">Address *</label>
                            <input type="text" id="address" name="address" class="form-control" required
                                   placeholder="Complete address">
                        </div>
                    </div>

                    <!-- Additional Information -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="civil_status">Civil Status *</label>
                            <select id="civil_status" name="civil_status" class="form-control" required>
                                <option value="">Select Status</option>
                                <option value="single">Single</option>
                                <option value="married">Married</option>
                                <option value="divorced">Divorced</option>
                                <option value="widowed">Widowed</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="philhealth_no">PhilHealth No. (Optional)</label>
                            <input type="text" id="philhealth_no" name="philhealth_no" class="form-control"
                                   placeholder="XX-XXXXXXXXX-X">
                        </div>
                    </div>

                    <!-- Medical History -->
                    <h3 style="color: #2E7D32; margin: 30px 0 20px;">Medical History / Notes</h3>
                    <div class="form-group">
                        <textarea id="medical_history" name="medical_history" class="form-control textarea"
                                  placeholder="Previous medical conditions, allergies, current medications, or other relevant medical information..."></textarea>
                    </div>

                    <!-- Emergency Contact -->
                    <h3 style="color: #2E7D32; margin: 30px 0 20px;">Emergency Contact</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="emergency_contact_name">Emergency Contact Name *</label>
                            <input type="text" id="emergency_contact_name" name="emergency_contact_name" 
                                   class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="emergency_contact_relationship">Relationship *</label>
                            <select id="emergency_contact_relationship" name="emergency_contact_relationship" 
                                    class="form-control" required>
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
                            <label for="emergency_contact_phone">Emergency Contact Number *</label>
                            <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" 
                                   class="form-control" required placeholder="09XX-XXX-XXXX">
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions" style="margin-top: 40px; display: flex; gap: 15px; justify-content: flex-end;">
                         <a href="admin_patient management.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-user-plus"></i> Save Patient
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Calculate age when birthdate changes
        document.getElementById('date_of_birth').addEventListener('change', function() {
            const birthDate = new Date(this.value);
            const today = new Date();
            let age = today.getFullYear() - birthDate.getFullYear();
            const monthDiff = today.getMonth() - birthDate.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            
            document.getElementById('age').value = age + ' years old';
        });

        // Form validation
        document.getElementById('patientForm').addEventListener('submit', function(e) {
            const phone = document.getElementById('phone').value;
            const emergencyPhone = document.getElementById('emergency_contact_phone').value;
            
            // Simple phone number validation for Philippines
            const phoneRegex = /^09\d{9}$/;
            
            if (!phoneRegex.test(phone.replace(/[-\s]/g, ''))) {
                alert('Please enter a valid Philippine mobile number (09XXXXXXXXX)');
                e.preventDefault();
                return false;
            }
            
            if (!phoneRegex.test(emergencyPhone.replace(/[-\s]/g, ''))) {
                alert('Please enter a valid emergency contact number (09XXXXXXXXX)');
                e.preventDefault();
                return false;
            }
            
            // Check if patient is minor (under 18) and emergency contact is provided
            const birthDate = new Date(document.getElementById('date_of_birth').value);
            const today = new Date();
            let age = today.getFullYear() - birthDate.getFullYear();
            const monthDiff = today.getMonth() - birthDate.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            
            if (age < 18) {
                const emergencyName = document.getElementById('emergency_contact_name').value.trim();
                if (!emergencyName) {
                    alert('Emergency contact is required for patients under 18 years old.');
                    e.preventDefault();
                    return false;
                }
            }
        });

        // Format phone numbers as user types
        function formatPhoneNumber(input) {
            let value = input.value.replace(/\D/g, '');
            if (value.length >= 11) {
                value = value.substring(0, 11);
                input.value = value.substring(0, 4) + '-' + value.substring(4, 7) + '-' + value.substring(7);
            }
        }

        document.getElementById('phone').addEventListener('input', function() {
            formatPhoneNumber(this);
        });

        document.getElementById('emergency_contact_phone').addEventListener('input', function() {
            formatPhoneNumber(this);
        });
    </script>

    <style>
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
        
        .form-actions {
            padding-top: 30px;
            border-top: 1px solid #E0E0E0;
        }
        
        .form-group label {
            font-size: 14px;
            margin-bottom: 6px;
        }
        
        .form-control:focus {
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }
        
        .btn {
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
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
            background: white;
            color: #666;
            border: 2px solid #E0E0E0;
        }
        
        .btn-secondary:hover {
            border-color: #4CAF50;
            color: #4CAF50;
        }
    </style>
</body>
</html>