<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Check if user is logged in and is a doctor
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user']['id'];

try {
    // First, get the doctor_id from doctors table using user_id
    $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doctor) {
        // Doctor record doesn't exist - return empty array
        echo json_encode(['success' => true, 'appointments' => [], 'message' => 'Doctor record not found']);
        exit;
    }
    
    $doctor_id = $doctor['id'];
    
    // Get ONLY appointments assigned to this doctor with approved status
    // Role-based access: doctors can only see patients assigned to them after FDO approval
    // For registered patients, prioritize users table to align with patient details
    // For dependents, use patients table (handle both patient_id and NULL patient_id cases)
    $stmt = $pdo->prepare("
        SELECT 
            a.*,
            -- For registered patients, use users table (aligned with patient records)
            CASE 
                WHEN u.id IS NOT NULL AND u.role = 'patient' THEN u.first_name
                ELSE COALESCE(p.first_name, pd.first_name, u.first_name, '')
            END as first_name,
            CASE 
                WHEN u.id IS NOT NULL AND u.role = 'patient' THEN u.middle_name
                ELSE COALESCE(p.middle_name, pd.middle_name, u.middle_name, '')
            END as middle_name,
            CASE 
                WHEN u.id IS NOT NULL AND u.role = 'patient' THEN u.last_name
                ELSE COALESCE(p.last_name, pd.last_name, u.last_name, '')
            END as last_name,
            CASE 
                WHEN u.id IS NOT NULL AND u.role = 'patient' THEN 
                    CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, ''))
                ELSE 
                    CONCAT(COALESCE(p.first_name, pd.first_name, u.first_name, ''), ' ', COALESCE(p.middle_name, pd.middle_name, u.middle_name, ''), ' ', COALESCE(p.last_name, pd.last_name, u.last_name, ''))
            END as patient_name,
            COALESCE(u.email, '') as patient_email,
            CASE 
                WHEN u.id IS NOT NULL AND u.role = 'patient' THEN u.contact_no
                ELSE COALESCE(p.phone, pd.phone, u.contact_no, '')
            END as patient_contact
        FROM appointments a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN patients p ON a.patient_id = p.id
        LEFT JOIN patients pd ON a.patient_id IS NULL 
            AND pd.created_by_user_id = a.user_id 
            AND pd.created_at <= a.created_at
            AND pd.created_at >= DATE_SUB(a.created_at, INTERVAL 1 HOUR)
        WHERE a.doctor_id = ? 
        AND a.status = 'approved'
        -- Pending appointments are hidden from doctors - only FDO can see them
        ORDER BY a.start_datetime DESC
        LIMIT 500
    ");
    $stmt->execute([$doctor_id]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the data
    foreach ($appointments as &$appt) {
        // Check if it's a dependent appointment
        // Dependent appointments have patient_id = NULL (new system) OR patient_id pointing to a patient record with created_by_user_id
        $is_dependent = false;
        $parent_name = '';
        $dependent_name = '';
        
        // If patient_id is NULL, it's a dependent appointment (new booking system)
        if (empty($appt['patient_id']) && !empty($appt['user_id'])) {
            $is_dependent = true;
            // Get parent's name
            $stmt = $pdo->prepare('SELECT first_name, middle_name, last_name FROM users WHERE id = ?');
            $stmt->execute([$appt['user_id']]);
            $parent = $stmt->fetch();
            if ($parent) {
                $parent_name = trim(($parent['first_name'] ?? '') . ' ' . ($parent['middle_name'] ?? '') . ' ' . ($parent['last_name'] ?? ''));
            }
            // Dependent name should already be in the query result from pd join
            $dependent_name = trim(($appt['first_name'] ?? '') . ' ' . ($appt['middle_name'] ?? '') . ' ' . ($appt['last_name'] ?? ''));
            $dependent_name = trim(preg_replace('/\s+/', ' ', $dependent_name));
        } elseif (!empty($appt['patient_id']) && !empty($appt['user_id'])) {
            // Get the patient record to check created_by_user_id and get dependent's name
            $stmt = $pdo->prepare('SELECT created_by_user_id, first_name, middle_name, last_name FROM patients WHERE id = ?');
            $stmt->execute([$appt['patient_id']]);
            $patient_record = $stmt->fetch();
            
            if ($patient_record && $patient_record['created_by_user_id'] == $appt['user_id']) {
                // This is a dependent - get the dependent's actual name from patients table
                $dependent_name = trim(($patient_record['first_name'] ?? '') . ' ' . ($patient_record['middle_name'] ?? '') . ' ' . ($patient_record['last_name'] ?? ''));
                $dependent_name = trim(preg_replace('/\s+/', ' ', $dependent_name));
                
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
        
        // For dependents, use the dependent's name from patients table
        // For registered patients, use patient_name from query (users table)
        if ($is_dependent && !empty($dependent_name)) {
            $patient_name = $dependent_name;
        } else {
            $patient_name = trim($appt['patient_name'] ?? '');
            $patient_name = trim(preg_replace('/\s+/', ' ', $patient_name));
            
            if (empty($patient_name)) {
                // Fallback: construct from individual fields
                $patient_name = trim(($appt['first_name'] ?? '') . ' ' . ($appt['middle_name'] ?? '') . ' ' . ($appt['last_name'] ?? ''));
                $patient_name = trim(preg_replace('/\s+/', ' ', $patient_name));
                if (empty($patient_name)) {
                    $patient_name = 'Unknown Patient';
                }
            }
        }
        
        // Store patient name and dependent info separately for frontend formatting
        $appt['patient_name'] = $patient_name;
        $appt['is_dependent'] = $is_dependent;
        $appt['parent_name'] = $parent_name;
        
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
    // Log the error for debugging
    error_log("Doctor appointments error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error loading appointments: ' . $e->getMessage(),
        'error_details' => $e->getFile() . ':' . $e->getLine()
    ]);
}

