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

$dosageOptions = ['125 mg','250 mg','300 mg','500 mg','800 mg','1 g','5 mL','10 mL','15 mL','30 mL','1 tablet','½ tablet','1 capsule'];
// Frequency = times per day only (plain language)
$frequencyOptions = ['Once a day','Twice a day','Three times a day','Four times a day','Every other day','As needed (PRN)'];
// Timing of intake = when to take (patient-friendly)
$timingOfIntakeOptions = ['Before meals','After meals','With meals','At bedtime','In the morning','In the evening'];
$durationOptions = ['1 day','3 days','5 days','7 days','10 days','14 days','30 days','Until symptoms improve'];
$categoryOptions = ['Analgesic','Antibiotic','Antihistamine','Antipyretic','Vitamin','Supplement','Other'];
// Medicine Form / Type: how the medicine should be taken or applied
$medicineFormOptions = ['Tablet','Capsule','Syrup','Suspension','Drops','Injection','Cream','Ointment','Gel','Patch','Inhaler','Others'];

// Predefined laboratory test packages (package name => array of test names)
$labTestPackages = [
    'Blood Chemistry Package' => [
        'Complete Blood Count (CBC)',
        'Fasting Blood Sugar (FBS)',
        'Random Blood Sugar (RBS)',
        'Cholesterol',
        'Triglycerides',
        'Uric Acid',
        'Creatinine',
        'Blood Urea Nitrogen (BUN)',
        'SGPT (ALT)',
        'SGOT (AST)',
    ],
    'Liver Function Test (LFT) Package' => [
        'SGPT (ALT)',
        'SGOT (AST)',
        'Alkaline Phosphatase',
        'Total Bilirubin',
        'Direct Bilirubin',
        'Indirect Bilirubin',
        'Albumin',
        'Total Protein',
    ],
    'Kidney / Renal Function Package' => [
        'Creatinine',
        'Blood Urea Nitrogen (BUN)',
        'Uric Acid',
        'Sodium',
        'Potassium',
        'Chloride',
    ],
    'Diabetes Package' => [
        'Fasting Blood Sugar (FBS)',
        'HbA1c',
        'Random Blood Sugar (RBS)',
        'Urinalysis (Sugar)',
    ],
    'Urinalysis Package' => [
        'Routine Urinalysis',
        'Microscopic Examination',
    ],
    'Fecalysis Package' => [
        'Routine Fecalysis',
        'Occult Blood Test',
    ],
    'Imaging / X-Ray Package' => [
        'Chest X-Ray',
        'Skull X-Ray',
        'Spine X-Ray',
        'Abdominal X-Ray',
        'Extremity X-Ray',
    ],
    'Ultrasound Package' => [
        'Ultrasound – Whole Abdomen',
        'Ultrasound – Upper Abdomen',
        'Ultrasound – Lower Abdomen',
        'Pelvic Ultrasound',
        'Transvaginal Ultrasound',
        'Prostate Ultrasound',
        'Thyroid Ultrasound',
        'Breast Ultrasound',
    ],
    'Cardiac Package' => [
        'ECG (Electrocardiogram)',
        '2D Echo (if applicable)',
        'Lipid Profile',
    ],
    'Infectious Disease Package' => [
        'Dengue NS1',
        'Dengue IgG / IgM',
        'Hepatitis B Screening',
        'Hepatitis A Screening',
        'HIV Screening',
        'COVID-19 Antigen / RT-PCR',
    ],
    'Pre-Employment / Medical Clearance Package' => [
        'Complete Blood Count (CBC)',
        'Urinalysis',
        'Fecalysis',
        'Chest X-Ray',
        'Physical Examination',
        'Drug Test',
    ],
    'Drug Test Package' => [
        'Urine Drug Test',
        'Blood Drug Test',
    ],
];

// Others / Individual tests (manual selection outside packages)
$labTestIndividualTests = [
    'Pregnancy Test',
    'Prostate-Specific Antigen (PSA)',
    'Pap Smear',
    'Erythrocyte Sedimentation Rate (ESR)',
    'C-Reactive Protein (CRP)',
    'Blood Typing',
];

// Combined list for autocomplete (all unique test names from packages + individual)
$commonLabTests = array_values(array_unique(array_merge(
    array_merge(...array_values($labTestPackages)),
    $labTestIndividualTests
)));
sort($commonLabTests);

// Predefined laboratories near Payatas
$payatasLaboratories = [
    'Health Center Laboratory',
    'Quezon City General Hospital',
    'East Avenue Medical Center',
    'Novaliches District Hospital',
    'Payatas Health Center',
    'Commonwealth Health Center',
    'Lung Center of the Philippines',
    'National Kidney and Transplant Institute',
    'Philippine Heart Center',
    'Philippine General Hospital',
    'St. Luke\'s Medical Center - Quezon City',
    'St. Luke\'s Medical Center - Global City',
    'Makati Medical Center',
    'The Medical City',
    'Metro Manila Diagnostic Center',
    'Hi-Precision Diagnostics',
    'MedConsult Clinic Laboratory',
    'Healthway Medical',
    'QualiMed Clinic',
    'Family Care Diagnostic Center'
];

// Predefined hospitals and facilities for referrals with addresses and contacts
$referralHospitals = [
    [
        'name' => 'Rosario Maclang Bautista General Hospital',
        'address' => 'Batasan Road, Quezon City',
        'contact' => '(02) 8931-2000'
    ],
    [
        'name' => 'Quezon City General Hospital (QCGH)',
        'address' => 'Seminary Road, Project 8, Quezon City',
        'contact' => '(02) 8630-800'
    ],
    [
        'name' => 'Quirino Memorial Medical Center',
        'address' => 'Katipunan Road corner J.P. Rizal Street, Project 4, Quezon City',
        'contact' => '(02) 8421-2250'
    ],
    [
        'name' => 'East Avenue Medical Center',
        'address' => 'East Avenue, Diliman, Quezon City',
        'contact' => '(02) 8928-0611'
    ],
    [
        'name' => 'Novaliches District Hospital',
        'address' => '793 Quirino Highway, Novaliches, Quezon City',
        'contact' => '(02) 938-7890'
    ],
    [
        'name' => 'Payatas Health Center',
        'address' => 'Payatas Road, Barangay Payatas, Quezon City',
        'contact' => '(02) 8931-5000'
    ],
    [
        'name' => 'Commonwealth Health Center',
        'address' => 'Commonwealth Avenue, Quezon City',
        'contact' => '(02) 8931-3000'
    ],
    [
        'name' => 'Litex Health Center',
        'address' => 'Litex Road, Barangay Payatas, Quezon City',
        'contact' => '(02) 8931-4000'
    ],
    [
        'name' => 'Lung Center of the Philippines',
        'address' => 'Quezon Avenue, Barangay Central, Diliman, Quezon City',
        'contact' => '(02) 8924-6101'
    ],
    [
        'name' => 'National Kidney and Transplant Institute (NKTI)',
        'address' => 'East Avenue, Diliman, Quezon City',
        'contact' => '(02) 981-0300'
    ],
    [
        'name' => 'Philippine Heart Center',
        'address' => 'East Avenue, Diliman, Quezon City',
        'contact' => '(02) 8925-2401'
    ],
    [
        'name' => 'St. Luke\'s Medical Center - Quezon City',
        'address' => '279 E. Rodriguez Sr. Boulevard, Quezon City',
        'contact' => '(02) 8723-0101'
    ],
    [
        'name' => 'St. Luke\'s Medical Center - Global City',
        'address' => '32nd Street, Bonifacio Global City, Taguig',
        'contact' => '(02) 8789-7700'
    ],
    [
        'name' => 'Makati Medical Center',
        'address' => 'No. 2 Amorsolo Street, Legaspi Village, Makati City',
        'contact' => '(02) 8888-8999'
    ],
    [
        'name' => 'The Medical City',
        'address' => 'Ortigas Avenue, Pasig City, Metro Manila',
        'contact' => '(02) 8988-7000'
    ],
    [
        'name' => 'Philippine General Hospital (UP-PGH)',
        'address' => 'Taft Avenue, Ermita, Manila',
        'contact' => '(02) 8554-8400'
    ],
    [
        'name' => 'Manila Doctors Hospital',
        'address' => '667 United Nations Avenue, Ermita, Manila',
        'contact' => '(02) 8525-0831'
    ],
    [
        'name' => 'Cardinal Santos Medical Center',
        'address' => 'Wilson Street, Greenhills, San Juan',
        'contact' => '(02) 8727-0001'
    ],
    [
        'name' => 'Asian Hospital and Medical Center',
        'address' => '2205 Civic Drive, Filinvest City, Alabang, Muntinlupa',
        'contact' => '(02) 8771-9000'
    ],
    [
        'name' => 'Metro Manila Diagnostic Center',
        'address' => 'Quezon City',
        'contact' => '(02) 8921-1234'
    ],
    [
        'name' => 'Hi-Precision Diagnostics',
        'address' => 'Multiple locations in Quezon City',
        'contact' => '(02) 8888-8888'
    ],
    [
        'name' => 'Healthway Medical',
        'address' => 'Multiple locations in Quezon City',
        'contact' => '(02) 8888-7777'
    ],
    [
        'name' => 'QualiMed Clinic',
        'address' => 'Multiple locations in Quezon City',
        'contact' => '(02) 8888-6666'
    ],
    [
        'name' => 'Family Care Diagnostic Center',
        'address' => 'Quezon City',
        'contact' => '(02) 8888-5555'
    ],
    [
        'name' => 'Other Hospital',
        'address' => '',
        'contact' => ''
    ]
];

// Predefined reasons for referral
$referralReasons = [
    'Requires specialist consultation',
    'Requires advanced diagnostic tests',
    'Requires imaging (X-ray, CT scan, MRI)',
    'Surgical evaluation required',
    'Management of chronic condition',
    'Emergency referral',
    'Other'
];

// Predefined clinical notes options
$clinicalNotesOptions = [
    'Persistent symptoms despite treatment',
    'Abnormal laboratory results',
    'Worsening of existing condition',
    'Requires further evaluation',
    'Pre-operative assessment',
    'Other'
];

$placeholderPatient = [
    'id' => $patient_id > 0 ? $patient_id : 999,
    'first_name' => 'Juan',
    'last_name' => 'Dela Cruz',
    'sex' => 'M',
    'dob' => '1992-01-05',
    'phone' => '+63 912 345 6789',
    'last_visit' => '2025-04-13',
    'complaint' => 'Recurring headache with mild dizziness.',
    'temperature' => '36.9',
    'blood_pressure' => '120/80',
    'pulse' => '78'
];

$placeholderAppointments = [
    [
        'id' => 501,
        'start_datetime' => '2025-04-14 09:00:00',
        'status' => 'pending'
    ],
    [
        'id' => 502,
        'start_datetime' => '2025-04-20 10:30:00',
        'status' => 'completed'
    ]
];

$patient = null;
$appointments = [];
$is_placeholder = false;
$lab_test_requests = [];
$lab_test_results = [];

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
                u.created_at
            FROM users u
            INNER JOIN patient_profiles pp ON pp.patient_id = u.id
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
                            u.created_at
                        FROM users u
                        INNER JOIN patient_profiles pp ON pp.patient_id = u.id
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
                    // Get parent's contact for display
                    $stmt = $pdo->prepare("SELECT contact_no FROM users WHERE id = ?");
                    $stmt->execute([$patient['created_by_user_id']]);
                    $parent_info = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($parent_info) {
                        $patient['phone'] = $parent_info['contact_no'] ?? $patient['phone'] ?? '';
                    }
                } else {
                    // If we still can't find the dependent, at least try to get parent's info for contact
                    $stmt = $pdo->prepare("SELECT contact_no FROM users WHERE id = ?");
                    $stmt->execute([$patient['created_by_user_id']]);
                    $parent_info = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($parent_info) {
                        $patient['phone'] = $parent_info['contact_no'] ?? $patient['phone'] ?? '';
                    }
                }
            }
            
            // Use the doctor_id we already have
            $actual_doctor_id = $doctor_id;
            
            if ($actual_doctor_id) {
                if ($is_dependent) {
                    // This is a dependent - use patient_id from patients table
                    $dependent_patient_id = $patient['id'];
                    
                    // Get appointments for dependent using patient_id
                    $stmt = $pdo->prepare("
                        SELECT id, start_datetime, status
                        FROM appointments
                        WHERE patient_id = ?
                          AND doctor_id = ?
                        ORDER BY start_datetime DESC
                    ");
                    $stmt->execute([$dependent_patient_id, $actual_doctor_id]);
                    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    // Registered patient - use user_id
                    $user_id = $patient['id'];
                    
                    // Get appointments - check both user_id and patient_id
                    $stmt = $pdo->prepare("
                        SELECT id, start_datetime, status
                        FROM appointments
                        WHERE (user_id = ? OR patient_id IN (SELECT id FROM patients WHERE created_by_user_id = ?))
                          AND doctor_id = ?
                        ORDER BY start_datetime DESC
                    ");
                    $stmt->execute([$user_id, $user_id, $actual_doctor_id]);
                    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }
    }
} catch (PDOException $e) {
    // silently fall back to placeholder data
    error_log("Consultation error: " . $e->getMessage());
}

if (!$patient) {
    $patient = $placeholderPatient;
    $appointments = $placeholderAppointments;
    $is_placeholder = true;
}

$patient['id'] = $patient['id'] ?? ($patient_id > 0 ? $patient_id : 999);

// Get lab requests (one request = one slip with multiple tests) and results for this patient
$lab_test_requests = [];
$lab_test_results = [];
if ($patient_id > 0 && !$is_placeholder) {
    try {
        $table_lr = $pdo->query("SHOW TABLES LIKE 'lab_requests'");
        if ($table_lr->rowCount() > 0) {
            // New schema: lab_requests + lab_request_tests (include both user id and patients.id for same person)
            $stmt = $pdo->prepare("
                SELECT lr.*,
                       CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
                FROM lab_requests lr
                LEFT JOIN doctors d ON d.id = lr.doctor_id
                LEFT JOIN users du ON du.id = d.user_id
                WHERE lr.patient_id = ? OR lr.patient_id IN (SELECT id FROM patients WHERE created_by_user_id = ?)
                ORDER BY lr.created_at DESC
            ");
            $stmt->execute([$patient_id, $patient_id]);
            $lab_requests_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $request_ids = array_column($lab_requests_rows, 'id');
            if (!empty($request_ids)) {
                $placeholders = implode(',', array_fill(0, count($request_ids), '?'));
                $stmt = $pdo->prepare("SELECT lab_request_id, test_name FROM lab_request_tests WHERE lab_request_id IN ($placeholders) ORDER BY id");
                $stmt->execute($request_ids);
                $tests_by_request = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $tests_by_request[$row['lab_request_id']][] = $row['test_name'];
                }
                foreach ($lab_requests_rows as $lr) {
                    $lr['tests'] = $tests_by_request[$lr['id']] ?? [];
                    $lr['test_name'] = implode(', ', $lr['tests']); // for display compatibility
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
        // Fallback: legacy lab_test_requests (one row per test)
        if (empty($lab_test_requests)) {
            $table_check = $pdo->query("SHOW TABLES LIKE 'lab_test_requests'");
            if ($table_check->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    SELECT ltr.*,
                           CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
                    FROM lab_test_requests ltr
                    LEFT JOIN doctors d ON d.id = ltr.doctor_id
                    LEFT JOIN users du ON du.id = d.user_id
                    WHERE ltr.patient_id = ? OR ltr.patient_id IN (SELECT id FROM patients WHERE created_by_user_id = ?)
                    ORDER BY ltr.created_at DESC
                ");
                $stmt->execute([$patient_id, $patient_id]);
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
    // Deduplicate so the same request appears only once (same appointment + same tests = one entry)
    if (!empty($lab_test_requests)) {
        $by_id = [];
        foreach ($lab_test_requests as $req) {
            $id = isset($req['id']) ? (int)$req['id'] : ('n' . count($by_id));
            if (!isset($by_id[$id])) {
                $by_id[$id] = $req;
            }
        }
        $lab_test_requests = array_values($by_id);
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
}
$patientFullName = trim(($patient['first_name'] ?? '') . ' ' . ($patient['middle_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
$patientAge = 'N/A';
if (!empty($patient['dob'])) {
    $dob = new DateTime($patient['dob']);
    $today = new DateTime('today');
    $patientAge = $dob->diff($today)->y;
}

// Get last medical record information (aligned with patient's My Record page)
$lastVisit = '';
$lastDoctor = '';
$lastDiagnosis = '';
$lastPrescription = '';

if ($patient && !empty($patient['id'])) {
    // Check if this is a dependent
    $is_dependent_record = !empty($patient['created_by_user_id']);
    
    if ($is_dependent_record) {
        // For dependents, use patient_id from patients table
        $dependent_patient_id = $patient['id'];
        
        // Get the most recent completed/approved appointment with diagnosis and prescription
        $stmt = $pdo->prepare('
            SELECT a.*, 
                   CONCAT(COALESCE(du.first_name, ""), " ", COALESCE(du.middle_name, ""), " ", COALESCE(du.last_name, "")) as doctor_name,
                   a.start_datetime as visit_date, 
                   COALESCE(a.diagnosis, a.notes) as diagnosis
            FROM appointments a 
            LEFT JOIN doctors d ON d.id = a.doctor_id 
            LEFT JOIN users du ON du.id = d.user_id
            WHERE a.patient_id = ?
              AND a.status IN ("approved", "completed")
            ORDER BY a.start_datetime DESC
            LIMIT 1
        ');
        $stmt->execute([$dependent_patient_id]);
        $lastRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // For registered patients, use user_id
        $user_id = $patient['id'];
        
        // Get the most recent completed/approved appointment with diagnosis and prescription
        $stmt = $pdo->prepare('
            SELECT a.*, 
                   CONCAT(COALESCE(du.first_name, ""), " ", COALESCE(du.middle_name, ""), " ", COALESCE(du.last_name, "")) as doctor_name,
                   a.start_datetime as visit_date, 
                   COALESCE(a.diagnosis, a.notes) as diagnosis
            FROM appointments a 
            LEFT JOIN doctors d ON d.id = a.doctor_id 
            LEFT JOIN users du ON du.id = d.user_id
            WHERE (a.user_id = ? OR a.patient_id IN (SELECT id FROM patients WHERE created_by_user_id = ?))
              AND a.status IN ("approved", "completed")
            ORDER BY a.start_datetime DESC
            LIMIT 1
        ');
        $stmt->execute([$user_id, $user_id]);
        $lastRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($lastRecord) {
        $lastVisit = $lastRecord['visit_date'] ? date('F j, Y', strtotime($lastRecord['visit_date'])) : '—';
        $lastDoctor = trim($lastRecord['doctor_name']) ?: 'Dr. TBA';
        $lastDiagnosis = $lastRecord['diagnosis'] ?: 'General Check-up';
        
        // Get prescription for this appointment
        if (!empty($lastRecord['id'])) {
            $presc_stmt = $pdo->prepare('
                SELECT GROUP_CONCAT(CONCAT(m.drug_name, " - ", COALESCE(m.dosage, ""), " - ", COALESCE(m.frequency, "")) SEPARATOR ", ") as meds
                FROM prescriptions p
                LEFT JOIN medications m ON m.prescription_id = p.id
                WHERE p.appointment_id = ?
            ');
            $presc_stmt->execute([$lastRecord['id']]);
            $presc = $presc_stmt->fetch(PDO::FETCH_ASSOC);
            if ($presc && !empty($presc['meds'])) {
                $lastPrescription = $presc['meds'];
            } else {
                $lastPrescription = 'No prescription given';
            }
        }
    }
}

$lastVisitDisplay = $lastVisit ?: '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultation Workspace</title>
    <link rel="stylesheet" href="assets/Style1.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        body { background:#f5f6fa; margin:0; overflow-x: hidden; overflow-y: auto !important; min-height: 100vh; }
        html { overflow-x: hidden; overflow-y: auto !important; }
        .consult-wrapper { max-width: 95%; width: 95%; margin: 30px auto; padding: 0 15px 100px; min-height: calc(100vh - 60px); }
        .consult-card {
            background: #fff;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 10px 35px rgba(0,0,0,0.08);
            margin-bottom: 24px;
            width: 100%;
            box-sizing: border-box;
        }
        .header-flex { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
        .patient-summary { display:flex; align-items:center; gap:16px; }
        .patient-summary .avatar {
            width:60px; height:60px; border-radius:50%;
            background:linear-gradient(135deg,#4CAF50,#43A047);
            color:#fff; font-size:24px; display:flex; align-items:center; justify-content:center;
        }
        /* Action Buttons - Matching Pharmacist Inventory Style */
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

        .btn-approve { 
            background: #e8f5e9; 
            color: #1b5e20; 
            border: 1px solid rgba(27, 94, 32, 0.2); 
        }
        .btn-approve:hover { 
            background: #c8e6c9; 
            color: #0f4213;
        }

        .btn-decline { 
            background: #ffebee; 
            color: #d32f2f; 
            border: 1px solid rgba(211, 47, 47, 0.2); 
        }
        .btn-decline:hover { 
            background: #ffcdd2; 
            color: #c62828;
        }

        .btn-complete { 
            background: linear-gradient(135deg, #4CAF50 0%, #388E3C 100%); 
            color: white; 
            border: none; 
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
        }
        .btn-complete:hover { 
            background: linear-gradient(135deg, #388E3C 0%, #2E7D32 100%); 
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.4);
        }

        .btn-remove-test { 
            background: linear-gradient(135deg, #f44336 0%, #d32f2f 100%); 
            color: white; 
            border: none; 
            box-shadow: 0 4px 15px rgba(244, 67, 54, 0.3);
        }
        .btn-remove-test:hover { 
            background: linear-gradient(135deg, #d32f2f 0%, #c62828 100%); 
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(244, 67, 54, 0.4);
        }

        /* View Profile button - Green style */
        .btn-view-profile {
            background: #e8f5e9;
            color: #1b5e20;
            border: 1px solid rgba(27, 94, 32, 0.2);
            padding: 12px 16px;
            min-width: auto;
            width: auto;
            box-sizing: border-box;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .btn-view-profile:hover {
            background: #c8e6c9;
            color: #0f4213;
        }

        /* Refresh button - Blue style */
        .btn-refresh {
            background: #e3f2fd;
            color: #1976d2;
            border: 1px solid rgba(25, 118, 210, 0.2);
        }
        .btn-refresh:hover {
            background: #bbdefb;
            color: #1565c0;
        }
        .form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:20px; }
        textarea.form-control { min-height:120px; }
        .table-mini { width:100%; border-collapse:collapse; margin-top:12px; }
        .table-mini th, .table-mini td { border-bottom:1px solid #e2e8f0; padding:10px 8px; text-align:left; }
        .status-pill { padding:3px 10px; border-radius:999px; font-size:12px; font-weight:600; }
        .pill-pending { background:#fff3cd; color:#854d0e; }
        .pill-approved { background:#e8f5e9; color:#1b5e20; }
        .pill-completed { background:#e0f2fe; color:#0f172a; }
        .pill-declined { background:#fee2e2; color:#b91c1c; }
        .dynamic-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; margin-bottom:15px; }
        .dynamic-row > div {
            display: flex;
            flex-direction: column;
        }
        .medicine-autocomplete-dropdown { 
            max-height: 300px; 
            overflow-y: auto; 
            overflow-x: hidden;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            margin-top: 4px;
            z-index: 9999;
            display: none;
        }
        .medicine-autocomplete-dropdown.is-open {
            display: block !important;
        }
        .medicine-autocomplete-wrapper {
            position: relative;
            overflow: visible;
        }
        .medicine-autocomplete-dropdown .dropdown-item {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
        }
        .medicine-autocomplete-dropdown .dropdown-item:hover,
        .medicine-autocomplete-dropdown .dropdown-item.selected {
            background: #e8f5e9;
        }
        .medicine-autocomplete-dropdown .dropdown-item:last-child {
            border-bottom: none;
        }
        .external-prescription-row { border-left: 4px solid #2E7D32; background: #f8f9fa; padding: 12px; border-radius: 8px; margin-bottom: 15px; }
        .external-prescription-row .external-section-header { grid-column: 1 / -1; margin: 0 0 12px 0; padding: 8px 12px; background: #E8F5E9; border-radius: 6px; font-weight: 600; color: #2E7D32; font-size: 13px; }
        .external-prescription-row .external-section-header i { margin-right: 6px; }
        .dynamic-row label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #333;
            line-height: 1.4;
        }
        .dynamic-row .form-control {
            width: 100%;
            box-sizing: border-box;
            height: 42px;
            padding: 10px 15px;
            border: 2px solid #E0E0E0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
            background: #FAFAFA;
        }
        .dynamic-row .form-control:focus {
            outline: none;
            border-color: #4CAF50;
            background: white;
        }
        .dynamic-row select.form-control {
            position: relative;
            vertical-align: top;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 35px;
        }
        .dynamic-row select.form-control:focus {
            position: relative;
            transform: none !important;
            margin: 0 !important;
            top: 0 !important;
            left: 0 !important;
            padding: 10px 35px 10px 15px !important;
        }
        .dynamic-row select.form-control:active {
            position: relative;
            transform: none !important;
            margin: 0 !important;
            top: 0 !important;
            left: 0 !important;
        }
        .dynamic-row .dosage-select,
        .dynamic-row .frequency-select,
        .dynamic-row .duration-select {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            margin: 0;
            z-index: 1;
        }
        .dynamic-row .dosage-other-input,
        .dynamic-row .frequency-other-input,
        .dynamic-row .duration-other-input,
        .dynamic-row .medicine_form-other-input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            margin: 0 !important;
            z-index: 2;
        }
        .quantity-info {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
            font-weight: normal;
        }
        .quantity-error {
            color: #d32f2f;
            font-size: 12px;
            margin-top: 4px;
            font-weight: 500;
        }
        
        /* Inline Form Validation Styles */
        .form-group {
            position: relative;
        }
        
        .form-control.invalid,
        .form-control:invalid {
            border-color: #d32f2f !important;
            background-color: #fff5f5 !important;
            box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.1) !important;
        }
        
        .form-control.invalid:focus,
        .form-control:invalid:focus {
            border-color: #d32f2f !important;
            box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.2) !important;
        }
        
        .error-message {
            display: none;
            color: #d32f2f;
            font-size: 12px;
            margin-top: 6px;
            font-weight: 500;
            padding-left: 4px;
            animation: fadeIn 0.3s ease;
        }
        
        .error-message.show {
            display: block;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-5px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-group.has-error .form-control {
            border-color: #d32f2f !important;
            background-color: #fff5f5 !important;
        }
        
        .form-group.has-error .error-message {
            display: block;
        }
        
        /* Notification Styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 10000;
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 300px;
            max-width: 500px;
            animation: slideInRight 0.3s ease;
            font-weight: 500;
        }
        
        .notification.success {
            background: #E8F5E9;
            color: #2E7D32;
            border: 1px solid #66BB6A;
        }
        
        .notification.error {
            background: #FFEBEE;
            color: #C62828;
            border: 1px solid #F44336;
        }
        
        .notification i {
            font-size: 20px;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        .add-medicine-btn { 
            margin-top:12px; 
            padding: 12px 20px;
            min-width: auto;
            width: auto;
            box-sizing: border-box;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Category Accordion Styles - Matching Patient Side */
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
            max-height: 10000px;
            padding: 0 1.5rem 1.5rem;
            overflow: visible;
        }
        #prescriptionFormsContainer,
        .prescription-rows-grid {
            overflow: visible;
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
        
        /* Primary action buttons - consistent styling */
        .btn-primary-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            min-height: 44px;
            white-space: nowrap;
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
            justify-content: center;
            background: linear-gradient(135deg, #4CAF50 0%, #388E3C 100%);
            color: white;
            box-sizing: border-box;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .btn-primary-action:hover {
            background: linear-gradient(135deg, #388E3C 0%, #2E7D32 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.4);
            color: white;
        }
        
        .btn-primary-action i {
            font-size: 14px;
            width: 16px;
            text-align: center;
        }
        
        /* Secondary action buttons - for View PDF */
        .btn-secondary-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            min-height: 40px;
            white-space: nowrap;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            justify-content: center;
            background: linear-gradient(135deg, #4CAF50 0%, #388E3C 100%);
            color: white;
            box-sizing: border-box;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .btn-secondary-action:hover {
            background: linear-gradient(135deg, #388E3C 0%, #2E7D32 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.35);
            color: white;
        }
        
        .btn-secondary-action i {
            font-size: 13px;
            width: 14px;
            text-align: center;
        }
        
        /* Document Action Buttons */
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
        
        .two-column { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:18px; }
        
        /* Triage History Entry Styles */
        .triage-history-entry {
            transition: all 0.2s ease;
        }
        
        .triage-history-entry:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-color: #d0d0d0;
        }
        
        .triage-history-entry[style*="border-color: #4CAF50"] {
            box-shadow: 0 2px 12px rgba(76, 175, 80, 0.15);
        }
        
        .triage-history-entry > div:first-child:hover {
            background-color: #fafafa;
        }
        
        /* Initial Screening Success Modal Styles */
        .initial-screening-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 10000;
            align-items: flex-start;
            justify-content: center;
            padding-top: 10vh;
            overflow-y: auto;
        }
        
        .initial-screening-modal-overlay.active {
            display: flex;
        }
        
        .initial-screening-modal {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 420px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
            animation: modalFadeIn 0.3s ease;
            position: relative;
            margin: 20px;
        }
        
        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .initial-screening-modal-content {
            padding: 32px;
            text-align: center;
        }
        
        .initial-screening-modal-icon {
            font-size: 64px;
            color: #4CAF50;
            margin-bottom: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .initial-screening-modal-icon i {
            background: linear-gradient(135deg, #E8F5E9 0%, #C8E6C9 100%);
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
        }
        
        .initial-screening-modal-message {
            color: #333;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 28px;
            font-weight: 500;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        
        .initial-screening-modal-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #4CAF50 0%, #388E3C 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        
        .initial-screening-modal-button:hover {
            background: linear-gradient(135deg, #388E3C 0%, #2E7D32 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.4);
        }
        
        .initial-screening-modal-button i {
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="consult-wrapper">
        <div class="consult-card">
            <div class="header-flex">
                <div class="patient-summary">
                    <div class="avatar">
                        <?php 
                        $firstInitial = strtoupper(substr($patient['first_name'] ?? 'P', 0, 1));
                        $lastInitial = strtoupper(substr($patient['last_name'] ?? '', 0, 1));
                        echo $firstInitial . $lastInitial;
                        ?>
                    </div>
                    <div>
                        <h2 style="margin:0;"><?php echo htmlspecialchars($patientFullName ?: 'Patient'); ?></h2>
                        <p style="color:#64748b;margin:4px 0 0;">Patient ID: <?php echo htmlspecialchars($patient['id']); ?> <?php if ($is_placeholder): ?><span style="color:#f97316;font-size:12px;">(Placeholder)</span><?php endif; ?></p>
                    </div>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <a class="action-btn btn-view-profile" href="patient_record.php<?php echo $is_placeholder ? '' : '?patient_id=' . $patient['id']; ?>"><i class="fas fa-eye"></i> View Profile</a>
                    <a class="action-btn btn-refresh" href="doctor_consultation.php?patient_id=<?php echo urlencode($patient['id']); ?>" title="Refresh to see latest lab test results"><i class="fas fa-sync"></i> Refresh</a>
                </div>
            </div>
            <div class="two-column" style="margin-top:18px;">
                <div class="info-tile">
                    <div class="info-label">Age</div>
                    <div class="info-value"><?php 
                        // Use age from dependent info if available, otherwise calculate
                        $displayAge = 'N/A';
                        if (!empty($patient['age']) && is_numeric($patient['age'])) {
                            $displayAge = $patient['age'] . ' yrs';
                        } elseif (is_numeric($patientAge)) {
                            $displayAge = $patientAge . ' yrs';
                        }
                        echo $displayAge;
                    ?></div>
                </div>
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
                    <div class="info-label">Contact</div>
                    <div class="info-value"><?php echo htmlspecialchars($patient['phone'] ?? 'N/A'); ?></div>
                </div>
                <?php if (!empty($patient['relationship'])): ?>
                <div class="info-tile">
                    <div class="info-label">Relationship</div>
                    <div class="info-value"><?php echo htmlspecialchars($patient['relationship']); ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($patient['medical_conditions'])): ?>
            <div class="info-tile" style="margin-top:18px;">
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
        </div>

        <!-- Triage Information Display & Input -->
        <?php
        // Get current appointment ID for triage
        $current_appointment_id = null;
        $triage_info = null;
        
        if ($patient_id > 0) {
            try {
                // Check if triage_records table exists
                $table_check = $pdo->query("SHOW TABLES LIKE 'triage_records'");
                $table_exists = $table_check->rowCount() > 0;
                
                if ($table_exists) {
                    // Get the most recent approved or completed appointment for this patient and doctor
                    $stmt = $pdo->prepare("
                        SELECT a.id as appointment_id, a.start_datetime
                        FROM appointments a
                        WHERE a.doctor_id = ? 
                        AND (a.status = 'approved' OR a.status = 'completed')
                        AND (
                            (a.user_id = ? AND a.patient_id = ?) 
                            OR (a.user_id = ? AND a.patient_id IS NULL)
                            OR a.patient_id = ?
                        )
                        ORDER BY a.start_datetime DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$doctor_id, $patient_id, $patient_id, $patient_id, $patient_id]);
                    $current_appointment = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($current_appointment) {
                        $current_appointment_id = $current_appointment['appointment_id'];
                        
                        // Get existing triage for this appointment
                        $stmt = $pdo->prepare("
                            SELECT tr.*, 
                                   CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as recorded_by_name
                            FROM triage_records tr
                            LEFT JOIN users u ON u.id = tr.recorded_by
                            WHERE tr.appointment_id = ?
                            ORDER BY tr.created_at DESC
                            LIMIT 1
                        ");
                        $stmt->execute([$current_appointment_id]);
                        $triage_info = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                } else {
                    // No triage table: still get current approved appointment so lab requests etc. can link to it
                    $stmt = $pdo->prepare("
                        SELECT a.id as appointment_id
                        FROM appointments a
                        WHERE a.doctor_id = ? 
                        AND a.status IN ('approved', 'completed')
                        AND (
                            (a.user_id = ? AND a.patient_id = ?) 
                            OR (a.user_id = ? AND a.patient_id IS NULL)
                            OR a.patient_id = ?
                        )
                        ORDER BY a.start_datetime DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$doctor_id, $patient_id, $patient_id, $patient_id, $patient_id]);
                    $current_appointment = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($current_appointment) {
                        $current_appointment_id = $current_appointment['appointment_id'];
                    }
                }
            } catch (PDOException $e) {
                // Silently ignore if table doesn't exist - triage feature not set up yet
                error_log("Triage table check failed: " . $e->getMessage());
            }
        }
        ?>
        
        <!-- Initial Screening Section - Read-only (recorded by FDO on Appointments page) -->
        <div class="consult-card" style="background: linear-gradient(135deg, #E8F5E9 0%, #ffffff 100%); border-left: 4px solid #4CAF50;">
            <h3 style="color:#2E7D32;margin:0 0 20px 0;">
                <i class="fas fa-stethoscope"></i> Initial Screening
            </h3>
            <p style="color:#666; font-size: 13px; margin-bottom: 15px;">Initial screening is recorded by the Front Desk Officer on the Appointments page. Below is the screening data for this appointment (view only).</p>
            
            <?php if ($triage_info): ?>
            <div style="margin-top: 25px; padding-top: 25px; border-top: 2px solid #e0e0e0;">
                <h4 style="color:#2E7D32; margin-bottom: 15px; font-size: 16px;">Current Initial Screening Values</h4>
                <div class="two-column">
                    <div class="info-tile">
                        <div class="info-label">Blood Pressure</div>
                        <div class="info-value" style="font-size: 18px; color: #2E7D32; font-weight: 700;">
                            <?php echo htmlspecialchars($triage_info['blood_pressure'] ?? 'N/A'); ?>
                        </div>
                    </div>
                    <div class="info-tile">
                        <div class="info-label">Temperature</div>
                        <div class="info-value" style="font-size: 18px; color: #2E7D32; font-weight: 700;">
                            <?php echo $triage_info['temperature'] ? number_format($triage_info['temperature'], 1) . ' °C' : 'N/A'; ?>
                        </div>
                    </div>
                    <div class="info-tile">
                        <div class="info-label">Weight</div>
                        <div class="info-value" style="font-size: 18px; color: #2E7D32; font-weight: 700;">
                            <?php echo $triage_info['weight'] ? number_format($triage_info['weight'], 1) . ' kg' : 'N/A'; ?>
                        </div>
                    </div>
                    <?php if (!empty($triage_info['pulse_rate'])): ?>
                    <div class="info-tile">
                        <div class="info-label">Pulse Rate</div>
                        <div class="info-value" style="font-size: 18px; color: #2E7D32; font-weight: 700;">
                            <?php echo htmlspecialchars($triage_info['pulse_rate']); ?> bpm
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($triage_info['oxygen_saturation'])): ?>
                    <div class="info-tile">
                        <div class="info-label">Oxygen Saturation</div>
                        <div class="info-value" style="font-size: 18px; color: #2E7D32; font-weight: 700;">
                            <?php echo number_format($triage_info['oxygen_saturation'], 1); ?>%
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div style="margin-top: 15px; font-size: 12px; color: #666;">
                    <i class="fas fa-user-md"></i> Recorded by: <?php echo htmlspecialchars($triage_info['recorded_by_name'] ?? 'N/A'); ?>
                    <?php if (!empty($triage_info['created_at'])): ?>
                        on <?php echo date('F j, Y h:i A', strtotime($triage_info['created_at'])); ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div style="padding: 20px; background: #f8f9fa; border-radius: 8px; color: #666; text-align: center;">
                <i class="fas fa-info-circle" style="font-size: 24px; margin-bottom: 8px;"></i>
                <div>No initial screening recorded for this appointment.</div>
                <div style="font-size: 12px; margin-top: 6px;">Screening is entered by the FDO on the Appointments page.</div>
            </div>
            <?php endif; ?>
            
            <!-- Initial Screening History Section -->
            <?php if ($patient_id > 0 && !$is_placeholder): ?>
            <div style="margin-top: 30px; padding-top: 30px; border-top: 2px solid #e0e0e0;">
                <h4 style="color:#2E7D32; margin-bottom: 20px; font-size: 18px;">
                    <i class="fas fa-history"></i> Initial Screening History
                </h4>
                
                <!-- Date Filter Section -->
                <div style="background: #f8f9fa; padding: 12px 15px; border-radius: 6px; margin-bottom: 25px; border: 1px solid #e9ecef;">
                    <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
                        <div style="flex: 0 0 auto; min-width: 140px;">
                            <select id="dateFilterType" class="form-control" style="font-size: 13px; padding: 8px 10px;" onchange="toggleDateFilterType()">
                                <option value="all">All Records</option>
                                <option value="single">Specific Date</option>
                                <option value="range">Date Range</option>
                            </select>
                        </div>
                        
                        <div id="singleDateFilter" style="display: none; flex: 0 0 auto; min-width: 160px;">
                            <input type="date" id="filterSingleDate" class="form-control" style="font-size: 13px; padding: 8px 10px;" onchange="loadTriageHistory()">
                        </div>
                        
                        <div id="rangeDateFilter" style="display: none; flex: 0 0 auto; min-width: 280px;">
                            <div style="display: flex; gap: 8px;">
                                <input type="date" id="filterDateFrom" class="form-control" style="font-size: 13px; padding: 8px 10px; flex: 1;" placeholder="From" onchange="loadTriageHistory()">
                                <input type="date" id="filterDateTo" class="form-control" style="font-size: 13px; padding: 8px 10px; flex: 1;" placeholder="To" onchange="loadTriageHistory()">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- History Records Container -->
                <div id="triageHistoryContainer" style="min-height: 100px;">
                    <div style="text-align: center; padding: 40px; color: #999;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 10px;"></i>
                        <div>Loading history...</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Last Medical Record Information -->
        <div class="consult-card">
            <h3 style="color:#2E7D32;margin:0 0 20px 0;">Last Medical Record</h3>
            <div class="two-column">
                <div class="info-tile">
                    <div class="info-label">Last Visit on the Health Center</div>
                    <div class="info-value"><?php echo htmlspecialchars($lastVisitDisplay); ?></div>
                </div>
                <div class="info-tile">
                    <div class="info-label">Doctor(s) that Accommodated</div>
                    <div class="info-value"><?php echo htmlspecialchars($lastDoctor); ?></div>
                </div>
                <div class="info-tile">
                    <div class="info-label">Diagnosis</div>
                    <div class="info-value"><?php echo htmlspecialchars($lastDiagnosis); ?></div>
                </div>
                <div class="info-tile">
                    <div class="info-label">Prescription</div>
                    <div class="info-value"><?php echo htmlspecialchars($lastPrescription); ?></div>
                </div>
            </div>
        </div>

        <form id="consultationForm">
            <input type="hidden" name="patient_id" value="<?php echo (int) $patient['id']; ?>">
            <input type="hidden" name="doctor_id" value="<?php echo (int) $doctor_id; ?>">
            <input type="hidden" id="consultationAppointmentSelect" name="appointment_id" value="<?php echo (int)($current_appointment_id ?? 0); ?>">
            <div class="consult-card">
                <h3 style="color:#2E7D32;margin:0 0 20px 0;">New Consultation</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Diagnosis <span style="color:red;">*</span></label>
                        <textarea class="form-control textarea" name="diagnosis" required placeholder="Enter diagnosis..."></textarea>
                        <div class="error-message" data-field="diagnosis">This field is required</div>
                    </div>
                </div>
            </div>

            <div class="consult-card">
                <h3 style="color:#2E7D32;margin:0 0 20px 0;">Next Steps / Patient Instructions</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Instructions for Patient (Optional)</label>
                        <textarea class="form-control textarea" name="patient_instructions" placeholder="Enter follow-up instructions, lifestyle recommendations, or any other guidance for the patient..."></textarea>
                        <small style="color:#666; font-size:12px; margin-top:5px; display:block;">These instructions will be visible to the patient in their portal.</small>
                    </div>
                </div>
            </div>

            <div class="consult-card" id="followUpSection">
                <h3 style="color:#2E7D32;margin:0 0 20px 0;">Follow-Up Check-Up (Optional)</h3>
                <div class="form-grid">
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="followUpRequired" name="follow_up_required" value="1" onchange="toggleFollowUpFields()" style="width: 20px; height: 20px; cursor: pointer;">
                            <span style="font-weight: 600; font-size: 16px;">Follow-up required</span>
                        </label>
                    </div>
                    
                    <div id="followUpFields" style="display: none; grid-column: 1 / -1;">
                        <div class="form-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
                            <div class="form-group">
                                <label for="followUpDate">Suggested Follow-Up Date <span style="color:red;">*</span></label>
                                <input type="text" id="followUpDate" name="follow_up_date" class="form-control" placeholder="Select date..." readonly required>
                                <div class="error-message" data-field="followUpDate">This field is required</div>
                            </div>
                            <div class="form-group">
                                <label for="followUpTime">Suggested Follow-Up Time <span style="color:red;">*</span></label>
                                <select id="followUpTime" name="follow_up_time" class="form-control" required>
                                    <option value="">Select time...</option>
                                    <option value="07:00">7:00 AM</option>
                                    <option value="07:30">7:30 AM</option>
                                    <option value="08:00">8:00 AM</option>
                                    <option value="08:30">8:30 AM</option>
                                    <option value="09:00">9:00 AM</option>
                                    <option value="09:30">9:30 AM</option>
                                    <option value="10:00">10:00 AM</option>
                                    <option value="10:30">10:30 AM</option>
                                    <option value="11:00">11:00 AM</option>
                                    <option value="12:00">12:00 PM</option>
                                    <option value="12:30">12:30 PM</option>
                                    <option value="13:00">1:00 PM</option>
                                    <option value="13:30">1:30 PM</option>
                                    <option value="14:00">2:00 PM</option>
                                    <option value="14:30">2:30 PM</option>
                                    <option value="15:00">3:00 PM</option>
                                </select>
                                <div class="error-message" data-field="followUpTime">This field is required</div>
                            </div>
                            <div class="form-group">
                                <label for="followUpReason">Reason for Follow-Up <span style="color:red;">*</span></label>
                                <select id="followUpReason" name="follow_up_reason" class="form-control" required>
                                    <option value="">Select reason</option>
                                    <option value="Monitor Progress">Monitor Progress</option>
                                    <option value="Review Test Results">Review Test Results</option>
                                    <option value="Medication Adjustment">Medication Adjustment</option>
                                    <option value="Symptom Reassessment">Symptom Reassessment</option>
                                    <option value="Preventive Care">Preventive Care</option>
                                    <option value="Post-Procedure Check">Post-Procedure Check</option>
                                    <option value="Chronic Condition Management">Chronic Condition Management</option>
                                    <option value="Other">Other</option>
                                </select>
                                <div class="error-message" data-field="followUpReason">This field is required</div>
                            </div>
                        </div>
                        <div class="form-group" id="followUpReasonOther" style="display: none; margin-top: 10px;">
                            <label for="followUpReasonOtherText">Specify Reason</label>
                            <input type="text" id="followUpReasonOtherText" name="follow_up_reason_other" class="form-control" placeholder="Enter specific reason for follow-up...">
                            <div class="error-message" data-field="followUpReasonOtherText">This field is required</div>
                        </div>
                    </div>
                </div>
            </div>

        <!-- Prescription Section (expandable, same pattern as Medical Certificate and Referral) -->
        <div class="consult-card" style="border-left: 4px solid #2E7D32;">
            <div class="category-accordion" id="prescriptionAccordion">
                <div class="category-header" onclick="toggleCategory(document.getElementById('prescriptionAccordion')); if (document.getElementById('prescriptionAccordion').classList.contains('active')) loadPrescriptions();">
                    <div class="category-header-title">
                        <i class="fas fa-prescription-bottle-alt" style="color: #2E7D32;"></i>
                        <span>Prescription (Optional)</span>
                        <span style="font-size: 13px; font-weight: 400; color: #666; margin-left: 8px;" id="prescriptionAccordionCount"></span>
                    </div>
                    <span class="category-toggle">+</span>
                </div>
                <div class="category-content" onclick="event.stopPropagation();">
                    <p style="color:#666; font-size: 14px; margin-bottom: 20px; margin-top: 10px;">
                        Add medicines from inventory or external sources. Click a button below to add a prescription entry; the form will appear for you to fill out. Prescriptions are saved when you complete the consultation.
                    </p>
                    
                    <div style="margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
                        <button type="button" class="action-btn btn-complete add-medicine-btn" onclick="event.stopPropagation(); addConsultPrescriptionRow(); return false;">
                            <i class="fas fa-plus"></i> Add Medicine (from inventory)
                        </button>
                        <button type="button" class="action-btn btn-complete add-medicine-btn" onclick="event.stopPropagation(); addExternalPrescriptionRow(); return false;">
                            <i class="fas fa-external-link-alt"></i> Add External Medicine
                        </button>
                    </div>
                    <small style="color:#666; font-size: 12px; display: block; margin-bottom: 20px;">External medicines are not in health center inventory — patient buys them outside.</small>
                    
                    <div id="prescriptionFormsContainer" style="display: none;">
                        <div id="consultPrescriptionRows" class="prescription-rows-grid">
                            <!-- Prescription rows added here when doctor clicks Add Medicine or Add External Medicine -->
                        </div>
                    </div>
                    <input type="hidden" id="currentPrescriptionId" name="current_prescription_id" value="">
                    
                    <!-- Prescription Validity Period -->
                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                        <div class="form-group" style="max-width: 300px;">
                            <label for="prescriptionValidityPeriod" style="font-weight: 600; color: #2E7D32; margin-bottom: 8px;">
                                <i class="fas fa-calendar-alt"></i> Prescription Validity Period
                            </label>
                            <select id="prescriptionValidityPeriod" name="prescription_validity_period" class="form-control" style="height: 42px;" onchange="toggleCustomExpirationDate('prescription')">
                                <option value="" disabled>Select validity period</option>
                                <option value="7">7 days</option>
                                <option value="14" selected>14 days (Default)</option>
                                <option value="30">30 days</option>
                                <option value="maintenance">Maintenance (no expiration)</option>
                                <option value="custom">Custom expiration date</option>
                            </select>
                            <div id="prescriptionCustomDateSection" style="display: none; margin-top: 10px;">
                                <label for="prescriptionCustomExpirationDate" style="font-weight: 600; color: #2E7D32; margin-bottom: 8px; font-size: 13px;">
                                    Custom Expiration Date
                                </label>
                                <input type="date" id="prescriptionCustomExpirationDate" name="prescription_custom_expiration_date" class="form-control" style="height: 42px;" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                                <small style="color:#666; font-size:11px; margin-top:5px; display:block;">
                                    Select a specific expiration date. Must be in the future.
                                </small>
                            </div>
                            <small style="color:#666; font-size:11px; margin-top:5px; display:block;">
                                The prescription will expire after the selected period or on the custom date (except Maintenance). Patients cannot download expired prescriptions.
                            </small>
                            <small style="color:#1565C0; font-size:11px; margin-top:5px; display:block;">
                                <strong>From inventory:</strong> Antibiotic items limit validity to the entered days (max 7). Maintenance items are long-term with no expiration. Other categories use the period above.
                            </small>
                        </div>
                    </div>
                    
                    <!-- Existing / saved prescriptions list (same pattern as Medical Certificates) -->
                    <div id="existingPrescriptions" style="margin-top: 20px;">
                        <p style="color: #999; font-style: italic; margin-top: 15px;">Expand this module to view saved prescriptions for this patient.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lab Test Requests Section (expandable, same pattern as Medical Certificate, Prescriptions, Referral) -->
        <div class="consult-card" style="border-left: 4px solid #2196F3;">
            <div class="category-accordion" id="labRequestsSectionAccordion">
                <div class="category-header" onclick="toggleCategory(document.getElementById('labRequestsSectionAccordion'))">
                    <div class="category-header-title">
                        <i class="fas fa-flask" style="color: #2196F3;"></i>
                        <span>Lab Test Requests (Optional)</span>
                    </div>
                    <span class="category-toggle">+</span>
                </div>
                <div class="category-content" onclick="event.stopPropagation();">
                    <p style="color:#666; font-size: 14px; margin-bottom: 20px; margin-top: 10px;">
                        Select predefined laboratory packages and/or add individual tests per request. Each request is one consolidated slip (e.g. Blood Chemistry + Diabetes packages, or packages + manual tests). The request will appear in the patient's My Records and can be downloaded as PDF.
                    </p>
                    <div id="labTestRequestsContainer">
                        <!-- Lab request cards (each card = one slip with multiple tests) -->
                    </div>
                    <div style="margin-top: 15px; display: flex; flex-direction: column; gap: 10px;">
                        <div id="labTestDoneBtnWrapper" style="display: none;">
                            <button type="button" class="action-btn btn-complete" id="labTestDoneBtn" onclick="saveLabTestDone()">
                                <i class="fas fa-check"></i> Done
                            </button>
                        </div>
                        <button type="button" class="action-btn btn-complete add-medicine-btn" onclick="ensureAccordionOpen('labTestRequestsContainer'); addLabRequestCard();">
                            <i class="fas fa-plus"></i> Add Lab Request
                        </button>
                    </div>
                    <input type="hidden" id="currentLabTestRequestIds" name="current_lab_test_request_ids" value="">
                    <div id="labTestDoneMessage" style="display: none; margin-top: 10px; padding: 10px; background: #E3F2FD; border-radius: 6px; border: 1px solid #BBDEFB; color: #1976D2;">
                        <i class="fas fa-check-circle"></i> Lab test requests saved. They will appear in the patient's My Records; the patient can download the lab request PDF from there. You can continue adding more requests if needed.
                    </div>

                    <!-- Submitted laboratory test requests (inside same expandable) -->
                    <p style="color:#666; font-size: 14px; margin-bottom: 16px; margin-top: 24px;">
                        Submitted laboratory test requests for this patient. New requests appear here immediately after you click Done above. They also appear on the patient's My Record page.
                    </p>
                    <div class="document-list" id="labRequestsSubmittedList">
                        <?php 
                        if (!empty($lab_test_requests)) {
                            foreach ($lab_test_requests as $request): 
                                $card_id = 'lab-' . $request['id'];
                                $request_date = date('F j, Y', strtotime($request['created_at']));
                                
                                $request_results = array_filter($lab_test_results, function($result) use ($request) {
                                    return (isset($result['lab_request_id']) && (int)$result['lab_request_id'] === (int)$request['id'])
                                        || (isset($result['lab_test_request_id']) && (int)$result['lab_test_request_id'] === (int)$request['id']);
                                });
                                
                                $status_colors = [
                                    'pending' => '#ff9800',
                                    'completed' => '#4CAF50',
                                    'cancelled' => '#999'
                                ];
                                $status_label = $request['status'] === 'pending' ? 'Active' : ucfirst($request['status']);
                                $status_color = $status_colors[$request['status']] ?? '#666';
                        ?>
                            <div class="document-item lab-request-card-item" data-request-id="<?= (int)$request['id'] ?>" onclick="event.stopPropagation(); toggleDocumentDetails('<?= $card_id ?>')">
                                <div class="document-row">
                                    <div class="document-chevron">
                                        <i class="fas fa-chevron-right"></i>
                                    </div>
                                    <div class="document-row-content">
                                        <h3 class="document-title">
                                            <?php 
                                            $testNames = !empty($request['tests']) ? $request['tests'] : [ $request['test_name'] ?? '' ];
                                            echo htmlspecialchars(implode(', ', array_filter($testNames)) ?: 'Lab Request');
                                            ?>
                                        </h3>
                                        <span class="document-status" style="background: <?= $status_color ?>; color: white;">
                                            <?php echo $status_label; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="document-details" id="<?= $card_id ?>">
                                    <div class="document-details-content">
                                        <p style="margin: 0 0 10px 0; color: #666; font-size: 12px; font-style: italic;">
                                            Lab Test Request #<?php echo htmlspecialchars($request['id']); ?>
                                        </p>
                                        <div style="margin-bottom: 10px;">
                                            <?php 
                                            $testsList = !empty($request['tests']) ? $request['tests'] : [ $request['test_name'] ?? '' ];
                                            $testsList = array_filter($testsList);
                                            ?>
                                            <strong>Tests:</strong> <?php echo htmlspecialchars(implode(', ', $testsList) ?: '—'); ?><br>
                                            <strong>Laboratory:</strong> <?php echo htmlspecialchars($request['laboratory_name'] ?? 'Not specified'); ?><br>
                                            <strong>Requested Date:</strong> <?php echo $request_date; ?><br>
                                            <?php if (!empty($request['doctor_name'])): ?>
                                                <strong>Requested by:</strong> <?php echo htmlspecialchars($request['doctor_name']); ?><br>
                                            <?php endif; ?>
                                            <?php if (!empty($request['notes'])): ?>
                                                <strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($request['notes'])); ?><br>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if (!empty($request_results)): ?>
                                            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e0e0e0;">
                                                <h5 style="margin: 0 0 10px 0; color: #2E7D32; font-size: 14px; font-weight: 600;">
                                                    <i class="fas fa-file-medical"></i> Uploaded Results:
                                                </h5>
                                                <?php foreach ($request_results as $result): ?>
                                                    <div style="margin-bottom: 12px; padding: 12px; background: #f8f9fa; border-radius: 6px; border-left: 3px solid #4CAF50;">
                                                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                                                            <div style="flex: 1;">
                                                                <?php
                                                                // Determine file icon based on file type
                                                                $file_ext = strtolower(pathinfo($result['file_name'], PATHINFO_EXTENSION));
                                                                $icon_class = 'fas fa-file';
                                                                $icon_color = '#666';
                                                                if (in_array($file_ext, ['pdf'])) {
                                                                    $icon_class = 'fas fa-file-pdf';
                                                                    $icon_color = '#d32f2f';
                                                                } elseif (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                                                                    $icon_class = 'fas fa-file-image';
                                                                    $icon_color = '#4CAF50';
                                                                } elseif (in_array($file_ext, ['doc', 'docx'])) {
                                                                    $icon_class = 'fas fa-file-word';
                                                                    $icon_color = '#2196F3';
                                                                }
                                                                ?>
                                                                <i class="<?php echo $icon_class; ?>" style="color: <?php echo $icon_color; ?>; margin-right: 8px;"></i>
                                                                <a href="<?php echo htmlspecialchars($result['file_path']); ?>" target="_blank" style="color: #2E7D32; text-decoration: none; font-weight: 500;">
                                                                    <?php echo htmlspecialchars($result['file_name']); ?>
                                                                </a>
                                                                <span style="color: #666; font-size: 12px; margin-left: 10px;">
                                                                    (<?php echo $result['file_size'] ? number_format($result['file_size'] / 1024, 2) . ' KB' : 'N/A'; ?>)
                                                                </span>
                                                                <p style="margin: 5px 0 0 0; color: #999; font-size: 11px;">
                                                                    Uploaded by <?php echo htmlspecialchars($result['uploaded_by_name'] ?? 'Patient'); ?>
                                                                    on <?php echo date('M d, Y h:i A', strtotime($result['uploaded_at'])); ?>
                                                                </p>
                                                                <?php if (!empty($result['notes'])): ?>
                                                                    <p style="margin: 5px 0 0 0; color: #666; font-size: 11px; font-style: italic;">
                                                                        Note: <?php echo htmlspecialchars($result['notes']); ?>
                                                                    </p>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <div class="document-actions" style="margin-top: 8px;">
                                                            <a href="<?php echo htmlspecialchars($result['file_path']); ?>" target="_blank" class="btn-view">
                                                                <i class="fas fa-eye"></i> View
                                                            </a>
                                                            <a href="<?php echo htmlspecialchars($result['file_path']); ?>" download class="btn-download">
                                                                <i class="fas fa-download"></i> Download
                                                            </a>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-radius: 6px; color: #856404; font-size: 13px;">
                                                <i class="fas fa-info-circle"></i> No results uploaded yet
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($request['appointment_id'])): ?>
                                            <div class="document-actions" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e0e0e0; display: flex; gap: 10px; flex-wrap: wrap;">
                                                <a href="generate_lab_test_request_pdf.php?appointment_id=<?php echo (int)$request['appointment_id']; ?>&mode=view" target="_blank" class="btn-view" onclick="event.stopPropagation();" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; background: #E3F2FD; color: #1976D2; border: 1px solid #1976D2;">
                                                    <i class="fas fa-eye"></i> View PDF
                                                </a>
                                                <a href="generate_lab_test_request_pdf.php?appointment_id=<?php echo (int)$request['appointment_id']; ?>" target="_blank" class="btn-download" onclick="event.stopPropagation();" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; background: #E8F5E9; color: #2E7D32; border: 1px solid #4CAF50;">
                                                    <i class="fas fa-download"></i> Download PDF
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php 
                            endforeach;
                        } else {
                        ?>
                            <div id="labRequestsEmptyPlaceholder" style="padding: 24px; text-align: center; color: #666; background: #f8f9fa; border-radius: 8px; border: 1px dashed #ccc;">
                                <i class="fas fa-flask" style="font-size: 32px; margin-bottom: 10px; color: #BBDEFB;"></i>
                                <p style="margin: 0;">No lab test requests submitted yet. Add requests above and click Done to save.</p>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Medical Certificate Section -->
        <div class="consult-card" style="border-left: 4px solid #FF9800;">
            <div class="category-accordion" id="medicalCertificateAccordion">
                <div class="category-header" onclick="toggleCategory(document.getElementById('medicalCertificateAccordion'))">
                    <div class="category-header-title">
                        <i class="fas fa-certificate" style="color: #FF9800;"></i>
                        <span>Medical Certificate (Optional)</span>
                    </div>
                    <span class="category-toggle">+</span>
                </div>
                <div class="category-content" onclick="event.stopPropagation();">
                    <p style="color:#666; font-size: 14px; margin-bottom: 20px; margin-top: 10px;">
                        Generate medical certificates for the patient. Select the certificate type and validity period.
                    </p>
                    
                    <div id="medicalCertificatesContainer">
                        <!-- Certificate rows will be added here dynamically -->
                    </div>
                    <button type="button" class="action-btn btn-complete add-medicine-btn" onclick="event.stopPropagation(); addMedicalCertificateRow(); return false;">
                        <i class="fas fa-plus"></i> Add Medical Certificate
                    </button>
                    
                    <!-- Display Existing Certificates -->
                    <div id="existingCertificates" style="margin-top: 20px;">
                        <!-- Certificates will be loaded here via JavaScript -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Referral to Other Hospitals Section -->
        <div class="consult-card" style="border-left: 4px solid #9C27B0;">
            <div class="category-accordion" id="referralAccordion">
                <div class="category-header" onclick="toggleCategory(document.getElementById('referralAccordion'))">
                    <div class="category-header-title">
                        <i class="fas fa-hospital" style="color: #9C27B0;"></i>
                        <span>Referral to Other Hospitals (Optional)</span>
                    </div>
                    <span class="category-toggle">+</span>
                </div>
                <div class="category-content" onclick="event.stopPropagation();">
                    <p style="color:#666; font-size: 14px; margin-bottom: 20px; margin-top: 10px;">
                        Create referral letters for the patient to be referred to other hospitals or medical facilities.
                    </p>
                    
                    <div id="referralsContainer">
                        <!-- Referral rows will be added here dynamically -->
                    </div>
                    <button type="button" class="action-btn btn-complete add-medicine-btn" onclick="event.stopPropagation(); addReferralRow(); return false;">
                        <i class="fas fa-plus"></i> Add Hospital Referral
                    </button>
                    
                    <!-- Display Existing Referrals -->
                    <div id="existingReferrals" style="margin-top: 20px;">
                        <!-- Referrals will be loaded here via JavaScript -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Done Button -->
        <div class="consult-card" id="doneButtonContainer" style="text-align: center; padding: 40px 30px; margin-top: 20px; margin-bottom: 40px; background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border: 2px solid #2E7D32; position: relative; z-index: 10; scroll-margin-top: 20px;">
            <button type="button" id="doneButton" class="action-btn btn-approve" style="min-width:280px; font-size:18px; padding:18px 40px; font-weight: 600; box-shadow: 0 4px 12px rgba(46, 125, 50, 0.3); transition: all 0.3s ease;">
                <i class="fas fa-check-circle"></i> Complete Consultation
            </button>
            <p style="margin-top: 15px; color: #666; font-size: 14px;">
                Save diagnosis, prescriptions, lab test requests, and follow-up instructions
            </p>
        </div>
        </form>

    </div>

    <script>
        const patientId = <?php echo (int) $patient['id']; ?>;
        const doctorId = <?php echo (int) $doctor_id; ?>;
        const isPlaceholder = <?php echo $is_placeholder ? 'true' : 'false'; ?>;
        const dosageOptions = <?php echo json_encode($dosageOptions); ?>;
        const frequencyOptions = <?php echo json_encode($frequencyOptions); ?>;
        const timingOfIntakeOptions = <?php echo json_encode($timingOfIntakeOptions); ?>;
        const durationOptions = <?php echo json_encode($durationOptions); ?>;
        const medicineFormOptions = <?php echo json_encode($medicineFormOptions); ?>;
        const patientName = <?php echo json_encode($patientFullName ?: 'Patient'); ?>;
        const commonLabTests = <?php echo json_encode($commonLabTests); ?>;
        const labTestPackages = <?php echo json_encode($labTestPackages); ?>;
        const labTestIndividualTests = <?php echo json_encode($labTestIndividualTests); ?>;
        const payatasLaboratories = <?php echo json_encode($payatasLaboratories); ?>;
        
        // Medicine autocomplete functionality
        let searchTimeouts = new Map();
        let selectedMedicineIndices = new Map();
        
        // Initialize medicine autocomplete for all medicine inputs
        function initializeMedicineAutocomplete() {
            document.querySelectorAll('.medicine-autocomplete').forEach(input => {
                // Skip if already initialized
                if (input.dataset.initialized === 'true') return;
                input.dataset.initialized = 'true';
                
                const container = input.parentElement; // This is the div with position: relative
                let dropdown = container.querySelector('.medicine-autocomplete-dropdown');
                let medicineIdInput = container.querySelector('.medicine-id-input');
                if (!dropdown || !medicineIdInput) {
                    const row = input.closest('.prescription-row');
                    if (row) {
                        dropdown = dropdown || row.querySelector('.medicine-autocomplete-dropdown');
                        medicineIdInput = medicineIdInput || row.querySelector('.medicine-id-input');
                    }
                }
                if (!dropdown) {
                    console.warn('Dropdown not found for medicine autocomplete');
                    return;
                }
                
                const inputId = input.id || Math.random().toString(36);
                if (!input.id) input.id = inputId;
                
                // Real-time search: list appears as soon as doctor types (even first letter). No need to click outside.
                input.addEventListener('input', function() {
                    const query = this.value.trim();
                    
                    if (searchTimeouts.has(inputId)) {
                        clearTimeout(searchTimeouts.get(inputId));
                    }
                    
                    if (query.length === 0) {
                        dropdown.style.display = 'none';
                        dropdown.classList.remove('is-open');
                        if (medicineIdInput) medicineIdInput.value = '';
                        const row = input.closest('.prescription-row');
                        if (row && typeof applyMedicineCategoryToRow === 'function') applyMedicineCategoryToRow(row, '');
                        return;
                    }
                    
                    // Very short debounce (50ms) so first letter shows list almost instantly
                    const timeout = setTimeout(() => {
                        searchMedicines(query, dropdown, input, medicineIdInput, inputId);
                    }, 50);
                    searchTimeouts.set(inputId, timeout);
                });
                
                input.addEventListener('focus', function() {
                    // Re-show dropdown only if there is already text (re-apply filter). Do not show list on empty focus.
                    const q = this.value.trim();
                    if (q.length >= 1) searchMedicines(q, dropdown, input, medicineIdInput, inputId);
                });
                
                input.addEventListener('keydown', function(e) {
                    const items = dropdown.querySelectorAll('.dropdown-item');
                    const currentIndex = selectedMedicineIndices.get(inputId) || -1;
                    
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        const newIndex = Math.min(currentIndex + 1, items.length - 1);
                        selectedMedicineIndices.set(inputId, newIndex);
                        updateSelectedItem(items, newIndex);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        const newIndex = Math.max(currentIndex - 1, -1);
                        selectedMedicineIndices.set(inputId, newIndex);
                        updateSelectedItem(items, newIndex);
                    } else if (e.key === 'Enter' && currentIndex >= 0 && items[currentIndex]) {
                        e.preventDefault();
                        items[currentIndex].click();
                    } else if (e.key === 'Escape') {
                        dropdown.style.display = 'none';
                        dropdown.classList.remove('is-open');
                    }
                });
                
                // Close dropdown when clicking outside (use mousedown so selecting an item doesn't close before click fires)
                document.addEventListener('mousedown', function closeDropdownOutside(e) {
                    if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                        dropdown.style.display = 'none';
                        dropdown.classList.remove('is-open');
                    }
                });
            });
        }
        
        async function searchMedicines(query, dropdown, input, medicineIdInput, inputId) {
            if (!dropdown || !input) return;
            function showDropdown() {
                requestAnimationFrame(function() {
                    dropdown.style.display = 'block';
                    dropdown.style.visibility = 'visible';
                    dropdown.classList.add('is-open');
                });
            }
            try {
                const response = await fetch(`doctor_search_medicines.php?q=${encodeURIComponent(query)}`);
                const data = await response.json();
                
                if (data.success && data.medicines) {
                    dropdown.innerHTML = '';
                    selectedMedicineIndices.set(inputId, -1);
                    
                    if (data.medicines.length === 0) {
                        dropdown.innerHTML = '<div class="dropdown-item" style="color: #999; cursor: default;">No medicines found</div>';
                    } else {
                        data.medicines.forEach((med) => {
                            const item = document.createElement('div');
                            item.className = 'dropdown-item';
                            item.textContent = med.display;
                            item.dataset.medicineId = med.id;
                            item.dataset.medicineName = med.name;
                            item.dataset.medicineQuantity = med.quantity;
                            item.dataset.medicineUnit = med.unit;
                            item.dataset.medicineCategory = med.category || '';
                            item.addEventListener('mousedown', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                            });
                            item.addEventListener('click', function() {
                                input.value = med.name;
                                if (medicineIdInput) {
                                    medicineIdInput.value = med.id;
                                }
                                const row = input.closest('.prescription-row');
                                if (row && typeof applyMedicineCategoryToRow === 'function') applyMedicineCategoryToRow(row, this.dataset.medicineCategory || '');
                                const quantityInput = row ? row.querySelector('.quantity-per-intake-input') || row.querySelector('.quantity-input') : null;
                                const quantityInfo = row ? row.querySelector('.quantity-info') : null;
                                const quantityError = row ? row.querySelector('.quantity-error') : null;
                                if (quantityInput) {
                                    quantityInput.dataset.maxQuantity = med.quantity;
                                    quantityInput.max = med.quantity;
                                    if (quantityInfo) {
                                        quantityInfo.textContent = `Available: ${med.quantity} ${med.unit || 'pcs'}`;
                                        quantityInfo.style.display = 'block';
                                    }
                                    if (quantityError) quantityError.style.display = 'none';
                                    const currentQty = parseInt(quantityInput.value) || 1;
                                    if (currentQty > med.quantity) {
                                        quantityInput.value = med.quantity;
                                        if (quantityError) {
                                            quantityError.textContent = `Quantity reduced to available stock (${med.quantity})`;
                                            quantityError.style.display = 'block';
                                        }
                                    }
                                }
                                dropdown.style.display = 'none';
                                dropdown.classList.remove('is-open');
                            });
                            dropdown.appendChild(item);
                        });
                    }
                    showDropdown();
                } else {
                    dropdown.innerHTML = '<div class="dropdown-item" style="color: #999; cursor: default;">Unable to load medicines.</div>';
                    showDropdown();
                }
            } catch (error) {
                console.error('Error searching medicines:', error);
                dropdown.innerHTML = '<div class="dropdown-item" style="color: #c00; cursor: default;">Error loading list. Try again.</div>';
                showDropdown();
            }
        }
        
        function updateSelectedItem(items, selectedIndex) {
            items.forEach((item, index) => {
                if (index === selectedIndex) {
                    item.classList.add('selected');
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('selected');
                }
            });
        }
        
        // Apply category-based UI per medicine (from Pharmacist Inventory): Antibiotic = require frequency & days (max 7); Maintenance = long-term no expiration
        function applyMedicineCategoryToRow(row, category) {
            const cat = (category || '').trim().toLowerCase();
            row.dataset.medicineCategory = cat;
            const badge = row.querySelector('.medicine-category-badge');
            const frequencySelect = row.querySelector('.frequency-select');
            const durationWrap = row.querySelector('.duration-select') ? row.querySelector('.duration-select').closest('div') : null;
            const durationSelect = row.querySelector('.duration-select');
            const durationOtherInput = row.querySelector('.duration-other-input');
            
            if (!badge) return;
            badge.style.display = 'none';
            badge.removeAttribute('data-required-frequency');
            badge.removeAttribute('data-required-duration');
            if (frequencySelect) frequencySelect.removeAttribute('required');
            if (durationSelect) durationSelect.removeAttribute('required');
            if (durationOtherInput) durationOtherInput.removeAttribute('required');
            
            if (cat === 'antibiotic') {
                badge.style.display = 'block';
                badge.style.background = '#E3F2FD';
                badge.style.color = '#1565C0';
                badge.style.borderLeft = '4px solid #1976D2';
                badge.innerHTML = '<i class="fas fa-info-circle"></i> <strong>Antibiotic</strong> — Enter frequency and number of days (max 7 days). Prescription validity will be limited accordingly.';
                if (frequencySelect) frequencySelect.setAttribute('required', 'required');
                if (durationSelect) durationSelect.setAttribute('required', 'required');
                badge.dataset.requiredFrequency = '1';
                badge.dataset.requiredDuration = '1';
                // Limit duration options to 1-7 days for antibiotic (optional: filter options in dropdown)
                if (durationSelect) {
                    const otherOpt = durationSelect.querySelector('option[value="__other__"]');
                    if (durationOtherInput) durationOtherInput.placeholder = 'e.g. 5 (max 7 days)';
                }
            } else if (cat === 'maintenance') {
                badge.style.display = 'block';
                badge.style.background = '#E8F5E9';
                badge.style.color = '#2E7D32';
                badge.style.borderLeft = '4px solid #4CAF50';
                badge.innerHTML = '<i class="fas fa-calendar-check"></i> <strong>Long-term / No Expiration</strong> — Prescription remains valid for follow-up.';
                if (durationSelect) durationSelect.removeAttribute('required');
                if (frequencySelect) frequencySelect.removeAttribute('required');
            }
            calculateTotalQuantity(row.querySelector('.quantity-per-intake-input') || row.querySelector('.total-quantity-input') || row);
        }
        
        // Validate antibiotic rows before save: frequency and duration required, duration max 7 days (per medicine, from inventory category)
        function validateAntibioticPrescriptionRows() {
            const rows = document.querySelectorAll('.prescription-row');
            for (const row of rows) {
                if (row.classList.contains('external-prescription-row')) continue;
                const cat = (row.dataset.medicineCategory || '').toLowerCase();
                if (cat !== 'antibiotic') continue;
                const medicineInput = row.querySelector('.medicine-autocomplete');
                const medicineName = medicineInput ? medicineInput.value.trim() : '';
                if (!medicineName) continue;
                const frequencySelect = row.querySelector('.frequency-select');
                const durationSelect = row.querySelector('.duration-select');
                const durationOtherInput = row.querySelector('.duration-other-input');
                let frequency = (frequencySelect && frequencySelect.value) ? frequencySelect.value : '';
                let duration = '';
                if (durationSelect && durationSelect.value === '__other__' && durationOtherInput) {
                    duration = durationOtherInput.value.trim();
                } else if (durationSelect && durationSelect.value) {
                    duration = durationSelect.value;
                }
                if (!frequency || !duration) {
                    return { valid: false, message: 'Antibiotic "' + medicineName + '" requires frequency and number of days (max 7 days).' };
                }
                const durationInDays = parseDurationInDays(duration);
                if (durationInDays <= 0) {
                    return { valid: false, message: 'Antibiotic "' + medicineName + '": enter a valid duration in days (e.g. 5 days, max 7).' };
                }
                if (durationInDays > 7) {
                    return { valid: false, message: 'Antibiotic "' + medicineName + '": duration cannot exceed 7 days.' };
                }
            }
            return { valid: true };
        }
        
        // Handle "Other" option for Dosage, Frequency, Duration
        function handleOtherOption(select, fieldType) {
            const row = select.closest('.prescription-row');
            const otherInput = row.querySelector(`.${fieldType}-other-input`);
            
            if (select.value === '__other__') {
                otherInput.style.display = 'block';
                otherInput.required = true;
                select.style.display = 'none';
            } else {
                otherInput.style.display = 'none';
                otherInput.required = false;
                otherInput.value = '';
                select.style.display = 'block';
            }
            
            // Recalculate total quantity when switching to/from "Other" option
            if (fieldType === 'frequency' || fieldType === 'duration') {
                calculateTotalQuantity(select);
            }
        }
        
        // Parse frequency to get frequency per day (plain-language options)
        function parseFrequencyPerDay(frequency) {
            if (!frequency || frequency.trim() === '') return 0;
            
            const freq = frequency.trim().toLowerCase();
            
            // Plain-language options (Frequency = times per day)
            if (freq === 'once a day') return 1;
            if (freq === 'twice a day') return 2;
            if (freq === 'three times a day') return 3;
            if (freq === 'four times a day') return 4;
            if (freq === 'every other day') return 0.5; // 1 dose every 2 days
            if (freq === 'as needed (prn)' || freq === 'as needed') return 0; // Can't calculate for PRN
            
            // Legacy / fallback
            if (freq.includes('once') || freq.includes('1 time')) return 1;
            if (freq.includes('twice') || freq.includes('2 time')) return 2;
            if (freq.includes('three') || freq.includes('3 time')) return 3;
            if (freq.includes('four') || freq.includes('4 time')) return 4;
            
            const numberMatch = freq.match(/(\d+)/);
            if (numberMatch) return parseInt(numberMatch[1]);
            
            return 0;
        }
        
        // Parse duration to get duration in days
        function parseDurationInDays(duration) {
            if (!duration || duration.trim() === '') return 0;
            
            const dur = duration.trim().toLowerCase();
            
            // Standard duration options
            if (dur.includes('until symptoms improve') || dur.includes('until improved') || dur.includes('prn')) {
                return 0; // Can't calculate for variable duration
            }
            
            // Parse "X day(s)" or "X days" patterns
            const dayMatch = dur.match(/(\d+)\s*day/i);
            if (dayMatch) {
                return parseInt(dayMatch[1]);
            }
            
            // Parse "X week(s)" patterns
            const weekMatch = dur.match(/(\d+)\s*week/i);
            if (weekMatch) {
                return parseInt(weekMatch[1]) * 7;
            }
            
            // Parse "X month(s)" patterns
            const monthMatch = dur.match(/(\d+)\s*month/i);
            if (monthMatch) {
                return parseInt(monthMatch[1]) * 30; // Approximate
            }
            
            // Try to extract any number from the string
            const numberMatch = dur.match(/(\d+)/);
            if (numberMatch) {
                return parseInt(numberMatch[1]);
            }
            
            return 0; // Default to 0 if can't parse
        }
        
        // Calculate total quantity: quantity_per_intake × frequency_per_day × duration_in_days
        function calculateTotalQuantity(inputElement) {
            const row = inputElement.closest('.prescription-row');
            if (!row) return;
            
            // Get quantity per intake
            const quantityInput = row.querySelector('.quantity-per-intake-input');
            const quantityPerIntake = quantityInput ? parseFloat(quantityInput.value) || 0 : 0;
            
            // Get frequency
            const frequencySelect = row.querySelector('.frequency-select');
            const frequencyOtherInput = row.querySelector('.frequency-other-input');
            let frequency = '';
            
            if (frequencySelect && frequencySelect.style.display !== 'none') {
                frequency = frequencySelect.value || '';
            } else if (frequencyOtherInput) {
                frequency = frequencyOtherInput.value || '';
            }
            
            const frequencyPerDay = parseFrequencyPerDay(frequency);
            
            // Get duration
            const durationSelect = row.querySelector('.duration-select');
            const durationOtherInput = row.querySelector('.duration-other-input');
            let duration = '';
            
            if (durationSelect && durationSelect.style.display !== 'none') {
                duration = durationSelect.value || '';
            } else if (durationOtherInput) {
                duration = durationOtherInput.value || '';
            }
            
            const durationInDays = parseDurationInDays(duration);
            
            // Calculate total quantity
            let totalQuantity = 0;
            if (quantityPerIntake > 0 && frequencyPerDay > 0 && durationInDays > 0) {
                totalQuantity = quantityPerIntake * frequencyPerDay * durationInDays;
            }
            
            // Update the total quantity input
            const totalQuantityInput = row.querySelector('.total-quantity-input');
            if (totalQuantityInput) {
                totalQuantityInput.value = Math.round(totalQuantity);
            }
        }
        
        // Validate quantity against available inventory
        function validateQuantity(input) {
            const row = input.closest('.prescription-row');
            const quantityInfo = row.querySelector('.quantity-info');
            const quantityError = row.querySelector('.quantity-error');
            const maxQuantity = parseInt(input.dataset.maxQuantity) || 0;
            
            if (maxQuantity > 0) {
                const enteredQty = parseInt(input.value) || 0;
                
                if (enteredQty > maxQuantity) {
                    input.value = maxQuantity;
                    if (quantityError) {
                        quantityError.textContent = `Cannot exceed available stock (${maxQuantity})`;
                        quantityError.style.display = 'block';
                    }
                    return false;
                } else {
                    if (quantityError) {
                        quantityError.style.display = 'none';
                    }
                    return true;
                }
            }
            return true;
        }
        
        // Initialize quantity validation
        function initializeQuantityValidation() {
            document.querySelectorAll('.quantity-input').forEach(input => {
                if (input.dataset.validated === 'true') return;
                input.dataset.validated = 'true';
                
                input.addEventListener('input', function() {
                    validateQuantity(this);
                });
                
                input.addEventListener('blur', function() {
                    validateQuantity(this);
                });
                
                input.addEventListener('change', function() {
                    validateQuantity(this);
                });
            });
        }

        // Toggle date filter type
        function toggleDateFilterType() {
            const filterType = document.getElementById('dateFilterType').value;
            const singleDateFilter = document.getElementById('singleDateFilter');
            const rangeDateFilter = document.getElementById('rangeDateFilter');
            
            if (filterType === 'single') {
                singleDateFilter.style.display = 'block';
                rangeDateFilter.style.display = 'none';
            } else if (filterType === 'range') {
                singleDateFilter.style.display = 'none';
                rangeDateFilter.style.display = 'block';
            } else {
                singleDateFilter.style.display = 'none';
                rangeDateFilter.style.display = 'none';
            }
            
            // Load history when filter type changes
            loadTriageHistory();
        }
        
        // Load triage history with filters
        async function loadTriageHistory() {
            const container = document.getElementById('triageHistoryContainer');
            if (!container) return;
            
            const patientId = <?php echo json_encode($patient_id); ?>;
            if (!patientId || patientId <= 0) {
                container.innerHTML = '<div style="text-align: center; padding: 40px; color: #999;">No patient selected.</div>';
                return;
            }
            
            // Show loading
            container.innerHTML = '<div style="text-align: center; padding: 40px; color: #999;"><i class="fas fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 10px;"></i><div>Loading history...</div></div>';
            
            try {
                const filterType = document.getElementById('dateFilterType').value;
                let url = `doctor_get_triage_history.php?patient_id=${patientId}`;
                
                if (filterType === 'single') {
                    const singleDate = document.getElementById('filterSingleDate').value;
                    if (singleDate) {
                        url += `&single_date=${singleDate}`;
                    }
                } else if (filterType === 'range') {
                    const dateFrom = document.getElementById('filterDateFrom').value;
                    const dateTo = document.getElementById('filterDateTo').value;
                    if (dateFrom) {
                        url += `&date_from=${dateFrom}`;
                    }
                    if (dateTo) {
                        url += `&date_to=${dateTo}`;
                    }
                }
                
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    displayTriageHistory(data.records);
                } else {
                    container.innerHTML = `<div style="text-align: center; padding: 40px; color: #dc3545;"><i class="fas fa-exclamation-circle" style="font-size: 24px; margin-bottom: 10px;"></i><div>Error: ${data.message || 'Failed to load history'}</div></div>`;
                }
            } catch (error) {
                console.error('Error loading triage history:', error);
                container.innerHTML = `<div style="text-align: center; padding: 40px; color: #dc3545;"><i class="fas fa-exclamation-circle" style="font-size: 24px; margin-bottom: 10px;"></i><div>Error loading history. Please try again.</div></div>`;
            }
        }
        
        // Display triage history records with compact expandable view
        function displayTriageHistory(records) {
            const container = document.getElementById('triageHistoryContainer');
            if (!container) return;
            
            if (!records || records.length === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 40px; color: #999;"><i class="fas fa-inbox" style="font-size: 24px; margin-bottom: 10px;"></i><div style="font-size: 14px;">No screening records found for the selected filter.</div></div>';
                return;
            }
            
            let html = '<div style="display: flex; flex-direction: column; gap: 20px;">';
            
            records.forEach((record, index) => {
                const recordId = `triage-record-${index}`;
                const dateTime = record.created_at ? new Date(record.created_at) : null;
                const formattedDate = dateTime ? dateTime.toLocaleDateString('en-US', { 
                    year: 'numeric', 
                    month: 'short', 
                    day: 'numeric' 
                }) : 'N/A';
                const formattedTime = dateTime ? dateTime.toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit',
                    hour12: true
                }) : 'N/A';
                
                // Check if there are additional details to show
                const hasDetails = record.weight || record.pulse_rate || record.oxygen_saturation;
                
                html += `
                    <div class="triage-history-entry" style="background: #ffffff; border: 1px solid #e0e0e0; border-radius: 6px; overflow: hidden; transition: all 0.2s ease;">
                        <!-- Compact Summary View -->
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; cursor: ${hasDetails ? 'pointer' : 'default'};" onclick="${hasDetails ? `toggleTriageDetails('${recordId}')` : ''}">
                            <div style="flex: 1; display: flex; align-items: center; gap: 20px;">
                                <!-- Date/Time - Primary Label -->
                                <div style="min-width: 140px;">
                                    <div style="font-size: 15px; font-weight: 600; color: #2E7D32; margin-bottom: 2px;">
                                        ${formattedDate}
                                    </div>
                                    <div style="font-size: 12px; color: #666;">
                                        ${formattedTime}
                                    </div>
                                </div>
                                
                                <!-- Blood Pressure -->
                                <div style="min-width: 100px;">
                                    <div style="font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 3px;">Blood Pressure</div>
                                    <div style="font-size: 14px; font-weight: 600; color: #333;">
                                        ${escapeHtml(record.blood_pressure || 'N/A')}
                                    </div>
                                </div>
                                
                                <!-- Temperature -->
                                <div style="min-width: 90px;">
                                    <div style="font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 3px;">Temperature</div>
                                    <div style="font-size: 14px; font-weight: 600; color: #333;">
                                        ${record.temperature ? parseFloat(record.temperature).toFixed(1) + ' °C' : 'N/A'}
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Expand/Collapse Button -->
                            ${hasDetails ? `
                            <div style="display: flex; align-items: center; gap: 8px; color: #4CAF50; font-size: 12px; font-weight: 500;">
                                <span class="triage-toggle-text">View Details</span>
                                <i class="fas fa-chevron-down triage-toggle-icon" style="transition: transform 0.2s ease; font-size: 10px;"></i>
                            </div>
                            ` : ''}
                        </div>
                        
                        <!-- Expanded Details (Hidden by default) -->
                        ${hasDetails ? `
                        <div id="${recordId}" class="triage-details" style="display: none; padding: 0 16px 16px 16px; border-top: 1px solid #f0f0f0; margin-top: 0;">
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; padding-top: 14px;">
                                ${record.weight ? `
                                <div style="background: #f8f9fa; padding: 10px 12px; border-radius: 4px;">
                                    <div style="font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Weight</div>
                                    <div style="font-size: 14px; font-weight: 600; color: #555;">
                                        ${parseFloat(record.weight).toFixed(1)} kg
                                    </div>
                                </div>
                                ` : ''}
                                ${record.pulse_rate ? `
                                <div style="background: #f8f9fa; padding: 10px 12px; border-radius: 4px;">
                                    <div style="font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Pulse Rate</div>
                                    <div style="font-size: 14px; font-weight: 600; color: #555;">
                                        ${escapeHtml(record.pulse_rate)} bpm
                                    </div>
                                </div>
                                ` : ''}
                                ${record.oxygen_saturation ? `
                                <div style="background: #f8f9fa; padding: 10px 12px; border-radius: 4px;">
                                    <div style="font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Oxygen Saturation</div>
                                    <div style="font-size: 14px; font-weight: 600; color: #555;">
                                        ${parseFloat(record.oxygen_saturation).toFixed(1)}%
                                    </div>
                                </div>
                                ` : ''}
                                ${record.recorded_by_name ? `
                                <div style="background: #f8f9fa; padding: 10px 12px; border-radius: 4px;">
                                    <div style="font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Recorded By</div>
                                    <div style="font-size: 13px; font-weight: 500; color: #555;">
                                        ${escapeHtml(record.recorded_by_name)}
                                    </div>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                        ` : ''}
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
        }
        
        // Toggle triage details - only one expanded at a time
        function toggleTriageDetails(recordId) {
            // Get the clicked entry
            const details = document.getElementById(recordId);
            if (!details) return;
            
            const entry = details.closest('.triage-history-entry');
            const icon = entry ? entry.querySelector('.triage-toggle-icon') : null;
            const text = entry ? entry.querySelector('.triage-toggle-text') : null;
            
            // Check if this entry is currently expanded
            const isExpanded = details.style.display === 'block';
            
            // Close all entries first
            const allDetails = document.querySelectorAll('.triage-details');
            const allEntries = document.querySelectorAll('.triage-history-entry');
            
            allDetails.forEach((detail) => {
                detail.style.display = 'none';
            });
            
            allEntries.forEach((ent) => {
                const entIcon = ent.querySelector('.triage-toggle-icon');
                const entText = ent.querySelector('.triage-toggle-text');
                if (entIcon) entIcon.style.transform = 'rotate(0deg)';
                if (entText) entText.textContent = 'View Details';
                ent.style.borderColor = '#e0e0e0';
            });
            
            // If the clicked entry was not expanded, expand it now
            if (!isExpanded) {
                details.style.display = 'block';
                if (icon) icon.style.transform = 'rotate(180deg)';
                if (text) text.textContent = 'Hide Details';
                if (entry) entry.style.borderColor = '#4CAF50';
            }
        }
        
        // Helper function to escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Load history on page load
        document.addEventListener('DOMContentLoaded', function() {
            const patientId = <?php echo json_encode($patient_id); ?>;
            if (patientId && patientId > 0) {
                loadTriageHistory();
            }
        });

        // Toggle follow-up fields
        function toggleFollowUpFields() {
            const checkbox = document.getElementById('followUpRequired');
            const fields = document.getElementById('followUpFields');
            const reasonSelect = document.getElementById('followUpReason');
            const dateInput = document.getElementById('followUpDate');
            const timeInput = document.getElementById('followUpTime');
            
            if (checkbox.checked) {
                fields.style.display = 'block';
                if (reasonSelect) reasonSelect.required = true;
                if (dateInput) dateInput.required = true;
                if (timeInput) timeInput.required = true;
            } else {
                fields.style.display = 'none';
                if (reasonSelect) {
                    reasonSelect.required = false;
                    reasonSelect.value = '';
                }
                if (dateInput) {
                    dateInput.required = false;
                    // Clear flatpickr value if it exists
                    if (dateInput._flatpickr) {
                        dateInput._flatpickr.clear();
                    } else {
                        dateInput.value = '';
                    }
                }
                if (timeInput) {
                    timeInput.required = false;
                    timeInput.value = '';
                }
                document.getElementById('followUpReasonOther').style.display = 'none';
                document.getElementById('followUpReasonOtherText').value = '';
            }
        }

        // Handle follow-up reason "Other" option
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Flatpickr for follow-up date to block weekends
            const followUpDateInput = document.getElementById('followUpDate');
            if (followUpDateInput) {
                // Calculate minimum date (tomorrow)
                const tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                const minDate = tomorrow.toISOString().split('T')[0];
                
                // Initialize Flatpickr with weekend blocking
                const flatpickrInstance = flatpickr(followUpDateInput, {
                    dateFormat: "Y-m-d",
                    minDate: minDate,
                    disable: [
                        function(date) {
                            // Disable weekends (Saturday = 6, Sunday = 0)
                            return (date.getDay() === 0 || date.getDay() === 6);
                        }
                    ],
                    onChange: function(selectedDates, dateStr, instance) {
                        // Ensure the input is required when a date is selected
                        if (dateStr) {
                            followUpDateInput.setAttribute('required', 'required');
                        }
                    }
                });
                
                // Store the flatpickr instance for later use if needed
                followUpDateInput._flatpickr = flatpickrInstance;
            }
            
            const reasonSelect = document.getElementById('followUpReason');
            if (reasonSelect) {
                reasonSelect.addEventListener('change', function() {
                    const otherDiv = document.getElementById('followUpReasonOther');
                    const otherInput = document.getElementById('followUpReasonOtherText');
                    if (this.value === 'Other') {
                        otherDiv.style.display = 'block';
                        if (otherInput) otherInput.required = true;
                    } else {
                        otherDiv.style.display = 'none';
                        if (otherInput) {
                            otherInput.required = false;
                            otherInput.value = '';
                        }
                    }
                });
            }
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            syncAppointmentSelection();
            // Load triage for initially selected appointment
            const initialAppointment = document.getElementById('triage_appointment_id')?.value;
            if (initialAppointment) {
                loadTriageForAppointment(initialAppointment);
            }
            // Initialize medicine autocomplete for existing rows
            initializeMedicineAutocomplete();
            // Initialize quantity validation
            initializeQuantityValidation();
            // Initialize lab test autocomplete for existing rows
            document.querySelectorAll('.lab-test-request-row').forEach(row => {
                initializeLabTestAutocomplete(row);
                initializeLaboratoryAutocomplete(row);
            });
        });

        // Notification function
        function showNotification(message, type = 'success') {
            // Remove any existing notifications
            const existingNotification = document.querySelector('.notification');
            if (existingNotification) {
                existingNotification.remove();
            }
            
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                <span>${message}</span>
            `;
            
            document.body.appendChild(notification);
            
            // Auto-remove after 5 seconds (or 3 seconds for errors)
            setTimeout(() => {
                notification.style.animation = 'slideInRight 0.3s ease reverse';
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }, type === 'error' ? 5000 : 3000);
        }
        
        // Inline validation functions
        function clearFieldError(field) {
            try {
                if (typeof field === 'string') {
                    field = document.getElementById(field) || document.querySelector(field);
                }
                if (!field) return;
                
                if (field.classList) {
                    field.classList.remove('invalid');
                }
                const formGroup = field.closest('.form-group') || field.closest('div[style*="grid-column"]') || field.parentElement;
                if (formGroup && formGroup.classList) {
                    formGroup.classList.remove('has-error');
                }
                // Try to find error message by data-field attribute or by proximity
                const fieldId = field.id || field.name;
                let errorMsg = null;
                if (fieldId && field.parentElement) {
                    errorMsg = field.parentElement.querySelector(`.error-message[data-field="${fieldId}"]`);
                }
                // If not found, look for any error message in the same container
                if (!errorMsg && (formGroup || field.parentElement)) {
                    errorMsg = (formGroup || field.parentElement).querySelector('.error-message');
                }
                if (errorMsg && errorMsg.classList) {
                    errorMsg.classList.remove('show');
                }
            } catch (err) {
                console.error('Error in clearFieldError:', err);
            }
        }
        
        function showFieldError(field, message) {
            try {
                if (typeof field === 'string') {
                    field = document.getElementById(field) || document.querySelector(field);
                }
                if (!field) return;
                
                if (field.classList) {
                    field.classList.add('invalid');
                }
                const formGroup = field.closest('.form-group') || field.closest('div[style*="grid-column"]') || field.parentElement;
                if (formGroup && formGroup.classList) {
                    formGroup.classList.add('has-error');
                }
                // Try to find error message by data-field attribute or by proximity
                const fieldId = field.id || field.name;
                let errorMsg = null;
                if (fieldId && field.parentElement) {
                    errorMsg = field.parentElement.querySelector(`.error-message[data-field="${fieldId}"]`);
                }
                // If not found, look for any error message in the same container
                if (!errorMsg && (formGroup || field.parentElement)) {
                    errorMsg = (formGroup || field.parentElement).querySelector('.error-message');
                }
                if (errorMsg) {
                    if (errorMsg.textContent !== undefined) {
                        errorMsg.textContent = message || 'This field is required';
                    }
                    if (errorMsg.classList) {
                        errorMsg.classList.add('show');
                    }
                }
            } catch (err) {
                console.error('Error in showFieldError:', err);
            }
        }
        
        function validateConsultationForm() {
            try {
                let isValid = true;
                let firstInvalidField = null;
                
                // Clear all previous errors
                try {
                    document.querySelectorAll('.form-control.invalid').forEach(field => {
                        clearFieldError(field);
                    });
                } catch (e) {
                    console.error('Error clearing field errors:', e);
                }
            
            // Validate diagnosis
            const diagnosisField = document.querySelector('textarea[name="diagnosis"]');
            if (!diagnosisField || !diagnosisField.value.trim()) {
                showFieldError(diagnosisField, 'This field is required');
                isValid = false;
                if (!firstInvalidField) firstInvalidField = diagnosisField;
            }
            
            // Validate follow-up fields if follow-up is required
            const followUpRequired = document.getElementById('followUpRequired')?.checked || false;
            if (followUpRequired) {
                const followUpDate = document.getElementById('followUpDate');
                const followUpTime = document.getElementById('followUpTime');
                const followUpReason = document.getElementById('followUpReason');
                
                if (!followUpDate || !followUpDate.value) {
                    showFieldError(followUpDate, 'This field is required');
                    isValid = false;
                    if (!firstInvalidField) firstInvalidField = followUpDate;
                }
                
                if (!followUpTime || !followUpTime.value) {
                    showFieldError(followUpTime, 'This field is required');
                    isValid = false;
                    if (!firstInvalidField) firstInvalidField = followUpTime;
                }
                
                if (!followUpReason || !followUpReason.value) {
                    showFieldError(followUpReason, 'This field is required');
                    isValid = false;
                    if (!firstInvalidField) firstInvalidField = followUpReason;
                } else if (followUpReason.value === 'Other') {
                    const followUpReasonOther = document.getElementById('followUpReasonOtherText');
                    if (!followUpReasonOther || !followUpReasonOther.value.trim()) {
                        showFieldError(followUpReasonOther, 'Please specify the reason');
                        isValid = false;
                        if (!firstInvalidField) firstInvalidField = followUpReasonOther;
                    }
                }
            }
            
            // Validate prescription section only if medicines are added (optional section)
            const prescriptionRows = document.querySelectorAll('.prescription-row');
            let hasPrescriptionData = false;
            prescriptionRows.forEach((row, index) => {
                if (row.classList.contains('external-prescription-row')) {
                    const nameInput = row.querySelector('.external-medicine-name');
                    if (nameInput && nameInput.value.trim()) {
                        hasPrescriptionData = true;
                        const formSelect = row.querySelector('select[name="external_medicine_form[]"]');
                        const formOtherInput = row.querySelector('.medicine_form-other-input');
                        if (!formSelect || !formSelect.value) {
                            showFieldError(formSelect, 'Please select Medicine Form for this medicine');
                            isValid = false;
                            if (!firstInvalidField) firstInvalidField = formSelect;
                        } else if (formSelect.value === '__other__' && (!formOtherInput || !formOtherInput.value.trim())) {
                            showFieldError(formOtherInput || formSelect, 'Please specify the medicine form');
                            isValid = false;
                            if (!firstInvalidField) firstInvalidField = (formOtherInput || formSelect);
                        }
                    }
                } else {
                    const medicineInput = row.querySelector('.medicine-autocomplete');
                    if (medicineInput && medicineInput.value.trim()) {
                        hasPrescriptionData = true;
                        const formSelect = row.querySelector('select[name="medicine_form[]"]');
                        const formOtherInput = row.querySelector('.medicine_form-other-input');
                        if (!formSelect || !formSelect.value) {
                            showFieldError(formSelect, 'Please select Medicine Form for this medicine');
                            isValid = false;
                            if (!firstInvalidField) firstInvalidField = formSelect;
                        } else if (formSelect.value === '__other__' && (!formOtherInput || !formOtherInput.value.trim())) {
                            showFieldError(formOtherInput || formSelect, 'Please specify the medicine form');
                            isValid = false;
                            if (!firstInvalidField) firstInvalidField = (formOtherInput || formSelect);
                        }
                    }
                }
            });
            
            // Only validate prescription validity period if prescription data exists
            if (hasPrescriptionData) {
                const prescriptionValidityPeriod = document.getElementById('prescriptionValidityPeriod');
                if (prescriptionValidityPeriod && !prescriptionValidityPeriod.value) {
                    showFieldError(prescriptionValidityPeriod, 'Please select a validity period for the prescription');
                    isValid = false;
                    if (!firstInvalidField) firstInvalidField = prescriptionValidityPeriod;
                } else if (prescriptionValidityPeriod && prescriptionValidityPeriod.value === 'custom') {
                    const customExpirationDate = document.getElementById('prescriptionCustomExpirationDate');
                    if (!customExpirationDate || !customExpirationDate.value) {
                        showFieldError(customExpirationDate, 'Please select a custom expiration date');
                        isValid = false;
                        if (!firstInvalidField) firstInvalidField = customExpirationDate;
                    }
                }
            }
            
                // Scroll to first invalid field
                if (!isValid && firstInvalidField) {
                    try {
                        firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        setTimeout(() => {
                            if (firstInvalidField && firstInvalidField.focus) {
                                firstInvalidField.focus();
                            }
                        }, 300);
                    } catch (e) {
                        console.error('Error scrolling to invalid field:', e);
                    }
                }
                
                return isValid;
            } catch (err) {
                console.error('Error in validateConsultationForm:', err);
                return false;
            }
        }
        
        // Add event listeners to clear errors when fields are filled
        document.addEventListener('DOMContentLoaded', function() {
            // Diagnosis field
            const diagnosisField = document.querySelector('textarea[name="diagnosis"]');
            if (diagnosisField) {
                diagnosisField.addEventListener('input', function() {
                    if (this.value.trim()) {
                        clearFieldError(this);
                    }
                });
            }
            
            // Follow-up fields
            const followUpDate = document.getElementById('followUpDate');
            const followUpTime = document.getElementById('followUpTime');
            const followUpReason = document.getElementById('followUpReason');
            const followUpReasonOther = document.getElementById('followUpReasonOtherText');
            
            if (followUpDate) {
                followUpDate.addEventListener('change', function() {
                    if (this.value) clearFieldError(this);
                });
            }
            if (followUpTime) {
                followUpTime.addEventListener('change', function() {
                    if (this.value) clearFieldError(this);
                });
            }
            if (followUpReason) {
                followUpReason.addEventListener('change', function() {
                    if (this.value) clearFieldError(this);
                });
            }
            if (followUpReasonOther) {
                followUpReasonOther.addEventListener('input', function() {
                    if (this.value.trim()) clearFieldError(this);
                });
            }
            
            // Prescription validity period
            const prescriptionValidityPeriod = document.getElementById('prescriptionValidityPeriod');
            const prescriptionCustomExpirationDate = document.getElementById('prescriptionCustomExpirationDate');
            
            if (prescriptionValidityPeriod) {
                prescriptionValidityPeriod.addEventListener('change', function() {
                    if (this.value) clearFieldError(this);
                });
            }
            if (prescriptionCustomExpirationDate) {
                prescriptionCustomExpirationDate.addEventListener('change', function() {
                    if (this.value) clearFieldError(this);
                });
            }
            
            // Referral fields
            const referredHospitalInput = document.getElementById('referredHospitalInput');
            const customHospitalName = document.getElementById('customHospitalName');
            const reasonForReferral = document.getElementById('reasonForReferral');
            const reasonOtherText = document.getElementById('reasonOtherText');
            
            if (referredHospitalInput) {
                referredHospitalInput.addEventListener('input', function() {
                    if (this.value.trim()) clearFieldError(this);
                });
            }
            if (customHospitalName) {
                customHospitalName.addEventListener('input', function() {
                    if (this.value.trim()) clearFieldError(this);
                });
            }
            if (reasonForReferral) {
                reasonForReferral.addEventListener('change', function() {
                    if (this.value) clearFieldError(this);
                });
            }
            if (reasonOtherText) {
                reasonOtherText.addEventListener('input', function() {
                    if (this.value.trim()) clearFieldError(this);
                });
            }
            
        });
        
        // Prescription medicine fields - global listener (outside DOMContentLoaded to avoid conflicts)
        // Only add this listener after DOM is ready to prevent conflicts
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                document.addEventListener('input', function(e) {
                    try {
                        if (e.target && e.target.classList && e.target.classList.contains('medicine-autocomplete')) {
                            if (e.target.value.trim() && typeof clearFieldError === 'function') {
                                clearFieldError(e.target);
                            }
                        }
                    } catch (err) {
                        console.error('Error in input listener:', err);
                    }
                });
            });
        } else {
            document.addEventListener('input', function(e) {
                try {
                    if (e.target && e.target.classList && e.target.classList.contains('medicine-autocomplete')) {
                        if (e.target.value.trim() && typeof clearFieldError === 'function') {
                            clearFieldError(e.target);
                        }
                    }
                } catch (err) {
                    console.error('Error in input listener:', err);
                }
            });
        }

        // Handle Done button click
        document.addEventListener('DOMContentLoaded', function() {
            const doneButton = document.getElementById('doneButton');
            if (doneButton) {
                doneButton.addEventListener('click', async () => {
                const consultationForm = document.getElementById('consultationForm');
                if (!consultationForm) return;

                // Validate form first
                if (!validateConsultationForm()) {
                    return;
                }

                // Get diagnosis
                const diagnosis = consultationForm.querySelector('textarea[name="diagnosis"]').value.trim();

                // Get patient instructions
                const patientInstructions = consultationForm.querySelector('textarea[name="patient_instructions"]')?.value.trim() || '';

                // Get follow-up data
                const followUpRequired = document.getElementById('followUpRequired')?.checked || false;
                let followUpDate = '';
                let followUpTime = '';
                let followUpReason = '';
                
                if (followUpRequired) {
                    followUpDate = document.getElementById('followUpDate')?.value || '';
                    followUpTime = document.getElementById('followUpTime')?.value || '';
                    followUpReason = document.getElementById('followUpReason')?.value || '';
                    
                    if (followUpReason === 'Other') {
                        followUpReason = document.getElementById('followUpReasonOtherText')?.value.trim() || '';
                    }
                }

                // Get appointment ID from hidden field (synced from top appointment selector)
                const appointmentId = consultationForm.querySelector('input[name="appointment_id"]').value;

                // Validate antibiotic rows: frequency and duration required, duration max 7 days
                const antibioticValidation = validateAntibioticPrescriptionRows();
                if (!antibioticValidation.valid) {
                    showNotification(antibioticValidation.message, 'error');
                    doneButton.disabled = false;
                    doneButton.innerHTML = '<i class="fas fa-check-circle"></i> Complete Consultation';
                    return;
                }

                // Get prescription medicines (inventory + external)
                const rows = document.querySelectorAll('.prescription-row');
                const medicines = [];
                rows.forEach(row => {
                    if (row.classList.contains('external-prescription-row')) {
                        const nameInput = row.querySelector('.external-medicine-name');
                        const name = nameInput ? nameInput.value.trim() : '';
                        if (!name) return;
                        const dosageSelect = row.querySelector('select[name="external_dosage[]"]');
                        const dosageOtherInput = row.querySelector('input[name="external_dosage_other[]"]');
                        const frequencySelect = row.querySelector('select[name="external_frequency[]"]');
                        const frequencyOtherInput = row.querySelector('input[name="external_frequency_other[]"]');
                        const durationSelect = row.querySelector('select[name="external_duration[]"]');
                        const durationOtherInput = row.querySelector('input[name="external_duration_other[]"]');
                        let dosage = '';
                        if (dosageSelect && dosageSelect.value === '__other__' && dosageOtherInput) {
                            dosage = dosageOtherInput.value.trim();
                        } else if (dosageSelect && dosageSelect.value) {
                            dosage = dosageSelect.value;
                        }
                        let frequency = '';
                        if (frequencySelect && frequencySelect.value === '__other__' && frequencyOtherInput) {
                            frequency = frequencyOtherInput.value.trim();
                        } else if (frequencySelect && frequencySelect.value) {
                            frequency = frequencySelect.value;
                        }
                        let duration = '';
                        if (durationSelect && durationSelect.value === '__other__' && durationOtherInput) {
                            duration = durationOtherInput.value.trim();
                        } else if (durationSelect && durationSelect.value) {
                            duration = durationSelect.value;
                        }
                        const instructionsInput = row.querySelector('textarea[name="external_instructions[]"]');
                        const formSelect = row.querySelector('select[name="external_medicine_form[]"]');
                        const medicineFormOtherInput = row.querySelector('.medicine_form-other-input');
                        const medicine_form_val = formSelect ? (formSelect.value === '__other__' ? (medicineFormOtherInput ? medicineFormOtherInput.value.trim() : '') : formSelect.value) : '';
                        medicines.push({
                            name: name,
                            medicine_form: medicine_form_val,
                            dosage: dosage,
                            frequency: frequency,
                            duration: duration,
                            instructions: instructionsInput ? instructionsInput.value.trim() : '',
                            is_external: 1,
                            quantity: 0,
                            total_quantity: 0
                        });
                        return;
                    }
                    const medicineInput = row.querySelector('.medicine-autocomplete');
                    const medicineIdInput = row.querySelector('.medicine-id-input');
                    const quantityInput = row.querySelector('input[name="quantity[]"]');
                    const dosageSelect = row.querySelector('.dosage-select');
                    const dosageOtherInput = row.querySelector('.dosage-other-input');
                    const frequencySelect = row.querySelector('.frequency-select');
                    const frequencyOtherInput = row.querySelector('.frequency-other-input');
                    const durationSelect = row.querySelector('.duration-select');
                    const durationOtherInput = row.querySelector('.duration-other-input');
                    
                    let medicineName = '';
                    if (medicineInput && medicineInput.value.trim()) {
                        medicineName = medicineInput.value.trim();
                    }
                    
                    if (medicineName) {
                        // Get quantity - ensure it's properly parsed
                        let quantity = 1; // Default
                        if (quantityInput && quantityInput.value) {
                            const parsed = parseInt(quantityInput.value.trim());
                            if (!isNaN(parsed) && parsed > 0) {
                                quantity = parsed;
                            }
                        }
                        
                        // Get dosage - check if "Other" was selected
                        let dosage = '';
                        if (dosageSelect && dosageSelect.value === '__other__' && dosageOtherInput) {
                            dosage = dosageOtherInput.value.trim();
                        } else if (dosageSelect && dosageSelect.value) {
                            dosage = dosageSelect.value;
                        }
                        
                        // Get frequency - check if "Other" was selected
                        let frequency = '';
                        if (frequencySelect && frequencySelect.value === '__other__' && frequencyOtherInput) {
                            frequency = frequencyOtherInput.value.trim();
                        } else if (frequencySelect && frequencySelect.value) {
                            frequency = frequencySelect.value;
                        }
                        
                        // Get duration - check if "Other" was selected
                        let duration = '';
                        if (durationSelect && durationSelect.value === '__other__' && durationOtherInput) {
                            duration = durationOtherInput.value.trim();
                        } else if (durationSelect && durationSelect.value) {
                            duration = durationSelect.value;
                        }
                        
                        // Get total_quantity (auto-calculated)
                        let totalQuantity = 0;
                        const totalQuantityInput = row.querySelector('.total-quantity-input');
                        if (totalQuantityInput && totalQuantityInput.value) {
                            const parsed = parseInt(totalQuantityInput.value.trim());
                            if (!isNaN(parsed) && parsed >= 0) {
                                totalQuantity = parsed;
                            }
                        }
                        
                        const timingSelect = row.querySelector('select[name="timing_of_intake[]"]');
                        const timing_of_intake = timingSelect ? timingSelect.value : '';
                        const medicineFormSelect = row.querySelector('select[name="medicine_form[]"]');
                        const medicineFormOtherInput = row.querySelector('.medicine_form-other-input');
                        const medicine_form = medicineFormSelect ? (medicineFormSelect.value === '__other__' ? (medicineFormOtherInput ? medicineFormOtherInput.value.trim() : '') : medicineFormSelect.value) : '';
                        medicines.push({
                            medicine_id: medicineIdInput ? medicineIdInput.value : null,
                            name: medicineName,
                            medicine_form: medicine_form,
                            quantity: quantity,
                            total_quantity: totalQuantity,
                            dosage: dosage,
                            frequency: frequency,
                            duration: duration,
                            timing_of_intake: timing_of_intake
                        });
                    }
                });

                // Show loading
                doneButton.disabled = true;
                doneButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

                try {
                    // Save consultation and create/update appointment
                    const formData = new FormData();
                    formData.append('action', 'complete_consultation');
                    formData.append('patient_id', patientId);
                    formData.append('doctor_id', doctorId);
                    formData.append('appointment_id', appointmentId || '');
                    formData.append('diagnosis', diagnosis);
                    formData.append('patient_instructions', patientInstructions);
                    formData.append('medicines', JSON.stringify(medicines));
                    
                    // Include current prescription_id if it exists (from "Done" button)
                    const currentPrescriptionId = document.getElementById('currentPrescriptionId')?.value || '';
                    if (currentPrescriptionId) {
                        formData.append('current_prescription_id', currentPrescriptionId);
                    }
                    formData.append('follow_up_required', followUpRequired ? '1' : '0');
                    if (followUpRequired) {
                        formData.append('follow_up_date', followUpDate);
                        formData.append('follow_up_time', followUpTime);
                        formData.append('follow_up_reason', followUpReason);
                    }
                    
                    // Collect lab requests (packages + individual tests per card)
                    const labTestRequests = [];
                    document.querySelectorAll('.lab-request-card').forEach(card => {
                        const labName = (card.querySelector('.lab-request-lab')?.value || '').trim();
                        const notes = (card.querySelector('.lab-request-notes')?.value || '').trim();
                        const tests = getLabRequestTestsFromCard(card);
                        if (tests.length > 0) {
                            labTestRequests.push({
                                laboratory_name: labName || null,
                                laboratory_type: labName ? 'custom' : 'select',
                                notes: notes || '',
                                tests: tests
                            });
                        }
                    });
                    formData.append('lab_test_requests', JSON.stringify(labTestRequests));
                    
                    // Add prescription validity period only if there are medicines
                    const prescriptionRows = document.querySelectorAll('.prescription-row');
                    let hasPrescriptionMedicines = false;
                    prescriptionRows.forEach((row) => {
                        if (row.classList.contains('external-prescription-row')) {
                            const nameInput = row.querySelector('.external-medicine-name');
                            if (nameInput && nameInput.value.trim()) hasPrescriptionMedicines = true;
                        } else {
                            const medicineInput = row.querySelector('.medicine-autocomplete');
                            if (medicineInput && medicineInput.value.trim()) hasPrescriptionMedicines = true;
                        }
                    });
                    
                    if (hasPrescriptionMedicines) {
                        const prescriptionValidityPeriod = document.getElementById('prescriptionValidityPeriod')?.value || '14';
                        formData.append('prescription_validity_period', prescriptionValidityPeriod);
                        if (prescriptionValidityPeriod === 'custom') {
                            const customExpirationDate = document.getElementById('prescriptionCustomExpirationDate')?.value || '';
                            if (customExpirationDate) {
                                formData.append('prescription_custom_expiration_date', customExpirationDate);
                            }
                        }
                    } else {
                        // No prescription validity period needed if no medicines
                        formData.append('prescription_validity_period', '');
                    }

                    const res = await fetch('doctor_consultation_handler.php', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await res.json();
                    
                    if (!data.success) {
                        throw new Error(data.message || 'Unable to save consultation');
                    }

                    // Show success notification
                    showNotification('Consultation completed successfully! The information has been saved to the patient\'s record.', 'success');
                    
                    // Pop-up confirmation then close consultation and return to patients page
                    if (window.self !== window.top) {
                        alert('Okay na ang consultation.');
                        try {
                            window.parent.closeConsultModal();
                            if (typeof window.parent.loadPatients === 'function') {
                                window.parent.loadPatients();
                            }
                        } catch (e) { console.error(e); }
                    } else {
                        setTimeout(() => {
                            window.location.href = 'doctors_page.php';
                        }, 1500);
                    }
                } catch (err) {
                    showNotification('Error: ' + err.message, 'error');
                    doneButton.disabled = false;
                    doneButton.innerHTML = '<i class="fas fa-check-circle"></i> Complete Consultation';
                }
            });
            }
        });

        function addConsultPrescriptionRow() {
            const formsContainer = document.getElementById('prescriptionFormsContainer');
            const container = document.getElementById('consultPrescriptionRows');
            if (formsContainer) formsContainer.style.display = 'block';
            const row = document.createElement('div');
            row.className = 'dynamic-row prescription-row';
            row.innerHTML = buildPrescriptionRow();
            container.appendChild(row);
            // Defer init to next tick so the new row is fully in the DOM; ensures typing triggers search on first entry
            setTimeout(function() {
                initializeMedicineAutocomplete();
                initializeQuantityValidation();
            }, 0);
        }
        
        // Save prescription (e.g. when "Done" was clicked; button may be hidden when using expandable module)
        async function savePrescriptionDone() {
            const doneBtn = document.getElementById('prescriptionDoneBtn');
            const prescriptionIdInput = document.getElementById('currentPrescriptionId');
            const doneMessage = document.getElementById('prescriptionDoneMessage');
            if (doneBtn) { doneBtn.disabled = true; doneBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; }
            
            // Get patient and appointment info
            const consultationForm = document.getElementById('consultationForm');
            if (!consultationForm) {
                showNotification('Consultation form not found', 'error');
                return;
            }
            
            const patientId = consultationForm.querySelector('input[name="patient_id"]')?.value;
            const appointmentId = consultationForm.querySelector('input[name="appointment_id"]')?.value || '';
            const diagnosis = consultationForm.querySelector('textarea[name="diagnosis"]')?.value.trim();
            
            if (!patientId) {
                showNotification('Patient ID is required', 'error');
                return;
            }
            
            if (!diagnosis) {
                showNotification('Please enter a diagnosis before saving the prescription', 'error');
                return;
            }
            
            // Validate antibiotic rows: frequency and duration required, duration max 7 days
            const antibioticValidation = validateAntibioticPrescriptionRows();
            if (!antibioticValidation.valid) {
                showNotification(antibioticValidation.message, 'error');
                return;
            }
            
            // Collect prescription medicines (inventory + external)
            const rows = document.querySelectorAll('.prescription-row');
            const medicines = [];
            rows.forEach(row => {
                if (row.classList.contains('external-prescription-row')) {
                    const nameInput = row.querySelector('.external-medicine-name');
                    const name = nameInput ? nameInput.value.trim() : '';
                    if (!name) return;
                    const dosageSelect = row.querySelector('select[name="external_dosage[]"]');
                    const dosageOtherInput = row.querySelector('input[name="external_dosage_other[]"]');
                    const frequencySelect = row.querySelector('select[name="external_frequency[]"]');
                    const frequencyOtherInput = row.querySelector('input[name="external_frequency_other[]"]');
                    const durationSelect = row.querySelector('select[name="external_duration[]"]');
                    const durationOtherInput = row.querySelector('input[name="external_duration_other[]"]');
                    let dosage = '';
                    if (dosageSelect && dosageSelect.value === '__other__' && dosageOtherInput) {
                        dosage = dosageOtherInput.value.trim();
                    } else if (dosageSelect && dosageSelect.value) {
                        dosage = dosageSelect.value;
                    }
                    let frequency = '';
                    if (frequencySelect && frequencySelect.value === '__other__' && frequencyOtherInput) {
                        frequency = frequencyOtherInput.value.trim();
                    } else if (frequencySelect && frequencySelect.value) {
                        frequency = frequencySelect.value;
                    }
                    let duration = '';
                    if (durationSelect && durationSelect.value === '__other__' && durationOtherInput) {
                        duration = durationOtherInput.value.trim();
                    } else if (durationSelect && durationSelect.value) {
                        duration = durationSelect.value;
                    }
                    const quantityInput = row.querySelector('input[name="external_quantity[]"]');
                    const totalQuantityInput = row.querySelector('input[name="external_total_quantity[]"]');
                    let quantity = 1;
                    if (quantityInput && quantityInput.value) {
                        const parsed = parseInt(quantityInput.value.trim());
                        if (!isNaN(parsed) && parsed > 0) quantity = parsed;
                    }
                    let totalQuantity = 0;
                    if (totalQuantityInput && totalQuantityInput.value) {
                        const parsed = parseInt(totalQuantityInput.value.trim());
                        if (!isNaN(parsed) && parsed >= 0) totalQuantity = parsed;
                    }
                    const instructionsInput = row.querySelector('textarea[name="external_instructions[]"]');
                    const timingSelect = row.querySelector('select[name="external_timing_of_intake[]"]');
                    const timing_of_intake = timingSelect ? timingSelect.value : '';
                    const formSelect = row.querySelector('select[name="external_medicine_form[]"]');
                    const medicineFormOtherInput = row.querySelector('.medicine_form-other-input');
                    const medicine_form_val = formSelect ? (formSelect.value === '__other__' ? (medicineFormOtherInput ? medicineFormOtherInput.value.trim() : '') : formSelect.value) : '';
                    medicines.push({
                        name: name,
                        medicine_form: medicine_form_val,
                        dosage: dosage,
                        frequency: frequency,
                        duration: duration,
                        timing_of_intake: timing_of_intake,
                        instructions: instructionsInput ? instructionsInput.value.trim() : '',
                        is_external: 1,
                        quantity: 0,
                        total_quantity: 0
                    });
                    return;
                }
                const medicineInput = row.querySelector('.medicine-autocomplete');
                const medicineIdInput = row.querySelector('.medicine-id-input');
                const quantityInput = row.querySelector('input[name="quantity[]"]');
                const dosageSelect = row.querySelector('.dosage-select');
                const dosageOtherInput = row.querySelector('.dosage-other-input');
                const frequencySelect = row.querySelector('.frequency-select');
                const frequencyOtherInput = row.querySelector('.frequency-other-input');
                const durationSelect = row.querySelector('.duration-select');
                const durationOtherInput = row.querySelector('.duration-other-input');
                
                let medicineName = '';
                if (medicineInput && medicineInput.value.trim()) {
                    medicineName = medicineInput.value.trim();
                }
                
                if (medicineName) {
                    // Get quantity
                    let quantity = 1;
                    if (quantityInput && quantityInput.value) {
                        const parsed = parseInt(quantityInput.value.trim());
                        if (!isNaN(parsed) && parsed > 0) {
                            quantity = parsed;
                        }
                    }
                    
                    // Get dosage
                    let dosage = '';
                    if (dosageSelect && dosageSelect.value === '__other__' && dosageOtherInput) {
                        dosage = dosageOtherInput.value.trim();
                    } else if (dosageSelect && dosageSelect.value) {
                        dosage = dosageSelect.value;
                    }
                    
                    // Get frequency
                    let frequency = '';
                    if (frequencySelect && frequencySelect.value === '__other__' && frequencyOtherInput) {
                        frequency = frequencyOtherInput.value.trim();
                    } else if (frequencySelect && frequencySelect.value) {
                        frequency = frequencySelect.value;
                    }
                    
                    // Get duration
                    let duration = '';
                    if (durationSelect && durationSelect.value === '__other__' && durationOtherInput) {
                        duration = durationOtherInput.value.trim();
                    } else if (durationSelect && durationSelect.value) {
                        duration = durationSelect.value;
                    }
                    
                    // Get total_quantity
                    let totalQuantity = 0;
                    const totalQuantityInput = row.querySelector('.total-quantity-input');
                    if (totalQuantityInput && totalQuantityInput.value) {
                        const parsed = parseInt(totalQuantityInput.value.trim());
                        if (!isNaN(parsed) && parsed >= 0) {
                            totalQuantity = parsed;
                        }
                    }
                    
                    const timingSelect = row.querySelector('select[name="timing_of_intake[]"]');
                    const timing_of_intake = timingSelect ? timingSelect.value : '';
                    const medicineFormSelect = row.querySelector('select[name="medicine_form[]"]');
                    const medicineFormOtherInput = row.querySelector('.medicine_form-other-input');
                    const medicine_form = medicineFormSelect ? (medicineFormSelect.value === '__other__' ? (medicineFormOtherInput ? medicineFormOtherInput.value.trim() : '') : medicineFormSelect.value) : '';
                    medicines.push({
                        medicine_id: medicineIdInput ? medicineIdInput.value : null,
                        name: medicineName,
                        medicine_form: medicine_form,
                        quantity: quantity,
                        total_quantity: totalQuantity,
                        dosage: dosage,
                        frequency: frequency,
                        duration: duration,
                        timing_of_intake: timing_of_intake
                    });
                }
            });
            
            if (medicines.length === 0) {
                showNotification('Please add at least one medicine (from inventory or external) before saving.', 'error');
                if (doneBtn) { doneBtn.disabled = false; doneBtn.innerHTML = '<i class="fas fa-check"></i> Done'; }
                return;
            }
            const missingForm = medicines.find(m => m.name && !(m.medicine_form && m.medicine_form.trim()));
            if (missingForm) {
                showNotification('Please select Medicine Form for every medicine in the prescription.', 'error');
                if (doneBtn) { doneBtn.disabled = false; doneBtn.innerHTML = '<i class="fas fa-check"></i> Done'; }
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'save_prescription');
                formData.append('patient_id', patientId);
                formData.append('appointment_id', appointmentId);
                formData.append('diagnosis', diagnosis);
                formData.append('medicines', JSON.stringify(medicines));
                formData.append('prescription_id', prescriptionIdInput.value || ''); // Include existing prescription_id if any
                
                // Add prescription validity period
                const prescriptionValidityPeriod = document.getElementById('prescriptionValidityPeriod')?.value || '14';
                formData.append('prescription_validity_period', prescriptionValidityPeriod);
                if (prescriptionValidityPeriod === 'custom') {
                    const customExpirationDate = document.getElementById('prescriptionCustomExpirationDate')?.value || '';
                    if (customExpirationDate) {
                        formData.append('prescription_custom_expiration_date', customExpirationDate);
                    }
                }
                
                const res = await fetch('doctor_consultation_handler.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                
                if (!data.success) {
                    throw new Error(data.message || 'Unable to save prescription');
                }
                
                // Store prescription_id for later use
                if (data.prescription_id) {
                    prescriptionIdInput.value = data.prescription_id;
                }
                
                // Show success message
                if (doneMessage) doneMessage.style.display = 'block';
                showNotification('Prescription saved successfully! You can continue adding more medicines if needed.', 'success');
                
                // Reset button
                if (doneBtn) { doneBtn.disabled = false; doneBtn.innerHTML = '<i class="fas fa-check"></i> Done'; }
                
            } catch (err) {
                showNotification('Error: ' + err.message, 'error');
                if (doneBtn) { doneBtn.disabled = false; doneBtn.innerHTML = '<i class="fas fa-check"></i> Done'; }
            }
        }
        
        // Lab Test Request Functions
        // Save lab test requests when "Generate Lab Test" button is clicked
        async function saveLabTestDone() {
            const doneBtn = document.getElementById('labTestDoneBtn');
            const labTestRequestIdsInput = document.getElementById('currentLabTestRequestIds');
            const doneMessage = document.getElementById('labTestDoneMessage');
            
            // Get patient and appointment info
            const consultationForm = document.getElementById('consultationForm');
            if (!consultationForm) {
                showNotification('Consultation form not found', 'error');
                return;
            }
            
            const patientId = consultationForm.querySelector('input[name="patient_id"]')?.value;
            const appointmentId = consultationForm.querySelector('input[name="appointment_id"]')?.value || '';
            const diagnosis = consultationForm.querySelector('textarea[name="diagnosis"]')?.value.trim();
            
            if (!patientId) {
                showNotification('Patient ID is required', 'error');
                return;
            }
            
            if (!diagnosis) {
                showNotification('Please enter a diagnosis before saving lab test requests', 'error');
                return;
            }
            
            // Collect lab requests (each card = one request: packages + individual tests)
            const labTestRequests = [];
            document.querySelectorAll('.lab-request-card').forEach(card => {
                const labName = (card.querySelector('.lab-request-lab')?.value || '').trim();
                const notes = (card.querySelector('.lab-request-notes')?.value || '').trim();
                const tests = getLabRequestTestsFromCard(card);
                if (tests.length > 0) {
                    labTestRequests.push({
                        laboratory_name: labName || null,
                        laboratory_type: labName ? 'custom' : 'select',
                        notes: notes || '',
                        tests: tests
                    });
                }
            });
            
            if (labTestRequests.length === 0) {
                showNotification('Please add at least one lab request with at least one test or package before clicking Done', 'error');
                return;
            }
            
            // Show loading
            if (doneBtn) { doneBtn.disabled = true; doneBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; }
            
            try {
                const formData = new FormData();
                formData.append('action', 'save_lab_test_requests');
                formData.append('patient_id', patientId);
                formData.append('appointment_id', appointmentId);
                formData.append('diagnosis', diagnosis);
                formData.append('lab_test_requests', JSON.stringify(labTestRequests));
                
                const res = await fetch('doctor_consultation_handler.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                
                if (!data.success) {
                    throw new Error(data.message || 'Unable to save lab test requests');
                }
                
                // Store lab test request IDs for later use (if returned)
                if (data.lab_test_request_ids && data.lab_test_request_ids.length > 0) {
                    labTestRequestIdsInput.value = JSON.stringify(data.lab_test_request_ids);
                }
                
                // Append new lab request cards to the "Laboratory Test Requests" section (no refresh)
                if (labTestRequests.length > 0 && data.lab_test_request_ids && data.lab_test_request_ids.length > 0) {
                    appendLabRequestCardsToSection(labTestRequests, data.lab_test_request_ids);
                }
                
                // Show success message
                if (doneMessage) doneMessage.style.display = 'block';
                showNotification('Lab test requests saved successfully! They appear below and on the patient\'s My Record page.', 'success');
                
                // Reset button
                if (doneBtn) { doneBtn.disabled = false; doneBtn.innerHTML = '<i class="fas fa-check"></i> Done'; }
                
            } catch (err) {
                showNotification('Error: ' + err.message, 'error');
                if (doneBtn) { doneBtn.disabled = false; doneBtn.innerHTML = '<i class="fas fa-check"></i> Done'; }
            }
        }
        
        function appendLabRequestCardsToSection(requestsPayload, requestIds) {
            const list = document.getElementById('labRequestsSubmittedList');
            const placeholder = document.getElementById('labRequestsEmptyPlaceholder');
            if (!list) return;
            if (placeholder) placeholder.remove();
            const nowStr = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            requestsPayload.forEach((req, idx) => {
                const id = requestIds[idx];
                if (!id) return;
                const tests = req.tests || [];
                const summary = tests.length ? tests.slice(0, 8).join(', ') + (tests.length > 8 ? ' (+' + (tests.length - 8) + ' more)' : '') : 'Lab request';
                const cardId = 'lab-' + id;
                const labName = (req.laboratory_name || '').trim() || 'Not specified';
                const notes = (req.notes || '').trim();
                const statusColor = '#ff9800';
                const card = document.createElement('div');
                card.className = 'document-item lab-request-card-item';
                card.setAttribute('data-request-id', id);
                card.onclick = function(e) { e.stopPropagation(); toggleDocumentDetails(cardId); };
                card.innerHTML = 
                    '<div class="document-row">' +
                    '  <div class="document-chevron"><i class="fas fa-chevron-right"></i></div>' +
                    '  <div class="document-row-content">' +
                    '    <h3 class="document-title">' + escapeHtml(summary) + '</h3>' +
                    '    <span class="document-status" style="background:' + statusColor + '; color: white;">Active</span>' +
                    '  </div>' +
                    '</div>' +
                    '<div class="document-details" id="' + cardId + '">' +
                    '  <div class="document-details-content">' +
                    '    <p style="margin: 0 0 10px 0; color: #666; font-size: 12px; font-style: italic;">Lab Test Request #' + id + '</p>' +
                    '    <div style="margin-bottom: 10px;">' +
                    '      <strong>Tests:</strong> ' + escapeHtml(tests.join(', ') || '—') + '<br>' +
                    '      <strong>Laboratory:</strong> ' + escapeHtml(labName) + '<br>' +
                    '      <strong>Requested Date:</strong> ' + nowStr + '<br>' +
                    (notes ? '<strong>Notes:</strong> ' + escapeHtml(notes).replace(/\n/g, '<br>') + '<br>' : '') +
                    '    </div>' +
                    '    <div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-radius: 6px; color: #856404; font-size: 13px;">' +
                    '      <i class="fas fa-info-circle"></i> No results uploaded yet' +
                    '    </div>' +
                    '  </div>' +
                    '</div>';
                list.appendChild(card);
            });
            const countEl = document.getElementById('labRequestsSectionCount');
            if (countEl) {
                const current = list.querySelectorAll('.lab-request-card-item').length;
                countEl.textContent = '(' + current + ')';
            }
        }
        function escapeHtml(s) {
            if (s == null) return '';
            const div = document.createElement('div');
            div.textContent = s;
            return div.innerHTML;
        }
        
        function addLabRequestCard() {
            const container = document.getElementById('labTestRequestsContainer');
            const card = document.createElement('div');
            card.className = 'lab-request-card';
            card.style.cssText = 'margin-bottom: 20px; padding: 20px; background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-radius: 10px; border: 1px solid #e0e0e0; box-shadow: 0 2px 4px rgba(0,0,0,0.05);';
            card.dataset.cardId = 'lab-card-' + Date.now();
            card.innerHTML = `
                <div style="font-weight: 600; color: #1976D2; margin-bottom: 12px;"><i class="fas fa-file-medical"></i> Lab Request</div>
                <div class="form-grid">
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Laboratory <span style="color:#666; font-size:12px;">(optional)</span></label>
                        <div style="position: relative;">
                            <input type="text" class="form-control lab-laboratory-autocomplete lab-request-lab" 
                                   placeholder="Type to search laboratory (e.g., Health Center, Quezon City General Hospital)..." 
                                   autocomplete="off">
                            <div class="lab-laboratory-autocomplete-dropdown" style="display:none; position:absolute; top:100%; left:0; right:0; background:white; border:1px solid #ddd; border-radius:8px; max-height:300px; overflow-y:auto; z-index:1000; box-shadow:0 4px 12px rgba(0,0,0,0.15); margin-top:4px;"></div>
                        </div>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Select packages <span style="color:#666; font-size:12px;">(one or more)</span></label>
                        <div class="lab-request-packages" style="max-height:220px; overflow-y:auto; padding:10px; background:#fafafa; border:1px solid #e8e8e8; border-radius:8px; display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap:8px 16px;"></div>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Additional individual tests <span style="color:#666; font-size:12px;">(optional)</span></label>
                        <div class="lab-request-tests-list"></div>
                        <button type="button" class="action-btn add-medicine-btn" style="margin-top:8px;" onclick="addLabTestRowInCard(this); updateLabRequestSummary(this.closest('.lab-request-card'));">
                            <i class="fas fa-plus"></i> Add test
                        </button>
                    </div>
                    <div class="form-group lab-request-summary-wrap" style="grid-column: 1 / -1; padding:10px; background:#E3F2FD; border-radius:8px; border:1px solid #BBDEFB;">
                        <label style="margin-bottom:6px;">Tests in this request</label>
                        <div class="lab-request-summary" style="font-size:13px; color:#1565C0; min-height:20px;">None selected. Select packages and/or add individual tests.</div>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Notes (Optional)</label>
                        <textarea class="form-control lab-request-notes" rows="2" placeholder="Additional instructions for this lab request..."></textarea>
                    </div>
                </div>
                <div style="display: flex; justify-content: flex-end; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e0e0e0;">
                    <button type="button" class="action-btn btn-remove-test add-medicine-btn" onclick="removeLabRequestCard(this)">
                        <i class="fas fa-trash-alt"></i> Remove this lab request
                    </button>
                </div>
            `;
            container.appendChild(card);
            const doneBtnWrapper = document.getElementById('labTestDoneBtnWrapper');
            if (doneBtnWrapper) doneBtnWrapper.style.display = 'block';
            const packagesDiv = card.querySelector('.lab-request-packages');
            if (packagesDiv && typeof labTestPackages === 'object') {
                Object.keys(labTestPackages).forEach(pkgName => {
                    const label = document.createElement('label');
                    label.style.cssText = 'display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px;';
                    const cb = document.createElement('input');
                    cb.type = 'checkbox';
                    cb.className = 'lab-package-cb';
                    cb.value = pkgName;
                    cb.addEventListener('change', () => updateLabRequestSummary(card));
                    label.appendChild(cb);
                    label.appendChild(document.createTextNode(pkgName));
                    packagesDiv.appendChild(label);
                });
            }
            initializeLaboratoryAutocomplete(card);
            addLabTestRowInCard(card.querySelector('.lab-request-tests-list').nextElementSibling);
            updateLabRequestSummary(card);
            setTimeout(() => {
                const doneButtonContainer = document.getElementById('doneButtonContainer');
                if (doneButtonContainer) doneButtonContainer.scrollIntoView({ behavior: 'smooth', block: 'end' });
            }, 300);
        }
        
        function updateLabRequestSummary(card) {
            if (!card || !card.classList.contains('lab-request-card')) return;
            const summaryEl = card.querySelector('.lab-request-summary');
            if (!summaryEl) return;
            const tests = getLabRequestTestsFromCard(card);
            if (tests.length === 0) {
                summaryEl.textContent = 'None selected. Select packages and/or add individual tests.';
                summaryEl.style.fontStyle = 'italic';
            } else {
                summaryEl.style.fontStyle = '';
                summaryEl.innerHTML = tests.length + ' test(s): ' + tests.slice(0, 15).join(', ') + (tests.length > 15 ? ' (+' + (tests.length - 15) + ' more)' : '');
            }
        }
        
        function getLabRequestTestsFromCard(card) {
            const testsSet = new Set();
            if (typeof labTestPackages === 'object') {
                card.querySelectorAll('.lab-package-cb:checked').forEach(cb => {
                    (labTestPackages[cb.value] || []).forEach(t => testsSet.add(t));
                });
            }
            card.querySelectorAll('.lab-test-request-row .lab-test-autocomplete').forEach(input => {
                const v = (input.value || '').trim();
                if (v) testsSet.add(v);
            });
            return Array.from(testsSet);
        }
        
        function addLabTestRowInCard(btnOrCard) {
            const card = btnOrCard.classList && btnOrCard.classList.contains('lab-request-card') ? btnOrCard : btnOrCard.closest('.lab-request-card');
            const list = card ? card.querySelector('.lab-request-tests-list') : null;
            if (!list) return;
            const row = document.createElement('div');
            row.className = 'lab-test-request-row';
            row.style.cssText = 'display: flex; align-items: center; gap: 10px; margin-bottom: 8px;';
            row.innerHTML = `
                <div style="position: relative; flex: 1;">
                    <input type="text" class="form-control lab-test-autocomplete" placeholder="e.g. CBC, Fasting Blood Sugar, Lipid Profile..." autocomplete="off">
                    <div class="lab-test-autocomplete-dropdown" style="display:none; position:absolute; top:100%; left:0; right:0; background:white; border:1px solid #ddd; border-radius:8px; max-height:280px; overflow-y:auto; z-index:1000; box-shadow:0 4px 12px rgba(0,0,0,0.15); margin-top:4px;"></div>
                </div>
                <button type="button" class="action-btn btn-remove-test add-medicine-btn" onclick="removeLabTestRowInCard(this)" title="Remove this test"><i class="fas fa-times"></i></button>
            `;
            list.appendChild(row);
            initializeLabTestAutocomplete(row);
        }
        
        function removeLabTestRowInCard(button) {
            const row = button.closest('.lab-test-request-row');
            const card = row ? row.closest('.lab-request-card') : null;
            if (row) row.remove();
            if (card && typeof updateLabRequestSummary === 'function') updateLabRequestSummary(card);
        }
        
        function removeLabRequestCard(button) {
            const card = button.closest('.lab-request-card');
            if (card && confirm('Remove this entire lab request and its tests?')) {
                card.remove();
                const container = document.getElementById('labTestRequestsContainer');
                const doneBtnWrapper = document.getElementById('labTestDoneBtnWrapper');
                if (doneBtnWrapper && container && container.querySelectorAll('.lab-request-card').length === 0) {
                    doneBtnWrapper.style.display = 'none';
                }
            }
        }
        
        // Initialize lab test name autocomplete
        function initializeLabTestAutocomplete(row) {
            const input = row.querySelector('.lab-test-autocomplete');
            const dropdown = row.querySelector('.lab-test-autocomplete-dropdown');
            
            if (!input || !dropdown) return;
            
            const inputId = input.id || 'lab-test-' + Date.now();
            if (!input.id) input.id = inputId;
            
            let selectedIndex = -1;
            
            input.addEventListener('input', function() {
                const query = this.value.trim().toLowerCase();
                selectedIndex = -1;
                
                if (query.length === 0) {
                    dropdown.style.display = 'none';
                    return;
                }
                
                // Filter common lab tests
                const matches = commonLabTests.filter(test => 
                    test.toLowerCase().includes(query)
                );
                
                if (matches.length === 0) {
                    dropdown.innerHTML = '<div class="dropdown-item" style="color: #999; cursor: default; padding: 10px;">No matching tests found</div>';
                    dropdown.style.display = 'block';
                    return;
                }
                
                dropdown.innerHTML = '';
                matches.forEach((test, index) => {
                    const item = document.createElement('div');
                    item.className = 'dropdown-item';
                    item.style.cssText = 'padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #f0f0f0;';
                    item.textContent = test;
                    item.addEventListener('mouseenter', function() {
                        selectedIndex = index;
                        updateLabTestSelected(dropdown, selectedIndex);
                    });
                    item.addEventListener('click', function() {
                        input.value = test;
                        dropdown.style.display = 'none';
                        const card = row.closest('.lab-request-card');
                        if (card && typeof updateLabRequestSummary === 'function') updateLabRequestSummary(card);
                    });
                    dropdown.appendChild(item);
                });
                
                dropdown.style.display = 'block';
            });
            
            input.addEventListener('blur', function() {
                setTimeout(() => {
                    const card = row.closest('.lab-request-card');
                    if (card && typeof updateLabRequestSummary === 'function') updateLabRequestSummary(card);
                }, 150);
            });
            
            input.addEventListener('keydown', function(e) {
                const items = dropdown.querySelectorAll('.dropdown-item');
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                    updateLabTestSelected(dropdown, selectedIndex);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    selectedIndex = Math.max(selectedIndex - 1, -1);
                    updateLabTestSelected(dropdown, selectedIndex);
                } else if (e.key === 'Enter' && selectedIndex >= 0 && items[selectedIndex]) {
                    e.preventDefault();
                    input.value = items[selectedIndex].textContent;
                    dropdown.style.display = 'none';
                    const card = row.closest('.lab-request-card');
                    if (card && typeof updateLabRequestSummary === 'function') updateLabRequestSummary(card);
                } else if (e.key === 'Escape') {
                    dropdown.style.display = 'none';
                }
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.style.display = 'none';
                }
            });
        }
        
        function updateLabTestSelected(dropdown, selectedIndex) {
            const items = dropdown.querySelectorAll('.dropdown-item');
            items.forEach((item, index) => {
                if (index === selectedIndex) {
                    item.style.background = '#e3f2fd';
                    item.style.color = '#1976d2';
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.style.background = '';
                    item.style.color = '';
                }
            });
        }
        
        // Initialize laboratory autocomplete
        function initializeLaboratoryAutocomplete(row) {
            const input = row.querySelector('.lab-laboratory-autocomplete');
            const dropdown = row.querySelector('.lab-laboratory-autocomplete-dropdown');
            
            if (!input || !dropdown) return;
            
            const inputId = input.id || 'lab-lab-' + Date.now();
            if (!input.id) input.id = inputId;
            
            let selectedIndex = -1;
            
            input.addEventListener('input', function() {
                const query = this.value.trim().toLowerCase();
                selectedIndex = -1;
                
                if (query.length === 0) {
                    dropdown.style.display = 'none';
                    return;
                }
                
                // Filter laboratories
                const matches = payatasLaboratories.filter(lab => 
                    lab.toLowerCase().includes(query)
                );
                
                if (matches.length === 0) {
                    dropdown.innerHTML = '<div class="dropdown-item" style="color: #999; cursor: default; padding: 10px;">No matching laboratories found</div>';
                    dropdown.style.display = 'block';
                    return;
                }
                
                dropdown.innerHTML = '';
                matches.forEach((lab, index) => {
                    const item = document.createElement('div');
                    item.className = 'dropdown-item';
                    item.style.cssText = 'padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #f0f0f0;';
                    item.innerHTML = `<i class="fas fa-hospital" style="color: #1976d2; margin-right: 8px;"></i>${lab}`;
                    item.dataset.labName = lab; // Store lab name in dataset for easy retrieval
                    item.addEventListener('mouseenter', function() {
                        selectedIndex = index;
                        updateLaboratorySelected(dropdown, selectedIndex);
                    });
                    item.addEventListener('click', function() {
                        input.value = this.dataset.labName || lab;
                        dropdown.style.display = 'none';
                    });
                    dropdown.appendChild(item);
                });
                
                dropdown.style.display = 'block';
            });
            
            input.addEventListener('keydown', function(e) {
                const items = dropdown.querySelectorAll('.dropdown-item');
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                    updateLaboratorySelected(dropdown, selectedIndex);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    selectedIndex = Math.max(selectedIndex - 1, -1);
                    updateLaboratorySelected(dropdown, selectedIndex);
                } else if (e.key === 'Enter' && selectedIndex >= 0 && items[selectedIndex]) {
                    e.preventDefault();
                    const selectedItem = items[selectedIndex];
                    // Use dataset if available, otherwise fall back to textContent
                    input.value = selectedItem.dataset.labName || selectedItem.textContent.trim() || selectedItem.innerText.trim();
                    dropdown.style.display = 'none';
                } else if (e.key === 'Escape') {
                    dropdown.style.display = 'none';
                }
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.style.display = 'none';
                }
            });
        }
        
        function updateLaboratorySelected(dropdown, selectedIndex) {
            const items = dropdown.querySelectorAll('.dropdown-item');
            items.forEach((item, index) => {
                if (index === selectedIndex) {
                    item.style.background = '#e3f2fd';
                    item.style.color = '#1976d2';
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.style.background = '';
                    item.style.color = '';
                }
            });
        }

        function buildSelect(options, name) {
            return `
                <select class="form-control" name="${name}">
                    <option value="">Select ${name.replace('[]', '').replace('_', ' ')}</option>
                    ${options.map(opt => `<option value="${opt}">${opt}</option>`).join('')}
                </select>
            `;
        }

        function buildPrescriptionRow() {
            const dosageOptionsHtml = dosageOptions.map(opt => 
                `<option value="${opt}">${opt}</option>`
            ).join('') + '<option value="__other__">Other</option>';
            
            const frequencyOptionsHtml = frequencyOptions.map(opt => 
                `<option value="${opt}">${opt}</option>`
            ).join('');
            
            const timingOptionsHtml = timingOfIntakeOptions.map(opt => 
                `<option value="${opt}">${opt}</option>`
            ).join('');
            
            const durationOptionsHtml = durationOptions.map(opt => 
                `<option value="${opt}">${opt}</option>`
            ).join('') + '<option value="__other__">Other</option>';
            
            const medicineFormOptionsHtml = medicineFormOptions.slice(0, -1).map(opt =>
                `<option value="${opt}">${opt}</option>`
            ).join('') + '<option value="__other__">Others</option>';
            
            return `
                <div style="grid-column: span 2;">
                    <label>Medicine</label>
                    <div class="medicine-autocomplete-wrapper" style="position: relative;">
                        <input type="text" class="form-control medicine-autocomplete" name="medicine[]" 
                               placeholder="Type to search medicine from inventory..." 
                               autocomplete="off" 
                               data-medicine-id="">
                        <input type="hidden" class="medicine-id-input" name="medicine_id[]" value="">
                        <div class="medicine-autocomplete-dropdown" style="display:none;">
                </div>
                        <div class="medicine-category-badge" style="display:none; margin-top:6px; font-size:12px; padding:6px 10px; border-radius:6px;"></div>
                    </div>
                </div>
                <div>
                    <label>Medicine Form <span style="color:#c00;">*</span></label>
                    <div style="position: relative; height: 42px;">
                        <select class="form-control medicine-form-select" name="medicine_form[]" title="Select how the medicine should be taken or applied (required when adding a medicine)" onchange="handleOtherOption(this, 'medicine_form')">
                            <option value="">Select form</option>
                            ${medicineFormOptionsHtml}
                        </select>
                        <input type="text" class="form-control medicine_form-other-input" name="medicine_form_other[]" placeholder="Specify medicine form" style="display:none;">
                    </div>
                </div>
                <div>
                    <label>Quantity per Intake</label>
                    <input type="number" class="form-control quantity-per-intake-input" name="quantity[]" placeholder="e.g. 1" min="1" value="1" data-max-quantity="" oninput="calculateTotalQuantity(this)">
                    <div class="quantity-info" style="display:none;"></div>
                    <div class="quantity-error" style="display:none;"></div>
                </div>
                <div>
                    <label>Dosage</label>
                    <div style="position: relative; height: 42px;">
                        <select class="form-control dosage-select" name="dosage[]" onchange="handleOtherOption(this, 'dosage')">
                            <option value="">Select dosage</option>
                            ${dosageOptionsHtml}
                        </select>
                        <input type="text" class="form-control dosage-other-input" name="dosage_other[]" placeholder="Enter custom dosage" style="display:none;">
                    </div>
                </div>
                <div>
                    <label>Frequency (Times per Day)</label>
                    <select class="form-control frequency-select" name="frequency[]" onchange="calculateTotalQuantity(this)">
                        <option value="">Select frequency</option>
                        ${frequencyOptionsHtml}
                    </select>
                </div>
                <div>
                    <label>Timing of Intake</label>
                    <select class="form-control timing-select" name="timing_of_intake[]">
                        <option value="">Select timing</option>
                        ${timingOptionsHtml}
                    </select>
                </div>
                <div>
                    <label>Duration</label>
                    <div style="position: relative; height: 42px;">
                        <select class="form-control duration-select" name="duration[]" onchange="handleOtherOption(this, 'duration'); calculateTotalQuantity(this);">
                            <option value="">Select duration</option>
                            ${durationOptionsHtml}
                        </select>
                        <input type="text" class="form-control duration-other-input" name="duration_other[]" placeholder="Enter custom duration" style="display:none;" oninput="calculateTotalQuantity(this)">
                    </div>
                </div>
                <div>
                    <label>Total Quantity (Auto-calculated)</label>
                    <input type="number" class="form-control total-quantity-input" name="total_quantity[]" readonly placeholder="0" value="0" style="background-color: #f5f5f5; cursor: not-allowed;">
                    <small style="color:#666; font-size:11px; margin-top:3px; display:block;">Quantity × Frequency × Duration</small>
                </div>
            `;
        }

        function buildExternalPrescriptionRow() {
            const dosageOptionsHtml = dosageOptions.map(opt =>
                `<option value="${opt}">${opt}</option>`
            ).join('') + '<option value="__other__">Other</option>';
            const frequencyOptionsHtml = frequencyOptions.map(opt =>
                `<option value="${opt}">${opt}</option>`
            ).join('');
            const timingOptionsHtml = timingOfIntakeOptions.map(opt =>
                `<option value="${opt}">${opt}</option>`
            ).join('');
            const durationOptionsHtml = durationOptions.map(opt =>
                `<option value="${opt}">${opt}</option>`
            ).join('') + '<option value="__other__">Other</option>';
            const medicineFormOptionsHtml = medicineFormOptions.slice(0, -1).map(opt =>
                `<option value="${opt}">${opt}</option>`
            ).join('') + '<option value="__other__">Others</option>';
            return `
                <div class="external-section-header">
                    <i class="fas fa-external-link-alt"></i> External — To be bought outside the health center
                </div>
                <input type="hidden" class="external-is-external-input" name="is_external[]" value="1">
                <div style="grid-column: span 2;">
                    <label>Medicine Name</label>
                    <input type="text" class="form-control external-medicine-name" name="external_medicine_name[]" placeholder="Enter medicine name (from external pharmacy)" maxlength="255">
                </div>
                <div>
                    <label>Medicine Form <span style="color:#c00;">*</span></label>
                    <div style="position: relative; height: 42px;">
                        <select class="form-control medicine-form-select" name="external_medicine_form[]" title="Select how the medicine should be taken or applied (required when adding a medicine)" onchange="handleOtherOption(this, 'medicine_form')">
                            <option value="">Select form</option>
                            ${medicineFormOptionsHtml}
                        </select>
                        <input type="text" class="form-control medicine_form-other-input" name="external_medicine_form_other[]" placeholder="Specify medicine form" style="display:none;">
                    </div>
                </div>
                <div>
                    <label>Quantity per Intake</label>
                    <input type="number" class="form-control quantity-per-intake-input" name="external_quantity[]" placeholder="e.g. 1" min="1" value="1" data-max-quantity="" oninput="calculateTotalQuantity(this)">
                    <div class="quantity-info" style="display:none;"></div>
                    <div class="quantity-error" style="display:none;"></div>
                </div>
                <div>
                    <label>Dosage</label>
                    <div style="position: relative; height: 42px;">
                        <select class="form-control dosage-select" name="external_dosage[]" onchange="handleOtherOption(this, 'dosage')">
                            <option value="">Select dosage</option>
                            ${dosageOptionsHtml}
                        </select>
                        <input type="text" class="form-control dosage-other-input" name="external_dosage_other[]" placeholder="Enter custom dosage" style="display:none;">
                    </div>
                </div>
                <div>
                    <label>Frequency (Times per Day)</label>
                    <select class="form-control frequency-select" name="external_frequency[]" onchange="calculateTotalQuantity(this)">
                        <option value="">Select frequency</option>
                        ${frequencyOptionsHtml}
                    </select>
                </div>
                <div>
                    <label>Timing of Intake</label>
                    <select class="form-control timing-select" name="external_timing_of_intake[]">
                        <option value="">Select timing</option>
                        ${timingOptionsHtml}
                    </select>
                </div>
                <div>
                    <label>Duration</label>
                    <div style="position: relative; height: 42px;">
                        <select class="form-control duration-select" name="external_duration[]" onchange="handleOtherOption(this, 'duration'); calculateTotalQuantity(this);">
                            <option value="">Select duration</option>
                            ${durationOptionsHtml}
                        </select>
                        <input type="text" class="form-control duration-other-input" name="external_duration_other[]" placeholder="Enter custom duration" style="display:none;" oninput="calculateTotalQuantity(this)">
                    </div>
                </div>
                <div>
                    <label>Total Quantity (Auto-calculated)</label>
                    <input type="number" class="form-control total-quantity-input" name="external_total_quantity[]" readonly placeholder="0" value="0" style="background-color: #f5f5f5; cursor: not-allowed;">
                    <small style="color:#666; font-size:11px; margin-top:3px; display:block;">Quantity × Frequency × Duration (for reference; not dispensed here)</small>
                </div>
                <div style="grid-column: 1 / -1;">
                    <label>Instructions / Notes</label>
                    <textarea class="form-control" name="external_instructions[]" rows="2" placeholder="Special instructions for the patient (optional)"></textarea>
                </div>
                <div style="grid-column: 1 / -1; display: flex; justify-content: flex-end;">
                    <button type="button" class="action-btn btn-remove-test" onclick="removeExternalPrescriptionRow(this)">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
            `;
        }

        function addExternalPrescriptionRow() {
            const formsContainer = document.getElementById('prescriptionFormsContainer');
            const container = document.getElementById('consultPrescriptionRows');
            if (formsContainer) formsContainer.style.display = 'block';
            const row = document.createElement('div');
            row.className = 'dynamic-row prescription-row external-prescription-row';
            row.innerHTML = buildExternalPrescriptionRow();
            container.appendChild(row);
        }

        function removeExternalPrescriptionRow(btn) {
            const row = btn.closest('.external-prescription-row');
            if (row) row.remove();
        }

        // Toggle custom expiration date field
        function toggleCustomExpirationDate(type) {
            const periodSelect = document.getElementById(type === 'prescription' ? 'prescriptionValidityPeriod' : 'certificateValidityPeriod');
            const customSection = document.getElementById(type === 'prescription' ? 'prescriptionCustomDateSection' : 'certificateCustomDateSection');
            const customDateInput = document.getElementById(type === 'prescription' ? 'prescriptionCustomExpirationDate' : 'certificateCustomExpirationDate');
            
            if (!periodSelect || !customSection) return;
            
            if (periodSelect.value === 'custom') {
                customSection.style.display = 'block';
                if (customDateInput) {
                    customDateInput.required = true;
                }
            } else {
                customSection.style.display = 'none';
                if (customDateInput) {
                    customDateInput.required = false;
                    customDateInput.value = '';
                }
            }
        }
        
        // Medical Certificate Functions
        function addMedicalCertificateRow() {
            // Ensure the accordion is open when adding a row
            ensureAccordionOpen('medicalCertificatesContainer');
            
            const container = document.getElementById('medicalCertificatesContainer');
            const row = document.createElement('div');
            row.className = 'medical-certificate-row';
            row.style.cssText = 'margin-bottom: 15px; padding: 20px; background: linear-gradient(135deg, #fff9e6 0%, #ffffff 100%); border-radius: 10px; border: 1px solid #ffcc80; box-shadow: 0 2px 4px rgba(0,0,0,0.05);';
            const rowId = 'cert-row-' + Date.now();
            row.id = rowId;
            
            row.innerHTML = `
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight: 600; color: #F57C00; margin-bottom: 8px;">
                        <i class="fas fa-file-medical"></i> Certificate Type
                    </label>
                    <select class="form-control certificate-type-select" style="height: 42px;" onchange="updateCertificateSubtypeRow(this)" onclick="event.stopPropagation();" onfocus="event.stopPropagation(); ensureAccordionOpen('medicalCertificatesContainer');">
                        <option value="">Select certificate type</option>
                        <optgroup label="Work-Related">
                            <option value="work_related" data-subtype="sick_leave">Sick Leave</option>
                            <option value="work_related" data-subtype="fit_to_work">Fit-to-Work</option>
                            <option value="work_related" data-subtype="food_handler">Food Handler</option>
                            <option value="work_related" data-subtype="high_risk_work">High-Risk Work</option>
                        </optgroup>
                        <optgroup label="Education">
                            <option value="education" data-subtype="school_clearance">School Clearance</option>
                        </optgroup>
                        <optgroup label="Travel">
                            <option value="travel" data-subtype="travel_clearance">Travel Clearance</option>
                        </optgroup>
                        <optgroup label="Licensing & Permits">
                            <option value="licensing" data-subtype="driver_license">Driver's License</option>
                            <option value="licensing" data-subtype="professional_license">Professional License</option>
                        </optgroup>
                        <optgroup label="General">
                            <option value="general" data-subtype="health_checkup">General Health Check-up</option>
                            <option value="general" data-subtype="pwd_registration">PWD Registration</option>
                        </optgroup>
                    </select>
                </div>
                
                <div class="fit-status-section" style="display: none; margin-bottom: 20px;">
                    <label style="font-weight: 600; color: #F57C00; margin-bottom: 8px;">
                        <i class="fas fa-user-check"></i> Work Status
                    </label>
                    <select class="form-control fit-status-select" style="height: 42px;" onclick="event.stopPropagation();" onfocus="event.stopPropagation(); ensureAccordionOpen('medicalCertificatesContainer');">
                        <option value="">Select status</option>
                        <option value="fit">Fit to Work</option>
                        <option value="unfit">Unfit to Work</option>
                    </select>
                    <small style="color:#666; font-size:11px; margin-top:5px; display:block;">
                        Select whether the patient is fit or unfit for work.
                    </small>
                </div>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight: 600; color: #F57C00; margin-bottom: 8px;">
                        <i class="fas fa-calendar-check"></i> Certificate Validity Period
                    </label>
                    <select class="form-control certificate-validity-select" style="height: 42px;" onchange="toggleCustomExpirationDateRow(this, 'certificate')" onclick="event.stopPropagation();" onfocus="event.stopPropagation(); ensureAccordionOpen('medicalCertificatesContainer');">
                        <option value="">Select validity period</option>
                        <option value="7">7 days</option>
                        <option value="14" selected>14 days (Default)</option>
                        <option value="30">30 days</option>
                        <option value="custom">Custom expiration date</option>
                    </select>
                    <div class="certificate-custom-date-section" style="display: none; margin-top: 10px;">
                        <label style="font-weight: 600; color: #F57C00; margin-bottom: 8px; font-size: 13px;">
                            Custom Expiration Date
                        </label>
                        <input type="date" class="form-control certificate-custom-date-input" style="height: 42px;" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" onclick="event.stopPropagation();" onfocus="event.stopPropagation(); ensureAccordionOpen('medicalCertificatesContainer');">
                        <small style="color:#666; font-size:11px; margin-top:5px; display:block;">
                            Select a specific expiration date. Must be in the future.
                        </small>
                    </div>
                    <small style="color:#666; font-size:11px; margin-top:5px; display:block;">
                        The certificate will expire after the selected period or on the custom date. Expired certificates will be automatically disabled.
                    </small>
                </div>
                
                <button type="button" class="btn-primary-action add-medicine-btn generate-certificate-btn" onclick="event.stopPropagation(); generateCertificateFromRow(this); return false;">
                    <i class="fas fa-certificate"></i> Generate Medical Certificate
                </button>
                
                <div class="certificate-message" style="margin-top: 15px; display: none;"></div>
                <div style="display: flex; justify-content: flex-end; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e0e0e0;">
                    <button type="button" class="action-btn btn-remove-test add-medicine-btn" onclick="event.stopPropagation(); removeMedicalCertificateRow(this); return false;">
                        <i class="fas fa-trash-alt"></i> Remove Certificate
                    </button>
                </div>
            `;
            container.appendChild(row);
            
            // Add event listeners to all form elements to prevent accordion collapse
            const formElements = row.querySelectorAll('input, select, textarea');
            formElements.forEach(element => {
                element.addEventListener('click', function(e) {
                    e.stopPropagation();
                    ensureAccordionOpen('medicalCertificatesContainer');
                });
                element.addEventListener('focus', function(e) {
                    e.stopPropagation();
                    ensureAccordionOpen('medicalCertificatesContainer');
                });
                element.addEventListener('input', function(e) {
                    e.stopPropagation();
                    ensureAccordionOpen('medicalCertificatesContainer');
                });
            });
            
            // Ensure scrolling is enabled
            ensureScrollingEnabled();
            
            // Scroll to show the new row (but don't prevent page scrolling)
            setTimeout(() => {
                try {
                    // Use 'nearest' to avoid blocking page scroll
                    row.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
                    // Ensure scrolling remains enabled after scroll
                    ensureScrollingEnabled();
                } catch (e) {
                    // If scrollIntoView fails, just ensure scrolling is enabled
                    ensureScrollingEnabled();
                }
            }, 150);
        }
        
        function removeMedicalCertificateRow(button) {
            const row = button.closest('.medical-certificate-row');
            if (row) {
                if (confirm('Remove this medical certificate form?')) {
                    row.remove();
                }
            }
        }
        
        function updateCertificateSubtypeRow(selectElement) {
            const row = selectElement.closest('.medical-certificate-row');
            if (!row) return;
            
            const fitStatusSection = row.querySelector('.fit-status-section');
            const fitStatusSelect = row.querySelector('.fit-status-select');
            
            if (!fitStatusSection || !fitStatusSelect) return;
            
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const certificateType = selectedOption.value;
            
            if (certificateType === 'work_related') {
                fitStatusSection.style.display = 'block';
                fitStatusSelect.required = true;
            } else {
                fitStatusSection.style.display = 'none';
                fitStatusSelect.required = false;
                fitStatusSelect.value = '';
            }
        }
        
        function toggleCustomExpirationDateRow(selectElement, type) {
            const row = selectElement.closest('.medical-certificate-row');
            if (!row) return;
            
            const customSection = row.querySelector('.certificate-custom-date-section');
            const customDateInput = row.querySelector('.certificate-custom-date-input');
            
            if (!customSection || !customDateInput) return;
            
            if (selectElement.value === 'custom') {
                customSection.style.display = 'block';
                customDateInput.required = true;
            } else {
                customSection.style.display = 'none';
                customDateInput.required = false;
                customDateInput.value = '';
            }
        }
        
        async function generateCertificateFromRow(button) {
            const row = button.closest('.medical-certificate-row');
            if (!row) return;
            
            const certificateTypeSelect = row.querySelector('.certificate-type-select');
            const certificateType = certificateTypeSelect ? certificateTypeSelect.value : '';
            const selectedOption = certificateTypeSelect ? certificateTypeSelect.options[certificateTypeSelect.selectedIndex] : null;
            const certificateSubtype = selectedOption ? selectedOption.getAttribute('data-subtype') : '';
            const fitStatus = row.querySelector('.fit-status-select') ? row.querySelector('.fit-status-select').value : '';
            const validityPeriod = row.querySelector('.certificate-validity-select') ? row.querySelector('.certificate-validity-select').value : '';
            const customExpirationDate = row.querySelector('.certificate-custom-date-input') ? row.querySelector('.certificate-custom-date-input').value : '';
            const messageDiv = row.querySelector('.certificate-message');
            
            if (!certificateType) {
                alert('Please select a certificate type.');
                return;
            }
            
            if (certificateType === 'work_related' && !fitStatus) {
                alert('Please select whether the patient is fit or unfit to work.');
                return;
            }
            
            if (!validityPeriod) {
                alert('Please select a validity period for the certificate.');
                return;
            }
            
            if (validityPeriod === 'custom' && !customExpirationDate) {
                alert('Please select a custom expiration date.');
                return;
            }
            
            const appointmentId = <?php echo isset($appointment_id) && $appointment_id > 0 ? (int)$appointment_id : 'null'; ?>;
            const consultationId = <?php 
                if (isset($appointment_id) && $appointment_id > 0) {
                    $stmt = $pdo->prepare("SELECT id FROM doctor_consultations WHERE appointment_id = ? ORDER BY created_at DESC LIMIT 1");
                    $stmt->execute([$appointment_id]);
                    $consult = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo $consult ? (int)$consult['id'] : 'null';
                } else {
                    echo 'null';
                }
            ?>;
            
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            if (messageDiv) messageDiv.style.display = 'none';
            
            try {
                const formData = new FormData();
                formData.append('action', 'generate_certificate');
                formData.append('patient_id', patientId);
                formData.append('certificate_type', certificateType);
                formData.append('certificate_subtype', certificateSubtype);
                if (fitStatus) formData.append('fit_status', fitStatus);
                formData.append('validity_period_days', validityPeriod);
                if (validityPeriod === 'custom' && customExpirationDate) {
                    formData.append('custom_expiration_date', customExpirationDate);
                }
                if (appointmentId) formData.append('appointment_id', appointmentId);
                if (consultationId) formData.append('consultation_id', consultationId);
                
                const response = await fetch('doctor_medical_certificate_handler.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    if (messageDiv) {
                        messageDiv.style.display = 'block';
                        messageDiv.style.padding = '12px';
                        messageDiv.style.borderRadius = '6px';
                        messageDiv.style.backgroundColor = '#d4edda';
                        messageDiv.style.color = '#155724';
                        messageDiv.style.border = '1px solid #c3e6cb';
                        messageDiv.innerHTML = '<i class="fas fa-check-circle"></i> Medical certificate generated successfully! Issued: ' + 
                            new Date(data.issued_date).toLocaleDateString() + ', Expires: ' + 
                            new Date(data.expiration_date).toLocaleDateString();
                    }
                    
                    // Reload certificates list
                    loadCertificates();
                    
                    // Ensure accordion stays open
                    ensureAccordionOpen('medicalCertificatesContainer');
                    
                    // Ensure scrolling is enabled
                    ensureScrollingEnabled();
                    
                    // Reset button
                    setTimeout(() => {
                        button.disabled = false;
                        button.innerHTML = '<i class="fas fa-certificate"></i> Generate Medical Certificate';
                    }, 2000);
                } else {
                    throw new Error(data.message || 'Failed to generate certificate');
                }
            } catch (error) {
                if (messageDiv) {
                    messageDiv.style.display = 'block';
                    messageDiv.style.padding = '12px';
                    messageDiv.style.borderRadius = '6px';
                    messageDiv.style.backgroundColor = '#f8d7da';
                    messageDiv.style.color = '#721c24';
                    messageDiv.style.border = '1px solid #f5c6cb';
                    messageDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Error: ' + error.message;
                }
                
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-certificate"></i> Generate Medical Certificate';
            }
        }
        
        // Referral Functions
        function addReferralRow() {
            // Ensure the accordion is open when adding a row
            ensureAccordionOpen('referralsContainer');
            
            const container = document.getElementById('referralsContainer');
            const row = document.createElement('div');
            row.className = 'referral-row';
            row.style.cssText = 'margin-bottom: 15px; padding: 20px; background: linear-gradient(135deg, #f3e5f5 0%, #ffffff 100%); border-radius: 10px; border: 1px solid #ce93d8; box-shadow: 0 2px 4px rgba(0,0,0,0.05);';
            const rowId = 'ref-row-' + Date.now();
            row.id = rowId;
            
            const referralReasonsData = <?php echo json_encode($referralReasons); ?>;
            const referralReasonsHtml = referralReasonsData.map(reason => 
                `<option value="${reason}">${reason}</option>`
            ).join('');
            
            const clinicalNotesData = <?php echo json_encode($clinicalNotesOptions); ?>;
            const clinicalNotesHtml = clinicalNotesData.map(note => 
                `<option value="${note}">${note}</option>`
            ).join('');
            
            row.innerHTML = `
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight: 600; color: #7B1FA2; margin-bottom: 8px;">
                        <i class="fas fa-hospital"></i> Referred Hospital/Facility
                    </label>
                    <div style="position: relative;">
                        <input type="text" 
                               class="form-control referral-hospital-input" 
                               style="height: 42px;" 
                               placeholder="Type to search hospitals (e.g., Quezon City General Hospital, Payatas, Novaliches)..." 
                               autocomplete="off"
                               onclick="event.stopPropagation();"
                               onfocus="event.stopPropagation(); ensureAccordionOpen('referralsContainer');"
                               oninput="event.stopPropagation(); ensureAccordionOpen('referralsContainer');">
                        <input type="hidden" class="referral-hospital-name" value="">
                        <input type="hidden" class="referral-hospital-address-data" value="">
                        <input type="hidden" class="referral-hospital-contact-data" value="">
                        <div class="referral-hospital-autocomplete-dropdown" style="display:none; position:absolute; top:100%; left:0; right:0; background:white; border:1px solid #ddd; border-radius:8px; max-height:300px; overflow-y:auto; z-index:1000; box-shadow:0 4px 12px rgba(0,0,0,0.15); margin-top:4px;" onclick="event.stopPropagation();">
                        </div>
                    </div>
                    <div class="referral-custom-hospital-section" style="display: none; margin-top: 10px;">
                        <input type="text" class="form-control referral-custom-hospital-name" style="height: 42px;" placeholder="Enter hospital/facility name" onclick="event.stopPropagation();" onfocus="event.stopPropagation(); ensureAccordionOpen('referralsContainer');">
                    </div>
                </div>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight: 600; color: #7B1FA2; margin-bottom: 8px;">
                        <i class="fas fa-map-marker-alt"></i> Hospital/Facility Address (Optional)
                    </label>
                    <textarea class="form-control textarea referral-hospital-address" rows="2" placeholder="Address will auto-fill when hospital is selected..." onclick="event.stopPropagation();" onfocus="event.stopPropagation(); ensureAccordionOpen('referralsContainer');"></textarea>
                </div>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight: 600; color: #7B1FA2; margin-bottom: 8px;">
                        <i class="fas fa-phone"></i> Hospital/Facility Contact (Optional)
                    </label>
                    <input type="text" class="form-control referral-hospital-contact" style="height: 42px;" placeholder="Contact will auto-fill when hospital is selected..." onclick="event.stopPropagation();" onfocus="event.stopPropagation(); ensureAccordionOpen('referralsContainer');">
                </div>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight: 600; color: #7B1FA2; margin-bottom: 8px;">
                        <i class="fas fa-file-medical-alt"></i> Reason for Referral
                    </label>
                    <select class="form-control referral-reason-select" style="height: 42px;" onchange="toggleReasonOtherRow(this)" onclick="event.stopPropagation();" onfocus="event.stopPropagation(); ensureAccordionOpen('referralsContainer');">
                        <option value="">Select reason for referral</option>
                        ${referralReasonsHtml}
                    </select>
                    <div class="referral-reason-other-section" style="display: none; margin-top: 10px;">
                        <textarea class="form-control textarea referral-reason-other-text" rows="2" placeholder="Please specify the reason for referral..." onclick="event.stopPropagation();" onfocus="event.stopPropagation(); ensureAccordionOpen('referralsContainer');"></textarea>
                    </div>
                </div>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight: 600; color: #7B1FA2; margin-bottom: 8px;">
                        <i class="fas fa-stethoscope"></i> Brief Clinical Notes (Optional)
                    </label>
                    <select class="form-control referral-clinical-notes-select" style="height: 42px; margin-bottom: 10px;" onchange="toggleClinicalNotesOtherRow(this)" onclick="event.stopPropagation();" onfocus="event.stopPropagation(); ensureAccordionOpen('referralsContainer');">
                        <option value="">Select clinical notes (optional)</option>
                        ${clinicalNotesHtml}
                    </select>
                    <div class="referral-clinical-notes-other-section" style="display: none; margin-top: 10px;">
                        <textarea class="form-control textarea referral-clinical-notes-other-text" rows="2" placeholder="Enter additional clinical notes..." onclick="event.stopPropagation();" onfocus="event.stopPropagation(); ensureAccordionOpen('referralsContainer');"></textarea>
                    </div>
                    <textarea class="form-control textarea referral-clinical-notes" rows="3" style="margin-top: 10px; display: none;" placeholder="Enter additional clinical notes, findings, or relevant medical history..." onclick="event.stopPropagation();" onfocus="event.stopPropagation(); ensureAccordionOpen('referralsContainer');"></textarea>
                </div>
                
                <button type="button" class="btn-primary-action add-medicine-btn create-referral-btn" onclick="event.stopPropagation(); createReferralFromRow(this); return false;">
                    <i class="fas fa-file-medical"></i> Create Referral Letter
                </button>
                
                <div class="referral-message" style="margin-top: 15px; display: none;"></div>
                <div style="display: flex; justify-content: flex-end; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e0e0e0;">
                    <button type="button" class="action-btn btn-remove-test add-medicine-btn" onclick="event.stopPropagation(); removeReferralRow(this); return false;">
                        <i class="fas fa-trash-alt"></i> Remove Referral
                    </button>
                </div>
            `;
            container.appendChild(row);
            
            // Initialize hospital autocomplete for this row
            initializeReferralHospitalAutocomplete(row);
            
            // Add event listeners to all form elements to prevent accordion collapse
            const formElements = row.querySelectorAll('input, select, textarea');
            formElements.forEach(element => {
                element.addEventListener('click', function(e) {
                    e.stopPropagation();
                    ensureAccordionOpen('referralsContainer');
                });
                element.addEventListener('focus', function(e) {
                    e.stopPropagation();
                    ensureAccordionOpen('referralsContainer');
                });
                element.addEventListener('input', function(e) {
                    e.stopPropagation();
                    ensureAccordionOpen('referralsContainer');
                });
            });
            
            // Ensure scrolling is enabled
            ensureScrollingEnabled();
            
            // Scroll to show the new row (but don't prevent page scrolling)
            setTimeout(() => {
                try {
                    // Use 'nearest' to avoid blocking page scroll
                    row.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
                    // Ensure scrolling remains enabled after scroll
                    ensureScrollingEnabled();
                } catch (e) {
                    // If scrollIntoView fails, just ensure scrolling is enabled
                    ensureScrollingEnabled();
                }
            }, 150);
        }
        
        function removeReferralRow(button) {
            const row = button.closest('.referral-row');
            if (row) {
                if (confirm('Remove this referral form?')) {
                    row.remove();
                }
            }
        }
        
        function toggleReasonOtherRow(selectElement) {
            const row = selectElement.closest('.referral-row');
            if (!row) return;
            
            const otherSection = row.querySelector('.referral-reason-other-section');
            const otherText = row.querySelector('.referral-reason-other-text');
            
            if (!otherSection || !otherText) return;
            
            if (selectElement.value === 'Other') {
                otherSection.style.display = 'block';
                otherText.required = true;
            } else {
                otherSection.style.display = 'none';
                otherText.required = false;
                otherText.value = '';
            }
        }
        
        function toggleClinicalNotesOtherRow(selectElement) {
            const row = selectElement.closest('.referral-row');
            if (!row) return;
            
            const otherSection = row.querySelector('.referral-clinical-notes-other-section');
            const clinicalNotes = row.querySelector('.referral-clinical-notes');
            
            if (!otherSection || !clinicalNotes) return;
            
            if (selectElement.value === 'Other') {
                otherSection.style.display = 'block';
                clinicalNotes.style.display = 'block';
            } else if (selectElement.value) {
                otherSection.style.display = 'none';
                clinicalNotes.style.display = 'block';
            } else {
                otherSection.style.display = 'none';
                clinicalNotes.style.display = 'none';
            }
        }
        
        function initializeReferralHospitalAutocomplete(row) {
            const input = row.querySelector('.referral-hospital-input');
            const dropdown = row.querySelector('.referral-hospital-autocomplete-dropdown');
            const hospitalNameInput = row.querySelector('.referral-hospital-name');
            const hospitalAddressInput = row.querySelector('.referral-hospital-address');
            const hospitalContactInput = row.querySelector('.referral-hospital-contact');
            const addressDataInput = row.querySelector('.referral-hospital-address-data');
            const contactDataInput = row.querySelector('.referral-hospital-contact-data');
            const customSection = row.querySelector('.referral-custom-hospital-section');
            const customInput = row.querySelector('.referral-custom-hospital-name');
            
            if (!input || !dropdown) return;
            
            const referralHospitalsData = <?php echo json_encode($referralHospitals); ?>;
            let selectedIndex = -1;
            
            // Define hospitals near Barangay Payatas, Quezon City
            const payatasAreaKeywords = ['payatas', 'quezon city', 'qc', 'novaliches', 'commonwealth', 'litex', 'diliman', 'east avenue', 'quirino', 'katipunan', 'seminary'];
            
            // Function to filter and display hospitals
            const filterAndDisplayHospitals = function(query) {
                selectedIndex = -1;
                const queryLower = (query || '').trim().toLowerCase();
                
                let matches = [];
                
                if (queryLower.length === 0) {
                    // When field is focused but empty, show all hospitals near Payatas/Quezon City
                    matches = referralHospitalsData.filter(hospital => {
                        const isNearPayatas = hospital.address && payatasAreaKeywords.some(keyword => 
                            hospital.address.toLowerCase().includes(keyword)
                        );
                        return isNearPayatas;
                    });
                } else {
                    // When typing, filter hospitals that match the query
                    // Show ALL hospitals that match the typed letter(s) in their name (case-insensitive)
                    matches = referralHospitalsData.filter(hospital => {
                        // Primary filter: match by hospital name (case-insensitive)
                        const nameMatch = hospital.name.toLowerCase().includes(queryLower);
                        
                        // Secondary filter: match by address if name doesn't match
                        const addressMatch = hospital.address ? hospital.address.toLowerCase().includes(queryLower) : false;
                        
                        // Return true if name matches (primary) or address matches (secondary)
                        return nameMatch || addressMatch;
                    });
                }
                
                // Sort matches: prioritize hospitals near Payatas, then exact name matches
                matches.sort((a, b) => {
                    // Check if near Payatas
                    const aNearPayatas = a.address && payatasAreaKeywords.some(keyword => 
                        a.address.toLowerCase().includes(keyword)
                    );
                    const bNearPayatas = b.address && payatasAreaKeywords.some(keyword => 
                        b.address.toLowerCase().includes(keyword)
                    );
                    
                    // Prioritize hospitals near Payatas
                    if (aNearPayatas && !bNearPayatas) return -1;
                    if (!aNearPayatas && bNearPayatas) return 1;
                    
                    // Then prioritize exact name matches
                    if (queryLower.length > 0) {
                        const aNameExact = a.name.toLowerCase().startsWith(queryLower);
                        const bNameExact = b.name.toLowerCase().startsWith(queryLower);
                        if (aNameExact && !bNameExact) return -1;
                        if (!aNameExact && bNameExact) return 1;
                    }
                    
                    // Alphabetical order for hospitals near Payatas
                    return a.name.localeCompare(b.name);
                });
                
                // Display results
                if (matches.length === 0) {
                    // Show custom input option if no matches
                    if (queryLower.length > 0) {
                        customSection.style.display = 'block';
                    } else {
                        customSection.style.display = 'none';
                    }
                    dropdown.style.display = 'none';
                    return;
                }
                
                // Clear previous results
                customSection.style.display = 'none';
                dropdown.innerHTML = '';
                
                // Populate dropdown with filtered results
                matches.forEach((hospital, index) => {
                    const item = document.createElement('div');
                    item.className = 'dropdown-item';
                    item.style.cssText = 'padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #f0f0f0; transition: background-color 0.2s;';
                    item.innerHTML = `
                        <div style="font-weight: 600;">${hospital.name}</div>
                        <div style="font-size: 12px; color: #666; margin-top: 4px;">${hospital.address || ''}</div>
                    `;
                    // Add hover effect
                    item.addEventListener('mouseenter', function() {
                        this.style.backgroundColor = '#f5f5f5';
                    });
                    item.addEventListener('mouseleave', function() {
                        this.style.backgroundColor = '';
                    });
                    item.addEventListener('click', function(e) {
                        e.stopPropagation();
                        ensureAccordionOpen('referralsContainer');
                        // Set the hospital name in the input field
                        input.value = hospital.name;
                        // Store hospital data in hidden inputs
                        if (hospitalNameInput) hospitalNameInput.value = hospital.name;
                        // Auto-populate the address field
                        if (hospitalAddressInput) {
                            hospitalAddressInput.value = hospital.address || '';
                        }
                        // Auto-populate the contact field
                        if (hospitalContactInput) {
                            hospitalContactInput.value = hospital.contact || '';
                        }
                        // Store in hidden fields for form submission
                        if (addressDataInput) addressDataInput.value = hospital.address || '';
                        if (contactDataInput) contactDataInput.value = hospital.contact || '';
                        // Hide dropdown
                        dropdown.style.display = 'none';
                        // Hide custom section if it was shown
                        if (customSection) customSection.style.display = 'none';
                    });
                    dropdown.appendChild(item);
                });
                
                // Show the dropdown with filtered results
                dropdown.style.display = 'block';
            }
            
            // Show hospitals when field is focused (even before typing)
            input.addEventListener('focus', function(e) {
                e.stopPropagation();
                ensureAccordionOpen('referralsContainer');
                const query = input.value || '';
                filterAndDisplayHospitals(query);
            });
            
            // Filter as user types - use both input and keyup for maximum compatibility
            const handleInput = function(e) {
                if (e) {
                    e.stopPropagation();
                }
                ensureAccordionOpen('referralsContainer');
                // Get the current value directly from the input element
                const currentValue = input.value || '';
                // Call filter function with the current input value
                filterAndDisplayHospitals(currentValue);
            };
            
            // Attach event listeners for real-time filtering
            input.addEventListener('input', handleInput, false);
            input.addEventListener('keyup', handleInput, false);
            // Also handle paste events
            input.addEventListener('paste', function(e) {
                // Small delay to ensure pasted content is in the input
                setTimeout(handleInput, 10);
            });
            
            input.addEventListener('keydown', function(e) {
                e.stopPropagation();
                ensureAccordionOpen('referralsContainer');
                const items = dropdown.querySelectorAll('.dropdown-item');
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                    updateReferralHospitalSelected(dropdown, selectedIndex);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    selectedIndex = Math.max(selectedIndex - 1, -1);
                    updateReferralHospitalSelected(dropdown, selectedIndex);
                } else if (e.key === 'Enter' && selectedIndex >= 0 && items[selectedIndex]) {
                    e.preventDefault();
                    items[selectedIndex].click();
                } else if (e.key === 'Escape') {
                    dropdown.style.display = 'none';
                }
            });
            
            // Use a more specific event listener that doesn't interfere with accordion
            const clickHandler = function(e) {
                if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.style.display = 'none';
                }
            };
            // Use capture phase and check if click is on accordion header
            document.addEventListener('click', clickHandler, true);
            // Store handler for cleanup if needed
            row._autocompleteClickHandler = clickHandler;
        }
        
        function updateReferralHospitalSelected(dropdown, selectedIndex) {
            const items = dropdown.querySelectorAll('.dropdown-item');
            items.forEach((item, index) => {
                if (index === selectedIndex) {
                    item.style.background = '#e3f2fd';
                    item.style.color = '#1976d2';
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.style.background = '';
                    item.style.color = '';
                }
            });
        }
        
        async function createReferralFromRow(button) {
            const row = button.closest('.referral-row');
            if (!row) return;
            
            const hospitalInput = row.querySelector('.referral-hospital-input');
            const hospitalNameInput = row.querySelector('.referral-hospital-name');
            const customHospitalInput = row.querySelector('.referral-custom-hospital-name');
            const customSection = row.querySelector('.referral-custom-hospital-section');
            const hospitalAddress = row.querySelector('.referral-hospital-address');
            const hospitalContact = row.querySelector('.referral-hospital-contact');
            const reasonSelect = row.querySelector('.referral-reason-select');
            const reasonOtherText = row.querySelector('.referral-reason-other-text');
            const clinicalNotesSelect = row.querySelector('.referral-clinical-notes-select');
            const clinicalNotesOtherText = row.querySelector('.referral-clinical-notes-other-text');
            const clinicalNotes = row.querySelector('.referral-clinical-notes');
            const messageDiv = row.querySelector('.referral-message');
            
            // Get hospital name
            let referredHospital = '';
            if (customSection && customSection.style.display === 'block' && customHospitalInput && customHospitalInput.value.trim()) {
                referredHospital = customHospitalInput.value.trim();
                if (!referredHospital) {
                    alert('Please enter the hospital/facility name.');
                    customHospitalInput.focus();
                    return;
                }
            } else if (hospitalNameInput && hospitalNameInput.value.trim()) {
                referredHospital = hospitalNameInput.value.trim();
            } else if (hospitalInput && hospitalInput.value.trim()) {
                referredHospital = hospitalInput.value.trim();
            }
            
            if (!referredHospital) {
                alert('Please select or enter a hospital/facility.');
                if (hospitalInput) hospitalInput.focus();
                return;
            }
            
            // Get reason for referral
            let reasonForReferral = '';
            if (!reasonSelect || !reasonSelect.value) {
                alert('Please select a reason for referral.');
                if (reasonSelect) reasonSelect.focus();
                return;
            }
            
            if (reasonSelect.value === 'Other') {
                reasonForReferral = reasonOtherText ? reasonOtherText.value.trim() : '';
                if (!reasonForReferral) {
                    alert('Please specify the reason for referral.');
                    if (reasonOtherText) reasonOtherText.focus();
                    return;
                }
            } else {
                reasonForReferral = reasonSelect.value;
            }
            
            // Get clinical notes
            let clinicalNotesValue = '';
            if (clinicalNotesSelect && clinicalNotesSelect.value) {
                if (clinicalNotesSelect.value === 'Other') {
                    clinicalNotesValue = (clinicalNotesOtherText ? clinicalNotesOtherText.value.trim() : '') || (clinicalNotes ? clinicalNotes.value.trim() : '');
                } else {
                    const selectedNote = clinicalNotesSelect.value;
                    const additionalNotes = clinicalNotes ? clinicalNotes.value.trim() : '';
                    clinicalNotesValue = additionalNotes ? `${selectedNote}\n\n${additionalNotes}` : selectedNote;
                }
            } else if (clinicalNotes && clinicalNotes.value.trim()) {
                clinicalNotesValue = clinicalNotes.value.trim();
            }
            
            const appointmentId = <?php echo isset($appointment_id) && $appointment_id > 0 ? (int)$appointment_id : 'null'; ?>;
            const consultationId = <?php 
                if (isset($appointment_id) && $appointment_id > 0) {
                    $stmt = $pdo->prepare("SELECT id FROM doctor_consultations WHERE appointment_id = ? ORDER BY created_at DESC LIMIT 1");
                    $stmt->execute([$appointment_id]);
                    $consult = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo $consult ? (int)$consult['id'] : 'null';
                } else {
                    echo 'null';
                }
            ?>;
            
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
            if (messageDiv) messageDiv.style.display = 'none';
            
            try {
                const formData = new FormData();
                formData.append('action', 'create_referral');
                formData.append('patient_id', patientId);
                formData.append('referred_hospital', referredHospital);
                formData.append('hospital_address', hospitalAddress ? hospitalAddress.value.trim() : '');
                formData.append('hospital_contact', hospitalContact ? hospitalContact.value.trim() : '');
                formData.append('reason_for_referral', reasonForReferral);
                if (clinicalNotesValue) formData.append('clinical_notes', clinicalNotesValue);
                if (appointmentId) formData.append('appointment_id', appointmentId);
                if (consultationId) formData.append('consultation_id', consultationId);
                
                const response = await fetch('doctor_referral_handler.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    if (messageDiv) {
                        messageDiv.style.display = 'block';
                        messageDiv.style.padding = '12px';
                        messageDiv.style.borderRadius = '6px';
                        messageDiv.style.backgroundColor = '#d4edda';
                        messageDiv.style.color = '#155724';
                        messageDiv.style.border = '1px solid #c3e6cb';
                        messageDiv.innerHTML = '<i class="fas fa-check-circle"></i> Referral created successfully! Date: ' + 
                            new Date(data.referral_date).toLocaleDateString();
                    }
                    
                    // Reload referrals list
                    loadReferrals();
                    
                    // Ensure accordion stays open
                    ensureAccordionOpen('referralsContainer');
                    
                    // Ensure scrolling is enabled
                    ensureScrollingEnabled();
                    
                    // Reset button
                    setTimeout(() => {
                        button.disabled = false;
                        button.innerHTML = '<i class="fas fa-file-medical"></i> Create Referral Letter';
                    }, 2000);
                } else {
                    throw new Error(data.message || 'Failed to create referral');
                }
            } catch (error) {
                if (messageDiv) {
                    messageDiv.style.display = 'block';
                    messageDiv.style.padding = '12px';
                    messageDiv.style.borderRadius = '6px';
                    messageDiv.style.backgroundColor = '#f8d7da';
                    messageDiv.style.color = '#721c24';
                    messageDiv.style.border = '1px solid #f5c6cb';
                    messageDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Error: ' + error.message;
                }
                
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-file-medical"></i> Create Referral Letter';
            }
        }
        
        function updateCertificateSubtype() {
            const certificateTypeSelect = document.getElementById('certificateType');
            const fitStatusSection = document.getElementById('fitStatusSection');
            const fitStatusSelect = document.getElementById('fitStatus');
            
            if (!certificateTypeSelect || !fitStatusSection) return;
            
            const selectedOption = certificateTypeSelect.options[certificateTypeSelect.selectedIndex];
            const certificateType = selectedOption.value;
            const certificateSubtype = selectedOption.getAttribute('data-subtype');
            
            // Show/hide fit status section for work-related certificates
            if (certificateType === 'work_related') {
                fitStatusSection.style.display = 'block';
                fitStatusSelect.required = true;
            } else {
                fitStatusSection.style.display = 'none';
                fitStatusSelect.required = false;
                fitStatusSelect.value = '';
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const generateCertBtn = document.getElementById('generateCertificateBtn');
            const certificateMessage = document.getElementById('certificateMessage');
            const existingCertificates = document.getElementById('existingCertificates');
            
            if (generateCertBtn) {
                generateCertBtn.addEventListener('click', async function() {
                    const certificateTypeSelect = document.getElementById('certificateType');
                    const certificateType = certificateTypeSelect ? certificateTypeSelect.value : '';
                    const selectedOption = certificateTypeSelect ? certificateTypeSelect.options[certificateTypeSelect.selectedIndex] : null;
                    const certificateSubtype = selectedOption ? selectedOption.getAttribute('data-subtype') : '';
                    const fitStatus = document.getElementById('fitStatus') ? document.getElementById('fitStatus').value : '';
                    const validityPeriod = document.getElementById('certificateValidityPeriod').value;
                    const customExpirationDate = document.getElementById('certificateCustomExpirationDate') ? document.getElementById('certificateCustomExpirationDate').value : '';
                    
                    if (!certificateType) {
                        alert('Please select a certificate type.');
                        return;
                    }
                    
                    if (certificateType === 'work_related' && !fitStatus) {
                        alert('Please select whether the patient is fit or unfit to work.');
                        return;
                    }
                    
                    if (!validityPeriod) {
                        alert('Please select a validity period for the certificate.');
                        return;
                    }
                    
                    if (validityPeriod === 'custom' && !customExpirationDate) {
                        alert('Please select a custom expiration date.');
                        return;
                    }
                    
                    // Get appointment_id and consultation_id if available
                    const appointmentId = <?php echo isset($appointment_id) && $appointment_id > 0 ? (int)$appointment_id : 'null'; ?>;
                    const consultationId = <?php 
                        if (isset($appointment_id) && $appointment_id > 0) {
                            $stmt = $pdo->prepare("SELECT id FROM doctor_consultations WHERE appointment_id = ? ORDER BY created_at DESC LIMIT 1");
                            $stmt->execute([$appointment_id]);
                            $consult = $stmt->fetch(PDO::FETCH_ASSOC);
                            echo $consult ? (int)$consult['id'] : 'null';
                        } else {
                            echo 'null';
                        }
                    ?>;
                    
                    generateCertBtn.disabled = true;
                    generateCertBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
                    certificateMessage.style.display = 'none';
                    
                    try {
                        const formData = new FormData();
                        formData.append('action', 'generate_certificate');
                        formData.append('patient_id', patientId);
                        formData.append('certificate_type', certificateType);
                        formData.append('certificate_subtype', certificateSubtype);
                        if (fitStatus) formData.append('fit_status', fitStatus);
                        formData.append('validity_period_days', validityPeriod);
                        if (validityPeriod === 'custom' && customExpirationDate) {
                            formData.append('custom_expiration_date', customExpirationDate);
                        }
                        if (appointmentId) formData.append('appointment_id', appointmentId);
                        if (consultationId) formData.append('consultation_id', consultationId);
                        
                        const response = await fetch('doctor_medical_certificate_handler.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const data = await response.json();
                        
                        if (data.success) {
                            certificateMessage.style.display = 'block';
                            certificateMessage.style.padding = '12px';
                            certificateMessage.style.borderRadius = '6px';
                            certificateMessage.style.backgroundColor = '#d4edda';
                            certificateMessage.style.color = '#155724';
                            certificateMessage.style.border = '1px solid #c3e6cb';
                            certificateMessage.innerHTML = '<i class="fas fa-check-circle"></i> Medical certificate generated successfully! Issued: ' + 
                                new Date(data.issued_date).toLocaleDateString() + ', Expires: ' + 
                                new Date(data.expiration_date).toLocaleDateString();
                            
                            // Reload certificates list
                            loadCertificates();
                            
                            // Reset button
                            setTimeout(() => {
                                generateCertBtn.disabled = false;
                                generateCertBtn.innerHTML = '<i class="fas fa-certificate"></i> Generate Medical Certificate';
                            }, 2000);
                        } else {
                            throw new Error(data.message || 'Failed to generate certificate');
                        }
                    } catch (error) {
                        certificateMessage.style.display = 'block';
                        certificateMessage.style.padding = '12px';
                        certificateMessage.style.borderRadius = '6px';
                        certificateMessage.style.backgroundColor = '#f8d7da';
                        certificateMessage.style.color = '#721c24';
                        certificateMessage.style.border = '1px solid #f5c6cb';
                        certificateMessage.innerHTML = '<i class="fas fa-exclamation-circle"></i> Error: ' + error.message;
                        
                        generateCertBtn.disabled = false;
                        generateCertBtn.innerHTML = '<i class="fas fa-certificate"></i> Generate Medical Certificate';
                    }
                });
            }
            
            // Load existing certificates
            loadCertificates();
        });
        
        async function loadCertificates() {
            const existingCertificates = document.getElementById('existingCertificates');
            if (!existingCertificates) return;
            
            try {
                const formData = new FormData();
                formData.append('action', 'get_certificates');
                formData.append('patient_id', patientId);
                
                const response = await fetch('doctor_medical_certificate_handler.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success && data.certificates && data.certificates.length > 0) {
                    // Format certificate type and subtype labels
                    const typeLabels = {
                        'work_related': 'Work-Related',
                        'education': 'Education',
                        'travel': 'Travel',
                        'licensing': 'Licensing & Permits',
                        'general': 'General'
                    };
                    const subtypeLabels = {
                        'sick_leave': 'Sick Leave',
                        'fit_to_work': 'Fit-to-Work',
                        'food_handler': 'Food Handler',
                        'high_risk_work': 'High-Risk Work',
                        'school_clearance': 'School Clearance',
                        'travel_clearance': 'Travel Clearance',
                        'driver_license': 'Driver\'s License',
                        'professional_license': 'Professional License',
                        'health_checkup': 'General Health Check-up',
                        'pwd_registration': 'PWD Registration'
                    };
                    
                    let html = `
                        <div class="category-accordion active" onclick="toggleCategory(this)">
                            <div class="category-header">
                                <div class="category-header-title">
                                    <i class="fas fa-certificate" style="color: #F57C00;"></i>
                                    <span>Medical Certificates</span>
                                    <span style="font-size: 13px; font-weight: 400; color: #666; margin-left: 8px;">
                                        (${data.certificates.length})
                                    </span>
                                </div>
                                <span class="category-toggle">+</span>
                            </div>
                            <div class="category-content">
                                <div class="document-list">
                    `;
                    
                    data.certificates.forEach(cert => {
                        const issuedDate = new Date(cert.issued_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                        const expDate = new Date(cert.expiration_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                        const today = new Date();
                        today.setHours(0, 0, 0, 0);
                        const expDateObj = new Date(cert.expiration_date);
                        expDateObj.setHours(0, 0, 0, 0);
                        const isExpired = expDateObj < today;
                        const cardId = 'cert-' + cert.id;
                        
                        const certType = typeLabels[cert.certificate_type] || cert.certificate_type || 'Medical Certificate';
                        const certSubtype = subtypeLabels[cert.certificate_subtype] || cert.certificate_subtype || '';
                        const fitStatus = cert.fit_status ? ` (${cert.fit_status === 'fit' ? 'Fit' : 'Unfit'} to Work)` : '';
                        const certTitle = certSubtype ? `${certSubtype}${fitStatus}` : certType;
                        
                        html += `
                            <div class="document-item" onclick="event.stopPropagation(); toggleDocumentDetails('${cardId}')">
                                <div class="document-row">
                                    <div class="document-chevron">
                                        <i class="fas fa-chevron-right"></i>
                                    </div>
                                    <div class="document-row-content">
                                        <h3 class="document-title">
                                            ${certTitle}
                                        </h3>
                                        <span class="document-status" style="background: ${isExpired ? '#ff9800' : '#4CAF50'}; color: white;">
                                            ${isExpired ? 'Expired' : 'Active'}
                                        </span>
                                    </div>
                                </div>
                                <div class="document-details" id="${cardId}">
                                    <div class="document-details-content">
                                        <p style="margin: 0 0 10px 0; color: #666; font-size: 12px; font-style: italic;">
                                            Certificate #${cert.id}
                                        </p>
                                        <div style="margin-bottom: 10px;">
                                            <strong>Issued Date:</strong> ${issuedDate}<br>
                                            <strong>Expiration Date:</strong> ${expDate}<br>
                                            <strong>Validity Period:</strong> ${cert.validity_period_days} days
                                        </div>
                                        ${isExpired ? `
                                            <p style="color: #999; font-size: 11px; margin-top: 10px;">
                                                This certificate expired on ${expDate}.
                                            </p>
                                        ` : `
                                            <p style="color: #4CAF50; font-size: 11px; font-weight: 600; margin-top: 10px;">
                                                <i class="fas fa-check-circle"></i> Valid until ${expDate}
                                            </p>
                                        `}
                                    </div>
                                    <div class="document-actions">
                                        ${!isExpired ? `
                                            <a href="generate_medical_certificate_pdf.php?certificate_id=${cert.id}&mode=view" 
                                               class="btn-view"
                                               onclick="event.stopPropagation(); openPdfModal(event, 'Medical Certificate'); return false;">
                                                <i class="fas fa-eye"></i> View Document
                                            </a>
                                            <a href="generate_medical_certificate_pdf.php?certificate_id=${cert.id}" 
                                               target="_blank" 
                                               class="btn-download"
                                               onclick="event.stopPropagation();">
                                                <i class="fas fa-download"></i> Download PDF
                                            </a>
                                        ` : `
                                            <a href="generate_medical_certificate_pdf.php?certificate_id=${cert.id}&mode=view" 
                                               class="btn-view"
                                               onclick="event.stopPropagation(); openPdfModal(event, 'Medical Certificate'); return false;">
                                                <i class="fas fa-eye"></i> View Document
                                            </a>
                                            <a href="generate_medical_certificate_pdf.php?certificate_id=${cert.id}" 
                                               target="_blank" 
                                               class="btn-download"
                                               onclick="event.stopPropagation();">
                                                <i class="fas fa-download"></i> Download PDF
                                            </a>
                                        `}
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    html += `
                                </div>
                            </div>
                        </div>
                    `;
                    
                    existingCertificates.innerHTML = html;
                } else {
                    existingCertificates.innerHTML = '<p style="color: #999; font-style: italic; margin-top: 15px;">No certificates issued yet.</p>';
                }
            } catch (error) {
                console.error('Error loading certificates:', error);
                existingCertificates.innerHTML = '<p style="color: #d32f2f;">Error loading certificates.</p>';
            }
        }

        // Referral Functions
        const referralHospitalsData = <?php echo json_encode($referralHospitals); ?>;
        
        // Initialize hospital autocomplete
        function initializeHospitalAutocomplete() {
            const input = document.getElementById('referredHospitalInput');
            const dropdown = document.getElementById('hospitalAutocompleteDropdown');
            const hospitalNameInput = document.getElementById('referredHospitalName');
            const hospitalAddressInput = document.getElementById('referredHospitalAddress');
            const hospitalContactInput = document.getElementById('referredHospitalContact');
            const addressDataInput = document.getElementById('referredHospitalAddressData');
            const contactDataInput = document.getElementById('referredHospitalContactData');
            const customSection = document.getElementById('customHospitalSection');
            const customInput = document.getElementById('customHospitalName');
            
            if (!input || !dropdown) return;
            
            let selectedIndex = -1;
            let filteredHospitals = [];
            
            input.addEventListener('input', function() {
                const query = this.value.trim().toLowerCase();
                selectedIndex = -1;
                
                if (query.length === 0) {
                    dropdown.style.display = 'none';
                    customSection.style.display = 'none';
                    customInput.required = false;
                    customInput.value = '';
                    return;
                }
                
                // Filter hospitals
                filteredHospitals = referralHospitalsData.filter(hospital => 
                    hospital.name.toLowerCase().includes(query)
                );
                
                if (filteredHospitals.length === 0) {
                    dropdown.innerHTML = '<div class="dropdown-item" style="color: #999; cursor: default; padding: 10px;">No matching hospitals found</div>';
                    dropdown.style.display = 'block';
                    return;
                }
                
                dropdown.innerHTML = '';
                filteredHospitals.forEach((hospital, index) => {
                    const item = document.createElement('div');
                    item.className = 'dropdown-item';
                    item.style.cssText = 'padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #eee;';
                    item.innerHTML = `
                        <div style="font-weight: 600; color: #333;">${hospital.name}</div>
                        ${hospital.address ? `<div style="font-size: 12px; color: #666; margin-top: 3px;">${hospital.address}</div>` : ''}
                    `;
                    
                    item.addEventListener('mouseenter', function() {
                        this.style.backgroundColor = '#f5f5f5';
                    });
                    item.addEventListener('mouseleave', function() {
                        this.style.backgroundColor = '';
                    });
                    
                    item.addEventListener('click', function() {
                        if (hospital.name === 'Other Hospital') {
                            // Show custom input
                            customSection.style.display = 'block';
                            customInput.required = true;
                            customInput.focus();
                            input.value = '';
                            hospitalNameInput.value = '';
                            addressDataInput.value = '';
                            contactDataInput.value = '';
                            hospitalAddressInput.value = '';
                            hospitalContactInput.value = '';
                        } else {
                            // Auto-fill hospital data
                            input.value = hospital.name;
                            hospitalNameInput.value = hospital.name;
                            addressDataInput.value = hospital.address || '';
                            contactDataInput.value = hospital.contact || '';
                            hospitalAddressInput.value = hospital.address || '';
                            hospitalContactInput.value = hospital.contact || '';
                            customSection.style.display = 'none';
                            customInput.required = false;
                            customInput.value = '';
                        }
                        dropdown.style.display = 'none';
                    });
                    
                    dropdown.appendChild(item);
                });
                
                dropdown.style.display = 'block';
            });
            
            input.addEventListener('keydown', function(e) {
                if (!dropdown.style.display || dropdown.style.display === 'none') return;
                
                const items = dropdown.querySelectorAll('.dropdown-item');
                if (items.length === 0) return;
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                    items[selectedIndex].scrollIntoView({ block: 'nearest' });
                    items.forEach((item, idx) => {
                        item.style.backgroundColor = idx === selectedIndex ? '#e3f2fd' : '';
                    });
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    selectedIndex = Math.max(selectedIndex - 1, -1);
                    items.forEach((item, idx) => {
                        item.style.backgroundColor = idx === selectedIndex ? '#e3f2fd' : '';
                    });
                } else if (e.key === 'Enter' && selectedIndex >= 0) {
                    e.preventDefault();
                    items[selectedIndex].click();
                } else if (e.key === 'Escape') {
                    dropdown.style.display = 'none';
                }
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.style.display = 'none';
                }
            });
        }
        
        function toggleReasonOther() {
            const reasonSelect = document.getElementById('reasonForReferral');
            const otherSection = document.getElementById('reasonOtherSection');
            const otherText = document.getElementById('reasonOtherText');
            
            if (reasonSelect && otherSection) {
                if (reasonSelect.value === 'Other') {
                    otherSection.style.display = 'block';
                    otherText.required = true;
                } else {
                    otherSection.style.display = 'none';
                    otherText.required = false;
                    otherText.value = '';
                }
            }
        }
        
        function toggleClinicalNotesOther() {
            const notesSelect = document.getElementById('clinicalNotesSelect');
            const otherSection = document.getElementById('clinicalNotesOtherSection');
            const otherText = document.getElementById('clinicalNotesOtherText');
            const freeTextArea = document.getElementById('clinicalNotes');
            
            if (notesSelect && otherSection && freeTextArea) {
                if (notesSelect.value === 'Other') {
                    otherSection.style.display = 'block';
                    otherText.required = false;
                    freeTextArea.style.display = 'block';
                    freeTextArea.value = '';
                    freeTextArea.placeholder = 'Enter additional clinical notes, findings, or relevant medical history...';
                } else if (notesSelect.value) {
                    otherSection.style.display = 'none';
                    otherText.value = '';
                    otherText.required = false;
                    freeTextArea.style.display = 'block';
                    freeTextArea.value = notesSelect.value;
                    freeTextArea.placeholder = 'You can add more details below the selected note...';
                } else {
                    otherSection.style.display = 'none';
                    otherText.value = '';
                    otherText.required = false;
                    freeTextArea.style.display = 'none';
                    freeTextArea.value = '';
                }
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const createReferralBtn = document.getElementById('createReferralBtn');
            const referralMessage = document.getElementById('referralMessage');
            const existingReferrals = document.getElementById('existingReferrals');
            
            // Initialize hospital autocomplete
            initializeHospitalAutocomplete();
            
            if (createReferralBtn) {
                createReferralBtn.addEventListener('click', async function() {
                    const hospitalInput = document.getElementById('referredHospitalInput');
                    const hospitalNameInput = document.getElementById('referredHospitalName');
                    const customHospitalInput = document.getElementById('customHospitalName');
                    const customSection = document.getElementById('customHospitalSection');
                    const hospitalAddress = document.getElementById('referredHospitalAddress');
                    const hospitalContact = document.getElementById('referredHospitalContact');
                    const reasonSelect = document.getElementById('reasonForReferral');
                    const reasonOtherText = document.getElementById('reasonOtherText');
                    const clinicalNotesSelect = document.getElementById('clinicalNotesSelect');
                    const clinicalNotesOtherText = document.getElementById('clinicalNotesOtherText');
                    const clinicalNotes = document.getElementById('clinicalNotes');
                    
                    // Get hospital name
                    let referredHospital = '';
                    if (customSection.style.display === 'block' && customHospitalInput.value.trim()) {
                        // Custom hospital entered
                        referredHospital = customHospitalInput.value.trim();
                        if (!referredHospital) {
                            alert('Please enter the hospital/facility name.');
                            customHospitalInput.focus();
                            return;
                        }
                    } else if (hospitalNameInput.value.trim()) {
                        // Hospital selected from dropdown
                        referredHospital = hospitalNameInput.value.trim();
                    } else if (hospitalInput.value.trim()) {
                        // Manual entry (fallback)
                        referredHospital = hospitalInput.value.trim();
                    }
                    
                    if (!referredHospital) {
                        alert('Please select or enter a hospital/facility.');
                        hospitalInput.focus();
                        return;
                    }
                    
                    // Get reason for referral
                    let reasonForReferral = '';
                    if (!reasonSelect.value) {
                        alert('Please select a reason for referral.');
                        reasonSelect.focus();
                        return;
                    }
                    
                    if (reasonSelect.value === 'Other') {
                        reasonForReferral = reasonOtherText.value.trim();
                        if (!reasonForReferral) {
                            alert('Please specify the reason for referral.');
                            reasonOtherText.focus();
                            return;
                        }
                    } else {
                        reasonForReferral = reasonSelect.value;
                    }
                    
                    // Get clinical notes
                    let clinicalNotesValue = '';
                    if (clinicalNotesSelect.value) {
                        if (clinicalNotesSelect.value === 'Other') {
                            clinicalNotesValue = clinicalNotesOtherText.value.trim() || clinicalNotes.value.trim();
                        } else {
                            // Combine selected option with any additional free text
                            const selectedNote = clinicalNotesSelect.value;
                            const additionalNotes = clinicalNotes.value.trim();
                            clinicalNotesValue = additionalNotes ? `${selectedNote}\n\n${additionalNotes}` : selectedNote;
                        }
                    } else if (clinicalNotes.value.trim()) {
                        clinicalNotesValue = clinicalNotes.value.trim();
                    }
                    
                    // Get appointment_id and consultation_id if available
                    const appointmentId = <?php echo isset($appointment_id) && $appointment_id > 0 ? (int)$appointment_id : 'null'; ?>;
                    const consultationId = <?php 
                        if (isset($appointment_id) && $appointment_id > 0) {
                            $stmt = $pdo->prepare("SELECT id FROM doctor_consultations WHERE appointment_id = ? ORDER BY created_at DESC LIMIT 1");
                            $stmt->execute([$appointment_id]);
                            $consult = $stmt->fetch(PDO::FETCH_ASSOC);
                            echo $consult ? (int)$consult['id'] : 'null';
                        } else {
                            echo 'null';
                        }
                    ?>;
                    
                    createReferralBtn.disabled = true;
                    createReferralBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
                    referralMessage.style.display = 'none';
                    
                    try {
                        const formData = new FormData();
                        formData.append('action', 'create_referral');
                        formData.append('patient_id', patientId);
                        formData.append('referred_hospital', referredHospital);
                        if (hospitalAddress.value.trim()) formData.append('referred_hospital_address', hospitalAddress.value.trim());
                        if (hospitalContact.value.trim()) formData.append('referred_hospital_contact', hospitalContact.value.trim());
                        formData.append('reason_for_referral', reasonForReferral);
                        if (clinicalNotesValue) formData.append('clinical_notes', clinicalNotesValue);
                        if (appointmentId) formData.append('appointment_id', appointmentId);
                        if (consultationId) formData.append('consultation_id', consultationId);
                        
                        const response = await fetch('doctor_referral_handler.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const data = await response.json();
                        
                        if (data.success) {
                            referralMessage.style.display = 'block';
                            referralMessage.style.padding = '12px';
                            referralMessage.style.borderRadius = '6px';
                            referralMessage.style.backgroundColor = '#d4edda';
                            referralMessage.style.color = '#155724';
                            referralMessage.style.border = '1px solid #c3e6cb';
                            referralMessage.innerHTML = '<i class="fas fa-check-circle"></i> Referral created successfully! Date: ' + 
                                new Date(data.referral_date).toLocaleDateString();
                            
                            // Clear form
                            hospitalInput.value = '';
                            hospitalNameInput.value = '';
                            if (customHospitalInput) customHospitalInput.value = '';
                            if (customSection) customSection.style.display = 'none';
                            if (hospitalAddress) hospitalAddress.value = '';
                            if (hospitalContact) hospitalContact.value = '';
                            reasonSelect.value = '';
                            if (reasonOtherText) {
                                reasonOtherText.value = '';
                                document.getElementById('reasonOtherSection').style.display = 'none';
                            }
                            clinicalNotesSelect.value = '';
                            if (clinicalNotesOtherText) {
                                clinicalNotesOtherText.value = '';
                                document.getElementById('clinicalNotesOtherSection').style.display = 'none';
                            }
                            if (clinicalNotes) {
                                clinicalNotes.value = '';
                                clinicalNotes.style.display = 'none';
                            }
                            
                            // Reload referrals list
                            loadReferrals();
                            
                            // Reset button
                            setTimeout(() => {
                                createReferralBtn.disabled = false;
                                createReferralBtn.innerHTML = '<i class="fas fa-file-medical"></i> Create Referral Letter';
                            }, 2000);
                        } else {
                            throw new Error(data.message || 'Failed to create referral');
                        }
                    } catch (error) {
                        referralMessage.style.display = 'block';
                        referralMessage.style.padding = '12px';
                        referralMessage.style.borderRadius = '6px';
                        referralMessage.style.backgroundColor = '#f8d7da';
                        referralMessage.style.color = '#721c24';
                        referralMessage.style.border = '1px solid #f5c6cb';
                        referralMessage.innerHTML = '<i class="fas fa-exclamation-circle"></i> Error: ' + error.message;
                        
                        createReferralBtn.disabled = false;
                        createReferralBtn.innerHTML = '<i class="fas fa-file-medical"></i> Create Referral Letter';
                    }
                });
            }
            
            // Load existing referrals
            loadReferrals();
        });
        
        async function loadReferrals() {
            const existingReferrals = document.getElementById('existingReferrals');
            if (!existingReferrals) return;
            
            try {
                const formData = new FormData();
                formData.append('action', 'get_referrals');
                formData.append('patient_id', patientId);
                
                const response = await fetch('doctor_referral_handler.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success && data.referrals && data.referrals.length > 0) {
                    let html = `
                        <div class="category-accordion active" onclick="toggleCategory(this)">
                            <div class="category-header">
                                <div class="category-header-title">
                                    <i class="fas fa-hospital" style="color: #9C27B0;"></i>
                                    <span>Referrals</span>
                                    <span style="font-size: 13px; font-weight: 400; color: #666; margin-left: 8px;">
                                        (${data.referrals.length})
                                    </span>
                                </div>
                                <span class="category-toggle">+</span>
                            </div>
                            <div class="category-content">
                                <div class="document-list">
                    `;
                    
                    data.referrals.forEach(ref => {
                        const referralDate = new Date(ref.referral_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                        const statusClass = ref.status === 'active' ? 'status-active' : ref.status === 'completed' ? 'status-completed' : 'status-cancelled';
                        const statusText = ref.status === 'active' ? 'Active' : ref.status === 'completed' ? 'Completed' : 'Cancelled';
                        const cardId = 'ref-' + ref.id;
                        
                        html += `
                            <div class="document-item" onclick="event.stopPropagation(); toggleDocumentDetails('${cardId}')">
                                <div class="document-row">
                                    <div class="document-chevron">
                                        <i class="fas fa-chevron-right"></i>
                                    </div>
                                    <div class="document-row-content">
                                        <h3 class="document-title">
                                            <i class="fas fa-hospital" style="margin-right: 6px; color: #9C27B0;"></i> ${ref.referred_hospital}
                                        </h3>
                                        <span class="document-status" style="background: ${ref.status === 'active' ? '#4CAF50' : ref.status === 'completed' ? '#2196F3' : '#999'}; color: white;">
                                            ${statusText}
                                        </span>
                                    </div>
                                </div>
                                <div class="document-details" id="${cardId}">
                                    <div class="document-details-content">
                                        <p style="margin: 0 0 10px 0; color: #666; font-size: 12px; font-style: italic;">
                                            Referral #${ref.id}
                                        </p>
                                        <div style="margin-bottom: 10px;">
                                            <strong>Referral Date:</strong> ${referralDate}<br>
                                            <strong>Reason for Referral:</strong> ${ref.reason_for_referral}<br>
                                            ${ref.referred_hospital_address ? `<strong>Hospital Address:</strong> ${ref.referred_hospital_address}<br>` : ''}
                                            ${ref.referred_hospital_contact ? `<strong>Hospital Contact:</strong> ${ref.referred_hospital_contact}<br>` : ''}
                                            ${ref.clinical_notes ? `<strong>Clinical Notes:</strong> ${ref.clinical_notes}<br>` : ''}
                                        </div>
                                    </div>
                                    <div class="document-actions">
                                        <a href="generate_referral_pdf.php?referral_id=${ref.id}&mode=view" 
                                           class="btn-view"
                                           onclick="event.stopPropagation(); openPdfModal(event, 'Referral Letter'); return false;">
                                            <i class="fas fa-eye"></i> View Document
                                        </a>
                                        <a href="generate_referral_pdf.php?referral_id=${ref.id}" 
                                           target="_blank" 
                                           class="btn-download"
                                           onclick="event.stopPropagation();">
                                            <i class="fas fa-download"></i> Download PDF
                                        </a>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    html += `
                                </div>
                            </div>
                        </div>
                    `;
                    
                    existingReferrals.innerHTML = html;
                } else {
                    existingReferrals.innerHTML = '<p style="color: #999; font-style: italic; margin-top: 15px;">No referrals issued yet.</p>';
                }
            } catch (error) {
                console.error('Error loading referrals:', error);
                existingReferrals.innerHTML = '<p style="color: #d32f2f;">Error loading referrals.</p>';
            }
        }

        async function loadPrescriptions() {
            const existingPrescriptions = document.getElementById('existingPrescriptions');
            if (!existingPrescriptions) return;
            const countEl = document.getElementById('prescriptionAccordionCount');
            try {
                const formData = new FormData();
                formData.append('action', 'get_prescriptions');
                formData.append('patient_id', patientId);
                const response = await fetch('doctor_prescription_handler.php', { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success && data.prescriptions && data.prescriptions.length > 0) {
                    const list = data.prescriptions;
                    if (countEl) countEl.textContent = '(' + list.length + ')';
                    let html = '<div class="document-list" style="margin-top: 10px;">';
                    list.forEach(p => {
                        const dateIssued = p.date_issued ? new Date(p.date_issued).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
                        const expDate = p.expiration_date ? new Date(p.expiration_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : 'No expiration';
                        const today = new Date();
                        today.setHours(0, 0, 0, 0);
                        const expObj = p.expiration_date ? new Date(p.expiration_date) : null;
                        const isExpired = expObj ? (expObj.setHours(0, 0, 0, 0), expObj < today) : false;
                        const statusBg = isExpired ? '#999' : (p.status === 'active' ? '#4CAF50' : '#2196F3');
                        const items = (p.items || []);
                        const medSummary = items.length ? items.slice(0, 3).map(i => (i.medicine_name || i.drug_name || '') + (i.dosage ? ' ' + i.dosage : '')).join('; ') + (items.length > 3 ? ' (+' + (items.length - 3) + ' more)' : '') : 'No items';
                        const cardId = 'rx-' + p.id;
                        html += `
                            <div class="document-item" onclick="event.stopPropagation(); toggleDocumentDetails('${cardId}')">
                                <div class="document-row">
                                    <div class="document-chevron"><i class="fas fa-chevron-right"></i></div>
                                    <div class="document-row-content">
                                        <h3 class="document-title">Prescription #${p.id} — ${dateIssued}</h3>
                                        <span class="document-status" style="background: ${statusBg}; color: white;">${isExpired ? 'Expired' : (p.status || 'Active')}</span>
                                    </div>
                                </div>
                                <div class="document-details" id="${cardId}">
                                    <div class="document-details-content">
                                        <p style="margin: 0 0 10px 0; color: #666; font-size: 12px;">Diagnosis: ${(p.diagnosis || '—')}</p>
                                        <p style="margin: 0 0 8px 0; font-size: 12px;"><strong>Date issued:</strong> ${dateIssued} &nbsp; <strong>Expiration:</strong> ${expDate}</p>
                                        <p style="margin: 0 0 8px 0; font-size: 12px;"><strong>Medicines:</strong> ${medSummary}</p>
                                        ${items.length ? '<ul style="margin: 8px 0 0 0; padding-left: 20px; font-size: 12px;">' + items.map(i => '<li>' + (i.medicine_name || i.drug_name || '') + (i.dosage ? ' — ' + i.dosage : '') + (i.frequency ? ', ' + i.frequency : '') + (i.duration ? ', ' + i.duration : '') + '</li>').join('') + '</ul>' : ''}
                                    </div>
                                </div>
                            </div>`;
                    });
                    html += '</div>';
                    existingPrescriptions.innerHTML = html;
                } else {
                    if (countEl) countEl.textContent = '';
                    existingPrescriptions.innerHTML = '<p style="color: #999; font-style: italic; margin-top: 15px;">No prescriptions for this patient yet. Add medicines above and complete the consultation to save.</p>';
                }
            } catch (error) {
                console.error('Error loading prescriptions:', error);
                if (countEl) countEl.textContent = '';
                existingPrescriptions.innerHTML = '<p style="color: #d32f2f;">Error loading prescriptions.</p>';
            }
        }

        // Toggle category accordion
        // Global safeguard to ensure scrolling is always enabled (except when modals are open)
        function ensureScrollingEnabled() {
            const pdfModal = document.getElementById('pdfModal');
            const hasActiveModal = (pdfModal && pdfModal.classList.contains('active'));
            
            if (!hasActiveModal) {
                // Ensure body and html allow vertical scrolling
                if (document.body.style.overflow === 'hidden' || document.body.style.overflowY === 'hidden') {
                    document.body.style.overflow = '';
                    document.body.style.overflowY = 'auto';
                }
                if (document.documentElement.style.overflow === 'hidden' || document.documentElement.style.overflowY === 'hidden') {
                    document.documentElement.style.overflow = '';
                    document.documentElement.style.overflowY = 'auto';
                }
            }
        }
        
        // Monitor and ensure scrolling is enabled periodically
        setInterval(ensureScrollingEnabled, 500);
        
        function toggleCategory(element) {
            // Only toggle if explicitly clicking the header (not programmatically)
            const isActive = element.classList.contains('active');
            if (!isActive) {
                element.classList.add('active');
            } else {
                // Allow collapsing only if user explicitly clicks the header
                element.classList.remove('active');
            }
            
            // Ensure scrolling remains enabled after toggle
            setTimeout(ensureScrollingEnabled, 100);
        }
        
        // Helper function to ensure accordion stays open (used by Add buttons)
        function ensureAccordionOpen(containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;
            
            // Find the parent accordion by traversing up the DOM tree
            let element = container;
            let accordion = null;
            let maxDepth = 10; // Safety limit
            let depth = 0;
            
            while (element && depth < maxDepth) {
                if (element.classList && element.classList.contains('category-accordion')) {
                    accordion = element;
                    break;
                }
                element = element.parentElement;
                depth++;
            }
            
            // If not found, try querySelector approach
            if (!accordion) {
                const consultCard = container.closest('.consult-card');
                if (consultCard) {
                    accordion = consultCard.querySelector('.category-accordion');
                }
            }
            
            // Also try direct ID lookup for specific accordions
            if (!accordion) {
                if (containerId === 'medicalCertificatesContainer') {
                    accordion = document.getElementById('medicalCertificateAccordion');
                } else if (containerId === 'referralsContainer') {
                    accordion = document.getElementById('referralAccordion');
                } else if (containerId === 'consultPrescriptionRows') {
                    accordion = document.getElementById('prescriptionAccordion');
                }
            }
            
            if (accordion && !accordion.classList.contains('active')) {
                accordion.classList.add('active');
            }
            
            // Ensure scrolling is enabled
            ensureScrollingEnabled();
        }
        
        // Prevent accordion collapse during user interaction
        document.addEventListener('DOMContentLoaded', function() {
            // Add event listeners to prevent collapse when interacting with form elements
            const medicalCertAccordion = document.getElementById('medicalCertificateAccordion');
            const referralAccordion = document.getElementById('referralAccordion');
            const prescriptionAccordion = document.getElementById('prescriptionAccordion');
            
            // Function to add protection to form elements
            function protectAccordionFromCollapse(accordion, containerId) {
                if (!accordion) return;
                
                const content = accordion.querySelector('.category-content');
                if (!content) return;
                
                // Use event delegation for dynamically added elements
                content.addEventListener('click', function(e) {
                    // Only stop propagation for form elements, not the content div itself
                    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || 
                        e.target.tagName === 'TEXTAREA' || e.target.closest('input, select, textarea')) {
                        e.stopPropagation();
                        ensureAccordionOpen(containerId);
                    }
                }, true);
                
                content.addEventListener('focusin', function(e) {
                    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || 
                        e.target.tagName === 'TEXTAREA') {
                        e.stopPropagation();
                        ensureAccordionOpen(containerId);
                    }
                }, true);
                
                content.addEventListener('input', function(e) {
                    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || 
                        e.target.tagName === 'TEXTAREA') {
                        e.stopPropagation();
                        ensureAccordionOpen(containerId);
                    }
                }, true);
            }
            
            // Protect all three accordions
            protectAccordionFromCollapse(medicalCertAccordion, 'medicalCertificatesContainer');
            protectAccordionFromCollapse(referralAccordion, 'referralsContainer');
            protectAccordionFromCollapse(prescriptionAccordion, 'consultPrescriptionRows');
        });
        
        // Monitor for style changes that might disable scrolling
        if (typeof MutationObserver !== 'undefined') {
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
                        ensureScrollingEnabled();
                    }
                });
            });
            
            // Start observing after DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    if (document.body) {
                        observer.observe(document.body, { attributes: true, attributeFilter: ['style'] });
                    }
                    if (document.documentElement) {
                        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['style'] });
                    }
                });
            } else {
                if (document.body) {
                    observer.observe(document.body, { attributes: true, attributeFilter: ['style'] });
                }
                if (document.documentElement) {
                    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['style'] });
                }
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
        
        // Close modal when clicking outside or pressing Escape
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

