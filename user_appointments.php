<?php
session_start();
require 'db.php';
require_once 'residency_verification_helper.php';

if(empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'patient') { 
    header('Location: Login.php'); 
    exit; 
}

// Display success/error messages
$success_msg = $_SESSION['booking_success'] ?? '';
$error_msg = $_SESSION['booking_error'] ?? '';
unset($_SESSION['booking_success'], $_SESSION['booking_error']);

// Get user's appointments
$filter = $_GET['filter'] ?? 'all';
$date_filter = $_GET['date'] ?? 'all'; // Changed default from 'today' to 'all' to show all appointments
$user_id = $_SESSION['user']['id'];
$residency_verified = isPatientResidencyVerified($user_id);

// Get user's data from users table (this is the account owner - always use this for "Myself")
$userData = null;
$stmt = $pdo->prepare('SELECT first_name, middle_name, last_name, contact_no, photo_path FROM users WHERE id = ?');
$stmt->execute([$user_id]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$user_photo_path = $userData['photo_path'] ?? null;

// Don't use patients table for "Myself" - it may contain dependent records
// Only use userData from users table for the account owner

// Get dependents
$dependents = [];
$stmt = $pdo->prepare('SELECT * FROM dependents WHERE patient_id = ? ORDER BY created_at DESC');
$stmt->execute([$user_id]);
$dependents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build WHERE clause - check both user_id and patient_id
$where_clause = "(a.user_id = ? OR a.patient_id = ?)";
$params = [$user_id, $user_id];

if ($filter !== 'all') {
    $where_clause .= " AND a.status = ?";
    $params[] = $filter;
}

if ($date_filter === 'today') {
    $where_clause .= " AND DATE(a.start_datetime) = CURDATE()";
} elseif ($date_filter === 'week') {
    $where_clause .= " AND WEEK(a.start_datetime) = WEEK(CURDATE()) AND YEAR(a.start_datetime) = YEAR(CURDATE())";
}

try {
    // Use DISTINCT to prevent duplicates from multiple patient record matches
    // For dependent appointments (patient_id IS NULL), we'll get the patient name in post-processing
    $stmt = $pdo->prepare("
        SELECT DISTINCT a.*, 
               COALESCE(p.first_name, u.first_name, '') as first_name, 
               COALESCE(p.middle_name, u.middle_name, '') as middle_name,
               COALESCE(p.last_name, u.last_name, '') as last_name, 
               COALESCE(p.phone, u.contact_no, '') as phone,
               CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
        FROM appointments a 
        LEFT JOIN patients p ON a.patient_id = p.id 
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN doctors d ON a.doctor_id = d.id
        LEFT JOIN users du ON du.id = d.user_id
        WHERE $where_clause
        ORDER BY a.start_datetime DESC, a.created_at DESC
    ");
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ensure a `rescheduled` flag is always available for the UI without
    // requiring the underlying database column to exist.
    foreach ($appointments as &$appt) {
        if (!array_key_exists('rescheduled', $appt) || $appt['rescheduled'] === null) {
            $appt['rescheduled'] = 0;
        }
        // Clean up doctor_name (remove extra spaces)
        if (!empty($appt['doctor_name'])) {
            $appt['doctor_name'] = trim(preg_replace('/\s+/', ' ', $appt['doctor_name']));
            if (empty($appt['doctor_name'])) {
                $appt['doctor_name'] = null;
            }
        }
        
        // For dependent appointments (patient_id IS NULL), get the patient name from the patients table
        if (empty($appt['patient_id']) && !empty($appt['user_id'])) {
            // This is a dependent appointment - get the most recent patient record created by this user around the appointment time
            $stmt = $pdo->prepare('SELECT first_name, middle_name, last_name, phone FROM patients 
                                   WHERE created_by_user_id = ? 
                                   AND created_at <= ? 
                                   AND created_at >= DATE_SUB(?, INTERVAL 1 HOUR)
                                   ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([$appt['user_id'], $appt['created_at'], $appt['created_at']]);
            $dependent_patient = $stmt->fetch();
            if ($dependent_patient) {
                $appt['first_name'] = $dependent_patient['first_name'] ?? '';
                $appt['middle_name'] = $dependent_patient['middle_name'] ?? '';
                $appt['last_name'] = $dependent_patient['last_name'] ?? '';
                $appt['phone'] = $dependent_patient['phone'] ?? '';
            }
        }
        
        // Extract patient name from notes if name fields are still empty (fallback)
        if (empty(trim(($appt['first_name'] ?? '') . ' ' . ($appt['last_name'] ?? ''))) && !empty($appt['notes'])) {
            // Try to extract "Patient: Name" from notes
            if (preg_match('/Patient:\s*([^\n]+)/i', $appt['notes'], $matches)) {
                $patientNameFromNotes = trim($matches[1]);
                $nameParts = explode(' ', $patientNameFromNotes, 3);
                if (count($nameParts) >= 2) {
                    $appt['first_name'] = $nameParts[0];
                    $appt['last_name'] = $nameParts[count($nameParts) - 1];
                    if (count($nameParts) === 3) {
                        $appt['middle_name'] = $nameParts[1];
                    }
                } else {
                    $appt['first_name'] = $patientNameFromNotes;
                }
            }
        }
        
        // Check if it's a dependent appointment
        // A dependent appointment is when patient_id is NULL (for new dependent bookings)
        // OR when the patient record's created_by_user_id matches the appointment's user_id
        // AND the patient_id is not the same as user_id (meaning it's a separate patient record)
        // IMPORTANT: If patient_id equals user_id, it's the account owner's own appointment, NOT a dependent
        $appt['is_dependent'] = false;
        $appt['parent_name'] = '';
        
        // If patient_id equals user_id, it's the account owner's own appointment - NOT a dependent
        if (!empty($appt['patient_id']) && $appt['patient_id'] == $appt['user_id']) {
            // This is the account owner's own appointment - definitely not a dependent
            $appt['is_dependent'] = false;
        } elseif (empty($appt['patient_id']) && !empty($appt['user_id'])) {
            // If patient_id is NULL, it's a dependent appointment (new booking system)
            $appt['is_dependent'] = true;
            // Get parent's name
            $stmt = $pdo->prepare('SELECT first_name, middle_name, last_name FROM users WHERE id = ?');
            $stmt->execute([$appt['user_id']]);
            $parent = $stmt->fetch();
            if ($parent) {
                $appt['parent_name'] = trim(($parent['first_name'] ?? '') . ' ' . ($parent['middle_name'] ?? '') . ' ' . ($parent['last_name'] ?? ''));
            }
        } elseif (!empty($appt['patient_id']) && !empty($appt['user_id'])) {
            // Get the patient record to check created_by_user_id
            $stmt = $pdo->prepare('SELECT created_by_user_id FROM patients WHERE id = ?');
            $stmt->execute([$appt['patient_id']]);
            $patient_record = $stmt->fetch();
            
            // If patient record exists and was created by the user_id in the appointment, it's a dependent
            // BUT only if patient_id is NOT equal to user_id (to exclude account owner's own appointments)
            if ($patient_record && $patient_record['created_by_user_id'] == $appt['user_id'] && $appt['patient_id'] != $appt['user_id']) {
                // Get parent's name
                $stmt = $pdo->prepare('SELECT first_name, middle_name, last_name FROM users WHERE id = ?');
                $stmt->execute([$appt['user_id']]);
                $parent = $stmt->fetch();
                if ($parent) {
                    $appt['is_dependent'] = true;
                    $appt['parent_name'] = trim(($parent['first_name'] ?? '') . ' ' . ($parent['middle_name'] ?? '') . ' ' . ($parent['last_name'] ?? ''));
                }
            }
        }
    }
    unset($appt);

} catch(PDOException $e) {
    // Log error for debugging but show friendly message to user
    error_log("Appointments query error: " . $e->getMessage());
    $error_msg = "Unable to load your appointments right now. Please try again later.";
    $appointments = [];
}

// Categorize appointments into tabs
// Upcoming: only future dates and not yet completed (approved or pending only).
// Declined: all appointments with status declined (own section).
// Past: completed, cancelled, rescheduled, or any appointment whose date/time has passed (excluding declined).
$upcoming_appointments = [];
$pending_appointments = [];
$reschedule_appointments = [];
$declined_appointments = [];
$past_appointments = [];

$current_time = time();
$one_week_from_now = strtotime('+7 days');

foreach ($appointments as $appt) {
    $appt_time = strtotime($appt['start_datetime']);
    $is_future = $appt_time > $current_time;
    $is_within_week = $appt_time <= $one_week_from_now && $is_future;
    $is_today = date('Y-m-d', $appt_time) === date('Y-m-d', $current_time);
    
    // Add highlighting flags
    $appt['is_today'] = $is_today;
    $appt['is_within_week'] = $is_within_week;
    
    // Completed/cancelled/declined or past date → never show in Upcoming.
    $is_done_or_past = in_array(strtolower($appt['status'] ?? ''), ['completed', 'cancelled', 'declined', 'rescheduled'], true)
        || !$is_future;
    
    // Categorize based on status and date
    if (strtolower($appt['status'] ?? '') === 'declined') {
        $declined_appointments[] = $appt;
    } elseif ($appt['status'] === 'pending') {
        $pending_appointments[] = $appt;
    } elseif (!empty($appt['rescheduled']) && $appt['rescheduled'] == 1) {
        $reschedule_appointments[] = $appt;
    } elseif ($is_future && ($appt['status'] === 'approved' || $appt['status'] === 'pending') && !$is_done_or_past) {
        // Upcoming: future date only, and not completed/cancelled/declined
        $upcoming_appointments[] = $appt;
    } else {
        // Past: completed, cancelled, rescheduled, or past dates (declined are in Declined tab)
        $past_appointments[] = $appt;
    }
}

// Sort upcoming by date (ascending - soonest first)
usort($upcoming_appointments, function($a, $b) {
    return strtotime($a['start_datetime']) - strtotime($b['start_datetime']);
});

// Sort declined by date (descending - most recent first)
usort($declined_appointments, function($a, $b) {
    return strtotime($b['start_datetime']) - strtotime($a['start_datetime']);
});

// Sort past by date (descending - most recent first)
usort($past_appointments, function($a, $b) {
    return strtotime($b['start_datetime']) - strtotime($a['start_datetime']);
});

// Get all follow-up appointments for the patient
$pending_followups = [];
$doctor_set_followups = [];
$reschedule_requested_followups = [];
$approved_followups = [];

try {
    $check_table = $pdo->query("SHOW TABLES LIKE 'follow_up_appointments'");
    if ($check_table->rowCount() > 0) {
        // Get pending follow-up appointments with alternative dates (for rescheduling)
        $stmt = $pdo->prepare("
            SELECT 
                f.*,
                a.start_datetime as original_appointment_date,
                CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
            FROM follow_up_appointments f
            LEFT JOIN appointments a ON f.original_appointment_id = a.id
            LEFT JOIN doctors d ON f.doctor_id = d.id
            LEFT JOIN users du ON d.user_id = du.id
            WHERE (f.user_id = ? OR f.patient_id = ?)
            AND f.status = 'pending_patient_confirmation'
            ORDER BY f.proposed_datetime ASC
        ");
        $stmt->execute([$user_id, $user_id]);
        $pending_followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get doctor-set follow-ups (initial recommendations)
        $stmt = $pdo->prepare("
            SELECT 
                f.*,
                a.start_datetime as original_appointment_date,
                CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
            FROM follow_up_appointments f
            LEFT JOIN appointments a ON f.original_appointment_id = a.id
            LEFT JOIN doctors d ON f.doctor_id = d.id
            LEFT JOIN users du ON d.user_id = du.id
            WHERE (f.user_id = ? OR f.patient_id = ?)
            AND f.status = 'doctor_set'
            ORDER BY f.proposed_datetime ASC
        ");
        $stmt->execute([$user_id, $user_id]);
        $doctor_set_followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get reschedule-requested follow-ups
        $stmt = $pdo->prepare("
            SELECT 
                f.*,
                a.start_datetime as original_appointment_date,
                CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
            FROM follow_up_appointments f
            LEFT JOIN appointments a ON f.original_appointment_id = a.id
            LEFT JOIN doctors d ON f.doctor_id = d.id
            LEFT JOIN users du ON d.user_id = du.id
            WHERE (f.user_id = ? OR f.patient_id = ?)
            AND f.status = 'reschedule_requested'
            ORDER BY f.proposed_datetime ASC
        ");
        $stmt->execute([$user_id, $user_id]);
        $reschedule_requested_followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get approved follow-ups
        $stmt = $pdo->prepare("
            SELECT 
                f.*,
                a.start_datetime as original_appointment_date,
                CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name
            FROM follow_up_appointments f
            LEFT JOIN appointments a ON f.original_appointment_id = a.id
            LEFT JOIN doctors d ON f.doctor_id = d.id
            LEFT JOIN users du ON d.user_id = du.id
            WHERE (f.user_id = ? OR f.patient_id = ?)
            AND f.status = 'approved'
            ORDER BY COALESCE(f.selected_datetime, f.proposed_datetime) ASC
        ");
        $stmt->execute([$user_id, $user_id]);
        $approved_followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $pending_followups = [];
    $doctor_set_followups = [];
    $reschedule_requested_followups = [];
    $approved_followups = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Appointments - HealthServe</title>
    <link rel="stylesheet" href="assets/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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

        /* Booking form - match reference styling */
        .booking-card{max-width: 1000px;margin:0 auto;border-radius:16px}
        .booking-title{font-size:18px;text-align:center;margin-bottom:16px;color:#2e3b4e}
        .booking-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .booking-section-title{font-weight:600;color:#2e3b4e;margin-bottom:8px}
        .form-input, .form-select{width:100%;padding:12px 14px;border:2px solid #e0e0e0;border-radius:10px;background:#fff;font-size:14px;transition:border .2s ease}
        .form-input:focus, .form-select:focus{outline:none;border-color:#4caf50;box-shadow:0 0 0 3px rgba(76,175,80,.08)}
        .booking-notes{resize:none}
        .form-select{appearance:none;-webkit-appearance:none;-moz-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;background-size:16px}
        .booking-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .input-wrapper{position:relative;width:100%}
        .input-wrapper input,
        .input-wrapper select{width:100%}
        .input-wrapper.required-field input{padding-right:28px}
        .input-wrapper.required-field::after{
            content:'*';
            color:#d32f2f;
            font-size:18px;
            position:absolute;
            top:8px;
            right:12px;
            pointer-events:none;
        }
        .booking-actions{
            display:flex;
            gap:12px;
            justify-content:center;
            margin-top:12px;
        }
        .booking-actions #booking-submit-wrapper{
            flex:1;
            min-width:0;
        }
        .booking-actions #booking-submit-wrapper #booking-submit-btn{
            width:100%;
        }
        .booking-actions .btn-primary{
            flex:1;
            text-align:center;
            padding:.875rem 1.5rem;
            border-radius:12px;
            font-weight:600;
            font-size:1rem;
        }

        /* Filter appointments styling */
        .filter-controls{
            display:flex;
            gap:1rem;
            flex-wrap:wrap;
            align-items:flex-end;
        }
        .filter-group{
            display:flex;
            flex-direction:column;
            gap:0.25rem;
        }
        .filter-label{
            font-size:0.9rem;
            font-weight:600;
            color:#2e3b4e;
        }
        .filter-select{
            min-width:180px;
        }
        @media(max-width:768px){
            .booking-grid,
            .booking-row{grid-template-columns:1fr}
        }

        /* Calendar Styles */
        .appointment-calendar {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
            margin-top: 16px;
        }
        .calendar-day {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 8px;
            background: #fff;
            min-height: 100px;
            display: flex;
            flex-direction: column;
        }
        .calendar-day-header {
            font-weight: 600;
            font-size: 12px;
            color: #666;
            margin-bottom: 6px;
            text-align: center;
        }
        .calendar-day-number {
            font-size: 14px;
            font-weight: 600;
            color: #2e3b4e;
            text-align: center;
            margin-bottom: 8px;
        }
        .calendar-slot {
            flex: 1;
            border-radius: 6px;
            padding: 6px 8px;
            margin-bottom: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid transparent;
        }
        .calendar-slot.am {
            background: #e3f2fd;
            color: #1976d2;
        }
        .calendar-slot.pm {
            background: #fff3e0;
            color: #f57c00;
        }
        .calendar-slot:hover:not(.disabled) {
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .calendar-slot.selected {
            border: 2px solid #4caf50;
            background: #4caf50;
            color: #fff;
            font-weight: 700;
        }
        .calendar-slot.disabled {
            background: #f5f5f5;
            color: #bbb;
            cursor: not-allowed;
            opacity: 0.6;
        }
        .calendar-slot.full {
            background: #ffebee;
            color: #c62828;
        }
        .calendar-slot.blocked {
            background: #eceff1;
            color: #546e7a;
            border-color: #90a4ae;
        }
        .calendar-slot.blocked.disabled {
            background: #cfd8dc;
            color: #78909c;
            opacity: 0.7;
        }
        .calendar-slot-label {
            font-size: 10px;
            margin-bottom: 2px;
        }
        .calendar-slot-count {
            font-size: 12px;
            font-weight: 700;
        }
        .calendar-slot-status {
            font-size: 9px;
            font-weight: 600;
            margin-top: 2px;
            text-transform: uppercase;
        }
        .calendar-weekend {
            background: #fafafa;
        }
        
        /* Follow-up option cards */
        .followup-option-card:hover {
            border-color: #4CAF50 !important;
            background: #f1f8f4 !important;
        }
        .followup-option-card input[type="radio"]:checked + span {
            color: #2E7D32;
        }
            opacity: 0.5;
        }
        .calendar-month-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding: 0 8px;
        }
        .calendar-nav-btn {
            background: #4caf50;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 8px 16px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.2s;
        }
        .calendar-nav-btn:hover {
            background: #45a049;
        }
        .calendar-nav-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .calendar-month-title {
            font-size: 18px;
            font-weight: 600;
            color: #2e3b4e;
        }
        @media(max-width:768px){
            .appointment-calendar {
                gap: 4px;
            }
            .calendar-day {
                padding: 4px;
                min-height: 80px;
            }
            .calendar-slot {
                padding: 4px;
                font-size: 10px;
            }
        }
        
        /* Spinner animation for loading states */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Page Header Section */
        .page-header-section {
            margin-bottom: 2.5rem;
            text-align: center;
        }
        .page-header-section .page-title {
            margin-bottom: 1.25rem;
            text-align: center;
        }
        .page-header-action {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 0.5rem;
        }
        .btn-book-appointment {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #4CAF50;
            color: white;
            border: none;
            padding: 0.75rem 1.75rem;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            white-space: nowrap;
            box-shadow: 0 2px 8px rgba(76, 175, 80, 0.2);
        }
        .btn-book-appointment:hover {
            background: #45a049;
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(76, 175, 80, 0.3);
        }
        .btn-book-appointment:active {
            transform: translateY(0);
        }
        .btn-book-appointment i {
            font-size: 1rem;
        }
        @media (max-width: 768px) {
            .page-header-section {
                margin-bottom: 2rem;
            }
            .btn-book-appointment {
                padding: 0.75rem 1.5rem;
                font-size: 0.9rem;
            }
        }
        
        /* Tab-based Appointments UI */
        .appointments-tabs-container {
            margin-bottom: 2rem;
            background: #fff;
            border-radius: 12px;
            padding: 0.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .appointments-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .tab-btn {
            flex: 1;
            min-width: 120px;
            padding: 0.875rem 1.5rem;
            background: transparent;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            color: #666;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .tab-btn:hover {
            background: #f5f5f5;
            color: #333;
        }
        .tab-btn.active {
            background: #4CAF50;
            color: #fff;
            box-shadow: 0 2px 8px rgba(76, 175, 80, 0.3);
        }
        .tab-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.25rem;
            height: 1.25rem;
            padding: 0 0.4rem;
            background: #dc3545;
            color: #fff;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 700;
            line-height: 1;
            vertical-align: middle;
            box-sizing: border-box;
        }
        .tab-btn.active .tab-badge {
            background: #dc3545;
            color: #fff;
        }
        
        /* Tab Content */
        .tab-content-container {
            margin-top: 1rem;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        
        /* Compact Appointment Cards */
        .appointments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .appointment-card-compact {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 1rem;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .appointment-card-compact:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .appointment-card-compact.highlight-today {
            border: 2px solid #4CAF50;
            background: linear-gradient(135deg, #f1f8f4 0%, #ffffff 100%);
            box-shadow: 0 4px 16px rgba(76, 175, 80, 0.2);
        }
        .appointment-card-compact.highlight-upcoming {
            border-left: 4px solid #2196F3;
            background: linear-gradient(135deg, #e3f2fd 0%, #ffffff 100%);
        }
        .card-header-compact {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #f0f0f0;
        }
        .card-date-time {
            display: flex;
            flex-direction: column;
        }
        .date-display {
            font-size: 1.1rem;
            font-weight: 700;
            color: #2e3b4e;
            line-height: 1.2;
        }
        .time-display {
            font-size: 0.9rem;
            color: #666;
            margin-top: 0.25rem;
        }
        .card-status-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .status-badge-compact {
            padding: 0.375rem 0.75rem;
            border-radius: 16px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .status-badge-compact.status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .status-badge-compact.status-approved {
            background: #d4edda;
            color: #155724;
        }
        .status-badge-compact.status-declined {
            background: #f8d7da;
            color: #721c24;
        }
        .status-badge-compact.status-completed {
            background: #d1ecf1;
            color: #0c5460;
        }
        .status-badge-compact.status-missed {
            background: #e2e3e5;
            color: #383d41;
        }
        
        /* More Menu Dropdown */
        .card-more-menu {
            position: relative;
        }
        .more-btn {
            background: transparent;
            border: none;
            font-size: 1.25rem;
            color: #666;
            cursor: pointer;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            transition: all 0.2s ease;
            line-height: 1;
        }
        .more-btn:hover {
            background: #f5f5f5;
            color: #333;
        }
        .more-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 100;
            min-width: 160px;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        .more-dropdown button {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            width: 100%;
            padding: 0.75rem 1rem;
            background: transparent;
            border: none;
            text-align: left;
            cursor: pointer;
            font-size: 0.9rem;
            color: #333;
            transition: background 0.2s ease;
        }
        .more-dropdown button:hover {
            background: #f5f5f5;
        }
        .more-dropdown button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .more-dropdown button.danger {
            color: #dc3545;
        }
        .more-dropdown button.danger:hover {
            background: #ffeaea;
        }
        .more-dropdown button i {
            width: 16px;
            text-align: center;
        }
        
        /* Card Body */
        .card-body-compact {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .card-info-row {
            display: flex;
            gap: 0.5rem;
            font-size: 0.9rem;
        }
        .info-label {
            font-weight: 600;
            color: #666;
            min-width: 70px;
        }
        .info-value {
            color: #333;
            flex: 1;
        }
        .info-value small {
            color: #999;
            font-size: 0.85em;
        }
        .rescheduled-badge {
            display: inline-block;
            background: #fff3e0;
            color: #ff9800;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.5rem;
            width: fit-content;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #666;
        }
        .empty-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        .empty-state h3 {
            margin: 0 0 0.5rem 0;
            color: #333;
        }
        .empty-state p {
            margin: 0;
            color: #999;
        }
        
        /* Reschedule Section */
        .reschedule-section {
            margin-bottom: 1.5rem;
        }
        .collapse-toggle {
            width: 100%;
            padding: 1rem;
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            color: #333;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.2s ease;
        }
        .collapse-toggle:hover {
            background: #f0f0f0;
        }
        .collapse-toggle i {
            transition: transform 0.3s ease;
        }
        .collapse-toggle.expanded i {
            transform: rotate(180deg);
        }
        .section-badge {
            background: #2196F3;
            color: #fff;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 700;
            margin-left: auto;
        }
        .collapsible-content {
            margin-top: 1rem;
        }
        .reschedule-request {
            border-left: 4px solid #2196F3;
        }
        .waiting-message {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem;
            background: #e3f2fd;
            border-radius: 6px;
            color: #2196F3;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .appointments-grid {
                grid-template-columns: 1fr;
            }
            .appointments-tabs {
                flex-direction: column;
            }
            .tab-btn {
                width: 100%;
            }
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
            border-color: #ff9800;
        }
        
        .category-accordion.active {
            border-color: #ff9800;
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
            color: #ff9800;
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
    </style>
    <script src="custom_modal.js"></script>
    <script src="custom_notification.js"></script>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-logo">
            <img src="assets/payatas logo.png" alt="Payatas B Logo">
            <h1>HealthServe - Payatas B</h1>
        </div>
        <nav class="header-nav">
            <a href="user_main_dashboard.php">Dashboard</a>
            <a href="user_records.php">My Record</a>
            <a href="user_appointments.php" class="active">Appointments</a>
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
            <strong>Complete your Payatas residency verification</strong> to book appointments, request lab tests, and receive prescriptions.
            <a href="user_profile.php#verification" style="color: #8b0000; text-decoration: underline;">Upload your ID in Profile &rarr;</a>
        </div>
        <?php endif; ?>
        <!-- Page Header -->
        <div class="page-header-section">
            <h1 class="page-title">📅 My Appointments</h1>
            <div class="page-header-action">
                <?php if ($residency_verified): ?>
                <a href="#appointment_booking_form" id="open-booking" class="btn-book-appointment">
                    <i class="fas fa-calendar-plus"></i>
                    <span>Book Appointment</span>
                </a>
                <?php else: ?>
                <button type="button" id="open-booking-restricted" class="btn-book-appointment" style="cursor:pointer;border:none;">
                    <i class="fas fa-calendar-plus"></i>
                    <span>Book Appointment</span>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if($success_msg): ?>
            <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; border: 1px solid #c3e6cb;">
                <?=htmlspecialchars($success_msg)?>
            </div>
        <?php endif; ?>

        <?php if($error_msg): ?>
            <div class="alert alert-error" style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; border: 1px solid #f5c6cb;">
                <?=htmlspecialchars($error_msg)?>
            </div>
        <?php endif; ?>

        <!-- Appointment Booking Form (embedded) -->
        <div id="appointment_booking_form" class="content-card booking-card" style="margin-bottom: 2rem; display:none;">
            <div class="booking-title">Appointment Booking Form</div>
            <form method="post" action="book_appointment.php" class="form-container" id="booking-form">
                <!-- Booking For Selection -->
                <div class="booking-section-title" style="margin-bottom:16px">Who is this appointment for?</div>
                <div class="booking-row" style="margin-bottom:20px">
                    <div class="input-wrapper" style="display:flex;gap:20px;align-items:center;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="radio" name="booking_for" value="self" id="booking_self" checked style="width:auto;cursor:pointer;">
                            <span>Myself</span>
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="radio" name="booking_for" value="dependent" id="booking_dependent" style="width:auto;cursor:pointer;">
                            <span>A Dependent</span>
                        </label>
                    </div>
                </div>

                <!-- Dependent Selection (hidden by default) -->
                <div id="dependent-selection" style="display:none;margin-bottom:20px;">
                    <div class="booking-section-title" style="margin-bottom:8px">Select Dependent</div>
                    <select class="form-select" name="dependent_id" id="dependent_select">
                        <option value="">-- Select a dependent --</option>
                        <?php foreach($dependents as $dep): ?>
                            <option value="<?=htmlspecialchars($dep['id'])?>" 
                                    data-first="<?=htmlspecialchars($dep['first_name'])?>"
                                    data-middle="<?=htmlspecialchars($dep['middle_name'] ?? '')?>"
                                    data-last="<?=htmlspecialchars($dep['last_name'])?>">
                                <?=htmlspecialchars(trim($dep['first_name'] . ' ' . ($dep['middle_name'] ?? '') . ' ' . $dep['last_name']))?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="booking-grid" style="margin-bottom:16px">
                    <div>
                        <div class="booking-section-title">Personal Information</div>
                        <div class="booking-row">
                            <div class="input-wrapper required-field">
                                <input type="text" class="form-input" name="first_name" id="first_name" placeholder="First Name" required>
                            </div>
                            <div class="input-wrapper">
                                <input type="text" class="form-input" name="middle_name" id="middle_name" placeholder="Middle Name">
                            </div>
                        </div>
                        <div class="booking-row" style="margin-top:16px">
                            <div class="input-wrapper required-field">
                                <input type="text" class="form-input" name="last_name" id="last_name" placeholder="Surname" required>
                            </div>
                            <div class="input-wrapper required-field">
                                <input type="tel" class="form-input" name="phone" id="phone" placeholder="Contact Number" required maxlength="11" pattern="\d{11}" inputmode="numeric">
                            </div>
                        </div>
                    </div>
                    <div></div>
                </div>

                <div class="booking-section-title">Appointment Details</div>
                <div class="booking-row">
                    <div class="input-wrapper" style="grid-column: span 2;">
                        <select class="form-select" name="reason" id="reason_select" required>
                            <option value="" disabled selected>Chief Complaint</option>
                            <option value="General Check-up">General Check-up</option>
                            <option value="Follow-up Check-up">Follow-up Check-up</option>
                            <option value="Medical Certificate Request">Medical Certificate Request</option>
                            <option value="Prenatal Care">Prenatal Care</option>
                            <option value="Child Checkup / Pediatrics">Child Checkup / Pediatrics</option>
                            <option value="Medication Refill">Medication Refill</option>
                            <option value="Vital Signs / BP Monitoring">Vital Signs / BP Monitoring</option>
                            <option value="others">Others (Please Specify)</option>
                        </select>
                    </div>
                </div>
                <div id="other-reason-container" style="display:none; margin-top:16px;">
                    <input type="text" class="form-input" id="other_reason_input" name="other_reason" placeholder="Please specify other reason">
                </div>
                
                <!-- Calendar View for Slot Selection -->
                <div style="margin-top:24px">
                    <div class="booking-section-title" style="margin-bottom:12px">Select Date & Time Period</div>
                    <div id="appointment-calendar-container" style="margin-bottom:20px">
                        <div style="text-align:center;padding:20px;color:#666;">
                            <div style="display:inline-block;width:20px;height:20px;border:2px solid #ddd;border-radius:4px;animation:spin 1s linear infinite;margin-right:10px;vertical-align:middle;"></div>
                            Loading calendar...
                        </div>
                    </div>
                </div>
                
                <!-- Time Selection (shown after calendar slot is selected) -->
                <div class="booking-row" style="margin-top:16px;display:none;" id="time-selection-container">
                    <div>
                        <div class="booking-section-title" style="margin-bottom:6px">Selected Date</div>
                        <div style="position:relative">
                            <input type="date" class="form-input" id="preferred_date" name="preferred_date" required readonly style="background:#f5f5f5;">
                            <span style="position:absolute;right:12px;top:50%;transform:translateY(-50%)">📅</span>
                        </div>
                    </div>
                    <div>
                        <div class="booking-section-title" style="margin-bottom:6px">Select Specific Time</div>
                        <div style="position:relative">
                            <select class="form-select" id="preferred_time" name="preferred_time" required></select>
                            <span style="position:absolute;right:12px;top:50%;transform:translateY(-50%)">⏰</span>
                        </div>
                    </div>
                </div>

                <div style="margin-top:16px">
                    <textarea class="form-input booking-notes" name="notes" rows="3" placeholder="Notes (optional): reason for visit, concerns, etc."></textarea>
                </div>

                <input type="hidden" name="start_datetime" id="start_datetime">
                <input type="hidden" name="duration_minutes" value="30">

                <div style="margin-top:16px">
                    <label style="display:flex;align-items:center;gap:10px;color:#2e3b4e"><input type="checkbox" required id="booking_confirm_checkbox"> I confirm  that the information provided is correct.</label>
                </div>
                <div id="booking-validation-msg" class="booking-validation-msg" style="display:none; margin-top:12px; padding:10px 12px; background:#fff3cd; color:#856404; border-radius:6px; font-size:0.9rem;"></div>
                <div class="booking-actions">
                    <span class="booking-submit-wrapper" id="booking-submit-wrapper" title="" style="cursor:pointer;display:inline-block;">
                        <button type="submit" name="create_appt" id="booking-submit-btn" class="btn-primary" disabled title="You must select a date and check-up type before confirming.">Book Appointment</button>
                    </span>
                    <button type="button" class="btn-primary" onclick="window.location.hash=''">Cancel</button>
                </div>
            </form>
        </div>

        <!-- Tab Navigation -->
        <div class="appointments-tabs-container">
            <div class="appointments-tabs">
                <button class="tab-btn active" data-tab="upcoming" onclick="switchTab('upcoming')">
                    Upcoming
                    <?php if(count($upcoming_appointments) > 0): ?>
                        <span class="tab-badge"><?= count($upcoming_appointments) ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab-btn" data-tab="pending" onclick="switchTab('pending')">
                    Pending
                    <?php if(count($pending_appointments) > 0): ?>
                        <span class="tab-badge"><?= count($pending_appointments) ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab-btn" data-tab="reschedule" onclick="switchTab('reschedule')">
                    Reschedule
                    <?php 
                    $reschedule_count = count($reschedule_appointments) + count($reschedule_requested_followups) + count($doctor_set_followups);
                    if ($reschedule_count > 0): 
                    ?>
                        <span class="tab-badge"><?= $reschedule_count ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab-btn" data-tab="declined" onclick="switchTab('declined')">
                    Declined
                    <?php if(count($declined_appointments) > 0): ?>
                        <span class="tab-badge"><?= count($declined_appointments) ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab-btn" data-tab="past" onclick="switchTab('past')">
                    Past
                    <?php if(count($past_appointments) > 0): ?>
                        <span class="tab-badge"><?= count($past_appointments) ?></span>
                    <?php endif; ?>
                </button>
            </div>
        </div>

        <!-- Approved Follow-Up Appointments -->
        <?php if(!empty($approved_followups)): ?>
        <div class="content-card" style="margin-bottom: 2rem; border-left: 4px solid #4CAF50;">
            <h2 class="card-title" style="color: #2E7D32;">
                <i class="fas fa-calendar-check"></i> Approved Follow-Up Appointments
            </h2>
            <?php foreach($approved_followups as $followup): 
                $final_datetime = !empty($followup['selected_datetime']) ? $followup['selected_datetime'] : $followup['proposed_datetime'];
                $final_date = date('F j, Y', strtotime($final_datetime));
                $final_time = date('g:i A', strtotime($final_datetime));
                $original_date = $followup['original_appointment_date'] ? date('F j, Y', strtotime($followup['original_appointment_date'])) : 'N/A';
            ?>
                <div class="appointment-card" style="border: 2px solid #4CAF50; border-radius: 8px; padding: 1.5rem; margin-bottom: 1rem; background: #e8f5e9;">
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

        <!-- Follow-Up Appointments Pending Selection -->
        <?php if(!empty($pending_followups)): ?>
        <div class="content-card" style="margin-bottom: 2rem; border-left: 4px solid #2196F3;">
            <h2 class="card-title" style="color: #2196F3;">
                <i class="fas fa-calendar-check"></i> Follow-Up Appointments - Select Your Preferred Date
            </h2>
            <p style="color: #666; margin-bottom: 1.5rem;">Your doctor has provided alternative schedule options. Please select your preferred date and time.</p>
            
            <?php foreach($pending_followups as $followup): 
                $proposed_date = date('F j, Y', strtotime($followup['proposed_datetime']));
                $proposed_time = date('g:i A', strtotime($followup['proposed_datetime']));
                $original_date = $followup['original_appointment_date'] ? date('F j, Y', strtotime($followup['original_appointment_date'])) : 'N/A';
            ?>
                <div class="appointment-card" style="border: 2px solid #2196F3; border-radius: 8px; padding: 1.5rem; margin-bottom: 1rem; background: #E3F2FD;">
                    <div style="margin-bottom: 1rem;">
                        <h3 style="margin: 0 0 0.5rem 0; color: #1976D2;">
                            Follow-Up Appointment Selection
                        </h3>
                        <p style="margin: 0; color: #666; font-size: 0.9rem;">
                            <strong>Original Appointment:</strong> <?= htmlspecialchars($original_date) ?><br>
                            <?php if (!empty($followup['follow_up_reason'])): ?>
                                <strong>Reason:</strong> <?= htmlspecialchars($followup['follow_up_reason']) ?><br>
                            <?php endif; ?>
                            <?php if (!empty($followup['doctor_name'])): ?>
                                <strong>Doctor:</strong> <?= htmlspecialchars(trim($followup['doctor_name'])) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <form id="followupForm<?= $followup['id'] ?>" onsubmit="selectFollowUpOption(event, <?= $followup['id'] ?>)">
                        <input type="hidden" name="follow_up_id" value="<?= $followup['id'] ?>">
                        <p style="font-weight: 600; margin-bottom: 1rem; color: #333;">Please select your preferred date and time:</p>
                        
                        <div style="display: grid; gap: 0.75rem; margin-bottom: 1rem;">
                            <!-- Proposed Option -->
                            <label class="followup-option-card" style="display: block; border: 2px solid #e0e0e0; border-radius: 8px; padding: 1rem; cursor: pointer; transition: all 0.3s; background: white;">
                                <input type="radio" name="selected_option" value="proposed" id="option_proposed_<?= $followup['id'] ?>" required style="margin-right: 0.75rem;">
                                <span style="font-weight: 600; color: #333;">
                                    <span style="font-size: 1.1rem;"><?= $proposed_date ?></span><br>
                                    <span style="color: #666; font-size: 0.9rem;"><?= $proposed_time ?></span>
                                </span>
                            </label>
                            
                            <!-- Alternative Options -->
                            <?php if (!empty($followup['alternative_datetime_1'])): 
                                $alt1_date = date('F j, Y', strtotime($followup['alternative_datetime_1']));
                                $alt1_time = date('g:i A', strtotime($followup['alternative_datetime_1']));
                            ?>
                                <label class="followup-option-card" style="display: block; border: 2px solid #e0e0e0; border-radius: 8px; padding: 1rem; cursor: pointer; transition: all 0.3s; background: white;">
                                    <input type="radio" name="selected_option" value="1" id="option_1_<?= $followup['id'] ?>" style="margin-right: 0.75rem;">
                                    <span style="font-weight: 600; color: #333;">
                                        <span style="font-size: 1.1rem;"><?= $alt1_date ?></span><br>
                                        <span style="color: #666; font-size: 0.9rem;"><?= $alt1_time ?></span>
                                    </span>
                                </label>
                            <?php endif; ?>
                            
                            <?php if (!empty($followup['alternative_datetime_2'])): 
                                $alt2_date = date('F j, Y', strtotime($followup['alternative_datetime_2']));
                                $alt2_time = date('g:i A', strtotime($followup['alternative_datetime_2']));
                            ?>
                                <label class="followup-option-card" style="display: block; border: 2px solid #e0e0e0; border-radius: 8px; padding: 1rem; cursor: pointer; transition: all 0.3s; background: white;">
                                    <input type="radio" name="selected_option" value="2" id="option_2_<?= $followup['id'] ?>" style="margin-right: 0.75rem;">
                                    <span style="font-weight: 600; color: #333;">
                                        <span style="font-size: 1.1rem;"><?= $alt2_date ?></span><br>
                                        <span style="color: #666; font-size: 0.9rem;"><?= $alt2_time ?></span>
                                    </span>
                                </label>
                            <?php endif; ?>
                            
                            <?php if (!empty($followup['alternative_datetime_3'])): 
                                $alt3_date = date('F j, Y', strtotime($followup['alternative_datetime_3']));
                                $alt3_time = date('g:i A', strtotime($followup['alternative_datetime_3']));
                            ?>
                                <label class="followup-option-card" style="display: block; border: 2px solid #e0e0e0; border-radius: 8px; padding: 1rem; cursor: pointer; transition: all 0.3s; background: white;">
                                    <input type="radio" name="selected_option" value="3" id="option_3_<?= $followup['id'] ?>" style="margin-right: 0.75rem;">
                                    <span style="font-weight: 600; color: #333;">
                                        <span style="font-size: 1.1rem;"><?= $alt3_date ?></span><br>
                                        <span style="color: #666; font-size: 0.9rem;"><?= $alt3_time ?></span>
                                    </span>
                                </label>
                            <?php endif; ?>
                        </div>
                        
                        <button type="submit" class="btn-primary" id="submitBtn<?= $followup['id'] ?>" disabled style="width: 100%; padding: 0.75rem; border-radius: 8px; font-weight: 600;">
                            Submit Selection for Doctor Approval
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Tab Content -->
        <div class="tab-content-container">
            <!-- Upcoming Tab -->
            <div id="tab-upcoming" class="tab-content active">
                <?php if(empty($upcoming_appointments)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📅</div>
                        <h3>No upcoming appointments</h3>
                        <p>You don't have any upcoming appointments scheduled.</p>
                    </div>
                <?php else: ?>
                    <div class="appointments-grid">
                        <?php foreach($upcoming_appointments as $appointment): 
                            // Extract appointment details
                            $appointment_notes = $appointment['notes'] ?? '';
                            $appointment_reason = '';
                            if ($appointment_notes && preg_match('/Reason:\s*([^\n]+)/i', $appointment_notes, $matches)) {
                                $appointment_reason = trim($matches[1]);
                            }
                            $appointment_time = strtotime($appointment['start_datetime']);
                            $current_time = time();
                            $time_diff = $appointment_time - $current_time;
                            $hours_away = $time_diff / 3600;
                            $can_reschedule = $hours_away > 1;
                            $highlight_class = '';
                            if ($appointment['is_today']) {
                                $highlight_class = 'highlight-today';
                            } elseif ($appointment['is_within_week']) {
                                $highlight_class = 'highlight-upcoming';
                            }
                        ?>
                            <div class="appointment-card-compact <?= $highlight_class ?>" data-appointment-id="<?= $appointment['id'] ?>">
                                <div class="card-header-compact">
                                    <div class="card-date-time">
                                        <div class="date-display"><?= date('M d', strtotime($appointment['start_datetime'])) ?></div>
                                        <div class="time-display"><?= date('g:i A', strtotime($appointment['start_datetime'])) ?></div>
                                    </div>
                                    <div class="card-status-actions">
                                        <span class="status-badge-compact status-<?= $appointment['status'] ?>">
                                            <?= htmlspecialchars($appointment['status']) ?>
                                        </span>
                                        <div class="card-more-menu">
                                            <button class="more-btn" onclick="toggleMoreMenu(event, <?= $appointment['id'] ?>)">⋮</button>
                                            <div class="more-dropdown" id="more-menu-<?= $appointment['id'] ?>" style="display: none;">
                                                <button onclick="openAppointmentDetailsModal(<?= $appointment['id'] ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <?php if(($appointment['status'] === 'pending' || $appointment['status'] === 'approved') && $can_reschedule): ?>
                                                    <button onclick="openRescheduleModal(<?= $appointment['id'] ?>)">
                                                        <i class="fas fa-calendar-alt"></i> Reschedule
                                                    </button>
                                                    <button onclick="cancelAppointment(<?= $appointment['id'] ?>)" class="danger">
                                                        <i class="fas fa-times"></i> Cancel
                                                    </button>
                                                <?php elseif(($appointment['status'] === 'pending' || $appointment['status'] === 'approved') && !$can_reschedule): ?>
                                                    <button disabled title="Cannot reschedule appointments less than 1 hour away">
                                                        <i class="fas fa-calendar-alt"></i> Reschedule
                                                    </button>
                                                    <button onclick="cancelAppointment(<?= $appointment['id'] ?>)" class="danger">
                                                        <i class="fas fa-times"></i> Cancel
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body-compact">
                                    <div class="card-info-row">
                                        <span class="info-label">Doctor:</span>
                                        <span class="info-value">
                                            <?= !empty($appointment['doctor_name']) && trim($appointment['doctor_name']) ? htmlspecialchars(trim($appointment['doctor_name'])) : 'To be assigned' ?>
                                        </span>
                                    </div>
                                    <?php if($appointment_reason): ?>
                                        <div class="card-info-row">
                                            <span class="info-label">Reason:</span>
                                            <span class="info-value"><?= htmlspecialchars($appointment_reason) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="card-info-row">
                                        <span class="info-label">Duration:</span>
                                        <span class="info-value"><?= $appointment['duration_minutes'] ?> minutes</span>
                                    </div>
                                    <?php if(!empty($appointment['is_dependent']) && !empty($appointment['parent_name'])): ?>
                                        <div class="card-info-row">
                                            <span class="info-label">Patient:</span>
                                            <span class="info-value">
                                                <?= htmlspecialchars(trim(($appointment['first_name'] ?? '') . ' ' . ($appointment['last_name'] ?? ''))) ?>
                                                <small>(Dependent)</small>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if(!empty($appointment['rescheduled']) && $appointment['rescheduled'] == 1): ?>
                                        <div class="rescheduled-badge">Rescheduled</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Pending Tab -->
            <div id="tab-pending" class="tab-content">
                <?php if(empty($pending_appointments)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">⏳</div>
                        <h3>No pending appointments</h3>
                        <p>You don't have any pending appointments.</p>
                    </div>
                <?php else: ?>
                    <div class="appointments-grid">
                        <?php foreach($pending_appointments as $appointment): 
                            $appointment_notes = $appointment['notes'] ?? '';
                            $appointment_reason = '';
                            if ($appointment_notes && preg_match('/Reason:\s*([^\n]+)/i', $appointment_notes, $matches)) {
                                $appointment_reason = trim($matches[1]);
                            }
                        ?>
                            <div class="appointment-card-compact" data-appointment-id="<?= $appointment['id'] ?>">
                                <div class="card-header-compact">
                                    <div class="card-date-time">
                                        <div class="date-display"><?= date('M d', strtotime($appointment['start_datetime'])) ?></div>
                                        <div class="time-display"><?= date('g:i A', strtotime($appointment['start_datetime'])) ?></div>
                                    </div>
                                    <div class="card-status-actions">
                                        <span class="status-badge-compact status-pending">Pending</span>
                                        <div class="card-more-menu">
                                            <button class="more-btn" onclick="toggleMoreMenu(event, <?= $appointment['id'] ?>)">⋮</button>
                                            <div class="more-dropdown" id="more-menu-<?= $appointment['id'] ?>" style="display: none;">
                                                <button onclick="openAppointmentDetailsModal(<?= $appointment['id'] ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body-compact">
                                    <div class="card-info-row">
                                        <span class="info-label">Doctor:</span>
                                        <span class="info-value">
                                            <?= !empty($appointment['doctor_name']) && trim($appointment['doctor_name']) ? htmlspecialchars(trim($appointment['doctor_name'])) : 'To be assigned' ?>
                                        </span>
                                    </div>
                                    <?php if($appointment_reason): ?>
                                        <div class="card-info-row">
                                            <span class="info-label">Reason:</span>
                                            <span class="info-value"><?= htmlspecialchars($appointment_reason) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="card-info-row">
                                        <span class="info-label">Duration:</span>
                                        <span class="info-value"><?= $appointment['duration_minutes'] ?> minutes</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Reschedule Tab -->
            <div id="tab-reschedule" class="tab-content">
                <?php 
                $has_reschedule_content = !empty($reschedule_appointments) || !empty($reschedule_requested_followups) || !empty($doctor_set_followups);
                ?>
                <?php if(!$has_reschedule_content): ?>
                    <div class="empty-state">
                        <div class="empty-icon">🔄</div>
                        <h3>No reschedule requests</h3>
                        <p>You don't have any appointments to reschedule.</p>
                    </div>
                <?php else: ?>
                    <!-- Recommended Follow-Up Appointments (Collapsible) -->
                    <?php if(!empty($doctor_set_followups)): ?>
                        <div class="content-card" style="margin-bottom: 2rem;">
                            <div class="category-accordion" onclick="toggleCategory(this)">
                                <div class="category-header">
                                    <div class="category-header-title">
                                        <i class="fas fa-calendar-check" style="color: #ff9800;"></i>
                                        <span>Recommended Follow-Up Appointments</span>
                                        <span style="font-size: 13px; font-weight: 400; color: #666; margin-left: 8px;">
                                            (<?= count($doctor_set_followups) ?>)
                                        </span>
                                    </div>
                                    <span class="category-toggle">+</span>
                                </div>
                                <div class="category-content">
                                    <?php foreach($doctor_set_followups as $followup): 
                                        $proposed_date = date('F j, Y', strtotime($followup['proposed_datetime']));
                                        $proposed_time = date('g:i A', strtotime($followup['proposed_datetime']));
                                        $original_date = $followup['original_appointment_date'] ? date('F j, Y', strtotime($followup['original_appointment_date'])) : 'N/A';
                                    ?>
                                        <div class="appointment-card" style="border: 2px solid #ff9800; border-radius: 8px; padding: 1.5rem; margin-bottom: 1rem; background: #fff3e0;">
                                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
                                                <div>
                                                    <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 5px;">Original Appointment</div>
                                                    <div style="font-weight: 600; color: #333;"><?= htmlspecialchars($original_date) ?></div>
                                                </div>
                                                <div>
                                                    <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 5px;">Recommended Date</div>
                                                    <div style="font-weight: 600; color: #ff9800;"><?= $proposed_date ?></div>
                                                </div>
                                                <div>
                                                    <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 5px;">Recommended Time</div>
                                                    <div style="font-weight: 600; color: #ff9800;"><?= $proposed_time ?></div>
                                                </div>
                                                <div>
                                                    <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 5px;">Reason</div>
                                                    <div style="font-weight: 600; color: #333;"><?= htmlspecialchars($followup['follow_up_reason'] ?? 'N/A') ?></div>
                                                </div>
                                            </div>
                                            <?php if (!empty($followup['doctor_name'])): ?>
                                                <div style="font-size: 13px; color: #666; margin-bottom: 15px;">
                                                    <i class="fas fa-user-md"></i> Recommended by: <?= htmlspecialchars(trim($followup['doctor_name'])) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div style="margin-top: 15px;">
                                                <button onclick="requestReschedule(<?= $followup['id'] ?>)" 
                                                        style="background: #ff9800; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                                    <i class="fas fa-calendar-alt"></i> Request Reschedule
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Reschedule Requests (Collapsed by default) -->
                    <?php if(!empty($reschedule_requested_followups)): ?>
                        <div class="reschedule-section">
                            <button class="collapse-toggle" onclick="toggleRescheduleSection()">
                                <i class="fas fa-chevron-down" id="reschedule-toggle-icon"></i>
                                Reschedule Requests Pending
                                <span class="section-badge"><?= count($reschedule_requested_followups) ?></span>
                            </button>
                            <div class="collapsible-content" id="reschedule-requests-content" style="display: none;">
                                <?php foreach($reschedule_requested_followups as $followup): 
                                    $proposed_date = date('F j, Y', strtotime($followup['proposed_datetime']));
                                    $proposed_time = date('g:i A', strtotime($followup['proposed_datetime']));
                                    $original_date = $followup['original_appointment_date'] ? date('F j, Y', strtotime($followup['original_appointment_date'])) : 'N/A';
                                ?>
                                    <div class="appointment-card-compact reschedule-request">
                                        <div class="card-header-compact">
                                            <div class="card-date-time">
                                                <div class="date-display"><?= date('M d', strtotime($followup['proposed_datetime'])) ?></div>
                                                <div class="time-display"><?= date('g:i A', strtotime($followup['proposed_datetime'])) ?></div>
                                            </div>
                                            <span class="status-badge-compact status-pending">Pending</span>
                                        </div>
                                        <div class="card-body-compact">
                                            <div class="card-info-row">
                                                <span class="info-label">Original:</span>
                                                <span class="info-value"><?= htmlspecialchars($original_date) ?></span>
                                            </div>
                                            <div class="waiting-message">
                                                <i class="fas fa-hourglass-half"></i> Waiting for doctor to provide alternative schedule options
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Rescheduled Appointments -->
                    <?php if(!empty($reschedule_appointments)): ?>
                        <div class="appointments-grid">
                            <?php foreach($reschedule_appointments as $appointment): 
                                $appointment_notes = $appointment['notes'] ?? '';
                                $appointment_reason = '';
                                if ($appointment_notes && preg_match('/Reason:\s*([^\n]+)/i', $appointment_notes, $matches)) {
                                    $appointment_reason = trim($matches[1]);
                                }
                            ?>
                                <div class="appointment-card-compact" data-appointment-id="<?= $appointment['id'] ?>">
                                    <div class="card-header-compact">
                                        <div class="card-date-time">
                                            <div class="date-display"><?= date('M d', strtotime($appointment['start_datetime'])) ?></div>
                                            <div class="time-display"><?= date('g:i A', strtotime($appointment['start_datetime'])) ?></div>
                                        </div>
                                        <div class="card-status-actions">
                                            <span class="status-badge-compact status-<?= $appointment['status'] ?>">
                                                <?= htmlspecialchars($appointment['status']) ?>
                                            </span>
                                            <div class="card-more-menu">
                                                <button class="more-btn" onclick="toggleMoreMenu(event, <?= $appointment['id'] ?>)">⋮</button>
                                                <div class="more-dropdown" id="more-menu-<?= $appointment['id'] ?>" style="display: none;">
                                                    <button onclick="openAppointmentDetailsModal(<?= $appointment['id'] ?>)">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-body-compact">
                                        <div class="card-info-row">
                                            <span class="info-label">Doctor:</span>
                                            <span class="info-value">
                                                <?= !empty($appointment['doctor_name']) && trim($appointment['doctor_name']) ? htmlspecialchars(trim($appointment['doctor_name'])) : 'To be assigned' ?>
                                            </span>
                                        </div>
                                        <?php if($appointment_reason): ?>
                                            <div class="card-info-row">
                                                <span class="info-label">Reason:</span>
                                                <span class="info-value"><?= htmlspecialchars($appointment_reason) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="card-info-row">
                                            <span class="info-label">Duration:</span>
                                            <span class="info-value"><?= $appointment['duration_minutes'] ?> minutes</span>
                                        </div>
                                        <div class="rescheduled-badge">Rescheduled</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <!-- Declined Tab -->
            <div id="tab-declined" class="tab-content">
                <?php if(empty($declined_appointments)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">🚫</div>
                        <h3>No declined appointments</h3>
                        <p>You don't have any declined appointments.</p>
                    </div>
                <?php else: ?>
                    <div class="appointments-grid">
                        <?php foreach($declined_appointments as $appointment): 
                            $appointment_notes = $appointment['notes'] ?? '';
                            $appointment_reason = '';
                            $decline_reason = '';
                            if ($appointment_notes && preg_match('/Reason:\s*([^\n]+)/i', $appointment_notes, $matches)) {
                                $appointment_reason = trim($matches[1]);
                            }
                            if ($appointment_notes && preg_match('/Declined?:\s*([^\n]+)/i', $appointment_notes, $dm)) {
                                $decline_reason = trim($dm[1]);
                            }
                            if (empty($decline_reason) && $appointment_notes && preg_match('/Decline Reason:\s*([^\n]+)/i', $appointment_notes, $dr)) {
                                $decline_reason = trim($dr[1]);
                            }
                        ?>
                            <div class="appointment-card-compact" data-appointment-id="<?= $appointment['id'] ?>">
                                <div class="card-header-compact">
                                    <div class="card-date-time">
                                        <div class="date-display"><?= date('M d', strtotime($appointment['start_datetime'])) ?></div>
                                        <div class="time-display"><?= date('g:i A', strtotime($appointment['start_datetime'])) ?></div>
                                    </div>
                                    <div class="card-status-actions">
                                        <span class="status-badge-compact status-declined">Declined</span>
                                        <div class="card-more-menu">
                                            <button class="more-btn" onclick="toggleMoreMenu(event, <?= $appointment['id'] ?>)">⋮</button>
                                            <div class="more-dropdown" id="more-menu-<?= $appointment['id'] ?>" style="display: none;">
                                                <button onclick="openAppointmentDetailsModal(<?= $appointment['id'] ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body-compact">
                                    <div class="card-info-row">
                                        <span class="info-label">Doctor:</span>
                                        <span class="info-value">
                                            <?= !empty($appointment['doctor_name']) && trim($appointment['doctor_name']) ? htmlspecialchars(trim($appointment['doctor_name'])) : 'To be assigned' ?>
                                        </span>
                                    </div>
                                    <?php if($appointment_reason): ?>
                                        <div class="card-info-row">
                                            <span class="info-label">Reason:</span>
                                            <span class="info-value"><?= htmlspecialchars($appointment_reason) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if($decline_reason): ?>
                                        <div class="card-info-row">
                                            <span class="info-label">Decline reason:</span>
                                            <span class="info-value" style="color:#721c24;"><?= htmlspecialchars($decline_reason) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="card-info-row">
                                        <span class="info-label">Duration:</span>
                                        <span class="info-value"><?= $appointment['duration_minutes'] ?> minutes</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Past Tab -->
            <div id="tab-past" class="tab-content">
                <?php if(empty($past_appointments)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📋</div>
                        <h3>No past appointments</h3>
                        <p>You don't have any past appointments.</p>
                    </div>
                <?php else: ?>
                    <div class="appointments-grid">
                        <?php foreach($past_appointments as $appointment): 
                            $appointment_notes = $appointment['notes'] ?? '';
                            $appointment_reason = '';
                            if ($appointment_notes && preg_match('/Reason:\s*([^\n]+)/i', $appointment_notes, $matches)) {
                                $appointment_reason = trim($matches[1]);
                            }
                        ?>
                            <div class="appointment-card-compact" data-appointment-id="<?= $appointment['id'] ?>">
                                <div class="card-header-compact">
                                    <div class="card-date-time">
                                        <div class="date-display"><?= date('M d', strtotime($appointment['start_datetime'])) ?></div>
                                        <div class="time-display"><?= date('g:i A', strtotime($appointment['start_datetime'])) ?></div>
                                    </div>
                                    <div class="card-status-actions">
                                        <span class="status-badge-compact status-<?= $appointment['status'] ?>">
                                            <?= htmlspecialchars($appointment['status']) ?>
                                        </span>
                                        <div class="card-more-menu">
                                            <button class="more-btn" onclick="toggleMoreMenu(event, <?= $appointment['id'] ?>)">⋮</button>
                                            <div class="more-dropdown" id="more-menu-<?= $appointment['id'] ?>" style="display: none;">
                                                <button onclick="openAppointmentDetailsModal(<?= $appointment['id'] ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body-compact">
                                    <div class="card-info-row">
                                        <span class="info-label">Doctor:</span>
                                        <span class="info-value">
                                            <?= !empty($appointment['doctor_name']) && trim($appointment['doctor_name']) ? htmlspecialchars(trim($appointment['doctor_name'])) : 'To be assigned' ?>
                                        </span>
                                    </div>
                                    <?php if($appointment_reason): ?>
                                        <div class="card-info-row">
                                            <span class="info-label">Reason:</span>
                                            <span class="info-value"><?= htmlspecialchars($appointment_reason) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="card-info-row">
                                        <span class="info-label">Duration:</span>
                                        <span class="info-value"><?= $appointment['duration_minutes'] ?> minutes</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Reschedule Appointment Modal -->
    <div id="rescheduleModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 12px; padding: 2rem; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto; position: relative;">
            <button onclick="closeRescheduleModal()" style="position: absolute; top: 1rem; right: 1rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666;">&times;</button>
            <h2 style="margin: 0 0 1.5rem 0; color: #2e3b4e;">Reschedule Appointment</h2>
            <form id="rescheduleForm" onsubmit="handleRescheduleSubmit(event)">
                <input type="hidden" id="reschedule_appointment_id" name="appointment_id">
                <div style="margin-bottom: 1rem;">
                    <label style="display: block; margin-bottom: 0.5rem; color: #333; font-weight: 600;">New Date*</label>
                    <input type="date" id="reschedule_date" name="new_date" class="form-input" required style="width: 100%; padding: 0.75rem; border: 2px solid #e0e0e0; border-radius: 8px; cursor: pointer;">
                </div>
                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; color: #333; font-weight: 600;">New Time*</label>
                    <select id="reschedule_time" name="new_time" class="form-select" required style="width: 100%; padding: 0.75rem; border: 2px solid #e0e0e0; border-radius: 8px;">
                        <option value="">Select Time</option>
                    </select>
                </div>
                <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                    <button type="button" onclick="closeRescheduleModal()" style="padding: 0.75rem 1.5rem; background: #6c757d; color: white; border: none; border-radius: 8px; cursor: pointer;">Cancel</button>
                    <button type="submit" style="padding: 0.75rem 1.5rem; background: #4CAF50; color: white; border: none; border-radius: 8px; cursor: pointer;">Confirm Reschedule</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Appointment Details Modal -->
    <div id="appointmentDetailsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1001; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 12px; padding: 2rem; max-width: 700px; width: 90%; max-height: 90vh; overflow-y: auto; position: relative;">
            <button onclick="closeAppointmentDetailsModal()" style="position: absolute; top: 1rem; right: 1rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666; z-index: 10;">&times;</button>
            <div id="appointmentDetailsContent">
                <div style="text-align: center; padding: 2rem;">
                    <div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #4CAF50; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                    <p style="margin-top: 1rem; color: #666;">Loading appointment details...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Verification Required Modal (unverified users clicking Book Appointment) -->
    <div id="verificationRequiredModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1002; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 12px; padding: 2rem; max-width: 440px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.15); position: relative;">
            <button type="button" onclick="closeVerificationRequiredModal()" style="position: absolute; top: 1rem; right: 1rem; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666;">&times;</button>
            <div style="text-align: center; padding: 0.5rem 0;">
                <div style="width: 56px; height: 56px; margin: 0 auto 1rem; background: #ffebee; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-id-card" style="font-size: 1.75rem; color: #b71c1c;"></i>
                </div>
                <h2 style="margin: 0 0 0.75rem 0; color: #2e3b4e; font-size: 1.25rem;">Verification required</h2>
                <p style="margin: 0 0 1.5rem 0; color: #555; line-height: 1.5;">Please upload your ID in your Profile first so we can verify your Payatas residency. Once verified, you’ll be able to book appointments, request lab tests, and receive prescriptions.</p>
                <div style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap;">
                    <button type="button" onclick="closeVerificationRequiredModal()" style="padding: 0.75rem 1.5rem; background: #e0e0e0; color: #333; border: none; border-radius: 8px; cursor: pointer;">Close</button>
                    <a href="user_profile.php#verification" style="padding: 0.75rem 1.5rem; background: #4CAF50; color: white; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; display: inline-block;">Upload ID in Profile</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Appointment Calendar System
        let currentCalendarMonth = new Date();
        let selectedDate = null;
        let selectedPeriod = null; // 'am' or 'pm'
        let calendarSlotsData = {};
        
        // Helper function to format date as YYYY-MM-DD in local timezone (not UTC)
        function formatDateLocal(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }
        
        function loadAppointmentCalendar() {
            const container = document.getElementById('appointment-calendar-container');
            if(!container) return;
            
            // Calculate date range (current month + next month)
            const startDate = new Date(currentCalendarMonth.getFullYear(), currentCalendarMonth.getMonth(), 1);
            const endDate = new Date(currentCalendarMonth.getFullYear(), currentCalendarMonth.getMonth() + 2, 0);
            
            // Use local timezone, not UTC
            const startDateStr = formatDateLocal(startDate);
            const endDateStr = formatDateLocal(endDate);
            
            container.innerHTML = '<div style="text-align:center;padding:20px;color:#666;">Loading calendar...</div>';
            
            fetch(`get_appointment_slots.php?start_date=${startDateStr}&end_date=${endDateStr}`)
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        calendarSlotsData = {};
                        data.slots.forEach(slot => {
                            calendarSlotsData[slot.date] = slot;
                        });
                        renderCalendar(data.slots);
                    } else {
                        console.error('API error:', data.message);
                        container.innerHTML = '<div style="text-align:center;padding:20px;color:#d32f2f;">Error loading calendar. Please try again.</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading calendar:', error);
                    container.innerHTML = '<div style="text-align:center;padding:20px;color:#d32f2f;">Error loading calendar. Please try again.</div>';
                });
        }
        
        function renderCalendar(slots) {
            const container = document.getElementById('appointment-calendar-container');
            if(!container) return;
            
            // Create month header
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                              'July', 'August', 'September', 'October', 'November', 'December'];
            const monthName = monthNames[currentCalendarMonth.getMonth()];
            const year = currentCalendarMonth.getFullYear();
            
            // Get first day of month and number of days
            const firstDay = new Date(currentCalendarMonth.getFullYear(), currentCalendarMonth.getMonth(), 1);
            const lastDay = new Date(currentCalendarMonth.getFullYear(), currentCalendarMonth.getMonth() + 1, 0);
            const daysInMonth = lastDay.getDate();
            const startingDayOfWeek = firstDay.getDay(); // 0 = Sunday
            
            let html = `
                <div class="calendar-month-header">
                    <button class="calendar-nav-btn" onclick="previousMonth()" ${currentCalendarMonth <= new Date() ? 'disabled' : ''}>← Previous</button>
                    <div class="calendar-month-title">${monthName} ${year}</div>
                    <button class="calendar-nav-btn" onclick="nextMonth()">Next →</button>
                </div>
                <div class="appointment-calendar">
                    <div class="calendar-day-header">Sun</div>
                    <div class="calendar-day-header">Mon</div>
                    <div class="calendar-day-header">Tue</div>
                    <div class="calendar-day-header">Wed</div>
                    <div class="calendar-day-header">Thu</div>
                    <div class="calendar-day-header">Fri</div>
                    <div class="calendar-day-header">Sat</div>
            `;
            
            // Add empty cells for days before month starts
            for(let i = 0; i < startingDayOfWeek; i++) {
                html += '<div class="calendar-day calendar-weekend"></div>';
            }
            
            // Add days of the month
            for(let day = 1; day <= daysInMonth; day++) {
                const date = new Date(currentCalendarMonth.getFullYear(), currentCalendarMonth.getMonth(), day);
                // Use local timezone, not UTC to avoid date shifting
                const dateStr = formatDateLocal(date);
                const dayOfWeek = date.getDay(); // 0 = Sunday, 1 = Monday, 6 = Saturday
                const isWeekend = dayOfWeek === 0 || dayOfWeek === 6;
                const isWeekday = dayOfWeek >= 1 && dayOfWeek <= 5; // Monday (1) through Friday (5)
                const slotData = calendarSlotsData[dateStr];
                
                
                if(isWeekend) {
                    html += `<div class="calendar-day calendar-weekend">
                        <div class="calendar-day-number">${day}</div>
                    </div>`;
                } else if(isWeekday && slotData) {
                    // Check if slots are blocked
                    const amBlocked = slotData.am.is_blocked || false;
                    const pmBlocked = slotData.pm.is_blocked || false;
                    
                    // Disable if blocked, full, past date, or too close
                    const amDisabled = amBlocked || slotData.am.is_full || isDateInPast(dateStr) || isDateTooClose(dateStr, 'am');
                    const pmDisabled = pmBlocked || slotData.pm.is_full || isDateInPast(dateStr) || isDateTooClose(dateStr, 'pm');
                    
                    const amSelected = selectedDate === dateStr && selectedPeriod === 'am';
                    const pmSelected = selectedDate === dateStr && selectedPeriod === 'pm';
                    
                    // Determine status for display
                    let amStatus = 'available';
                    let amStatusText = '';
                    if (amBlocked) {
                        amStatus = 'blocked';
                        amStatusText = 'Blocked';
                    } else if (slotData.am.is_full) {
                        amStatus = 'full';
                        amStatusText = 'Full';
                    } else {
                        amStatusText = 'Available';
                    }
                    
                    let pmStatus = 'available';
                    let pmStatusText = '';
                    if (pmBlocked) {
                        pmStatus = 'blocked';
                        pmStatusText = 'Blocked';
                    } else if (slotData.pm.is_full) {
                        pmStatus = 'full';
                        pmStatusText = 'Full';
                    } else {
                        pmStatusText = 'Available';
                    }
                    
                    html += `<div class="calendar-day">
                        <div class="calendar-day-number">${day}</div>
                        <div class="calendar-slot am ${amStatus} ${amDisabled ? 'disabled' : ''} ${amSelected ? 'selected' : ''}" 
                             onclick="${amDisabled ? '' : `selectSlot('${dateStr}', 'am')`}" 
                             data-date="${dateStr}" data-period="am"
                             title="${amStatusText}: ${slotData.am.booked} / ${slotData.am.total}">
                            <div class="calendar-slot-label">AM</div>
                            <div class="calendar-slot-count">${slotData.am.booked} / ${slotData.am.total}</div>
                            ${amStatus !== 'available' ? `<div class="calendar-slot-status">${amStatusText}</div>` : ''}
                        </div>
                        <div class="calendar-slot pm ${pmStatus} ${pmDisabled ? 'disabled' : ''} ${pmSelected ? 'selected' : ''}" 
                             onclick="${pmDisabled ? '' : `selectSlot('${dateStr}', 'pm')`}" 
                             data-date="${dateStr}" data-period="pm"
                             title="${pmStatusText}: ${slotData.pm.booked} / ${slotData.pm.total}">
                            <div class="calendar-slot-label">PM</div>
                            <div class="calendar-slot-count">${slotData.pm.booked} / ${slotData.pm.total}</div>
                            ${pmStatus !== 'available' ? `<div class="calendar-slot-status">${pmStatusText}</div>` : ''}
                        </div>
                    </div>`;
                } else if(isWeekday) {
                    // Weekday (Monday-Friday) but no slot data - create default slot data
                    // This ensures all weekdays are displayed even if API hasn't returned data yet
                    const defaultSlotData = {
                        am: {
                            booked: 0,
                            available: 30,
                            total: 30,
                            is_full: false,
                            is_blocked: false
                        },
                        pm: {
                            booked: 0,
                            available: 15,
                            total: 15,
                            is_full: false,
                            is_blocked: false
                        }
                    };
                    
                    const amDisabled = isDateInPast(dateStr) || isDateTooClose(dateStr, 'am');
                    const pmDisabled = isDateInPast(dateStr) || isDateTooClose(dateStr, 'pm');
                    const amSelected = selectedDate === dateStr && selectedPeriod === 'am';
                    const pmSelected = selectedDate === dateStr && selectedPeriod === 'pm';
                    
                    html += `<div class="calendar-day">
                        <div class="calendar-day-number">${day}</div>
                        <div class="calendar-slot am ${amDisabled ? 'disabled' : ''} ${amSelected ? 'selected' : ''}" 
                             onclick="${amDisabled ? '' : `selectSlot('${dateStr}', 'am')`}" 
                             data-date="${dateStr}" data-period="am"
                             title="Available: ${defaultSlotData.am.booked} / ${defaultSlotData.am.total}">
                            <div class="calendar-slot-label">AM</div>
                            <div class="calendar-slot-count">${defaultSlotData.am.booked} / ${defaultSlotData.am.total}</div>
                        </div>
                        <div class="calendar-slot pm ${pmDisabled ? 'disabled' : ''} ${pmSelected ? 'selected' : ''}" 
                             onclick="${pmDisabled ? '' : `selectSlot('${dateStr}', 'pm')`}" 
                             data-date="${dateStr}" data-period="pm"
                             title="Available: ${defaultSlotData.pm.booked} / ${defaultSlotData.pm.total}">
                            <div class="calendar-slot-label">PM</div>
                            <div class="calendar-slot-count">${defaultSlotData.pm.booked} / ${defaultSlotData.pm.total}</div>
                        </div>
                    </div>`;
                }
            }
            
            html += '</div>';
            container.innerHTML = html;
            
            // Update time slots based on selection
            if(selectedDate && selectedPeriod) {
                updateTimeSlotsForPeriod(selectedDate, selectedPeriod);
            }
        }
        
        function previousMonth() {
            currentCalendarMonth.setMonth(currentCalendarMonth.getMonth() - 1);
            loadAppointmentCalendar();
        }
        
        function nextMonth() {
            currentCalendarMonth.setMonth(currentCalendarMonth.getMonth() + 1);
            loadAppointmentCalendar();
        }
        
        function isDateInPast(dateStr) {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const checkDate = new Date(dateStr);
            return checkDate < today;
        }
        
        function isDateTooClose(dateStr, period) {
            const now = new Date();
            const checkDate = new Date(dateStr);
            const isToday = checkDate.toDateString() === now.toDateString();
            
            if(!isToday) return false;
            
            // Minimum advance booking rule: 1 hour lead time
            const minAllowedTime = new Date(now.getTime() + (1 * 60 * 60 * 1000));
            
            // Master time slot list (matches CLINIC_TIME_SLOTS from clinic_time_slots.php):
            // AM: 07:00, 07:30, 08:00, 08:30, 09:00, 09:30, 10:00, 10:30, 11:00, 11:30
            // PM: 13:00, 13:30, 14:00, 14:30, 15:00
            const lastSlotStart = (period === 'am')
                ? new Date(dateStr + 'T11:30:00')
                : new Date(dateStr + 'T15:00:00');
            
            // If even the last slot starts before minAllowedTime, the whole period is too close.
            return lastSlotStart < minAllowedTime;
        }
        
        function selectSlot(dateStr, period) {
            // Check if slot is blocked or unavailable
            const slotData = calendarSlotsData[dateStr];
            if (!slotData) {
                alert('This date is not available for booking.');
                return;
            }
            
            const slot = slotData[period];
            if (slot.is_blocked) {
                alert('This time slot is blocked and cannot be booked. Please select another time.');
                return;
            }
            
            if (slot.is_full) {
                alert('This time slot is full. Please select another time.');
                return;
            }
            
            if (isDateInPast(dateStr)) {
                alert('Cannot book appointments in the past. Please select a future date.');
                return;
            }
            
            if (isDateTooClose(dateStr, period)) {
                alert('Appointments must be booked at least 1 hour in advance.');
                return;
            }
            
            selectedDate = dateStr;
            selectedPeriod = period;
            
            // Update calendar to show selection
            renderCalendar(Object.values(calendarSlotsData));
            
            // Set the date input
            const dateInput = document.getElementById('preferred_date');
            if(dateInput) {
                dateInput.value = dateStr;
            }
            
            // Show time selection container
            const timeContainer = document.getElementById('time-selection-container');
            if(timeContainer) {
                timeContainer.style.display = 'grid';
            }
            
            // Update time slots for the selected period
            updateTimeSlotsForPeriod(dateStr, period);
            if (typeof updateBookingSubmitButton === 'function') updateBookingSubmitButton();
        }
        
        // Prevent multiple simultaneous calls to updateTimeSlotsForPeriod
        let isUpdatingTimeSlots = false;
        
        async function updateTimeSlotsForPeriod(dateStr, period) {
            // Prevent concurrent calls
            if (isUpdatingTimeSlots) {
                console.log('Time slots update already in progress, skipping...');
                return;
            }
            
            const timeSel = document.getElementById('preferred_time');
            if(!timeSel) return;
            
            isUpdatingTimeSlots = true;
            
            try {
                // Clear dropdown completely - remove all options
                timeSel.innerHTML = '';
                // Also clear any existing options by removing all child nodes
                while (timeSel.firstChild) {
                    timeSel.removeChild(timeSel.firstChild);
                }
                
                const now = new Date();
                const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                const selectedDateObj = new Date(dateStr + 'T00:00:00');
                const isToday = selectedDateObj.getTime() === today.getTime();
                const minAllowedTime = new Date(now.getTime() + (1 * 60 * 60 * 1000));
                
                // Fetch per-slot capacity + server-side blocking logic
                let slotData = [];
                try {
                    const res = await fetch(`get_time_slot_capacities.php?date=${encodeURIComponent(dateStr)}&period=${encodeURIComponent(period)}`);
                    const data = await res.json();
                    if (data && data.success && Array.isArray(data.slots)) {
                        slotData = data.slots;
                    }
                } catch (e) {
                    console.warn('Failed to load slot capacities, falling back to client-only rules.', e);
                }
                
                // Fallback slot list - MUST use master time slot list if API fails
                // This matches CLINIC_TIME_SLOTS from clinic_time_slots.php
                if (!slotData.length) {
                    const masterTimeSlots = [
                        '07:00', '07:30', '08:00', '08:30', '09:00', '09:30',
                        '10:00', '10:30', '11:00', '11:30',
                        '13:00', '13:30', '14:00', '14:30', '15:00'
                    ];
                    // Filter by period
                    const times = masterTimeSlots.filter(t => {
                        const hour = parseInt(t.split(':')[0]);
                        if (period === 'am') {
                            return hour >= 7 && hour < 12;
                        } else {
                            return hour >= 13 && hour <= 15;
                        }
                    });
                    slotData = times.map(t => ({
                        time: t,
                        available: 3,
                        capacity: 3,
                        disabled: false
                    }));
                }
                
                // Deduplicate slots by time to ensure each time appears only once
                // Use Map to preserve the last occurrence of each time (in case of duplicates)
                const slotMap = new Map();
                for (const s of slotData) {
                    const t = s.time; // HH:MM
                    // Only keep the first occurrence, or update if we want the latest data
                    if (!slotMap.has(t)) {
                        slotMap.set(t, s);
                    }
                }
                const uniqueSlotData = Array.from(slotMap.values());
            
            // Track added option values to prevent duplicates
            const addedValues = new Set();
            
            for (const s of uniqueSlotData) {
                const t = s.time; // HH:MM
                
                // Skip if this value was already added
                if (addedValues.has(t)) {
                    console.warn(`Duplicate time slot detected and skipped: ${t}`);
                    continue;
                }
                addedValues.add(t);
                
                const slotTime = new Date(dateStr + 'T' + t + ':00');
                const hour = slotTime.getHours();
                const min = slotTime.getMinutes();
                const hour12 = ((hour + 11) % 12) + 1;
                const ampm = hour < 12 ? 'AM' : 'PM';
                const label = `${hour12}:${String(min).padStart(2,'0')} ${ampm}`;
                
                // Check if option with this value already exists in dropdown
                const existingOption = timeSel.querySelector(`option[value="${t}"]`);
                if (existingOption) {
                    console.warn(`Option with value ${t} already exists, skipping...`);
                    continue;
                }
                
                const opt = document.createElement('option');
                opt.value = t;
                
                // Slot Capacity per Hour (per slot start time): "(3 slots available)"
                const available = (typeof s.available === 'number') ? s.available : 3;
                opt.textContent = `${label} (${available} slots available)`;
                
                // Past time restriction + minimum lead time (client-side)
                if (isToday) {
                    if (slotTime <= now) {
                        opt.disabled = true;
                        opt.textContent = `${label} (Unavailable)`;
                    } else if (slotTime < minAllowedTime) {
                        opt.disabled = true;
                        opt.textContent = `${label} (Unavailable)`;
                    }
                }
                
                // Server-reported disabled state (capacity/blocked/etc)
                if (s.disabled) {
                    opt.disabled = true;
                    if (s.disabled_reason === 'full') opt.textContent = `${label} (0 slots available)`;
                    else opt.textContent = `${label} (Unavailable)`;
                }
                
                timeSel.appendChild(opt);
            }
            } finally {
                // Always reset the flag, even if there's an error
                isUpdatingTimeSlots = false;
            }
        }
        
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
            
            // Appointment booking form validation: enable submit only when all required fields are filled
            function validateBookingForm() {
                const missing = [];
                const reasonSel = document.getElementById('reason_select');
                const otherReason = document.getElementById('other_reason_input');
                const prefDate = document.getElementById('preferred_date');
                const prefTime = document.getElementById('preferred_time');
                const timeContainer = document.getElementById('time-selection-container');
                const firstName = document.getElementById('first_name');
                const lastName = document.getElementById('last_name');
                const phone = document.querySelector('input[name="phone"]');
                const confirmCheck = document.getElementById('booking_confirm_checkbox');
                const bookingDependent = document.getElementById('booking_dependent');
                const dependentSelect = document.getElementById('dependent_select');

                if (!reasonSel || !reasonSel.value || reasonSel.value.trim() === '') {
                    missing.push('Check-up type (Chief Complaint)');
                } else if (reasonSel.value === 'others' && (!otherReason || !otherReason.value.trim())) {
                    missing.push('Please specify reason (Others)');
                }
                const hasDate = prefDate && prefDate.value && prefDate.value.trim() !== '';
                const hasTime = prefTime && prefTime.value && prefTime.value.trim() !== '';
                const timeVisible = timeContainer && timeContainer.style.display !== 'none';
                if (!hasDate || !timeVisible) {
                    missing.push('Date');
                }
                if (!hasTime) {
                    missing.push('Time');
                }
                if (!firstName || !firstName.value.trim()) missing.push('First name');
                if (!lastName || !lastName.value.trim()) missing.push('Last name');
                if (!phone || !phone.value.trim() || phone.value.length < 11) missing.push('Contact number (11 digits)');
                if (bookingDependent && bookingDependent.checked && (!dependentSelect || !dependentSelect.value)) {
                    missing.push('Dependent');
                }
                if (!confirmCheck || !confirmCheck.checked) missing.push('Confirmation checkbox');

                return { valid: missing.length === 0, missing };
            }

            function updateBookingSubmitButton() {
                const btn = document.getElementById('booking-submit-btn');
                const wrapper = document.getElementById('booking-submit-wrapper');
                const msgEl = document.getElementById('booking-validation-msg');
                if (!btn) return;
                const result = validateBookingForm();
                btn.disabled = !result.valid;
                btn.style.pointerEvents = btn.disabled ? 'none' : '';
                const tooltipText = result.valid ? '' : (result.missing.length ? 'Missing: ' + result.missing.join(', ') : 'You must select a date and check-up type before confirming.');
                btn.title = tooltipText;
                if (wrapper) wrapper.title = tooltipText;
                if (msgEl) {
                    msgEl.style.display = 'none';
                    msgEl.textContent = '';
                }
            }

            document.addEventListener('DOMContentLoaded', function(){
                window.notificationSystem = new NotificationSystem();
                // Open booking form when open=1 or clicking the CTA
                const url = new URL(window.location);
                const open = url.searchParams.get('open');
                const prefillDate = url.searchParams.get('prefill_date');
                const prefillTime = url.searchParams.get('prefill_time');
                const prefillDuration = url.searchParams.get('prefill_duration');
                const form = document.getElementById('appointment_booking_form');
                const openBtn = document.getElementById('open-booking');
                
                // Reload calendar if there's a success message (after booking)
                <?php if($success_msg): ?>
                if(typeof loadAppointmentCalendar === 'function'){
                    setTimeout(() => {
                        loadAppointmentCalendar();
                    }, 500);
                }
                <?php endif; ?>
                
                function showForm(){ 
                    if(form){ 
                        form.style.display='block'; 
                        form.scrollIntoView({behavior:'smooth'});
                        // Load calendar when form is shown
                        if(typeof loadAppointmentCalendar === 'function'){
                            loadAppointmentCalendar();
                        }
                        
                        // Pre-fill if parameters are provided
                        if(prefillDate && prefillTime) {
                            setTimeout(() => {
                                prefillBookingForm(prefillDate, prefillTime, prefillDuration ? parseInt(prefillDuration) : 30);
                            }, 500);
                        }
                    }
                }
                if(open === '1' && <?= $residency_verified ? 'true' : 'false' ?>) { showForm(); }
                if(openBtn){ openBtn.addEventListener('click', function(e){ e.preventDefault(); showForm(); }); }
                var restrictedBtn = document.getElementById('open-booking-restricted');
                if(restrictedBtn){
                    restrictedBtn.addEventListener('click', function(){
                        openVerificationRequiredModal();
                    });
                }
                
                // Initialize calendar when form is visible
                const calendarContainer = document.getElementById('appointment-calendar-container');
                if(calendarContainer && form && form.style.display !== 'none'){
                    loadAppointmentCalendar();
                }

                // Function to update time slots based on selected date
                function updateTimeSlots() {
                    const timeSel = document.getElementById('preferred_time');
                    const dateInput = document.getElementById('preferred_date');
                    if(!timeSel || !dateInput) return;
                    
                    // When using the calendar selector, we should only show slots for the selected AM/PM period
                    const selectedDate = dateInput.value;
                    if (selectedDate && typeof selectedPeriod !== 'undefined' && selectedPeriod) {
                        // Delegate to the authoritative per-period renderer (includes capacity labels)
                        updateTimeSlotsForPeriod(selectedDate, selectedPeriod);
                        return;
                    }
                    
                    // No date/period chosen yet — show placeholder
                    timeSel.innerHTML = '';
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = 'Select a date first';
                    opt.disabled = true;
                    opt.selected = true;
                    timeSel.appendChild(opt);
                }
                
                // Populate time slots initially
                const timeSel = document.getElementById('preferred_time');
                if(timeSel){
                    updateTimeSlots();
                }

                // Enforce Monday-Friday dates only
                const dateInput = document.getElementById('preferred_date');
                if(dateInput){
                    const today = new Date();
                    const yyyy = today.getFullYear();
                    const mm = String(today.getMonth()+1).padStart(2,'0');
                    const dd = String(today.getDate()).padStart(2,'0');
                    dateInput.min = `${yyyy}-${mm}-${dd}`;
                    dateInput.addEventListener('keydown', function(e){ e.preventDefault(); });
                    dateInput.addEventListener('click', function(){
                        if(this.showPicker){ this.showPicker(); }
                    });
                    dateInput.addEventListener('change', function(){
                        const d = new Date(this.value);
                        const day = d.getDay(); // 0 Sun, 6 Sat
                        if(day === 0 || day === 6){
                            alert('Please select a weekday (Monday to Friday).');
                            this.value = '';
                            updateTimeSlots(); // Update time slots when date is cleared
                        } else {
                            updateTimeSlots(); // Update time slots when valid date is selected
                        }
                    });
                    dateInput.addEventListener('input', function(){
                        const d = new Date(this.value);
                        const day = d.getDay();
                        if(day === 0 || day === 6){
                            this.dispatchEvent(new Event('change'));
                        } else if(this.value){
                            updateTimeSlots(); // Update time slots on input
                        }
                    });
                }

                // Compose start_datetime on submit from date + time
                const bookingForm = document.getElementById('booking-form');
                if(bookingForm){
                    bookingForm.addEventListener('submit', function(e){
                        const d = document.getElementById('preferred_date').value;
                        const t = document.getElementById('preferred_time').value;
                        if(!d || !t){ return; }
                        document.getElementById('start_datetime').value = `${d}T${t}`;
                    });
                }

                // Handle reason select
                const reasonSelect = document.getElementById('reason_select');
                const otherReasonContainer = document.getElementById('other-reason-container');
                const otherReasonInput = document.getElementById('other_reason_input');
                if(reasonSelect){
                    reasonSelect.addEventListener('change', function(){
                        if(this.value === 'others'){
                            otherReasonContainer.style.display = 'block';
                            otherReasonInput.required = true;
                            otherReasonInput.focus();
                        } else {
                            otherReasonContainer.style.display = 'none';
                            otherReasonInput.required = false;
                            otherReasonInput.value = '';
                        }
                    });
                }

                // Restrict contact number
                const phoneInput = document.querySelector('input[name="phone"]');
                if(phoneInput){
                    phoneInput.addEventListener('input', function(){
                        this.value = this.value.replace(/\D/g,'').slice(0,11);
                    });
                }

                // Handle booking for self/dependent selection
                const bookingSelf = document.getElementById('booking_self');
                const bookingDependent = document.getElementById('booking_dependent');
                const dependentSelection = document.getElementById('dependent-selection');
                const dependentSelect = document.getElementById('dependent_select');
                const firstNameInput = document.getElementById('first_name');
                const middleNameInput = document.getElementById('middle_name');
                const lastNameInput = document.getElementById('last_name');
                const phoneInputField = document.getElementById('phone');

                // Auto-fill when booking for self - always use account owner's data from users table
                <?php if($userData): ?>
                if(bookingSelf){
                    bookingSelf.addEventListener('change', function(){
                        if(this.checked){
                            dependentSelection.style.display = 'none';
                            dependentSelect.value = '';
                            dependentSelect.removeAttribute('required');
                            
                            // Auto-fill from account owner's data (users table only - not patients table)
                            <?php 
                            $autoFirstName = $userData['first_name'] ?? '';
                            $autoMiddleName = $userData['middle_name'] ?? '';
                            $autoLastName = $userData['last_name'] ?? '';
                            $autoPhone = $userData['contact_no'] ?? '';
                            ?>
                            if(firstNameInput) firstNameInput.value = '<?=htmlspecialchars($autoFirstName, ENT_QUOTES)?>';
                            if(middleNameInput) middleNameInput.value = '<?=htmlspecialchars($autoMiddleName ?? '', ENT_QUOTES)?>';
                            if(lastNameInput) lastNameInput.value = '<?=htmlspecialchars($autoLastName, ENT_QUOTES)?>';
                            if(phoneInputField) phoneInputField.value = '<?=htmlspecialchars($autoPhone, ENT_QUOTES)?>';
                        }
                    });
                    
                    // Auto-fill on page load if self is selected
                    if(bookingSelf.checked){
                        bookingSelf.dispatchEvent(new Event('change'));
                    }
                }
                <?php endif; ?>

                // Handle dependent selection
                if(bookingDependent){
                    bookingDependent.addEventListener('change', function(){
                        if(this.checked){
                            dependentSelection.style.display = 'block';
                            dependentSelect.setAttribute('required', 'required');
                            
                            // Clear name fields
                            if(firstNameInput) firstNameInput.value = '';
                            if(middleNameInput) middleNameInput.value = '';
                            if(lastNameInput) lastNameInput.value = '';
                            if(phoneInputField) phoneInputField.value = '';
                        }
                    });
                }

                // Auto-fill when dependent is selected
                if(dependentSelect){
                    dependentSelect.addEventListener('change', function(){
                        const selectedOption = this.options[this.selectedIndex];
                        if(selectedOption && selectedOption.value){
                            if(firstNameInput) firstNameInput.value = selectedOption.getAttribute('data-first') || '';
                            if(middleNameInput) middleNameInput.value = selectedOption.getAttribute('data-middle') || '';
                            if(lastNameInput) lastNameInput.value = selectedOption.getAttribute('data-last') || '';
                            // Phone stays empty for dependents (use parent's contact)
                            if(phoneInputField) phoneInputField.value = '<?=htmlspecialchars($userData['contact_no'] ?? '', ENT_QUOTES)?>';
                        }
                        updateBookingSubmitButton();
                    });
                }

                // Validation: enable Book Appointment button only when all required fields are filled
                const bookingSubmitBtn = document.getElementById('booking-submit-btn');
                const bookingSubmitWrapper = document.getElementById('booking-submit-wrapper');
                const bookingValidationMsg = document.getElementById('booking-validation-msg');
                function attachBookingValidation() {
                    const inputs = ['first_name', 'last_name', 'reason_select', 'other_reason_input', 'preferred_date', 'preferred_time'];
                    const ev = ['input', 'change'];
                    inputs.forEach(function(id) {
                        const el = document.getElementById(id);
                        if (el) ev.forEach(function(e) { el.addEventListener(e, updateBookingSubmitButton); });
                    });
                    if(phoneInputField) { ev.forEach(function(e) { phoneInputField.addEventListener(e, updateBookingSubmitButton); }); }
                    const confirmCb = document.getElementById('booking_confirm_checkbox');
                    if(confirmCb) confirmCb.addEventListener('change', updateBookingSubmitButton);
                    if(bookingSelf) bookingSelf.addEventListener('change', updateBookingSubmitButton);
                    if(bookingDependent) bookingDependent.addEventListener('change', updateBookingSubmitButton);
                }
                attachBookingValidation();
                updateBookingSubmitButton();

                // When user tries to proceed with disabled button, show message listing missing fields
                if(bookingSubmitWrapper && bookingSubmitBtn) {
                    bookingSubmitWrapper.addEventListener('click', function(e) {
                        if(bookingSubmitBtn.disabled) {
                            e.preventDefault();
                            e.stopPropagation();
                            const result = validateBookingForm();
                            const msg = result.missing.length ? 'Please complete the following before confirming:\n\n• ' + result.missing.join('\n• ') : 'You must select a date and check-up type before confirming.';
                            if(bookingValidationMsg) {
                                bookingValidationMsg.textContent = result.missing.length ? 'Missing: ' + result.missing.join(', ') : 'You must select a date and check-up type before confirming.';
                                bookingValidationMsg.style.display = 'block';
                                bookingValidationMsg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                            } else {
                                alert(msg);
                            }
                        }
                    });
                }
            });
        })();
    </script>
    <script>
        // Tab switching functionality
        function switchTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');
            
            // Update tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(`tab-${tabName}`).classList.add('active');
            
            // Close any open more menus
            document.querySelectorAll('.more-dropdown').forEach(menu => {
                menu.style.display = 'none';
            });
        }
        
        // Toggle category accordion (for collapsible sections)
        function toggleCategory(element) {
            const isActive = element.classList.contains('active');
            if (!isActive) {
                element.classList.add('active');
            } else {
                element.classList.remove('active');
            }
        }
        
        // More menu toggle
        function toggleMoreMenu(event, appointmentId) {
            event.stopPropagation();
            const menu = document.getElementById(`more-menu-${appointmentId}`);
            const isVisible = menu.style.display === 'block';
            
            // Close all other menus
            document.querySelectorAll('.more-dropdown').forEach(m => {
                if (m.id !== `more-menu-${appointmentId}`) {
                    m.style.display = 'none';
                }
            });
            
            // Toggle current menu
            menu.style.display = isVisible ? 'none' : 'block';
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.card-more-menu')) {
                document.querySelectorAll('.more-dropdown').forEach(menu => {
                    menu.style.display = 'none';
                });
            }
        });
        
        // Toggle reschedule section
        function toggleRescheduleSection() {
            const content = document.getElementById('reschedule-requests-content');
            const toggle = event.target.closest('.collapse-toggle');
            const icon = document.getElementById('reschedule-toggle-icon');
            
            if (content.style.display === 'none') {
                content.style.display = 'block';
                toggle.classList.add('expanded');
                if (icon) icon.style.transform = 'rotate(180deg)';
            } else {
                content.style.display = 'none';
                toggle.classList.remove('expanded');
                if (icon) icon.style.transform = 'rotate(0deg)';
            }
        }
        
        function updateFilter() {
            const statusFilter = document.getElementById('status-filter')?.value;
            const dateFilter = document.getElementById('date-filter')?.value;
            
            if (statusFilter && dateFilter) {
                const url = new URL(window.location);
                url.searchParams.set('filter', statusFilter);
                url.searchParams.set('date', dateFilter);
                
                window.location.href = url.toString();
            }
        }
        
        async function cancelAppointment(id) {
            const confirmed = await confirm('Are you sure you want to cancel this appointment?');
            if (confirmed) {
                // Create form and submit
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'Cancel_appointment.php';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'appointment_id';
                input.value = id;
                
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function openAppointmentDetailsModal(appointmentId) {
            const modal = document.getElementById('appointmentDetailsModal');
            const content = document.getElementById('appointmentDetailsContent');
            
            // Show modal with loading state
            modal.style.display = 'flex';
            content.innerHTML = `
                <div style="text-align: center; padding: 2rem;">
                    <div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #4CAF50; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                    <p style="margin-top: 1rem; color: #666;">Loading appointment details...</p>
                </div>
            `;
            
            // Fetch appointment details
            fetch(`get_appointment_details.php?appointment_id=${appointmentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.appointment) {
                        const apt = data.appointment;
                        renderAppointmentDetails(apt);
                    } else {
                        content.innerHTML = `
                            <div style="text-align: center; padding: 2rem;">
                                <p style="color: #d32f2f;">Error loading appointment details. Please try again.</p>
                                <button onclick="closeAppointmentDetailsModal()" style="margin-top: 1rem; padding: 0.5rem 1rem; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">Close</button>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    content.innerHTML = `
                        <div style="text-align: center; padding: 2rem;">
                            <p style="color: #d32f2f;">Error loading appointment details. Please try again.</p>
                            <button onclick="closeAppointmentDetailsModal()" style="margin-top: 1rem; padding: 0.5rem 1rem; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">Close</button>
                        </div>
                    `;
                });
        }

        function renderAppointmentDetails(apt) {
            const content = document.getElementById('appointmentDetailsContent');
            
            // Format date and time
            const appointmentDate = apt.start_datetime ? new Date(apt.start_datetime) : null;
            const formattedDate = appointmentDate ? appointmentDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
            const formattedTime = appointmentDate ? appointmentDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }) : 'N/A';
            
            // Extract reason and notes
            let appointmentReason = '';
            let appointmentNotes = '';
            let declineReason = '';
            let suggestedSchedule = '';
            
            if (apt.notes) {
                // Extract appointment reason (for non-declined appointments)
                if (apt.notes.match(/Reason:\s*([^\n]+)/i)) {
                    appointmentReason = apt.notes.match(/Reason:\s*([^\n]+)/i)[1].trim();
                }
                
                // Extract decline reason (for declined appointments)
                if (apt.notes.match(/Declined:\s*([^\n]+)/i)) {
                    declineReason = apt.notes.match(/Declined:\s*([^\n]+)/i)[1].trim();
                }
                
                // Extract suggested alternative schedule
                if (apt.notes.match(/Suggested schedule:\s*([^\n]+)/i)) {
                    suggestedSchedule = apt.notes.match(/Suggested schedule:\s*([^\n]+)/i)[1].trim();
                }
                
                // Extract general notes
                if (apt.notes.match(/Notes:\s*(.+)/is)) {
                    appointmentNotes = apt.notes.match(/Notes:\s*(.+)/is)[1].trim();
                    appointmentNotes = appointmentNotes.replace(/\[Dependent of:\s*[^\]]+\]\s*/i, '');
                } else {
                    // If no "Notes:" prefix, treat entire notes as appointmentNotes
                    appointmentNotes = apt.notes.trim();
                }
                
                // For declined appointments, remove decline reason and suggested schedule from notes
                if ((apt.status || '').toLowerCase() === 'declined') {
                    // Remove "Declined: ..." from notes
                    appointmentNotes = appointmentNotes.replace(/Declined:\s*[^\n]+/gi, '').trim();
                    // Remove "Suggested schedule: ..." from notes
                    appointmentNotes = appointmentNotes.replace(/Suggested schedule:\s*[^\n]+/gi, '').trim();
                    // Clean up any extra whitespace or newlines
                    appointmentNotes = appointmentNotes.replace(/\n\s*\n/g, '\n').trim();
                }
            }
            
            // Status badge styling
            let statusStyle = '';
            let statusText = apt.status || 'unknown';
            switch(statusText) {
                case 'pending': statusStyle = 'background: #fff3cd; color: #856404; border: 1px solid #ffeaa7;'; break;
                case 'approved': statusStyle = 'background: #d4edda; color: #155724; border: 1px solid #c3e6cb;'; break;
                case 'declined': statusStyle = 'background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;'; break;
                case 'completed': statusStyle = 'background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb;'; break;
                case 'missed': statusStyle = 'background: #e2e3e5; color: #383d41; border: 1px solid #d6d8db;'; break;
                default: statusStyle = 'background: #f8f9fa; color: #6c757d; border: 1px solid #dee2e6;';
            }
            
            // Patient name
            const patientName = [apt.first_name, apt.middle_name, apt.last_name].filter(Boolean).join(' ') || 'Appointment';
            
            let html = `
                <h2 style="margin: 0 0 1.5rem 0; color: #2e3b4e; border-bottom: 2px solid #e0e0e0; padding-bottom: 1rem;">Appointment Details</h2>
                
                <div style="margin-bottom: 1.5rem;">
                    <h3 style="margin: 0 0 1rem 0; color: #333; font-size: 1.2rem;">${escapeHtml(patientName)}</h3>
                    <div style="display: grid; gap: 0.75rem;">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <span style="font-weight: 600; color: #666; min-width: 120px;">📅 Date:</span>
                            <span style="color: #333;">${escapeHtml(formattedDate)}</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <span style="font-weight: 600; color: #666; min-width: 120px;">⏰ Time:</span>
                            <span style="color: #333;">${escapeHtml(formattedTime)}</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <span style="font-weight: 600; color: #666; min-width: 120px;">⏱️ Duration:</span>
                            <span style="color: #333;">${apt.duration_minutes || 'N/A'} minutes</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <span style="font-weight: 600; color: #666; min-width: 120px;">👨‍⚕️ Doctor:</span>
                            <span style="color: #333;">${escapeHtml(apt.doctor_name && apt.doctor_name.trim() ? apt.doctor_name.trim() : 'To be assigned')}</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <span style="font-weight: 600; color: #666; min-width: 120px;">🔖 Appointment Code:</span>
                            <span style="color: #333; font-family: monospace; font-weight: 600;">${escapeHtml(apt.appointment_code || '—')}</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <span style="font-weight: 600; color: #666; min-width: 120px;">Status:</span>
                            <span style="padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600; text-transform: uppercase; ${statusStyle}">${escapeHtml(statusText)}</span>
                        </div>
                        ${appointmentReason && statusText !== 'declined' ? `
                        <div style="display: flex; align-items: start; gap: 0.5rem;">
                            <span style="font-weight: 600; color: #666; min-width: 120px;">📋 Reason:</span>
                            <span style="color: #333;">${escapeHtml(appointmentReason)}</span>
                        </div>
                        ` : ''}
                        ${appointmentNotes ? `
                        <div style="display: flex; align-items: start; gap: 0.5rem;">
                            <span style="font-weight: 600; color: #666; min-width: 120px;">📝 Notes:</span>
                            <span style="color: #333;">${escapeHtml(appointmentNotes)}</span>
                        </div>
                        ` : ''}
                        ${declineReason && statusText === 'declined' ? `
                        <div style="display: flex; align-items: start; gap: 0.5rem;">
                            <span style="font-weight: 600; color: #666; min-width: 120px;">📋 Reason:</span>
                            <span style="color: #333;">${escapeHtml(declineReason)}</span>
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;
            
            // Parse suggested schedule for declined appointments
            let suggestedDateStr = null;
            let suggestedTimeStr = null;
            let suggestedDateTime = null;
            
            if (statusText === 'declined' && suggestedSchedule) {
                // Parse format: "Jan 28, 2026 at 11:00 AM" or "M d, Y at g:i A"
                // Try multiple patterns to handle different formats
                let scheduleMatch = suggestedSchedule.match(/([A-Za-z]+\s+\d+,\s+\d+)\s+at\s+(\d+:\d+\s+(?:AM|PM))/i);
                
                if (!scheduleMatch) {
                    // Try alternative format: "January 28, 2026 at 11:00 AM"
                    scheduleMatch = suggestedSchedule.match(/([A-Za-z]+\s+\d{1,2},\s+\d{4})\s+at\s+(\d{1,2}:\d{2}\s+(?:AM|PM))/i);
                }
                
                if (scheduleMatch) {
                    const datePart = scheduleMatch[1].trim();
                    const timePart = scheduleMatch[2].trim();
                    
                    // Convert date to YYYY-MM-DD format
                    const parsedDate = new Date(datePart);
                    if (!isNaN(parsedDate.getTime())) {
                        const year = parsedDate.getFullYear();
                        const month = String(parsedDate.getMonth() + 1).padStart(2, '0');
                        const day = String(parsedDate.getDate()).padStart(2, '0');
                        suggestedDateStr = `${year}-${month}-${day}`;
                        
                        // Convert time to 24-hour format for API
                        const time12 = timePart.toUpperCase().trim();
                        const timeMatch = time12.match(/(\d{1,2}):(\d{2})\s+(AM|PM)/);
                        if (timeMatch) {
                            let hour24 = parseInt(timeMatch[1]);
                            const minutes = timeMatch[2];
                            const ampm = timeMatch[3];
                            
                            if (ampm === 'PM' && hour24 !== 12) hour24 += 12;
                            if (ampm === 'AM' && hour24 === 12) hour24 = 0;
                            
                            suggestedTimeStr = String(hour24).padStart(2, '0') + ':' + minutes + ':00';
                            suggestedDateTime = suggestedDateStr + ' ' + suggestedTimeStr;
                        }
                    }
                }
            }
            
            // Add Decline Information section for declined appointments
            if (statusText === 'declined' && (declineReason || suggestedSchedule)) {
                html += `
                    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 2px solid #e0e0e0;">
                        <h3 style="margin: 0 0 1rem 0; color: #721c24; font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-info-circle"></i> Appointment Decline Information
                        </h3>
                        <div style="padding: 1rem; background: #fff5f5; border-left: 4px solid #dc3545; border-radius: 8px;">
                            ${declineReason ? `
                            <div style="margin-bottom: 1rem;">
                                <div style="font-weight: 600; color: #721c24; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                                    <i class="fas fa-exclamation-triangle"></i> Decline Reason:
                                </div>
                                <div style="color: #333; padding-left: 1.5rem;">${escapeHtml(declineReason)}</div>
                            </div>
                            ` : ''}
                            ${suggestedSchedule ? `
                            <div style="margin-top: ${declineReason ? '1rem' : '0'}; padding-top: ${declineReason ? '1rem' : '0'}; border-top: ${declineReason ? '1px solid #f5c6cb' : 'none'}; background: ${suggestedSchedule ? '#f0f9f0' : 'transparent'}; padding: ${suggestedSchedule ? '1rem' : '0'}; border-radius: 6px;">
                                <div style="font-weight: 600; color: #155724; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                                    <i class="fas fa-calendar-alt"></i> Suggested Alternative Schedule:
                                </div>
                                <div style="color: #333; padding-left: 1.5rem; font-size: 1.05rem;">
                                    <strong>${escapeHtml(suggestedSchedule)}</strong>
                                </div>
                                <div style="color: #666; font-size: 0.9rem; margin-top: 0.5rem; padding-left: 1.5rem; font-style: italic;">
                                    This alternative schedule was suggested by the front desk officer. You can use this information when rescheduling your appointment.
                                </div>
                                <div id="suggested-slot-availability" style="margin-top: 0.75rem; padding-left: 1.5rem; font-size: 0.9rem; color: #666;">
                                    <span style="display: inline-block; width: 12px; height: 12px; border: 2px solid #ddd; border-radius: 50%; animation: spin 1s linear infinite; margin-right: 6px; vertical-align: middle;"></span>
                                    Checking availability...
                                </div>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                `;
            }
            
            // Add Next Patient Instructions section if available
            if (apt.patient_instructions && apt.patient_instructions.trim()) {
                html += `
                    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 2px solid #e0e0e0;">
                        <h3 style="margin: 0 0 1rem 0; color: #2E7D32; font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-clipboard-list"></i> Next Patient Instructions
                        </h3>
                        <div style="padding: 1rem; background: #f8f9fa; border-left: 4px solid #4CAF50; border-radius: 8px; line-height: 1.6; white-space: pre-wrap; color: #333;">
                            ${escapeHtml(apt.patient_instructions).replace(/\n/g, '<br>')}
                        </div>
                    </div>
                `;
            }
            
            // Add action buttons for declined appointments with suggested schedule
            if (statusText === 'declined' && suggestedSchedule && suggestedDateStr && suggestedTimeStr) {
                html += `
                    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 2px solid #e0e0e0;">
                        <div style="display: flex; gap: 0.75rem; justify-content: flex-end; flex-wrap: wrap;">
                            <button id="use-suggested-schedule-btn" 
                                    onclick="useSuggestedSchedule('${escapeHtml(suggestedDateStr)}', '${escapeHtml(suggestedTimeStr)}', ${apt.duration_minutes || 30})" 
                                    style="padding: 0.75rem 1.5rem; background: #28a745; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.3s ease;"
                                    disabled>
                                <i class="fas fa-calendar-check"></i> Use Suggested Schedule
                            </button>
                            <button onclick="chooseAnotherDate()" 
                                    style="padding: 0.75rem 1.5rem; background: #007bff; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.3s ease;">
                                <i class="fas fa-calendar-alt"></i> Choose Another Date
                            </button>
                        </div>
                    </div>
                `;
            }
            
            html += `
                <div style="margin-top: 2rem; text-align: right;">
                    <button onclick="closeAppointmentDetailsModal()" style="padding: 0.75rem 1.5rem; background: #6c757d; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">Close</button>
                </div>
            `;
            
            content.innerHTML = html;
            
            // Check availability for suggested schedule if applicable
            if (statusText === 'declined' && suggestedSchedule && suggestedDateStr && suggestedTimeStr) {
                checkSuggestedSlotAvailability(suggestedDateStr, suggestedTimeStr);
            }
        }
        
        // Check if suggested slot is available
        function checkSuggestedSlotAvailability(dateStr, timeStr) {
            const availabilityDiv = document.getElementById('suggested-slot-availability');
            const useBtn = document.getElementById('use-suggested-schedule-btn');
            
            if (!availabilityDiv) return;
            
            fetch(`check_suggested_slot_availability.php?date=${encodeURIComponent(dateStr)}&time=${encodeURIComponent(timeStr)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (data.available) {
                            availabilityDiv.innerHTML = '<span style="color: #28a745;"><i class="fas fa-check-circle"></i> This time slot is available</span>';
                            if (useBtn) {
                                useBtn.disabled = false;
                                useBtn.style.opacity = '1';
                                useBtn.style.cursor = 'pointer';
                            }
                        } else {
                            availabilityDiv.innerHTML = '<span style="color: #dc3545;"><i class="fas fa-times-circle"></i> This suggested time slot is no longer available. Please choose another date.</span>';
                            if (useBtn) {
                                useBtn.disabled = true;
                                useBtn.style.opacity = '0.6';
                                useBtn.style.cursor = 'not-allowed';
                            }
                        }
                    } else {
                        availabilityDiv.innerHTML = '<span style="color: #856404;"><i class="fas fa-exclamation-triangle"></i> Unable to check availability. Please try again.</span>';
                        if (useBtn) {
                            useBtn.disabled = true;
                            useBtn.style.opacity = '0.6';
                            useBtn.style.cursor = 'not-allowed';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error checking availability:', error);
                    availabilityDiv.innerHTML = '<span style="color: #856404;"><i class="fas fa-exclamation-triangle"></i> Error checking availability. Please try again.</span>';
                    if (useBtn) {
                        useBtn.disabled = true;
                        useBtn.style.opacity = '0.6';
                        useBtn.style.cursor = 'not-allowed';
                    }
                });
        }
        
        // Use suggested schedule - redirect to booking form with pre-filled data
        function useSuggestedSchedule(dateStr, timeStr, durationMinutes) {
            // Close the modal first
            closeAppointmentDetailsModal();
            
            // Show the booking form
            const bookingForm = document.getElementById('appointment_booking_form');
            if (bookingForm) {
                bookingForm.style.display = 'block';
                bookingForm.scrollIntoView({ behavior: 'smooth' });
                
                // Wait a bit for form to render, then pre-fill
                setTimeout(() => {
                    prefillBookingForm(dateStr, timeStr, durationMinutes);
                }, 300);
            } else {
                // If form doesn't exist on this page, redirect to booking page with parameters
                window.location.href = `user_appointments.php?open=1&prefill_date=${dateStr}&prefill_time=${timeStr}&prefill_duration=${durationMinutes}`;
            }
        }
        
        // Pre-fill booking form with suggested schedule
        function prefillBookingForm(dateStr, timeStr, durationMinutes) {
            // Load calendar first if not loaded
            if (typeof loadAppointmentCalendar === 'function') {
                loadAppointmentCalendar();
            }
            
            // Wait for calendar to load, then select the date and period
            setTimeout(() => {
                // Parse time to determine period (AM or PM)
                const [hours, minutes] = timeStr.split(':');
                const hour24 = parseInt(hours);
                const period = (hour24 >= 7 && hour24 < 12) ? 'am' : 'pm';
                
                // Select the date and period in calendar
                if (typeof selectSlot === 'function') {
                    selectSlot(dateStr, period);
                }
                
                // Set duration
                const durationInput = document.querySelector('input[name="duration_minutes"]');
                if (durationInput) {
                    durationInput.value = durationMinutes || 30;
                }
                
                // Wait a bit more for time slots to populate, then select the time
                setTimeout(() => {
                    const timeSelect = document.getElementById('preferred_time');
                    if (timeSelect) {
                        // Remove seconds from time string if present
                        const timeWithoutSeconds = timeStr.split(':').slice(0, 2).join(':');
                        
                        // Find matching option by value (format: "HH:MM")
                        for (let option of timeSelect.options) {
                            if (option.value === timeWithoutSeconds) {
                                timeSelect.value = option.value;
                                timeSelect.dispatchEvent(new Event('change'));
                                break;
                            }
                        }
                        
                        // If not found by value, try by text (12-hour format)
                        if (!timeSelect.value) {
                            const hour12 = hour24 > 12 ? hour24 - 12 : (hour24 === 0 ? 12 : hour24);
                            const ampm = hour24 >= 12 ? 'PM' : 'AM';
                            const minsOnly = minutes.split(':')[0]; // Remove seconds if present
                            const time12Str = `${hour12}:${minsOnly} ${ampm}`;
                            
                            for (let option of timeSelect.options) {
                                if (option.textContent.trim() === time12Str || option.textContent.includes(time12Str)) {
                                    timeSelect.value = option.value;
                                    timeSelect.dispatchEvent(new Event('change'));
                                    break;
                                }
                            }
                        }
                    }
                }, 800);
            }, 1000);
        }
        
        // Choose another date - redirect to standard booking
        function chooseAnotherDate() {
            // Close the modal
            closeAppointmentDetailsModal();
            
            // Show the booking form without pre-filling
            const bookingForm = document.getElementById('appointment_booking_form');
            if (bookingForm) {
                bookingForm.style.display = 'block';
                bookingForm.scrollIntoView({ behavior: 'smooth' });
                
                // Load calendar
                if (typeof loadAppointmentCalendar === 'function') {
                    loadAppointmentCalendar();
                }
            } else {
                // Redirect to booking page
                window.location.href = 'user_appointments.php?open=1';
            }
        }

        function closeAppointmentDetailsModal() {
            const modal = document.getElementById('appointmentDetailsModal');
            modal.style.display = 'none';
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('appointmentDetailsModal');
            if (event.target === modal) {
                closeAppointmentDetailsModal();
            }
            const verificationModal = document.getElementById('verificationRequiredModal');
            if (verificationModal && event.target === verificationModal) {
                closeVerificationRequiredModal();
            }
        });

        let dateInputHandler = null;
        
        // Function to update reschedule time slots based on selected date
        function updateRescheduleTimeSlots() {
            const timeSelect = document.getElementById('reschedule_time');
            const dateInput = document.getElementById('reschedule_date');
            if(!timeSelect || !dateInput) return;
            
            // Clear existing options
            timeSelect.innerHTML = '<option value="">Select Time</option>';
            
            // Get selected date and current time
            const selectedDate = dateInput.value;
            if(!selectedDate) return;
            
            const now = new Date();
            const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            const selectedDateObj = new Date(selectedDate + 'T00:00:00');
            const isToday = selectedDateObj.getTime() === today.getTime();
            
            // Minimum advance booking rule: 1 hour lead time
            const minAllowedTime = new Date(now.getTime() + (1 * 60 * 60 * 1000)); // 1 hour from now
            
            // Time interval rule: 1h30m slot interval (same as booking)
            // Master time slot list - MUST match CLINIC_TIME_SLOTS from clinic_time_slots.php
            const times = ['07:00', '07:30', '08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '13:00', '13:30', '14:00', '14:30', '15:00'];
            for (const t of times) {
                const slotTime = new Date(selectedDate + 'T' + t + ':00');
                const hour = slotTime.getHours();
                const min = slotTime.getMinutes();
                const hour12 = ((hour + 11) % 12) + 1;
                const ampm = hour < 12 ? 'AM' : 'PM';
                
                const opt = document.createElement('option');
                opt.value = t;
                opt.textContent = `${hour12}:${String(min).padStart(2,'0')} ${ampm}`;
                
                if (selectedDateObj < today) {
                    opt.disabled = true;
                    opt.textContent += ' (Unavailable)';
                } else if (isToday) {
                    if (slotTime <= now || slotTime < minAllowedTime) {
                        opt.disabled = true;
                        opt.textContent += ' (Unavailable)';
                    }
                }
                
                timeSelect.appendChild(opt);
            }
        }
        
        function openRescheduleModal(appointmentId) {
            const modal = document.getElementById('rescheduleModal');
            const dateInput = document.getElementById('reschedule_date');
            const timeSelect = document.getElementById('reschedule_time');
            const appointmentIdInput = document.getElementById('reschedule_appointment_id');
            
            // Set appointment ID
            appointmentIdInput.value = appointmentId;
            
            // Set minimum date to today
            const today = new Date();
            const yyyy = today.getFullYear();
            const mm = String(today.getMonth() + 1).padStart(2, '0');
            const dd = String(today.getDate()).padStart(2, '0');
            dateInput.min = `${yyyy}-${mm}-${dd}`;
            
            // Clear previous values
            dateInput.value = '';
            timeSelect.innerHTML = '<option value="">Select Time</option>';
            
            // Remove previous event listener if exists
            if (dateInputHandler) {
                dateInput.removeEventListener('change', dateInputHandler);
                dateInput.removeEventListener('input', dateInputHandler);
                dateInput.removeEventListener('click', dateInputHandler);
            }
            
            // Make date input clickable and restrict to weekdays
            dateInputHandler = function() {
                if (this.value) {
                    const d = new Date(this.value);
                    const day = d.getDay();
                    if (day === 0 || day === 6) {
                        alert('Please select a weekday (Monday to Friday).');
                        this.value = '';
                        updateRescheduleTimeSlots(); // Update time slots when date is cleared
                    } else {
                        updateRescheduleTimeSlots(); // Update time slots when valid date is selected
                    }
                }
            };
            
            dateInput.addEventListener('change', dateInputHandler);
            dateInput.addEventListener('input', function() {
                if(this.value) {
                    dateInputHandler.call(this);
                }
            });
            dateInput.addEventListener('click', function() {
                if (this.showPicker) {
                    this.showPicker();
                }
            });
            
            // Show modal
            modal.style.display = 'flex';
        }

        function closeRescheduleModal() {
            const modal = document.getElementById('rescheduleModal');
            modal.style.display = 'none';
        }

        function openVerificationRequiredModal() {
            const modal = document.getElementById('verificationRequiredModal');
            if (modal) modal.style.display = 'flex';
        }
        function closeVerificationRequiredModal() {
            const modal = document.getElementById('verificationRequiredModal');
            if (modal) modal.style.display = 'none';
        }

        async function handleRescheduleSubmit(event) {
            event.preventDefault();
            
            const appointmentId = document.getElementById('reschedule_appointment_id').value;
            const newDate = document.getElementById('reschedule_date').value;
            const newTime = document.getElementById('reschedule_time').value;
            
            if (!newDate || !newTime) {
                alert('Please select both date and time.');
                return;
            }
            
            // Check if new date/time is at least 1 hour away
            const newDateTime = new Date(newDate + 'T' + newTime);
            const currentTime = new Date();
            const timeDiff = newDateTime - currentTime;
            const hoursAway = timeDiff / (1000 * 60 * 60);
            
            if (hoursAway < 1) {
                alert('The new appointment time must be at least 1 hour away from now.');
                return;
            }
            
            // Check if it's a weekday
            const day = newDateTime.getDay();
            if (day === 0 || day === 6) {
                alert('Please select a weekday (Monday to Friday).');
                return;
            }
            
            // Confirmation popup
            const confirmed = await confirm('You can only reschedule an appointment once. Are you sure of these changes?');
            if (!confirmed) {
                return;
            }
            
            // Create form and submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'reschedule_appointment.php';
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'appointment_id';
            idInput.value = appointmentId;
            
            const dateInput = document.createElement('input');
            dateInput.type = 'hidden';
            dateInput.name = 'new_date';
            dateInput.value = newDate;
            
            const timeInput = document.createElement('input');
            timeInput.type = 'hidden';
            timeInput.name = 'new_time';
            timeInput.value = newTime;
            
            form.appendChild(idInput);
            form.appendChild(dateInput);
            form.appendChild(timeInput);
            document.body.appendChild(form);
            form.submit();
        }
        
        // Close modal when clicking outside (wait for DOM to be ready)
        document.addEventListener('DOMContentLoaded', function() {
            const rescheduleModal = document.getElementById('rescheduleModal');
            if (rescheduleModal) {
                rescheduleModal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeRescheduleModal();
                    }
                });
            }
        });

        // Follow-up appointment selection handlers
        document.addEventListener('DOMContentLoaded', function() {
            // Enable submit button when option is selected
            document.querySelectorAll('input[name^="selected_option"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    const formId = this.closest('form').id;
                    const followUpId = formId.replace('followupForm', '');
                    const submitBtn = document.getElementById('submitBtn' + followUpId);
                    if (submitBtn) {
                        submitBtn.disabled = false;
                    }
                    
                    // Update visual selection
                    const form = document.getElementById(formId);
                    const cards = form.querySelectorAll('.followup-option-card');
                    cards.forEach(card => {
                        card.style.borderColor = '#e0e0e0';
                        card.style.background = 'white';
                    });
                    if (this.closest('.followup-option-card')) {
                        const selectedCard = this.closest('.followup-option-card');
                        selectedCard.style.borderColor = '#4CAF50';
                        selectedCard.style.background = '#e8f5e9';
                    }
                });
            });
        });

        async function selectFollowUpOption(event, followUpId) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            formData.append('action', 'select_followup');
            
            const submitBtn = document.getElementById('submitBtn' + followUpId);
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Processing...';
            }
            
            try {
                const response = await fetch('patient_followup_selection.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Follow-up appointment selected successfully! It is now pending doctor approval.');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to select follow-up'));
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit Selection for Doctor Approval';
                    }
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error selecting follow-up appointment. Please try again.');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Selection for Doctor Approval';
                }
            }
        }

        // Request reschedule for doctor-set follow-up
        async function requestReschedule(followUpId) {
            // Check if reschedule is allowed (at least 1 week before)
            try {
                // First, get the follow-up details to check the date
                const response = await fetch(`get_followup_available_slots.php?follow_up_id=${followUpId}`);
                const slotData = await response.json();
                
                if (!slotData.success) {
                    alert('Error: ' + (slotData.message || 'Failed to check availability'));
                    return;
                }
                
                const originalDate = new Date(slotData.original_date);
                const now = new Date();
                const oneWeekBefore = new Date(originalDate);
                oneWeekBefore.setDate(oneWeekBefore.getDate() - 7);
                
                if (now > oneWeekBefore) {
                    alert('Reschedule requests must be made at least one week before the scheduled appointment date.');
                    return;
                }
                
                if (!confirm('Request a reschedule for this follow-up appointment? The doctor will provide alternative schedule options.')) {
                    return;
                }
                
                const formData = new FormData();
                formData.append('action', 'request_reschedule');
                formData.append('follow_up_id', followUpId);
                
                const rescheduleResponse = await fetch('patient_request_reschedule.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await rescheduleResponse.json();
                
                if (data.success) {
                    alert('Reschedule request submitted successfully! The doctor will provide alternative schedule options.');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to submit reschedule request'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error submitting reschedule request. Please try again.');
            }
        }
    </script>
</body>
</html>

