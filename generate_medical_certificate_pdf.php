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

$certificate_id = isset($_GET['certificate_id']) ? (int)$_GET['certificate_id'] : 0;
$user_id = $_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'];

if ($certificate_id <= 0) {
    die('Invalid certificate ID');
}

try {
    // Get certificate details with patient and doctor information
    $stmt = $pdo->prepare("
        SELECT 
            mc.*,
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
        FROM medical_certificates mc
        LEFT JOIN users u ON mc.patient_id = u.id
        LEFT JOIN patients pt ON mc.patient_id = pt.id
        LEFT JOIN patient_profiles pp ON pp.patient_id = u.id
        LEFT JOIN doctors d ON mc.doctor_id = d.id
        LEFT JOIN users du ON d.user_id = du.id
        LEFT JOIN doctor_consultations dc ON dc.id = mc.consultation_id
        WHERE mc.id = ?
    ");
    $stmt->execute([$certificate_id]);
    $certificate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$certificate) {
        die('Certificate not found.');
    }
    
    // Check access permissions
    if ($user_role === 'patient') {
        // Patient can only access their own certificates
        $access_check = $pdo->prepare("
            SELECT 1 FROM medical_certificates mc
            WHERE mc.id = ? 
            AND (
                mc.patient_id = ?
                OR mc.patient_id IN (SELECT id FROM patients WHERE created_by_user_id = ?)
            )
            LIMIT 1
        ");
        $access_check->execute([$certificate_id, $user_id, $user_id]);
        
        if (!$access_check->fetch()) {
            die('You do not have access to this certificate.');
        }
    } elseif ($user_role === 'doctor') {
        // Doctor can access certificates they issued
        $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $doctor_record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$doctor_record || $certificate['doctor_id'] != $doctor_record['id']) {
            die('You do not have access to this certificate.');
        }
    } else {
        die('Unauthorized access');
    }
    
    // Check if certificate is expired and update status if needed
    $today = date('Y-m-d');
    $is_expired = strtotime($certificate['expiration_date']) < strtotime($today);
    
    // Automatically update status if expired
    if ($is_expired && $certificate['status'] === 'active') {
        $update_stmt = $pdo->prepare("UPDATE medical_certificates SET status = 'expired' WHERE id = ?");
        $update_stmt->execute([$certificate_id]);
        $certificate['status'] = 'expired';
    }
    
    // Prevent patients from accessing expired certificates
    if ($is_expired && $user_role === 'patient') {
        die('This medical certificate has expired on ' . date('F j, Y', strtotime($certificate['expiration_date'])) . '. Please contact your doctor for a new certificate.');
    }
    
    // Calculate patient age
    $patient_age = 'N/A';
    $dob = $certificate['patient_dob'] ?? $certificate['dependent_dob'] ?? null;
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
    $patient_gender = $certificate['patient_gender'] ?? $certificate['dependent_sex'] ?? '';
    if ($patient_gender) {
        $patient_gender = strtolower($patient_gender);
    } else {
        $patient_gender = 'male/female';
    }
    
    // Get patient address
    $patient_address = trim($certificate['patient_address'] ?? $certificate['dependent_address'] ?? '');
    if (empty($patient_address)) {
        $patient_address = 'N/A';
    }
    
    // Get medical finding from consultation findings or diagnosis
    $medical_finding = trim($certificate['findings'] ?? '');
    if (empty($medical_finding)) {
        $medical_finding = trim($certificate['diagnosis'] ?? '');
    }
    if (empty($medical_finding)) {
        $medical_finding = 'PHYSICALLY FIT TO WORK';
    } else {
        $medical_finding = strtoupper($medical_finding);
    }
    
    // Get doctor license number (check if field exists in doctors table)
    $doctor_license_no = '045792'; // Default placeholder, can be updated if field exists
    try {
        $license_stmt = $pdo->prepare("SHOW COLUMNS FROM doctors LIKE 'license_no'");
        $license_stmt->execute();
        if ($license_stmt->fetch()) {
            $license_query = $pdo->prepare("SELECT license_no FROM doctors WHERE id = ?");
            $license_query->execute([$certificate['doctor_id']]);
            $license_result = $license_query->fetch(PDO::FETCH_ASSOC);
            if ($license_result && !empty($license_result['license_no'])) {
                $doctor_license_no = $license_result['license_no'];
            }
        }
    } catch (Exception $e) {
        // Use default if field doesn't exist
    }
    
    // Format patient name in uppercase
    $patient_name_upper = strtoupper(trim($certificate['patient_name']));
    
    // Format dates
    $issued_date = new DateTime($certificate['issued_date']);
    $issued_date_formatted = strtoupper($issued_date->format('F j, Y')); // Will show as "JANUARY 19, 2026" (no leading zero)
    $issued_day = (int)$issued_date->format('j'); // Day without leading zero
    $issued_month_year = strtoupper($issued_date->format('F, Y'));
    
    // Get day suffix (1st, 2nd, 3rd, 4th, etc.)
    $day_suffix = 'th';
    if ($issued_day == 1 || $issued_day == 21 || $issued_day == 31) {
        $day_suffix = 'st';
    } elseif ($issued_day == 2 || $issued_day == 22) {
        $day_suffix = 'nd';
    } elseif ($issued_day == 3 || $issued_day == 23) {
        $day_suffix = 'rd';
    }
    
    // Format doctor name with M.D suffix
    $doctor_name_formatted = strtoupper(trim($certificate['doctor_name'])) . '_M.D';
    
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
    $pdf->SetCreator('Carating Medical Clinic');
    $pdf->SetAuthor('Carating Medical Clinic');
    $pdf->SetTitle('Medical Certificate - ' . trim($certificate['patient_name']));
    $pdf->SetSubject('Medical Certificate');
    
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
    
    // Clinic name - centered, bold, uppercase
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 8, 'BARANGAY PAYATAS B HEALTH CENTER', 0, 1, 'C');
    
    // Telephone number
    $pdf->SetY($pdf->GetY() + 3);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 6, 'Tel No.: 09690394762', 0, 1, 'C');
    
    // Address
    $pdf->SetY($pdf->GetY() + 2);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 6, 'Bulacan St., Brgy. Payatas, Quezon City', 0, 1, 'C');
    
    // Separator line
    $pdf->SetY($pdf->GetY() + 8);
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    
    // ========== TITLE SECTION ==========
    $pdf->SetY($pdf->GetY() + 12);
    
    // Date - right aligned, underlined (placed before title)
    $pdf->SetFont('helvetica', '', 11);
    $date_text = 'DATE: ' . $issued_date_formatted;
    $date_width = $pdf->GetStringWidth($date_text);
    $date_y = $pdf->GetY();
    $pdf->SetX(190 - $date_width - 5);
    $pdf->Cell($date_width, 6, $date_text, 0, 0, 'R');
    // Underline the date
    $pdf->SetY($date_y + 5);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(190 - $date_width - 5, $pdf->GetY(), 190, $pdf->GetY());
    
    // Title - centered, bold, underlined (placed after date)
    $pdf->SetY($date_y + 10);
    $pdf->SetFont('helvetica', 'B', 16);
    $title_y = $pdf->GetY();
    $pdf->Cell(0, 8, 'MEDICAL CERTIFICATE', 0, 1, 'C');
    // Underline the title
    $pdf->SetY($title_y + 7);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(70, $pdf->GetY(), 140, $pdf->GetY());
    
    // ========== CERTIFICATE BODY ==========
    $pdf->SetY($title_y + 15);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, 'TO WHOM IT MAY CONCERN:', 0, 1, 'L');
    
    $pdf->SetY($pdf->GetY() + 8);
    $pdf->SetFont('helvetica', '', 11);
    
    // Build the certificate text with proper formatting
    $start_x = 20;
    $max_width = 170; // Maximum width available (190 - 20 margin)
    $current_x = $start_x;
    $current_y = $pdf->GetY();
    $line_height = 7;
    $underline_offset = 5.5;
    $space_between_lines = 4;
    
    // Write "This is to certify that "
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetXY($start_x, $current_y);
    $intro_text = "This is to certify that ";
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
    
    // Age " X y/o "
    $pdf->SetFont('helvetica', '', 11);
    $age_text = " " . $patient_age . " y/o ";
    $age_width = $pdf->GetStringWidth($age_text);
    // Check if age fits on current line
    if ($current_x + $age_width > $start_x + $max_width) {
        $current_y += $line_height + $space_between_lines;
        $current_x = $start_x;
    }
    $pdf->SetXY($current_x, $current_y);
    $pdf->Cell($age_width, $line_height, $age_text, 0, 0, 'L');
    $pdf->SetLineWidth(0.3);
    $pdf->Line($current_x, $current_y + $underline_offset, $current_x + $age_width, $current_y + $underline_offset);
    $current_x += $age_width;
    
    // Gender - underlined
    $gender_width = $pdf->GetStringWidth($patient_gender);
    // Check if gender fits on current line
    if ($current_x + $gender_width > $start_x + $max_width) {
        $current_y += $line_height + $space_between_lines;
        $current_x = $start_x;
    }
    $pdf->SetXY($current_x, $current_y);
    $pdf->Cell($gender_width, $line_height, $patient_gender, 0, 0, 'L');
    $pdf->SetLineWidth(0.3);
    $pdf->Line($current_x, $current_y + $underline_offset, $current_x + $gender_width, $current_y + $underline_offset);
    $current_x += $gender_width;
    
    // " of "
    $of_text = " of ";
    $of_width = $pdf->GetStringWidth($of_text);
    // Check if "of" fits on current line
    if ($current_x + $of_width > $start_x + $max_width) {
        $current_y += $line_height + $space_between_lines;
        $current_x = $start_x;
    }
    $pdf->SetXY($current_x, $current_y);
    $pdf->Cell($of_width, $line_height, $of_text, 0, 0, 'L');
    $current_x += $of_width;
    
    // Address - underlined, bold (handle long addresses)
    $address_upper = strtoupper($patient_address);
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
    
    // "came in for medical examination in hospital on "
    $pdf->SetFont('helvetica', '', 11);
    $exam_text = "came in for medical examination in hospital on ";
    $exam_width = $pdf->GetStringWidth($exam_text);
    $pdf->SetXY($start_x, $current_y);
    $pdf->Cell($exam_width, $line_height, $exam_text, 0, 0, 'L');
    $current_x = $start_x + $exam_width;
    
    // Examination date - underlined, bold (use issued_date_formatted)
    $pdf->SetFont('helvetica', 'B', 11);
    $exam_date_width = $pdf->GetStringWidth($issued_date_formatted);
    // Check if date fits on same line
    if ($current_x + $exam_date_width > $start_x + $max_width) {
        $current_y += $line_height + $space_between_lines;
        $current_x = $start_x;
    }
    $pdf->SetXY($current_x, $current_y);
    $pdf->Cell($exam_date_width, $line_height, $issued_date_formatted, 0, 0, 'L');
    $pdf->SetLineWidth(0.3);
    $pdf->Line($current_x, $current_y + $underline_offset, $current_x + $exam_date_width, $current_y + $underline_offset);
    
    // New line
    $current_y += $line_height + $space_between_lines;
    $current_x = $start_x;
    
    // "and the result revealed that he/she is "
    $pdf->SetFont('helvetica', '', 11);
    $result_text = "and the result revealed that he/she is ";
    $result_width = $pdf->GetStringWidth($result_text);
    $pdf->SetXY($start_x, $current_y);
    $pdf->Cell($result_width, $line_height, $result_text, 0, 0, 'L');
    $current_x = $start_x + $result_width;
    
    // Medical finding - underlined, bold
    $pdf->SetFont('helvetica', 'B', 11);
    $finding_width = $pdf->GetStringWidth($medical_finding);
    // Check if finding fits on same line
    if ($current_x + $finding_width > $start_x + $max_width) {
        $current_y += $line_height + $space_between_lines;
        $current_x = $start_x;
    }
    $pdf->SetXY($current_x, $current_y);
    $pdf->Cell($finding_width, $line_height, $medical_finding, 0, 0, 'L');
    $pdf->SetLineWidth(0.3);
    $pdf->Line($current_x, $current_y + $underline_offset, $current_x + $finding_width, $current_y + $underline_offset);
    
    // Set Y position for next section
    $pdf->SetY($current_y + $line_height + 8);
    
    // Purpose paragraph
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 6, "This certification is issued upon the request of the interested party for local employment purposes only.", 0, 1, 'L');
    
    // Issued date paragraph - handle long text with wrapping
    $pdf->SetY($pdf->GetY() + 8);
    $pdf->SetFont('helvetica', '', 11);
    $issued_text = "Issued this " . $issued_day . $day_suffix . " day of " . $issued_month_year . " at BARANGAY PAYATAS B HEALTH CENTER, Quezon City, Philippines.";
    
    // Check if text fits on one line, if not, wrap it
    $issued_text_width = $pdf->GetStringWidth($issued_text);
    $available_width = 170; // max_width
    
    if ($issued_text_width > $available_width) {
        // Split the text into multiple lines
        $words = explode(' ', $issued_text);
        $current_line = '';
        $line_y = $pdf->GetY();
        
        foreach ($words as $word) {
            $test_line = $current_line . ($current_line ? ' ' : '') . $word;
            $test_width = $pdf->GetStringWidth($test_line);
            
            if ($test_width > $available_width && $current_line) {
                // Output current line and start new line
                $pdf->SetXY(20, $line_y);
                $pdf->Cell(0, 6, $current_line, 0, 1, 'L');
                $line_y += 7;
                $current_line = $word;
            } else {
                $current_line = $test_line;
            }
        }
        
        // Output the last line
        if ($current_line) {
            $pdf->SetXY(20, $line_y);
            $pdf->Cell(0, 6, $current_line, 0, 1, 'L');
        }
        
        // Update Y position for next section
        $pdf->SetY($line_y + 7);
    } else {
        // Text fits on one line
        $pdf->Cell(0, 6, $issued_text, 0, 1, 'L');
    }
    
    // ========== SIGNATURE SECTION ==========
    $pdf->SetY($pdf->GetY() + 20);
    
    // Doctor name with M.D - right aligned, bold, underlined
    $pdf->SetFont('helvetica', 'B', 11);
    $doctor_name_width = $pdf->GetStringWidth($doctor_name_formatted);
    $pdf->SetX(190 - $doctor_name_width - 5);
    $doctor_y = $pdf->GetY();
    $pdf->Cell($doctor_name_width, 7, $doctor_name_formatted, 0, 1, 'R');
    // Underline doctor name
    $pdf->SetY($doctor_y + 6);
    $pdf->Line(190 - $doctor_name_width - 5, $pdf->GetY(), 190, $pdf->GetY());
    
    // License number - right aligned, bold
    $pdf->SetY($doctor_y + 10);
    $pdf->SetFont('helvetica', 'B', 10);
    $license_text = "LICENSED NO: " . $doctor_license_no;
    $license_width = $pdf->GetStringWidth($license_text);
    $pdf->SetX(190 - $license_width - 5);
    $pdf->Cell($license_width, 6, $license_text, 0, 1, 'R');
    
    // Attending Physician - right aligned
    $pdf->SetY($pdf->GetY() + 2);
    $pdf->SetFont('helvetica', '', 10);
    $attending_text = "ATTENDING PHYSICIAN";
    $attending_width = $pdf->GetStringWidth($attending_text);
    $pdf->SetX(190 - $attending_width - 5);
    $pdf->Cell($attending_width, 6, $attending_text, 0, 1, 'R');
    
    // Clear any output buffer before sending PDF
    ob_end_clean();
    
    // Output PDF
    $filename = 'Medical_Certificate_' . date('Y-m-d') . '_' . preg_replace('/[^A-Za-z0-9]/', '_', trim($certificate['patient_name'])) . '.pdf';
    // Support both inline viewing ('I') and download ('D')
    $mode = isset($_GET['mode']) && $_GET['mode'] === 'view' ? 'I' : 'D';
    $pdf->Output($filename, $mode); // 'D' = download, 'I' = inline

} catch (Exception $e) {
    ob_end_clean();
    die('Error generating PDF: ' . htmlspecialchars($e->getMessage()));
}

