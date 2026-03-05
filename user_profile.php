<?php
session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/residency_verification_helper.php')) {
    require_once 'residency_verification_helper.php';
}

// Guard: must be logged in
if (!isset($_SESSION['user'])) {
    header('Location: Login.php');
    exit;
}

$userId = (int)$_SESSION['user']['id'];

// Handle profile picture upload
$uploadMessage = '';
$uploadError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_profile_picture') {
    // Check if photo_path column exists, if not add it
    try {
        $checkColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'photo_path'");
        if ($checkColumn->rowCount() == 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN photo_path VARCHAR(255) DEFAULT NULL");
        }
    } catch(PDOException $e) {
        // Column might already exist, continue
    }
    
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['profile_picture'];
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
        $max_bytes = 5 * 1024 * 1024; // 5 MB
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed_ext) && $f['size'] <= $max_bytes) {
            // Ensure upload directory exists
            $uploadDir = __DIR__ . '/uploads/profile_pictures';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Get old photo path to delete it later
            $stmt = $pdo->prepare('SELECT photo_path FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $oldPhoto = $stmt->fetchColumn();
            
            // Generate unique filename
            $newName = 'user_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $dst = $uploadDir . '/' . $newName;
            
            if (move_uploaded_file($f['tmp_name'], $dst)) {
                $photo_path = 'uploads/profile_pictures/' . $newName;
                
                // Update database
                $stmt = $pdo->prepare('UPDATE users SET photo_path = ? WHERE id = ?');
                $stmt->execute([$photo_path, $userId]);
                
                // Delete old photo if it exists
                if ($oldPhoto && file_exists($oldPhoto)) {
                    @unlink($oldPhoto);
                }
                
                $uploadMessage = 'Profile picture updated successfully!';
                // Redirect to avoid resubmission
                header('Location: user_profile.php?success=1');
                exit;
            } else {
                $uploadError = 'Failed to upload image. Please try again.';
            }
        } else {
            if (!in_array($ext, $allowed_ext)) {
                $uploadError = 'Invalid file format. Only JPG, PNG, and GIF are allowed.';
            } else {
                $uploadError = 'File size exceeds 5MB limit.';
            }
        }
    } else {
        $uploadError = 'No file selected or upload error occurred.';
    }
}

// Update height and weight (Medical Info) — supports unit conversion (cm/in/ft, kg/lbs)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_medical_vitals') {
    $height_cm = null;
    $weight_kg = null;

    // Height: from height_value + height_unit (convert) or from height_cm
    $height_value = trim($_POST['height_value'] ?? '');
    $height_unit = $_POST['height_unit'] ?? 'cm';
    $height_cm_post = isset($_POST['height_cm']) ? trim($_POST['height_cm']) : '';
    if ($height_value !== '') {
        if ($height_unit === 'ft') {
            if (preg_match('/^(\d{1,2})\'(\d{0,2})$/', $height_value, $m)) {
                $feet = (int) $m[1];
                $inches = (int) $m[2];
                if ($feet <= 10 && $inches <= 11) {
                    $total_in = $feet * 12 + $inches;
                    $height_cm = round($total_in * 2.54, 2);
                }
            }
        } elseif ($height_unit === 'in') {
            $num = (float) $height_value;
            if ($num <= 120 && $num >= 0) $height_cm = round($num * 2.54, 2);
        } else {
            $num = (float) $height_value;
            if ($num <= 304.8 && $num >= 0) $height_cm = round($num, 2);
        }
    }
    if ($height_cm === null && $height_cm_post !== '') {
        $n = filter_var($height_cm_post, FILTER_VALIDATE_FLOAT);
        if ($n !== false && $n >= 0 && $n <= 300) $height_cm = $n;
    }

    // Weight: from weight_value + weight_unit (convert) or from weight_kg
    $weight_value = trim($_POST['weight_value'] ?? '');
    $weight_unit = $_POST['weight_unit'] ?? 'kg';
    $weight_kg_post = isset($_POST['weight_kg']) ? trim($_POST['weight_kg']) : '';
    if ($weight_value !== '') {
        $num = (float) preg_replace('/[^0-9.]/', '', $weight_value);
        if ($num <= 999 && $num >= 0) {
            $weight_kg = ($weight_unit === 'lbs') ? round($num * 0.45359237, 2) : round($num, 2);
        }
    }
    if ($weight_kg === null && $weight_kg_post !== '') {
        $n = filter_var($weight_kg_post, FILTER_VALIDATE_FLOAT);
        if ($n !== false && $n >= 0 && $n <= 999) $weight_kg = $n;
    }

    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM patient_profiles LIKE 'height_cm'");
        if ($checkCol->rowCount() == 0) {
            $pdo->exec("ALTER TABLE patient_profiles ADD COLUMN height_cm DECIMAL(6,2) NULL");
        }
        $checkCol = $pdo->query("SHOW COLUMNS FROM patient_profiles LIKE 'weight_kg'");
        if ($checkCol->rowCount() == 0) {
            $pdo->exec("ALTER TABLE patient_profiles ADD COLUMN weight_kg DECIMAL(6,2) NULL");
        }
        $stmt = $pdo->prepare('SELECT height_cm, weight_kg FROM patient_profiles WHERE patient_id = ?');
        $stmt->execute([$userId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        $currentHeight = $current && $current['height_cm'] !== null ? $current['height_cm'] : null;
        $currentWeight = $current && $current['weight_kg'] !== null ? $current['weight_kg'] : null;
        $saveHeight = $height_cm !== null ? $height_cm : $currentHeight;
        $saveWeight = $weight_kg !== null ? $weight_kg : $currentWeight;
        $stmt = $pdo->prepare('INSERT INTO patient_profiles (patient_id, height_cm, weight_kg) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE height_cm = VALUES(height_cm), weight_kg = VALUES(weight_kg)');
        $stmt->execute([$userId, $saveHeight, $saveWeight]);
        header('Location: user_profile.php?updated_vitals=1');
        exit;
    } catch (PDOException $e) {
        error_log('Profile update_medical_vitals: ' . $e->getMessage());
    }
}

// Check if photo_path column exists, if not add it
try {
    $checkColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'photo_path'");
    if ($checkColumn->rowCount() == 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN photo_path VARCHAR(255) DEFAULT NULL");
    }
} catch(PDOException $e) {
    // Column might already exist, continue
}

// Always get account owner's data from users table first (not from patients table which may have dependents)
$stmt = $pdo->prepare('SELECT id, username, email, first_name, middle_name, last_name, suffix, contact_no, address, role, created_at, photo_path FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$u = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Fetch patient profile data (medical information) - this is linked to the user_id
$stmt = $pdo->prepare('SELECT * FROM patient_profiles WHERE patient_id = ? LIMIT 1');
$stmt->execute([$userId]);
$patientProfile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Get patient record only if it matches the user's own name (not a dependent)
$patient = [];
if ($u && !empty($u['first_name']) && !empty($u['last_name'])) {
    $stmt = $pdo->prepare('SELECT * FROM patients WHERE created_by_user_id = ? AND first_name = ? AND last_name = ? ORDER BY created_at ASC LIMIT 1');
    $stmt->execute([$userId, $u['first_name'], $u['last_name']]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Get last visit date from appointments
$lastVisit = 'No visits yet';
if ($patient && isset($patient['id'])) {
    $stmt = $pdo->prepare('SELECT MAX(start_datetime) as last_visit FROM appointments WHERE (patient_id = ? OR user_id = ?) AND status IN ("approved", "completed")');
    $stmt->execute([$patient['id'], $userId]);
} else {
    $stmt = $pdo->prepare('SELECT MAX(start_datetime) as last_visit FROM appointments WHERE user_id = ? AND status IN ("approved", "completed")');
    $stmt->execute([$userId]);
}
$lastVisitData = $stmt->fetch(PDO::FETCH_ASSOC);
if ($lastVisitData && $lastVisitData['last_visit']) {
    $lastVisit = date('F j, Y', strtotime($lastVisitData['last_visit']));
}

// Get current medications from prescriptions
$medications = [];
if ($patient && isset($patient['id'])) {
    $stmt = $pdo->prepare('SELECT medication, dosage FROM prescriptions WHERE patient_id = ? AND status = "active" ORDER BY created_at DESC');
    $stmt->execute([$patient['id']]);
    $medications = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Derive display fields - always use account owner's data from users table
$displayName = '';
if ($u) {
    $displayName = trim(($u['first_name'] ?? '') . ' ' . ($u['middle_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    if (!empty($u['suffix'])) {
        $displayName .= ' ' . $u['suffix'];
    }
}
if (empty($displayName)) {
    $displayName = $u['username'] ?? 'User';
}

// Always use account owner's data from users table
$contactNo = $u['contact_no'] ?? '';
$address = $u['address'] ?? '';
$email = $u['email'] ?? '';
$dateRegistered = isset($u['created_at']) ? date('F j, Y', strtotime($u['created_at'])) : '';

// Date of birth and gender - check patient_profiles first, then patients table
$dob = $patientProfile['date_of_birth'] ?? $patient['dob'] ?? '';
$gender = '';
if ($patientProfile && isset($patientProfile['gender'])) {
    $gender = ucfirst($patientProfile['gender']);
} elseif ($patientProfile && isset($patientProfile['sex'])) {
    $gender = ucfirst($patientProfile['sex']);
} elseif ($patient && isset($patient['sex'])) {
    $sex = $patient['sex'];
    if ($sex === 'Male' || strtolower($sex) === 'male') $gender = 'Male';
    elseif ($sex === 'Female' || strtolower($sex) === 'female') $gender = 'Female';
    elseif ($sex === 'Prefer not to say') $gender = 'Prefer not to say';
    else $gender = ucfirst(strtolower($sex));
}

// Calculate age
$age = '';
if ($dob) {
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
}

// Format DOB for display
$dobFormatted = $dob ? date('F j, Y', strtotime($dob)) : '';

// All fields from signup - check patient_profiles first, then patients table
$height = $patientProfile['height_cm'] ?? $patient['height_cm'] ?? '';
$weight = $patientProfile['weight_kg'] ?? $patient['weight_kg'] ?? '';
$bloodType = $patientProfile['blood_type'] ?? $patient['blood_type'] ?? 'Not specified';
$allergies = $patientProfile['allergies'] ?? $patient['allergies'] ?? '';

// Medical conditions from patients table or patient_profiles
$medicalConditions = [];
$medicalConditionOther = '';
if ($patient && isset($patient['medical_conditions']) && $patient['medical_conditions']) {
    $mcJson = $patient['medical_conditions'];
    $medicalConditions = json_decode($mcJson, true) ?: [];
}
if ($patient && isset($patient['medical_condition_other'])) {
    $medicalConditionOther = $patient['medical_condition_other'];
}
// Also check medical_history from patient_profiles
if ($patientProfile && isset($patientProfile['medical_history']) && $patientProfile['medical_history']) {
    $medicalHistory = $patientProfile['medical_history'];
    // If it's JSON, decode it; otherwise use as-is
    $decoded = json_decode($medicalHistory, true);
    if ($decoded !== null) {
        $medicalConditions = array_merge($medicalConditions, $decoded);
    } elseif (!empty($medicalHistory)) {
        $medicalConditions[] = $medicalHistory;
    }
}

// Emergency contact info - check patient_profiles first, then patients table
$emergencyName = $patientProfile['emergency_contact_name'] ?? $patient['emergency_name'] ?? '';
$emergencyRelationship = $patientProfile['emergency_contact_relationship'] ?? $patient['emergency_relationship'] ?? '';
$emergencyContact = $patientProfile['emergency_contact_phone'] ?? '';
// If not in patient_profiles, try to parse from patients.emergency_contact (format: "Name - Relationship - Phone")
if (empty($emergencyContact) && isset($patient['emergency_contact']) && $patient['emergency_contact']) {
    $parts = explode(' - ', $patient['emergency_contact']);
    if (count($parts) >= 3) {
        $emergencyName = $parts[0] ?? $emergencyName;
        $emergencyRelationship = $parts[1] ?? $emergencyRelationship;
        $emergencyContact = $parts[2] ?? $emergencyContact;
    }
}

// Priority category
$priorityCategory = $patient['priority_category'] ?? '';
$priorityOther = $patient['priority_other'] ?? '';

// Patient ID - format as P-YYYY-XXXXX
$patientId = 'P-' . date('Y', strtotime($u['created_at'] ?? 'now')) . '-' . str_pad($userId, 5, '0', STR_PAD_LEFT);

// Payatas Residency Verification - fetch status directly from DB so tab always renders
$residencyStatus = ['status' => 'not_verified', 'rejected_reason' => null];
$residencyRequest = null;
$residencyDocuments = [];
$canSubmitResidency = true;
try {
    if (function_exists('ensureResidencyVerificationSchema')) {
        ensureResidencyVerificationSchema();
    }
} catch (Throwable $e) {
    error_log("Profile residency schema: " . $e->getMessage());
}
try {
    $stmt = $pdo->prepare("SELECT COALESCE(residency_status, 'not_verified') AS status, residency_rejected_reason AS rejected_reason FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $residencyStatus['status'] = $row['status'] ?? 'not_verified';
        $residencyStatus['rejected_reason'] = $row['rejected_reason'] ?? null;
    }
} catch (Throwable $e) {
    error_log("Profile residency status: " . $e->getMessage());
}
$residencyVerified = isset($residencyStatus['status']) && $residencyStatus['status'] === 'verified';
try {
    $stmt = $pdo->prepare("SELECT id FROM residency_verification_requests WHERE user_id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$userId]);
    $canSubmitResidency = !$stmt->fetch();
} catch (Throwable $e) {
    error_log("Profile residency pending check: " . $e->getMessage());
}
try {
    $stmt = $pdo->prepare("SELECT * FROM residency_verification_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $residencyRequest = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($residencyRequest && !empty($residencyRequest['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM residency_verification_documents WHERE request_id = ? ORDER BY id");
        $stmt->execute([$residencyRequest['id']]);
        $residencyDocuments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log("Profile residency documents: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HealthServe - My Profile</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="user_profile.css">
    <style>
        .notification-container{position:relative}
        .notification-btn{background:#4CAF50;border:none;border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .3s ease;box-shadow:0 2px 8px rgba(76,175,80,.3);position:relative}
        .notification-btn:hover{background:#45a049;transform:translateY(-1px);box-shadow:0 4px 12px rgba(76,175,80,.4)}
        .notification-badge{position:absolute;top:-5px;right:-5px;background:#ff4444;color:#fff;border-radius:50%;width:20px;height:20px;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center}
        .notification-dropdown{position:absolute;top:50px;right:0;width:350px;background:#fff;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.15);opacity:0;visibility:hidden;transform:translateY(-10px);transition:all .3s ease;z-index:1000;border:1px solid #e0e0e0}
        .notification-dropdown.active{opacity:1;visibility:visible;transform:translateY(0)}
        .notification-header{padding:16px 20px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center}
        .notification-title{font-size:16px;font-weight:600;color:#333}
        .clear-all{color:#4CAF50;font-size:14px;cursor:pointer;text-decoration:none}
        .notification-list{max-height:400px;overflow-y:auto}
        .notification-item{padding:16px 20px;border-bottom:1px solid #f8f8f8;cursor:pointer;transition:all .3s ease;display:flex;gap:12px;text-decoration:none;color:inherit}
        .notification-item:hover{background:#f8fdf8}
        .notification-item:last-child{border-bottom:none}
        .notification-icon-wrapper{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .notification-icon-wrapper.appointment{background:rgba(156,39,176,.1);color:#9C27B0}
        .notification-icon-wrapper.record_update{background:rgba(33,150,243,.1);color:#2196F3}
        .notification-icon-wrapper.prescription{background:rgba(33,150,243,.1);color:#2196F3}
        .notification-icon-wrapper.residency_verification{background:rgba(46,125,50,.1);color:#2e7d32}
        .notification-icon-wrapper.announcement{background:rgba(255,193,7,.1);color:#FFC107}
        .notification-content{flex:1}
        .notification-text{font-size:14px;color:#333;margin-bottom:4px;line-height:1.4}
        .notification-time{font-size:12px;color:#888}
        .notification-dot{width:8px;height:8px;background:#4CAF50;border-radius:50%;margin-left:auto;flex-shrink:0}
        .notification-item.read .notification-dot{display:none}
        .notification-overlay{display:none}
        @media (max-width:768px){.notification-dropdown{width:300px;right:-20px}}
        
        /* Profile Picture Upload Styles */
        .change-picture-btn {
            margin-top: 15px;
            padding: 10px 20px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .change-picture-btn:hover {
            background: #45a049;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
        }
        .change-picture-btn.btn-pending-disabled,
        .change-picture-btn:disabled {
            cursor: not-allowed;
            opacity: 0.8;
        }
        .change-picture-btn.btn-pending-disabled:hover,
        .change-picture-btn:disabled:hover {
            transform: none;
        }
        .profile-image-buttons-upload-id-wrap {
            margin-top: 15px;
            align-items: center;
        }
        .profile-image-buttons-upload-id-wrap .change-picture-btn {
            margin-top: 0;
        }
        .residency-pending-note {
            display: inline-flex;
            align-items: center;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            color: #856404;
            background: #fff3cd;
            border-radius: 8px;
            line-height: 1;
            box-sizing: border-box;
        }
        .profile-image {
            position: relative;
            text-align: center;
        }
        .upload-message {
            margin-top: 15px;
            padding: 10px;
            border-radius: 8px;
            font-size: 14px;
        }
        .upload-message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .upload-message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        #profilePicture {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        .btn-verified-disabled {
            background: #81c784 !important;
            color: #1b5e20 !important;
        }
        .btn-verified-disabled:hover {
            transform: none;
            box-shadow: 0 2px 8px rgba(76, 175, 80, 0.25);
        }
    </style>
</head>
<body>
    <!-- Header - matches dashboard exactly -->
    <header class="header">
        <div class="header-logo">
            <img src="assets/payatas logo.png" alt="Payatas B Logo">
            <h1>HealthServe - Payatas B</h1>
        </div>
        <nav class="header-nav">
            <a href="user_main_dashboard.php">Dashboard</a>
            <a href="user_records.php">My Record</a>
            <a href="user_appointments.php">Appointments</a>
            <a href="health_tips.php">Announcements</a>
        </nav>
        <div class="header-user">
            <div class="notification-container">
                <button class="notification-btn" id="notificationBtn">
                    <span class="notification-icon">🔔</span>
                    <span class="notification-badge" id="notificationBadge" style="display:none">0</span>
                </button>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <span class="notification-title">Notifications</span>
                        <a href="#" class="clear-all" id="clearAll">Clear all</a>
                    </div>
                    <div class="notification-list" id="notificationList"></div>
                </div>
            </div>
            <a href="user_profile.php" title="My Profile" style="text-decoration:none">
                <div class="user-avatar" style="background:#2e7d32; overflow: hidden; position: relative;">
                    <?php if (!empty($u['photo_path']) && file_exists($u['photo_path'])): ?>
                        <img src="<?php echo htmlspecialchars($u['photo_path']); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                    <?php else: ?>
                        👤
                    <?php endif; ?>
                </div>
            </a>
            <a href="logout.php" class="btn-logout">Log out</a>
        </div>
    </header>
    <div class="notification-overlay" id="notificationOverlay"></div>

    <!-- Main Content -->
    <main class="main-content">
        <h1 class="page-title">My Profile</h1>
        
<?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
            <div class="upload-message success" style="margin-bottom: 20px;">
                Profile picture updated successfully!
            </div>
            <?php endif; ?>
            <?php if (isset($_GET['updated_vitals']) && $_GET['updated_vitals'] == '1'): ?>
            <div class="upload-message success" style="margin-bottom: 20px;">
                Height and weight updated successfully!
            </div>
            <?php endif; ?>
        
        <?php if (!empty($uploadError)): ?>
            <div class="upload-message error" style="margin-bottom: 20px;">
                <?php echo htmlspecialchars($uploadError); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($_GET['verification'])): ?>
            <?php if ($_GET['verification'] === 'submitted'): ?>
            <div class="upload-message success" style="margin-bottom: 20px;">Your ID has been submitted for verification.</div>
            <?php elseif ($_GET['verification'] === 'error' && !empty($_GET['msg'])): ?>
            <div class="upload-message error" style="margin-bottom: 20px;"><?php echo htmlspecialchars($_GET['msg']); ?></div>
            <?php elseif ($_GET['verification'] === 'pending'): ?>
            <div class="upload-message error" style="margin-bottom: 20px;">You already have a pending verification request.</div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!$residencyVerified && !empty($residencyStatus['rejected_reason'])): ?>
            <div class="upload-message error" style="margin-bottom: 20px;">Your residency verification was declined. <strong>Reason:</strong> <?php echo htmlspecialchars($residencyStatus['rejected_reason']); ?> You may submit a new ID from below.</div>
        <?php endif; ?>

        <div class="profile-header" id="verification">
            <div class="profile-image">
                <div class="profile-avatar" id="profileAvatar">
                    <?php 
                    if (!empty($u['photo_path']) && file_exists($u['photo_path'])): 
                    ?>
                        <img src="<?php echo htmlspecialchars($u['photo_path']); ?>" alt="Profile Picture" id="profilePicture" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                    <?php else: ?>
                        <?php echo strtoupper(substr($displayName, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <form id="profilePictureForm" method="POST" enctype="multipart/form-data" style="display: none;">
                    <input type="hidden" name="action" value="upload_profile_picture">
                    <input type="file" id="profilePictureInput" name="profile_picture" accept="image/jpeg,image/jpg,image/png,image/gif">
                </form>
                <?php if (!$residencyVerified): ?>
                <form id="residencyIdForm" method="post" action="submit_residency_verification.php" enctype="multipart/form-data" style="display: none;">
                    <input type="file" id="residencyIdInput" name="government_id" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                </form>
                <?php endif; ?>
            </div>
            <div class="profile-info">
                <div class="profile-name"><?php echo htmlspecialchars($displayName); ?></div>
                <div class="profile-details">
                    <?php if ($age): ?>
                        <span><?php echo htmlspecialchars($age); ?> years old</span>
                    <?php endif; ?>
                    <?php if ($dobFormatted): ?>
                        <span><?php echo htmlspecialchars($dobFormatted); ?></span>
                    <?php endif; ?>
                    <?php if ($gender): ?>
                        <span><?php echo htmlspecialchars($gender); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="profile-image-buttons">
                <button class="change-picture-btn" onclick="document.getElementById('profilePictureInput').click()" title="Change Profile Picture">
                    <span style="font-size: 18px;">📷</span> Change Picture
                </button>
                <?php if ($residencyVerified): ?>
                <button type="button" class="change-picture-btn btn-verified-disabled" disabled style="cursor: not-allowed; opacity: 0.85;">
                    <span style="font-size: 18px;">✓</span> Already verified
                </button>
                <?php else:
                    $residencyPending = isset($canSubmitResidency) && !$canSubmitResidency;
                ?>
                <div class="profile-image-buttons-upload-id-wrap" style="display: inline-flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                    <button type="button" class="change-picture-btn <?php echo $residencyPending ? 'btn-pending-disabled' : ''; ?>" id="uploadIdForVerificationBtn" title="Upload ID for Payatas residency verification" <?php echo $residencyPending ? 'disabled' : ''; ?>>
                        <span style="font-size: 18px;">🪪</span> Upload ID for Verification
                    </button>
                    <?php if ($residencyPending): ?>
                    <span class="residency-pending-note">Approval is pending</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="tabs">
            <button class="tab active" data-tab="overview">Overview</button>
            <button class="tab" data-tab="medical">Medical Information</button>
            <button class="tab" data-tab="account">Account Info</button>
        </div>

        <div id="overview" class="tab-content active">
            <div class="content-card">
                <div class="card-title">Basic Information</div>
                <div class="info-row">
                    <div class="info-label">Full Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($displayName); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Address</div>
                    <div class="info-value"><?php echo htmlspecialchars($address ?: 'Not provided'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Contact Number</div>
                    <div class="info-value"><?php echo htmlspecialchars($contactNo ?: 'Not provided'); ?></div>
                </div>
                <?php if ($patientProfile && isset($patientProfile['marital_status']) && $patientProfile['marital_status']): ?>
                <div class="info-row">
                    <div class="info-label">Marital Status</div>
                    <div class="info-value"><?php echo htmlspecialchars(ucfirst($patientProfile['marital_status'])); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($patientProfile && isset($patientProfile['occupation']) && $patientProfile['occupation']): ?>
                <div class="info-row">
                    <div class="info-label">Occupation</div>
                    <div class="info-value"><?php echo htmlspecialchars($patientProfile['occupation']); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($patient && isset($patient['philhealth_no']) && $patient['philhealth_no']): ?>
                <div class="info-row">
                    <div class="info-label">PhilHealth Number</div>
                    <div class="info-value"><?php echo htmlspecialchars($patient['philhealth_no']); ?></div>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <div class="info-label">Last Visit</div>
                    <div class="info-value"><?php echo htmlspecialchars($lastVisit); ?></div>
                </div>
            </div>
        </div>

        <div id="medical" class="tab-content">
            <div class="medical-grid">
                <div class="content-card">
                    <div class="card-title">Medical Info</div>
                    <div class="info-row">
                        <div class="info-label">Blood Type</div>
                        <div class="info-value"><?php echo htmlspecialchars($bloodType); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Medical Conditions</div>
                        <div class="info-value">
                            <?php if (count($medicalConditions) > 0): ?>
                                <?php echo htmlspecialchars(implode(', ', $medicalConditions)); ?>
                                <?php if ($medicalConditionOther): ?>
                                    <br><em>Other: <?php echo htmlspecialchars($medicalConditionOther); ?></em>
                                <?php endif; ?>
                            <?php else: ?>
                                None
                            <?php endif; ?>
                        </div>
                    </div>
                    <div id="heightWeightView" class="vitals-view">
                    <!-- Height: view row -->
                    <div class="info-row" id="heightViewRow">
                        <div class="info-label">Height</div>
                        <div class="info-value" style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                            <span id="heightDisplay"><?php echo $height !== '' && $height !== null ? htmlspecialchars($height) . ' cm' : 'Not provided'; ?></span>
                            <button type="button" class="profile-edit-vitals-btn edit-height-btn" style="padding: 0.25rem 0.6rem; background: #2196F3; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500;">Edit</button>
                        </div>
                    </div>
                    <!-- Height: edit row (hidden by default) — validation same as signup: max 10 ft -->
                    <div class="info-row" id="heightEditRow" style="display: none;">
                        <div class="info-label">Height (max 10 ft)</div>
                        <div class="info-value" style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                            <form id="heightOnlyForm" method="post" action="user_profile.php" style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                <input type="hidden" name="action" value="update_medical_vitals">
                                <input type="hidden" name="weight_kg" value="<?php echo $weight !== '' && $weight !== null ? htmlspecialchars($weight) : ''; ?>">
                                <input type="text" name="height_value" id="profile_height_value" placeholder="e.g. 175 or 5'9" value="<?php echo $height !== '' && $height !== null ? htmlspecialchars($height) : ''; ?>" style="width: 100px; padding: 0.35rem 0.5rem; border: 1px solid #ccc; border-radius: 6px;" autocomplete="off">
                                <select name="height_unit" id="profile_height_unit" style="width: 70px; padding: 0.35rem 0.5rem; border: 1px solid #ccc; border-radius: 6px;">
                                    <option value="cm">cm</option>
                                    <option value="in">in</option>
                                    <option value="ft">ft</option>
                                </select>
                                <span id="height_equivalent" style="font-size: 0.85rem; color: #666;" aria-live="polite"></span>
                                <button type="submit" class="profile-save-vitals-btn" style="padding: 0.25rem 0.6rem; background: #4CAF50; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500;">Save</button>
                                <button type="button" class="cancel-edit-btn cancel-height-btn" style="padding: 0.25rem 0.6rem; background: #9e9e9e; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500;">Cancel</button>
                            </form>
                        </div>
                    </div>
                    <!-- Weight: view row -->
                    <div class="info-row" id="weightViewRow">
                        <div class="info-label">Weight</div>
                        <div class="info-value" style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                            <span id="weightDisplay"><?php echo $weight !== '' && $weight !== null ? htmlspecialchars($weight) . ' kg' : 'Not provided'; ?></span>
                            <button type="button" class="profile-edit-vitals-btn edit-weight-btn" style="padding: 0.25rem 0.6rem; background: #2196F3; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500;">Edit</button>
                        </div>
                    </div>
                    <!-- Weight: edit row (hidden by default) — validation same as signup: numbers only, max 999 -->
                    <div class="info-row" id="weightEditRow" style="display: none;">
                        <div class="info-label">Weight (max 999)</div>
                        <div class="info-value" style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                            <form id="weightOnlyForm" method="post" action="user_profile.php" style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                <input type="hidden" name="action" value="update_medical_vitals">
                                <input type="hidden" name="height_cm" value="<?php echo $height !== '' && $height !== null ? htmlspecialchars($height) : ''; ?>">
                                <input type="text" name="weight_value" id="profile_weight_value" placeholder="Numbers only" maxlength="3" value="<?php echo $weight !== '' && $weight !== null ? htmlspecialchars(round((float)$weight, 0)) : ''; ?>" style="width: 90px; padding: 0.35rem 0.5rem; border: 1px solid #ccc; border-radius: 6px;" inputmode="numeric" autocomplete="off">
                                <select name="weight_unit" id="profile_weight_unit" style="width: 70px; padding: 0.35rem 0.5rem; border: 1px solid #ccc; border-radius: 6px;">
                                    <option value="kg">kg</option>
                                    <option value="lbs">lbs</option>
                                </select>
                                <span id="weight_equivalent" style="font-size: 0.85rem; color: #666;" aria-live="polite"></span>
                                <button type="submit" class="profile-save-vitals-btn" style="padding: 0.25rem 0.6rem; background: #4CAF50; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500;">Save</button>
                                <button type="button" class="cancel-edit-btn cancel-weight-btn" style="padding: 0.25rem 0.6rem; background: #9e9e9e; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500;">Cancel</button>
                            </form>
                        </div>
                    </div>
                </div>
                    <div class="info-row">
                        <div class="info-label">Current Medications</div>
                        <div class="info-value">
                            <?php if (count($medications) > 0): ?>
                                <?php foreach ($medications as $med): ?>
                                    <?php echo htmlspecialchars($med['medication']); ?>
                                    <?php if ($med['dosage']): ?>
                                        (<?php echo htmlspecialchars($med['dosage']); ?>)
                                    <?php endif; ?>
                                    <br>
                                <?php endforeach; ?>
                            <?php else: ?>
                                None
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($patientProfile['medical_history'])): ?>
                <div class="content-card">
                    <div class="card-title">Medical History</div>
                    <div style="padding: 1rem; background: #f0f8f0; border-left: 4px solid #4CAF50; border-radius: 8px; margin-top: 1rem;">
                        <div style="color: #2e7d32; line-height: 1.6;">
                            <?php 
                            $medicalHistory = $patientProfile['medical_history'];
                            // If it's JSON, decode and display nicely
                            $decoded = json_decode($medicalHistory, true);
                            if ($decoded !== null && is_array($decoded)) {
                                echo htmlspecialchars(implode(', ', $decoded));
                            } else {
                                echo htmlspecialchars($medicalHistory);
                            }
                            ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($allergies)): ?>
                <div class="content-card" style="margin-top: 1.5rem;">
                    <div class="card-title">Allergies</div>
                    <div style="padding: 1rem; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 8px; margin-top: 1rem;">
                        <div style="color: #856404; line-height: 1.6;">
                            <?php echo htmlspecialchars($allergies); ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="account" class="tab-content">
            <div class="content-card">
                <div class="card-title">Account Info</div>
                <div class="info-row">
                    <div class="info-label">Patient ID</div>
                    <div class="info-value"><?php echo htmlspecialchars($patientId); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Date Registered</div>
                    <div class="info-value"><?php echo htmlspecialchars($dateRegistered); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Email</div>
                    <div class="info-value"><?php echo htmlspecialchars($email ?: 'Not provided'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Username</div>
                    <div class="info-value"><?php echo htmlspecialchars($u['username'] ?? 'Not provided'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Priority Category</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($priorityCategory ?: 'Not specified'); ?>
                        <?php if ($priorityOther): ?>
                            - <?php echo htmlspecialchars($priorityOther); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if ($emergencyName || $emergencyContact): ?>
            <div class="content-card" style="margin-top: 1.5rem;">
                <div class="card-title">Emergency Contact</div>
                <?php if ($emergencyName): ?>
                <div class="info-row">
                    <div class="info-label">Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($emergencyName); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($emergencyRelationship): ?>
                <div class="info-row">
                    <div class="info-label">Relationship</div>
                    <div class="info-value"><?php echo htmlspecialchars($emergencyRelationship); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($emergencyContact): ?>
                <div class="info-row">
                    <div class="info-label">Contact Number</div>
                    <div class="info-value"><?php echo htmlspecialchars($emergencyContact); ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
    </main>

    <!-- Reminder: ID must show Payatas address (shown before choosing file) -->
    <div id="residencyUploadReminderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 12px; padding: 1.75rem; max-width: 420px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.15); position: relative;">
            <button type="button" onclick="closeResidencyUploadReminder()" style="position: absolute; top: 1rem; right: 1rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666;">&times;</button>
            <div style="text-align: center; padding: 0.25rem 0;">
                <div style="width: 52px; height: 52px; margin: 0 auto 1rem; background: #E8F5E9; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                    <span style="font-size: 1.5rem;">🪪</span>
                </div>
                <h2 style="margin: 0 0 0.75rem 0; color: #2e3b4e; font-size: 1.2rem;">Before you upload</h2>
                <p style="margin: 0 0 1.25rem 0; color: #555; line-height: 1.5;">Please make sure your ID shows a <strong>Payatas address</strong>. Only IDs with a Payatas address will be accepted for residency verification.</p>
                <div style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap;">
                    <button type="button" onclick="closeResidencyUploadReminder()" style="padding: 0.75rem 1.25rem; background: #e0e0e0; color: #333; border: none; border-radius: 8px; cursor: pointer;">Cancel</button>
                    <button type="button" id="residencyReminderChooseFileBtn" style="padding: 0.75rem 1.5rem; background: #4CAF50; color: white; border: none; border-radius: 8px; cursor: pointer;">Choose file</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Preview selected ID image before submit -->
    <div id="residencyIdPreviewModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1001; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 12px; padding: 1.5rem; max-width: 520px; width: 90%; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 4px 20px rgba(0,0,0,0.15); position: relative;">
            <button type="button" onclick="closeResidencyIdPreview()" style="position: absolute; top: 1rem; right: 1rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666; z-index: 2;">&times;</button>
            <h2 style="margin: 0 0 1rem 0; color: #2e3b4e; font-size: 1.15rem;">Preview your ID</h2>
            <p style="margin: 0 0 0.75rem 0; color: #666; font-size: 0.9rem;">Confirm this is the correct image showing your Payatas address.</p>
            <div style="flex: 1; min-height: 200px; max-height: 60vh; background: #f5f5f5; border-radius: 8px; overflow: auto; display: flex; align-items: center; justify-content: center;">
                <img id="residencyIdPreviewImg" src="" alt="ID preview" style="max-width: 100%; max-height: 100%; object-fit: contain;">
            </div>
            <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.25rem;">
                <button type="button" onclick="closeResidencyIdPreview()" style="padding: 0.75rem 1.5rem; background: #e0e0e0; color: #333; border: none; border-radius: 8px; cursor: pointer;">Cancel</button>
                <button type="button" id="residencyIdPreviewSubmitBtn" style="padding: 0.75rem 1.5rem; background: #4CAF50; color: white; border: none; border-radius: 8px; cursor: pointer;">Submit for verification</button>
            </div>
        </div>
    </div>

    <script>
        // Real-time Notification System connected to database
        (function(){
            class NotificationSystem{
                constructor(){
                    this.notifications = [];
                    this.pollInterval = null;
                    this.init();
                }
                
                async init(){
                    await this.fetchNotifications();
                    this.bindEvents();
                    this.startPolling();
                    // Check for appointment reminders on load (only once per session)
                    if (!sessionStorage.getItem('remindersChecked')) {
                        this.checkAppointmentReminders();
                        sessionStorage.setItem('remindersChecked', 'true');
                    }
                }
                
                async fetchNotifications(){
                    try {
                        const response = await fetch('get_patient_notifications.php?action=fetch');
                        const data = await response.json();
                        if(data.success){
                            this.notifications = data.notifications.map(n => ({
                                id: n.id,
                                type: n.type,
                                text: n.message,
                                time: n.time_ago,
                                read: n.read,
                                reference_id: n.reference_id || null,
                                link: this.getLinkForType(n.type)
                            }));
                            this.renderNotifications();
                            this.updateBadge();
                        }
                    } catch(e){
                        console.error('Error fetching notifications:', e);
                    }
                }
                
                getLinkForType(type){
                    const links = {
                        'appointment': 'user_appointments.php',
                        'announcement': 'health_tips.php',
                        'record_update': 'user_records.php',
                        'prescription': 'user_records.php',
                        'residency_verification': 'user_profile.php'
                    };
                    return links[type] || '#';
                }
                
                getIconForType(type){
                    const icons = {
                        'appointment': '📅',
                        'announcement': '📢',
                        'record_update': '💊',
                        'prescription': '💊',
                        'residency_verification': '🪪'
                    };
                    return icons[type] || '🔔';
                }
                
                bindEvents(){
                    const nBtn = document.getElementById('notificationBtn');
                    const nDrop = document.getElementById('notificationDropdown');
                    const clear = document.getElementById('clearAll');
                    
                    if(!nBtn || !nDrop || !clear) return;
                    
                    nBtn.addEventListener('click', e => {
                        e.stopPropagation();
                        this.toggleDropdown();
                    });
                    
                    clear.addEventListener('click', e => {
                        e.preventDefault();
                        this.clearAllNotifications();
                    });
                    
                    document.addEventListener('click', e => {
                        if(!nDrop.contains(e.target) && !nBtn.contains(e.target)){
                            this.closeDropdown();
                        }
                    });
                    
                    document.addEventListener('keydown', e => {
                        if(e.key === 'Escape'){
                            this.closeDropdown();
                        }
                    });
                }
                
                renderNotifications(){
                    const list = document.getElementById('notificationList');
                    if(!list) return;
                    list.innerHTML = '';
                    
                    if(this.notifications.length === 0){
                        list.innerHTML = '<div style="padding: 2rem; text-align: center; color: #888;">No notifications</div>';
                        return;
                    }
                    
                    this.notifications.forEach(n => {
                        list.appendChild(this.createNotificationElement(n));
                    });
                }
                
                createNotificationElement(n){
                    const a = document.createElement('a');
                    a.className = `notification-item ${n.read ? 'read' : ''}`;
                    a.href = '#';
                    a.setAttribute('data-id', n.id);
                    a.innerHTML = `
                        <div class="notification-icon-wrapper ${n.type}">
                            <span>${this.getIconForType(n.type)}</span>
                        </div>
                        <div class="notification-content">
                            <div class="notification-text">${n.text}</div>
                            <div class="notification-time">${n.time}</div>
                        </div>
                        ${!n.read ? '<div class="notification-dot"></div>' : ''}
                    `;
                    a.addEventListener('click', e => {
                        e.preventDefault();
                        this.handleNotificationClick(n.id, n.link);
                    });
                    return a;
                }
                
                async handleNotificationClick(id, link){
                    const n = this.notifications.find(x => x.id === id);
                    if(n && !n.read){
                        try {
                            const formData = new FormData();
                            formData.append('notification_id', id);
                            await fetch('get_patient_notifications.php?action=mark_read', {
                                method: 'POST',
                                body: formData
                            });
                            n.read = true;
                            this.renderNotifications();
                            this.updateBadge();
                        } catch(e){
                            console.error('Error marking notification as read:', e);
                        }
                    }
                    
                    // Handle announcement clicks - open announcement modal
                    if(n && n.type === 'announcement' && n.reference_id){
                        this.openAnnouncementModal(n.reference_id);
                        this.closeDropdown();
                        return;
                    }
                    
                    if(link && link !== '#'){
                        window.location.href = link;
                    }
                    this.closeDropdown();
                }
                
                async openAnnouncementModal(announcementId){
                    try {
                        const response = await fetch('get_announcements.php');
                        const data = await response.json();
                        if(data.success){
                            const announcement = data.announcements.find(a => a.announcement_id == announcementId);
                            if(announcement){
                                window.location.href = `health_tips.php?announcement=${announcementId}`;
                            } else {
                                window.location.href = 'health_tips.php';
                            }
                        } else {
                            window.location.href = 'health_tips.php';
                        }
                    } catch(e){
                        console.error('Error loading announcement:', e);
                        window.location.href = 'health_tips.php';
                    }
                }
                
                toggleDropdown(){
                    const d = document.getElementById('notificationDropdown');
                    if(!d) return;
                    const isActive = d.classList.contains('active');
                    if(isActive){
                        this.closeDropdown();
                    } else {
                        d.classList.add('active');
                    }
                }
                
                closeDropdown(){
                    const d = document.getElementById('notificationDropdown');
                    if(!d) return;
                    d.classList.remove('active');
                }
                
                updateBadge(){
                    const b = document.getElementById('notificationBadge');
                    if(!b) return;
                    const unread = this.notifications.filter(n => !n.read).length;
                    if(unread > 0){
                        b.textContent = unread;
                        b.style.display = 'flex';
                    } else {
                        b.style.display = 'none';
                    }
                }
                
                async clearAllNotifications(){
                    try {
                        await fetch('get_patient_notifications.php?action=mark_all_read', {
                            method: 'POST'
                        });
                        this.notifications.forEach(n => n.read = true);
                        this.renderNotifications();
                        this.updateBadge();
                        this.closeDropdown();
                    } catch(e){
                        console.error('Error clearing notifications:', e);
                    }
                }
                
                startPolling(){
                    // Poll for new notifications every 10 seconds for real-time updates
                    this.pollInterval = setInterval(() => {
                        this.fetchNotifications();
                    }, 10000);
                }
                
                async checkAppointmentReminders(){
                    try {
                        await fetch('check_appointment_reminders.php');
                    } catch(e){
                        console.error('Error checking appointment reminders:', e);
                    }
                }
            }
            
            document.addEventListener('DOMContentLoaded', function(){
                window.notificationSystem = new NotificationSystem();
            });
        })();

        // Tab switching functionality
        const tabs = document.querySelectorAll('.tab');
        const tabContents = document.querySelectorAll('.tab-content');

        function switchToTab(targetTab) {
            if (!document.getElementById(targetTab)) return;
            tabs.forEach(t => t.classList.remove('active'));
            tabContents.forEach(tc => tc.classList.remove('active'));
            const tabBtn = document.querySelector('.tab[data-tab="' + targetTab + '"]');
            if (tabBtn) tabBtn.classList.add('active');
            document.getElementById(targetTab).classList.add('active');
        }

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                switchToTab(tab.getAttribute('data-tab'));
            });
        });

        if (window.location.search.indexOf('updated_vitals=1') !== -1) {
            switchToTab('medical');
        }

        // Height/Weight: Edit per row — only the clicked row becomes editable
        (function() {
            var heightViewRow = document.getElementById('heightViewRow');
            var heightEditRow = document.getElementById('heightEditRow');
            var weightViewRow = document.getElementById('weightViewRow');
            var weightEditRow = document.getElementById('weightEditRow');
            var editHeightBtn = document.querySelector('.edit-height-btn');
            var editWeightBtn = document.querySelector('.edit-weight-btn');
            var cancelHeightBtn = document.querySelector('.cancel-height-btn');
            var cancelWeightBtn = document.querySelector('.cancel-weight-btn');
            if (editHeightBtn && heightViewRow && heightEditRow) {
                editHeightBtn.addEventListener('click', function() {
                    heightViewRow.style.display = 'none';
                    heightEditRow.style.display = 'flex';
                });
            }
            if (cancelHeightBtn && heightViewRow && heightEditRow) {
                cancelHeightBtn.addEventListener('click', function() {
                    heightEditRow.style.display = 'none';
                    heightViewRow.style.display = 'flex';
                });
            }
            if (editWeightBtn && weightViewRow && weightEditRow) {
                editWeightBtn.addEventListener('click', function() {
                    weightViewRow.style.display = 'none';
                    weightEditRow.style.display = 'flex';
                });
            }
            if (cancelWeightBtn && weightViewRow && weightEditRow) {
                cancelWeightBtn.addEventListener('click', function() {
                    weightEditRow.style.display = 'none';
                    weightViewRow.style.display = 'flex';
                });
            }
        })();

        // Height/Weight validation — same as signup: height max 10 ft, weight numbers only max 999
        (function() {
            var IN_TO_CM = 2.54;
            var LBS_TO_KG = 0.45359237;
            var heightValueEl = document.getElementById('profile_height_value');
            var heightUnitEl = document.getElementById('profile_height_unit');
            var heightEquivEl = document.getElementById('height_equivalent');
            var weightValueEl = document.getElementById('profile_weight_value');
            var weightUnitEl = document.getElementById('profile_weight_unit');
            var weightEquivEl = document.getElementById('weight_equivalent');

            // Weight: numbers only, max 3 digits (max 999)
            if (weightValueEl) {
                weightValueEl.addEventListener('input', function() {
                    this.value = this.value.replace(/\D/g, '').slice(0, 3);
                    updateWeightEquivalent();
                });
            }

            // Height unit change: switch placeholder and clear invalid when switching to/from ft
            function heightUnitChanged() {
                var unit = heightUnitEl ? heightUnitEl.value : 'cm';
                if (heightValueEl) {
                    heightValueEl.placeholder = unit === 'ft' ? "e.g. 5'9" : 'e.g. 175';
                    if (unit === 'ft' && /^\d+(\.\d+)?$/.test(heightValueEl.value)) {
                        var num = parseFloat(heightValueEl.value);
                        if (num <= 10 && num === Math.floor(num)) heightValueEl.value = num + "'0";
                        else if (num > 0) heightValueEl.value = '';
                    } else if (unit !== 'ft' && heightValueEl.value.indexOf("'") !== -1) heightValueEl.value = '';
                }
                updateHeightEquivalent();
            }
            if (heightUnitEl) heightUnitEl.addEventListener('change', heightUnitChanged);

            // Height input: when ft format as 5'9 max 10'11; when cm/in numbers only with max
            if (heightValueEl) {
                heightValueEl.addEventListener('input', function() {
                    var unit = heightUnitEl ? heightUnitEl.value : 'cm';
                    if (unit === 'ft') {
                        var v = this.value.replace(/[^\d']/g, '');
                        if ((v.match(/'/g) || []).length > 1) v = v.substring(0, v.indexOf("'") + 1) + v.substring(v.indexOf("'") + 1).replace(/'/g, '');
                        var hasApos = v.indexOf("'") !== -1;
                        var feetPart = '', inchesPart = '';
                        if (hasApos) {
                            var parts = v.split("'");
                            feetPart = (parts[0] || '').replace(/\D/g, '').slice(0, 2);
                            inchesPart = (parts[1] || '').replace(/\D/g, '').slice(0, 2);
                        } else {
                            var digits = v.replace(/\D/g, '');
                            if (digits.length <= 1) feetPart = digits;
                            else if (digits.length === 2) { feetPart = digits[0]; inchesPart = digits[1]; }
                            else if (digits.substring(0, 2) === '10') { feetPart = '10'; inchesPart = digits.substring(2).slice(0, 2); }
                            else { feetPart = digits[0]; inchesPart = digits.substring(1).slice(0, 2); }
                        }
                        var f = feetPart === '' ? '' : Math.min(10, parseInt(feetPart, 10) || 0);
                        var i = inchesPart === '' ? '' : Math.min(11, parseInt(inchesPart, 10) || 0);
                        if (f !== '' && i !== '') this.value = f + "'" + i;
                        else if (f !== '') this.value = hasApos ? (f + "'" + (inchesPart || '')) : (inchesPart !== '' ? f + "'" + i : String(f));
                        else this.value = v;
                    } else {
                        var numOnly = this.value.replace(/[^\d.]/g, '');
                        var oneDecimal = numOnly.replace(/^(\d*)\.?\d*/, '$1') + (numOnly.indexOf('.') !== -1 ? '.' + numOnly.split('.')[1].slice(0, 1) : '');
                        this.value = oneDecimal;
                        var n = parseFloat(this.value);
                        if (!isNaN(n) && unit === 'cm' && n > 304.8) this.value = '304.8';
                        if (!isNaN(n) && unit === 'in' && n > 120) this.value = '120';
                    }
                    updateHeightEquivalent();
                });
            }

            function parseHeightToCm(val, unit) {
                if (!val || !unit) return null;
                if (unit === 'ft') {
                    var match = String(val).trim().match(/^(\d{1,2})'(\d{0,2})$/);
                    if (match) {
                        var feet = Math.min(10, parseInt(match[1], 10));
                        var inches = Math.min(11, parseInt(match[2], 10) || 0);
                        return (feet * 12 + inches) * IN_TO_CM;
                    }
                    return null;
                }
                var n = parseFloat(val);
                if (isNaN(n)) return null;
                if (unit === 'in') return n <= 120 && n >= 0 ? n * IN_TO_CM : null;
                return n <= 304.8 && n >= 0 ? n : null;
            }

            function updateHeightEquivalent() {
                if (!heightEquivEl) return;
                var unit = heightUnitEl ? heightUnitEl.value : 'cm';
                var val = heightValueEl ? heightValueEl.value.trim() : '';
                if (!val) { heightEquivEl.textContent = ''; return; }
                if (unit === 'ft') {
                    var cm = parseHeightToCm(val, 'ft');
                    if (cm == null || cm <= 0) { heightEquivEl.textContent = ''; return; }
                    heightEquivEl.textContent = '\u2248 ' + cm.toFixed(1) + ' cm';
                    return;
                }
                var n = parseFloat(val);
                if (isNaN(n) || n <= 0) { heightEquivEl.textContent = ''; return; }
                if (unit === 'cm') {
                    heightEquivEl.textContent = '\u2248 ' + (n / IN_TO_CM).toFixed(2) + ' in';
                } else {
                    heightEquivEl.textContent = '\u2248 ' + (n * IN_TO_CM).toFixed(2) + ' cm';
                }
            }

            function updateWeightEquivalent() {
                if (!weightEquivEl) return;
                var val = parseFloat(weightValueEl ? weightValueEl.value : '');
                if (isNaN(val) || val <= 0) { weightEquivEl.textContent = ''; return; }
                var unit = weightUnitEl ? weightUnitEl.value : 'kg';
                if (unit === 'kg') {
                    weightEquivEl.textContent = '\u2248 ' + (val / LBS_TO_KG).toFixed(2) + ' lbs';
                } else {
                    weightEquivEl.textContent = '\u2248 ' + (val * LBS_TO_KG).toFixed(2) + ' kg';
                }
            }

            if (heightValueEl) heightValueEl.addEventListener('input', updateHeightEquivalent);
            if (heightUnitEl) heightUnitEl.addEventListener('change', updateHeightEquivalent);
            if (weightUnitEl) weightUnitEl.addEventListener('change', updateWeightEquivalent);
            updateHeightEquivalent();
            updateWeightEquivalent();
        })();

        // Upload ID for Verification: reminder first -> choose file -> preview -> Cancel or Submit
        function openResidencyUploadReminder() {
            var m = document.getElementById('residencyUploadReminderModal');
            if (m) m.style.display = 'flex';
        }
        function closeResidencyUploadReminder() {
            var m = document.getElementById('residencyUploadReminderModal');
            if (m) m.style.display = 'none';
        }
        function openResidencyIdPreview(dataUrl) {
            var m = document.getElementById('residencyIdPreviewModal');
            var img = document.getElementById('residencyIdPreviewImg');
            if (m && img) {
                img.src = dataUrl;
                m.style.display = 'flex';
            }
        }
        function closeResidencyIdPreview() {
            var m = document.getElementById('residencyIdPreviewModal');
            if (m) m.style.display = 'none';
            var input = document.getElementById('residencyIdInput');
            if (input) input.value = '';
        }
        var residencyIdInput = document.getElementById('residencyIdInput');
        var residencyIdForm = document.getElementById('residencyIdForm');
        if (residencyIdInput && residencyIdForm) {
            // Button: show reminder modal instead of opening file picker directly
            var uploadIdBtn = document.getElementById('uploadIdForVerificationBtn');
            if (uploadIdBtn) {
                uploadIdBtn.addEventListener('click', function() {
                    openResidencyUploadReminder();
                });
            }
            // Reminder "Choose file" -> close reminder and open file picker
            var reminderChooseBtn = document.getElementById('residencyReminderChooseFileBtn');
            if (reminderChooseBtn) {
                reminderChooseBtn.addEventListener('click', function() {
                    closeResidencyUploadReminder();
                    residencyIdInput.click();
                });
            }
            // Backdrop click closes reminder
            var reminderModal = document.getElementById('residencyUploadReminderModal');
            if (reminderModal) {
                reminderModal.addEventListener('click', function(e) {
                    if (e.target === reminderModal) closeResidencyUploadReminder();
                });
            }
            // File selected -> validate, show preview modal (Cancel or Submit)
            residencyIdInput.addEventListener('change', function(e) {
                var file = e.target.files[0];
                if (!file) return;
                var allowed = ['image/jpeg', 'image/jpg', 'image/png'];
                if (!allowed.includes(file.type)) {
                    alert('Please choose a JPG or PNG image.');
                    e.target.value = '';
                    return;
                }
                if (file.size > 5 * 1024 * 1024) {
                    alert('File must be 5MB or less.');
                    e.target.value = '';
                    return;
                }
                var reader = new FileReader();
                reader.onload = function(ev) {
                    openResidencyIdPreview(ev.target.result);
                };
                reader.readAsDataURL(file);
            });
            // Preview modal: Cancel clears and closes; Submit sends to admin verification
            var previewModal = document.getElementById('residencyIdPreviewModal');
            if (previewModal) {
                previewModal.addEventListener('click', function(e) {
                    if (e.target === previewModal) closeResidencyIdPreview();
                });
            }
            var previewSubmitBtn = document.getElementById('residencyIdPreviewSubmitBtn');
            if (previewSubmitBtn) {
                previewSubmitBtn.addEventListener('click', function() {
                    previewModal = document.getElementById('residencyIdPreviewModal');
                    if (previewModal) previewModal.style.display = 'none';
                    residencyIdForm.submit();
                });
            }
        }

        // Profile Picture Upload Handler
        const profilePictureInput = document.getElementById('profilePictureInput');
        const profilePictureForm = document.getElementById('profilePictureForm');
        
        if (profilePictureInput && profilePictureForm) {
            profilePictureInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    // Validate file type
                    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                    if (!allowedTypes.includes(file.type)) {
                        alert('Invalid file format. Only JPG, PNG, and GIF are allowed.');
                        e.target.value = '';
                        return;
                    }
                    
                    // Validate file size (5MB)
                    if (file.size > 5 * 1024 * 1024) {
                        alert('File size exceeds 5MB limit.');
                        e.target.value = '';
                        return;
                    }
                    
                    // Show preview immediately
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const img = document.getElementById('profilePicture');
                        const avatar = document.getElementById('profileAvatar');
                        if (img) {
                            img.src = e.target.result;
                        } else {
                            // Create img element if it doesn't exist (replacing initial)
                            avatar.innerHTML = '<img src="' + e.target.result + '" alt="Profile Picture" id="profilePicture" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">';
                        }
                    };
                    reader.readAsDataURL(file);
                    
                    // Submit form after preview
                    profilePictureForm.submit();
                }
            });
        }
    </script>
</body>
</html>
