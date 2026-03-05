<?php
// Start output buffering to prevent any output before PDF generation
ob_start();

// Suppress warnings/notices that might cause output
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', 0);

session_start();
require_once 'db.php';
require_once 'residency_verification_helper.php';

// Check if user is logged in as patient
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'patient') {
    ob_end_clean();
    http_response_code(403);
    die('Unauthorized access');
}

$user_id = $_SESSION['user']['id'];
if (!isPatientResidencyVerified($user_id)) {
    ob_end_clean();
    http_response_code(403);
    die('Only verified residents of Barangay Payatas can access prescriptions. Please complete your residency verification.');
}

$appointment_id = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;

if ($appointment_id <= 0) {
    die('Invalid appointment ID');
}

try {
    // Get appointment details with patient and doctor information
    // Handle both registered patients and dependents
    // Also get patient profile data (age, gender, weight) and diagnosis
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
            -- Patient profile data for registered patients (from patient_profiles)
            pp.date_of_birth as patient_dob,
            COALESCE(pp.gender, pp.sex, '') as patient_gender,
            pp.weight_kg as patient_weight_kg,
            -- Patient data for dependents (from patients table)
            pt.dob as dependent_dob,
            pt.sex as dependent_sex,
            pt.address as dependent_address,
            -- Patient address from users table
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
    
    if (!$appointment) {
        die('Appointment not found or you do not have access to this record.');
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
    
    // Get diagnosis
    $diagnosis = trim($appointment['diagnosis'] ?? '');
    if (empty($diagnosis)) {
        $diagnosis = 'N/A';
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
    
    // Get prescription for this appointment
    $stmt = $pdo->prepare("
        SELECT * FROM prescriptions 
        WHERE appointment_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$appointment_id]);
    $prescription = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$prescription) {
        die('No prescription found for this appointment.');
    }
    
    // Check if prescription has expiration date and if it's expired
    $is_expired = false;
    $expiration_date = null;
    $validity_period_days = null;
    
    // Check if expiration_date column exists
    $has_expiration = false;
    try {
        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescriptions LIKE 'expiration_date'");
        $has_expiration = $test_stmt->rowCount() > 0;
    } catch (PDOException $e) {}
    
    if ($has_expiration && !empty($prescription['expiration_date'])) {
        $expiration_date = $prescription['expiration_date'];
        $today = date('Y-m-d');
        $is_expired = strtotime($expiration_date) < strtotime($today);
        $validity_period_days = $prescription['validity_period_days'] ?? null;
        
        // Prevent downloading expired prescriptions
        if ($is_expired) {
            die('This prescription has expired on ' . date('F j, Y', strtotime($expiration_date)) . '. Please contact your doctor for a new prescription.');
        }
    }
    
    // Check if prescription_items table has quantity, total_quantity, medicine_form columns
    $has_quantity = false;
    $has_total_quantity = false;
    $has_medicine_form = false;
    try {
        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'quantity'");
        $has_quantity = $test_stmt->rowCount() > 0;
        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'total_quantity'");
        $has_total_quantity = $test_stmt->rowCount() > 0;
        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'medicine_form'");
        $has_medicine_form = $test_stmt->rowCount() > 0;
    } catch (PDOException $e) {}
    
    // Get prescription items (medications); include medicine_form when column exists
    $med_cols = $has_medicine_form ? 'medicine_name, medicine_form' : 'medicine_name';
    if ($has_quantity && $has_total_quantity) {
        $stmt = $pdo->prepare("
            SELECT {$med_cols}, dosage, frequency, duration, quantity, total_quantity 
            FROM prescription_items 
            WHERE prescription_id = ?
            ORDER BY id
        ");
    } elseif ($has_quantity) {
        $stmt = $pdo->prepare("
            SELECT {$med_cols}, dosage, frequency, duration, quantity 
            FROM prescription_items 
            WHERE prescription_id = ?
            ORDER BY id
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT {$med_cols}, dosage, frequency, duration 
            FROM prescription_items 
            WHERE prescription_id = ?
            ORDER BY id
        ");
    }
    $stmt->execute([$prescription['id']]);
    $medications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no medications in prescription_items, check medications table
    if (empty($medications)) {
        $stmt = $pdo->prepare("
            SELECT drug_name as medicine_name, dosage, frequency, duration 
            FROM medications 
            WHERE prescription_id = ?
            ORDER BY medication_id
        ");
        $stmt->execute([$prescription['id']]);
        $medications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if (empty($medications)) {
        die('No medications found in this prescription.');
    }
    
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
    $pdf->SetTitle('Prescription - ' . trim($appointment['patient_name']));
    $pdf->SetSubject('Medical Prescription');
    
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
    
    // ========== PRESCRIPTION SECTION ==========
    $pdf->SetY($pdf->GetY() + 2);
    
    // Rx symbol on left margin
    $pdf->SetFont('helvetica', 'B', 24);
    $pdf->SetX(20);
    $pdf->Cell(15, 10, 'Rx', 0, 0, 'L');
    
    // "PRESCRIPTION" heading - centered
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetX(40);
    $pdf->Cell(130, 10, 'PRESCRIPTION', 0, 1, 'C');
    
    $pdf->SetY($pdf->GetY() + 8);
    
    // Medications list
    $pdf->SetFont('helvetica', '', 10);
    foreach ($medications as $med) {
        $medicine_name = htmlspecialchars($med['medicine_name'] ?? '');
        $medicine_form = isset($med['medicine_form']) && trim($med['medicine_form']) !== '' ? htmlspecialchars(trim($med['medicine_form'])) : '';
        $dosage = htmlspecialchars($med['dosage'] ?? 'N/A');
        $frequency = htmlspecialchars($med['frequency'] ?? 'N/A');
        $duration = htmlspecialchars($med['duration'] ?? 'N/A');
        $quantity = isset($med['quantity']) ? (int)$med['quantity'] : 1;
        $total_quantity = isset($med['total_quantity']) ? (int)$med['total_quantity'] : 0;
        
        // Medicine name with form (e.g. "Paracetamol (Tablet)") for clarity
        $medicine_display = $medicine_name;
        if ($medicine_form !== '') {
            $medicine_display .= ' (' . $medicine_form . ')';
        }
        
        // Medicine name with bullet point
        $pdf->SetX(40);
        $med_line = '• ' . $medicine_display;
        if ($dosage !== 'N/A') {
            $med_line .= ' - ' . $dosage;
        }
        if ($frequency !== 'N/A') {
            $med_line .= ' (' . $frequency . ')';
        }
        if ($duration !== 'N/A') {
            $med_line .= ' - ' . $duration;
        }
        if ($has_total_quantity && $total_quantity > 0) {
            $med_line .= ' [Qty: ' . $total_quantity . ']';
        } elseif ($has_quantity) {
            $med_line .= ' [Qty: ' . $quantity . ']';
        }
        
        // Check if line fits, wrap if needed
        $max_width = 150;
        $line_width = $pdf->GetStringWidth($med_line);
        if ($line_width > $max_width) {
            // Split into multiple lines
            $words = explode(' ', $med_line);
            $current_line = '';
            $line_y = $pdf->GetY();
            
            foreach ($words as $word) {
                $test_line = $current_line . ($current_line ? ' ' : '') . $word;
                $test_width = $pdf->GetStringWidth($test_line);
                
                if ($test_width > $max_width && $current_line) {
                    // Output current line and start new line
                    $pdf->SetXY(40, $line_y);
                    $pdf->Cell(0, 6, $current_line, 0, 1, 'L');
                    $line_y += 6;
                    $current_line = $word;
                } else {
                    $current_line = $test_line;
                }
            }
            
            // Output the last line
            if ($current_line) {
                $pdf->SetXY(40, $line_y);
                $pdf->Cell(0, 6, $current_line, 0, 1, 'L');
            }
            $pdf->SetY($line_y + 4);
        } else {
            $pdf->Cell(0, 6, $med_line, 0, 1, 'L');
            $pdf->SetY($pdf->GetY() + 2);
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
    $filename = 'Prescription_' . date('Y-m-d') . '_' . preg_replace('/[^A-Za-z0-9]/', '_', trim($appointment['patient_name'])) . '.pdf';
    // Support both inline viewing ('I') and download ('D')
    $mode = isset($_GET['mode']) && $_GET['mode'] === 'view' ? 'I' : 'D';
    $pdf->Output($filename, $mode); // 'D' = download, 'I' = inline

} catch (Exception $e) {
    ob_end_clean();
    die('Error generating PDF: ' . htmlspecialchars($e->getMessage()));
}

