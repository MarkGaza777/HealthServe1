<?php
// Start output buffering to prevent any output before PDF generation
ob_start();

// Suppress warnings/notices that might cause output
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', 0);

session_start();
require_once 'db.php';
require_once 'residency_verification_helper.php';

$role = $_SESSION['user']['role'] ?? '';
if (empty($_SESSION['user']) || !in_array($role, ['patient', 'doctor'], true)) {
    ob_end_clean();
    http_response_code(403);
    die('Unauthorized access');
}

$user_id = (int)$_SESSION['user']['id'];
if ($role === 'patient' && !isPatientResidencyVerified($user_id)) {
    ob_end_clean();
    http_response_code(403);
    die('Only verified residents of Barangay Payatas can access lab test requests. Please complete your residency verification.');
}

$appointment_id = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;

if ($appointment_id <= 0) {
    die('Invalid appointment ID');
}

try {
    $appointment = null;
    if ($role === 'patient') {
        // Patient: must own the appointment or be guardian of the dependent
        $stmt = $pdo->prepare("
            SELECT 
                a.*,
                a.start_datetime as consultation_date,
                CASE 
                    WHEN pt.id IS NOT NULL THEN CONCAT(COALESCE(pt.first_name, ''), ' ', COALESCE(pt.middle_name, ''), ' ', COALESCE(pt.last_name, ''))
                    WHEN u.id IS NOT NULL THEN CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, ''))
                    ELSE 'Unknown Patient'
                END as patient_name,
                CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name,
                d.specialization as doctor_specialization,
                COALESCE(dc.diagnosis, a.diagnosis, '') as diagnosis,
                pp.date_of_birth as patient_dob,
                COALESCE(pp.gender, pp.sex, '') as patient_gender,
                pt.dob as dependent_dob,
                pt.sex as dependent_sex,
                pt.address as dependent_address,
                u.address as patient_address
            FROM appointments a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN patients pt ON a.patient_id = pt.id
            LEFT JOIN patient_profiles pp ON pp.patient_id = u.id
            LEFT JOIN doctors d ON a.doctor_id = d.id
            LEFT JOIN users du ON d.user_id = du.id
            LEFT JOIN doctor_consultations dc ON dc.appointment_id = a.id
            WHERE a.id = ? 
            AND (a.user_id = ? OR a.patient_id IN (SELECT id FROM patients WHERE created_by_user_id = ?))
            AND a.status IN ('approved', 'completed')
        ");
        $stmt->execute([$appointment_id, $user_id, $user_id]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Doctor: must be the assigned doctor for this appointment
        $stmt_doc = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ? LIMIT 1");
        $stmt_doc->execute([$user_id]);
        $doctor_row = $stmt_doc->fetch(PDO::FETCH_ASSOC);
        if (!$doctor_row) {
            die('Doctor profile not found.');
        }
        $doctor_id = (int)$doctor_row['id'];
        $stmt = $pdo->prepare("
            SELECT 
                a.*,
                a.start_datetime as consultation_date,
                CASE 
                    WHEN pt.id IS NOT NULL THEN CONCAT(COALESCE(pt.first_name, ''), ' ', COALESCE(pt.middle_name, ''), ' ', COALESCE(pt.last_name, ''))
                    WHEN u.id IS NOT NULL THEN CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, ''))
                    ELSE 'Unknown Patient'
                END as patient_name,
                CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name,
                d.specialization as doctor_specialization,
                COALESCE(dc.diagnosis, a.diagnosis, '') as diagnosis,
                pp.date_of_birth as patient_dob,
                COALESCE(pp.gender, pp.sex, '') as patient_gender,
                pt.dob as dependent_dob,
                pt.sex as dependent_sex,
                pt.address as dependent_address,
                u.address as patient_address
            FROM appointments a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN patients pt ON a.patient_id = pt.id
            LEFT JOIN patient_profiles pp ON pp.patient_id = u.id
            LEFT JOIN doctors d ON a.doctor_id = d.id
            LEFT JOIN users du ON d.user_id = du.id
            LEFT JOIN doctor_consultations dc ON dc.appointment_id = a.id
            WHERE a.id = ? AND a.doctor_id = ? AND a.status IN ('approved', 'completed')
        ");
        $stmt->execute([$appointment_id, $doctor_id]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$appointment) {
        die('Appointment not found or you do not have access to this record.');
    }
    
    // Get lab requests (multi-test per request) for this appointment
    $lab_test_requests = [];
    $has_lab_requests = $pdo->query("SHOW TABLES LIKE 'lab_requests'");
    if ($has_lab_requests->rowCount() > 0) {
        $stmt = $pdo->prepare("
            SELECT lr.*,
                   CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
            FROM lab_requests lr
            LEFT JOIN doctors d ON d.id = lr.doctor_id
            LEFT JOIN users du ON du.id = d.user_id
            WHERE lr.appointment_id = ?
            ORDER BY lr.created_at ASC
        ");
        $stmt->execute([$appointment_id]);
        $lab_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($lab_requests)) {
            $lr_ids = array_column($lab_requests, 'id');
            $placeholders = implode(',', array_fill(0, count($lr_ids), '?'));
            $stmt = $pdo->prepare("SELECT lab_request_id, test_name FROM lab_request_tests WHERE lab_request_id IN ($placeholders) ORDER BY lab_request_id, id");
            $stmt->execute($lr_ids);
            $tests_by_lr = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $tests_by_lr[$row['lab_request_id']][] = $row['test_name'];
            }
            foreach ($lab_requests as $lr) {
                $tests = $tests_by_lr[$lr['id']] ?? [];
                foreach ($tests as $test_name) {
                    $lab_test_requests[] = [
                        'test_name' => $test_name,
                        'notes' => $lr['notes'] ?? null,
                        'laboratory_name' => $lr['laboratory_name'] ?? null,
                        'doctor_name' => $lr['doctor_name'] ?? null,
                    ];
                }
            }
        }
    }
    if (empty($lab_test_requests)) {
        $stmt = $pdo->prepare("
            SELECT ltr.*,
                   CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
            FROM lab_test_requests ltr
            LEFT JOIN doctors d ON d.id = ltr.doctor_id
            LEFT JOIN users du ON du.id = d.user_id
            WHERE ltr.appointment_id = ?
            ORDER BY ltr.created_at ASC
        ");
        $stmt->execute([$appointment_id]);
        $lab_test_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if (empty($lab_test_requests)) {
        die('No lab test requests found for this appointment.');
    }
    
    // Calculate patient age
    $patient_age = 'N/A';
    $dob = $appointment['patient_dob'] ?? $appointment['dependent_dob'] ?? null;
    if ($dob) {
        try {
            $birthDate = new DateTime($dob);
            $today = new DateTime();
            $patient_age = $today->diff($birthDate)->y;
        } catch (Exception $e) {
            $patient_age = 'N/A';
        }
    }
    
    // Get patient gender
    $patient_gender = $appointment['patient_gender'] ?? $appointment['dependent_sex'] ?? '';
    if ($patient_gender) {
        $patient_gender = strtoupper(substr($patient_gender, 0, 1)); // F or M
    } else {
        $patient_gender = 'N/A';
    }
    
    // Get patient address
    $patient_address = trim($appointment['patient_address'] ?? $appointment['dependent_address'] ?? '');
    if (empty($patient_address)) {
        $patient_address = 'N/A';
    }
    
    // Get doctor specialization/qualification
    $doctor_qualification = trim($appointment['doctor_specialization'] ?? '');
    if (empty($doctor_qualification)) {
        $doctor_qualification = 'GENERAL PRACTITIONER';
    }
    
    // Get doctor license number
    $doctor_license_no = '045792'; // Default placeholder
    try {
        $license_stmt = $pdo->prepare("SHOW COLUMNS FROM doctors LIKE 'license_no'");
        $license_stmt->execute();
        if ($license_stmt->fetch()) {
            $license_query = $pdo->prepare("SELECT license_no FROM doctors WHERE id = ?");
            $license_query->execute([$appointment['doctor_id']]);
            $license_result = $license_query->fetch(PDO::FETCH_ASSOC);
            if ($license_result && !empty($license_result['license_no'])) {
                $doctor_license_no = $license_result['license_no'];
            }
        }
    } catch (Exception $e) {
        // Use default if field doesn't exist
    }
    
    // Format date
    $consultation_date = new DateTime($appointment['consultation_date']);
    $date_formatted = $consultation_date->format('d F Y'); // "19 October 2025"
    
    // Generate security code
    $security_code = strtoupper(substr(md5($appointment_id . $user_id . time()), 0, 5) . '-' . 
                                substr(md5($appointment_id . $user_id . time() . '1'), 0, 5) . '-' .
                                substr(md5($appointment_id . $user_id . time() . '2'), 0, 5) . '-' .
                                substr(md5($appointment_id . $user_id . time() . '3'), 0, 5) . '-' .
                                substr(md5($appointment_id . $user_id . time() . '4'), 0, 5) . '-' .
                                substr(md5($appointment_id . $user_id . time() . '5'), 0, 5));
    
    // Load TCPDF library
    require_once __DIR__ . '/vendor/autoload.php';
    
    // Check if TCPDF is available
    if (!class_exists('TCPDF')) {
        // Try to load TCPDF directly
        $tcpdf_path = __DIR__ . '/vendor/tecnickcom/tcpdf/tcpdf.php';
        if (file_exists($tcpdf_path)) {
            require_once $tcpdf_path;
        } else {
            die('TCPDF library is not installed. Please run: composer require tecnickcom/tcpdf');
        }
    }
    
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('Barangay Payatas B Health Center');
    $pdf->SetAuthor('Barangay Payatas B Health Center');
    $pdf->SetTitle('Lab Test Request - ' . trim($appointment['patient_name']));
    $pdf->SetSubject('Laboratory Test Request');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(20, 20, 20);
    $pdf->SetAutoPageBreak(TRUE, 20);
    
    // Add a page
    $pdf->AddPage();
    
    // Set text color to black
    $pdf->SetTextColor(0, 0, 0);
    
    // ========== TOP SECTION ==========
    $start_y = 20;
    $pdf->SetY($start_y);
    
    // Health Center name - centered
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'BARANGAY PAYATAS B HEALTH CENTER', 0, 1, 'C');
    
    $pdf->SetY($pdf->GetY() + 3);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Bulacan St., Brgy. Payatas, Quezon City', 0, 1, 'C');
    
    // Separator line
    $pdf->SetY($pdf->GetY() + 8);
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    
    // Patient information below separator line
    $pdf->SetY($pdf->GetY() + 8);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetX(20);
    $pdf->Cell(0, 6, 'Patient\'s Name: ' . trim($appointment['patient_name']), 0, 1, 'L');
    
    $pdf->SetY($pdf->GetY() + 2);
    $pdf->SetX(20);
    $pdf->Cell(0, 6, 'Patient\'s Address: ' . $patient_address, 0, 1, 'L');
    
    // Right side - Date, Age, Sex (below separator line)
    $pdf->SetY($pdf->GetY() - 12);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetX(140);
    $pdf->Cell(0, 6, 'Date: ' . $date_formatted, 0, 1, 'R');
    
    $pdf->SetY($pdf->GetY() + 2);
    $pdf->SetX(140);
    $pdf->Cell(0, 6, 'Age: ' . $patient_age . ' years', 0, 1, 'R');
    
    $pdf->SetY($pdf->GetY() + 2);
    $pdf->SetX(140);
    $pdf->Cell(0, 6, 'Sex: ' . $patient_gender, 0, 1, 'R');
    
    // Set Y position for next section (after patient info)
    $pdf->SetY($pdf->GetY() + 2);
    
    // ========== DIAGNOSTICS SECTION ==========
    $pdf->SetY($pdf->GetY() + 2);
    
    // Rx symbol on left margin
    $pdf->SetFont('helvetica', 'B', 24);
    $pdf->SetX(20);
    $pdf->Cell(15, 10, 'Rx', 0, 0, 'L');
    
    // "DIAGNOSTICS" heading - centered
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetX(40);
    $pdf->Cell(130, 10, 'DIAGNOSTICS', 0, 1, 'C');
    
    $pdf->SetY($pdf->GetY() + 8);
    
    // Organize lab tests by category (if we can determine category from test name)
    // For now, we'll group them into sections
    $imaging_tests = [];
    $laboratory_tests = [];
    $other_tests = [];
    
    foreach ($lab_test_requests as $ltr) {
        $test_name = strtolower(trim($ltr['test_name'] ?? ''));
        if (strpos($test_name, 'x-ray') !== false || strpos($test_name, 'xray') !== false || 
            strpos($test_name, 'ct') !== false || strpos($test_name, 'mri') !== false ||
            strpos($test_name, 'ultrasound') !== false || strpos($test_name, 'ecg') !== false ||
            strpos($test_name, 'echocardiography') !== false || strpos($test_name, 'treadmill') !== false) {
            $imaging_tests[] = $ltr;
        } elseif (strpos($test_name, 'cbc') !== false || strpos($test_name, 'blood') !== false ||
                   strpos($test_name, 'lipid') !== false || strpos($test_name, 'hba1c') !== false ||
                   strpos($test_name, 'laboratory') !== false || strpos($test_name, 'lab') !== false) {
            $laboratory_tests[] = $ltr;
        } else {
            $other_tests[] = $ltr;
        }
    }
    
    // If no categorization, put all in laboratory
    if (empty($imaging_tests) && empty($laboratory_tests) && empty($other_tests)) {
        $laboratory_tests = $lab_test_requests;
    }
    
    $current_y = $pdf->GetY();
    
    // Imaging / Radiology section
    if (!empty($imaging_tests)) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetX(40);
        $pdf->Cell(0, 7, 'Imaging / Radiology:', 0, 1, 'L');
        
        $pdf->SetY($pdf->GetY() + 3);
        $pdf->SetFont('helvetica', '', 10);
        foreach ($imaging_tests as $ltr) {
            $test_name = htmlspecialchars($ltr['test_name'] ?? '');
            $pdf->SetX(40);
            $pdf->Cell(0, 6, '• ' . $test_name, 0, 1, 'L');
        }
        $pdf->SetY($pdf->GetY() + 5);
    }
    
    // Heart Station section (if ECG or related tests)
    $heart_tests = array_filter($imaging_tests, function($ltr) {
        $test_name = strtolower($ltr['test_name'] ?? '');
        return strpos($test_name, 'ecg') !== false || strpos($test_name, 'echocardiography') !== false || 
               strpos($test_name, 'treadmill') !== false;
    });
    
    if (!empty($heart_tests)) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetX(40);
        $pdf->Cell(0, 7, 'Heart Station:', 0, 1, 'L');
        
        $pdf->SetY($pdf->GetY() + 3);
        $pdf->SetFont('helvetica', '', 10);
        foreach ($heart_tests as $ltr) {
            $test_name = htmlspecialchars($ltr['test_name'] ?? '');
            $pdf->SetX(40);
            $pdf->Cell(0, 6, '• ' . $test_name, 0, 1, 'L');
            // Add remarks if available
            if (!empty($ltr['notes'])) {
                $pdf->SetX(45);
                $pdf->SetFont('helvetica', '', 9);
                $pdf->Cell(0, 5, 'Remarks: ' . htmlspecialchars($ltr['notes']), 0, 1, 'L');
                $pdf->SetFont('helvetica', '', 10);
            }
        }
        $pdf->SetY($pdf->GetY() + 5);
    }
    
    // Laboratory section
    if (!empty($laboratory_tests)) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetX(40);
        $pdf->Cell(0, 7, 'Laboratory:', 0, 1, 'L');
        
        $pdf->SetY($pdf->GetY() + 3);
        $pdf->SetFont('helvetica', '', 10);
        foreach ($laboratory_tests as $ltr) {
            $test_name = htmlspecialchars($ltr['test_name'] ?? '');
            $pdf->SetX(40);
            $pdf->Cell(0, 6, '• ' . $test_name, 0, 1, 'L');
        }
        $pdf->SetY($pdf->GetY() + 5);
    }
    
    // Other tests
    if (!empty($other_tests)) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetX(40);
        $pdf->Cell(0, 7, 'Other Tests:', 0, 1, 'L');
        
        $pdf->SetY($pdf->GetY() + 3);
        $pdf->SetFont('helvetica', '', 10);
        foreach ($other_tests as $ltr) {
            $test_name = htmlspecialchars($ltr['test_name'] ?? '');
            $pdf->SetX(40);
            $pdf->Cell(0, 6, '• ' . $test_name, 0, 1, 'L');
        }
    }
    
    // ========== BOTTOM SECTION ==========
    $pdf->SetY($pdf->GetY() + 20);
    
    // Right side - Signature with doctor name
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetX(140);
    $doctor_name_display = trim($appointment['doctor_name']) . ', MD';
    $pdf->Cell(0, 6, $doctor_name_display, 0, 1, 'R');
    
    $pdf->SetY($pdf->GetY() + 2);
    $pdf->SetX(140);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 5, 'License No.: ' . $doctor_license_no, 0, 1, 'R');
    
    // Clear any output buffer before sending PDF
    ob_end_clean();
    
    // Output PDF
    $filename = 'Lab_Test_Request_' . date('Y-m-d') . '_' . preg_replace('/[^A-Za-z0-9]/', '_', trim($appointment['patient_name'])) . '.pdf';
    // Support both inline viewing ('I') and download ('D')
    $mode = isset($_GET['mode']) && $_GET['mode'] === 'view' ? 'I' : 'D';
    $pdf->Output($filename, $mode); // 'D' = download, 'I' = inline

} catch (Exception $e) {
    ob_end_clean();
    die('Error generating PDF: ' . htmlspecialchars($e->getMessage()));
}

