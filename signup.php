<?php
session_start();
require 'db.php'; // your PDO $pdo

// Check if PHPMailer is available
$phpmailer_loaded = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    $phpmailer_loaded = class_exists('PHPMailer\PHPMailer\PHPMailer');
}

// Helper function to check if column exists
function hasColumn($pdo, $table, $column) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) { 
        return false; 
    }
}

// Ensure email_verifications table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        email VARCHAR(150) NOT NULL,
        otp_code VARCHAR(6) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        verified_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user_id (user_id),
        KEY idx_email (email),
        KEY idx_otp_code (otp_code),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    // Table might already exist, ignore error
}

// Ensure email_verified column exists in users table
if (!hasColumn($pdo, 'users', 'email_verified')) {
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) DEFAULT 0");
    } catch (Exception $e) {
        // Column might already exist, ignore error
    }
}

// Ensure suffix column exists in users table
if (!hasColumn($pdo, 'users', 'suffix')) {
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN suffix VARCHAR(20) DEFAULT NULL AFTER last_name");
    } catch (Exception $e) {
        // Column might already exist, ignore error
    }
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect + sanitize
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $dob = $_POST['dob'] ?? null;
    $email = trim($_POST['email'] ?? '');
    $sex = $_POST['sex'] ?? null;
    $mobile = trim($_POST['mobile'] ?? '');
    $address = trim($_POST['address'] ?? '');
    // Default to Payatas, Quezon City (serviced area)
    $barangay = trim($_POST['barangay'] ?? 'Payatas');
    $city = trim($_POST['city'] ?? 'Quezon City');
    $street = trim($_POST['street_or_sitio'] ?? '');
    // Height/weight: optional; support unit conversion (store as cm and kg in DB)
    $height_value = trim($_POST['height_value'] ?? '');
    $height_unit = $_POST['height_unit'] ?? 'cm';
    $weight_value = trim($_POST['weight_value'] ?? '');
    $weight_unit = $_POST['weight_unit'] ?? 'kg';
    $height_cm = null;
    $weight_kg = null;
    if ($height_value !== '') {
        if ($height_unit === 'ft') {
            // Parse 5'9 or 5'10 format (feet'inches)
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
            if ($num <= 120) $height_cm = round($num * 2.54, 2); // max 10 ft
        } else {
            $num = (float) $height_value;
            if ($num <= 304.8) $height_cm = round($num, 2); // max 10 ft in cm
        }
    }
    if ($weight_value !== '') {
        $num = (float) preg_replace('/[^0-9.]/', '', $weight_value);
        if ($num <= 999) {
            $weight_kg = ($weight_unit === 'lbs') ? round($num * 0.45359237, 2) : round($num, 2);
        }
    }
    $blood_type = $_POST['blood_type'] ?? null;
    $medical_conditions = $_POST['medical_conditions'] ?? []; // array
    $medical_condition_other = trim($_POST['medical_condition_other'] ?? '');
    $suffix_val = trim($_POST['suffix'] ?? '');
    $suffix_allowed = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];
    $suffix = in_array($suffix_val, $suffix_allowed, true) ? $suffix_val : '';
    $emergency_suffix_val = trim($_POST['emergency_suffix'] ?? '');
    $emergency_suffix = in_array($emergency_suffix_val, $suffix_allowed, true) ? $emergency_suffix_val : '';
    $emergency_first_name = trim($_POST['emergency_first_name'] ?? '');
    $emergency_middle_name = trim($_POST['emergency_middle_name'] ?? '');
    $emergency_last_name = trim($_POST['emergency_last_name'] ?? '');
    $emergency_relationship = trim($_POST['emergency_relationship'] ?? '');
    $emergency_contact = trim($_POST['emergency_contact'] ?? '');
    $priority_category = $_POST['priority_category'] ?? null;
    $priority_other = trim($_POST['priority_other'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Names: letters only (no numbers)
    $name_fields = [
        'First name' => $first_name,
        'Middle name' => $middle_name,
        'Last name' => $last_name,
        'Emergency first name' => $emergency_first_name,
        'Emergency middle name' => $emergency_middle_name,
        'Emergency last name' => $emergency_last_name
    ];
    $name_has_number = false;
    foreach ($name_fields as $label => $val) {
        if ($val !== '' && preg_match('/[0-9]/', $val)) {
            $name_has_number = true;
            break;
        }
    }

    // Basic validation
    if ($first_name === '' || $last_name === '' || $mobile === '' || $password === '') {
        $err = 'Please fill all required fields';
    } elseif ($name_has_number) {
        $err = 'First name, middle name, and last name (patient and emergency contact) must contain letters only (no numbers).';
    } elseif ($password !== $confirm_password) {
        $err = 'Passwords do not match';
    } elseif ($emergency_first_name === '' || $emergency_last_name === '') {
        $err = 'Please fill all required emergency contact fields';
    } elseif ($barangay !== 'Payatas' || $city !== 'Quezon City') {
        $err = 'HealthServe is only for residents of Barangay Payatas, Quezon City. Please select Barangay: Payatas and City: Quezon City.';
    } else {
        // Normalize medical conditions to JSON (include other if provided)
        if (!is_array($medical_conditions)) $medical_conditions = [$medical_conditions];
        if ($medical_condition_other !== '') {
            $medical_conditions[] = 'Others: ' . $medical_condition_other;
        }
        $medical_conditions_json = json_encode(array_values($medical_conditions), JSON_UNESCAPED_UNICODE);

        if ($err === '') {
            try {
                // Wrap in transaction
                $pdo->beginTransaction();

                // Check mobile uniqueness (username)
                $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                $stmt->execute([$mobile]);
                if ($stmt->fetch()) {
                    $pdo->rollBack();
                    $err = 'Mobile number already registered';
                } else {
                    // Build full address from Barangay Payatas, Quezon City + optional street
                    $full_address = $street ? $street . ', ' : '';
                    $full_address .= 'Barangay Payatas, Quezon City';
                    if ($address && $address !== $full_address) {
                        $full_address = $address; // fallback if form sent raw address
                    }
                    // Insert user (using first_name, middle_name, last_name instead of full_name)
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $insert_cols = 'username, password_hash, email, role, first_name, middle_name, last_name, contact_no, address, created_at';
                    $insert_vals = '?,?,?,?,?,?,?,?,?, NOW()';
                    $insert_params = [$mobile, $hash, $email ?: null, 'patient', $first_name, $middle_name ?: null, $last_name, $mobile, $full_address];
                    if (hasColumn($pdo, 'users', 'barangay')) {
                        $insert_cols .= ', barangay, city';
                        $insert_vals .= ', ?, ?';
                        $insert_params[] = $barangay;
                        $insert_params[] = $city;
                    }
                    if (hasColumn($pdo, 'users', 'suffix')) {
                        $insert_cols .= ', suffix';
                        $insert_vals .= ', ?';
                        $insert_params[] = $suffix ?: null;
                    }
                    $stmt = $pdo->prepare("INSERT INTO users ($insert_cols) VALUES ($insert_vals)");
                    $stmt->execute($insert_params);

                    $user_id = $pdo->lastInsertId();
                    
                    // Log patient registration
                    require_once 'admin_helpers_simple.php';
                    logAuditEvent('Patient Registration', 'User Account', $user_id, "New patient registered: {$first_name} {$last_name} (Email: {$email})");

                    // Insert into patient_profiles table
                    $stmt = $pdo->prepare('INSERT INTO patient_profiles
                        (patient_id, date_of_birth, sex, gender, blood_type, height_cm, weight_kg, 
                         emergency_contact_name, emergency_contact_relationship, emergency_contact_phone,
                         medical_history, allergies)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');

                    // Convert sex to lowercase for database enum
                    $sex_db = strtolower($sex);
                    if ($sex_db === 'male' || $sex_db === 'female') {
                        $gender_db = $sex_db;
                    } else {
                        $gender_db = 'other';
                    }

                    $emergency_full_name = trim($emergency_first_name . ' ' . ($emergency_middle_name ? $emergency_middle_name . ' ' : '') . $emergency_last_name);
                    $emergency_contact_name = $emergency_suffix ? ($emergency_full_name . ' ' . $emergency_suffix) : $emergency_full_name;

                    $stmt->execute([
                        $user_id,
                        $dob ?: null,
                        $sex_db,
                        $gender_db,
                        $blood_type ?: null,
                        $height_cm !== '' ? $height_cm : null,
                        $weight_kg !== '' ? $weight_kg : null,
                        $emergency_contact_name ?: null,
                        $emergency_relationship ?: null,
                        $emergency_contact ?: null,
                        $medical_conditions_json ?: null,
                        null // allergies - can be added later if needed
                    ]);

                    // Insert into patients table (for legacy support - only columns that exist)
                    $stmt = $pdo->prepare('INSERT INTO patients
                        (first_name, middle_name, last_name, sex, dob, phone, address,
                         emergency_contact, created_by_user_id, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?, NOW())');

                    $stmt->execute([
                        $first_name,
                        $middle_name ?: null,
                        $last_name,
                        $sex_db,
                        $dob ?: null,
                        $mobile,
                        $full_address,
                        ($emergency_contact_name . ' - ' . $emergency_relationship . ' - ' . $emergency_contact) ?: null,
                        $user_id
                    ]);

                    // Generate OTP for email verification
                    $otp_code = str_pad((string)rand(100000, 999999), 6, '0', STR_PAD_LEFT);
                    $otp_expires = date('Y-m-d H:i:s', strtotime('+15 minutes')); // OTP expires in 15 minutes
                    
                    // Store OTP in database
                    $stmt = $pdo->prepare('INSERT INTO email_verifications (user_id, email, otp_code, expires_at) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$user_id, $email, $otp_code, $otp_expires]);
                    
                    // Set email_verified to 0 (unverified)
                    if (hasColumn($pdo, 'users', 'email_verified')) {
                        $stmt = $pdo->prepare('UPDATE users SET email_verified = 0 WHERE id = ?');
                        $stmt->execute([$user_id]);
                    }
                    
                    // Send OTP email
                    if ($phpmailer_loaded && !empty($email)) {
                        try {
                            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                            
                            // Load email configuration
                            $email_config = [];
                            if (file_exists('email_config.php')) {
                                $email_config = require 'email_config.php';
                            }
                            
                            // SMTP Configuration
                            $mail->isSMTP();
                            $mail->Host = $email_config['smtp_host'] ?? 'smtp.gmail.com';
                            $mail->SMTPAuth = true;
                            $mail->Username = $email_config['smtp_username'] ?? 'your-email@gmail.com';
                            $mail->Password = $email_config['smtp_password'] ?? 'your-app-password';
                            $mail->SMTPSecure = ($email_config['smtp_encryption'] ?? 'tls') === 'ssl' 
                                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS 
                                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port = $email_config['smtp_port'] ?? 587;
                            $mail->CharSet = 'UTF-8';
                            
                            // From address
                            $from_email = $email_config['from_email'] ?? 'noreply@healthserve.ph';
                            $from_name = $email_config['from_name'] ?? 'HealthServe - Payatas B';
                            
                            // Email content
                            $mail->setFrom($from_email, $from_name);
                            $mail->addAddress($email, trim($first_name . ' ' . $last_name));
                            $mail->isHTML(true);
                            $mail->Subject = 'Email Verification - HealthServe';
                            $mail->Body = "
                                <html>
                                <head>
                                    <style>
                                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                                        .header { background: #2E7D32; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                                        .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
                                        .otp-box { background: #E8F5E9; border: 2px solid #2E7D32; border-radius: 8px; padding: 20px; text-align: center; margin: 20px 0; }
                                        .otp-code { font-size: 32px; font-weight: bold; color: #2E7D32; letter-spacing: 5px; }
                                        .warning { color: #F57C00; font-weight: bold; margin-top: 20px; }
                                    </style>
                                </head>
                                <body>
                                    <div class='container'>
                                        <div class='header'>
                                            <h2>HealthServe - Payatas B</h2>
                                        </div>
                                        <div class='content'>
                                            <h3>Email Verification Required</h3>
                                            <p>Hello " . htmlspecialchars($first_name) . ",</p>
                                            <p>Thank you for signing up with HealthServe - Payatas B. Please verify your email address by entering the OTP code below:</p>
                                            
                                            <div class='otp-box'>
                                                <div style='font-size: 14px; color: #666; margin-bottom: 10px;'>Your verification code is:</div>
                                                <div class='otp-code'>" . htmlspecialchars($otp_code) . "</div>
                                            </div>
                                            
                                            <p>This code will expire in 15 minutes.</p>
                                            <p class='warning'>⚠️ If you did not create an account, please ignore this email.</p>
                                            <p>Best regards,<br>HealthServe - Payatas B Team</p>
                                        </div>
                                    </div>
                                </body>
                                </html>
                            ";
                            
                            $mail->send();
                        } catch (Exception $e) {
                            // Log error but don't fail registration - user can request resend
                            error_log("Failed to send OTP email: " . $e->getMessage());
                        }
                    }
                    
                    // Commit
                    $pdo->commit();

                    // Store user_id in session for verification page
                    $_SESSION['pending_verification_user_id'] = $user_id;
                    $_SESSION['pending_verification_email'] = $email;
                    
                    // Redirect to email verification page
                    header('Location: verify_email.php');
                    exit;
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $err = 'An error occurred while creating account: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign up - HealthServe Payatas B</title>
    <link rel="stylesheet" href="assets/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="auth-container">
        <div class="auth-card signup-card">
            <div class="auth-title">Sign up</div>
            
            <?php if($err): ?>
                <div class="alert alert-error"><?=htmlspecialchars($err)?></div>
            <?php endif; ?>
            
            <form method="post">
                <div class="form-section">
                    <div class="section-title">Personal Information</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name*</label>
                            <input type="text" id="first_name" name="first_name" class="form-input" required value="<?=htmlspecialchars($_POST['first_name'] ?? '')?>">
                        </div>
                        <div class="form-group">
                            <label for="middle_name">Middle Name</label>
                            <input type="text" id="middle_name" name="middle_name" class="form-input" value="<?=htmlspecialchars($_POST['middle_name'] ?? '')?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="last_name">Last Name*</label>
                            <input type="text" id="last_name" name="last_name" class="form-input" required value="<?=htmlspecialchars($_POST['last_name'] ?? '')?>">
                        </div>
                        <div class="form-group" style="max-width: 180px;">
                            <label for="suffix">Suffix</label>
                            <select id="suffix" name="suffix" class="form-input">
                                <option value="" <?=empty($_POST['suffix']) ? 'selected' : ''?>>None</option>
                                <option value="Jr." <?=($_POST['suffix'] ?? '') === 'Jr.' ? 'selected' : ''?>>Jr.</option>
                                <option value="Sr." <?=($_POST['suffix'] ?? '') === 'Sr.' ? 'selected' : ''?>>Sr.</option>
                                <option value="II" <?=($_POST['suffix'] ?? '') === 'II' ? 'selected' : ''?>>II</option>
                                <option value="III" <?=($_POST['suffix'] ?? '') === 'III' ? 'selected' : ''?>>III</option>
                                <option value="IV" <?=($_POST['suffix'] ?? '') === 'IV' ? 'selected' : ''?>>IV</option>
                                <option value="V" <?=($_POST['suffix'] ?? '') === 'V' ? 'selected' : ''?>>V</option>
                                <option value="VI" <?=($_POST['suffix'] ?? '') === 'VI' ? 'selected' : ''?>>VI</option>
                                <option value="VII" <?=($_POST['suffix'] ?? '') === 'VII' ? 'selected' : ''?>>VII</option>
                                <option value="VIII" <?=($_POST['suffix'] ?? '') === 'VIII' ? 'selected' : ''?>>VIII</option>
                                <option value="IX" <?=($_POST['suffix'] ?? '') === 'IX' ? 'selected' : ''?>>IX</option>
                                <option value="X" <?=($_POST['suffix'] ?? '') === 'X' ? 'selected' : ''?>>X</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="dob">Date of Birth*</label>
                            <input type="date" id="dob" name="dob" class="form-input" required value="<?=htmlspecialchars($_POST['dob'] ?? '')?>">
                        </div>
                        <div class="form-group">
                            <label for="email">Email*</label>
                            <input type="email" id="email" name="email" class="form-input" required value="<?=htmlspecialchars($_POST['email'] ?? '')?>">
                        </div>
                    <div class="form-group" style="max-width: 300px;">
                        <label for="sex">Sex*</label>
                        <select id="sex" name="sex" class="form-input" required>
                            <option value="" disabled <?=empty($_POST['sex']) ? 'selected' : ''?>>Select</option>
                            <option value="Male" <?=(($_POST['sex'] ?? '') === 'Male') ? 'selected' : ''?>>Male</option>
                            <option value="Female" <?=(($_POST['sex'] ?? '') === 'Female') ? 'selected' : ''?>>Female</option>
                            <option value="Prefer not to say" <?=(($_POST['sex'] ?? '') === 'Prefer not to say') ? 'selected' : ''?>>Prefer not to say</option>
                        </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-title">Contact Information</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="mobile">Mobile Number*</label>
                            <input type="tel" id="mobile" name="mobile" class="form-input" required maxlength="11" pattern="\d{11}" inputmode="numeric" value="<?=htmlspecialchars($_POST['mobile'] ?? '')?>">
                        </div>
                    </div>
                    <p style="font-size: 13px; color: #666; margin-bottom: 0.5rem;">HealthServe serves only residents of <strong>Barangay Payatas, Quezon City</strong>. Your address must be within this area.</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="barangay">Barangay*</label>
                            <div class="form-input" style="background:#f0f0f0; color:#333; cursor:default;" aria-readonly="true">Payatas</div>
                            <input type="hidden" name="barangay" id="barangay" value="Payatas">
                        </div>
                        <div class="form-group">
                            <label for="city">City*</label>
                            <div class="form-input" style="background:#f0f0f0; color:#333; cursor:default;" aria-readonly="true">Quezon City</div>
                            <input type="hidden" name="city" id="city" value="Quezon City">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="street_or_sitio">Street / Sitio / Purok (optional)</label>
                        <input type="text" id="street_or_sitio" name="street_or_sitio" class="form-input" placeholder="e.g. Sitio San Roque" value="<?=htmlspecialchars($_POST['street_or_sitio'] ?? '')?>">
                    </div>
                    <input type="hidden" name="address" id="address" value="<?=htmlspecialchars($_POST['address'] ?? '')?>">
                </div>

                <div class="form-section">
                    <div class="section-title">Health Information</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="height_value">Height (optional, max 10 ft)</label>
                            <div class="height-weight-row" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                <input type="text" id="height_value" name="height_value" class="form-input" placeholder="e.g. 175 or 5'9" style="flex: 1; min-width: 100px;" value="<?=htmlspecialchars($_POST['height_value'] ?? '')?>" autocomplete="off">
                                <select id="height_unit" name="height_unit" class="form-input" style="width: 90px; flex-shrink: 0;" aria-label="Height unit">
                                    <option value="cm" <?=($_POST['height_unit'] ?? 'cm') === 'cm' ? 'selected' : ''?>>cm</option>
                                    <option value="in" <?=($_POST['height_unit'] ?? '') === 'in' ? 'selected' : ''?>>in</option>
                                    <option value="ft" <?=($_POST['height_unit'] ?? '') === 'ft' ? 'selected' : ''?>>ft</option>
                                </select>
                                <span id="height_equivalent" class="equivalent-display" style="font-size: 0.9rem; color: #666;" aria-live="polite"></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="weight_value">Weight (optional, max 999)</label>
                            <div class="height-weight-row" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                <input type="text" id="weight_value" name="weight_value" class="form-input" placeholder="Numbers only" maxlength="3" style="flex: 1; min-width: 100px;" value="<?=htmlspecialchars($_POST['weight_value'] ?? '')?>" inputmode="numeric" autocomplete="off">
                                <select id="weight_unit" name="weight_unit" class="form-input" style="width: 90px; flex-shrink: 0;" aria-label="Weight unit">
                                    <option value="kg" <?=($_POST['weight_unit'] ?? 'kg') === 'kg' ? 'selected' : ''?>>kg</option>
                                    <option value="lbs" <?=($_POST['weight_unit'] ?? '') === 'lbs' ? 'selected' : ''?>>lbs</option>
                                </select>
                                <span id="weight_equivalent" class="equivalent-display" style="font-size: 0.9rem; color: #666;" aria-live="polite"></span>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="blood_type">Blood Type (optional)</label>
                        <select id="blood_type" name="blood_type" class="form-input blood-type-select">
                            <option value="" <?=empty($_POST['blood_type']) ? 'selected' : ''?>>Select</option>
                            <?php $bloodTypes = ['A+','A-','B+','B-','AB+','AB-','O+','O-']; ?>
                            <?php foreach($bloodTypes as $bt): ?>
                                <option value="<?=$bt?>" <?=(($_POST['blood_type'] ?? '') === $bt) ? 'selected' : ''?>><?=$bt?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Existing Medical Conditions</label>
                        <div class="checkbox-list" style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.5rem 1rem;">
                            <?php
                                $conditions = ['Hypertension','Diabetes','Asthma','Heart Condition','Allergies'];
                                $selected = $_POST['medical_conditions'] ?? [];
                            ?>
                            <?php foreach($conditions as $condition): ?>
                                <label style="display:flex;align-items:center;gap:6px;">
                                    <input type="checkbox" name="medical_conditions[]" value="<?=$condition?>" <?=in_array($condition, $selected) ? 'checked' : ''?>> <?=$condition?>
                                </label>
                            <?php endforeach; ?>
                            <label style="display:flex;align-items:center;gap:6px;">
                                <input type="checkbox" id="condition_other" name="medical_conditions[]" value="Others" <?=in_array('Others', $selected) ? 'checked' : ''?>> Others (Please specify)
                            </label>
                        </div>
                        <input type="text" id="condition_other_input" name="medical_condition_other" class="form-input" placeholder="Please specify other condition" style="margin-top:0.5rem;display:<?=in_array('Others', $selected) ? 'block' : 'none';?>;" value="<?=htmlspecialchars($_POST['medical_condition_other'] ?? '')?>">
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-title">Emergency Contact</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="emergency_first_name">First Name*</label>
                            <input type="text" id="emergency_first_name" name="emergency_first_name" class="form-input" required value="<?=htmlspecialchars($_POST['emergency_first_name'] ?? '')?>">
                        </div>
                        <div class="form-group">
                            <label for="emergency_middle_name">Middle Name</label>
                            <input type="text" id="emergency_middle_name" name="emergency_middle_name" class="form-input" value="<?=htmlspecialchars($_POST['emergency_middle_name'] ?? '')?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="emergency_last_name">Last Name*</label>
                            <input type="text" id="emergency_last_name" name="emergency_last_name" class="form-input" required value="<?=htmlspecialchars($_POST['emergency_last_name'] ?? '')?>">
                        </div>
                        <div class="form-group" style="max-width: 180px;">
                            <label for="emergency_suffix">Suffix</label>
                            <select id="emergency_suffix" name="emergency_suffix" class="form-input">
                                <option value="" <?=empty($_POST['emergency_suffix']) ? 'selected' : ''?>>None</option>
                                <option value="Jr." <?=($_POST['emergency_suffix'] ?? '') === 'Jr.' ? 'selected' : ''?>>Jr.</option>
                                <option value="Sr." <?=($_POST['emergency_suffix'] ?? '') === 'Sr.' ? 'selected' : ''?>>Sr.</option>
                                <option value="II" <?=($_POST['emergency_suffix'] ?? '') === 'II' ? 'selected' : ''?>>II</option>
                                <option value="III" <?=($_POST['emergency_suffix'] ?? '') === 'III' ? 'selected' : ''?>>III</option>
                                <option value="IV" <?=($_POST['emergency_suffix'] ?? '') === 'IV' ? 'selected' : ''?>>IV</option>
                                <option value="V" <?=($_POST['emergency_suffix'] ?? '') === 'V' ? 'selected' : ''?>>V</option>
                                <option value="VI" <?=($_POST['emergency_suffix'] ?? '') === 'VI' ? 'selected' : ''?>>VI</option>
                                <option value="VII" <?=($_POST['emergency_suffix'] ?? '') === 'VII' ? 'selected' : ''?>>VII</option>
                                <option value="VIII" <?=($_POST['emergency_suffix'] ?? '') === 'VIII' ? 'selected' : ''?>>VIII</option>
                                <option value="IX" <?=($_POST['emergency_suffix'] ?? '') === 'IX' ? 'selected' : ''?>>IX</option>
                                <option value="X" <?=($_POST['emergency_suffix'] ?? '') === 'X' ? 'selected' : ''?>>X</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="emergency_relationship">Relationship</label>
                            <?php
                                $relationships = [
                                    'Spouse',
                                    'Parent',
                                    'Child',
                                    'Sibling',
                                    'Grandparent',
                                    'Grandchild',
                                    'Relative',
                                    'Friend',
                                    'Guardian',
                                    'Partner',
                                    'Other'
                                ];
                                $selectedRelationship = $_POST['emergency_relationship'] ?? '';
                            ?>
                            <select id="emergency_relationship" name="emergency_relationship" class="form-input">
                                <option value="" <?=empty($selectedRelationship) ? 'selected' : ''?>>Select Relationship</option>
                                <?php foreach($relationships as $rel): ?>
                                    <option value="<?=$rel?>" <?=$selectedRelationship === $rel ? 'selected' : ''?>><?=$rel?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" style="max-width: 300px;">
                        <label for="emergency_contact">Contact Number</label>
                        <input type="tel" id="emergency_contact" name="emergency_contact" class="form-input" maxlength="11" pattern="\d{11}" inputmode="numeric" value="<?=htmlspecialchars($_POST['emergency_contact'] ?? '')?>">
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-title">Priority Category</div>
                    <div class="form-group">
                        <label for="priority_category">Category</label>
                        <?php
                            $categories = [
                                'Regular Patient',
                                'Senior Citizen (60+)',
                                'PWD',
                                'Pregnant',
                                'Lactating Mother',
                                'Adolescent (13-19)',
                                'Adult (20-59)',
                                'Others'
                            ];
                            $selectedCat = $_POST['priority_category'] ?? '';
                        ?>
                        <select id="priority_category" name="priority_category" class="form-input">
                            <option value="" disabled <?=empty($selectedCat) ? 'selected' : ''?>>Select Category</option>
                            <?php foreach($categories as $cat): ?>
                                <option value="<?=$cat?>" <?=$selectedCat === $cat ? 'selected' : ''?>><?=$cat?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" id="priority_other_input" name="priority_other" class="form-input" placeholder="Please specify other category" style="margin-top:0.5rem;display:<?=$selectedCat === 'Others' ? 'block' : 'none';?>;" value="<?=htmlspecialchars($_POST['priority_other'] ?? '')?>">
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-title" style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Account Credentials</span>
                        <button type="button" class="password-toggle" id="passwordToggle" aria-label="Toggle password visibility" style="background: none; border: none; color: #666; cursor: pointer; padding: 0.5rem; font-size: 1.1rem;">
                            <i class="fas fa-eye" id="passwordToggleIcon"></i>
                        </button>
                    </div>
                    <div class="form-row">
                        <div class="form-group password-field-group">
                            <label for="password">Set Password*</label>
                            <div class="password-input-wrapper">
                                <input type="password" id="password" name="password" class="form-input password-input" required>
                            </div>
                            <div class="password-strength-indicator" id="passwordStrength">
                                <div class="strength-bar">
                                    <div class="strength-fill" id="strengthFill"></div>
                                </div>
                                <span class="strength-text" id="strengthText">Password Strength</span>
                            </div>
                            <div class="password-requirements" id="passwordRequirements">
                                <div class="requirement-item" id="req-length">
                                    <span class="requirement-icon"></span>
                                    <span class="requirement-text">At least 8 characters</span>
                                </div>
                                <div class="requirement-item" id="req-number">
                                    <span class="requirement-icon"></span>
                                    <span class="requirement-text">At least 1 number</span>
                                </div>
                                <div class="requirement-item" id="req-lowercase">
                                    <span class="requirement-icon"></span>
                                    <span class="requirement-text">At least 1 lowercase letter</span>
                                </div>
                                <div class="requirement-item" id="req-uppercase">
                                    <span class="requirement-icon"></span>
                                    <span class="requirement-text">At least 1 uppercase letter</span>
                                </div>
                                <div class="requirement-item" id="req-special">
                                    <span class="requirement-icon"></span>
                                    <span class="requirement-text">At least 1 special character</span>
                                </div>
                            </div>
                        </div>
                        <div class="form-group confirm-password-group">
                            <label for="confirm_password">Confirm Password*</label>
                            <div class="password-input-wrapper">
                                <input type="password" id="confirm_password" name="confirm_password" class="form-input password-input" required>
                            </div>
                            <div class="password-match-message" id="passwordMatchMessage"></div>
                        </div>
                    </div>
                </div>
                
                <div id="validationMessage" class="alert alert-error" style="display: none; margin-bottom: 1rem;"></div>
                <button type="submit" id="signupSubmitBtn" class="btn-primary" disabled>Sign Up</button>  
                <div style="text-align: center;">
                    <span>Already have an account? </span>
                    <a href="Login.php" class="auth-link">Log in</a>
                </div>
            </form>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function(){
            function enforceDigits(el){
                if(!el) return;
                el.addEventListener('input', function(){
                    this.value = this.value.replace(/\D/g,'').slice(0,11);
                });
            }
            enforceDigits(document.getElementById('mobile'));
            enforceDigits(document.getElementById('emergency_contact'));

            // Name fields: letters only (no numbers); allow space, hyphen, apostrophe for names
            function enforceLettersOnly(el) {
                if (!el) return;
                el.addEventListener('input', function() {
                    this.value = this.value.replace(/\d/g, '');
                });
            }
            ['first_name', 'middle_name', 'last_name', 'emergency_first_name', 'emergency_middle_name', 'emergency_last_name'].forEach(function(id) {
                enforceLettersOnly(document.getElementById(id));
            });

            // Height/weight: weight = digits only max 3; height = cm/in max 10 ft, ft = 5'9 format
            const IN_TO_CM = 2.54;
            const LBS_TO_KG = 0.45359237;
            const heightValueEl = document.getElementById('height_value');
            const heightUnitEl = document.getElementById('height_unit');
            const heightEquivalentEl = document.getElementById('height_equivalent');
            const weightValueEl = document.getElementById('weight_value');
            const weightUnitEl = document.getElementById('weight_unit');
            const weightEquivalentEl = document.getElementById('weight_equivalent');

            // Weight: numbers only, max 3 digits
            if (weightValueEl) {
                weightValueEl.addEventListener('input', function() {
                    this.value = this.value.replace(/\D/g, '').slice(0, 3);
                    updateWeightEquivalent();
                });
            }

            // Height unit change: switch placeholder and clear invalid when switching to/from ft
            function heightUnitChanged() {
                const unit = heightUnitEl?.value || 'cm';
                if (heightValueEl) {
                    heightValueEl.placeholder = unit === 'ft' ? "e.g. 5'9" : 'e.g. 175';
                    if (unit === 'ft' && /^\d+(\.\d+)?$/.test(heightValueEl.value)) {
                        const num = parseFloat(heightValueEl.value);
                        if (num <= 10 && num === Math.floor(num)) heightValueEl.value = num + "'0";
                        else if (num > 0) heightValueEl.value = '';
                    } else if (unit !== 'ft' && heightValueEl.value.indexOf("'") !== -1) heightValueEl.value = '';
                }
                updateHeightEquivalent();
            }
            if (heightUnitEl) heightUnitEl.addEventListener('change', heightUnitChanged);

            // Height input: when ft, format as feet'inches (5'9), max 10'11; when cm/in, numbers only with max
            if (heightValueEl) {
                heightValueEl.addEventListener('input', function() {
                    const unit = heightUnitEl?.value || 'cm';
                    if (unit === 'ft') {
                        let v = this.value.replace(/[^\d']/g, '');
                        if ((v.match(/'/g) || []).length > 1) v = v.substring(0, v.indexOf("'") + 1) + v.substring(v.indexOf("'") + 1).replace(/'/g, '');
                        const hasApos = v.indexOf("'") !== -1;
                        let feetPart = '', inchesPart = '';
                        if (hasApos) {
                            const parts = v.split("'");
                            feetPart = (parts[0] || '').replace(/\D/g, '').slice(0, 2);
                            inchesPart = (parts[1] || '').replace(/\D/g, '').slice(0, 2);
                        } else {
                            const digits = v.replace(/\D/g, '');
                            if (digits.length <= 1) feetPart = digits;
                            else if (digits.length === 2) { feetPart = digits[0]; inchesPart = digits[1]; }
                            else if (digits.substring(0, 2) === '10') { feetPart = '10'; inchesPart = digits.substring(2).slice(0, 2); }
                            else { feetPart = digits[0]; inchesPart = digits.substring(1).slice(0, 2); }
                        }
                        const f = feetPart === '' ? '' : Math.min(10, parseInt(feetPart, 10));
                        const i = inchesPart === '' ? '' : Math.min(11, parseInt(inchesPart, 10));
                        if (f !== '' && i !== '') this.value = f + "'" + i;
                        else if (f !== '') this.value = hasApos ? (f + "'" + (inchesPart || '')) : (inchesPart !== '' ? f + "'" + i : String(f));
                        else this.value = v;
                    } else {
                        const numOnly = this.value.replace(/[^\d.]/g, '');
                        const oneDecimal = numOnly.replace(/^(\d*)\.?\d*/, '$1') + (numOnly.indexOf('.') !== -1 ? '.' + numOnly.split('.')[1].slice(0, 1) : '');
                        this.value = oneDecimal;
                        const n = parseFloat(this.value);
                        if (!isNaN(n) && unit === 'cm' && n > 304.8) this.value = '304.8';
                        if (!isNaN(n) && unit === 'in' && n > 120) this.value = '120';
                    }
                    updateHeightEquivalent();
                });
            }

            function parseHeightToCm(val, unit) {
                if (!val || !unit) return null;
                if (unit === 'ft') {
                    const match = val.match(/^(\d{1,2})'(\d{0,2})$/);
                    if (match) {
                        const feet = Math.min(10, parseInt(match[1], 10));
                        const inches = Math.min(11, parseInt(match[2], 10) || 0);
                        return (feet * 12 + inches) * IN_TO_CM;
                    }
                    return null;
                }
                const n = parseFloat(val);
                if (isNaN(n)) return null;
                if (unit === 'in') return n <= 120 ? n * IN_TO_CM : null;
                return n <= 304.8 ? n : null;
            }

            function updateHeightEquivalent() {
                const unit = heightUnitEl?.value || 'cm';
                const val = heightValueEl?.value?.trim() || '';
                if (!heightEquivalentEl) return;
                if (unit === 'ft') {
                    const cm = parseHeightToCm(val, 'ft');
                    if (cm == null || cm <= 0) { heightEquivalentEl.textContent = ''; return; }
                    heightEquivalentEl.textContent = '\u2248 ' + cm.toFixed(1) + ' cm';
                    return;
                }
                const n = parseFloat(val);
                if (isNaN(n) || n <= 0) { heightEquivalentEl.textContent = ''; return; }
                if (unit === 'cm') {
                    heightEquivalentEl.textContent = '\u2248 ' + (n / IN_TO_CM).toFixed(2) + ' in';
                } else {
                    heightEquivalentEl.textContent = '\u2248 ' + (n * IN_TO_CM).toFixed(2) + ' cm';
                }
            }
            function updateWeightEquivalent() {
                const val = parseFloat(weightValueEl?.value);
                if (!weightEquivalentEl || isNaN(val) || val <= 0) {
                    if (weightEquivalentEl) weightEquivalentEl.textContent = '';
                    return;
                }
                const unit = weightUnitEl?.value || 'kg';
                if (unit === 'kg') {
                    weightEquivalentEl.textContent = '\u2248 ' + (val / LBS_TO_KG).toFixed(2) + ' lbs';
                } else {
                    weightEquivalentEl.textContent = '\u2248 ' + (val * LBS_TO_KG).toFixed(2) + ' kg';
                }
            }
            if (heightValueEl) heightValueEl.addEventListener('input', updateHeightEquivalent);
            if (heightUnitEl) heightUnitEl.addEventListener('change', updateHeightEquivalent);
            if (weightUnitEl) weightUnitEl.addEventListener('change', updateWeightEquivalent);
            updateHeightEquivalent();
            updateWeightEquivalent();

            const conditionOther = document.getElementById('condition_other');
            const conditionOtherInput = document.getElementById('condition_other_input');
            if(conditionOther){
                conditionOther.addEventListener('change', function(){
                    const show = this.checked;
                    conditionOtherInput.style.display = show ? 'block' : 'none';
                    conditionOtherInput.required = show;
                    if(!show){ conditionOtherInput.value=''; }
                });
            }

            const prioritySelect = document.getElementById('priority_category');
            const priorityOtherInput = document.getElementById('priority_other_input');
            if(prioritySelect){
                prioritySelect.addEventListener('change', function(){
                    const show = this.value === 'Others';
                    priorityOtherInput.style.display = show ? 'block' : 'none';
                    priorityOtherInput.required = show;
                    if(!show){ priorityOtherInput.value=''; }
                });
            }

            // Password Validation Functionality
            const passwordInput = document.getElementById('password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const passwordToggle = document.getElementById('passwordToggle');
            const passwordToggleIcon = document.getElementById('passwordToggleIcon');
            const strengthFill = document.getElementById('strengthFill');
            const strengthText = document.getElementById('strengthText');
            const passwordMatchMessage = document.getElementById('passwordMatchMessage');
            const form = document.querySelector('form');

            // Password toggle functionality - controls both password fields simultaneously
            if (passwordToggle && passwordInput && confirmPasswordInput) {
                passwordToggle.addEventListener('click', function() {
                    const isPassword = passwordInput.getAttribute('type') === 'password';
                    const newType = isPassword ? 'text' : 'password';
                    
                    // Toggle both password fields
                    passwordInput.setAttribute('type', newType);
                    confirmPasswordInput.setAttribute('type', newType);
                    
                    // Update icon
                    passwordToggleIcon.classList.toggle('fa-eye');
                    passwordToggleIcon.classList.toggle('fa-eye-slash');
                });
            }

            // Password validation requirements
            const requirements = {
                length: { element: document.getElementById('req-length'), test: (pwd) => pwd.length >= 8 },
                number: { element: document.getElementById('req-number'), test: (pwd) => /\d/.test(pwd) },
                lowercase: { element: document.getElementById('req-lowercase'), test: (pwd) => /[a-z]/.test(pwd) },
                uppercase: { element: document.getElementById('req-uppercase'), test: (pwd) => /[A-Z]/.test(pwd) },
                special: { element: document.getElementById('req-special'), test: (pwd) => /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(pwd) }
            };

            function validatePassword(password) {
                let validCount = 0;
                
                // Check each requirement
                Object.keys(requirements).forEach(key => {
                    const req = requirements[key];
                    const isValid = req.test(password);
                    
                    if (isValid) {
                        req.element.classList.add('valid');
                        validCount++;
                    } else {
                        req.element.classList.remove('valid');
                    }
                });

                // Update strength indicator
                let strength = 'none';
                let strengthLabel = 'Password Strength';
                
                if (validCount === 0) {
                    strength = 'none';
                    strengthLabel = 'Password Strength';
                } else if (validCount <= 2) {
                    strength = 'weak';
                    strengthLabel = 'Weak';
                } else if (validCount <= 4) {
                    strength = 'medium';
                    strengthLabel = 'Medium';
                } else {
                    strength = 'strong';
                    strengthLabel = 'Strong';
                }

                // Update strength bar
                strengthFill.className = 'strength-fill ' + strength;
                strengthText.className = 'strength-text ' + strength;
                strengthText.textContent = strengthLabel;

                return validCount === 5;
            }

            function validatePasswordMatch() {
                const password = passwordInput.value;
                const confirmPassword = confirmPasswordInput.value;
                
                if (confirmPassword === '') {
                    passwordMatchMessage.textContent = '';
                    passwordMatchMessage.className = 'password-match-message';
                    return false;
                }
                
                if (password === confirmPassword) {
                    passwordMatchMessage.textContent = 'Passwords match';
                    passwordMatchMessage.className = 'password-match-message match';
                    return true;
                } else {
                    passwordMatchMessage.textContent = 'Passwords do not match';
                    passwordMatchMessage.className = 'password-match-message mismatch';
                    return false;
                }
            }

            // Real-time password validation
            if (passwordInput) {
                passwordInput.addEventListener('input', function() {
                    validatePassword(this.value);
                    if (confirmPasswordInput.value) {
                        validatePasswordMatch();
                    }
                });
            }

            // Real-time confirm password validation
            if (confirmPasswordInput) {
                confirmPasswordInput.addEventListener('input', function() {
                    validatePasswordMatch();
                });
            }

            // --- Required fields validation (disable button until all filled, show missing list on submit) ---
            const signupSubmitBtn = document.getElementById('signupSubmitBtn');
            const validationMessageEl = document.getElementById('validationMessage');

            const requiredFields = [
                { id: 'first_name', label: 'First Name' },
                { id: 'last_name', label: 'Last Name' },
                { id: 'dob', label: 'Date of Birth' },
                { id: 'email', label: 'Email' },
                { id: 'sex', label: 'Sex' },
                { id: 'mobile', label: 'Mobile Number' },
                { id: 'barangay', label: 'Barangay' },
                { id: 'city', label: 'City' },
                { id: 'emergency_first_name', label: 'Emergency Contact First Name' },
                { id: 'emergency_last_name', label: 'Emergency Contact Last Name' },
                { id: 'password', label: 'Set Password' },
                { id: 'confirm_password', label: 'Confirm Password' }
            ];

            function getMissingRequiredFields() {
                const missing = [];
                // Main required fields
                requiredFields.forEach(function(f) {
                    const el = document.getElementById(f.id);
                    if (!el) return;
                    let val = (el.value || '').toString().trim();
                    if (f.id === 'sex' || f.id === 'barangay' || f.id === 'city') {
                        if (!val) missing.push(f.label);
                        else if (f.id === 'barangay' && val !== 'Payatas') missing.push(f.label + ' (must be Payatas)');
                        else if (f.id === 'city' && val !== 'Quezon City') missing.push(f.label + ' (must be Quezon City)');
                    } else if (f.id === 'password') {
                        if (!val || !validatePassword(val)) missing.push(f.label + ' (must meet all requirements)');
                    } else if (f.id === 'confirm_password') {
                        if (!val || !validatePasswordMatch()) missing.push(f.label + ' (must match password)');
                    } else {
                        if (!val) missing.push(f.label);
                    }
                });
                // Conditional: Others medical condition
                const conditionOther = document.getElementById('condition_other');
                const conditionOtherInput = document.getElementById('condition_other_input');
                if (conditionOther && conditionOther.checked && conditionOtherInput) {
                    if (!(conditionOtherInput.value || '').trim()) missing.push('Other medical condition (please specify)');
                }
                // Conditional: Priority category Others
                const prioritySelect = document.getElementById('priority_category');
                const priorityOtherInput = document.getElementById('priority_other_input');
                if (prioritySelect && prioritySelect.value === 'Others' && priorityOtherInput) {
                    if (!(priorityOtherInput.value || '').trim()) missing.push('Other priority category (please specify)');
                }
                return missing;
            }

            function updateSignupButtonState() {
                const missing = getMissingRequiredFields();
                const allValid = missing.length === 0;
                if (signupSubmitBtn) {
                    signupSubmitBtn.disabled = !allValid;
                }
                if (validationMessageEl) {
                    validationMessageEl.style.display = 'none';
                    validationMessageEl.textContent = '';
                }
            }

            // Listen to all required fields and conditional fields
            requiredFields.forEach(function(f) {
                const el = document.getElementById(f.id);
                if (el) {
                    el.addEventListener('input', updateSignupButtonState);
                    el.addEventListener('change', updateSignupButtonState);
                }
            });
            if (conditionOther) {
                conditionOther.addEventListener('change', function() { setTimeout(updateSignupButtonState, 0); });
            }
            if (conditionOtherInput) {
                conditionOtherInput.addEventListener('input', updateSignupButtonState);
            }
            if (prioritySelect) {
                prioritySelect.addEventListener('change', function() { setTimeout(updateSignupButtonState, 0); });
            }
            if (priorityOtherInput) {
                priorityOtherInput.addEventListener('input', updateSignupButtonState);
            }
            // Initial state
            updateSignupButtonState();

            // Form submission: show missing fields message if validation fails
            if (form) {
                form.addEventListener('submit', function(e) {
                    const missing = getMissingRequiredFields();
                    const isPasswordValid = validatePassword(passwordInput.value);
                    const isMatch = validatePasswordMatch();
                    
                    if (missing.length > 0) {
                        e.preventDefault();
                        if (validationMessageEl) {
                            validationMessageEl.textContent = 'Please fill all required fields: ' + missing.join(', ');
                            validationMessageEl.style.display = 'block';
                            validationMessageEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        } else {
                            alert('Please fill all required fields:\n\n' + missing.join('\n'));
                        }
                        return false;
                    }
                    
                    if (!isPasswordValid || !isMatch) {
                        e.preventDefault();
                        if (validationMessageEl) {
                            validationMessageEl.textContent = 'Please ensure all password requirements are met and passwords match.';
                            validationMessageEl.style.display = 'block';
                            validationMessageEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        } else {
                            alert('Please ensure all password requirements are met and passwords match.');
                        }
                        return false;
                    }
                    
                    if (validationMessageEl) {
                        validationMessageEl.style.display = 'none';
                        validationMessageEl.textContent = '';
                    }
                });
            }

        });
    </script>
</body>
</html>