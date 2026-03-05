<?php
session_start();
require_once 'db.php';
require_once 'appointment_code_helper.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$doctor_user_id = $_SESSION['user']['id'];
$action = $_POST['action'] ?? '';

// Get doctor_id from doctors table
$stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
$stmt->execute([$doctor_user_id]);
$doctor_record = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doctor_record) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Doctor record not found']);
    exit();
}
$doctor_id = $doctor_record['id'];

/**
 * Deduct inventory quantity for a prescribed medicine
 * @param PDO $pdo Database connection
 * @param int|null $medicine_id Medicine ID from inventory (if available)
 * @param string $medicine_name Medicine name
 * @param int $total_quantity Total quantity to deduct
 * @return array Result with success status and message
 */
function deductInventoryForPrescription($pdo, $medicine_id, $medicine_name, $total_quantity) {
    if ($total_quantity <= 0) {
        return ['success' => true, 'message' => 'No quantity to deduct'];
    }
    
    // Try to find inventory item by ID first, then by name
    $inventory_item = null;
    
    if ($medicine_id) {
        $stmt = $pdo->prepare("
            SELECT id, quantity, item_name, reorder_level
            FROM inventory 
            WHERE id = ?
        ");
        $stmt->execute([(int)$medicine_id]);
        $inventory_item = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // If not found by ID, try by name (case-insensitive)
    if (!$inventory_item && !empty($medicine_name)) {
        $stmt = $pdo->prepare("
            SELECT id, quantity, item_name, reorder_level
            FROM inventory 
            WHERE LOWER(TRIM(item_name)) = LOWER(TRIM(?))
            LIMIT 1
        ");
        $stmt->execute([$medicine_name]);
        $inventory_item = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$inventory_item) {
        // Medicine not found in inventory - log but don't fail
        error_log("Inventory deduction skipped: Medicine '{$medicine_name}' (ID: {$medicine_id}) not found in inventory");
        return ['success' => true, 'message' => 'Medicine not found in inventory - skipping deduction'];
    }
    
    // Calculate new quantity (ensure it doesn't go below 0)
    $current_quantity = (int)$inventory_item['quantity'];
    $new_quantity = max(0, $current_quantity - $total_quantity);
    
    // Update inventory
    $stmt = $pdo->prepare("
        UPDATE inventory 
        SET quantity = ?, 
            last_dispensed_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$new_quantity, $inventory_item['id']]);
    
    // Get pharmacist user IDs to notify
    $pharmacist_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'pharmacist'");
    $pharmacist_stmt->execute();
    $pharmacists = $pharmacist_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Create notification for pharmacists about inventory update
    $message = "Prescription finalized — {$total_quantity} {$inventory_item['item_name']} deducted. Remaining: {$new_quantity}";
    foreach ($pharmacists as $pharmacist_id) {
        $notif_stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, message, type, status) 
            VALUES (?, ?, 'inventory_update', 'unread')
        ");
        $notif_stmt->execute([$pharmacist_id, $message]);
    }
    
    // Check if low stock or out of stock after deduction
    if ($new_quantity == 0) {
        // Out of stock notification
        $message = "Out of Stock — {$inventory_item['item_name']} is now out of stock";
        foreach ($pharmacists as $pharmacist_id) {
            $notif_stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, message, type, status) 
                VALUES (?, ?, 'inventory_out', 'unread')
            ");
            $notif_stmt->execute([$pharmacist_id, $message]);
        }
    } elseif ($new_quantity <= $inventory_item['reorder_level']) {
        // Low stock notification
        $message = "Medicine Running Low — {$inventory_item['item_name']} ({$new_quantity} remaining, reorder at {$inventory_item['reorder_level']})";
        foreach ($pharmacists as $pharmacist_id) {
            $notif_stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, message, type, status) 
                VALUES (?, ?, 'inventory_low', 'unread')
            ");
            $notif_stmt->execute([$pharmacist_id, $message]);
        }
    }
    
    return ['success' => true, 'message' => 'Inventory deducted successfully'];
}

/**
 * Parse duration string to days (for antibiotic validity cap).
 * Matches frontend parseDurationInDays (e.g. "5 days", "7 days", "1 day").
 * @param string $duration
 * @return int Days, or 0 if unparseable / variable (e.g. "Until symptoms improve")
 */
function parseDurationToDays($duration) {
    if ($duration === null || trim($duration) === '') {
        return 0;
    }
    $dur = strtolower(trim($duration));
    if (strpos($dur, 'until symptoms') !== false || strpos($dur, 'prn') !== false) {
        return 0;
    }
    if (preg_match('/(\d+)\s*day/i', $dur, $m)) {
        return (int) $m[1];
    }
    if (preg_match('/(\d+)\s*week/i', $dur, $m)) {
        return (int) $m[1] * 7;
    }
    if (preg_match('/(\d+)\s*month/i', $dur, $m)) {
        return (int) $m[1] * 30;
    }
    if (preg_match('/(\d+)/', $dur, $m)) {
        return (int) $m[1];
    }
    return 0;
}

try {
    switch ($action) {
        case 'update_status':
            $appointment_id = isset($_POST['appointment_id']) ? (int) $_POST['appointment_id'] : 0;
            $status = strtolower(trim($_POST['status'] ?? ''));
            $allowed = ['approved', 'declined', 'completed', 'pending'];

            if ($appointment_id <= 0 || !in_array($status, $allowed, true)) {
                throw new Exception('Invalid appointment or status.');
            }

            $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ? AND doctor_id = ?");
            $stmt->execute([$status, $appointment_id, $doctor_id]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Unable to update appointment. Make sure it belongs to you.');
            }

            echo json_encode(['success' => true]);
            break;

        case 'save_consultation':
            $patient_id = isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
            $appointment_id = isset($_POST['appointment_id']) ? (int) $_POST['appointment_id'] : null;
            $findings = trim($_POST['findings'] ?? '');
            $diagnosis = trim($_POST['diagnosis'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $chief_complaint = trim($_POST['chief_complaint'] ?? '');
            $temperature = trim($_POST['temperature'] ?? '');
            $blood_pressure = trim($_POST['blood_pressure'] ?? '');
            $pulse_rate = trim($_POST['pulse_rate'] ?? '');

            if ($patient_id <= 0 || $findings === '' || $diagnosis === '') {
                throw new Exception('Patient, findings, and diagnosis are required.');
            }
            
            // Check if doctor has access to this patient (has approved appointment)
            $access_check = $pdo->prepare("
                SELECT 1 FROM appointments 
                WHERE doctor_id = ? 
                AND status = 'approved'
                AND (
                    (user_id = ? AND patient_id = ?) 
                    OR (user_id = ? AND patient_id IS NULL)
                    OR patient_id = ?
                )
                LIMIT 1
            ");
            $access_check->execute([$doctor_id, $patient_id, $patient_id, $patient_id, $patient_id]);
            
            if (!$access_check->fetch()) {
                throw new Exception('You do not have access to this patient\'s records.');
            }

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS doctor_consultations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    doctor_id INT NOT NULL,
                    patient_id INT NOT NULL,
                    appointment_id INT NULL,
                    findings TEXT,
                    diagnosis TEXT,
                    notes TEXT,
                    chief_complaint TEXT,
                    temperature VARCHAR(20),
                    blood_pressure VARCHAR(20),
                    pulse_rate VARCHAR(20),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $stmt = $pdo->prepare("
                INSERT INTO doctor_consultations (
                    doctor_id, patient_id, appointment_id, findings, diagnosis, notes,
                    chief_complaint, temperature, blood_pressure, pulse_rate
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $doctor_id,
                $patient_id,
                $appointment_id,
                $findings,
                $diagnosis,
                $notes,
                $chief_complaint,
                $temperature,
                $blood_pressure,
                $pulse_rate
            ]);
            
            // Create notification for patient when consultation is saved
            // Check if this is a dependent
            $stmt = $pdo->prepare("SELECT created_by_user_id FROM patients WHERE id = ?");
            $stmt->execute([$patient_id]);
            $patient_record = $stmt->fetch(PDO::FETCH_ASSOC);
            $is_dependent = $patient_record && !empty($patient_record['created_by_user_id']);
            $patient_user_id_for_notif = $is_dependent ? $patient_record['created_by_user_id'] : $patient_id;
            
            // Check if reference_id column exists
            $has_reference_id = false;
            try {
                $test_stmt = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'reference_id'");
                $has_reference_id = $test_stmt->rowCount() > 0;
            } catch (PDOException $e) {}
            
            $notif_message = "Your medical record has been updated with a new consultation.";
            
            // Check if notification already exists for this specific consultation
            // Only check within last 2 minutes to catch accidental duplicate submissions
            // This allows multiple different consultations to each create a notification
            if ($has_reference_id && $appointment_id) {
                $check_notif = $pdo->prepare("
                    SELECT notification_id 
                    FROM notifications 
                    WHERE user_id = ? 
                      AND type = 'record_update' 
                      AND reference_id = ?
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                    LIMIT 1
                ");
                $check_notif->execute([$patient_user_id_for_notif, $appointment_id]);
            } else {
                // Check by message and very recent time (within last 2 minutes) to catch only immediate duplicates
                $check_notif = $pdo->prepare("
                    SELECT notification_id 
                    FROM notifications 
                    WHERE user_id = ? 
                      AND type = 'record_update' 
                      AND message = ?
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                    LIMIT 1
                ");
                $check_notif->execute([$patient_user_id_for_notif, $notif_message]);
            }
            
            // Only skip if notification was created in the last 2 minutes (prevents duplicate from same consultation submission)
            // This allows each new consultation to create a notification
            if (!$check_notif->fetch()) {
                if ($has_reference_id && $appointment_id) {
                    $notif_stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, message, type, reference_id, status) 
                        VALUES (?, ?, 'record_update', ?, 'unread')
                    ");
                    $notif_stmt->execute([$patient_user_id_for_notif, $notif_message, $appointment_id]);
                } else {
                    $notif_stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, message, type, status) 
                        VALUES (?, ?, 'record_update', 'unread')
                    ");
                    $notif_stmt->execute([$patient_user_id_for_notif, $notif_message]);
                }
            }

            echo json_encode(['success' => true]);
            break;

        case 'save_prescription':
            $patient_id = isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
            $appointment_id = !empty($_POST['appointment_id']) ? (int) $_POST['appointment_id'] : null;
            $diagnosis = trim($_POST['diagnosis'] ?? '');
            $medicines = json_decode($_POST['medicines'] ?? '[]', true);
            $existing_prescription_id = !empty($_POST['prescription_id']) ? (int) $_POST['prescription_id'] : null;

            if ($patient_id <= 0 || empty($diagnosis)) {
                throw new Exception('Patient ID and diagnosis are required.');
            }
            
            if (empty($medicines) || !is_array($medicines)) {
                throw new Exception('At least one medicine is required.');
            }
            
            // Check if doctor has access to this patient
            $access_check = $pdo->prepare("
                SELECT 1 FROM appointments 
                WHERE doctor_id = ? 
                AND status = 'approved'
                AND (
                    (user_id = ? AND patient_id = ?) 
                    OR (user_id = ? AND patient_id IS NULL)
                    OR patient_id = ?
                )
                LIMIT 1
            ");
            $access_check->execute([$doctor_id, $patient_id, $patient_id, $patient_id, $patient_id]);
            
            if (!$access_check->fetch()) {
                throw new Exception('You do not have access to this patient\'s records.');
            }

            $pdo->beginTransaction();

            try {
                // Get validity period
                $validity_period_input = isset($_POST['prescription_validity_period']) ? trim($_POST['prescription_validity_period']) : '14';
                $date_issued = date('Y-m-d');
                $validity_period_days = null;
                $expiration_date = null;
                
                if ($validity_period_input === 'maintenance') {
                    // Maintenance: no expiration (long-term prescription)
                    $validity_period_days = null;
                    $expiration_date = null;
                } elseif ($validity_period_input === 'custom') {
                    $custom_expiration = isset($_POST['prescription_custom_expiration_date']) ? trim($_POST['prescription_custom_expiration_date']) : '';
                    if ($custom_expiration) {
                        $expiration_date = $custom_expiration;
                        $date1 = new DateTime($date_issued);
                        $date2 = new DateTime($expiration_date);
                        $diff = $date1->diff($date2);
                        $validity_period_days = (int)$diff->days;
                    } else {
                        $validity_period_days = 14;
                        $expiration_date = date('Y-m-d', strtotime("+14 days"));
                    }
                } else {
                    $validity_period_days = (int)$validity_period_input;
                    if (!in_array($validity_period_days, [7, 14, 30])) {
                        $validity_period_days = 14;
                    }
                    $expiration_date = date('Y-m-d', strtotime("+{$validity_period_days} days"));
                }
                
                // Check if validity_period_days and expiration_date columns exist
                $has_validity_fields = false;
                try {
                    $test_stmt = $pdo->query("SHOW COLUMNS FROM prescriptions LIKE 'validity_period_days'");
                    $has_validity_fields = $test_stmt->rowCount() > 0;
                } catch (PDOException $e) {}
                
                $prescription_id = $existing_prescription_id;
                
                if ($prescription_id) {
                    // Update existing prescription
                    if ($has_validity_fields) {
                        $stmt = $pdo->prepare("
                            UPDATE prescriptions 
                            SET diagnosis = ?, validity_period_days = ?, expiration_date = ?, updated_at = NOW()
                            WHERE id = ? AND doctor_id = ? AND patient_id = ?
                        ");
                        $stmt->execute([$diagnosis, $validity_period_days, $expiration_date, $prescription_id, $doctor_id, $patient_id]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE prescriptions 
                            SET diagnosis = ?, updated_at = NOW()
                            WHERE id = ? AND doctor_id = ? AND patient_id = ?
                        ");
                        $stmt->execute([$diagnosis, $prescription_id, $doctor_id, $patient_id]);
                    }
                    
                    if ($stmt->rowCount() === 0) {
                        throw new Exception('Prescription not found or does not belong to this doctor.');
                    }
                    
                    // Delete existing prescription items (we'll add all medicines fresh)
                    $delete_stmt = $pdo->prepare("DELETE FROM prescription_items WHERE prescription_id = ?");
                    $delete_stmt->execute([$prescription_id]);
                    
                    // Also delete from medications table
                    $delete_med_stmt = $pdo->prepare("DELETE FROM medications WHERE prescription_id = ?");
                    $delete_med_stmt->execute([$prescription_id]);
                } else {
                    // Create new prescription
                    if ($has_validity_fields) {
                        $stmt = $pdo->prepare("
                            INSERT INTO prescriptions (patient_id, doctor_id, appointment_id, diagnosis, status, date_issued, validity_period_days, expiration_date, created_at)
                            VALUES (?, ?, ?, ?, 'active', CURDATE(), ?, ?, NOW())
                        ");
                        $stmt->execute([$patient_id, $doctor_id, $appointment_id, $diagnosis, $validity_period_days, $expiration_date]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO prescriptions (patient_id, doctor_id, appointment_id, diagnosis, status, date_issued, created_at)
                            VALUES (?, ?, ?, ?, 'active', CURDATE(), NOW())
                        ");
                        $stmt->execute([$patient_id, $doctor_id, $appointment_id, $diagnosis]);
                    }
                    $prescription_id = $pdo->lastInsertId();
                }

                // Check if prescription_items table has quantity, total_quantity, is_external, instructions columns
                $has_quantity = false;
                $has_total_quantity = false;
                $has_is_external = false;
                $has_instructions = false;
                try {
                    $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'quantity'");
                    $has_quantity = $test_stmt->rowCount() > 0;
                    $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'total_quantity'");
                    $has_total_quantity = $test_stmt->rowCount() > 0;
                    $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'is_external'");
                    $has_is_external = $test_stmt->rowCount() > 0;
                    $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'instructions'");
                    $has_instructions = $test_stmt->rowCount() > 0;
                } catch (PDOException $e) {}
                if (!$has_is_external) {
                    try {
                        $pdo->exec("ALTER TABLE prescription_items ADD COLUMN is_external TINYINT(1) NOT NULL DEFAULT 0");
                        $has_is_external = true;
                    } catch (PDOException $e) {}
                }
                if (!$has_instructions) {
                    try {
                        $pdo->exec("ALTER TABLE prescription_items ADD COLUMN instructions TEXT NULL");
                        $has_instructions = true;
                    } catch (PDOException $e) {}
                }
                $has_timing_of_intake = false;
                try {
                    $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'timing_of_intake'");
                    $has_timing_of_intake = $test_stmt->rowCount() > 0;
                } catch (PDOException $e) {}
                if (!$has_timing_of_intake) {
                    try {
                        $pdo->exec("ALTER TABLE prescription_items ADD COLUMN timing_of_intake VARCHAR(100) NULL");
                        $has_timing_of_intake = true;
                    } catch (PDOException $e) {}
                }
                $has_medicine_form = false;
                try {
                    $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'medicine_form'");
                    $has_medicine_form = $test_stmt->rowCount() > 0;
                } catch (PDOException $e) {}
                if (!$has_medicine_form) {
                    try {
                        $pdo->exec("ALTER TABLE prescription_items ADD COLUMN medicine_form VARCHAR(50) NULL DEFAULT NULL AFTER medicine_name");
                        $has_medicine_form = true;
                    } catch (PDOException $e) {}
                }
                $has_item_expiration_date = false;
                $has_item_is_long_term = false;
                try {
                    $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'expiration_date'");
                    $has_item_expiration_date = $test_stmt->rowCount() > 0;
                    $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'is_long_term'");
                    $has_item_is_long_term = $test_stmt->rowCount() > 0;
                } catch (PDOException $e) {}
                if (!$has_item_expiration_date) {
                    try {
                        $pdo->exec("ALTER TABLE prescription_items ADD COLUMN expiration_date DATE NULL DEFAULT NULL");
                        $has_item_expiration_date = true;
                    } catch (PDOException $e) {}
                }
                if (!$has_item_is_long_term) {
                    try {
                        $pdo->exec("ALTER TABLE prescription_items ADD COLUMN is_long_term TINYINT(1) NOT NULL DEFAULT 0");
                        $has_item_is_long_term = true;
                    } catch (PDOException $e) {}
                }

                // Add medications to prescription_items table
                if ($has_quantity && $has_total_quantity) {
                    $stmt = $pdo->prepare("
                        INSERT INTO prescription_items (prescription_id, medicine_name, category, dosage, frequency, duration, quantity, total_quantity, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                } elseif ($has_quantity) {
                    $stmt = $pdo->prepare("
                        INSERT INTO prescription_items (prescription_id, medicine_name, category, dosage, frequency, duration, quantity, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO prescription_items (prescription_id, medicine_name, category, dosage, frequency, duration, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                }
                
                // Also add to medications table for compatibility
                $med_stmt = $pdo->prepare("
                    INSERT INTO medications (prescription_id, drug_name, dosage, frequency, duration, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                
                foreach ($medicines as $med) {
                    if (!empty($med['name'])) {
                        $is_external = !empty($med['is_external']);
                        $quantity = 1;
                        if (isset($med['quantity'])) {
                            if (is_numeric($med['quantity'])) {
                                $quantity = (int)$med['quantity'];
                            } elseif (is_string($med['quantity']) && is_numeric(trim($med['quantity']))) {
                                $quantity = (int)trim($med['quantity']);
                            }
                        }
                        if ($quantity <= 0) {
                            $quantity = 1;
                        }
                        
                        $total_quantity = 0;
                        if (isset($med['total_quantity'])) {
                            if (is_numeric($med['total_quantity'])) {
                                $total_quantity = (int)$med['total_quantity'];
                            } elseif (is_string($med['total_quantity']) && is_numeric(trim($med['total_quantity']))) {
                                $total_quantity = (int)trim($med['total_quantity']);
                            }
                        }
                        if ($total_quantity < 0) {
                            $total_quantity = 0;
                        }
                        
                        // External medicines: no inventory quantity
                        if ($is_external) {
                            $quantity = 0;
                            $total_quantity = 0;
                        }
                        
                        // Get category from inventory if medicine_id is provided (for Antibiotic/Maintenance logic)
                        $category = '';
                        if (isset($med['medicine_id']) && !empty($med['medicine_id'])) {
                            $cat_stmt = $pdo->prepare("SELECT category FROM inventory WHERE id = ?");
                            $cat_stmt->execute([(int)$med['medicine_id']]);
                            $cat_result = $cat_stmt->fetch(PDO::FETCH_ASSOC);
                            $category = $cat_result['category'] ?? '';
                        }
                        $cat = strtolower(trim($category));
                        // Antibiotic: require frequency and duration; validity max 7 days. Maintenance: long-term, no expiration. Other: no special rules.
                        if ($cat === 'antibiotic' && !$is_external) {
                            $freq = trim($med['frequency'] ?? '');
                            $dur = trim($med['duration'] ?? '');
                            if ($freq === '' || $dur === '') {
                                throw new Exception('Antibiotic "' . ($med['name'] ?? '') . '" requires frequency and number of days (max 7 days).');
                            }
                            $days = parseDurationToDays($dur);
                            if ($days <= 0) {
                                throw new Exception('Antibiotic "' . ($med['name'] ?? '') . '" requires a valid number of days (max 7).');
                            }
                        }
                        
                        // Insert into prescription_items
                        if ($has_quantity && $has_total_quantity) {
                            $stmt->execute([
                                $prescription_id,
                                $med['name'],
                                $category,
                                $med['dosage'] ?? '',
                                $med['frequency'] ?? '',
                                $med['duration'] ?? '',
                                $quantity,
                                $total_quantity
                            ]);
                        } elseif ($has_quantity) {
                            try {
                                $pdo->exec("ALTER TABLE prescription_items ADD COLUMN total_quantity INT(11) DEFAULT 0 AFTER quantity");
                                $has_total_quantity = true;
                                $stmt = $pdo->prepare("
                                    INSERT INTO prescription_items (prescription_id, medicine_name, category, dosage, frequency, duration, quantity, total_quantity, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                                ");
                                $stmt->execute([
                                    $prescription_id,
                                    $med['name'],
                                    $category,
                                    $med['dosage'] ?? '',
                                    $med['frequency'] ?? '',
                                    $med['duration'] ?? '',
                                    $quantity,
                                    $total_quantity
                                ]);
                            } catch (PDOException $e) {
                                $stmt->execute([
                                    $prescription_id,
                                    $med['name'],
                                    $category,
                                    $med['dosage'] ?? '',
                                    $med['frequency'] ?? '',
                                    $med['duration'] ?? '',
                                    $quantity
                                ]);
                            }
                        } else {
                            try {
                                $pdo->exec("ALTER TABLE prescription_items ADD COLUMN quantity INT(11) DEFAULT 1 AFTER duration");
                                $has_quantity = true;
                                $stmt = $pdo->prepare("
                                    INSERT INTO prescription_items (prescription_id, medicine_name, category, dosage, frequency, duration, quantity, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                                ");
                                $stmt->execute([
                                    $prescription_id,
                                    $med['name'],
                                    $category,
                                    $med['dosage'] ?? '',
                                    $med['frequency'] ?? '',
                                    $med['duration'] ?? '',
                                    $quantity
                                ]);
                            } catch (PDOException $e) {
                                $stmt->execute([
                                    $prescription_id,
                                    $med['name'],
                                    $category,
                                    $med['dosage'] ?? '',
                                    $med['frequency'] ?? '',
                                    $med['duration'] ?? ''
                                ]);
                            }
                        }
                        
                        // Mark external medicines (to be bought outside health center)
                        $prescription_item_id = $pdo->lastInsertId();
                        if ($has_is_external && $is_external) {
                            $upd = $pdo->prepare("UPDATE prescription_items SET is_external = 1, instructions = ? WHERE id = ?");
                            $upd->execute([isset($med['instructions']) ? $med['instructions'] : '', $prescription_item_id]);
                        }
                        if ($has_timing_of_intake && isset($med['timing_of_intake']) && $med['timing_of_intake'] !== '') {
                            $upd = $pdo->prepare("UPDATE prescription_items SET timing_of_intake = ? WHERE id = ?");
                            $upd->execute([$med['timing_of_intake'], $prescription_item_id]);
                        }
                        if ($has_medicine_form && isset($med['medicine_form']) && $med['medicine_form'] !== '') {
                            $upd = $pdo->prepare("UPDATE prescription_items SET medicine_form = ? WHERE id = ?");
                            $upd->execute([$med['medicine_form'], $prescription_item_id]);
                        }
                        
                        // Category-based expiration per item (from Pharmacist Inventory): Antibiotic = validity max 7 days; Maintenance = long-term no expiration
                        if (($has_item_expiration_date || $has_item_is_long_term) && !$is_external) {
                            $item_expiration_date = null;
                            $item_is_long_term = 0;
                            if ($cat === 'antibiotic') {
                                $validity_days = min(parseDurationToDays(trim($med['duration'] ?? '')), 7);
                                $item_expiration_date = $validity_days > 0 ? date('Y-m-d', strtotime("+{$validity_days} days")) : null;
                            } elseif ($cat === 'maintenance') {
                                $item_is_long_term = 1;
                            }
                            if ($has_item_expiration_date && $has_item_is_long_term) {
                                $upd_exp = $pdo->prepare("UPDATE prescription_items SET expiration_date = ?, is_long_term = ? WHERE id = ?");
                                $upd_exp->execute([$item_expiration_date, $item_is_long_term, $prescription_item_id]);
                            }
                        }
                        
                        // Insert into medications table for compatibility
                        $med_stmt->execute([
                            $prescription_id,
                            $med['name'],
                            $med['dosage'] ?? '',
                            $med['frequency'] ?? '',
                            $med['duration'] ?? ''
                        ]);
                        
                        // Note: Inventory deduction happens when consultation is completed, not when prescription is saved
                        // This prevents double deduction if prescription is updated before consultation completion
                    }
                }
                
                // Recompute prescription-level expiration only when at least one item has category-based expiration (antibiotic or maintenance)
                if ($has_validity_fields && $has_item_expiration_date && $has_item_is_long_term) {
                    $has_cat_based = $pdo->prepare("SELECT 1 FROM prescription_items WHERE prescription_id = ? AND (expiration_date IS NOT NULL OR is_long_term = 1) LIMIT 1");
                    $has_cat_based->execute([$prescription_id]);
                    if ($has_cat_based->fetch()) {
                        $max_exp_stmt = $pdo->prepare("SELECT MAX(expiration_date) AS max_exp FROM prescription_items WHERE prescription_id = ? AND expiration_date IS NOT NULL");
                        $max_exp_stmt->execute([$prescription_id]);
                        $max_row = $max_exp_stmt->fetch(PDO::FETCH_ASSOC);
                        $new_exp = ($max_row && !empty($max_row['max_exp'])) ? $max_row['max_exp'] : null;
                        if ($new_exp) {
                            $diff_stmt = $pdo->prepare("SELECT DATEDIFF(?, date_issued) AS d FROM prescriptions WHERE id = ?");
                            $diff_stmt->execute([$new_exp, $prescription_id]);
                            $diff_row = $diff_stmt->fetch(PDO::FETCH_ASSOC);
                            $validity_days_new = $diff_row ? (int)$diff_row['d'] : null;
                            $upd_pres = $pdo->prepare("UPDATE prescriptions SET expiration_date = ?, validity_period_days = ? WHERE id = ?");
                            $upd_pres->execute([$new_exp, $validity_days_new, $prescription_id]);
                        } else {
                            $upd_pres = $pdo->prepare("UPDATE prescriptions SET expiration_date = NULL, validity_period_days = NULL WHERE id = ?");
                            $upd_pres->execute([$prescription_id]);
                        }
                    }
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Prescription saved successfully.', 'prescription_id' => $prescription_id]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'save_lab_test_requests':
            $patient_id = isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
            $appointment_id = !empty($_POST['appointment_id']) ? (int) $_POST['appointment_id'] : null;
            $diagnosis = trim($_POST['diagnosis'] ?? '');
            $lab_test_requests = json_decode($_POST['lab_test_requests'] ?? '[]', true);

            if ($patient_id <= 0 || empty($diagnosis)) {
                throw new Exception('Patient ID and diagnosis are required.');
            }
            
            if (empty($lab_test_requests) || !is_array($lab_test_requests)) {
                throw new Exception('At least one lab test request is required.');
            }
            
            // Check if doctor has access to this patient
            $access_check = $pdo->prepare("
                SELECT 1 FROM appointments 
                WHERE doctor_id = ? 
                AND status = 'approved'
                AND (
                    (user_id = ? AND patient_id = ?) 
                    OR (user_id = ? AND patient_id IS NULL)
                    OR patient_id = ?
                )
                LIMIT 1
            ");
            $access_check->execute([$doctor_id, $patient_id, $patient_id, $patient_id, $patient_id]);
            
            if (!$access_check->fetch()) {
                throw new Exception('You do not have access to this patient\'s records.');
            }

            // Check if this is a dependent
            $stmt = $pdo->prepare("SELECT created_by_user_id FROM patients WHERE id = ?");
            $stmt->execute([$patient_id]);
            $patient_record = $stmt->fetch(PDO::FETCH_ASSOC);
            $is_dependent = $patient_record && !empty($patient_record['created_by_user_id']);
            $parent_user_id = $is_dependent ? $patient_record['created_by_user_id'] : $patient_id;
            $patient_user_id_for_notif = $is_dependent ? $parent_user_id : $patient_id;

            $pdo->beginTransaction();

            try {
                // Ensure lab_requests and lab_request_tests tables exist (multi-test per request)
                try {
                    $check_lr = $pdo->query("SHOW TABLES LIKE 'lab_requests'");
                    if ($check_lr->rowCount() == 0) {
                        $pdo->exec("
                            CREATE TABLE IF NOT EXISTS `lab_requests` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `patient_id` int(11) NOT NULL,
                              `doctor_id` int(11) NOT NULL,
                              `appointment_id` int(11) DEFAULT NULL,
                              `consultation_id` int(11) DEFAULT NULL,
                              `laboratory_name` varchar(255) DEFAULT NULL,
                              `laboratory_type` enum('select', 'custom') DEFAULT 'select',
                              `notes` text DEFAULT NULL,
                              `status` enum('pending', 'completed', 'cancelled') DEFAULT 'pending',
                              `requested_date` date DEFAULT NULL,
                              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                              PRIMARY KEY (`id`),
                              KEY `idx_patient` (`patient_id`),
                              KEY `idx_doctor` (`doctor_id`),
                              KEY `idx_appointment` (`appointment_id`),
                              KEY `idx_consultation` (`consultation_id`),
                              KEY `idx_status` (`status`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                    }
                    $check_lrt = $pdo->query("SHOW TABLES LIKE 'lab_request_tests'");
                    if ($check_lrt->rowCount() == 0) {
                        $pdo->exec("
                            CREATE TABLE IF NOT EXISTS `lab_request_tests` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `lab_request_id` int(11) NOT NULL,
                              `test_name` varchar(255) NOT NULL,
                              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                              PRIMARY KEY (`id`),
                              KEY `idx_lab_request` (`lab_request_id`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                    }
                } catch (PDOException $e) {
                    error_log("Lab requests table pre-check error: " . $e->getMessage());
                }
                
                // Get consultation_id if available
                $consultation_id = null;
                try {
                    $consultation_stmt = $pdo->prepare("
                        SELECT id FROM doctor_consultations 
                        WHERE patient_id = ? AND doctor_id = ? 
                        ORDER BY created_at DESC LIMIT 1
                    ");
                    $consultation_stmt->execute([$patient_id, $doctor_user_id]);
                    $consultation_result = $consultation_stmt->fetch(PDO::FETCH_ASSOC);
                    $consultation_id = $consultation_result['id'] ?? null;
                } catch (PDOException $e) {
                    error_log("Consultation ID fetch error: " . $e->getMessage());
                }
                
                // Normalize payload: each item is one lab request with tests[] (or legacy single test_name)
                $saved_request_ids = [];
                $ins_request = $pdo->prepare("
                    INSERT INTO lab_requests (patient_id, doctor_id, appointment_id, consultation_id, laboratory_name, laboratory_type, notes, status, requested_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', CURDATE())
                ");
                $ins_test = $pdo->prepare("INSERT INTO lab_request_tests (lab_request_id, test_name) VALUES (?, ?)");
                $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, 'lab_test', 'unread')");
                
                foreach ($lab_test_requests as $req) {
                    $tests = [];
                    if (!empty($req['tests']) && is_array($req['tests'])) {
                        $tests = array_values(array_filter(array_map('trim', $req['tests'])));
                    }
                    if (empty($tests) && !empty(trim($req['test_name'] ?? ''))) {
                        $tests = [trim($req['test_name'])];
                    }
                    if (empty($tests)) {
                        continue;
                    }
                    $ins_request->execute([
                        $patient_id,
                        $doctor_id,
                        $appointment_id,
                        $consultation_id,
                        $req['laboratory_name'] ?? null,
                        $req['laboratory_type'] ?? 'select',
                        $req['notes'] ?? null
                    ]);
                    $lab_request_id = (int) $pdo->lastInsertId();
                    $saved_request_ids[] = $lab_request_id;
                    foreach ($tests as $test_name) {
                        if ($test_name !== '') {
                            $ins_test->execute([$lab_request_id, $test_name]);
                        }
                    }
                    $test_list = implode(', ', array_slice($tests, 0, 5));
                    if (count($tests) > 5) {
                        $test_list .= ' (+' . (count($tests) - 5) . ' more)';
                    }
                    $lab_notif_message = "Your doctor has requested lab tests: " . $test_list;
                    $notif_stmt->execute([$patient_user_id_for_notif, $lab_notif_message]);
                }
                
                if (empty($saved_request_ids)) {
                    throw new Exception('At least one lab request with at least one test is required.');
                }
                
                $pdo->commit();
                echo json_encode([
                    'success' => true, 
                    'message' => 'Lab test requests saved successfully.', 
                    'lab_test_request_ids' => $saved_request_ids
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'complete_consultation':
            // Ensure lab test tables exist before processing
            try {
                $check_table = $pdo->query("SHOW TABLES LIKE 'lab_test_requests'");
                if ($check_table->rowCount() == 0) {
                    // Create lab_test_requests table directly
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `lab_test_requests` (
                          `id` int(11) NOT NULL AUTO_INCREMENT,
                          `patient_id` int(11) NOT NULL,
                          `doctor_id` int(11) NOT NULL,
                          `appointment_id` int(11) DEFAULT NULL,
                          `consultation_id` int(11) DEFAULT NULL,
                          `test_name` varchar(255) NOT NULL,
                          `laboratory_name` varchar(255) DEFAULT NULL,
                          `laboratory_type` enum('select', 'custom') DEFAULT 'select',
                          `notes` text DEFAULT NULL,
                          `status` enum('pending', 'completed', 'cancelled') DEFAULT 'pending',
                          `requested_date` date DEFAULT NULL,
                          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                          PRIMARY KEY (`id`),
                          KEY `idx_patient` (`patient_id`),
                          KEY `idx_doctor` (`doctor_id`),
                          KEY `idx_appointment` (`appointment_id`),
                          KEY `idx_consultation` (`consultation_id`),
                          KEY `idx_status` (`status`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    
                    // Create lab_test_results table
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `lab_test_results` (
                          `id` int(11) NOT NULL AUTO_INCREMENT,
                          `lab_test_request_id` int(11) NOT NULL,
                          `patient_id` int(11) NOT NULL,
                          `doctor_id` int(11) DEFAULT NULL,
                          `file_path` varchar(500) NOT NULL,
                          `file_name` varchar(255) NOT NULL,
                          `file_type` varchar(50) DEFAULT NULL,
                          `file_size` int(11) DEFAULT NULL,
                          `uploaded_by` int(11) NOT NULL COMMENT 'User ID of the person who uploaded (patient or doctor)',
                          `notes` text DEFAULT NULL,
                          `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
                          PRIMARY KEY (`id`),
                          KEY `idx_lab_request` (`lab_test_request_id`),
                          KEY `idx_patient` (`patient_id`),
                          KEY `idx_doctor` (`doctor_id`),
                          KEY `idx_uploaded_by` (`uploaded_by`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                }
            } catch (PDOException $e) {
                error_log("Lab test table pre-check error: " . $e->getMessage());
            }
            
            $patient_id = isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
            $appointment_id = !empty($_POST['appointment_id']) ? (int) $_POST['appointment_id'] : null;
            $diagnosis = trim($_POST['diagnosis'] ?? '');
            $patient_instructions = trim($_POST['patient_instructions'] ?? '');
            $medicines = json_decode($_POST['medicines'] ?? '[]', true);
            $follow_up_required = isset($_POST['follow_up_required']) && $_POST['follow_up_required'] === '1';
            $follow_up_date = trim($_POST['follow_up_date'] ?? '');
            $follow_up_time = trim($_POST['follow_up_time'] ?? '');
            $follow_up_reason = trim($_POST['follow_up_reason'] ?? '');

            if ($patient_id <= 0 || empty($diagnosis)) {
                throw new Exception('Patient ID and diagnosis are required.');
            }
            
            // Check if doctor has access to this patient (has approved appointment)
            $access_check = $pdo->prepare("
                SELECT 1 FROM appointments 
                WHERE doctor_id = ? 
                AND status = 'approved'
                AND (
                    (user_id = ? AND patient_id = ?) 
                    OR (user_id = ? AND patient_id IS NULL)
                    OR patient_id = ?
                )
                LIMIT 1
            ");
            $access_check->execute([$doctor_id, $patient_id, $patient_id, $patient_id, $patient_id]);
            
            if (!$access_check->fetch()) {
                throw new Exception('You do not have access to this patient\'s records.');
            }

            // Check if this is a dependent (patient_id from patients table with created_by_user_id)
            $stmt = $pdo->prepare("SELECT created_by_user_id FROM patients WHERE id = ?");
            $stmt->execute([$patient_id]);
            $patient_record = $stmt->fetch(PDO::FETCH_ASSOC);
            $is_dependent = $patient_record && !empty($patient_record['created_by_user_id']);
            $parent_user_id = $is_dependent ? $patient_record['created_by_user_id'] : $patient_id;

            $pdo->beginTransaction();

            try {
                // Get or create appointment
                if ($appointment_id) {
                    // Update existing appointment
                    // Check if patient_instructions column exists
                    $has_patient_instructions = false;
                    try {
                        $test_stmt = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'patient_instructions'");
                        $has_patient_instructions = $test_stmt->rowCount() > 0;
                    } catch (PDOException $e) {}
                    
                    if ($has_patient_instructions) {
                        $stmt = $pdo->prepare("
                            UPDATE appointments 
                            SET status = 'completed', diagnosis = ?, patient_instructions = ?, updated_at = NOW()
                            WHERE id = ? AND doctor_id = ?
                        ");
                        $stmt->execute([$diagnosis, $patient_instructions, $appointment_id, $doctor_id]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE appointments 
                            SET status = 'completed', diagnosis = ?, updated_at = NOW()
                            WHERE id = ? AND doctor_id = ?
                        ");
                        $stmt->execute([$diagnosis, $appointment_id, $doctor_id]);
                    }
                    
                    if ($stmt->rowCount() === 0) {
                        throw new Exception('Appointment not found or does not belong to this doctor.');
                    }
                } else {
                    // Create new appointment
                    // For dependents: user_id = parent's user_id, patient_id = dependent's patient_id
                    // For registered patients: user_id = patient_id = user.id
                    // Check if patient_instructions column exists
                    $has_patient_instructions = false;
                    try {
                        $test_stmt = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'patient_instructions'");
                        $has_patient_instructions = $test_stmt->rowCount() > 0;
                    } catch (PDOException $e) {}
                    
                    if (appointmentCodeColumnExists($pdo)) {
                        $appointment_code = generateUniqueAppointmentCode($pdo);
                        if ($has_patient_instructions) {
                            $stmt = $pdo->prepare("
                                INSERT INTO appointments (user_id, patient_id, doctor_id, start_datetime, status, diagnosis, patient_instructions, appointment_code, created_at, updated_at)
                                VALUES (?, ?, ?, NOW(), 'completed', ?, ?, ?, NOW(), NOW())
                            ");
                            $stmt->execute([$parent_user_id, $patient_id, $doctor_id, $diagnosis, $patient_instructions, $appointment_code]);
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO appointments (user_id, patient_id, doctor_id, start_datetime, status, diagnosis, appointment_code, created_at, updated_at)
                                VALUES (?, ?, ?, NOW(), 'completed', ?, ?, NOW(), NOW())
                            ");
                            $stmt->execute([$parent_user_id, $patient_id, $doctor_id, $diagnosis, $appointment_code]);
                        }
                    } else {
                        if ($has_patient_instructions) {
                            $stmt = $pdo->prepare("
                                INSERT INTO appointments (user_id, patient_id, doctor_id, start_datetime, status, diagnosis, patient_instructions, created_at, updated_at)
                                VALUES (?, ?, ?, NOW(), 'completed', ?, ?, NOW(), NOW())
                            ");
                            $stmt->execute([$parent_user_id, $patient_id, $doctor_id, $diagnosis, $patient_instructions]);
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO appointments (user_id, patient_id, doctor_id, start_datetime, status, diagnosis, created_at, updated_at)
                                VALUES (?, ?, ?, NOW(), 'completed', ?, NOW(), NOW())
                            ");
                            $stmt->execute([$parent_user_id, $patient_id, $doctor_id, $diagnosis]);
                        }
                    }
                    $appointment_id = $pdo->lastInsertId();
                }

                // Create or update prescription if medicines are provided
                // Always use patient_id (dependent's patient_id or registered patient's user_id)
                if (!empty($medicines) && is_array($medicines)) {
                    // Check if prescription already exists (from "Done" button)
                    $existing_prescription_id = !empty($_POST['current_prescription_id']) ? (int) $_POST['current_prescription_id'] : null;
                    
                    // Get validity period (default to 14 days if not provided)
                    $validity_period_input = isset($_POST['prescription_validity_period']) ? trim($_POST['prescription_validity_period']) : '14';
                    $date_issued = date('Y-m-d');
                    $validity_period_days = null;
                    $expiration_date = null;
                    
                    if ($validity_period_input === 'maintenance') {
                        // Maintenance: no expiration (long-term prescription)
                        $validity_period_days = null;
                        $expiration_date = null;
                    } elseif ($validity_period_input === 'custom') {
                        $custom_expiration = isset($_POST['prescription_custom_expiration_date']) ? trim($_POST['prescription_custom_expiration_date']) : '';
                        if ($custom_expiration) {
                            $expiration_date = $custom_expiration;
                            $date1 = new DateTime($date_issued);
                            $date2 = new DateTime($expiration_date);
                            $diff = $date1->diff($date2);
                            $validity_period_days = (int)$diff->days;
                        } else {
                            $validity_period_days = 14;
                            $expiration_date = date('Y-m-d', strtotime("+14 days"));
                        }
                    } else {
                        $validity_period_days = (int)$validity_period_input;
                        if (!in_array($validity_period_days, [7, 14, 30])) {
                            $validity_period_days = 14;
                        }
                        $expiration_date = date('Y-m-d', strtotime("+{$validity_period_days} days"));
                    }
                    
                    // Check if validity_period_days and expiration_date columns exist
                    $has_validity_fields = false;
                    try {
                        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescriptions LIKE 'validity_period_days'");
                        $has_validity_fields = $test_stmt->rowCount() > 0;
                    } catch (PDOException $e) {}
                    
                    $prescription_id = $existing_prescription_id;
                    
                    if ($prescription_id) {
                        // Update existing prescription
                        if ($has_validity_fields) {
                            $stmt = $pdo->prepare("
                                UPDATE prescriptions 
                                SET diagnosis = ?, validity_period_days = ?, expiration_date = ?, updated_at = NOW()
                                WHERE id = ? AND doctor_id = ? AND patient_id = ?
                            ");
                            $stmt->execute([$diagnosis, $validity_period_days, $expiration_date, $prescription_id, $doctor_id, $patient_id]);
                        } else {
                            $stmt = $pdo->prepare("
                                UPDATE prescriptions 
                                SET diagnosis = ?, updated_at = NOW()
                                WHERE id = ? AND doctor_id = ? AND patient_id = ?
                            ");
                            $stmt->execute([$diagnosis, $prescription_id, $doctor_id, $patient_id]);
                        }
                        
                        if ($stmt->rowCount() === 0) {
                            throw new Exception('Prescription not found or does not belong to this doctor.');
                        }
                        
                        // Delete existing prescription items (we'll add all medicines fresh, including new ones)
                        $delete_stmt = $pdo->prepare("DELETE FROM prescription_items WHERE prescription_id = ?");
                        $delete_stmt->execute([$prescription_id]);
                        
                        // Also delete from medications table
                        $delete_med_stmt = $pdo->prepare("DELETE FROM medications WHERE prescription_id = ?");
                        $delete_med_stmt->execute([$prescription_id]);
                    } else {
                        // Create new prescription - use patient_id (for dependents, this is the patient table id)
                        // Use $doctor_id (from doctors table) not $doctor_user_id (from users table)
                        if ($has_validity_fields) {
                            $stmt = $pdo->prepare("
                                INSERT INTO prescriptions (patient_id, doctor_id, appointment_id, diagnosis, status, date_issued, validity_period_days, expiration_date, created_at)
                                VALUES (?, ?, ?, ?, 'active', CURDATE(), ?, ?, NOW())
                            ");
                            $stmt->execute([$patient_id, $doctor_id, $appointment_id, $diagnosis, $validity_period_days, $expiration_date]);
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO prescriptions (patient_id, doctor_id, appointment_id, diagnosis, status, date_issued, created_at)
                                VALUES (?, ?, ?, ?, 'active', CURDATE(), NOW())
                            ");
                            $stmt->execute([$patient_id, $doctor_id, $appointment_id, $diagnosis]);
                        }
                        $prescription_id = $pdo->lastInsertId();
                    }

                    // Check if prescription_items table has quantity, total_quantity, is_external, instructions columns
                    $has_quantity = false;
                    $has_total_quantity = false;
                    $has_is_external = false;
                    $has_instructions = false;
                    try {
                        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'quantity'");
                        $has_quantity = $test_stmt->rowCount() > 0;
                        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'total_quantity'");
                        $has_total_quantity = $test_stmt->rowCount() > 0;
                        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'is_external'");
                        $has_is_external = $test_stmt->rowCount() > 0;
                        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'instructions'");
                        $has_instructions = $test_stmt->rowCount() > 0;
                    } catch (PDOException $e) {}
                    if (!$has_is_external) {
                        try {
                            $pdo->exec("ALTER TABLE prescription_items ADD COLUMN is_external TINYINT(1) NOT NULL DEFAULT 0");
                            $has_is_external = true;
                        } catch (PDOException $e) {}
                    }
                    if (!$has_instructions) {
                        try {
                            $pdo->exec("ALTER TABLE prescription_items ADD COLUMN instructions TEXT NULL");
                            $has_instructions = true;
                        } catch (PDOException $e) {}
                    }
                    $has_timing_of_intake = false;
                    try {
                        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'timing_of_intake'");
                        $has_timing_of_intake = $test_stmt->rowCount() > 0;
                    } catch (PDOException $e) {}
                    if (!$has_timing_of_intake) {
                        try {
                            $pdo->exec("ALTER TABLE prescription_items ADD COLUMN timing_of_intake VARCHAR(100) NULL");
                            $has_timing_of_intake = true;
                        } catch (PDOException $e) {}
                    }
                    $has_medicine_form = false;
                    try {
                        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'medicine_form'");
                        $has_medicine_form = $test_stmt->rowCount() > 0;
                    } catch (PDOException $e) {}
                    if (!$has_medicine_form) {
                        try {
                            $pdo->exec("ALTER TABLE prescription_items ADD COLUMN medicine_form VARCHAR(50) NULL DEFAULT NULL AFTER medicine_name");
                            $has_medicine_form = true;
                        } catch (PDOException $e) {}
                    }
                    $has_item_expiration_date = false;
                    $has_item_is_long_term = false;
                    try {
                        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'expiration_date'");
                        $has_item_expiration_date = $test_stmt->rowCount() > 0;
                        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'is_long_term'");
                        $has_item_is_long_term = $test_stmt->rowCount() > 0;
                    } catch (PDOException $e) {}
                    if (!$has_item_expiration_date) {
                        try {
                            $pdo->exec("ALTER TABLE prescription_items ADD COLUMN expiration_date DATE NULL DEFAULT NULL");
                            $has_item_expiration_date = true;
                        } catch (PDOException $e) {}
                    }
                    if (!$has_item_is_long_term) {
                        try {
                            $pdo->exec("ALTER TABLE prescription_items ADD COLUMN is_long_term TINYINT(1) NOT NULL DEFAULT 0");
                            $has_item_is_long_term = true;
                        } catch (PDOException $e) {}
                    }

                    // Add medications to prescription_items table (used by doctor portal)
                    if ($has_quantity && $has_total_quantity) {
                        $stmt = $pdo->prepare("
                            INSERT INTO prescription_items (prescription_id, medicine_name, category, dosage, frequency, duration, quantity, total_quantity, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                    } elseif ($has_quantity) {
                        $stmt = $pdo->prepare("
                            INSERT INTO prescription_items (prescription_id, medicine_name, category, dosage, frequency, duration, quantity, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO prescription_items (prescription_id, medicine_name, category, dosage, frequency, duration, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                    }
                    
                    // Also add to medications table for compatibility
                    $med_stmt = $pdo->prepare("
                        INSERT INTO medications (prescription_id, drug_name, dosage, frequency, duration, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    
                    foreach ($medicines as $med) {
                        if (!empty($med['name'])) {
                            $is_external = !empty($med['is_external']);
                            // Get quantity (quantity per intake) - ensure it's properly parsed
                            $quantity = 1; // Default
                            if (isset($med['quantity'])) {
                                if (is_numeric($med['quantity'])) {
                                    $quantity = (int)$med['quantity'];
                                } elseif (is_string($med['quantity']) && is_numeric(trim($med['quantity']))) {
                                    $quantity = (int)trim($med['quantity']);
                                }
                            }
                            
                            // Ensure quantity is at least 1 (for non-external)
                            if ($quantity <= 0) {
                                $quantity = 1;
                            }
                            
                            // Get total_quantity (auto-calculated) - ensure it's properly parsed
                            $total_quantity = 0; // Default
                            if (isset($med['total_quantity'])) {
                                if (is_numeric($med['total_quantity'])) {
                                    $total_quantity = (int)$med['total_quantity'];
                                } elseif (is_string($med['total_quantity']) && is_numeric(trim($med['total_quantity']))) {
                                    $total_quantity = (int)trim($med['total_quantity']);
                                }
                            }
                            
                            // Ensure total_quantity is at least 0
                            if ($total_quantity < 0) {
                                $total_quantity = 0;
                            }
                            
                            // External medicines: no inventory quantity, do not deduct
                            if ($is_external) {
                                $quantity = 0;
                                $total_quantity = 0;
                            }
                            
                            // Log for debugging
                            error_log("Saving prescription #{$prescription_id}: Medicine '{$med['name']}', Quantity per intake: {$quantity}, Total quantity: {$total_quantity}" . ($is_external ? ' (external)' : ''));
                            
                            // Get category from inventory if medicine_id is provided (for Antibiotic/Maintenance logic)
                            $category = '';
                            if (isset($med['medicine_id']) && !empty($med['medicine_id'])) {
                                $cat_stmt = $pdo->prepare("SELECT category FROM inventory WHERE id = ?");
                                $cat_stmt->execute([(int)$med['medicine_id']]);
                                $cat_result = $cat_stmt->fetch(PDO::FETCH_ASSOC);
                                $category = $cat_result['category'] ?? '';
                            }
                            $cat = strtolower(trim($category));
                            // Antibiotic: require frequency and duration; validity max 7 days. Maintenance: long-term, no expiration.
                            if ($cat === 'antibiotic' && !$is_external) {
                                $freq = trim($med['frequency'] ?? '');
                                $dur = trim($med['duration'] ?? '');
                                if ($freq === '' || $dur === '') {
                                    throw new Exception('Antibiotic "' . ($med['name'] ?? '') . '" requires frequency and number of days (max 7 days).');
                                }
                                $days = parseDurationToDays($dur);
                                if ($days <= 0) {
                                    throw new Exception('Antibiotic "' . ($med['name'] ?? '') . '" requires a valid number of days (max 7).');
                                }
                            }
                            
                            // Insert into prescription_items
                            if ($has_quantity && $has_total_quantity) {
                                $stmt->execute([
                                    $prescription_id,
                                    $med['name'],
                                    $category,
                                    $med['dosage'] ?? '',
                                    $med['frequency'] ?? '',
                                    $med['duration'] ?? '',
                                    $quantity,
                                    $total_quantity
                                ]);
                            } elseif ($has_quantity) {
                                // Try to add total_quantity column if it doesn't exist
                                try {
                                    $pdo->exec("ALTER TABLE prescription_items ADD COLUMN total_quantity INT(11) DEFAULT 0 AFTER quantity");
                                    $has_total_quantity = true;
                                    // Re-prepare statement with total_quantity
                                    $stmt = $pdo->prepare("
                                        INSERT INTO prescription_items (prescription_id, medicine_name, category, dosage, frequency, duration, quantity, total_quantity, created_at)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                                    ");
                                    $stmt->execute([
                                        $prescription_id,
                                        $med['name'],
                                        $category,
                                        $med['dosage'] ?? '',
                                        $med['frequency'] ?? '',
                                        $med['duration'] ?? '',
                                        $quantity,
                                        $total_quantity
                                    ]);
                                } catch (PDOException $e) {
                                    error_log("Could not add total_quantity column: " . $e->getMessage());
                                    // Fall back to inserting without total_quantity
                                    $stmt->execute([
                                        $prescription_id,
                                        $med['name'],
                                        $category,
                                        $med['dosage'] ?? '',
                                        $med['frequency'] ?? '',
                                        $med['duration'] ?? '',
                                        $quantity
                                    ]);
                                }
                            } else {
                                // If quantity column doesn't exist, try to add it
                                try {
                                    $pdo->exec("ALTER TABLE prescription_items ADD COLUMN quantity INT(11) DEFAULT 1 AFTER duration");
                                    $has_quantity = true;
                                    // Re-prepare statement with quantity
                                    $stmt = $pdo->prepare("
                                        INSERT INTO prescription_items (prescription_id, medicine_name, category, dosage, frequency, duration, quantity, created_at)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                                    ");
                                    // Get category from inventory if medicine_id is provided
                                    $category = '';
                                    if (isset($med['medicine_id']) && !empty($med['medicine_id'])) {
                                        $cat_stmt = $pdo->prepare("SELECT category FROM inventory WHERE id = ?");
                                        $cat_stmt->execute([(int)$med['medicine_id']]);
                                        $cat_result = $cat_stmt->fetch(PDO::FETCH_ASSOC);
                                        $category = $cat_result['category'] ?? '';
                                    }
                                    
                                    $stmt->execute([
                                        $prescription_id,
                                        $med['name'],
                                        $category,
                                        $med['dosage'] ?? '',
                                        $med['frequency'] ?? '',
                                        $med['duration'] ?? '',
                                        $quantity
                                    ]);
                                } catch (PDOException $e) {
                                    // If adding column fails, insert without quantity
                                    error_log("Could not add quantity column: " . $e->getMessage());
                                    
                                    // Get category from inventory if medicine_id is provided
                                    $category = '';
                                    if (isset($med['medicine_id']) && !empty($med['medicine_id'])) {
                                        $cat_stmt = $pdo->prepare("SELECT category FROM inventory WHERE id = ?");
                                        $cat_stmt->execute([(int)$med['medicine_id']]);
                                        $cat_result = $cat_stmt->fetch(PDO::FETCH_ASSOC);
                                        $category = $cat_result['category'] ?? '';
                                    }
                                    
                                    $stmt->execute([
                                        $prescription_id,
                                        $med['name'],
                                        $category,
                                        $med['dosage'] ?? '',
                                        $med['frequency'] ?? '',
                                        $med['duration'] ?? ''
                                    ]);
                                }
                            }
                            
                            // Mark external medicines (to be bought outside health center)
                            $prescription_item_id = $pdo->lastInsertId();
                            if ($has_is_external && $is_external) {
                                $upd = $pdo->prepare("UPDATE prescription_items SET is_external = 1, instructions = ? WHERE id = ?");
                                $upd->execute([isset($med['instructions']) ? $med['instructions'] : '', $prescription_item_id]);
                            }
                            if ($has_timing_of_intake && isset($med['timing_of_intake']) && $med['timing_of_intake'] !== '') {
                                $upd = $pdo->prepare("UPDATE prescription_items SET timing_of_intake = ? WHERE id = ?");
                                $upd->execute([$med['timing_of_intake'], $prescription_item_id]);
                            }
                            if ($has_medicine_form && isset($med['medicine_form']) && $med['medicine_form'] !== '') {
                                $upd = $pdo->prepare("UPDATE prescription_items SET medicine_form = ? WHERE id = ?");
                                $upd->execute([$med['medicine_form'], $prescription_item_id]);
                            }
                            
                            // Category-based expiration per item (Antibiotic = max 7 days; Maintenance = long-term no expiration)
                            if (($has_item_expiration_date || $has_item_is_long_term) && !$is_external) {
                                $item_expiration_date = null;
                                $item_is_long_term = 0;
                                if ($cat === 'antibiotic') {
                                    $validity_days = min(parseDurationToDays(trim($med['duration'] ?? '')), 7);
                                    $item_expiration_date = $validity_days > 0 ? date('Y-m-d', strtotime("+{$validity_days} days")) : null;
                                } elseif ($cat === 'maintenance') {
                                    $item_is_long_term = 1;
                                }
                                if ($has_item_expiration_date && $has_item_is_long_term) {
                                    $upd_exp = $pdo->prepare("UPDATE prescription_items SET expiration_date = ?, is_long_term = ? WHERE id = ?");
                                    $upd_exp->execute([$item_expiration_date, $item_is_long_term, $prescription_item_id]);
                                }
                            }
                            
                            // Insert into medications table for compatibility
                            $med_stmt->execute([
                                $prescription_id,
                                $med['name'],
                                $med['dosage'] ?? '',
                                $med['frequency'] ?? '',
                                $med['duration'] ?? ''
                            ]);
                            
                            // Deduct inventory only for non-external medicines (external = to be bought outside, no inventory impact)
                            if ($total_quantity > 0 && !$is_external) {
                                $medicine_id = isset($med['medicine_id']) && !empty($med['medicine_id']) ? (int)$med['medicine_id'] : null;
                                deductInventoryForPrescription($pdo, $medicine_id, $med['name'], $total_quantity);
                            }
                        }
                    }
                    
                    // Recompute prescription-level expiration from items (antibiotic = max 7 days per item; maintenance = no expiration)
                    if (!empty($medicines) && $has_validity_fields && $has_item_expiration_date && $has_item_is_long_term) {
                        $has_cat_based = $pdo->prepare("SELECT 1 FROM prescription_items WHERE prescription_id = ? AND (expiration_date IS NOT NULL OR is_long_term = 1) LIMIT 1");
                        $has_cat_based->execute([$prescription_id]);
                        if ($has_cat_based->fetch()) {
                            $max_exp_stmt = $pdo->prepare("SELECT MAX(expiration_date) AS max_exp FROM prescription_items WHERE prescription_id = ? AND expiration_date IS NOT NULL");
                            $max_exp_stmt->execute([$prescription_id]);
                            $max_row = $max_exp_stmt->fetch(PDO::FETCH_ASSOC);
                            $new_exp = ($max_row && !empty($max_row['max_exp'])) ? $max_row['max_exp'] : null;
                            if ($new_exp) {
                                $diff_stmt = $pdo->prepare("SELECT DATEDIFF(?, date_issued) AS d FROM prescriptions WHERE id = ?");
                                $diff_stmt->execute([$new_exp, $prescription_id]);
                                $diff_row = $diff_stmt->fetch(PDO::FETCH_ASSOC);
                                $validity_days_new = $diff_row ? (int)$diff_row['d'] : null;
                                $upd_pres = $pdo->prepare("UPDATE prescriptions SET expiration_date = ?, validity_period_days = ? WHERE id = ?");
                                $upd_pres->execute([$new_exp, $validity_days_new, $prescription_id]);
                            } else {
                                $upd_pres = $pdo->prepare("UPDATE prescriptions SET expiration_date = NULL, validity_period_days = NULL WHERE id = ?");
                                $upd_pres->execute([$prescription_id]);
                            }
                        }
                    }
                }

                // Create notification for patient about record update
                // Get the actual patient user_id (parent if dependent, or patient_id if registered)
                $patient_user_id_for_notif = $is_dependent ? $parent_user_id : $patient_id;
                
                // Try to create medical record entry if patient_profile exists
                $check_profile = $pdo->prepare("SELECT patient_id FROM patient_profiles WHERE patient_id = ?");
                $check_profile->execute([$patient_user_id_for_notif]);
                
                if ($check_profile->fetch()) {
                    // Check if record already exists for this appointment
                    $check_record = $pdo->prepare("
                        SELECT record_id FROM medical_records 
                        WHERE appointment_id = ? AND patient_id = ?
                    ");
                    $check_record->execute([$appointment_id, $patient_user_id_for_notif]);
                    
                    if (!$check_record->fetch()) {
                        // Create medical record entry
                        $record_stmt = $pdo->prepare("
                            INSERT INTO medical_records (patient_id, doctor_id, appointment_id, diagnosis, date_recorded, created_at)
                            VALUES (?, ?, ?, ?, CURDATE(), NOW())
                        ");
                        $record_stmt->execute([$patient_user_id_for_notif, $doctor_user_id, $appointment_id, $diagnosis]);
                    }
                }
                
                // Create notification for patient about record update
                // Always create notification for each consultation completion
                // Check if reference_id column exists for linking to appointment
                $has_reference_id = false;
                try {
                    $test_stmt = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'reference_id'");
                    $has_reference_id = $test_stmt->rowCount() > 0;
                } catch (PDOException $e) {
                    // Column doesn't exist yet
                }
                
                $notif_message = "Your medical record has been updated with a new consultation.";
                
                // Check if notification already exists for this specific appointment (to prevent duplicates from same consultation being submitted twice)
                // Only check within last 2 minutes to catch accidental duplicate submissions
                // This allows multiple different consultations to each create a notification
                if ($has_reference_id && $appointment_id) {
                    $check_notif = $pdo->prepare("
                        SELECT notification_id 
                        FROM notifications 
                        WHERE user_id = ? 
                          AND type = 'record_update' 
                          AND reference_id = ?
                          AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                        LIMIT 1
                    ");
                    $check_notif->execute([$patient_user_id_for_notif, $appointment_id]);
                } else {
                    // Fallback: check by message and very recent time (within last 2 minutes) to catch only immediate duplicates
                    $check_notif = $pdo->prepare("
                        SELECT notification_id 
                        FROM notifications 
                        WHERE user_id = ? 
                          AND type = 'record_update' 
                          AND message = ?
                          AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                        LIMIT 1
                    ");
                    $check_notif->execute([$patient_user_id_for_notif, $notif_message]);
                }
                
                // Only skip if notification was created in the last 2 minutes (prevents duplicate from same consultation submission)
                // This allows each new consultation to create a notification
                if (!$check_notif->fetch()) {
                    if ($has_reference_id && $appointment_id) {
                        $notif_stmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, message, type, reference_id, status) 
                            VALUES (?, ?, 'record_update', ?, 'unread')
                        ");
                        $notif_stmt->execute([$patient_user_id_for_notif, $notif_message, $appointment_id]);
                    } else {
                        $notif_stmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, message, type, status) 
                            VALUES (?, ?, 'record_update', 'unread')
                        ");
                        $notif_stmt->execute([$patient_user_id_for_notif, $notif_message]);
                    }
                }
                
                // Handle lab test requests (multi-test per request: lab_requests + lab_request_tests)
                $lab_test_requests = json_decode($_POST['lab_test_requests'] ?? '[]', true);
                if (!empty($lab_test_requests) && is_array($lab_test_requests)) {
                    try {
                        $check_lr = $pdo->query("SHOW TABLES LIKE 'lab_requests'");
                        if ($check_lr->rowCount() == 0) {
                            $pdo->exec("
                                CREATE TABLE IF NOT EXISTS `lab_requests` (
                                  `id` int(11) NOT NULL AUTO_INCREMENT,
                                  `patient_id` int(11) NOT NULL,
                                  `doctor_id` int(11) NOT NULL,
                                  `appointment_id` int(11) DEFAULT NULL,
                                  `consultation_id` int(11) DEFAULT NULL,
                                  `laboratory_name` varchar(255) DEFAULT NULL,
                                  `laboratory_type` enum('select', 'custom') DEFAULT 'select',
                                  `notes` text DEFAULT NULL,
                                  `status` enum('pending', 'completed', 'cancelled') DEFAULT 'pending',
                                  `requested_date` date DEFAULT NULL,
                                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                                  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                                  PRIMARY KEY (`id`),
                                  KEY `idx_patient` (`patient_id`),
                                  KEY `idx_doctor` (`doctor_id`),
                                  KEY `idx_appointment` (`appointment_id`),
                                  KEY `idx_consultation` (`consultation_id`),
                                  KEY `idx_status` (`status`)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                            ");
                        }
                        $check_lrt = $pdo->query("SHOW TABLES LIKE 'lab_request_tests'");
                        if ($check_lrt->rowCount() == 0) {
                            $pdo->exec("
                                CREATE TABLE IF NOT EXISTS `lab_request_tests` (
                                  `id` int(11) NOT NULL AUTO_INCREMENT,
                                  `lab_request_id` int(11) NOT NULL,
                                  `test_name` varchar(255) NOT NULL,
                                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                                  PRIMARY KEY (`id`),
                                  KEY `idx_lab_request` (`lab_request_id`)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                            ");
                        }
                    } catch (PDOException $e) {
                        throw new Exception('Database error creating lab request tables: ' . $e->getMessage());
                    }
                    $consultation_id = null;
                    try {
                        $consultation_stmt = $pdo->prepare("
                            SELECT id FROM doctor_consultations 
                            WHERE patient_id = ? AND doctor_id = ? 
                            ORDER BY created_at DESC LIMIT 1
                        ");
                        $consultation_stmt->execute([$patient_id, $doctor_user_id]);
                        $consultation_result = $consultation_stmt->fetch(PDO::FETCH_ASSOC);
                        $consultation_id = $consultation_result['id'] ?? null;
                    } catch (PDOException $e) {
                        error_log("Consultation ID fetch error: " . $e->getMessage());
                    }
                    $ins_request = $pdo->prepare("
                        INSERT INTO lab_requests (patient_id, doctor_id, appointment_id, consultation_id, laboratory_name, laboratory_type, notes, status, requested_date)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', CURDATE())
                    ");
                    $ins_test = $pdo->prepare("INSERT INTO lab_request_tests (lab_request_id, test_name) VALUES (?, ?)");
                    $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, 'lab_test', 'unread')");
                    foreach ($lab_test_requests as $req) {
                        $tests = [];
                        if (!empty($req['tests']) && is_array($req['tests'])) {
                            $tests = array_values(array_filter(array_map('trim', $req['tests'])));
                        }
                        if (empty($tests) && !empty(trim($req['test_name'] ?? ''))) {
                            $tests = [trim($req['test_name'])];
                        }
                        if (empty($tests)) {
                            continue;
                        }
                        $ins_request->execute([
                            $patient_id,
                            $doctor_id,
                            $appointment_id,
                            $consultation_id,
                            $req['laboratory_name'] ?? null,
                            $req['laboratory_type'] ?? 'select',
                            $req['notes'] ?? null
                        ]);
                        $lab_request_id = (int) $pdo->lastInsertId();
                        foreach ($tests as $test_name) {
                            if ($test_name !== '') {
                                $ins_test->execute([$lab_request_id, $test_name]);
                            }
                        }
                        $test_list = implode(', ', array_slice($tests, 0, 5));
                        if (count($tests) > 5) {
                            $test_list .= ' (+' . (count($tests) - 5) . ' more)';
                        }
                        $lab_notif_message = "Your doctor has requested lab tests: " . $test_list;
                        $notif_stmt->execute([$patient_user_id_for_notif, $lab_notif_message]);
                    }
                }
                
                // Handle follow-up appointment if requested
                if ($follow_up_required && !empty($follow_up_date) && !empty($follow_up_time) && !empty($follow_up_reason)) {
                    // Check if follow_up_appointments table exists, create if not
                    try {
                        $check_table = $pdo->query("SHOW TABLES LIKE 'follow_up_appointments'");
                        if ($check_table->rowCount() == 0) {
                            $pdo->exec("
                                CREATE TABLE `follow_up_appointments` (
                                  `id` int(11) NOT NULL AUTO_INCREMENT,
                                  `original_appointment_id` int(11) NOT NULL,
                                  `user_id` int(11) DEFAULT NULL,
                                  `patient_id` int(11) DEFAULT NULL,
                                  `doctor_id` int(11) DEFAULT NULL,
                                  `fdo_id` int(11) DEFAULT NULL,
                                  `proposed_datetime` datetime NOT NULL,
                                  `selected_datetime` datetime DEFAULT NULL,
                                  `status` enum('doctor_set', 'pending_patient_confirmation', 'pending_doctor_approval', 'approved', 'declined', 'cancelled', 'reschedule_requested') DEFAULT 'doctor_set',
                                  `notes` text DEFAULT NULL,
                                  `follow_up_reason` varchar(255) DEFAULT NULL,
                                  `alternative_datetime_1` datetime DEFAULT NULL,
                                  `alternative_datetime_2` datetime DEFAULT NULL,
                                  `alternative_datetime_3` datetime DEFAULT NULL,
                                  `patient_selected_alternative` int(11) DEFAULT NULL,
                                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                                  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                                  PRIMARY KEY (`id`),
                                  KEY `idx_original_appointment` (`original_appointment_id`),
                                  KEY `idx_user` (`user_id`),
                                  KEY `idx_patient` (`patient_id`),
                                  KEY `idx_doctor` (`doctor_id`),
                                  KEY `idx_fdo` (`fdo_id`),
                                  KEY `idx_status` (`status`)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                            ");
                        }
                    } catch (PDOException $e) {
                        // Table might already exist, continue
                    }
                    
                    // Check if status enum includes 'doctor_set' and 'reschedule_requested'
                    try {
                        $check_enum = $pdo->query("SHOW COLUMNS FROM follow_up_appointments WHERE Field = 'status'");
                        $enum_result = $check_enum->fetch(PDO::FETCH_ASSOC);
                        if ($enum_result && (strpos($enum_result['Type'], 'doctor_set') === false || strpos($enum_result['Type'], 'reschedule_requested') === false)) {
                            // Add 'doctor_set' and 'reschedule_requested' to enum
                            $pdo->exec("
                                ALTER TABLE follow_up_appointments 
                                MODIFY COLUMN status enum(
                                    'doctor_set',
                                    'pending_patient_confirmation',
                                    'pending_doctor_approval',
                                    'approved',
                                    'declined',
                                    'cancelled',
                                    'reschedule_requested'
                                ) DEFAULT 'doctor_set'
                            ");
                        }
                    } catch (PDOException $e) {
                        // Enum update might fail, continue
                    }
                    
                    // Check if follow_up_reason column exists
                    try {
                        $check_column = $pdo->query("SHOW COLUMNS FROM follow_up_appointments LIKE 'follow_up_reason'");
                        if ($check_column->rowCount() == 0) {
                            $pdo->exec("ALTER TABLE follow_up_appointments ADD COLUMN follow_up_reason VARCHAR(255) DEFAULT NULL AFTER notes");
                        }
                    } catch (PDOException $e) {
                        // Column might already exist
                    }
                    
                    // Combine date and time
                    $proposed_datetime = $follow_up_date . ' ' . $follow_up_time;
                    
                    // Get FDO ID from original appointment if available
                    $fdo_id = null;
                    if ($appointment_id) {
                        $fdo_stmt = $pdo->prepare("SELECT fdo_id FROM appointments WHERE id = ?");
                        $fdo_stmt->execute([$appointment_id]);
                        $fdo_result = $fdo_stmt->fetch(PDO::FETCH_ASSOC);
                        $fdo_id = $fdo_result['fdo_id'] ?? null;
                    }
                    
                    // Insert follow-up appointment with status 'doctor_set'
                    $follow_up_stmt = $pdo->prepare("
                        INSERT INTO follow_up_appointments 
                        (original_appointment_id, user_id, patient_id, doctor_id, fdo_id, proposed_datetime, selected_datetime, status, notes, follow_up_reason)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'doctor_set', ?, ?)
                    ");
                    $follow_up_stmt->execute([
                        $appointment_id,
                        $parent_user_id,
                        $patient_id,
                        $doctor_id,
                        $fdo_id,
                        $proposed_datetime,
                        $proposed_datetime, // Initially, selected_datetime = proposed_datetime
                        'Follow-up scheduled by doctor',
                        $follow_up_reason
                    ]);
                    
                    $follow_up_id = $pdo->lastInsertId();
                    
                    // Create notification for patient
                    $formatted_date = date('M d, Y', strtotime($proposed_datetime));
                    $formatted_time = date('g:i A', strtotime($proposed_datetime));
                    $follow_up_message = "Your doctor has scheduled a follow-up appointment: {$formatted_date} at {$formatted_time}. Reason: {$follow_up_reason}";
                    
                    $follow_up_notif_stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, message, type, status) 
                        VALUES (?, ?, 'appointment', 'unread')
                    ");
                    $follow_up_notif_stmt->execute([$patient_user_id_for_notif, $follow_up_message]);
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Consultation completed successfully.', 'appointment_id' => $appointment_id]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'save_triage':
            // Initial screening create/update moved to FDO Appointments; doctors are view-only
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only FDO can create or update initial screening. Use the FDO Appointments page.']);
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

