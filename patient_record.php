<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    header('Location: Login.php');
    exit();
}

// Get the doctor_id from the logged-in doctor's user_id
$user_id = $_SESSION['user']['id'];
$stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
$stmt->execute([$user_id]);
$doctor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    header('Location: doctors_page.php');
    exit();
}

$doctor_id = $doctor['id'];
$patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;

$placeholderPatient = [
    'id' => $patient_id > 0 ? $patient_id : 999,
    'first_name' => 'Juan',
    'last_name' => 'Dela Cruz',
    'sex' => 'M',
    'dob' => '1992-01-05',
    'phone' => '+63 912 345 6789',
    'address' => 'Zone 4, Payatas B, Quezon City',
    'philhealth_no' => '12-345678901-2'
];

$placeholderAppointments = [
    [
        'start_datetime' => '2025-04-13 09:00:00',
        'doctor_name' => 'Dr. Nomer Gumiran',
        'status' => 'completed',
    ],
    [
        'start_datetime' => '2025-04-20 10:30:00',
        'doctor_name' => 'Dr. Anna Torres',
        'status' => 'pending',
    ]
];

$placeholderConsultations = [
    [
        'findings' => 'Noted mild tenderness on the frontal area.',
        'diagnosis' => 'Tension-type Headache',
        'notes' => 'Advise hydration and rest.',
        'created_at' => '2025-04-13 10:00:00'
    ]
];

$placeholderFollowups = [
    [
        'followup_datetime' => '2025-05-02 09:00:00',
        'notes' => 'Re-evaluate headache and check vital signs.',
        'created_at' => '2025-04-13 10:15:00'
    ]
];

$patient = null;
$appointments = [];
$consultations = [];
$followups = [];
$is_placeholder = false;

try {
    if ($patient_id > 0) {
        // First, check if this doctor has access to this patient (has approved or completed appointment)
        $access_check = $pdo->prepare("
            SELECT 1 FROM appointments 
            WHERE doctor_id = ? 
            AND (status = 'approved' OR status = 'completed')
            AND (
                (user_id = ? AND patient_id = ?) 
                OR (user_id = ? AND patient_id IS NULL)
                OR patient_id = ?
            )
            LIMIT 1
        ");
        $access_check->execute([$doctor_id, $patient_id, $patient_id, $patient_id, $patient_id]);
        
        if (!$access_check->fetch()) {
            // Doctor doesn't have access to this patient
            header('Location: doctors_page.php?error=access_denied');
            exit();
        }
        
        // First, try to get patient from users table (registered patient)
        // The patient_id passed from doctor_get_patients.php is now a user.id
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.contact_no as phone,
                u.address,
                pp.sex,
                pp.date_of_birth as dob,
                COALESCE(p.philhealth_no, '') as philhealth_no,
                u.created_at
            FROM users u
            INNER JOIN patient_profiles pp ON pp.patient_id = u.id
            LEFT JOIN patients p ON p.created_by_user_id = u.id
            WHERE u.id = ? AND u.role = 'patient'
            LIMIT 1
        ");
        $stmt->execute([$patient_id]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);

        // If not found in users table, try patients table (for dependents or backward compatibility)
        if (!$patient) {
            // Check access for dependent patient
            $access_check = $pdo->prepare("
                SELECT 1 FROM appointments 
                WHERE doctor_id = ? 
                AND (status = 'approved' OR status = 'completed')
                AND patient_id = ?
                LIMIT 1
            ");
            $access_check->execute([$doctor_id, $patient_id]);
            
            if (!$access_check->fetch()) {
                // Doctor doesn't have access to this patient
                header('Location: doctors_page.php?error=access_denied');
                exit();
            }
            
            $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
            $stmt->execute([$patient_id]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // If we got a patient from patients table, verify it's actually a dependent
            // and not the account owner's own record
            if ($patient && !empty($patient['created_by_user_id'])) {
                // Check if this patient record belongs to a registered user with the same name
                // (which would mean it's the account owner's record, not a dependent)
                $stmt = $pdo->prepare("
                    SELECT id FROM users 
                    WHERE id = ? 
                      AND role = 'patient'
                      AND LOWER(TRIM(first_name)) = LOWER(TRIM(?))
                      AND LOWER(TRIM(last_name)) = LOWER(TRIM(?))
                ");
                $stmt->execute([
                    $patient['created_by_user_id'], 
                    $patient['first_name'], 
                    $patient['last_name']
                ]);
                $is_owner_record = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // If this matches the account owner's own record, it's not a dependent
                if ($is_owner_record) {
                    // This is the account owner's record, not a dependent
                    // Try to get the actual user record instead
                    $stmt = $pdo->prepare("
                        SELECT 
                            u.id,
                            u.first_name,
                            u.middle_name,
                            u.last_name,
                            u.contact_no as phone,
                            u.address,
                            pp.sex,
                            pp.date_of_birth as dob,
                            COALESCE(p.philhealth_no, '') as philhealth_no,
                            u.created_at
                        FROM users u
                        INNER JOIN patient_profiles pp ON pp.patient_id = u.id
                        LEFT JOIN patients p ON p.created_by_user_id = u.id
                        WHERE u.id = ? AND u.role = 'patient'
                        LIMIT 1
                    ");
                    $stmt->execute([$patient['created_by_user_id']]);
                    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            }
        }

        if ($patient) {
            // Check if this is a dependent (has created_by_user_id and it's not the account owner's own record)
            $is_dependent = false;
            if (!empty($patient['created_by_user_id'])) {
                // Verify this is not the account owner's own record
                if (empty($patient['id']) || $patient['id'] != $patient['created_by_user_id']) {
                    // Check if there's a registered user with this created_by_user_id and same name
                    $stmt = $pdo->prepare("
                        SELECT id FROM users 
                        WHERE id = ? 
                          AND role = 'patient'
                          AND LOWER(TRIM(first_name)) = LOWER(TRIM(?))
                          AND LOWER(TRIM(last_name)) = LOWER(TRIM(?))
                    ");
                    $stmt->execute([
                        $patient['created_by_user_id'], 
                        $patient['first_name'] ?? '', 
                        $patient['last_name'] ?? ''
                    ]);
                    $is_owner_record = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Only mark as dependent if it's NOT the account owner's own record
                    $is_dependent = !$is_owner_record;
                }
            }
            
            // If it's a dependent, get the original information from dependents table
            if ($is_dependent && !empty($patient['created_by_user_id'])) {
                // Get dependent information from dependents table (signup data)
                // Match by parent user_id and dependent's name
                $dependent_info = null;
                
                // Try exact match first
                $stmt = $pdo->prepare("
                    SELECT d.*, 
                           CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as parent_name
                    FROM dependents d
                    INNER JOIN users u ON u.id = d.patient_id
                    WHERE d.patient_id = ? 
                      AND LOWER(TRIM(d.first_name)) = LOWER(TRIM(?))
                      AND LOWER(TRIM(d.last_name)) = LOWER(TRIM(?))
                    ORDER BY d.created_at DESC
                    LIMIT 1
                ");
                $stmt->execute([$patient['created_by_user_id'], $patient['first_name'], $patient['last_name']]);
                $dependent_info = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // If not found, try fuzzy matching (ignore spaces and case)
                if (!$dependent_info) {
                    $stmt = $pdo->prepare("
                        SELECT d.*, 
                               CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as parent_name
                        FROM dependents d
                        INNER JOIN users u ON u.id = d.patient_id
                        WHERE d.patient_id = ? 
                          AND LOWER(TRIM(REPLACE(d.first_name, ' ', ''))) = LOWER(TRIM(REPLACE(?, ' ', '')))
                          AND LOWER(TRIM(REPLACE(d.last_name, ' ', ''))) = LOWER(TRIM(REPLACE(?, ' ', '')))
                        ORDER BY d.created_at DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$patient['created_by_user_id'], $patient['first_name'], $patient['last_name']]);
                    $dependent_info = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                
                // If still not found, try matching by first name only (in case last name has variations)
                if (!$dependent_info && !empty($patient['first_name'])) {
                    $stmt = $pdo->prepare("
                        SELECT d.*, 
                               CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as parent_name
                        FROM dependents d
                        INNER JOIN users u ON u.id = d.patient_id
                        WHERE d.patient_id = ? 
                          AND LOWER(TRIM(d.first_name)) = LOWER(TRIM(?))
                        ORDER BY d.created_at DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$patient['created_by_user_id'], $patient['first_name']]);
                    $dependent_info = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                
                // Last resort: get the most recent dependent for this parent (if only one exists)
                if (!$dependent_info) {
                    $stmt = $pdo->prepare("
                        SELECT d.*, 
                               CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as parent_name
                        FROM dependents d
                        INNER JOIN users u ON u.id = d.patient_id
                        WHERE d.patient_id = ?
                        ORDER BY d.created_at DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$patient['created_by_user_id']]);
                    $all_dependents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    // Only use if there's exactly one dependent (to avoid wrong match)
                    if (count($all_dependents) === 1) {
                        $dependent_info = $all_dependents[0];
                    }
                }
                
                if ($dependent_info) {
                    // Override patient data with dependent signup information
                    // Use the original data from dependents table (signup form)
                    $patient['first_name'] = $dependent_info['first_name'];
                    $patient['middle_name'] = $dependent_info['middle_name'];
                    $patient['last_name'] = $dependent_info['last_name'];
                    // Convert sex format: 'Male'/'Female'/'Prefer not to say' to lowercase for consistency
                    $sex_lower = strtolower($dependent_info['sex'] ?? '');
                    if ($sex_lower === 'male' || $sex_lower === 'female') {
                        $patient['sex'] = $sex_lower;
                    } else {
                        $patient['sex'] = $dependent_info['sex'] ?? null;
                    }
                    $patient['dob'] = $dependent_info['date_of_birth'];
                    $patient['medical_conditions'] = $dependent_info['medical_conditions'];
                    $patient['relationship'] = $dependent_info['relationship'];
                    // Use age from dependents table, or calculate from date_of_birth
                    if (!empty($dependent_info['age'])) {
                        $patient['age'] = $dependent_info['age'];
                    } elseif (!empty($dependent_info['date_of_birth'])) {
                        $dob = new DateTime($dependent_info['date_of_birth']);
                        $today = new DateTime('today');
                        $patient['age'] = $dob->diff($today)->y;
                    }
                    // Get parent's contact and address for display
                    $stmt = $pdo->prepare("SELECT contact_no, address FROM users WHERE id = ?");
                    $stmt->execute([$patient['created_by_user_id']]);
                    $parent_info = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($parent_info) {
                        $patient['phone'] = $parent_info['contact_no'] ?? $patient['phone'] ?? '';
                        $patient['address'] = $parent_info['address'] ?? $patient['address'] ?? '';
                    }
                } else {
                    // If we still can't find the dependent, at least try to get parent's info for contact/address
                    $stmt = $pdo->prepare("SELECT contact_no, address FROM users WHERE id = ?");
                    $stmt->execute([$patient['created_by_user_id']]);
                    $parent_info = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($parent_info) {
                        $patient['phone'] = $parent_info['contact_no'] ?? $patient['phone'] ?? '';
                        $patient['address'] = $parent_info['address'] ?? $patient['address'] ?? '';
                    }
                }
            }
            
            if ($is_dependent) {
                // This is a dependent - use patient_id from patients table
                $dependent_patient_id = $patient['id'];
                
                // Get appointments for dependent using patient_id
                $stmt = $pdo->prepare("
                    SELECT a.*, 
                           CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) AS doctor_name
                    FROM appointments a
                    LEFT JOIN doctors d ON a.doctor_id = d.id
                    LEFT JOIN users du ON du.id = d.user_id
                    WHERE a.patient_id = ?
                    ORDER BY a.start_datetime DESC
                    LIMIT 10
                ");
                $stmt->execute([$dependent_patient_id]);
                $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Use dependent_patient_id for consultations and followups
                $user_id = $dependent_patient_id;
            } else {
                // Registered patient - use user_id
                $user_id = $patient['id'];
                
                // Get appointments - check both user_id and patient_id
                // For registered patients, appointments have user_id = user.id
                // Also check patient_id in case there are appointments linked via patients table
                $stmt = $pdo->prepare("
                    SELECT a.*, 
                           CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) AS doctor_name
                    FROM appointments a
                    LEFT JOIN doctors d ON a.doctor_id = d.id
                    LEFT JOIN users du ON du.id = d.user_id
                    WHERE (a.user_id = ? OR a.patient_id IN (SELECT id FROM patients WHERE created_by_user_id = ?))
                    ORDER BY a.start_datetime DESC
                    LIMIT 10
                ");
                $stmt->execute([$user_id, $user_id]);
                $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            try {
                $stmt = $pdo->prepare("
                    SELECT findings, diagnosis, notes, created_at
                    FROM doctor_consultations
                    WHERE patient_id = ?
                    ORDER BY created_at DESC
                    LIMIT 10
                ");
                $stmt->execute([$user_id]);
                $consultations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $consultations = [];
            }

            try {
                $stmt = $pdo->prepare("
                    SELECT followup_datetime, notes, created_at
                    FROM patient_followups
                    WHERE patient_id = ?
                    ORDER BY followup_datetime DESC
                    LIMIT 10
                ");
                $stmt->execute([$user_id]);
                $followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $followups = [];
            }

            // Prescriptions (per appointment)
            $prescriptions_list = [];
            if (!empty($appointments)) {
                $has_pi = false;
                try { $has_pi = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'medicine_name'")->rowCount() > 0; } catch (PDOException $e) {}
                foreach ($appointments as $appt) {
                    $aid = (int)($appt['id'] ?? 0);
                    if ($aid <= 0) continue;
                    $meds = '';
                    try {
                        if ($has_pi) {
                            $presc_stmt = $pdo->prepare("
                                SELECT GROUP_CONCAT(CONCAT(pi.medicine_name, ' - ', COALESCE(pi.dosage,''), ' - ', COALESCE(pi.frequency,''), ' - ', COALESCE(pi.duration,'')) SEPARATOR ', ') AS meds
                                FROM prescriptions p
                                LEFT JOIN prescription_items pi ON pi.prescription_id = p.id
                                WHERE p.appointment_id = ?
                            ");
                            $presc_stmt->execute([$aid]);
                            $row = $presc_stmt->fetch(PDO::FETCH_ASSOC);
                            $meds = $row['meds'] ?? '';
                        }
                        if ($meds === '' || $meds === null) {
                            $presc_stmt = $pdo->prepare("
                                SELECT GROUP_CONCAT(CONCAT(m.drug_name, ' - ', COALESCE(m.dosage,''), ' - ', COALESCE(m.frequency,'')) SEPARATOR ', ') AS meds
                                FROM prescriptions p
                                LEFT JOIN medications m ON m.prescription_id = p.id
                                WHERE p.appointment_id = ?
                            ");
                            $presc_stmt->execute([$aid]);
                            $row = $presc_stmt->fetch(PDO::FETCH_ASSOC);
                            $meds = $row['meds'] ?? '';
                        }
                    } catch (PDOException $e) { continue; }
                    if ($meds !== '' && $meds !== null) {
                        $prescriptions_list[] = [
                            'appointment_id' => $aid,
                            'visit_date' => $appt['start_datetime'],
                            'doctor_name' => $appt['doctor_name'] ?? '',
                            'meds' => $meds
                        ];
                    }
                }
            }

            // Lab test requests
            $lab_test_requests = [];
            $lab_test_results_by_request = [];
            try {
                $pid = $patient['id'];
                $table_lr = $pdo->query("SHOW TABLES LIKE 'lab_requests'");
                if ($table_lr->rowCount() > 0) {
                    if ($is_dependent) {
                        $stmt = $pdo->prepare("
                            SELECT lr.*, CONCAT(COALESCE(du.first_name,''), ' ', COALESCE(du.middle_name,''), ' ', COALESCE(du.last_name,'')) AS doctor_name
                            FROM lab_requests lr
                            LEFT JOIN doctors d ON d.id = lr.doctor_id
                            LEFT JOIN users du ON du.id = d.user_id
                            WHERE lr.patient_id = ?
                            ORDER BY lr.created_at DESC
                        ");
                        $stmt->execute([$pid]);
                    } else {
                        $stmt = $pdo->prepare("
                            SELECT lr.*, CONCAT(COALESCE(du.first_name,''), ' ', COALESCE(du.middle_name,''), ' ', COALESCE(du.last_name,'')) AS doctor_name
                            FROM lab_requests lr
                            LEFT JOIN doctors d ON d.id = lr.doctor_id
                            LEFT JOIN users du ON du.id = d.user_id
                            WHERE lr.patient_id = ? OR lr.patient_id IN (SELECT id FROM patients WHERE created_by_user_id = ?)
                            ORDER BY lr.created_at DESC
                        ");
                        $stmt->execute([$pid, $pid]);
                    }
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($rows)) {
                        $lr_ids = array_column($rows, 'id');
                        $ph = implode(',', array_fill(0, count($lr_ids), '?'));
                        $stmt2 = $pdo->prepare("SELECT lab_request_id, test_name FROM lab_request_tests WHERE lab_request_id IN ($ph) ORDER BY id");
                        $stmt2->execute($lr_ids);
                        $tests_by_lr = [];
                        while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                            $tests_by_lr[$r['lab_request_id']][] = $r['test_name'];
                        }
                        foreach ($rows as $lr) {
                            $lr['tests'] = $tests_by_lr[$lr['id']] ?? [];
                            $lr['test_name'] = implode(', ', $lr['tests']);
                            $lr['is_lab_request'] = true;
                            $lab_test_requests[] = $lr;
                        }
                    }
                } else {
                    $table_check = $pdo->query("SHOW TABLES LIKE 'lab_test_requests'");
                    if ($table_check->rowCount() > 0) {
                        if ($is_dependent) {
                            $stmt = $pdo->prepare("
                                SELECT ltr.*, CONCAT(COALESCE(du.first_name,''), ' ', COALESCE(du.middle_name,''), ' ', COALESCE(du.last_name,'')) AS doctor_name
                                FROM lab_test_requests ltr
                                LEFT JOIN doctors d ON d.id = ltr.doctor_id
                                LEFT JOIN users du ON du.id = d.user_id
                                WHERE ltr.patient_id = ?
                                ORDER BY ltr.created_at DESC
                            ");
                            $stmt->execute([$pid]);
                        } else {
                            $stmt = $pdo->prepare("
                                SELECT ltr.*, CONCAT(COALESCE(du.first_name,''), ' ', COALESCE(du.middle_name,''), ' ', COALESCE(du.last_name,'')) AS doctor_name
                                FROM lab_test_requests ltr
                                LEFT JOIN doctors d ON d.id = ltr.doctor_id
                                LEFT JOIN users du ON du.id = d.user_id
                                WHERE ltr.patient_id = ? OR ltr.patient_id IN (SELECT id FROM patients WHERE created_by_user_id = ?)
                                ORDER BY ltr.created_at DESC
                            ");
                            $stmt->execute([$pid, $pid]);
                        }
                        $lab_test_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($lab_test_requests as &$r) {
                            $r['test_name'] = $r['test_name'] ?? '';
                            $r['is_lab_request'] = false;
                        }
                        unset($r);
                    }
                }
            } catch (PDOException $e) {
                $lab_test_requests = [];
            }

            // Fetch uploaded lab test results (patient uploads) for display
            $lab_test_results_by_request = [];
            if (!empty($lab_test_requests)) {
                try {
                    $check_res = $pdo->query("SHOW TABLES LIKE 'lab_test_results'");
                    if ($check_res->rowCount() > 0) {
                        $lab_req_ids = [];
                        $lab_test_req_ids = [];
                        foreach ($lab_test_requests as $req) {
                            if (!empty($req['is_lab_request'])) {
                                $lab_req_ids[] = (int)$req['id'];
                            } else {
                                $lab_test_req_ids[] = (int)$req['id'];
                            }
                        }
                        $all_results = [];
                        if (!empty($lab_req_ids)) {
                            $ph = implode(',', array_fill(0, count($lab_req_ids), '?'));
                            $stmt = $pdo->prepare("SELECT * FROM lab_test_results WHERE lab_request_id IN ($ph) ORDER BY uploaded_at DESC");
                            $stmt->execute($lab_req_ids);
                            $all_results = array_merge($all_results, $stmt->fetchAll(PDO::FETCH_ASSOC));
                        }
                        if (!empty($lab_test_req_ids)) {
                            $ph = implode(',', array_fill(0, count($lab_test_req_ids), '?'));
                            $stmt = $pdo->prepare("SELECT * FROM lab_test_results WHERE lab_test_request_id IN ($ph) ORDER BY uploaded_at DESC");
                            $stmt->execute($lab_test_req_ids);
                            $all_results = array_merge($all_results, $stmt->fetchAll(PDO::FETCH_ASSOC));
                        }
                        foreach ($all_results as $res) {
                            $rid = isset($res['lab_request_id']) && (int)$res['lab_request_id'] > 0 ? (int)$res['lab_request_id'] : (int)$res['lab_test_request_id'];
                            if (!isset($lab_test_results_by_request[$rid])) {
                                $lab_test_results_by_request[$rid] = [];
                            }
                            $lab_test_results_by_request[$rid][] = $res;
                        }
                    }
                } catch (PDOException $e) {
                    // ignore
                }
            }

            // Deduplicate lab test requests so the same request appears only once (keep merged_request_ids for results)
            if (!empty($lab_test_requests)) {
                $by_id = [];
                foreach ($lab_test_requests as $req) {
                    $id = isset($req['id']) ? (int)$req['id'] : ('n' . count($by_id));
                    if (!isset($by_id[$id])) {
                        $req['merged_request_ids'] = [$id];
                        $by_id[$id] = $req;
                    }
                }
                $lab_test_requests = array_values($by_id);
                $seen_keys = [];
                $deduped = [];
                foreach ($lab_test_requests as $req) {
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
                        $deduped[$seen_keys[$key]]['merged_request_ids'] = array_merge($deduped[$seen_keys[$key]]['merged_request_ids'], $req['merged_request_ids'] ?? [$req['id']]);
                    } else {
                        $seen_keys[$key] = count($deduped);
                        $deduped[] = $req;
                    }
                }
                $lab_test_requests = array_values($deduped);
            }

            // Medical certificates
            $medical_certificates = [];
            try {
                $check_mc = $pdo->query("SHOW TABLES LIKE 'medical_certificates'");
                if ($check_mc->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        SELECT mc.*, CONCAT(COALESCE(du.first_name,''), ' ', COALESCE(du.middle_name,''), ' ', COALESCE(du.last_name,'')) AS doctor_name
                        FROM medical_certificates mc
                        LEFT JOIN doctors d ON mc.doctor_id = d.id
                        LEFT JOIN users du ON d.user_id = du.id
                        WHERE mc.patient_id = ?
                        ORDER BY mc.created_at DESC
                    ");
                    $stmt->execute([$patient['id']]);
                    $medical_certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (PDOException $e) {
                $medical_certificates = [];
            }

            // Referrals
            $referrals = [];
            try {
                $check_ref = $pdo->query("SHOW TABLES LIKE 'referrals'");
                if ($check_ref->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        SELECT r.*, CONCAT(COALESCE(du.first_name,''), ' ', COALESCE(du.middle_name,''), ' ', COALESCE(du.last_name,'')) AS doctor_name
                        FROM referrals r
                        LEFT JOIN doctors d ON r.doctor_id = d.id
                        LEFT JOIN users du ON d.user_id = du.id
                        WHERE r.patient_id = ?
                        ORDER BY r.created_at DESC
                    ");
                    $stmt->execute([$patient['id']]);
                    $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (PDOException $e) {
                $referrals = [];
            }
        }
    }
} catch (PDOException $e) {
    // fall back to placeholder data
    error_log("Patient record error: " . $e->getMessage());
}

if (!$patient) {
    $patient = $placeholderPatient;
    $appointments = $placeholderAppointments;
    $consultations = $placeholderConsultations;
    $followups = $placeholderFollowups;
    $prescriptions_list = [];
    $lab_test_requests = [];
    $lab_test_results_by_request = [];
    $medical_certificates = [];
    $referrals = [];
    $is_placeholder = true;
}
if (!isset($prescriptions_list)) { $prescriptions_list = []; }
if (!isset($lab_test_requests)) { $lab_test_requests = []; }
if (!isset($lab_test_results_by_request)) { $lab_test_results_by_request = []; }
if (!isset($medical_certificates)) { $medical_certificates = []; }
if (!isset($referrals)) { $referrals = []; }

$patientFullName = trim(($patient['first_name'] ?? '') . ' ' . ($patient['middle_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
$patientAge = 'N/A';
if (!empty($patient['dob'])) {
    $dob = new DateTime($patient['dob']);
    $today = new DateTime('today');
    $patientAge = $dob->diff($today)->y;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Profile</title>
    <link rel="stylesheet" href="assets/Style1.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .profile-wrapper {
            max-width: 1300px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .profile-card {
            background: #fff;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 10px 35px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }
        .profile-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4CAF50, #43A047);
            color: #fff;
            font-size: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .section-title {
            margin-bottom: 15px;
            color: #2E7D32;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 18px;
        }
        .info-tile {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px 18px;
        }
        .info-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #94a3b8;
        }
        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
            margin-top: 6px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table th, table td {
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        table th {
            font-size: 13px;
            text-transform: uppercase;
            color: #94a3b8;
        }
        .status-badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-approved { background:#e8f5e9; color:#2e7d32; }
        .status-pending { background:#fff3cd; color:#ad8400; }
        .status-completed { background:#e0f2fe; color:#0369a1; }
        .status-declined { background:#fee2e2; color:#b91c1c; }
        .btn-pdf-view, .btn-pdf-download {
            transition: opacity 0.2s, box-shadow 0.2s;
        }
        .btn-pdf-view:hover { opacity: 0.9; box-shadow: 0 2px 8px rgba(25,118,210,0.3); }
        .btn-pdf-download:hover { opacity: 0.9; box-shadow: 0 2px 8px rgba(46,125,50,0.3); }
    </style>
</head>
<body style="background:#f5f6fa;margin:0;">
    <div class="profile-wrapper">
            <div class="profile-card">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php 
                        $firstInitial = strtoupper(substr($patient['first_name'] ?? 'P', 0, 1));
                        $lastInitial = strtoupper(substr($patient['last_name'] ?? '', 0, 1));
                        echo $firstInitial . $lastInitial;
                        ?>
                    </div>
                    <div>
                        <h1 style="margin:0;"><?php echo htmlspecialchars(trim(($patient['first_name'] ?? '') . ' ' . ($patient['middle_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''))); ?></h1>
                        <p style="color:#64748b;margin:4px 0 0;">Patient ID: <?php echo $patient['id']; ?></p>
                    </div>
                </div>
                <div class="info-grid">
                    <div class="info-tile">
                        <div class="info-label">Sex</div>
                        <div class="info-value"><?php 
                            // Handle different sex formats
                            $sex_display = 'N/A';
                            if (!empty($patient['sex'])) {
                                $sex_lower = strtolower($patient['sex']);
                                if ($sex_lower === 'male' || $sex_lower === 'm') {
                                    $sex_display = 'M';
                                } elseif ($sex_lower === 'female' || $sex_lower === 'f') {
                                    $sex_display = 'F';
                                } else {
                                    $sex_display = strtoupper(substr($patient['sex'], 0, 1));
                                }
                            }
                            echo $sex_display;
                        ?></div>
                    </div>
                    <div class="info-tile">
                        <div class="info-label">Date of Birth</div>
                        <div class="info-value"><?php 
                            echo $patient['dob'] ? date('M d, Y', strtotime($patient['dob'])) : 'N/A';
                            // Use age from dependent info if available
                            $displayAge = $patientAge;
                            if (!empty($patient['age']) && is_numeric($patient['age'])) {
                                $displayAge = $patient['age'];
                            }
                            echo ' (' . (is_numeric($displayAge) ? $displayAge . ' yrs' : '—') . ')';
                        ?></div>
                    </div>
                    <div class="info-tile">
                        <div class="info-label">Contact</div>
                        <div class="info-value"><?php echo htmlspecialchars($patient['phone'] ?? 'N/A'); ?></div>
                    </div>
                    <?php if (!empty($patient['relationship'])): ?>
                    <div class="info-tile">
                        <div class="info-label">Relationship</div>
                        <div class="info-value"><?php echo htmlspecialchars($patient['relationship']); ?></div>
                    </div>
                    <?php else: ?>
                    <div class="info-tile">
                        <div class="info-label">PhilHealth No.</div>
                        <div class="info-value"><?php echo htmlspecialchars($patient['philhealth_no'] ?? 'N/A'); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($patient['medical_conditions'])): ?>
                <div class="info-tile" style="margin-top:20px;">
                    <div class="info-label">Medical Conditions</div>
                    <div class="info-value" style="line-height:1.5;">
                        <?php 
                        $conditions = $patient['medical_conditions'];
                        // If it's JSON, decode it
                        if (is_string($conditions) && (substr($conditions, 0, 1) === '[' || substr($conditions, 0, 1) === '{')) {
                            $conditions_array = json_decode($conditions, true);
                            if (is_array($conditions_array)) {
                                echo htmlspecialchars(implode(', ', $conditions_array));
                            } else {
                                echo htmlspecialchars($conditions);
                            }
                        } else {
                            echo htmlspecialchars($conditions);
                        }
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="info-tile" style="margin-top:20px;">
                    <div class="info-label">Address</div>
                    <div class="info-value" style="line-height:1.5;"><?php echo htmlspecialchars($patient['address'] ?? 'N/A'); ?></div>
                </div>
            </div>

            <div class="profile-card">
                <h2 class="section-title">Recent Appointments</h2>
                <?php if (empty($appointments)): ?>
                    <p style="color:#94a3b8;">No appointments recorded.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Doctor</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($appointments as $appt): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($appt['start_datetime'])); ?></td>
                                    <td><?php echo date('h:i A', strtotime($appt['start_datetime'])); ?></td>
                                    <td><?php echo htmlspecialchars($appt['doctor_name'] ?? 'Unassigned'); ?></td>
                                    <td><span class="status-badge status-<?php echo htmlspecialchars($appt['status']); ?>"><?php echo ucfirst($appt['status']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="profile-card">
                <h2 class="section-title">Consultation Notes</h2>
                <?php if (empty($consultations)): ?>
                    <p style="color:#94a3b8;">No consultation notes available yet.</p>
                <?php else: ?>
                    <?php foreach ($consultations as $item): ?>
                        <div class="info-tile" style="margin-bottom:12px;">
                            <div class="info-label">Recorded on <?php echo date('M d, Y h:i A', strtotime($item['created_at'])); ?></div>
                            <div class="info-value" style="margin-top:8px;">Findings: <?php echo nl2br(htmlspecialchars($item['findings'])); ?></div>
                            <div style="margin-top:6px;"><strong>Diagnosis:</strong> <?php echo nl2br(htmlspecialchars($item['diagnosis'])); ?></div>
                            <?php if (!empty($item['notes'])): ?>
                                <div style="margin-top:6px;"><strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($item['notes'])); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Prescriptions -->
            <div class="profile-card">
                <h2 class="section-title"><i class="fas fa-prescription-bottle-alt" style="color:#2E7D32; margin-right:8px;"></i>Prescriptions</h2>
                <?php if (empty($prescriptions_list)): ?>
                    <p style="color:#94a3b8;">No prescriptions recorded.</p>
                <?php else: ?>
                    <?php foreach ($prescriptions_list as $presc): ?>
                        <div class="info-tile" style="margin-bottom:12px;">
                            <div class="info-label"><?php echo date('M d, Y', strtotime($presc['visit_date'])); ?> — <?php echo htmlspecialchars($presc['doctor_name']); ?></div>
                            <div class="info-value" style="margin-top:8px;"><?php echo nl2br(htmlspecialchars($presc['meds'])); ?></div>
                            <p style="margin-top:10px;">
                                <a href="generate_prescription_pdf.php?appointment_id=<?php echo (int)$presc['appointment_id']; ?>&mode=view" class="btn-pdf-view" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;padding:8px 14px;border-radius:999px;font-size:13px;font-weight:500;background:#e3f2fd;color:#1976d2;border:1px solid #1976d2;"><i class="fas fa-eye"></i> View PDF</a>
                                <a href="generate_prescription_pdf.php?appointment_id=<?php echo (int)$presc['appointment_id']; ?>" target="_blank" class="btn-pdf-download" style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;text-decoration:none;padding:8px 14px;border-radius:999px;font-size:13px;font-weight:500;background:#e8f5e9;color:#2e7d32;border:1px solid #2e7d32;"><i class="fas fa-download"></i> Download</a>
                            </p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Lab Test Requests -->
            <div class="profile-card">
                <h2 class="section-title"><i class="fas fa-vial" style="color:#1565C0; margin-right:8px;"></i>Lab Test Requests</h2>
                <?php if (empty($lab_test_requests)): ?>
                    <p style="color:#94a3b8;">No lab test requests recorded.</p>
                <?php else: ?>
                    <?php foreach ($lab_test_requests as $lr): 
                        $lr_id = $lr['id'] ?? 0;
                        $appt_id = $lr['appointment_id'] ?? 0;
                        $pdf_appointment_id = $appt_id;
                        if (empty($pdf_appointment_id) && !empty($lr['lab_request_id'])) { $pdf_appointment_id = 0; }
                        $result_ids = array_merge([$lr_id], $lr['merged_request_ids'] ?? []);
                        $result_ids = array_unique(array_filter($result_ids));
                        $uploaded_results = [];
                        foreach ($result_ids as $rid) {
                            if (!empty($lab_test_results_by_request[$rid])) {
                                $uploaded_results = array_merge($uploaded_results, $lab_test_results_by_request[$rid]);
                            }
                        }
                    ?>
                        <div class="info-tile" style="margin-bottom:12px;">
                            <div class="info-label"><?php echo !empty($lr['created_at']) ? date('M d, Y', strtotime($lr['created_at'])) : '—'; ?> — <?php echo htmlspecialchars($lr['doctor_name'] ?? ''); ?></div>
                            <div class="info-value" style="margin-top:8px;"><?php echo htmlspecialchars($lr['test_name'] ?? 'Lab request'); ?></div>
                            <?php if (!empty($uploaded_results)): ?>
                            <div style="margin-top:12px; padding-top:12px; border-top:1px solid #e2e8f0;">
                                <strong style="color:#1565C0; font-size:13px;"><i class="fas fa-file-medical"></i> Uploaded Results:</strong>
                                <?php foreach ($uploaded_results as $res): ?>
                                <div style="margin-top:8px; padding:10px; background:#f8fafc; border-radius:6px; border-left:3px solid #4CAF50; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                                    <div>
                                        <i class="fas fa-file-pdf" style="color:#d32f2f; margin-right:6px;"></i>
                                        <a href="<?php echo htmlspecialchars($res['file_path']); ?>" target="_blank" style="color:#1976D2; text-decoration:none; font-weight:500;"><?php echo htmlspecialchars($res['file_name']); ?></a>
                                        <span style="color:#64748b; font-size:12px; margin-left:8px;">(<?php echo $res['file_size'] ? number_format($res['file_size'] / 1024, 2) . ' KB' : 'N/A'; ?>)</span>
                                        <p style="margin:4px 0 0 0; color:#94a3b8; font-size:11px;">Uploaded on <?php echo date('M d, Y h:i A', strtotime($res['uploaded_at'])); ?></p>
                                    </div>
                                    <a href="<?php echo htmlspecialchars($res['file_path']); ?>" target="_blank" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:6px;font-size:12px;text-decoration:none;background:#e8f5e9;color:#2e7d32;border:1px solid #4CAF50;"><i class="fas fa-eye"></i> View</a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($pdf_appointment_id > 0): ?>
                            <p style="margin-top:10px;">
                                <a href="generate_lab_test_request_pdf.php?appointment_id=<?php echo (int)$pdf_appointment_id; ?>&mode=view" class="btn-pdf-view" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;padding:8px 14px;border-radius:999px;font-size:13px;font-weight:500;background:#e3f2fd;color:#1976d2;border:1px solid #1976d2;"><i class="fas fa-eye"></i> View PDF</a>
                                <a href="generate_lab_test_request_pdf.php?appointment_id=<?php echo (int)$pdf_appointment_id; ?>" target="_blank" class="btn-pdf-download" style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;text-decoration:none;padding:8px 14px;border-radius:999px;font-size:13px;font-weight:500;background:#e8f5e9;color:#2e7d32;border:1px solid #2e7d32;"><i class="fas fa-download"></i> Download</a>
                            </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Medical Certificates -->
            <div class="profile-card">
                <h2 class="section-title"><i class="fas fa-certificate" style="color:#F57C00; margin-right:8px;"></i>Medical Certificates</h2>
                <?php if (empty($medical_certificates)): ?>
                    <p style="color:#94a3b8;">No medical certificates recorded.</p>
                <?php else: ?>
                    <?php foreach ($medical_certificates as $cert): 
                        $cert_type = $cert['certificate_type'] ?? $cert['certificate_subtype'] ?? 'Medical Certificate';
                        $issued = !empty($cert['issued_date']) ? date('M d, Y', strtotime($cert['issued_date'])) : '—';
                        $exp = !empty($cert['expiration_date']) ? date('M d, Y', strtotime($cert['expiration_date'])) : '—';
                    ?>
                        <div class="info-tile" style="margin-bottom:12px;">
                            <div class="info-label"><?php echo htmlspecialchars($cert_type); ?> — Issued: <?php echo $issued; ?> — Expires: <?php echo $exp; ?></div>
                            <div style="margin-top:6px;color:#64748b;"><?php echo htmlspecialchars($cert['doctor_name'] ?? ''); ?></div>
                            <p style="margin-top:10px;">
                                <a href="generate_medical_certificate_pdf.php?certificate_id=<?php echo (int)$cert['id']; ?>&mode=view" class="btn-pdf-view" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;padding:8px 14px;border-radius:999px;font-size:13px;font-weight:500;background:#e3f2fd;color:#1976d2;border:1px solid #1976d2;"><i class="fas fa-eye"></i> View PDF</a>
                                <a href="generate_medical_certificate_pdf.php?certificate_id=<?php echo (int)$cert['id']; ?>" target="_blank" class="btn-pdf-download" style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;text-decoration:none;padding:8px 14px;border-radius:999px;font-size:13px;font-weight:500;background:#e8f5e9;color:#2e7d32;border:1px solid #2e7d32;"><i class="fas fa-download"></i> Download</a>
                            </p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Referrals to Other Hospitals -->
            <div class="profile-card">
                <h2 class="section-title"><i class="fas fa-hospital" style="color:#7B1FA2; margin-right:8px;"></i>Referrals to Other Hospitals</h2>
                <?php if (empty($referrals)): ?>
                    <p style="color:#94a3b8;">No referrals recorded.</p>
                <?php else: ?>
                    <?php foreach ($referrals as $ref): 
                        $facility = $ref['referred_hospital'] ?? $ref['facility_name'] ?? $ref['hospital_name'] ?? 'Other facility';
                        $ref_date = !empty($ref['created_at']) ? date('M d, Y', strtotime($ref['created_at'])) : '—';
                    ?>
                        <div class="info-tile" style="margin-bottom:12px;">
                            <div class="info-label"><?php echo htmlspecialchars($facility); ?> — <?php echo $ref_date; ?></div>
                            <div style="margin-top:6px;color:#64748b;"><?php echo htmlspecialchars($ref['doctor_name'] ?? ''); ?><?php if (!empty($ref['reason_for_referral'])): ?> — <?php echo htmlspecialchars($ref['reason_for_referral']); ?><?php endif; ?></div>
                            <p style="margin-top:10px;">
                                <a href="generate_referral_pdf.php?referral_id=<?php echo (int)$ref['id']; ?>&mode=view" class="btn-pdf-view" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;padding:8px 14px;border-radius:999px;font-size:13px;font-weight:500;background:#e3f2fd;color:#1976d2;border:1px solid #1976d2;"><i class="fas fa-eye"></i> View PDF</a>
                                <a href="generate_referral_pdf.php?referral_id=<?php echo (int)$ref['id']; ?>" target="_blank" class="btn-pdf-download" style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;text-decoration:none;padding:8px 14px;border-radius:999px;font-size:13px;font-weight:500;background:#e8f5e9;color:#2e7d32;border:1px solid #2e7d32;"><i class="fas fa-download"></i> Download</a>
                            </p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
    </div>
</body>
</html>

