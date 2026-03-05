<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Check if user is logged in and is an FDO
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'fdo') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$fdo_id = $_SESSION['user']['id'];

// Get filter parameters
$date_filter = $_GET['date_filter'] ?? 'all'; // 'all', 'today', 'week', 'month'
$status_filter = $_GET['status_filter'] ?? 'all'; // 'all', 'pending', 'approved', 'completed', 'declined', 'cancelled'

try {
    // Build WHERE clause
    $where_clause = "(a.fdo_id = ? OR a.fdo_id IS NULL)";
    $params = [$fdo_id];
    
    // Add date filter
    if ($date_filter === 'today') {
        $where_clause .= " AND DATE(a.start_datetime) = CURDATE()";
    } elseif ($date_filter === 'week') {
        $where_clause .= " AND WEEK(a.start_datetime) = WEEK(CURDATE()) AND YEAR(a.start_datetime) = YEAR(CURDATE())";
    } elseif ($date_filter === 'month') {
        $where_clause .= " AND MONTH(a.start_datetime) = MONTH(CURDATE()) AND YEAR(a.start_datetime) = YEAR(CURDATE())";
    }
    // 'all' means no date filter, so we don't add anything
    
    // Add status filter
    if ($status_filter !== 'all') {
        $where_clause .= " AND a.status = ?";
        $params[] = $status_filter;
    }
    
    // Get all appointments (FDO can see all appointments)
    // Include appointments assigned to this FDO or unassigned appointments
    // Use DISTINCT to prevent duplicates from JOINs
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            a.*,
            COALESCE(p.first_name, u.first_name, '') as first_name,
            COALESCE(p.middle_name, u.middle_name, '') as middle_name,
            COALESCE(p.last_name, u.last_name, '') as last_name,
            CONCAT(COALESCE(p.first_name, u.first_name, ''), ' ', COALESCE(p.middle_name, u.middle_name, ''), ' ', COALESCE(p.last_name, u.last_name, '')) as patient_name,
            COALESCE(p.phone, u.contact_no, '') as patient_contact,
            COALESCE(u.email, '') as patient_email,
            CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name,
            d.specialization,
            d.clinic_room
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN doctors d ON a.doctor_id = d.id
        LEFT JOIN users du ON d.user_id = du.id
        WHERE $where_clause
        ORDER BY a.start_datetime DESC, a.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the data for frontend
    foreach ($appointments as &$appt) {
        // Check if it's a dependent appointment
        // IMPORTANT: If patient_id equals user_id, it's the account owner's own appointment, NOT a dependent
        $is_dependent = false;
        $parent_name = '';
        
        // If patient_id equals user_id, it's the account owner's own appointment - NOT a dependent
        if (!empty($appt['patient_id']) && $appt['patient_id'] == $appt['user_id']) {
            // This is the account owner's own appointment - definitely not a dependent
            $is_dependent = false;
        } elseif (empty($appt['patient_id']) && !empty($appt['user_id'])) {
            // If patient_id is NULL, it's a dependent appointment (new booking system)
            $is_dependent = true;
            // Get parent's name
            $stmt = $pdo->prepare('SELECT first_name, middle_name, last_name FROM users WHERE id = ?');
            $stmt->execute([$appt['user_id']]);
            $parent = $stmt->fetch();
            if ($parent) {
                $parent_name = trim(($parent['first_name'] ?? '') . ' ' . ($parent['middle_name'] ?? '') . ' ' . ($parent['last_name'] ?? ''));
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
                    $is_dependent = true;
                    $parent_name = trim(($parent['first_name'] ?? '') . ' ' . ($parent['middle_name'] ?? '') . ' ' . ($parent['last_name'] ?? ''));
                }
            }
        }
        
        // For dependent appointments (patient_id IS NULL), get the patient name from patients table
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
                $appt['patient_contact'] = $dependent_patient['phone'] ?? '';
            }
        }
        
        // Get patient name from patient record or user record
        $patient_name = trim(($appt['first_name'] ?? '') . ' ' . ($appt['middle_name'] ?? '') . ' ' . ($appt['last_name'] ?? ''));
        $patient_name = trim(preg_replace('/\s+/', ' ', $patient_name));
        
        if (empty($patient_name)) {
            $patient_name = 'Unknown Patient';
        }
        
        // Add dependent note if applicable (only for actual dependents, not registered patients)
        if ($is_dependent && !empty($parent_name)) {
            $appt['patient_name'] = $patient_name;
            $appt['dependent_note'] = $parent_name; // Store separately for frontend to display
        } else {
            $appt['patient_name'] = $patient_name;
            $appt['dependent_note'] = '';
        }
        
        // Clean up doctor name
        $appt['doctor_name'] = trim(preg_replace('/\s+/', ' ', $appt['doctor_name']));
        if (empty($appt['doctor_name'])) {
            $appt['doctor_name'] = 'Unassigned';
        }
        
        // Format datetime
        if (!empty($appt['start_datetime'])) {
            $dt = new DateTime($appt['start_datetime']);
            $appt['date'] = $dt->format('F j, Y');
            $appt['time'] = $dt->format('g:i A');
        }
    }
    unset($appt);
    
    echo json_encode(['success' => true, 'appointments' => $appointments]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

