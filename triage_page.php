<?php
session_start();
require_once 'db.php';

// Check if user is logged in and is staff (FDO, admin, or doctor)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['fdo', 'admin', 'doctor'])) {
    header('Location: Login.php');
    exit();
}

$appointment_id = isset($_GET['appointment_id']) ? (int) $_GET['appointment_id'] : 0;
$error = '';
$success = '';

if ($appointment_id <= 0) {
    header('Location: fdo_page.php?error=invalid_appointment');
    exit();
}

// Get appointment details
$stmt = $pdo->prepare("
    SELECT 
        a.id,
        a.user_id,
        a.patient_id,
        a.doctor_id,
        a.start_datetime,
        a.status,
        a.notes,
        CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as patient_name,
        p.first_name as dep_first_name,
        p.last_name as dep_last_name,
        p.middle_name as dep_middle_name
    FROM appointments a
    LEFT JOIN users u ON u.id = a.user_id AND u.role = 'patient'
    LEFT JOIN patients p ON p.id = a.patient_id
    WHERE a.id = ?
    LIMIT 1
");
$stmt->execute([$appointment_id]);
$appointment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$appointment) {
    header('Location: fdo_page.php?error=appointment_not_found');
    exit();
}

// Get patient name
$patient_name = '';
if (!empty($appointment['patient_name'])) {
    $patient_name = trim($appointment['patient_name']);
} elseif (!empty($appointment['dep_first_name'])) {
    $patient_name = trim(($appointment['dep_first_name'] ?? '') . ' ' . ($appointment['dep_middle_name'] ?? '') . ' ' . ($appointment['dep_last_name'] ?? ''));
}

// Check if triage_records table exists
$table_exists = false;
try {
    $table_check = $pdo->query("SHOW TABLES LIKE 'triage_records'");
    $table_exists = $table_check->rowCount() > 0;
} catch (PDOException $e) {
    // Table doesn't exist
    $table_exists = false;
}

// Check if triage already exists (only if table exists)
$existing_triage = null;
if ($table_exists) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM triage_records WHERE appointment_id = ? LIMIT 1");
        $stmt->execute([$appointment_id]);
        $existing_triage = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = 'Triage table not set up. Please run setup_triage_table.php first.';
    }
} else {
    $error = 'Triage table not found. Please run setup_triage_table.php to set up the triage functionality.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $blood_pressure = trim($_POST['blood_pressure'] ?? '');
    $temperature = !empty($_POST['temperature']) ? floatval($_POST['temperature']) : null;
    $weight = !empty($_POST['weight']) ? floatval($_POST['weight']) : null;
    $pulse_rate = !empty($_POST['pulse_rate']) ? intval($_POST['pulse_rate']) : null;
    $notes = trim($_POST['notes'] ?? '');
    $recorded_by = $_SESSION['user']['id'];
    
    // Check if table exists before saving
    if (!$table_exists) {
        $error = 'Triage table not found. Please run setup_triage_table.php to set up the triage functionality.';
    } else {
        // Determine patient_id and user_id
        // For registered patients: user_id is set, patient_id may be NULL or same as user_id
        // For dependents: patient_id is set (from patients table), user_id is the parent's user_id
        $patient_id = $appointment['patient_id'];
        $user_id = $appointment['user_id'];
        
        // If patient_id is NULL but user_id is set, it's a registered patient
        // In that case, we can set patient_id to user_id for reference, or leave it NULL
        // The triage table stores both for flexibility
        
        try {
            if ($existing_triage) {
                // Update existing triage
                $stmt = $pdo->prepare("
                    UPDATE triage_records 
                    SET blood_pressure = ?, temperature = ?, weight = ?, pulse_rate = ?, notes = ?, recorded_by = ?
                    WHERE appointment_id = ?
                ");
                $stmt->execute([$blood_pressure ?: null, $temperature, $weight, $pulse_rate, $notes ?: null, $recorded_by, $appointment_id]);
                $success = 'Triage information updated successfully!';
            } else {
                // Create new triage
                $stmt = $pdo->prepare("
                    INSERT INTO triage_records (appointment_id, patient_id, user_id, blood_pressure, temperature, weight, pulse_rate, notes, recorded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$appointment_id, $patient_id, $user_id, $blood_pressure ?: null, $temperature, $weight, $pulse_rate, $notes ?: null, $recorded_by]);
                $success = 'Triage information saved successfully!';
            }
            
            // Reload triage data
            $stmt = $pdo->prepare("SELECT * FROM triage_records WHERE appointment_id = ? LIMIT 1");
            $stmt->execute([$appointment_id]);
            $existing_triage = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $error = 'Error saving triage information: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Triage/Screening - HealthServe</title>
    <link rel="stylesheet" href="assets/Style1.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f6fa;
            margin: 0;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .triage-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 10px 35px rgba(0,0,0,0.08);
        }
        .page-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
        }
        .page-header h1 {
            color: #2E7D32;
            margin: 0 0 10px 0;
            font-size: 28px;
        }
        .page-header p {
            color: #666;
            margin: 0;
        }
        .patient-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
        }
        .patient-info h3 {
            margin: 0 0 15px 0;
            color: #2E7D32;
            font-size: 18px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .info-item {
            display: flex;
            flex-direction: column;
        }
        .info-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        .info-value {
            font-size: 16px;
            color: #333;
            font-weight: 600;
        }
        .form-group {
            margin-bottom: 25px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #E0E0E0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
            background: #FAFAFA;
            box-sizing: border-box;
        }
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #4CAF50;
            background: white;
        }
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .alert-error {
            background: #ffebee;
            color: #d32f2f;
            border: 1px solid #ffcdd2;
        }
        .alert-success {
            background: #E8F5E9;
            color: #2E7D32;
            border: 1px solid #66BB6A;
        }
        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            justify-content: flex-end;
        }
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary {
            background: #4CAF50;
            color: white;
        }
        .btn-primary:hover {
            background: #45a049;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
        }
        .btn-secondary {
            background: #f5f5f5;
            color: #666;
        }
        .btn-secondary:hover {
            background: #e0e0e0;
        }
        .required {
            color: #d32f2f;
        }
    </style>
</head>
<body>
    <div class="triage-container">
        <div class="page-header">
            <h1><i class="fas fa-stethoscope"></i> Patient Triage/Screening</h1>
            <p>Record initial vital signs before consultation</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                <?php if (strpos($error, 'table not found') !== false || strpos($error, 'not set up') !== false): ?>
                    <br><br>
                    <a href="setup_triage_table.php" style="color: #d32f2f; font-weight: 600; text-decoration: underline;">
                        <i class="fas fa-tools"></i> Click here to set up the triage table
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <div class="patient-info">
            <h3><i class="fas fa-user"></i> Patient Information</h3>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Patient Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($patient_name ?: 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Appointment Date</div>
                    <div class="info-value"><?php echo date('F j, Y', strtotime($appointment['start_datetime'])); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Appointment Time</div>
                    <div class="info-value"><?php echo date('h:i A', strtotime($appointment['start_datetime'])); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Status</div>
                    <div class="info-value"><?php echo ucfirst($appointment['status']); ?></div>
                </div>
            </div>
        </div>

        <form method="POST" action="">
            <h3 style="color: #2E7D32; margin-bottom: 20px;"><i class="fas fa-heartbeat"></i> Vital Signs</h3>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="blood_pressure">Blood Pressure <span class="required">*</span></label>
                    <input type="text" id="blood_pressure" name="blood_pressure" 
                           placeholder="e.g., 120/80" 
                           value="<?php echo htmlspecialchars($existing_triage['blood_pressure'] ?? ''); ?>" 
                           required>
                </div>
                <div class="form-group">
                    <label for="temperature">Temperature (°C) <span class="required">*</span></label>
                    <input type="number" id="temperature" name="temperature" 
                           step="0.1" min="30" max="45" 
                           placeholder="e.g., 36.5" 
                           value="<?php echo htmlspecialchars($existing_triage['temperature'] ?? ''); ?>" 
                           required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="weight">Weight (kg) <span class="required">*</span></label>
                    <input type="number" id="weight" name="weight" 
                           step="0.1" min="0" max="500" 
                           placeholder="e.g., 70.5" 
                           value="<?php echo htmlspecialchars($existing_triage['weight'] ?? ''); ?>" 
                           required>
                </div>
                <div class="form-group">
                    <label for="pulse_rate">Pulse Rate (bpm)</label>
                    <input type="number" id="pulse_rate" name="pulse_rate" 
                           min="0" max="200" 
                           placeholder="e.g., 72" 
                           value="<?php echo htmlspecialchars($existing_triage['pulse_rate'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="notes">Additional Notes</label>
                <textarea id="notes" name="notes" 
                          placeholder="Any additional observations or notes..."><?php echo htmlspecialchars($existing_triage['notes'] ?? ''); ?></textarea>
            </div>

            <div class="btn-group">
                <a href="fdo_page.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Triage Information
                </button>
            </div>
        </form>
    </div>
</body>
</html>

