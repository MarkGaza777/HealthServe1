<?php
// Start output buffering to prevent any output before PDF generation
ob_start();

// Suppress warnings/notices that might cause output
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', 0);

session_start();
require_once 'db.php';

// Check if user is logged in (patient or doctor can access)
if (empty($_SESSION['user'])) {
    ob_end_clean();
    http_response_code(403);
    die('Unauthorized access');
}

$referral_id = isset($_GET['referral_id']) ? (int)$_GET['referral_id'] : 0;
$user_id = $_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'];

if ($referral_id <= 0) {
    die('Invalid referral ID');
}

try {
    // Get referral details with patient and doctor information
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            CASE 
                WHEN pt.id IS NOT NULL THEN CONCAT(COALESCE(pt.first_name, ''), ' ', COALESCE(pt.middle_name, ''), ' ', COALESCE(pt.last_name, ''))
                WHEN u.id IS NOT NULL THEN CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, ''))
                ELSE 'Unknown Patient'
            END as patient_name,
            CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name,
            d.specialization as doctor_specialization,
            -- Patient profile data for registered patients
            pp.date_of_birth as patient_dob,
            COALESCE(pp.gender, pp.sex, '') as patient_gender,
            -- Patient data for dependents
            pt.dob as dependent_dob,
            pt.sex as dependent_sex,
            pt.address as dependent_address,
            -- Patient address from users table
            u.address as patient_address,
            -- Consultation data
            dc.diagnosis,
            dc.findings
        FROM referrals r
        LEFT JOIN users u ON r.patient_id = u.id
        LEFT JOIN patients pt ON r.patient_id = pt.id
        LEFT JOIN patient_profiles pp ON pp.patient_id = u.id
        LEFT JOIN doctors d ON r.doctor_id = d.id
        LEFT JOIN users du ON d.user_id = du.id
        LEFT JOIN doctor_consultations dc ON dc.id = r.consultation_id
        WHERE r.id = ?
    ");
    $stmt->execute([$referral_id]);
    $referral = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$referral) {
        die('Referral not found.');
    }
    
    // Check access permissions
    if ($user_role === 'patient') {
        // Patient can only access their own referrals
        $access_check = $pdo->prepare("
            SELECT 1 FROM referrals r
            WHERE r.id = ? 
            AND (
                r.patient_id = ?
                OR r.patient_id IN (SELECT id FROM patients WHERE created_by_user_id = ?)
            )
            LIMIT 1
        ");
        $access_check->execute([$referral_id, $user_id, $user_id]);
        
        if (!$access_check->fetch()) {
            die('You do not have access to this referral.');
        }
    } elseif ($user_role === 'doctor') {
        // Doctor can access referrals they issued
        $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $doctor_record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$doctor_record || $referral['doctor_id'] != $doctor_record['id']) {
            die('You do not have access to this referral.');
        }
    } else {
        die('Unauthorized access');
    }
    
    // Calculate patient age
    $patient_age = 'N/A';
    $dob = $referral['patient_dob'] ?? $referral['dependent_dob'] ?? null;
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
    $patient_gender = $referral['patient_gender'] ?? $referral['dependent_sex'] ?? '';
    if ($patient_gender) {
        $patient_gender = strtolower($patient_gender);
    } else {
        $patient_gender = 'male/female';
    }
    
    // Get patient address
    $patient_address = trim($referral['patient_address'] ?? $referral['dependent_address'] ?? '');
    if (empty($patient_address)) {
        $patient_address = 'N/A';
    }
    
    // Format patient name in uppercase
    $patient_name_upper = strtoupper(trim($referral['patient_name']));
    $address_upper = strtoupper($patient_address);
    
    // Get diagnosis
    $diagnosis = trim($referral['diagnosis'] ?? '');
    if (empty($diagnosis)) {
        $diagnosis = 'N/A';
    }
    
    // Get reason for referral - this will be used in the body text
    $reason_for_referral = trim($referral['reason_for_referral'] ?? '');
    if (empty($reason_for_referral)) {
        $reason_for_referral = 'further medical evaluation and management';
    }
    
    // Get clinical notes - this might contain additional instructions
    $clinical_notes = trim($referral['clinical_notes'] ?? '');
    
    // Format dates
    $referral_date_obj = new DateTime($referral['referral_date']);
    $referral_date_formatted = strtoupper($referral_date_obj->format('F j, Y')); // "JANUARY 19, 2026"
    
    // Get doctor license number (check if field exists in doctors table)
    $doctor_license_no = '045792'; // Default placeholder
    try {
        $license_stmt = $pdo->prepare("SHOW COLUMNS FROM doctors LIKE 'license_no'");
        $license_stmt->execute();
        if ($license_stmt->fetch()) {
            $license_query = $pdo->prepare("SELECT license_no FROM doctors WHERE id = ?");
            $license_query->execute([$referral['doctor_id']]);
            $license_result = $license_query->fetch(PDO::FETCH_ASSOC);
            if ($license_result && !empty($license_result['license_no'])) {
                $doctor_license_no = $license_result['license_no'];
            }
        }
    } catch (Exception $e) {
        // Use default if field doesn't exist
    }
    
    // Format doctor name
    $doctor_name_formatted = strtoupper(trim($referral['doctor_name']));
    
    // Get doctor specialization/qualification
    $doctor_qualification = trim($referral['doctor_specialization'] ?? '');
    if (empty($doctor_qualification)) {
        $doctor_qualification = 'MEDICAL OFFICER';
    }
    
    // Load TCPDF library
    require_once __DIR__ . '/vendor/autoload.php';
    
    // Check if TCPDF is available
    if (!class_exists('TCPDF')) {
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
    $pdf->SetTitle('Referral Letter - ' . trim($referral['patient_name']));
    $pdf->SetSubject('Medical Referral Letter');
    
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
    
    // ========== HEADER SECTION ==========
    $start_y = 20;
    $pdf->SetY($start_y);
    
    // Center-aligned header text
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Republic of the Philippines', 0, 1, 'C');
    
    $pdf->SetY($pdf->GetY() + 2);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 6, 'BARANGAY PAYATAS B HEALTH CENTER', 0, 1, 'C');
    
    $pdf->SetY($pdf->GetY() + 2);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Quezon City', 0, 1, 'C');
    
    // Separator line
    $pdf->SetY($pdf->GetY() + 10);
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    
    // ========== TITLE SECTION ==========
    $pdf->SetY($pdf->GetY() + 12);
    
    // Date - right aligned (placed above title, no underline)
    $pdf->SetFont('helvetica', '', 11);
    $date_text = 'DATE: ' . $referral_date_formatted;
    $date_width = $pdf->GetStringWidth($date_text);
    $date_y = $pdf->GetY();
    $pdf->SetX(190 - $date_width - 5);
    $pdf->Cell($date_width, 6, $date_text, 0, 0, 'R');
    
    // Title - centered, bold (placed below date)
    $pdf->SetY($date_y + 10);
    $pdf->SetFont('helvetica', 'B', 16);
    $title_y = $pdf->GetY();
    $pdf->Cell(0, 8, 'REFERRAL LETTER', 0, 1, 'C');
    
    // ========== REFERRAL BODY ==========
    $pdf->SetY($title_y + 10);
    $pdf->SetFont('helvetica', '', 11);
    
    // Salutation
    $pdf->Cell(0, 7, 'To Whom It May Concern:', 0, 1, 'L');
    
    $pdf->SetY($pdf->GetY() + 8);
    
    // Build the referral text with proper formatting
    $start_x = 20;
    $max_width = 170; // Maximum width available (190 - 20 margin)
    $current_x = $start_x;
    $current_y = $pdf->GetY();
    $line_height = 7;
    $underline_offset = 5.5;
    $space_between_lines = 4;
    
    // Write "This is to refer "
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetXY($start_x, $current_y);
    $intro_text = "This is to refer ";
    $intro_width = $pdf->GetStringWidth($intro_text);
    $pdf->Cell($intro_width, $line_height, $intro_text, 0, 0, 'L');
    $current_x = $start_x + $intro_width;
    
    // Patient name - underlined, bold
    $pdf->SetFont('helvetica', 'B', 11);
    $name_width = $pdf->GetStringWidth($patient_name_upper);
    // Check if name fits on current line
    if ($current_x + $name_width > $start_x + $max_width) {
        $current_y += $line_height + $space_between_lines;
        $current_x = $start_x;
    }
    $pdf->SetXY($current_x, $current_y);
    $pdf->Cell($name_width, $line_height, $patient_name_upper, 0, 0, 'L');
    $pdf->SetLineWidth(0.3);
    $pdf->Line($current_x, $current_y + $underline_offset, $current_x + $name_width, $current_y + $underline_offset);
    $current_x += $name_width;
    
    // " of "
    $of_text = " of ";
    $of_width = $pdf->GetStringWidth($of_text);
    // Check if "of" fits on current line
    if ($current_x + $of_width > $start_x + $max_width) {
        $current_y += $line_height + $space_between_lines;
        $current_x = $start_x;
    }
    $pdf->SetXY($current_x, $current_y);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell($of_width, $line_height, $of_text, 0, 0, 'L');
    $current_x += $of_width;
    
    // Address - underlined, bold (handle long addresses)
    $pdf->SetFont('helvetica', 'B', 11);
    $address_width = $pdf->GetStringWidth($address_upper);
    // If address is too long, wrap to next line
    if ($current_x + $address_width > $start_x + $max_width) {
        $current_y += $line_height + $space_between_lines;
        $current_x = $start_x;
    }
    $pdf->SetXY($current_x, $current_y);
    // Limit address width to prevent overflow
    $max_address_width = $max_width - ($current_x - $start_x);
    if ($address_width > $max_address_width) {
        // Truncate address if too long (keep it reasonable)
        $max_chars = floor($max_address_width / 5); // Approximate chars per mm
        if (strlen($address_upper) > $max_chars) {
            $address_upper = substr($address_upper, 0, $max_chars);
            $address_width = $pdf->GetStringWidth($address_upper);
        }
    }
    $pdf->Cell($address_width, $line_height, $address_upper, 0, 0, 'L');
    $pdf->SetLineWidth(0.3);
    $pdf->Line($current_x, $current_y + $underline_offset, $current_x + $address_width, $current_y + $underline_offset);
    
    // New line for continuation
    $current_y += $line_height + $space_between_lines;
    $current_x = $start_x;
    
    // Reason for referral text - handle wrapping
    $pdf->SetFont('helvetica', '', 11);
    $reason_prefix = "for " . $reason_for_referral;
    if (!empty($referral['referred_hospital'])) {
        $reason_prefix .= " at " . strtoupper(trim($referral['referred_hospital']));
    }
    $reason_prefix .= ".";
    
    // Check if text fits on one line, if not, wrap it
    $reason_width = $pdf->GetStringWidth($reason_prefix);
    if ($reason_width > $max_width) {
        // Split the text into multiple lines
        $words = explode(' ', $reason_prefix);
        $current_line = '';
        
        foreach ($words as $word) {
            $test_line = $current_line . ($current_line ? ' ' : '') . $word;
            $test_width = $pdf->GetStringWidth($test_line);
            
            if ($test_width > $max_width && $current_line) {
                // Output current line and start new line
                $pdf->SetXY($start_x, $current_y);
                $pdf->Cell(0, $line_height, $current_line, 0, 1, 'L');
                $current_y += $line_height + 2;
                $current_line = $word;
            } else {
                $current_line = $test_line;
            }
        }
        
        // Output the last line
        if ($current_line) {
            $pdf->SetXY($start_x, $current_y);
            $pdf->Cell(0, $line_height, $current_line, 0, 1, 'L');
            $current_y += $line_height + 4;
        }
    } else {
        // Text fits on one line
        $pdf->SetXY($start_x, $current_y);
        $pdf->Cell(0, $line_height, $reason_prefix, 0, 1, 'L');
        $current_y += $line_height + 4;
    }
    
    // Purpose paragraph - handle wrapping
    $pdf->SetY($current_y + 4);
    $pdf->SetFont('helvetica', '', 11);
    $purpose_text = "This certification is being issued at the request of " . $patient_name_upper . " for whatever purpose it may serve except medicolegal.";
    
    // Check if text fits on one line, if not, wrap it
    $purpose_width = $pdf->GetStringWidth($purpose_text);
    if ($purpose_width > $max_width) {
        // Split the text into multiple lines
        $words = explode(' ', $purpose_text);
        $current_line = '';
        $line_y = $pdf->GetY();
        
        foreach ($words as $word) {
            $test_line = $current_line . ($current_line ? ' ' : '') . $word;
            $test_width = $pdf->GetStringWidth($test_line);
            
            if ($test_width > $max_width && $current_line) {
                // Output current line and start new line
                $pdf->SetXY($start_x, $line_y);
                $pdf->Cell(0, $line_height, $current_line, 0, 1, 'L');
                $line_y += $line_height + 2;
                $current_line = $word;
            } else {
                $current_line = $test_line;
            }
        }
        
        // Output the last line
        if ($current_line) {
            $pdf->SetXY($start_x, $line_y);
            $pdf->Cell(0, $line_height, $current_line, 0, 1, 'L');
        }
        
        // Update Y position for next section
        $pdf->SetY($line_y + $line_height + 8);
    } else {
        // Text fits on one line
        $pdf->Cell(0, $line_height, $purpose_text, 0, 1, 'L');
        $pdf->SetY($pdf->GetY() + 8);
    }
    
    // ========== SIGNATURE SECTION ==========
    $pdf->SetY($pdf->GetY() + 15);
    
    // Doctor name - right aligned, bold, underlined
    $pdf->SetFont('helvetica', 'B', 11);
    $doctor_name_with_title = $doctor_name_formatted . ", " . strtoupper($doctor_qualification);
    $doctor_name_width = $pdf->GetStringWidth($doctor_name_with_title);
    $pdf->SetX(190 - $doctor_name_width - 5);
    $doctor_y = $pdf->GetY();
    $pdf->Cell($doctor_name_width, 7, $doctor_name_with_title, 0, 1, 'R');
    // Underline doctor name
    $pdf->SetY($doctor_y + 6);
    $pdf->Line(190 - $doctor_name_width - 5, $pdf->GetY(), 190, $pdf->GetY());
    
    // License number - right aligned, bold
    $pdf->SetY($doctor_y + 10);
    $pdf->SetFont('helvetica', 'B', 10);
    $license_text = "LICENSE NO. " . $doctor_license_no;
    $license_width = $pdf->GetStringWidth($license_text);
    $pdf->SetX(190 - $license_width - 5);
    $pdf->Cell($license_width, 6, $license_text, 0, 1, 'R');
    
    // Medical Officer title - right aligned
    $pdf->SetY($pdf->GetY() + 2);
    $pdf->SetFont('helvetica', '', 10);
    $officer_text = "MEDICAL OFFICER III";
    $officer_width = $pdf->GetStringWidth($officer_text);
    $pdf->SetX(190 - $officer_width - 5);
    $pdf->Cell($officer_width, 6, $officer_text, 0, 1, 'R');
    
    // Health Center name - right aligned
    $pdf->SetY($pdf->GetY() + 2);
    $pdf->SetFont('helvetica', '', 9);
    $center_text = "BARANGAY PAYATAS B HEALTH CENTER";
    $center_width = $pdf->GetStringWidth($center_text);
    $pdf->SetX(190 - $center_width - 5);
    $pdf->Cell($center_width, 5, $center_text, 0, 1, 'R');
    
    // Clear any output buffer before sending PDF
    ob_end_clean();
    
    // Output PDF
    $filename = 'Referral_Letter_' . date('Y-m-d') . '_' . preg_replace('/[^A-Za-z0-9]/', '_', trim($referral['patient_name'])) . '.pdf';
    // Support both inline viewing ('I') and download ('D')
    $mode = isset($_GET['mode']) && $_GET['mode'] === 'view' ? 'I' : 'D';
    $pdf->Output($filename, $mode); // 'D' = download, 'I' = inline

} catch (Exception $e) {
    ob_end_clean();
    die('Error generating PDF: ' . htmlspecialchars($e->getMessage()));
}

