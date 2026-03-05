<?php
session_start();
require 'db.php';
require_once 'residency_verification_helper.php';
if(empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'patient') { 
    header('Location: login.php'); 
    exit; 
}

// Get user's patient record
$user_id = $_SESSION['user']['id'];
$residency_verified = isPatientResidencyVerified($user_id);

// Get selected patient/dependent ID from query parameter
$selected_patient_id = isset($_GET['patient_id']) ? $_GET['patient_id'] : 0;
$selected_dependent_id = null;
$is_dependent = false;
$selected_patient_record = null;
$selected_dependent_record = null;

// Check if selection is a dependent ID (format: "dep_{id}") or patient ID
if (is_string($selected_patient_id) && strpos($selected_patient_id, 'dep_') === 0) {
    $selected_dependent_id = (int)str_replace('dep_', '', $selected_patient_id);
    $selected_patient_id = 0;
} else {
    $selected_patient_id = (int)$selected_patient_id;
}

// Get all dependents for this user
$stmt = $pdo->prepare('
    SELECT d.*
    FROM dependents d
    WHERE d.patient_id = ?
    ORDER BY d.created_at DESC
');
$stmt->execute([$user_id]);
$dependents_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// For each dependent, find their patient record if it exists
$dependents = [];
foreach ($dependents_raw as $dep) {
    $stmt = $pdo->prepare('
        SELECT p.*
        FROM patients p
        WHERE p.created_by_user_id = ? 
          AND p.first_name = ? 
          AND p.last_name = ?
        ORDER BY p.created_at DESC
        LIMIT 1
    ');
    $stmt->execute([$user_id, $dep['first_name'], $dep['last_name']]);
    $patient_record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $dep['patient_table_id'] = $patient_record ? $patient_record['id'] : null;
    $dep['p_first_name'] = $patient_record ? $patient_record['first_name'] : $dep['first_name'];
    $dep['p_middle_name'] = $patient_record ? $patient_record['middle_name'] : $dep['middle_name'];
    $dep['p_last_name'] = $patient_record ? $patient_record['last_name'] : $dep['last_name'];
    $dep['phone'] = $patient_record ? $patient_record['phone'] : null;
    $dep['dob'] = $patient_record ? $patient_record['dob'] : $dep['date_of_birth'];
    $dep['sex'] = $patient_record ? $patient_record['sex'] : $dep['sex'];
    
    $dependents[] = $dep;
}

// If a dependent ID is selected (from dependents table, no patient record yet)
if ($selected_dependent_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM dependents WHERE id = ? AND patient_id = ?');
    $stmt->execute([$selected_dependent_id, $user_id]);
    $selected_dependent_record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selected_dependent_record) {
        $is_dependent = true;
        // Use dependent's data from dependents table; prefer dependent's address/contact if stored
        $dep_contact = isset($selected_dependent_record['contact_no']) ? $selected_dependent_record['contact_no'] : null;
        $dep_address = isset($selected_dependent_record['address']) ? $selected_dependent_record['address'] : null;
        $patient = [
            'id' => null,
            'first_name' => $selected_dependent_record['first_name'],
            'middle_name' => $selected_dependent_record['middle_name'] ?? '',
            'last_name' => $selected_dependent_record['last_name'],
            'contact_no' => $dep_contact,
            'address' => $dep_address,
            'date_of_birth' => $selected_dependent_record['date_of_birth'],
            'sex' => $selected_dependent_record['sex'],
            'allergies' => null,
            'medical_history' => $selected_dependent_record['medical_conditions'] ?? null
        ];
        // Fall back to parent's contact and address if dependent has none
        if (empty($patient['contact_no']) || empty($patient['address'])) {
            $stmt = $pdo->prepare('SELECT contact_no, address FROM users WHERE id = ?');
            $stmt->execute([$user_id]);
            $parent_info = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($parent_info) {
                if (empty($patient['contact_no'])) $patient['contact_no'] = $parent_info['contact_no'];
                if (empty($patient['address'])) $patient['address'] = $parent_info['address'];
            }
        }
        
        // No medical history yet (no appointments)
        $medical_history = [];
    } else {
        // Invalid dependent ID, treat as self
        $selected_dependent_id = null;
        $selected_patient_id = 0;
    }
}
// If a patient_id is selected, check if it's a dependent with patient record
elseif ($selected_patient_id > 0) {
    // Check if it's a dependent (patient_id from patients table)
    $stmt = $pdo->prepare('SELECT * FROM patients WHERE id = ? AND created_by_user_id = ?');
    $stmt->execute([$selected_patient_id, $user_id]);
    $selected_patient_record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selected_patient_record) {
        $is_dependent = true;
        // Use dependent's data from patients table
        $patient = [
            'id' => $selected_patient_record['id'],
            'first_name' => $selected_patient_record['first_name'],
            'middle_name' => $selected_patient_record['middle_name'],
            'last_name' => $selected_patient_record['last_name'],
            'contact_no' => $selected_patient_record['phone'],
            'address' => $selected_patient_record['address'],
            'date_of_birth' => $selected_patient_record['dob'],
            'sex' => $selected_patient_record['sex'],
            'allergies' => $selected_patient_record['allergies'],
            'medical_history' => $selected_patient_record['notes']
        ];
        
        // Get medical history for dependent using patient_id from patients table
        // One row per appointment (no join to doctor_consultations to avoid duplicate visits)
        $stmt = $pdo->prepare('
            SELECT a.*, 
                   CONCAT(COALESCE(du.first_name, ""), " ", COALESCE(du.middle_name, ""), " ", COALESCE(du.last_name, "")) as doctor_name,
                   a.start_datetime as visit_date,
                   a.status,
                   a.diagnosis as appointment_diagnosis,
                   a.patient_instructions,
                   "" as prescription 
            FROM appointments a 
            LEFT JOIN doctors d ON d.id = a.doctor_id 
            LEFT JOIN users du ON du.id = d.user_id
            WHERE a.patient_id = ?
              AND a.status IN ("approved", "completed")
            ORDER BY a.start_datetime DESC
        ');
        $stmt->execute([$selected_patient_id]);
        $medical_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Build lab test requests by appointment for this dependent so they show in each visit
        $lab_test_requests_by_appointment = [];
        try {
            $appointment_ids = array_column($medical_history, 'id');
            if (!empty($appointment_ids)) {
                $placeholders = implode(',', array_fill(0, count($appointment_ids), '?'));
                $table_lr = $pdo->query("SHOW TABLES LIKE 'lab_requests'");
                if ($table_lr->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        SELECT lr.*,
                               CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
                        FROM lab_requests lr
                        LEFT JOIN doctors d ON d.id = lr.doctor_id
                        LEFT JOIN users du ON du.id = d.user_id
                        WHERE lr.appointment_id IN ($placeholders)
                        ORDER BY lr.created_at DESC
                    ");
                    $stmt->execute($appointment_ids);
                    $all_lr = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($all_lr)) {
                        $lr_ids = array_column($all_lr, 'id');
                        $ph = implode(',', array_fill(0, count($lr_ids), '?'));
                        $stmt = $pdo->prepare("SELECT lab_request_id, test_name FROM lab_request_tests WHERE lab_request_id IN ($ph) ORDER BY id");
                        $stmt->execute($lr_ids);
                        $tests_by_lr = [];
                        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $tests_by_lr[$r['lab_request_id']][] = $r['test_name'];
                        }
                        foreach ($all_lr as $lr) {
                            $lr['tests'] = $tests_by_lr[$lr['id']] ?? [];
                            $lr['test_name'] = implode(', ', $lr['tests']);
                            $lr['is_lab_request'] = true;
                            $appt_id = $lr['appointment_id'];
                            if ($appt_id) {
                                if (!isset($lab_test_requests_by_appointment[$appt_id])) {
                                    $lab_test_requests_by_appointment[$appt_id] = [];
                                }
                                $lab_test_requests_by_appointment[$appt_id][] = $lr;
                            }
                        }
                    }
                }
                if (empty($lab_test_requests_by_appointment)) {
                    $table_check = $pdo->query("SHOW TABLES LIKE 'lab_test_requests'");
                    if ($table_check->rowCount() > 0) {
                        $stmt = $pdo->prepare("
                            SELECT ltr.*,
                                   CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
                            FROM lab_test_requests ltr
                            LEFT JOIN doctors d ON d.id = ltr.doctor_id
                            LEFT JOIN users du ON du.id = d.user_id
                            WHERE ltr.appointment_id IN ($placeholders)
                            ORDER BY ltr.created_at DESC
                        ");
                        $stmt->execute($appointment_ids);
                        $all_lab_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($all_lab_requests as $ltr) {
                            $ltr['tests'] = [$ltr['test_name']];
                            $appt_id = $ltr['appointment_id'];
                            if ($appt_id) {
                                if (!isset($lab_test_requests_by_appointment[$appt_id])) {
                                    $lab_test_requests_by_appointment[$appt_id] = [];
                                }
                                $lab_test_requests_by_appointment[$appt_id][] = $ltr;
                            }
                        }
                    }
                }
                // Deduplicate per appointment so same request shows only once
                foreach ($lab_test_requests_by_appointment as $appt_id => $list) {
                    $seen = [];
                    $lab_test_requests_by_appointment[$appt_id] = array_values(array_filter($list, function ($req) use (&$seen) {
                        $t = $req['tests'] ?? [];
                        if (is_array($t)) { sort($t); $k = ($req['created_at'] ?? '') . '|' . implode('|', $t); }
                        else { $k = ($req['created_at'] ?? '') . '|' . ($req['test_name'] ?? ''); }
                        if (isset($seen[$k])) return false;
                        $seen[$k] = true;
                        return true;
                    }));
                }
            }
        } catch (PDOException $e) {
            error_log("Dependent lab requests error: " . $e->getMessage());
        }
        
        // Get prescriptions and consultation (diagnosis/notes) for each appointment
        foreach ($medical_history as &$record) {
            $record['lab_test_requests'] = $lab_test_requests_by_appointment[$record['id']] ?? [];
            // Fetch latest consultation for this appointment (one per visit)
            if (!empty($record['id'])) {
                $dc_stmt = $pdo->prepare('
                    SELECT diagnosis as consultation_diagnosis, notes as consultation_notes
                    FROM doctor_consultations
                    WHERE appointment_id = ?
                    ORDER BY id DESC
                    LIMIT 1
                ');
                $dc_stmt->execute([$record['id']]);
                $dc = $dc_stmt->fetch(PDO::FETCH_ASSOC);
                if ($dc) {
                    $record['consultation_diagnosis'] = $dc['consultation_diagnosis'] ?? null;
                    $record['consultation_notes'] = $dc['consultation_notes'] ?? null;
                } else {
                    $record['consultation_diagnosis'] = null;
                    $record['consultation_notes'] = null;
                }
            }
            if (!empty($record['id'])) {
                // Check if prescription_items table has total_quantity column
                $has_total_quantity = false;
                try {
                    $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'total_quantity'");
                    $has_total_quantity = $test_stmt->rowCount() > 0;
                } catch (PDOException $e) {}
                
                // Check if expiration_date column exists
                $has_expiration = false;
                try {
                    $test_stmt = $pdo->query("SHOW COLUMNS FROM prescriptions LIKE 'expiration_date'");
                    $has_expiration = $test_stmt->rowCount() > 0;
                } catch (PDOException $e) {}
                
                // Get prescription expiration info first
                $prescription_expiration = null;
                $prescription_expired = false;
                if ($has_expiration) {
                    $exp_stmt = $pdo->prepare('
                        SELECT expiration_date, validity_period_days, date_issued
                        FROM prescriptions
                        WHERE appointment_id = ?
                        ORDER BY created_at DESC
                        LIMIT 1
                    ');
                    $exp_stmt->execute([$record['id']]);
                    $exp_data = $exp_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($exp_data && !empty($exp_data['expiration_date'])) {
                        $prescription_expiration = $exp_data['expiration_date'];
                        $today = date('Y-m-d');
                        $prescription_expired = strtotime($prescription_expiration) < strtotime($today);
                        $record['prescription_expiration_date'] = $prescription_expiration;
                        $record['prescription_expired'] = $prescription_expired;
                        $record['prescription_validity_days'] = $exp_data['validity_period_days'] ?? null;
                    }
                }
                
                // Check if prescription_items has medicine_form column
                $has_medicine_form = false;
                try {
                    $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'medicine_form'");
                    $has_medicine_form = $test_stmt->rowCount() > 0;
                } catch (PDOException $e) {}
                $med_name_expr = $has_medicine_form
                    ? "CONCAT(pi.medicine_name, IF(COALESCE(TRIM(pi.medicine_form),'')='','', CONCAT(' (', pi.medicine_form, ')')), '')"
                    : "pi.medicine_name";
                // Try prescription_items first (preferred, has total_quantity)
                $presc_stmt = $pdo->prepare("
                    SELECT GROUP_CONCAT(
                        CONCAT(
                            {$med_name_expr},
                            \" - \", COALESCE(pi.dosage, \"\"),
                            \" - \", COALESCE(pi.frequency, \"\"),
                            \" - \", COALESCE(pi.duration, \"\"),
                            CASE
                                WHEN pi.total_quantity > 0 THEN CONCAT(\" (Total Qty: \", pi.total_quantity, \")\")
                                ELSE \"\"
                            END
                        ) SEPARATOR \", \"
                    ) as meds
                    FROM prescriptions p
                    LEFT JOIN prescription_items pi ON pi.prescription_id = p.id
                    WHERE p.appointment_id = ?
                ");
                $presc_stmt->execute([$record['id']]);
                $presc = $presc_stmt->fetch(PDO::FETCH_ASSOC);
                
                // If no results from prescription_items, try medications table
                if (empty($presc['meds'])) {
                    $presc_stmt = $pdo->prepare('
                        SELECT GROUP_CONCAT(CONCAT(m.drug_name, " - ", COALESCE(m.dosage, ""), " - ", COALESCE(m.frequency, "")) SEPARATOR ", ") as meds
                        FROM prescriptions p
                        LEFT JOIN medications m ON m.prescription_id = p.id
                        WHERE p.appointment_id = ?
                    ');
                    $presc_stmt->execute([$record['id']]);
                    $presc = $presc_stmt->fetch(PDO::FETCH_ASSOC);
                }
                
                if ($presc && !empty($presc['meds'])) {
                    $record['prescription'] = $presc['meds'];
                }
            }
            
            // Build diagnosis from consultation (preferred) or appointment diagnosis
            $diagnosis_parts = [];
            if (!empty($record['consultation_diagnosis'])) {
                $diagnosis_parts[] = $record['consultation_diagnosis'];
            } elseif (!empty($record['appointment_diagnosis'])) {
                $diagnosis_parts[] = $record['appointment_diagnosis'];
            }
            
            // Add consultation notes if available
            if (!empty($record['consultation_notes'])) {
                $diagnosis_parts[] = "Notes: " . $record['consultation_notes'];
            }
            
            $record['diagnosis'] = !empty($diagnosis_parts) ? implode(". ", $diagnosis_parts) : 'General Check-up';
        }
        unset($record);
    } else {
        // Not a dependent, treat as self (registered patient)
        $selected_patient_id = 0;
    }
}

// Get user's photo_path for header avatar
$stmt = $pdo->prepare('SELECT photo_path FROM users WHERE id = ?');
$stmt->execute([$user_id]);
$user_photo_data = $stmt->fetch(PDO::FETCH_ASSOC);
$user_photo_path = $user_photo_data['photo_path'] ?? null;

// If no dependent selected, get account owner's data from users table
if (!$is_dependent) {
    $stmt = $pdo->prepare('
        SELECT 
            u.id,
            u.username,
            u.email,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.suffix,
            u.contact_no,
            u.address,
            u.role,
            u.created_at,
            u.photo_path,
            pp.date_of_birth,
            pp.allergies,
            pp.medical_history
        FROM users u
        LEFT JOIN patient_profiles pp ON pp.patient_id = u.id
        WHERE u.id = ?
        LIMIT 1
    ');
    $stmt->execute([$user_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get medical history (appointments with diagnoses/prescriptions) for registered patient
    // One row per appointment (no join to doctor_consultations to avoid duplicate visits)
    $stmt = $pdo->prepare('
        SELECT a.*, 
               CONCAT(COALESCE(du.first_name, ""), " ", COALESCE(du.middle_name, ""), " ", COALESCE(du.last_name, "")) as doctor_name,
               a.start_datetime as visit_date,
               a.status,
               a.diagnosis as appointment_diagnosis,
               a.patient_instructions,
               "" as prescription 
        FROM appointments a 
        LEFT JOIN doctors d ON d.id = a.doctor_id 
        LEFT JOIN users du ON du.id = d.user_id
        WHERE (a.user_id = ? OR a.patient_id IN (SELECT id FROM patients WHERE created_by_user_id = ?))
          AND a.status IN ("approved", "completed")
        ORDER BY a.start_datetime DESC
    ');
    $stmt->execute([$user_id, $user_id]);
    $medical_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get lab requests grouped by appointment_id (prefer lab_requests + lab_request_tests)
    $lab_test_requests_by_appointment = [];
    try {
        $appointment_ids = array_column($medical_history, 'id');
        if (!empty($appointment_ids)) {
            $placeholders = implode(',', array_fill(0, count($appointment_ids), '?'));
            $table_lr = $pdo->query("SHOW TABLES LIKE 'lab_requests'");
            if ($table_lr->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    SELECT lr.*,
                           CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
                    FROM lab_requests lr
                    LEFT JOIN doctors d ON d.id = lr.doctor_id
                    LEFT JOIN users du ON du.id = d.user_id
                    WHERE lr.appointment_id IN ($placeholders)
                    ORDER BY lr.created_at DESC
                ");
                $stmt->execute($appointment_ids);
                $all_lr = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($all_lr)) {
                    $lr_ids = array_column($all_lr, 'id');
                    $ph = implode(',', array_fill(0, count($lr_ids), '?'));
                    $stmt = $pdo->prepare("SELECT lab_request_id, test_name FROM lab_request_tests WHERE lab_request_id IN ($ph) ORDER BY id");
                    $stmt->execute($lr_ids);
                    $tests_by_lr = [];
                    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $tests_by_lr[$r['lab_request_id']][] = $r['test_name'];
                    }
                    foreach ($all_lr as $lr) {
                        $lr['tests'] = $tests_by_lr[$lr['id']] ?? [];
                        $lr['test_name'] = implode(', ', $lr['tests']);
                        $lr['is_lab_request'] = true;
                        $appt_id = $lr['appointment_id'];
                        if (!isset($lab_test_requests_by_appointment[$appt_id])) {
                            $lab_test_requests_by_appointment[$appt_id] = [];
                        }
                        $lab_test_requests_by_appointment[$appt_id][] = $lr;
                    }
                    // Deduplicate per appointment (same appointment + created_at + tests = one entry)
                    foreach ($lab_test_requests_by_appointment as $appt_id => $list) {
                        $seen = [];
                        $lab_test_requests_by_appointment[$appt_id] = array_values(array_filter($list, function ($req) use (&$seen) {
                            $t = $req['tests'] ?? [];
                            if (is_array($t)) { sort($t); $k = ($req['created_at'] ?? '') . '|' . implode('|', $t); }
                            else { $k = ($req['created_at'] ?? '') . '|' . ($req['test_name'] ?? ''); }
                            if (isset($seen[$k])) return false;
                            $seen[$k] = true;
                            return true;
                        }));
                    }
                }
            }
            if (empty($lab_test_requests_by_appointment)) {
                $table_check = $pdo->query("SHOW TABLES LIKE 'lab_test_requests'");
                if ($table_check->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        SELECT ltr.*,
                               CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
                        FROM lab_test_requests ltr
                        LEFT JOIN doctors d ON d.id = ltr.doctor_id
                        LEFT JOIN users du ON du.id = d.user_id
                        WHERE ltr.appointment_id IN ($placeholders)
                        ORDER BY ltr.created_at DESC
                    ");
                    $stmt->execute($appointment_ids);
                    $all_lab_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($all_lab_requests as $ltr) {
                        $ltr['tests'] = [$ltr['test_name']];
                        $appt_id = $ltr['appointment_id'];
                        if (!isset($lab_test_requests_by_appointment[$appt_id])) {
                            $lab_test_requests_by_appointment[$appt_id] = [];
                        }
                        $lab_test_requests_by_appointment[$appt_id][] = $ltr;
                    }
                    foreach ($lab_test_requests_by_appointment as $appt_id => $list) {
                        $seen = [];
                        $lab_test_requests_by_appointment[$appt_id] = array_values(array_filter($list, function ($req) use (&$seen) {
                            $t = $req['tests'] ?? [];
                            if (is_array($t)) { sort($t); $k = ($req['created_at'] ?? '') . '|' . implode('|', $t); }
                            else { $k = ($req['created_at'] ?? '') . '|' . ($req['test_name'] ?? ''); }
                            if (isset($seen[$k])) return false;
                            $seen[$k] = true;
                            return true;
                        }));
                    }
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Lab test requests by appointment error: " . $e->getMessage());
    }
}

// Get lab test requests for this patient (prefer lab_requests + lab_request_tests)
$lab_test_requests = [];
$lab_test_results = [];
try {
    $lab_patient_id = $is_dependent ? $selected_patient_id : $user_id;
    $table_lr = $pdo->query("SHOW TABLES LIKE 'lab_requests'");
    if ($table_lr->rowCount() > 0) {
        // Main patient: fetch by user_id OR by patient record id (patients where created_by_user_id = user_id) so we don't miss requests
        if ($is_dependent) {
            $stmt = $pdo->prepare("
                SELECT lr.*,
                       CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
                FROM lab_requests lr
                LEFT JOIN doctors d ON d.id = lr.doctor_id
                LEFT JOIN users du ON du.id = d.user_id
                WHERE lr.patient_id = ?
                ORDER BY lr.created_at DESC
            ");
            $stmt->execute([$lab_patient_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT lr.*,
                       CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
                FROM lab_requests lr
                LEFT JOIN doctors d ON d.id = lr.doctor_id
                LEFT JOIN users du ON du.id = d.user_id
                WHERE lr.patient_id = ? OR lr.patient_id IN (SELECT id FROM patients WHERE created_by_user_id = ?)
                ORDER BY lr.created_at DESC
            ");
            $stmt->execute([$user_id, $user_id]);
        }
        $lab_requests_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($lab_requests_rows)) {
            $request_ids = array_column($lab_requests_rows, 'id');
            $placeholders = implode(',', array_fill(0, count($request_ids), '?'));
            $stmt = $pdo->prepare("SELECT lab_request_id, test_name FROM lab_request_tests WHERE lab_request_id IN ($placeholders) ORDER BY id");
            $stmt->execute($request_ids);
            $tests_by_request = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $tests_by_request[$row['lab_request_id']][] = $row['test_name'];
            }
            foreach ($lab_requests_rows as $lr) {
                $lr['tests'] = $tests_by_request[$lr['id']] ?? [];
                $lr['test_name'] = implode(', ', $lr['tests']);
                $lr['is_lab_request'] = true;
                $lab_test_requests[] = $lr;
            }
            $stmt = $pdo->prepare("
                SELECT ltr.*,
                       CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as uploaded_by_name
                FROM lab_test_results ltr
                LEFT JOIN users u ON u.id = ltr.uploaded_by
                WHERE ltr.lab_request_id IN ($placeholders)
                ORDER BY ltr.uploaded_at DESC
            ");
            $stmt->execute($request_ids);
            $lab_test_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    if (empty($lab_test_requests)) {
        $table_check = $pdo->query("SHOW TABLES LIKE 'lab_test_requests'");
        if ($table_check->rowCount() > 0) {
            if ($is_dependent) {
                $stmt = $pdo->prepare("
                    SELECT ltr.*,
                           CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
                    FROM lab_test_requests ltr
                    LEFT JOIN doctors d ON d.id = ltr.doctor_id
                    LEFT JOIN users du ON du.id = d.user_id
                    WHERE ltr.patient_id = ?
                    ORDER BY ltr.created_at DESC
                ");
                $stmt->execute([$lab_patient_id]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT ltr.*,
                           CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
                    FROM lab_test_requests ltr
                    LEFT JOIN doctors d ON d.id = ltr.doctor_id
                    LEFT JOIN users du ON du.id = d.user_id
                    WHERE ltr.patient_id = ? OR ltr.patient_id IN (SELECT id FROM patients WHERE created_by_user_id = ?)
                    ORDER BY ltr.created_at DESC
                ");
                $stmt->execute([$user_id, $user_id]);
            }
            $lab_test_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($lab_test_requests as &$r) {
                $r['tests'] = [$r['test_name']];
            }
            unset($r);
            if (!empty($lab_test_requests)) {
                $request_ids = array_column($lab_test_requests, 'id');
                $placeholders = implode(',', array_fill(0, count($request_ids), '?'));
                $stmt = $pdo->prepare("
                    SELECT ltr.*,
                           CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as uploaded_by_name
                    FROM lab_test_results ltr
                    LEFT JOIN users u ON u.id = ltr.uploaded_by
                    WHERE ltr.lab_test_request_id IN ($placeholders)
                    ORDER BY ltr.uploaded_at DESC
                ");
                $stmt->execute($request_ids);
                $lab_test_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
} catch (PDOException $e) {
    error_log("Lab test data fetch error: " . $e->getMessage());
    $lab_test_requests = [];
    $lab_test_results = [];
}

// Deduplicate lab test requests so the same request/document appears only once in My Record
if (!empty($lab_test_requests)) {
    // First drop exact duplicate rows (same id)
    $by_id = [];
    foreach ($lab_test_requests as $req) {
        $id = isset($req['id']) ? (int)$req['id'] : 'n' . count($by_id);
        if (!isset($by_id[$id])) {
            $by_id[$id] = $req;
        }
    }
    $lab_test_requests = array_values($by_id);
    // Then drop logical duplicates: same appointment + same set of tests = one entry
    $seen_keys = [];
    $lab_test_requests = array_values(array_filter($lab_test_requests, function ($req) use (&$seen_keys) {
        $tests = $req['tests'] ?? [];
        if (is_array($tests) && !empty($tests)) {
            sort($tests);
            $tests_str = implode('|', array_map('trim', $tests));
        } else {
            $tests_str = trim((string)($req['test_name'] ?? ''));
        }
        $appt = $req['appointment_id'] ?? '';
        $key = $appt . '|' . $tests_str;
        if (isset($seen_keys[$key])) {
            return false;
        }
        $seen_keys[$key] = true;
        return true;
    }));
}

// Get lab test requests grouped by appointment_id (only rebuild from $lab_test_requests when we don't already have it from the appointment-based query above, so main patient keeps correct map)
if (empty($lab_test_requests_by_appointment)) {
    $lab_requests_without_appointment = [];
    if (!empty($lab_test_requests)) {
        foreach ($lab_test_requests as $ltr) {
            $appt_id = $ltr['appointment_id'] ?? null;
            if ($appt_id) {
                if (!isset($lab_test_requests_by_appointment[$appt_id])) {
                    $lab_test_requests_by_appointment[$appt_id] = [];
                }
                $lab_test_requests_by_appointment[$appt_id][] = $ltr;
            } else {
                $lab_requests_without_appointment[] = $ltr;
            }
        }
    }
    // Fallback: attach lab requests with no appointment_id to the most recent visit so they still show
    if (!empty($lab_requests_without_appointment) && !empty($medical_history)) {
        $most_recent_id = $medical_history[0]['id'] ?? null;
        if ($most_recent_id) {
            if (!isset($lab_test_requests_by_appointment[$most_recent_id])) {
                $lab_test_requests_by_appointment[$most_recent_id] = [];
            }
            foreach ($lab_requests_without_appointment as $lr) {
                $lab_test_requests_by_appointment[$most_recent_id][] = $lr;
            }
        }
    }
}

// Get prescriptions and consultation (diagnosis/notes) for each appointment
foreach ($medical_history as &$record) {
    // Fetch latest consultation for this appointment (one per visit)
    if (!empty($record['id'])) {
        $dc_stmt = $pdo->prepare('
            SELECT diagnosis as consultation_diagnosis, notes as consultation_notes
            FROM doctor_consultations
            WHERE appointment_id = ?
            ORDER BY id DESC
            LIMIT 1
        ');
        $dc_stmt->execute([$record['id']]);
        $dc = $dc_stmt->fetch(PDO::FETCH_ASSOC);
        if ($dc) {
            $record['consultation_diagnosis'] = $dc['consultation_diagnosis'] ?? null;
            $record['consultation_notes'] = $dc['consultation_notes'] ?? null;
        } else {
            $record['consultation_diagnosis'] = null;
            $record['consultation_notes'] = null;
        }
    }
    if (!empty($record['id'])) {
        // Add lab test requests for this appointment
        $record['lab_test_requests'] = $lab_test_requests_by_appointment[$record['id']] ?? [];
        // Check if prescription_items table has total_quantity column
        $has_total_quantity = false;
        try {
            $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'total_quantity'");
            $has_total_quantity = $test_stmt->rowCount() > 0;
        } catch (PDOException $e) {}
        
        // Check if expiration_date column exists
        $has_expiration = false;
        try {
            $test_stmt = $pdo->query("SHOW COLUMNS FROM prescriptions LIKE 'expiration_date'");
            $has_expiration = $test_stmt->rowCount() > 0;
        } catch (PDOException $e) {}
        
        // Get prescription expiration info first
        $prescription_expiration = null;
        $prescription_expired = false;
        if ($has_expiration) {
            $exp_stmt = $pdo->prepare('
                SELECT expiration_date, validity_period_days, date_issued
                FROM prescriptions
                WHERE appointment_id = ?
                ORDER BY created_at DESC
                LIMIT 1
            ');
            $exp_stmt->execute([$record['id']]);
            $exp_data = $exp_stmt->fetch(PDO::FETCH_ASSOC);
            if ($exp_data && !empty($exp_data['expiration_date'])) {
                $prescription_expiration = $exp_data['expiration_date'];
                $today = date('Y-m-d');
                $prescription_expired = strtotime($prescription_expiration) < strtotime($today);
                $record['prescription_expiration_date'] = $prescription_expiration;
                $record['prescription_expired'] = $prescription_expired;
                $record['prescription_validity_days'] = $exp_data['validity_period_days'] ?? null;
            }
        }
        
        // Check if prescription_items has medicine_form column
        $has_medicine_form = false;
        try {
            $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'medicine_form'");
            $has_medicine_form = $test_stmt->rowCount() > 0;
        } catch (PDOException $e) {}
        $med_name_expr = $has_medicine_form
            ? "CONCAT(pi.medicine_name, IF(COALESCE(TRIM(pi.medicine_form),'')='','', CONCAT(' (', pi.medicine_form, ')')), '')"
            : "pi.medicine_name";
        // Try prescription_items first (preferred, has total_quantity)
        $presc_stmt = $pdo->prepare("
            SELECT GROUP_CONCAT(
                CONCAT(
                    {$med_name_expr},
                    \" - \", COALESCE(pi.dosage, \"\"),
                    \" - \", COALESCE(pi.frequency, \"\"),
                    \" - \", COALESCE(pi.duration, \"\"),
                    CASE
                        WHEN pi.total_quantity > 0 THEN CONCAT(\" (Total Qty: \", pi.total_quantity, \")\")
                        ELSE \"\"
                    END
                ) SEPARATOR \", \"
            ) as meds
            FROM prescriptions p
            LEFT JOIN prescription_items pi ON pi.prescription_id = p.id
            WHERE p.appointment_id = ?
        ");
        $presc_stmt->execute([$record['id']]);
        $presc = $presc_stmt->fetch(PDO::FETCH_ASSOC);
        
        // If no results from prescription_items, try medications table
        if (empty($presc['meds'])) {
            $presc_stmt = $pdo->prepare('
                SELECT GROUP_CONCAT(CONCAT(m.drug_name, " - ", COALESCE(m.dosage, ""), " - ", COALESCE(m.frequency, "")) SEPARATOR ", ") as meds
                FROM prescriptions p
                LEFT JOIN medications m ON m.prescription_id = p.id
                WHERE p.appointment_id = ?
            ');
            $presc_stmt->execute([$record['id']]);
            $presc = $presc_stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if ($presc && !empty($presc['meds'])) {
            $record['prescription'] = $presc['meds'];
        }
    }
    
    // Build diagnosis from consultation (preferred) or appointment diagnosis
    $diagnosis_parts = [];
    if (!empty($record['consultation_diagnosis'])) {
        $diagnosis_parts[] = $record['consultation_diagnosis'];
    } elseif (!empty($record['appointment_diagnosis'])) {
        $diagnosis_parts[] = $record['appointment_diagnosis'];
    }
    
    // Add consultation notes if available
    if (!empty($record['consultation_notes'])) {
        $diagnosis_parts[] = "Notes: " . $record['consultation_notes'];
    }
    
    $record['diagnosis'] = !empty($diagnosis_parts) ? implode(". ", $diagnosis_parts) : 'General Check-up';
}
unset($record);

// Get medical certificates for the patient
$medical_certificates = [];
$certificates_by_appointment = [];
try {
    $check_table = $pdo->query("SHOW TABLES LIKE 'medical_certificates'");
    if ($check_table->rowCount() > 0) {
        // Determine patient_id to use for query
        $cert_patient_id = $is_dependent ? $selected_patient_id : $user_id;
        
        // Also check if patient_id exists in patients table (for dependents)
        $stmt = $pdo->prepare("
            SELECT 
                mc.*,
                CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name,
                d.specialization as doctor_specialization
            FROM medical_certificates mc
            LEFT JOIN doctors d ON mc.doctor_id = d.id
            LEFT JOIN users du ON d.user_id = du.id
            WHERE mc.patient_id = ?
            ORDER BY mc.created_at DESC
        ");
        $stmt->execute([$cert_patient_id]);
        $medical_certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Map certificates by appointment_id
        foreach ($medical_certificates as $cert) {
            if (!empty($cert['appointment_id'])) {
                if (!isset($certificates_by_appointment[$cert['appointment_id']])) {
                    $certificates_by_appointment[$cert['appointment_id']] = [];
                }
                $certificates_by_appointment[$cert['appointment_id']][] = $cert;
            }
        }
        
        // Filter out expired certificates from patient view (but keep them in the list for reference)
        // We'll mark them as expired in the display
    }
} catch (PDOException $e) {
    // Table doesn't exist yet, certificates array will be empty
}

// Get referrals for the patient
$referrals = [];
$referrals_by_appointment = [];
try {
    $check_table = $pdo->query("SHOW TABLES LIKE 'referrals'");
    if ($check_table->rowCount() > 0) {
        // Determine patient_id to use for query
        $referral_patient_id = $is_dependent ? $selected_patient_id : $user_id;
        
        $stmt = $pdo->prepare("
            SELECT 
                r.*,
                CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name,
                d.specialization as doctor_specialization
            FROM referrals r
            LEFT JOIN doctors d ON r.doctor_id = d.id
            LEFT JOIN users du ON d.user_id = du.id
            WHERE r.patient_id = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$referral_patient_id]);
        $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Map referrals by appointment_id
        foreach ($referrals as $ref) {
            if (!empty($ref['appointment_id'])) {
                if (!isset($referrals_by_appointment[$ref['appointment_id']])) {
                    $referrals_by_appointment[$ref['appointment_id']] = [];
                }
                $referrals_by_appointment[$ref['appointment_id']][] = $ref;
            }
        }
    }
} catch (PDOException $e) {
    // Table doesn't exist or error occurred, ignore
}

// Get approved follow-up appointments only (for medical history display)
// All other follow-up actions are handled on the Appointments page
$approved_followups = [];
try {
    $check_table = $pdo->query("SHOW TABLES LIKE 'follow_up_appointments'");
    if ($check_table->rowCount() > 0) {
        if ($is_dependent && $selected_patient_id > 0) {
            // For dependents, use patient_id
            $stmt = $pdo->prepare("
                SELECT 
                    f.*,
                    a.start_datetime as original_appointment_date,
                    CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
                FROM follow_up_appointments f
                LEFT JOIN appointments a ON f.original_appointment_id = a.id
                LEFT JOIN doctors d ON f.doctor_id = d.id
                LEFT JOIN users du ON d.user_id = du.id
                WHERE f.patient_id = ?
                AND f.status = 'approved'
                ORDER BY COALESCE(f.selected_datetime, f.proposed_datetime) ASC
            ");
            $stmt->execute([$selected_patient_id]);
            $approved_followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // For registered patients, use user_id
            $stmt = $pdo->prepare("
                SELECT 
                    f.*,
                    a.start_datetime as original_appointment_date,
                    CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
                FROM follow_up_appointments f
                LEFT JOIN appointments a ON f.original_appointment_id = a.id
                LEFT JOIN doctors d ON f.doctor_id = d.id
                LEFT JOIN users du ON d.user_id = du.id
                WHERE f.user_id = ?
                AND f.status = 'approved'
                ORDER BY COALESCE(f.selected_datetime, f.proposed_datetime) ASC
            ");
            $stmt->execute([$user_id]);
            $approved_followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Exception $e) {
    $approved_followups = [];
}

// Deduplicate visits: one card per (visit date + doctor), merge data from multiple appointments
$visit_groups = [];
foreach ($medical_history as $record) {
    $visit_date = $record['visit_date'] ?? null;
    $doctor_id = (int)($record['doctor_id'] ?? 0);
    $visit_key = $visit_date ? (date('Y-m-d', strtotime($visit_date)) . '_' . $doctor_id) : ('id_' . $record['id']);
    if (!isset($visit_groups[$visit_key])) {
        $visit_groups[$visit_key] = [];
    }
    $visit_groups[$visit_key][] = $record;
}
$medical_history = [];
foreach ($visit_groups as $group) {
    $master = $group[0];
    if (count($group) > 1) {
        $prescription_parts = array_filter(array_unique(array_column($group, 'prescription')), function ($p) {
            return $p !== '' && $p !== null && stripos((string)$p, 'No prescription given') !== 0;
        });
        $master['prescription'] = !empty($prescription_parts) ? implode('; ', $prescription_parts) : ($master['prescription'] ?? '');
        $diagnoses = array_filter(array_unique(array_column($group, 'diagnosis')));
        $master['diagnosis'] = !empty($diagnoses) ? implode('. ', $diagnoses) : ($master['diagnosis'] ?? 'General Check-up');
        $all_lab = [];
        foreach ($group as $r) {
            if (!empty($r['lab_test_requests'])) {
                $all_lab = array_merge($all_lab, $r['lab_test_requests']);
            }
        }
        $master['lab_test_requests'] = $all_lab;
        $all_certs = [];
        $all_refs = [];
        foreach ($group as $r) {
            $aid = $r['id'] ?? null;
            if ($aid) {
                $all_certs = array_merge($all_certs, $certificates_by_appointment[$aid] ?? []);
                $all_refs = array_merge($all_refs, $referrals_by_appointment[$aid] ?? []);
            }
        }
        $master['appointment_certificates'] = $all_certs;
        $master['appointment_referrals'] = $all_refs;
    } else {
        $master['appointment_certificates'] = $certificates_by_appointment[$master['id']] ?? [];
        $master['appointment_referrals'] = $referrals_by_appointment[$master['id']] ?? [];
    }
    $medical_history[] = $master;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Record - HealthServe Payatas B</title>
    <link rel="stylesheet" href="assets/style.css">
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
        .notification-icon-wrapper.announcement{background:rgba(255,193,7,.1);color:#FFC107}
        .notification-content{flex:1}
        .notification-text{font-size:14px;color:#333;margin-bottom:4px;line-height:1.4}
        .notification-time{font-size:12px;color:#888}
        .notification-dot{width:8px;height:8px;background:#4CAF50;border-radius:50%;margin-left:auto;flex-shrink:0}
        .notification-item.read .notification-dot{display:none}
        .notification-overlay{display:none}
        @media (max-width:768px){.notification-dropdown{width:300px;right:-20px}}
        
        /* Center page title */
        .page-title {
            text-align: center;
        }
    </style>
    <style>
        .patient-header {
            display: flex;
            align-items: center;
            gap: 30px;
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .patient-avatar {
            flex-shrink: 0;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4CAF50, #81C784);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 36px;
            font-weight: bold;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            position: relative;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        
        .patient-info {
            flex: 1;
        }
        
        .patient-name {
            font-size: 28px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 20px;
        }
        
        .patient-details {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .patient-detail {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .patient-detail-label {
            font-size: 12px;
            text-transform: uppercase;
            font-weight: 600;
            color: #999;
            letter-spacing: 0.5px;
        }
        
        .patient-detail-value {
            font-size: 16px;
            font-weight: 500;
            color: #2c3e50;
        }
        
        .medical-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.05);
        }
        
        .medical-table th {
            background: linear-gradient(135deg, #4CAF50, #66BB6A);
            color: white;
            padding: 16px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
        }
        
        .medical-table td {
            padding: 16px;
            border-bottom: 1px solid #f0f0f0;
            color: #2c3e50;
        }
        
        .medical-table tr:hover {
            background: rgba(76, 175, 80, 0.05);
        }
        
        .medical-table tr:last-child td {
            border-bottom: none;
        }
        
        .content-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .dependent-selector {
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
            border: 2px solid #e0e0e0;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        
        .dependent-selector .selector-left {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
        }
        
        .dependent-selector label {
            font-size: 14px;
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
        }
        
        .dependent-selector select {
            min-width: 220px;
            max-width: 400px;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            background: white;
            cursor: pointer;
            transition: border-color 0.3s ease;
        }
        
        .dependent-selector select:hover {
            border-color: #4CAF50;
        }
        
        .dependent-selector select:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }
        
        .btn-add-dependent {
            padding: 10px 20px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            white-space: nowrap;
        }
        
        .btn-add-dependent:hover {
            background: #43a047;
        }
        
        .btn-add-dependent:active {
            transform: scale(0.98);
        }
        
        /* Add Dependent modal — single card, no body scroll lock */
        .add-dependent-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 9998;
            align-items: center;
            justify-content: center;
            padding: 24px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        .add-dependent-overlay.show {
            display: flex;
        }
        .add-dependent-modal {
            background: #fff;
            border-radius: 8px;
            max-width: 920px;
            width: 100%;
            min-width: 0;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.12);
            margin: auto;
            flex-shrink: 0;
        }
        .add-dependent-modal .modal-header {
            padding: 16px 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .add-dependent-modal .modal-header h2 {
            margin: 0;
            font-size: 1.125rem;
            font-weight: 600;
            color: #111827;
        }
        .add-dependent-modal .modal-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: #6b7280;
            padding: 4px;
            line-height: 1;
        }
        .add-dependent-modal .modal-close:hover {
            color: #111827;
        }
        .add-dependent-modal .modal-body {
            padding: 28px 32px;
            overflow-x: hidden;
            overflow-y: auto;
            min-width: 0;
        }
        .add-dependent-modal .form-section-title {
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
            margin-bottom: 12px;
        }
        .add-dependent-modal .form-section-title:not(:first-child) {
            margin-top: 20px;
        }
        .add-dependent-modal .form-row {
            margin-bottom: 0;
        }
        .add-dependent-modal .form-row label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 500;
            color: #374151;
            margin-bottom: 6px;
        }
        .add-dependent-modal .form-row input,
        .add-dependent-modal .form-row select,
        .add-dependent-modal .form-row textarea {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            height: 40px;
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 0.875rem;
            box-sizing: border-box;
            font-family: inherit;
            color: #111827;
        }
        .add-dependent-modal .form-row input::placeholder,
        .add-dependent-modal .form-row textarea::placeholder {
            color: #9ca3af;
        }
        .add-dependent-modal .form-row input:focus,
        .add-dependent-modal .form-row select:focus,
        .add-dependent-modal .form-row textarea:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 1px #4CAF50;
        }
        .add-dependent-modal .form-row {
            min-width: 0;
        }
        /* Single grid system: uniform spacing, no fixed widths. Desktop 3 cols, tablet 2, mobile 1 */
        .add-dependent-modal .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px 20px;
        }
        .add-dependent-modal .form-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px 20px;
        }
        .add-dependent-modal .form-grid .form-row,
        .add-dependent-modal .form-grid-3 .form-row {
            margin-bottom: 0;
            min-width: 0;
        }
        .add-dependent-modal .form-grid .form-row label,
        .add-dependent-modal .form-grid-3 .form-row label {
            overflow: visible;
            white-space: normal;
        }
        .add-dependent-modal .form-row-spaced {
            margin-top: 20px;
        }
        .add-dependent-modal .form-row-full {
            grid-column: 1 / -1;
        }
        /* Medical conditions: dropdown multi-select (tags + checkbox dropdown) */
        .add-dependent-modal .medical-select-wrap {
            position: relative;
            width: 100%;
        }
        .add-dependent-modal .medical-select-trigger {
            min-height: 40px;
            padding: 6px 12px 6px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: #fff;
            cursor: pointer;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            font-size: 0.875rem;
            color: #111827;
            box-sizing: border-box;
        }
        .add-dependent-modal .medical-select-trigger:hover {
            border-color: #d1d5db;
        }
        .add-dependent-modal .medical-select-trigger.open {
            border-color: #4CAF50;
            box-shadow: 0 0 0 1px #4CAF50;
        }
        .add-dependent-modal .medical-select-placeholder {
            color: #9ca3af;
            padding: 2px 0;
        }
        .add-dependent-modal .medical-select-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }
        .add-dependent-modal .medical-select-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            background: #e5e7eb;
            border-radius: 4px;
            font-size: 0.8125rem;
            color: #374151;
        }
        .add-dependent-modal .medical-select-tag-remove {
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            color: #6b7280;
            font-size: 1rem;
            line-height: 1;
        }
        .add-dependent-modal .medical-select-tag-remove:hover {
            color: #111827;
        }
        .add-dependent-modal .medical-select-dropdown {
            display: none;
            position: absolute;
            left: 0;
            right: 0;
            top: 100%;
            margin-top: 4px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            z-index: 100;
            max-height: 240px;
            overflow-y: auto;
            padding: 8px 0;
        }
        .add-dependent-modal .medical-select-dropdown.open {
            display: block;
        }
        .add-dependent-modal .medical-select-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 0.875rem;
            color: #374151;
        }
        .add-dependent-modal .medical-select-option:hover {
            background: #f9fafb;
        }
        .add-dependent-modal .medical-select-option input[type="checkbox"] {
            width: 16px;
            height: 16px;
            margin: 0;
            cursor: pointer;
            flex-shrink: 0;
        }
        .add-dependent-modal .medical-select-other-input {
            margin: 8px 12px 0;
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 0.875rem;
            width: calc(100% - 24px);
            box-sizing: border-box;
        }
        .add-dependent-modal .medical-other-wrap {
            margin-top: 10px;
            display: none;
        }
        .add-dependent-modal .medical-other-wrap.visible {
            display: block;
        }
        .add-dependent-modal .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-shrink: 0;
            background: #fafafa;
        }
        .add-dependent-modal .btn-cancel {
            padding: 8px 16px;
            height: 38px;
            background: #fff;
            color: #374151;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
        }
        .add-dependent-modal .btn-cancel:hover {
            background: #f9fafb;
        }
        .add-dependent-modal .btn-save-dependent {
            padding: 8px 20px;
            height: 38px;
            background: #4CAF50;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
        }
        .add-dependent-modal .btn-save-dependent:hover {
            background: #43a047;
        }
        .add-dependent-modal .form-message {
            margin-bottom: 16px;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 0.8125rem;
            display: none;
        }
        .add-dependent-modal .form-message.error {
            display: block;
            background: #fef2f2;
            color: #b91c1c;
        }
        .add-dependent-modal .form-message.success {
            display: block;
            background: #f0fdf4;
            color: #166534;
        }
        /* Tablet: 2 columns for 3-col rows; same uniform gaps */
        @media (max-width: 840px) {
            .add-dependent-modal .form-grid-3 {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 16px 20px;
            }
        }
        @media (max-width: 960px) {
            .add-dependent-modal { max-width: 100%; }
        }
        /* Mobile: 1 column; keep vertical breathing room */
        @media (max-width: 480px) {
            .add-dependent-overlay { padding: 12px; }
            .add-dependent-modal .modal-body { padding: 24px 28px; }
            .add-dependent-modal .form-grid,
            .add-dependent-modal .form-grid-3 {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            .add-dependent-modal .form-grid .form-row,
            .add-dependent-modal .form-grid-3 .form-row {
                margin-bottom: 0;
            }
            .add-dependent-modal .form-row-spaced { margin-top: 20px; }
        }
        
        /* FAQ-Style Category Accordion */
        .category-accordion {
            background: white;
            border: 2px solid #f0f4f8;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s;
            margin-bottom: 20px;
        }
        
        .category-accordion:hover {
            border-color: #4caf50;
        }
        
        .category-accordion.active {
            border-color: #4caf50;
        }
        
        .category-header {
            padding: 1.5rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            color: #333;
            transition: background-color 0.2s ease;
        }
        
        .category-header:hover {
            background: #f9f9f9;
        }
        
        .category-header-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 16px;
        }
        
        .category-toggle {
            font-size: 1.5rem;
            color: #4caf50;
            transition: transform 0.3s;
            font-weight: 300;
        }
        
        .category-accordion.active .category-toggle {
            transform: rotate(45deg);
        }
        
        .category-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            padding: 0 1.5rem;
        }
        
        .category-accordion.active .category-content {
            max-height: 5000px;
            padding: 0 1.5rem 1.5rem;
        }
        
        /* Document List Items */
        .document-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 1rem;
        }
        
        .document-item {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        
        .document-item:hover {
            border-color: #4caf50;
            background: #f0f7f0;
        }
        
        .document-item.expanded {
            border-color: #4caf50;
            background: #f0f7f0;
        }
        
        .document-row {
            display: flex;
            align-items: center;
            padding: 14px 16px;
            cursor: pointer;
        }
        
        .document-chevron {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            margin-right: 10px;
            transition: transform 0.3s ease;
            flex-shrink: 0;
            font-size: 12px;
        }
        
        .document-item.expanded .document-chevron {
            transform: rotate(90deg);
        }
        
        .document-row-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        
        .document-title {
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin: 0;
            flex: 1;
        }
        
        .document-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .document-details {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, padding 0.3s ease;
            padding: 0 16px;
            background-color: white;
        }
        
        .document-item.expanded .document-details {
            max-height: 2000px;
            padding: 16px;
            border-top: 1px solid #e0e0e0;
        }
        
        .document-details-content {
            font-size: 13px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 16px;
        }
        
        .document-details-content strong {
            color: #333;
        }
        
        .document-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .visit-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .btn-view, .btn-download {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 16px;
            font-size: 13px;
            text-decoration: none;
            border-radius: 8px;
            white-space: nowrap;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-weight: 600;
            min-height: 40px;
            box-sizing: border-box;
            overflow: hidden;
            text-overflow: ellipsis;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        
        .btn-view {
            background: linear-gradient(135deg, #4CAF50 0%, #388E3C 100%);
            color: white;
        }
        
        .btn-view:hover {
            background: linear-gradient(135deg, #388E3C 0%, #2E7D32 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.35);
            color: white;
        }
        
        .btn-download {
            background: linear-gradient(135deg, #4CAF50 0%, #388E3C 100%);
            color: white;
        }
        
        .btn-download:hover {
            background: linear-gradient(135deg, #388E3C 0%, #2E7D32 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.35);
            color: white;
        }
        
        .btn-view i, .btn-download i {
            font-size: 13px;
            width: 14px;
            text-align: center;
        }
        
        /* Visit Cards - Accordion Style */
        .visit-card {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.2s ease;
            margin-bottom: 12px;
        }
        
        .visit-card:hover {
            border-color: #4caf50;
            background: #f0f7f0;
        }
        
        .visit-card.expanded {
            border-color: #4caf50;
            background: #f0f7f0;
        }
        
        .visit-card-header {
            display: flex;
            align-items: center;
            padding: 16px;
            cursor: pointer;
            gap: 12px;
        }
        
        .visit-chevron {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            transition: transform 0.3s ease;
            flex-shrink: 0;
            font-size: 12px;
        }
        
        .visit-card.expanded .visit-chevron {
            transform: rotate(90deg);
        }
        
        .visit-card-content {
            flex: 1;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
            align-items: center;
        }
        
        .visit-info-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .visit-info-label {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .visit-info-value {
            font-size: 14px;
            color: #333;
            font-weight: 500;
        }
        
        .visit-details {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, padding 0.3s ease;
            padding: 0 16px;
            background-color: white;
        }
        
        .visit-card.expanded .visit-details {
            max-height: 2000px;
            padding: 20px;
            border-top: 1px solid #e0e0e0;
        }
        
        .visit-details-section {
            margin-bottom: 20px;
        }
        
        .visit-details-section:last-child {
            margin-bottom: 0;
        }
        
        .visit-details-label {
            font-size: 12px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .visit-details-value {
            font-size: 14px;
            color: #333;
            line-height: 1.6;
        }
        
        .visit-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        
        .btn-view-prescription, .btn-view-certificate, .btn-view-referral {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 16px;
            font-size: 13px;
            text-decoration: none;
            border-radius: 8px;
            white-space: nowrap;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-weight: 600;
            min-height: 40px;
            box-sizing: border-box;
            overflow: hidden;
            text-overflow: ellipsis;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        
        .btn-view-prescription {
            background: linear-gradient(135deg, #4CAF50 0%, #388E3C 100%);
            color: white;
        }
        
        .btn-view-prescription:hover {
            background: linear-gradient(135deg, #388E3C 0%, #2E7D32 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.35);
            color: white;
        }
        
        .btn-view-certificate {
            background: linear-gradient(135deg, #4CAF50 0%, #388E3C 100%);
            color: white;
        }
        
        .btn-view-certificate:hover {
            background: linear-gradient(135deg, #388E3C 0%, #2E7D32 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.35);
            color: white;
        }
        
        .btn-view-referral {
            background: linear-gradient(135deg, #4CAF50 0%, #388E3C 100%);
            color: white;
        }
        
        .btn-view-referral:hover {
            background: linear-gradient(135deg, #388E3C 0%, #2E7D32 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.35);
            color: white;
        }
        
        .btn-view-prescription i, .btn-view-certificate i, .btn-view-referral i {
            font-size: 13px;
            width: 14px;
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .visit-card-content {
                grid-template-columns: 1fr;
                gap: 12px;
            }
        }
        
        /* PDF Modal Viewer */
        .pdf-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 10000;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
        }
        
        .pdf-modal.active {
            display: flex;
        }
        
        .pdf-modal-content {
            position: relative;
            width: 90%;
            max-width: 1200px;
            height: 90vh;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .pdf-modal-header {
            padding: 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
        }
        
        .pdf-modal-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin: 0;
        }
        
        .pdf-modal-close {
            background: none;
            border: none;
            font-size: 28px;
            color: #666;
            cursor: pointer;
            padding: 0;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s ease;
        }
        
        .pdf-modal-close:hover {
            background: #e0e0e0;
            color: #333;
        }
        
        .pdf-modal-body {
            flex: 1;
            overflow: hidden;
            position: relative;
        }
        
        .pdf-modal-iframe {
            width: 100%;
            height: 100%;
            border: none;
        }
        
        .pdf-modal-loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #666;
            font-size: 16px;
        }
    </style>
</head>
<body data-residency-verified="<?= $residency_verified ? '1' : '0' ?>">
    <!-- Header -->
    <header class="header">
        <div class="header-logo">
            <img src="assets/payatas logo.png" alt="Payatas B Logo">
            <h1>HealthServe - Payatas B</h1>
        </div>
        <nav class="header-nav">
            <a href="user_main_dashboard.php">Dashboard</a>
            <a href="user_records.php" class="active">My Record</a>
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
                    <div class="notification-filters" id="notificationFilters" style="display: flex; gap: 8px; padding: 8px 16px; border-bottom: 1px solid #f0f0f0;">
                        <button class="filter-btn active" data-filter="active" style="flex: 1; padding: 6px 12px; border: 1px solid #e0e0e0; background: #4CAF50; color: white; border-radius: 4px; cursor: pointer; font-size: 13px;">Active</button>
                        <button class="filter-btn" data-filter="archived" style="flex: 1; padding: 6px 12px; border: 1px solid #e0e0e0; background: white; color: #666; border-radius: 4px; cursor: pointer; font-size: 13px;">Archived</button>
                    </div>
                    <div class="notification-list" id="notificationList"></div>
                </div>
            </div>
            <a href="user_profile.php" title="My Profile" style="text-decoration:none">
                <div class="user-avatar" style="background:#2e7d32; overflow: hidden; position: relative;">
                    <?php if (!empty($user_photo_path) && file_exists($user_photo_path)): ?>
                        <img src="<?php echo htmlspecialchars($user_photo_path); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
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
        <?php if (!$residency_verified): ?>
        <div class="residency-banner" role="alert" style="background: linear-gradient(90deg, #ffcdd2 0%, #ef9a9a 100%); color: #b71c1c; padding: 14px 20px; border-radius: 8px; margin-bottom: 1rem; box-shadow: 0 2px 8px rgba(183, 28, 28, 0.2);">
            <strong>Complete your Payatas residency verification</strong> to view prescriptions, request lab tests, and upload results.
            <a href="user_profile.php#verification" style="color: #8b0000; text-decoration: underline;">Upload your ID in Profile &rarr;</a>
        </div>
        <?php endif; ?>
        <h1 class="page-title">My Record</h1>

        <div class="content-card">
            <!-- Dependent Selector -->
            <div class="dependent-selector">
                <div class="selector-left">
                    <label for="patientSelector">View Record For:</label>
                    <select id="patientSelector" onchange="loadPatientRecord(this.value)">
                        <option value="0" <?php echo ($selected_patient_id == 0 && $selected_dependent_id === null) ? 'selected' : ''; ?>>
                            Myself<?php
                            $myself_name = trim(($patient['first_name'] ?? '') . ' ' . ($patient['middle_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
                            if (!empty($patient['suffix'])) $myself_name .= ' ' . $patient['suffix'];
                            echo ($myself_name !== '') ? ' (' . htmlspecialchars($myself_name) . ')' : '';
                            ?>
                        </option>
                        <?php foreach($dependents as $dep): 
                            $dep_patient_id = $dep['patient_table_id'] ?? null;
                            $dep_name = trim(($dep['p_first_name'] ?? $dep['first_name'] ?? '') . ' ' . ($dep['p_middle_name'] ?? $dep['middle_name'] ?? '') . ' ' . ($dep['p_last_name'] ?? $dep['last_name'] ?? ''));
                            if (empty($dep_name)) {
                                $dep_name = trim(($dep['first_name'] ?? '') . ' ' . ($dep['middle_name'] ?? '') . ' ' . ($dep['last_name'] ?? ''));
                            }
                            // Show all dependents - use patient_id if available, otherwise use dependent_id
                            if ($dep_patient_id):
                                // Dependent has patient record - use patient_id
                                $option_value = $dep_patient_id;
                                $is_selected = ($selected_patient_id > 0 && $dep_patient_id == $selected_patient_id);
                            else:
                                // Dependent doesn't have patient record yet - use dependent_id with prefix
                                $option_value = 'dep_' . $dep['id'];
                                $is_selected = ($selected_dependent_id !== null && $dep['id'] == $selected_dependent_id);
                            endif;
                        ?>
                            <option value="<?php echo htmlspecialchars($option_value); ?>" <?php echo $is_selected ? 'selected' : ''; ?>>
                                (Dependent) <?php echo htmlspecialchars($dep_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="btn-add-dependent" id="btnAddDependent" title="Add a family member or dependent">+ Add Dependent</button>
            </div>
            
            <!-- Add Dependent Modal -->
            <div class="add-dependent-overlay" id="addDependentOverlay" aria-hidden="true">
                <div class="add-dependent-modal" id="addDependentModal" role="dialog" aria-labelledby="addDependentTitle">
                    <div class="modal-header">
                        <h2 id="addDependentTitle">Add Dependent</h2>
                        <button type="button" class="modal-close" id="addDependentClose" aria-label="Close">&times;</button>
                    </div>
                    <form id="addDependentForm">
                        <div class="modal-body">
                            <div class="form-message" id="addDependentMessage"></div>
                            <p class="form-section-title">Personal information</p>
                            <div class="form-grid-3">
                                <div class="form-row">
                                    <label for="dep_first_name">First name <span style="color:#b91c1c;">*</span></label>
                                    <input type="text" id="dep_first_name" name="first_name" required maxlength="120" placeholder="First name">
                                </div>
                                <div class="form-row">
                                    <label for="dep_middle_name">Middle name</label>
                                    <input type="text" id="dep_middle_name" name="middle_name" maxlength="120" placeholder="Middle (optional)">
                                </div>
                                <div class="form-row">
                                    <label for="dep_last_name">Last name <span style="color:#b91c1c;">*</span></label>
                                    <input type="text" id="dep_last_name" name="last_name" required maxlength="120" placeholder="Last name">
                                </div>
                            </div>
                            <div class="form-grid-3 form-row-spaced">
                                <div class="form-row">
                                    <label for="dep_date_of_birth">Birthdate <span style="color:#b91c1c;">*</span></label>
                                    <input type="date" id="dep_date_of_birth" name="date_of_birth" required>
                                </div>
                                <div class="form-row">
                                    <label for="dep_sex">Sex <span style="color:#b91c1c;">*</span></label>
                                    <select id="dep_sex" name="sex" required>
                                        <option value="">Select…</option>
                                        <option value="Prefer not to say">Prefer not to say</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                </div>
                                <div class="form-row">
                                    <label for="dep_relationship">Relationship <span style="color:#b91c1c;">*</span></label>
                                    <select id="dep_relationship" name="relationship" required>
                                        <option value="">Select…</option>
                                        <option value="Son">Son</option>
                                        <option value="Daughter">Daughter</option>
                                        <option value="Spouse">Spouse</option>
                                        <option value="Parent">Parent</option>
                                        <option value="Sibling">Sibling</option>
                                        <option value="Grandparent">Grandparent</option>
                                        <option value="Others">Others</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-grid-3 form-row-spaced form-row-contact-conditions">
                                <div class="form-row">
                                    <label for="dep_contact_no">Contact number</label>
                                    <input type="tel" id="dep_contact_no" name="contact_no" maxlength="11" pattern="\d{11}" inputmode="numeric" placeholder="e.g. 09xxxxxxxxx" title="11 digits only, numbers only">
                                </div>
                                <div class="form-row">
                                    <label for="medical_select_trigger">Conditions</label>
                                    <div class="medical-select-wrap" id="medical_select_wrap">
                                    <div class="medical-select-trigger" id="medical_select_trigger" role="combobox" aria-expanded="false" aria-haspopup="listbox" tabindex="0">
                                        <span class="medical-select-tags" id="medical_select_tags"></span>
                                        <span class="medical-select-placeholder" id="medical_select_placeholder">Select conditions…</span>
                                    </div>
                                    <div class="medical-select-dropdown" id="medical_select_dropdown" role="listbox" aria-multiselectable="true">
                                        <label class="medical-select-option"><input type="checkbox" value="None" data-label="None / No known condition"> None / No known condition</label>
                                        <label class="medical-select-option"><input type="checkbox" value="Asthma" data-label="Asthma"> Asthma</label>
                                        <label class="medical-select-option"><input type="checkbox" value="Hypertension" data-label="Hypertension"> Hypertension</label>
                                        <label class="medical-select-option"><input type="checkbox" value="Diabetes" data-label="Diabetes"> Diabetes</label>
                                        <label class="medical-select-option"><input type="checkbox" value="Heart Condition" data-label="Heart Condition"> Heart Condition</label>
                                        <label class="medical-select-option"><input type="checkbox" value="Allergies" data-label="Allergies"> Allergies</label>
                                        <label class="medical-select-option"><input type="checkbox" value="PWD" data-label="PWD"> PWD</label>
                                        <label class="medical-select-option"><input type="checkbox" value="Senior Citizen" data-label="Senior Citizen"> Senior Citizen</label>
                                        <label class="medical-select-option"><input type="checkbox" value="Pregnant" data-label="Pregnant"> Pregnant</label>
                                        <label class="medical-select-option"><input type="checkbox" value="Other" data-label="Other"> Other</label>
                                        <div class="medical-other-wrap" id="dep_medical_other_wrap">
                                            <input type="text" class="medical-select-other-input" id="dep_medical_other" name="medical_other" maxlength="255" placeholder="Other (please specify)" onclick="event.stopPropagation()">
                                        </div>
                                    </div>
                                    <div id="medical_history_hidden_container"></div>
                                    </div>
                                </div>
                                <div class="form-row"></div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn-cancel" id="addDependentCancel">Cancel</button>
                            <button type="submit" class="btn-save-dependent" id="addDependentSubmit">Save Dependent</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php if($patient && !empty($patient['first_name'])): ?>
            <!-- Patient Header -->
            <div class="patient-header">
                <div class="patient-avatar">
                    <div class="profile-avatar">
                        <?php if (!$is_dependent && !empty($patient['photo_path']) && file_exists($patient['photo_path'])): ?>
                            <img src="<?php echo htmlspecialchars($patient['photo_path']); ?>" alt="Profile Picture">
                        <?php else: ?>
                            <?php echo strtoupper(substr($patient['first_name'] ?? 'R', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="patient-info">
                    <h2 class="patient-name">
                        <?=htmlspecialchars($patient['first_name'] ?? '')?> 
                        <?=htmlspecialchars($patient['middle_name'] ?? '')?> 
                        <?=htmlspecialchars($patient['last_name'] ?? '')?>
                        <?php if (!empty($patient['suffix'])): ?> <?=htmlspecialchars($patient['suffix'])?><?php endif; ?>
                    </h2>
                    <div class="patient-details">
                        <div class="patient-detail">
                            <div class="patient-detail-label">First Name</div>
                            <div class="patient-detail-value"><?=htmlspecialchars($patient['first_name'] ?? 'Not specified')?></div>
                        </div>
                        <div class="patient-detail">
                            <div class="patient-detail-label">Middle Name</div>
                            <div class="patient-detail-value"><?=htmlspecialchars($patient['middle_name'] ?: 'Not specified')?></div>
                        </div>
                        <div class="patient-detail">
                            <div class="patient-detail-label">Last Name</div>
                            <div class="patient-detail-value"><?=htmlspecialchars($patient['last_name'] ?? 'Not specified')?></div>
                        </div>
                        <?php if (!empty($patient['suffix'])): ?>
                        <div class="patient-detail">
                            <div class="patient-detail-label">Suffix</div>
                            <div class="patient-detail-value"><?=htmlspecialchars($patient['suffix'])?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="patient-details">
                        <div class="patient-detail">
                            <div class="patient-detail-label">Birth Date</div>
                            <div class="patient-detail-value">
                                <?=!empty($patient['date_of_birth']) ? date('F j, Y', strtotime($patient['date_of_birth'])) : 'Not specified'?>
                            </div>
                        </div>
                        <div class="patient-detail">
                            <div class="patient-detail-label">Contact Number</div>
                            <div class="patient-detail-value">
                                <?=htmlspecialchars($patient['contact_no'] ?: 'Not specified')?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Medical History Section - Part of patient details -->
                    <?php if(!empty($patient['medical_history'])): ?>
                    <div class="patient-details" style="margin-top: 20px; grid-template-columns: 1fr;">
                        <div class="patient-detail">
                            <div class="patient-detail-label">Medical History</div>
                            <div class="patient-detail-value" style="line-height: 1.6;">
                                <?php 
                                $medicalHistory = $patient['medical_history'];
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
                    
                    <!-- Allergies Section - Part of patient details -->
                    <?php if(!empty($patient['allergies'])): ?>
                    <div class="patient-details" style="margin-top: 20px; grid-template-columns: 1fr;">
                        <div class="patient-detail">
                            <div class="patient-detail-label">Allergies</div>
                            <div class="patient-detail-value" style="line-height: 1.6;">
                                <?=htmlspecialchars($patient['allergies'])?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Health Center Visits Section -->
            <?php if(!empty($medical_history)): ?>
            <div class="profile-card" style="margin-top: 2rem;">
                <div class="category-accordion active" onclick="toggleCategory(this)">
                    <div class="category-header">
                        <div class="category-header-title">
                            <i class="fas fa-calendar-check" style="color: #4CAF50;"></i>
                            <span>Health Center Visits</span>
                            <span style="font-size: 13px; font-weight: 400; color: #666; margin-left: 8px;">
                                (<?= count($medical_history) ?>)
                            </span>
                        </div>
                        <span class="category-toggle">+</span>
                    </div>
                    <div class="category-content">
                        <div style="display: flex; justify-content: flex-end; align-items: center; margin-bottom: 1rem; gap: 10px; flex-wrap: wrap;" onclick="event.stopPropagation();">
                            <label for="visitFilter" style="font-size: 14px; color: #666; font-weight: 500; display: flex; align-items: center; gap: 6px;">
                                <i class="fas fa-filter" style="color: #4CAF50;"></i> Filter:
                            </label>
                            <select id="visitFilter" style="padding: 8px 12px; border: 1px solid #e0e0e0; border-radius: 6px; font-size: 14px; background: white; cursor: pointer; min-width: 150px; transition: all 0.2s ease; color: #333;" onchange="filterVisits()" onfocus="this.style.borderColor='#4CAF50'; this.style.boxShadow='0 0 0 3px rgba(76, 175, 80, 0.1)';" onblur="this.style.borderColor='#e0e0e0'; this.style.boxShadow='none';">
                                <option value="all" selected>All Visits</option>
                                <option value="week">This Week</option>
                                <option value="month">This Month</option>
                                <option value="upcoming">Upcoming</option>
                                <option value="completed">Completed</option>
                            </select>
                            <label for="visitMonthFilter" style="font-size: 14px; color: #666; font-weight: 500; display: flex; align-items: center; gap: 6px;">
                                <i class="fas fa-calendar-alt" style="color: #4CAF50;"></i> Month:
                            </label>
                            <select id="visitMonthFilter" style="padding: 8px 12px; border: 1px solid #e0e0e0; border-radius: 6px; font-size: 14px; background: white; cursor: pointer; min-width: 130px; transition: all 0.2s ease; color: #333;" onchange="filterVisits()" onfocus="this.style.borderColor='#4CAF50'; this.style.boxShadow='0 0 0 3px rgba(76, 175, 80, 0.1)';" onblur="this.style.borderColor='#e0e0e0'; this.style.boxShadow='none';">
                                <option value="all" selected>All Months</option>
                                <option value="1">January</option>
                                <option value="2">February</option>
                                <option value="3">March</option>
                                <option value="4">April</option>
                                <option value="5">May</option>
                                <option value="6">June</option>
                                <option value="7">July</option>
                                <option value="8">August</option>
                                <option value="9">September</option>
                                <option value="10">October</option>
                                <option value="11">November</option>
                                <option value="12">December</option>
                            </select>
                            <label for="visitYearFilter" style="font-size: 14px; color: #666; font-weight: 500; display: flex; align-items: center; gap: 6px;">
                                Year:
                            </label>
                            <select id="visitYearFilter" style="padding: 8px 12px; border: 1px solid #e0e0e0; border-radius: 6px; font-size: 14px; background: white; cursor: pointer; min-width: 100px; transition: all 0.2s ease; color: #333;" onchange="filterVisits()" onfocus="this.style.borderColor='#4CAF50'; this.style.boxShadow='0 0 0 3px rgba(76, 175, 80, 0.1)';" onblur="this.style.borderColor='#e0e0e0'; this.style.boxShadow='none';">
                                <option value="all" selected>All Years</option>
                                <?php
                                // Get unique years from medical history
                                $years = [];
                                foreach($medical_history as $record) {
                                    if (!empty($record['visit_date'])) {
                                        $year = date('Y', strtotime($record['visit_date']));
                                        if (!in_array($year, $years)) {
                                            $years[] = $year;
                                        }
                                    }
                                }
                                rsort($years); // Sort descending (newest first)
                                foreach($years as $year):
                                ?>
                                    <option value="<?= $year ?>"><?= $year ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="document-list" id="visitsList">
                        <?php foreach($medical_history as $record): 
                            $visit_date = !empty($record['visit_date']) ? date('F j, Y', strtotime($record['visit_date'])) : 'N/A';
                            $visit_datetime = !empty($record['visit_date']) ? strtotime($record['visit_date']) : 0;
                            $visit_date_iso = !empty($record['visit_date']) ? date('Y-m-d', strtotime($record['visit_date'])) : '';
                            $diagnosis = htmlspecialchars($record['diagnosis'] ?: 'General Check-up');
                            $doctor_name = htmlspecialchars(trim($record['doctor_name']) ?: 'Dr. TBA');
                            $has_prescription = !empty($record['prescription']) && $record['prescription'] !== 'No prescription given';
                            $card_id = 'visit-' . $record['id'];
                            
                            // Determine if appointment is upcoming or completed
                            $now = time();
                            $is_upcoming = false;
                            if (!empty($record['status']) && $record['status'] === 'approved' && $visit_datetime > $now) {
                                $is_upcoming = true;
                            }
                            $is_completed = !empty($record['status']) && ($record['status'] === 'completed' || ($record['status'] === 'approved' && $visit_datetime <= $now));
                            
                            // Get related certificates and referrals (merged when visit was deduplicated)
                            $appointment_certificates = $record['appointment_certificates'] ?? $certificates_by_appointment[$record['id']] ?? [];
                            $appointment_referrals = $record['appointment_referrals'] ?? $referrals_by_appointment[$record['id']] ?? [];
                        ?>
                            <div class="visit-card" 
                                 data-visit-date="<?= $visit_date_iso ?>"
                                 data-visit-timestamp="<?= $visit_datetime ?>"
                                 data-visit-month="<?= !empty($record['visit_date']) ? date('n', strtotime($record['visit_date'])) : '' ?>"
                                 data-visit-year="<?= !empty($record['visit_date']) ? date('Y', strtotime($record['visit_date'])) : '' ?>"
                                 data-status="<?= $is_upcoming ? 'upcoming' : ($is_completed ? 'completed' : 'other') ?>">
                                <div class="visit-card-header" onclick="event.stopPropagation(); toggleVisitCard('<?= $card_id ?>')">
                                    <div class="visit-chevron">
                                        <i class="fas fa-chevron-right"></i>
                                    </div>
                                    <div class="visit-card-content">
                                        <div class="visit-info-item">
                                            <div class="visit-info-label">Visit Date</div>
                                            <div class="visit-info-value"><?= $visit_date ?></div>
                                        </div>
                                        <div class="visit-info-item">
                                            <div class="visit-info-label">Diagnosis</div>
                                            <div class="visit-info-value"><?= $diagnosis ?></div>
                                        </div>
                                        <div class="visit-info-item">
                                            <div class="visit-info-label">Doctor</div>
                                            <div class="visit-info-value"><?= $doctor_name ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="visit-details" id="<?= $card_id ?>">
                                    <!-- Diagnosis Section -->
                                    <div class="visit-details-section">
                                        <div class="visit-details-label">Diagnosis</div>
                                        <div class="visit-details-value"><?= $diagnosis ?></div>
                                    </div>
                                    
                                    <!-- Prescription Section (only if available) -->
                                    <?php if ($has_prescription): 
                                        $is_expired = !empty($record['prescription_expired']) && $record['prescription_expired'];
                                        $expiration_date = $record['prescription_expiration_date'] ?? null;
                                    ?>
                                        <div class="visit-details-section">
                                            <div class="visit-details-label">Prescription</div>
                                            <div class="visit-details-value">
                                                <?= htmlspecialchars($record['prescription']) ?>
                                                <?php if ($is_expired): ?>
                                                    <div style="margin-top: 8px; padding: 8px; background: #ffebee; border-left: 3px solid #d32f2f; border-radius: 4px;">
                                                        <span style="color: #d32f2f; font-size: 12px; font-weight: 600;">
                                                            <i class="fas fa-exclamation-circle"></i> Expired
                                                        </span>
                                                        <?php if ($expiration_date): ?>
                                                            <div style="color: #999; font-size: 11px; margin-top: 4px;">
                                                                Expired on <?= date('M j, Y', strtotime($expiration_date)) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div style="color: #999; font-size: 11px; font-style: italic; margin-top: 4px;">
                                                            Please contact your doctor for a new prescription.
                                                        </div>
                                                    </div>
                                                <?php elseif ($expiration_date): ?>
                                                    <div style="margin-top: 8px; padding: 8px; background: #e8f5e9; border-left: 3px solid #4CAF50; border-radius: 4px;">
                                                        <span style="color: #4CAF50; font-size: 12px; font-weight: 600;">
                                                            <i class="fas fa-check-circle"></i> Valid until <?= date('M j, Y', strtotime($expiration_date)) ?>
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <div style="margin-top: 8px; padding: 8px; background: #e8f5e9; border-left: 3px solid #4CAF50; border-radius: 4px;">
                                                        <span style="color: #4CAF50; font-size: 12px; font-weight: 600;">
                                                            <i class="fas fa-check-circle"></i> Valid
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Doctor Section -->
                                    <div class="visit-details-section">
                                        <div class="visit-details-label">Doctor(s) that Accommodated</div>
                                        <div class="visit-details-value"><?= $doctor_name ?></div>
                                    </div>
                                    
                                    <!-- Documents from this visit (Prescription, certificates, referrals – lab request has its own section below) -->
                                    <?php if ($has_prescription || !empty($appointment_certificates) || !empty($appointment_referrals)): ?>
                                    <div class="visit-details-section" style="background: #f8fdf8; border: 1px solid #C8E6C9; border-radius: 8px; padding: 12px 16px;">
                                        <div class="visit-details-label" style="color: #2E7D32; font-weight: 600;"><i class="fas fa-file-pdf" style="margin-right:6px;"></i>Documents from this visit</div>
                                        <p style="margin: 6px 0 0 0; font-size: 12px; color: #666;">View or download prescription, medical certificates, or referral letters as PDF.</p>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <!-- Action Buttons (View/Download PDFs) -->
                                    <div class="visit-actions">
                                        <?php if ($has_prescription): ?>
                                            <a href="generate_prescription_pdf.php?appointment_id=<?= (int)$record['id'] ?>&mode=view" 
                                               class="btn-view-prescription"
                                               onclick="event.stopPropagation(); openPdfModal(event, 'Prescription'); return false;">
                                                <i class="fas fa-eye"></i> View Prescription
                                            </a>
                                            <a href="generate_prescription_pdf.php?appointment_id=<?= (int)$record['id'] ?>" 
                                               class="btn-download"
                                               target="_blank"
                                               onclick="event.stopPropagation();">
                                                <i class="fas fa-download"></i> Download PDF
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($appointment_certificates)): ?>
                                            <?php foreach ($appointment_certificates as $cert): ?>
                                                <a href="generate_medical_certificate_pdf.php?certificate_id=<?= (int)$cert['id'] ?>&mode=view" 
                                                   class="btn-view-certificate"
                                                   onclick="event.stopPropagation(); openPdfModal(event, 'Medical Certificate'); return false;">
                                                    <i class="fas fa-eye"></i> View Medical Certificate
                                                </a>
                                                <a href="generate_medical_certificate_pdf.php?certificate_id=<?= (int)$cert['id'] ?>" 
                                                   class="btn-download"
                                                   target="_blank"
                                                   onclick="event.stopPropagation();">
                                                    <i class="fas fa-download"></i> Download PDF
                                                </a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($appointment_referrals)): ?>
                                            <?php foreach ($appointment_referrals as $ref): ?>
                                                <a href="generate_referral_pdf.php?referral_id=<?= (int)$ref['id'] ?>&mode=view" 
                                                   class="btn-view-referral"
                                                   onclick="event.stopPropagation(); openPdfModal(event, 'Referral Letter'); return false;">
                                                    <i class="fas fa-eye"></i> View Referral
                                                </a>
                                                <a href="generate_referral_pdf.php?referral_id=<?= (int)$ref['id'] ?>" 
                                                   class="btn-download"
                                                   target="_blank"
                                                   onclick="event.stopPropagation();">
                                                    <i class="fas fa-download"></i> Download PDF
                                                </a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="profile-card" style="margin-top: 2rem;">
                <div class="category-accordion active" onclick="toggleCategory(this)">
                    <div class="category-header">
                        <div class="category-header-title">
                            <i class="fas fa-calendar-check" style="color: #4CAF50;"></i>
                            <span>Health Center Visits</span>
                            <span style="font-size: 13px; font-weight: 400; color: #666; margin-left: 8px;">
                                (0)
                            </span>
                        </div>
                        <span class="category-toggle">+</span>
                    </div>
                    <div class="category-content">
                        <div style="text-align: center; padding: 3rem; color: #666;">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">📋</div>
                            <p>No appointment records yet. Schedule your first appointment to begin building your medical record.</p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Lab Test Requests Section -->
            <?php if (!empty($lab_test_requests)): ?>
            <div class="profile-card" style="margin-top: 2rem;">
                <div class="category-accordion" onclick="toggleCategory(this)">
                    <div class="category-header">
                        <div class="category-header-title">
                            <i class="fas fa-flask" style="color: #1976D2;"></i>
                            <span>Lab Test Requests</span>
                            <span style="font-size: 13px; font-weight: 400; color: #666; margin-left: 8px;">
                                (<?= count($lab_test_requests) ?>)
                            </span>
                        </div>
                        <span class="category-toggle">+</span>
                    </div>
                    <div class="category-content">
                        <div class="document-list">
                        <?php foreach ($lab_test_requests as $request): 
                            $request_results = array_filter($lab_test_results, function($result) use ($request) {
                                return (isset($result['lab_request_id']) && (int)$result['lab_request_id'] === (int)$request['id'])
                                    || (isset($result['lab_test_request_id']) && (int)$result['lab_test_request_id'] === (int)$request['id']);
                            });
                            $card_id = 'lab-' . $request['id'];
                            $status_colors = [
                                'pending' => '#ff9800',
                                'completed' => '#4CAF50',
                                'cancelled' => '#999'
                            ];
                            $status_color = $status_colors[$request['status']] ?? '#666';
                            $tests_display = !empty($request['tests']) ? implode(', ', $request['tests']) : ($request['test_name'] ?? '');
                            $use_lab_request_id = !empty($request['is_lab_request']);
                        ?>
                            <div class="document-item" onclick="event.stopPropagation(); toggleDocumentDetails('<?= $card_id ?>')">
                                <div class="document-row">
                                    <div class="document-chevron">
                                        <i class="fas fa-chevron-right"></i>
                                    </div>
                                    <div class="document-row-content">
                                        <h3 class="document-title">
                                            <?= htmlspecialchars($tests_display ?: 'Lab Request') ?>
                                        </h3>
                                        <span class="document-status" style="background: <?= $status_color ?>; color: white;">
                                            <?= ucfirst($request['status']) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="document-details" id="<?= $card_id ?>" onclick="event.stopPropagation();">
                                    <div class="document-details-content">
                                        <div style="margin-bottom: 10px;">
                                            <strong>Laboratory:</strong> <?= htmlspecialchars($request['laboratory_name'] ?? 'Not specified') ?><br>
                                            <strong>Requested:</strong> <?= date('F j, Y', strtotime($request['created_at'])) ?>
                                            <?php if (!empty($request['doctor_name'])): ?>
                                                by <?= htmlspecialchars($request['doctor_name']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($request['notes'])): ?>
                                            <div style="margin-top: 10px; padding: 10px; background: white; border-radius: 6px; font-size: 13px;">
                                                <strong>Notes:</strong> <?= nl2br(htmlspecialchars($request['notes'])) ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Upload Form (clicks inside do not collapse the expandable) -->
                                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;" onclick="event.stopPropagation();">
                                            <h4 style="margin: 0 0 10px 0; color: #1976D2; font-size: 14px;">Upload Lab Test Result</h4>
                                            <form class="lab-upload-form" data-request-id="<?= (int)$request['id'] ?>" data-use-lab-request-id="<?= $use_lab_request_id ? '1' : '0' ?>" data-patient-id="<?= $is_dependent ? (int)$selected_patient_id : (int)$user_id ?>" data-card-id="<?= $card_id ?>" enctype="multipart/form-data">
                                                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                                    <div style="flex: 1; min-width: 180px;">
                                                        <input type="file" name="lab_result_file" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 6px;">
                                                    </div>
                                                    <div style="flex: 1; min-width: 180px;">
                                                        <input type="text" name="notes" placeholder="Optional notes..." style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box;">
                                                    </div>
                                                    <button type="submit" class="btn-primary" style="padding: 8px 20px; border: none; border-radius: 6px; cursor: pointer; white-space: nowrap; flex-shrink: 0;">
                                                        <i class="fas fa-upload"></i> Upload
                                                    </button>
                                                </div>
                                                <small style="color: #666; font-size: 11px; display: block; margin-top: 6px;">Allowed: PDF, JPG, PNG, GIF, DOC, DOCX (Max 10MB)</small>
                                            </form>
                                            <div class="upload-message" data-request-id="<?= $request['id'] ?>" style="margin-top: 10px; display: none; padding: 10px; border-radius: 6px;"></div>
                                            <div class="uploaded-results-container" data-request-id="<?= (int)$request['id'] ?>" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd; display: <?= !empty($request_results) ? 'block' : 'none' ?>;">
                                                <h4 style="margin: 0 0 10px 0; color: #1976D2; font-size: 14px;">Uploaded Results:</h4>
                                                <div class="uploaded-results-list">
                                                <?php if (!empty($request_results)): ?>
                                                    <?php foreach ($request_results as $result): ?>
                                                    <div class="uploaded-result-row" style="margin-bottom: 10px; padding: 12px; background: white; border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
                                                        <div>
                                                            <i class="fas fa-file-pdf" style="color: #d32f2f; margin-right: 8px;"></i>
                                                            <a href="<?= htmlspecialchars($result['file_path']) ?>" target="_blank" style="color: #1976D2; text-decoration: none; font-weight: 500;"><?= htmlspecialchars($result['file_name']) ?></a>
                                                            <span style="color: #666; font-size: 12px; margin-left: 10px;">(<?= $result['file_size'] ? number_format($result['file_size'] / 1024, 2) . ' KB' : 'N/A' ?>)</span>
                                                            <p style="margin: 5px 0 0 0; color: #999; font-size: 11px;">Uploaded on <?= date('M d, Y h:i A', strtotime($result['uploaded_at'])) ?></p>
                                                        </div>
                                                        <a href="<?= htmlspecialchars($result['file_path']) ?>" target="_blank" class="btn-primary" style="padding: 6px 12px; font-size: 12px; text-decoration: none; border-radius: 6px;"><i class="fas fa-eye"></i> View</a>
                                                    </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($request['appointment_id'])): ?>
                                        <div class="document-actions">
                                            <a href="generate_lab_test_request_pdf.php?appointment_id=<?= (int)$request['appointment_id'] ?>&mode=view" 
                                               class="btn-view"
                                               onclick="event.stopPropagation(); openPdfModal(event, 'Lab Test Request'); return false;">
                                                <i class="fas fa-eye"></i> View Document
                                            </a>
                                            <a href="generate_lab_test_request_pdf.php?appointment_id=<?= (int)$request['appointment_id'] ?>" 
                                               target="_blank" 
                                               class="btn-download"
                                               onclick="event.stopPropagation();">
                                                <i class="fas fa-download"></i> Download PDF
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Approved Follow-Up Appointments Section -->
            <?php if(!empty($approved_followups)): ?>
            <div class="profile-card" style="margin-top: 2rem; border-left: 4px solid #4CAF50;">
                <h2 class="section-title" style="color: #2E7D32;">
                    <i class="fas fa-calendar-check"></i> Approved Follow-Up Appointments
                </h2>
                <?php foreach($approved_followups as $followup): 
                    $final_datetime = !empty($followup['selected_datetime']) ? $followup['selected_datetime'] : $followup['proposed_datetime'];
                    $final_date = date('F j, Y', strtotime($final_datetime));
                    $final_time = date('g:i A', strtotime($final_datetime));
                    $original_date = $followup['original_appointment_date'] ? date('F j, Y', strtotime($followup['original_appointment_date'])) : 'N/A';
                ?>
                    <div style="padding: 20px; margin-bottom: 20px; background: #e8f5e9; border-radius: 8px; border: 1px solid #4CAF50;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
                            <div>
                                <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 5px;">Original Appointment</div>
                                <div style="font-weight: 600; color: #333;"><?= htmlspecialchars($original_date) ?></div>
                            </div>
                            <div>
                                <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 5px;">Follow-Up Date</div>
                                <div style="font-weight: 600; color: #2E7D32; font-size: 18px;"><?= $final_date ?></div>
                            </div>
                            <div>
                                <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 5px;">Follow-Up Time</div>
                                <div style="font-weight: 600; color: #2E7D32; font-size: 18px;"><?= $final_time ?></div>
                            </div>
                            <div>
                                <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 5px;">Reason</div>
                                <div style="font-weight: 600; color: #333;"><?= htmlspecialchars($followup['follow_up_reason'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                        <?php if (!empty($followup['doctor_name'])): ?>
                            <div style="font-size: 13px; color: #666; margin-bottom: 15px;">
                                <i class="fas fa-user-md"></i> Doctor: <?= htmlspecialchars(trim($followup['doctor_name'])) ?>
                            </div>
                        <?php endif; ?>
                        <div style="padding: 10px; background: #fff; border-radius: 6px; margin-top: 10px;">
                            <span style="color: #4CAF50; font-weight: 600;">
                                <i class="fas fa-check-circle"></i> Approved and Confirmed
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Follow-up appointments moved to Appointments page -->

            <!-- Medical Certificates Section -->
            <?php if (!empty($medical_certificates)): ?>
            <div class="profile-card" style="margin-top: 2rem;">
                <div class="category-accordion" onclick="toggleCategory(this)">
                    <div class="category-header">
                        <div class="category-header-title">
                            <i class="fas fa-certificate" style="color: #F57C00;"></i>
                            <span>Medical Certificates</span>
                            <span style="font-size: 13px; font-weight: 400; color: #666; margin-left: 8px;">
                                (<?= count($medical_certificates) ?>)
                            </span>
                        </div>
                        <span class="category-toggle">+</span>
                    </div>
                    <div class="category-content">
                        <div class="document-list">
                        <?php 
                        // Certificate type and subtype labels
                        $type_labels = [
                            'work_related' => 'Work-Related',
                            'education' => 'Education',
                            'travel' => 'Travel',
                            'licensing' => 'Licensing & Permits',
                            'general' => 'General'
                        ];
                        $subtype_labels = [
                            'sick_leave' => 'Sick Leave',
                            'fit_to_work' => 'Fit-to-Work',
                            'food_handler' => 'Food Handler',
                            'high_risk_work' => 'High-Risk Work',
                            'school_clearance' => 'School Clearance',
                            'travel_clearance' => 'Travel Clearance',
                            'driver_license' => 'Driver\'s License',
                            'professional_license' => 'Professional License',
                            'health_checkup' => 'General Health Check-up',
                            'pwd_registration' => 'PWD Registration'
                        ];
                        
                        foreach ($medical_certificates as $index => $cert): 
                            $issued_date = date('F j, Y', strtotime($cert['issued_date']));
                            $exp_date = date('F j, Y', strtotime($cert['expiration_date']));
                            $today = date('Y-m-d');
                            $is_expired = strtotime($cert['expiration_date']) < strtotime($today);
                            
                            // Format certificate title
                            $cert_type = $type_labels[$cert['certificate_type']] ?? $cert['certificate_type'] ?? 'Medical Certificate';
                            $cert_subtype = $subtype_labels[$cert['certificate_subtype']] ?? $cert['certificate_subtype'] ?? '';
                            $fit_status = $cert['fit_status'] ? ' (' . ($cert['fit_status'] === 'fit' ? 'Fit' : 'Unfit') . ' to Work)' : '';
                            $cert_title = $cert_subtype ? $cert_subtype . $fit_status : $cert_type;
                            $card_id = 'cert-' . $cert['id'];
                        ?>
                            <div class="document-item" onclick="event.stopPropagation(); toggleDocumentDetails('<?= $card_id ?>')">
                                <div class="document-row">
                                    <div class="document-chevron">
                                        <i class="fas fa-chevron-right"></i>
                                    </div>
                                    <div class="document-row-content">
                                        <h3 class="document-title">
                                            <?= htmlspecialchars($cert_title) ?>
                                        </h3>
                                        <span class="document-status" style="background: <?= $is_expired ? '#ff9800' : '#4CAF50' ?>; color: white;">
                                            <?= $is_expired ? 'Expired' : 'Active' ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="document-details" id="<?= $card_id ?>">
                                    <div class="document-details-content">
                                        <p style="margin: 0 0 10px 0; color: #666; font-size: 12px; font-style: italic;">
                                            Certificate #<?= htmlspecialchars($cert['id']) ?>
                                        </p>
                                        <div style="margin-bottom: 10px;">
                                            <strong>Issued Date:</strong> <?= $issued_date ?><br>
                                            <strong>Expiration Date:</strong> <?= $exp_date ?><br>
                                            <strong>Validity Period:</strong> <?= htmlspecialchars($cert['validity_period_days']) ?> days
                                        </div>
                                        <?php if (!empty($cert['doctor_name'])): ?>
                                            <div style="font-size: 13px; color: #666; margin-top: 10px;">
                                                <i class="fas fa-user-md"></i> Issued by: <?= htmlspecialchars(trim($cert['doctor_name'])) ?>
                                                <?php if (!empty($cert['doctor_specialization'])): ?>
                                                    (<?= htmlspecialchars($cert['doctor_specialization']) ?>)
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($is_expired): ?>
                                            <p style="color: #999; font-size: 11px; margin-top: 10px;">
                                                This certificate expired on <?= $exp_date ?>. Please contact your doctor for a new certificate.
                                            </p>
                                        <?php else: ?>
                                            <p style="color: #4CAF50; font-size: 11px; font-weight: 600; margin-top: 10px;">
                                                <i class="fas fa-check-circle"></i> Valid until <?= $exp_date ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="document-actions">
                                        <a href="generate_medical_certificate_pdf.php?certificate_id=<?= (int)$cert['id'] ?>&mode=view" 
                                           class="btn-view"
                                           onclick="event.stopPropagation(); openPdfModal(event, 'Medical Certificate'); return false;">
                                            <i class="fas fa-eye"></i> View Document
                                        </a>
                                        <a href="generate_medical_certificate_pdf.php?certificate_id=<?= (int)$cert['id'] ?>" 
                                           target="_blank" 
                                           class="btn-download"
                                           onclick="event.stopPropagation();">
                                            <i class="fas fa-download"></i> Download PDF
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Referrals Section -->
            <?php if (!empty($referrals)): ?>
            <div class="profile-card" style="margin-top: 2rem;">
                <div class="category-accordion" onclick="toggleCategory(this)">
                    <div class="category-header">
                        <div class="category-header-title">
                            <i class="fas fa-hospital" style="color: #7B1FA2;"></i>
                            <span>Referrals to Other Hospitals</span>
                            <span style="font-size: 13px; font-weight: 400; color: #666; margin-left: 8px;">
                                (<?= count($referrals) ?>)
                            </span>
                        </div>
                        <span class="category-toggle">+</span>
                    </div>
                    <div class="category-content">
                        <div class="document-list">
                        <?php 
                        foreach ($referrals as $index => $ref): 
                            $referral_date = date('F j, Y', strtotime($ref['referral_date']));
                            $status_labels = [
                                'active' => 'Active',
                                'completed' => 'Completed',
                                'cancelled' => 'Cancelled'
                            ];
                            $status_colors = [
                                'active' => '#4CAF50',
                                'completed' => '#2196F3',
                                'cancelled' => '#999'
                            ];
                            $status_label = $status_labels[$ref['status']] ?? $ref['status'];
                            $status_color = $status_colors[$ref['status']] ?? '#999';
                            $card_id = 'ref-' . $ref['id'];
                        ?>
                            <div class="document-item" onclick="event.stopPropagation(); toggleDocumentDetails('<?= $card_id ?>')">
                                <div class="document-row">
                                    <div class="document-chevron">
                                        <i class="fas fa-chevron-right"></i>
                                    </div>
                                    <div class="document-row-content">
                                        <h3 class="document-title">
                                            <i class="fas fa-hospital"></i> <?= htmlspecialchars($ref['referred_hospital']) ?>
                                        </h3>
                                        <span class="document-status" style="background: <?= $status_color ?>; color: white;">
                                            <?= $status_label ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="document-details" id="<?= $card_id ?>">
                                    <div class="document-details-content">
                                        <p style="margin: 0 0 10px 0; color: #666; font-size: 12px; font-style: italic;">
                                            Referral #<?= htmlspecialchars($ref['id']) ?>
                                        </p>
                                        <div style="margin-bottom: 10px;">
                                            <strong>Referral Date:</strong> <?= $referral_date ?><br>
                                            <?php if (!empty($ref['referred_hospital_address'])): ?>
                                                <strong>Address:</strong> <?= htmlspecialchars($ref['referred_hospital_address']) ?><br>
                                            <?php endif; ?>
                                            <?php if (!empty($ref['referred_hospital_contact'])): ?>
                                                <strong>Contact:</strong> <?= htmlspecialchars($ref['referred_hospital_contact']) ?><br>
                                            <?php endif; ?>
                                            <strong>Reason:</strong> <?= htmlspecialchars($ref['reason_for_referral']) ?>
                                            <?php if (!empty($ref['clinical_notes'])): ?>
                                                <br><br>
                                                <strong>Clinical Notes:</strong><br>
                                                <div style="margin-top: 5px; padding: 10px; background: white; border-radius: 4px; font-size: 13px;">
                                                    <?= nl2br(htmlspecialchars($ref['clinical_notes'])) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($ref['doctor_name'])): ?>
                                            <div style="font-size: 13px; color: #666; margin-top: 10px;">
                                                <i class="fas fa-user-md"></i> Referred by: <?= htmlspecialchars(trim($ref['doctor_name'])) ?>
                                                <?php if (!empty($ref['doctor_specialization'])): ?>
                                                    (<?= htmlspecialchars($ref['doctor_specialization']) ?>)
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="document-actions">
                                        <a href="generate_referral_pdf.php?referral_id=<?= (int)$ref['id'] ?>&mode=view" 
                                           class="btn-view"
                                           onclick="event.stopPropagation(); openPdfModal(event, 'Referral Letter'); return false;">
                                            <i class="fas fa-eye"></i> View Document
                                        </a>
                                        <a href="generate_referral_pdf.php?referral_id=<?= (int)$ref['id'] ?>" 
                                           target="_blank" 
                                           class="btn-download"
                                           onclick="event.stopPropagation();">
                                            <i class="fas fa-download"></i> Download PDF
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <!-- No Patient Record -->
            <div style="text-align: center; padding: 3rem;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">📋</div>
                <h2 style="color: #2e3b4e; margin-bottom: 1rem;">No Medical Record Found</h2>
                <p style="color: #666; margin-bottom: 2rem;">
                    It looks like you haven't created a patient record yet. 
                    Please book an appointment to get started with your medical record.
                </p>
                <a href="user_appointments.php" class="btn-primary" style="display: inline-block; text-decoration: none;">
                    Book Your First Appointment
                </a>
            </div>
            <?php endif; ?>
        </div>
    </main>
    
    <!-- PDF Modal Viewer -->
    <div class="pdf-modal" id="pdfModal">
        <div class="pdf-modal-content">
            <div class="pdf-modal-header">
                <h3 class="pdf-modal-title" id="pdfModalTitle">Document Viewer</h3>
                <button class="pdf-modal-close" onclick="closePdfModal()" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="pdf-modal-body">
                <div class="pdf-modal-loading" id="pdfModalLoading">Loading document...</div>
                <iframe class="pdf-modal-iframe" id="pdfModalIframe" style="display: none;"></iframe>
            </div>
        </div>
    </div>
</body>
</html>
<script>
    // Real-time Notification System connected to database
    (function(){
        class NotificationSystem{
            constructor(){
                this.notifications = [];
                this.pollInterval = null;
                this.currentFilter = 'active';
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
            
            async fetchNotifications(filter = 'active'){
                try {
                    this.currentFilter = filter;
                    const response = await fetch(`get_patient_notifications.php?action=fetch&filter=${filter}`);
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
                        if(filter === 'active'){
                            this.updateBadge();
                        }
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
                    'prescription': 'user_records.php'
                };
                return links[type] || '#';
            }
            
            getIconForType(type){
                const icons = {
                    'appointment': '📅',
                    'announcement': '📢',
                    'record_update': '💊',
                    'prescription': '💊'
                };
                return icons[type] || '🔔';
            }
            
            bindEvents(){
                const nBtn = document.getElementById('notificationBtn');
                const nDrop = document.getElementById('notificationDropdown');
                const clear = document.getElementById('clearAll');
                const filters = document.querySelectorAll('.filter-btn');
                
                if(!nBtn || !nDrop || !clear) return;
                
                nBtn.addEventListener('click', e => {
                    e.stopPropagation();
                    this.toggleDropdown();
                });
                
                clear.addEventListener('click', e => {
                    e.preventDefault();
                    if(this.currentFilter === 'active'){
                        this.clearAllNotifications();
                    }
                });
                
                // Filter button events
                filters.forEach(btn => {
                    btn.addEventListener('click', e => {
                        e.stopPropagation();
                        const filter = btn.getAttribute('data-filter');
                        filters.forEach(b => {
                            b.classList.remove('active');
                            b.style.background = 'white';
                            b.style.color = '#666';
                        });
                        btn.classList.add('active');
                        btn.style.background = '#4CAF50';
                        btn.style.color = 'white';
                        this.fetchNotifications(filter);
                    });
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
                const item = document.createElement('div');
                item.className = `notification-item ${n.read ? 'read' : ''}`;
                item.setAttribute('data-id', n.id);
                item.style.cssText = 'display: flex; align-items: center; gap: 8px; padding: 12px 16px; border-bottom: 1px solid #f8f8f8; position: relative;';
                
                const isArchived = this.currentFilter === 'archived';
                
                // Get background color for icon based on type
                let iconBgColor = 'rgba(76, 175, 80, 0.1)';
                let iconTextColor = '#4CAF50';
                if (n.type === 'appointment') {
                    iconBgColor = 'rgba(156, 39, 176, 0.1)';
                    iconTextColor = '#9C27B0';
                } else if (n.type === 'record_update' || n.type === 'prescription') {
                    iconBgColor = 'rgba(33, 150, 243, 0.1)';
                    iconTextColor = '#2196F3';
                } else if (n.type === 'announcement') {
                    iconBgColor = 'rgba(255, 193, 7, 0.1)';
                    iconTextColor = '#FFC107';
                }
                
                item.innerHTML = `
                    <a href="#" class="notification-link" style="flex: 1; display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit;">
                        <div class="notification-icon-wrapper ${n.type}" style="width: 28px; height: 28px; border-radius: 4px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 16px; background: ${iconBgColor}; color: ${iconTextColor};">
                            <span>${this.getIconForType(n.type)}</span>
                        </div>
                        <div class="notification-content" style="flex: 1; min-width: 0;">
                            <div class="notification-text" style="font-size: 14px; color: #333; margin-bottom: 2px; line-height: 1.4;">${n.text}</div>
                            <div class="notification-time" style="font-size: 12px; color: #888;">${n.time}</div>
                        </div>
                        ${!n.read && !isArchived ? '<div class="notification-dot" style="width: 6px; height: 6px; background: #4CAF50; border-radius: 50%; flex-shrink: 0;"></div>' : ''}
                    </a>
                    <div class="notification-actions" style="display: flex; gap: 3px; align-items: center; flex-shrink: 0;">
                        ${isArchived ? 
                            `<button class="action-btn restore-btn" title="Restore" style="padding: 2px; border: 1px solid #4CAF50; background: white; color: #4CAF50; border-radius: 2px; cursor: pointer; font-size: 12px; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; min-width: 20px;">↩</button>` :
                            `<button class="action-btn archive-btn" title="Archive" style="padding: 2px; border: 1px solid #ff9800; background: white; color: #ff9800; border-radius: 2px; cursor: pointer; font-size: 12px; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; min-width: 20px;">📦</button>`
                        }
                        <button class="action-btn delete-btn" title="Delete" style="padding: 2px; border: 1px solid #f44336; background: white; color: #f44336; border-radius: 2px; cursor: pointer; font-size: 12px; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; min-width: 20px;">🗑</button>
                    </div>
                `;
                
                // Link click handler
                const link = item.querySelector('.notification-link');
                link.addEventListener('click', e => {
                    e.preventDefault();
                    e.stopPropagation();
                    this.handleNotificationClick(n.id, n.link);
                });
                
                // Archive button
                const archiveBtn = item.querySelector('.archive-btn');
                if(archiveBtn){
                    archiveBtn.addEventListener('click', e => {
                        e.preventDefault();
                        e.stopPropagation();
                        this.archiveNotification(n.id);
                    });
                }
                
                // Restore button
                const restoreBtn = item.querySelector('.restore-btn');
                if(restoreBtn){
                    restoreBtn.addEventListener('click', e => {
                        e.preventDefault();
                        e.stopPropagation();
                        this.restoreNotification(n.id);
                    });
                }
                
                // Delete button
                const deleteBtn = item.querySelector('.delete-btn');
                deleteBtn.addEventListener('click', e => {
                    e.preventDefault();
                    e.stopPropagation();
                    this.confirmDelete(n.id, n.text);
                });
                
                return item;
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
            
            async archiveNotification(id){
                try {
                    const formData = new FormData();
                    formData.append('notification_id', id);
                    const response = await fetch('get_patient_notifications.php?action=archive', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();
                    if(data.success){
                        await this.fetchNotifications(this.currentFilter);
                        this.updateBadge();
                    } else {
                        alert('Error: ' + (data.message || 'Failed to archive notification'));
                    }
                } catch(e){
                    console.error('Error archiving notification:', e);
                    alert('Error archiving notification');
                }
            }
            
            async restoreNotification(id){
                try {
                    const formData = new FormData();
                    formData.append('notification_id', id);
                    const response = await fetch('get_patient_notifications.php?action=restore', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();
                    if(data.success){
                        await this.fetchNotifications(this.currentFilter);
                        this.updateBadge();
                    } else {
                        alert('Error: ' + (data.message || 'Failed to restore notification'));
                    }
                } catch(e){
                    console.error('Error restoring notification:', e);
                    alert('Error restoring notification');
                }
            }
            
            confirmDelete(id, text){
                const message = `Are you sure you want to permanently delete this notification?\n\n"${text.substring(0, 50)}${text.length > 50 ? '...' : ''}"\n\nThis action cannot be undone.`;
                if(confirm(message)){
                    this.deleteNotification(id);
                }
            }
            
            async deleteNotification(id){
                try {
                    const formData = new FormData();
                    formData.append('notification_id', id);
                    const response = await fetch('get_patient_notifications.php?action=delete', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();
                    if(data.success){
                        await this.fetchNotifications(this.currentFilter);
                        this.updateBadge();
                    } else {
                        alert('Error: ' + (data.message || 'Failed to delete notification'));
                    }
                } catch(e){
                    console.error('Error deleting notification:', e);
                    alert('Error deleting notification');
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
    
    // Handle dependent selector change
    function loadPatientRecord(patientId) {
        if (patientId && patientId !== '0') {
            // Reload page with selected patient_id or dependent_id
            // patientId can be either a numeric patient_id or "dep_{dependent_id}" format
            const url = new URL(window.location.href);
            url.searchParams.set('patient_id', patientId);
            window.location.href = url.toString();
        } else {
            // Load self (registered patient)
            const url = new URL(window.location.href);
            url.searchParams.delete('patient_id');
            window.location.href = url.toString();
        }
    }

    // Add Dependent modal + Medical conditions dropdown (checkbox multi-select)
    (function() {
        const overlay = document.getElementById('addDependentOverlay');
        const modal = document.getElementById('addDependentModal');
        const form = document.getElementById('addDependentForm');
        const messageEl = document.getElementById('addDependentMessage');
        const submitBtn = document.getElementById('addDependentSubmit');
        const medicalTrigger = document.getElementById('medical_select_trigger');
        const medicalPlaceholder = document.getElementById('medical_select_placeholder');
        const medicalTags = document.getElementById('medical_select_tags');
        const medicalDropdown = document.getElementById('medical_select_dropdown');
        const medicalOtherWrap = document.getElementById('dep_medical_other_wrap');
        const medicalOtherInput = document.getElementById('dep_medical_other');
        const medicalHiddenContainer = document.getElementById('medical_history_hidden_container');
        const otherCheckbox = medicalDropdown ? medicalDropdown.querySelector('input[type="checkbox"][value="Other"]') : null;

        function showMessage(text, type) {
            messageEl.textContent = text;
            messageEl.className = 'form-message ' + (type === 'error' ? 'error' : 'success');
            messageEl.style.display = 'block';
        }
        function hideMessage() {
            messageEl.className = 'form-message';
            messageEl.style.display = 'none';
        }

        function getMedicalSelected() {
            const checks = modal ? modal.querySelectorAll('#medical_select_dropdown input[type="checkbox"]:checked') : [];
            var list = Array.from(checks).map(function(c) { return c.value; });
            var otherText = medicalOtherInput ? medicalOtherInput.value.trim() : '';
            if (list.indexOf('Other') !== -1 && otherText === '') return list.filter(function(v) { return v !== 'Other'; });
            return list;
        }
        function syncMedicalHidden() {
            if (!medicalHiddenContainer) return;
            medicalHiddenContainer.innerHTML = '';
            getMedicalSelected().forEach(function(val) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'medical_history[]';
                inp.value = val;
                medicalHiddenContainer.appendChild(inp);
            });
        }
        function renderMedicalTags() {
            var selected = getMedicalSelected();
            medicalPlaceholder.style.display = selected.length ? 'none' : 'inline';
            medicalTags.innerHTML = '';
            var checkboxes = medicalDropdown.querySelectorAll('input[type="checkbox"]');
            var otherText = medicalOtherInput ? medicalOtherInput.value.trim() : '';
            selected.forEach(function(val) {
                var opt = null;
                checkboxes.forEach(function(cb) { if (cb.value === val) opt = cb; });
                var label = opt ? (opt.getAttribute('data-label') || val) : val;
                if (val === 'Other' && otherText) label = 'Other: ' + otherText;
                var span = document.createElement('span');
                span.className = 'medical-select-tag';
                span.textContent = label;
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'medical-select-tag-remove';
                btn.setAttribute('aria-label', 'Remove');
                btn.innerHTML = '&times;';
                (function(v) {
                    btn.onclick = function(ev) {
                        ev.preventDefault();
                        ev.stopPropagation();
                        checkboxes.forEach(function(cb) { if (cb.value === v) cb.checked = false; });
                        if (v === 'Other' && medicalOtherInput) medicalOtherInput.value = '';
                        renderMedicalTags();
                        syncMedicalHidden();
                        toggleMedicalOtherVisible();
                    };
                })(val);
                span.appendChild(btn);
                medicalTags.appendChild(span);
            });
        }
        function toggleMedicalOtherVisible() {
            medicalOtherWrap.classList.toggle('visible', otherCheckbox ? otherCheckbox.checked : false);
        }
        function closeMedicalDropdown() {
            if (medicalTrigger) medicalTrigger.classList.remove('open');
            if (medicalDropdown) medicalDropdown.classList.remove('open');
            medicalTrigger && medicalTrigger.setAttribute('aria-expanded', 'false');
        }
        function openMedicalDropdown() {
            medicalTrigger.classList.add('open');
            medicalDropdown.classList.add('open');
            medicalTrigger.setAttribute('aria-expanded', 'true');
        }

        function openModal() {
            if (overlay) {
                overlay.classList.add('show');
                overlay.setAttribute('aria-hidden', 'false');
                hideMessage();
                form.reset();
                closeMedicalDropdown();
                if (medicalTags) medicalTags.innerHTML = '';
                if (medicalPlaceholder) medicalPlaceholder.style.display = 'inline';
                medicalDropdown.querySelectorAll('input[type="checkbox"]').forEach(function(cb) { cb.checked = false; });
                if (medicalOtherWrap) medicalOtherWrap.classList.remove('visible');
                syncMedicalHidden();
            }
        }
        function closeModal() {
            if (overlay) {
                overlay.classList.remove('show');
                overlay.setAttribute('aria-hidden', 'true');
                closeMedicalDropdown();
            }
        }

        if (medicalTrigger && medicalDropdown) {
            medicalTrigger.addEventListener('click', function(e) {
                e.preventDefault();
                if (medicalDropdown.classList.contains('open')) closeMedicalDropdown(); else openMedicalDropdown();
            });
            medicalTrigger.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    if (medicalDropdown.classList.contains('open')) closeMedicalDropdown(); else openMedicalDropdown();
                }
            });
        }
        document.addEventListener('click', function(e) {
            if (medicalDropdown && medicalTrigger && medicalDropdown.classList.contains('open')) {
                if (!medicalDropdown.contains(e.target) && !medicalTrigger.contains(e.target)) closeMedicalDropdown();
            }
        });
        if (medicalDropdown) {
            medicalDropdown.querySelectorAll('input[type="checkbox"]').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    renderMedicalTags();
                    syncMedicalHidden();
                    toggleMedicalOtherVisible();
                });
            });
        }
        if (medicalOtherWrap) {
            medicalOtherWrap.querySelector('input')?.addEventListener('click', function(e) { e.stopPropagation(); });
        }
        if (medicalOtherInput) {
            medicalOtherInput.addEventListener('input', function() {
                renderMedicalTags();
                syncMedicalHidden();
            });
            medicalOtherInput.addEventListener('blur', function() {
                renderMedicalTags();
                syncMedicalHidden();
            });
        }

        document.getElementById('btnAddDependent')?.addEventListener('click', openModal);
        document.getElementById('addDependentClose')?.addEventListener('click', closeModal);
        document.getElementById('addDependentCancel')?.addEventListener('click', closeModal);
        overlay?.addEventListener('click', function(e) {
            if (e.target === overlay) closeModal();
        });
        modal?.addEventListener('click', function(e) { e.stopPropagation(); });

        function validateContactNo(value) {
            if (!value || value.trim() === '') return true;
            var digits = value.replace(/\D/g, '');
            return digits.length === 11 && /^\d{11}$/.test(digits);
        }
        document.getElementById('dep_contact_no')?.addEventListener('input', function() {
            var el = this;
            var v = el.value.replace(/\D/g, '');
            if (v.length > 11) v = v.slice(0, 11);
            el.value = v;
        });
        form?.addEventListener('submit', async function(e) {
            e.preventDefault();
            syncMedicalHidden();
            hideMessage();
            var contactEl = document.getElementById('dep_contact_no');
            var contactVal = contactEl ? contactEl.value.trim() : '';
            if (contactVal && !validateContactNo(contactVal)) {
                showMessage('Contact number dapat eksaktong 11 digits, numbers lang.', 'error');
                return;
            }
            var origText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';
            var formData = new FormData(form);
            try {
                var resp = await fetch('patient_add_dependent.php', { method: 'POST', body: formData });
                var data = await resp.json();
                if (data.success) {
                    closeModal();
                    var url = new URL(window.location.href);
                    url.searchParams.set('patient_id', data.option_value);
                    window.location.href = url.toString();
                } else {
                    showMessage(data.error || 'Could not save dependent.', 'error');
                    submitBtn.disabled = false;
                    submitBtn.textContent = origText;
                }
            } catch (err) {
                showMessage('Network error. Please try again.', 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = origText;
            }
        });
    })();

    // Reschedule functionality moved to Appointments page
    
    // Restrict prescription/lab links and lab upload when residency not verified
    document.addEventListener('DOMContentLoaded', function() {
        var restrictedMsg = 'Only verified residents of Barangay Payatas are allowed to access HealthServe services. Please complete your verification.';
        var verified = document.body.getAttribute('data-residency-verified') === '1';
        if (!verified) {
            document.querySelectorAll('main').forEach(function(main) {
                main.addEventListener('click', function(e) {
                    var a = e.target.closest('a[href*="generate_prescription_pdf"], a[href*="generate_lab_test_request_pdf"]');
                    if (a) {
                        e.preventDefault();
                        alert(restrictedMsg);
                        window.location.href = 'user_profile.php';
                    }
                });
            });
        }
        const uploadForms = document.querySelectorAll('.lab-upload-form');
        uploadForms.forEach(form => {
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (!verified) {
                    alert(restrictedMsg);
                    window.location.href = 'user_profile.php';
                    return;
                }
                const requestId = this.dataset.requestId;
                const patientId = this.dataset.patientId;
                const fileInput = this.querySelector('input[type="file"]');
                const notesInput = this.querySelector('input[name="notes"]');
                const submitBtn = this.querySelector('button[type="submit"]');
                const messageDiv = document.querySelector(`.upload-message[data-request-id="${requestId}"]`) ||
                                   this.parentElement.querySelector('.upload-message') ||
                                   this.closest('div[style*="margin-top"]')?.querySelector('.upload-message');
                
                if (!fileInput.files || fileInput.files.length === 0) {
                    showUploadMessage(messageDiv, 'Please select a file to upload', 'error');
                    return;
                }
                
                const useLabRequestId = form.getAttribute('data-use-lab-request-id') === '1';
                const formData = new FormData();
                formData.append('action', 'upload_result');
                if (useLabRequestId) {
                    formData.append('lab_request_id', requestId);
                } else {
                    formData.append('lab_test_request_id', requestId);
                }
                formData.append('patient_id', patientId);
                formData.append('lab_result_file', fileInput.files[0]);
                if (notesInput && notesInput.value.trim()) {
                    formData.append('notes', notesInput.value.trim());
                }
                
                const originalBtnText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
                if (messageDiv) {
                    showUploadMessage(messageDiv, 'Uploading file...', 'info');
                }
                
                try {
                    const response = await fetch('lab_test_upload_handler.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    let data;
                    try {
                        data = await response.json();
                    } catch (_) {
                        data = { success: false, message: 'Invalid response from server.' };
                    }
                    
                    if (!response.ok) {
                        const errorMsg = data.message || ('Request failed: ' + response.status);
                        if (messageDiv) {
                            showUploadMessage(messageDiv, errorMsg, 'error');
                        } else {
                            alert(errorMsg);
                        }
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnText;
                        return;
                    }
                    
                    if (data.success) {
                        if (messageDiv) {
                            showUploadMessage(messageDiv, 'File uploaded successfully! The doctor will be notified.', 'success');
                        } else {
                            alert('File uploaded successfully! The doctor will be notified.');
                        }
                        fileInput.value = '';
                        if (notesInput) notesInput.value = '';
                        // Append new result to list without reloading (expandable stays open)
                        const container = document.querySelector(`.uploaded-results-container[data-request-id="${requestId}"]`);
                        const list = container ? container.querySelector('.uploaded-results-list') : null;
                        if (container && list && data.file_path) {
                            container.style.display = 'block';
                            const sizeKb = data.file_size ? (data.file_size / 1024).toFixed(2) + ' KB' : 'N/A';
                            const row = document.createElement('div');
                            row.className = 'uploaded-result-row';
                            row.style.cssText = 'margin-bottom: 10px; padding: 12px; background: white; border-radius: 6px; display: flex; justify-content: space-between; align-items: center;';
                            row.innerHTML = '<div><i class="fas fa-file-pdf" style="color: #d32f2f; margin-right: 8px;"></i><a href="' + (data.file_path || '') + '" target="_blank" style="color: #1976D2; text-decoration: none; font-weight: 500;">' + (data.file_name || 'File') + '</a><span style="color: #666; font-size: 12px; margin-left: 10px;">(' + sizeKb + ')</span><p style="margin: 5px 0 0 0; color: #999; font-size: 11px;">Uploaded on ' + (data.uploaded_at || '') + '</p></div><a href="' + (data.file_path || '') + '" target="_blank" class="btn-primary" style="padding: 6px 12px; font-size: 12px; text-decoration: none; border-radius: 6px;"><i class="fas fa-eye"></i> View</a>';
                            list.appendChild(row);
                        }
                    } else {
                        const errorMsg = data.message || 'Upload failed';
                        if (messageDiv) {
                            showUploadMessage(messageDiv, 'Error: ' + errorMsg, 'error');
                        } else {
                            alert('Error: ' + errorMsg);
                        }
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnText;
                    }
                } catch (error) {
                    console.error('Upload error:', error);
                    const errorMsg = error.message || 'Error uploading file. Please try again.';
                    if (messageDiv) {
                        showUploadMessage(messageDiv, errorMsg, 'error');
                    } else {
                        alert(errorMsg);
                    }
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                }
            });
        });
    });
    
    function showUploadMessage(element, message, type) {
        if (!element) return;
        
        element.style.display = 'block';
        element.textContent = message;
        element.style.padding = '10px';
        element.style.borderRadius = '6px';
        
        if (type === 'success') {
            element.style.background = '#d4edda';
            element.style.color = '#155724';
            element.style.border = '1px solid #c3e6cb';
        } else if (type === 'error') {
            element.style.background = '#f8d7da';
            element.style.color = '#721c24';
            element.style.border = '1px solid #f5c6cb';
        } else {
            element.style.background = '#d1ecf1';
            element.style.color = '#0c5460';
            element.style.border = '1px solid #bee5eb';
        }
    }
    
    // Toggle category accordion (like FAQ)
    function toggleCategory(element) {
        const isActive = element.classList.contains('active');
        // Close all other categories (optional - remove if you want multiple open)
        // document.querySelectorAll('.category-accordion').forEach(cat => cat.classList.remove('active'));
        if (!isActive) {
            element.classList.add('active');
        } else {
            element.classList.remove('active');
        }
    }
    
    // Toggle document details accordion
    function toggleDocumentDetails(cardId) {
        const details = document.getElementById(cardId);
        if (!details) return;
        
        const item = details.closest('.document-item');
        if (!item) return;
        
        const isExpanded = item.classList.contains('expanded');
        
        if (isExpanded) {
            item.classList.remove('expanded');
        } else {
            item.classList.add('expanded');
        }
    }
    
    // Toggle visit card accordion
    function toggleVisitCard(cardId) {
        const details = document.getElementById(cardId);
        if (!details) return;
        
        const card = details.closest('.visit-card');
        if (!card) return;
        
        const isExpanded = card.classList.contains('expanded');
        
        if (isExpanded) {
            card.classList.remove('expanded');
        } else {
            card.classList.add('expanded');
        }
    }
    
    // Filter visits based on selected filter, month, and year
    function filterVisits() {
        const filter = document.getElementById('visitFilter').value;
        const monthFilter = document.getElementById('visitMonthFilter').value;
        const yearFilter = document.getElementById('visitYearFilter').value;
        const visitCards = document.querySelectorAll('.visit-card');
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        // Calculate date ranges for "This Week"
        const startOfWeek = new Date(today);
        const dayOfWeek = today.getDay(); // 0 = Sunday, 1 = Monday, etc.
        startOfWeek.setDate(today.getDate() - dayOfWeek); // Go back to Sunday
        startOfWeek.setHours(0, 0, 0, 0);
        
        const endOfWeek = new Date(startOfWeek);
        endOfWeek.setDate(startOfWeek.getDate() + 6); // Saturday
        endOfWeek.setHours(23, 59, 59, 999);
        
        // Calculate date ranges for "This Month"
        const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
        startOfMonth.setHours(0, 0, 0, 0);
        
        const endOfMonth = new Date(today.getFullYear(), today.getMonth() + 1, 0);
        endOfMonth.setHours(23, 59, 59, 999);
        
        let visibleCount = 0;
        
        visitCards.forEach(card => {
            const visitTimestamp = parseInt(card.getAttribute('data-visit-timestamp')) || 0;
            const status = card.getAttribute('data-status') || 'other';
            const visitMonth = card.getAttribute('data-visit-month') || '';
            const visitYear = card.getAttribute('data-visit-year') || '';
            const visitDate = new Date(visitTimestamp * 1000);
            visitDate.setHours(0, 0, 0, 0);
            const visitTime = visitDate.getTime();
            
            // Start with filter-based logic
            let shouldShow = false;
            
            switch(filter) {
                case 'all':
                    shouldShow = true;
                    break;
                    
                case 'week':
                    // Show visits scheduled for this week (Sunday to Saturday)
                    const weekStartTime = startOfWeek.getTime();
                    const weekEndTime = endOfWeek.getTime();
                    shouldShow = visitTime >= weekStartTime && visitTime <= weekEndTime;
                    break;
                    
                case 'month':
                    // Show visits scheduled for this month
                    const monthStartTime = startOfMonth.getTime();
                    const monthEndTime = endOfMonth.getTime();
                    shouldShow = visitTime >= monthStartTime && visitTime <= monthEndTime;
                    break;
                    
                case 'upcoming':
                    // Show only upcoming appointments
                    shouldShow = status === 'upcoming';
                    break;
                    
                case 'completed':
                    // Show only completed appointments
                    shouldShow = status === 'completed';
                    break;
                    
                default:
                    shouldShow = true;
            }
            
            // Apply month filter if not "all"
            if (shouldShow && monthFilter !== 'all') {
                shouldShow = visitMonth === monthFilter;
            }
            
            // Apply year filter if not "all"
            if (shouldShow && yearFilter !== 'all') {
                shouldShow = visitYear === yearFilter;
            }
            
            if (shouldShow) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });
        
        // Show message if no visits match the filter
        const visitsList = document.getElementById('visitsList');
        let noResultsMsg = document.getElementById('noVisitsMessage');
        
        if (visibleCount === 0) {
            if (!noResultsMsg) {
                noResultsMsg = document.createElement('div');
                noResultsMsg.id = 'noVisitsMessage';
                noResultsMsg.style.cssText = 'text-align: center; padding: 3rem; color: #666;';
                noResultsMsg.innerHTML = '<div style="font-size: 3rem; margin-bottom: 1rem;">📋</div><p>No visits match the selected filter.</p>';
                visitsList.appendChild(noResultsMsg);
            }
            noResultsMsg.style.display = 'block';
        } else {
            if (noResultsMsg) {
                noResultsMsg.style.display = 'none';
            }
        }
    }
    
    // Open PDF in modal
    function openPdfModal(event, title) {
        event.preventDefault();
        const link = event.currentTarget;
        const pdfUrl = link.getAttribute('href');
        
        const modal = document.getElementById('pdfModal');
        const modalTitle = document.getElementById('pdfModalTitle');
        const modalIframe = document.getElementById('pdfModalIframe');
        const modalLoading = document.getElementById('pdfModalLoading');
        
        if (!modal || !modalTitle || !modalIframe) return;
        
        // Set title
        modalTitle.textContent = title || 'Document Viewer';
        
        // Show modal
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Show loading, hide iframe
        modalLoading.style.display = 'block';
        modalIframe.style.display = 'none';
        
        // Load PDF in iframe
        modalIframe.src = pdfUrl;
        
        // Hide loading when PDF loads
        modalIframe.onload = function() {
            modalLoading.style.display = 'none';
            modalIframe.style.display = 'block';
        };
        
        // Handle iframe load errors
        modalIframe.onerror = function() {
            modalLoading.textContent = 'Error loading document. Please try downloading instead.';
        };
    }
    
    // Close PDF modal
    function closePdfModal() {
        const modal = document.getElementById('pdfModal');
        const modalIframe = document.getElementById('pdfModalIframe');
        
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        if (modalIframe) {
            // Clear iframe src to stop loading
            modalIframe.src = '';
        }
    }
    
    // Close modal when clicking outside
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('pdfModal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closePdfModal();
                }
            });
        }
        
        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modal = document.getElementById('pdfModal');
                if (modal && modal.classList.contains('active')) {
                    closePdfModal();
                }
            }
        });
    });
</script>
</html>