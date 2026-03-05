<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get the doctor_id from the logged-in doctor's user_id
    $user_id = $_SESSION['user']['id'];
    $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doctor) {
        // Doctor record doesn't exist - return empty array
        echo json_encode([
            'success' => true,
            'patients' => [],
            'stats' => [
                'total_patients' => 0,
                'new_this_month' => 0,
                'total_dependents' => 0
            ]
        ]);
        exit;
    }
    
    $doctor_id = $doctor['id'];
    
    // Get patients list - include both registered patients AND dependents
    // BUT ONLY those with approved appointments assigned to this doctor
    // Registered patients: users table with patient_profiles
    // Dependents: patients table with created_by_user_id pointing to parent
    $search = $_GET['search'] ?? '';
    $params = [];
    
    // Build query for registered patients - those with approved OR completed appointments for this doctor (so they stay visible after consultation)
    $registered_where = "u.role = 'patient' 
                        AND EXISTS (
                            SELECT 1 FROM appointments a 
                            WHERE a.doctor_id = ? 
                            AND (a.status = 'approved' OR a.status = 'completed')
                            AND (a.user_id = u.id OR (a.user_id = u.id AND a.patient_id IS NULL))
                        )";
    $params[] = $doctor_id;
    
    if (!empty($search)) {
        $registered_where .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.contact_no LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    }
    
    // Get registered patients - use DISTINCT to ensure each patient appears only once
    // Add doctor_id again for the last_visit subquery
    $params_with_last_visit = array_merge($params, [$doctor_id]);
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            u.id as id,
            u.first_name,
            u.middle_name,
            u.last_name,
            pp.sex,
            pp.date_of_birth as dob,
            u.contact_no as phone,
            u.address,
            'active' as status,
            u.created_at,
            NULL as parent_name,
            NULL as is_dependent,
            (SELECT MAX(start_datetime) 
             FROM appointments 
             WHERE doctor_id = ?
               AND status = 'completed'
               AND ((user_id = u.id AND patient_id = u.id)
                OR (user_id = u.id AND patient_id IS NULL))) as last_visit
        FROM users u
        INNER JOIN patient_profiles pp ON pp.patient_id = u.id
        WHERE $registered_where 
        ORDER BY u.created_at DESC
    ");
    $stmt->execute($params_with_last_visit);
    $registered_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get dependents from dependents table (signup form data)
    // This is the primary source for dependents with accurate age, sex, and date_of_birth
    // Include those with approved OR completed appointments for this doctor
    $dependents_where = "EXISTS (
                            SELECT 1 FROM appointments a 
                            WHERE a.doctor_id = ? 
                            AND (a.status = 'approved' OR a.status = 'completed')
                            AND (a.user_id = d.patient_id AND a.patient_id IS NULL)
                        )";
    $dependents_params = [$doctor_id];
    
    if (!empty($search)) {
        $dependents_where .= " AND (d.first_name LIKE ? OR d.last_name LIKE ? OR CONCAT(d.first_name, ' ', d.last_name) LIKE ?)";
        $search_param = "%$search%";
        $dependents_params = array_merge($dependents_params, [$search_param, $search_param, $search_param]);
    }
    
    // Add doctor_id again for the last_visit subquery
    $dependents_params_with_last_visit = array_merge($dependents_params, [$doctor_id]);
    $stmt = $pdo->prepare("
        SELECT 
            d.id as dependent_id,
            d.first_name,
            d.middle_name,
            d.last_name,
            d.sex,
            d.date_of_birth as dob,
            d.age,
            d.relationship,
            d.created_at,
            d.patient_id as parent_user_id,
            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as parent_name,
            u.contact_no as phone,
            u.address,
            (SELECT MAX(start_datetime) 
             FROM appointments 
             WHERE doctor_id = ?
               AND user_id = d.patient_id
               AND patient_id IS NULL
               AND status = 'completed') as last_visit,
            (SELECT p.id FROM patients p 
             WHERE p.created_by_user_id = d.patient_id 
               AND LOWER(TRIM(p.first_name)) = LOWER(TRIM(d.first_name))
               AND LOWER(TRIM(p.last_name)) = LOWER(TRIM(d.last_name))
             ORDER BY p.created_at DESC LIMIT 1) as patient_table_id
        FROM dependents d
        INNER JOIN users u ON u.id = d.patient_id
        WHERE $dependents_where
        ORDER BY d.first_name, d.last_name, d.created_at DESC
    ");
    $stmt->execute($dependents_params_with_last_visit);
    $dependents_from_table = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get dependents from patients table (created by doctors)
    // These are patient records that were created for dependents
    // Include those with approved OR completed appointments for this doctor
    $dependent_where = "p.created_by_user_id IS NOT NULL 
                        AND EXISTS (
                            SELECT 1 FROM appointments a 
                            WHERE a.doctor_id = ? 
                            AND (a.status = 'approved' OR a.status = 'completed')
                            AND a.patient_id = p.id
                        )
                        AND NOT EXISTS (
                            SELECT 1 FROM users u 
                            WHERE u.id = p.created_by_user_id 
                            AND u.role = 'patient'
                            AND LOWER(TRIM(u.first_name)) = LOWER(TRIM(p.first_name))
                            AND LOWER(TRIM(u.last_name)) = LOWER(TRIM(p.last_name))
                        )
                        AND NOT EXISTS (
                            SELECT 1 FROM dependents d
                            WHERE d.patient_id = p.created_by_user_id
                            AND LOWER(TRIM(d.first_name)) = LOWER(TRIM(p.first_name))
                            AND LOWER(TRIM(d.last_name)) = LOWER(TRIM(p.last_name))
                        )";
    $dependent_params = [$doctor_id];
    if (!empty($search)) {
        $dependent_where .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.phone LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?)";
        $search_param = "%$search%";
        $dependent_params = array_merge($dependent_params, [$search_param, $search_param, $search_param, $search_param]);
    }
    
    // Add doctor_id again for the last_visit subquery
    $dependent_params_with_last_visit = array_merge($dependent_params, [$doctor_id]);
    $stmt = $pdo->prepare("
        SELECT 
            p.id as id,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.sex,
            p.dob,
            p.phone,
            p.address,
            p.status,
            p.created_at,
            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as parent_name,
            p.created_by_user_id as parent_user_id,
            1 as is_dependent,
            (SELECT MAX(start_datetime) 
             FROM appointments 
             WHERE doctor_id = ?
               AND patient_id = p.id
               AND status = 'completed') as last_visit,
            NULL as dependent_id
        FROM patients p
        INNER JOIN users u ON u.id = p.created_by_user_id
        WHERE $dependent_where
        ORDER BY p.first_name, p.last_name, p.created_at DESC
    ");
    $stmt->execute($dependent_params_with_last_visit);
    $all_dependent_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process dependents from dependents table (signup form)
    // These have accurate age, sex, and date_of_birth
    $dependent_map = [];
    foreach ($dependents_from_table as $dep) {
        $key = strtolower(trim($dep['first_name'] . '|' . $dep['last_name'] . '|' . $dep['parent_user_id']));
        // Use patient_table_id if available, otherwise use dependent_id with prefix
        $dep['id'] = $dep['patient_table_id'] ? $dep['patient_table_id'] : ('dep_' . $dep['dependent_id']);
        $dep['status'] = 'active';
        $dep['is_dependent'] = 1;
        if (!isset($dependent_map[$key]) || strtotime($dep['created_at']) > strtotime($dependent_map[$key]['created_at'])) {
            $dependent_map[$key] = $dep;
        }
    }
    
    // Add dependents from patients table that don't have a match in dependents table
    foreach ($all_dependent_patients as $dep) {
        $key = strtolower(trim($dep['first_name'] . '|' . $dep['last_name'] . '|' . $dep['parent_user_id']));
        if (!isset($dependent_map[$key])) {
            $dependent_map[$key] = $dep;
        }
    }
    
    $dependent_patients = array_values($dependent_map);
    
    // Combine both lists and ensure uniqueness
    // For registered patients: use user ID as unique key
    // For dependents: use first_name + last_name + parent_user_id as unique key
    $unique_patients = [];
    $seen_keys = [];
    
    // Process registered patients first (they have unique user IDs)
    foreach ($registered_patients as $patient) {
        $key = 'registered_' . $patient['id'];
        if (!isset($seen_keys[$key])) {
            $unique_patients[] = $patient;
            $seen_keys[$key] = true;
        }
    }
    
    // Process dependents - group by name and parent to ensure uniqueness
    // Also check that the dependent is not actually a registered patient
    foreach ($dependent_patients as $patient) {
        // Double-check: Make sure this patient record doesn't belong to a registered patient with the same name
        $is_registered_patient = false;
        foreach ($registered_patients as $reg_patient) {
            if (strtolower(trim($reg_patient['first_name'])) === strtolower(trim($patient['first_name'])) &&
                strtolower(trim($reg_patient['last_name'])) === strtolower(trim($patient['last_name'])) &&
                $patient['parent_user_id'] == $reg_patient['id']) {
                // This is the registered patient's own record, skip it
                $is_registered_patient = true;
                break;
            }
        }
        
        if ($is_registered_patient) {
            continue; // Skip this record - it's a registered patient, not a dependent
        }
        
        // Create unique key: first_name + last_name + parent_user_id
        $name_key = strtolower(trim($patient['first_name'] . '|' . $patient['last_name'] . '|' . ($patient['parent_user_id'] ?? '')));
        $key = 'dependent_' . $name_key;
        
        // Check if we already have this dependent
        $existing_index = null;
        foreach ($unique_patients as $idx => $existing) {
            if (!empty($existing['is_dependent']) && 
                strtolower(trim($existing['first_name'])) === strtolower(trim($patient['first_name'])) &&
                strtolower(trim($existing['last_name'])) === strtolower(trim($patient['last_name'])) &&
                $existing['parent_name'] === $patient['parent_name']) {
                $existing_index = $idx;
                break;
            }
        }
        
        if ($existing_index === null && !isset($seen_keys[$key])) {
            // New unique dependent
            $unique_patients[] = $patient;
            $seen_keys[$key] = true;
        } elseif ($existing_index !== null) {
            // Update existing dependent if this one is more recent
            $existing = $unique_patients[$existing_index];
            if (strtotime($patient['created_at']) > strtotime($existing['created_at'])) {
                $unique_patients[$existing_index] = $patient;
            }
        }
    }
    
    $patients = $unique_patients;
    
    // Format patients data
    $formatted_patients = [];
    foreach ($patients as $patient) {
        // Final check: If this patient has is_dependent set but matches a registered patient's name,
        // it's not actually a dependent - it's the registered patient's own record
        $is_actually_dependent = !empty($patient['is_dependent']);
        if ($is_actually_dependent) {
            // Double-check against registered patients list
            foreach ($registered_patients as $reg_patient) {
                if (strtolower(trim($reg_patient['first_name'])) === strtolower(trim($patient['first_name'])) &&
                    strtolower(trim($reg_patient['last_name'])) === strtolower(trim($patient['last_name']))) {
                    // This is actually a registered patient, not a dependent
                    $is_actually_dependent = false;
                    break;
                }
            }
        }
        
        // For dependents, prioritize data from dependents table (signup form)
        // Check if this is from dependents table by looking for dependent_id or age field
        $age = 'N/A';
        $sex_display = 'N/A';
        
        if ($is_actually_dependent && (isset($patient['dependent_id']) || isset($patient['age']))) {
            // This is from dependents table - use the age and sex from there
            if (!empty($patient['age']) && is_numeric($patient['age'])) {
                $age = (int)$patient['age'];
            } elseif (!empty($patient['dob'])) {
                $dob = new DateTime($patient['dob']);
                $age = (new DateTime('today'))->diff($dob)->y;
            }
            
            // Use sex from dependents table
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
        } else {
            // For registered patients or dependents without dependents table data
            $dob = $patient['dob'] ? new DateTime($patient['dob']) : null;
            $age = $dob ? (new DateTime('today'))->diff($dob)->y : 'N/A';
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
        }
        
        $last_visit = 'No visits yet';
        if ($patient['last_visit']) {
            $last_visit = date('F j, Y', strtotime($patient['last_visit']));
        }
        
        $formatted_patients[] = [
            'id' => $patient['id'],
            'first_name' => $patient['first_name'],
            'last_name' => $patient['last_name'],
            'middle_name' => $patient['middle_name'] ?? '',
            'age' => $age,
            'sex' => $patient['sex'],
            'age_sex' => $age . '/' . $sex_display,
            'last_visit' => $last_visit,
            'status' => $patient['status'] ?? 'active',
            'is_dependent' => $is_actually_dependent, // Only true for actual dependents
            'parent_name' => $is_actually_dependent ? ($patient['parent_name'] ?? '') : '', // Only set parent_name for actual dependents
            'created_at' => $patient['created_at'] ?? null // Store created_at for stats calculation
        ];
    }
    
    // Calculate stats from the actual formatted patients list
    // This ensures the counts match what's displayed
    $actual_total_patients = count($formatted_patients);
    $actual_total_dependents = count(array_filter($formatted_patients, function($p) {
        return !empty($p['is_dependent']);
    }));
    
    // Count new this month from the actual list
    $current_month = date('m');
    $current_year = date('Y');
    $actual_new_this_month = 0;
    foreach ($formatted_patients as $p) {
        if (!empty($p['created_at'])) {
            $created_date = new DateTime($p['created_at']);
            if ($created_date->format('m') == $current_month && $created_date->format('Y') == $current_year) {
                $actual_new_this_month++;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'patients' => $formatted_patients,
        'stats' => [
            'total_patients' => $actual_total_patients,
            'new_this_month' => $actual_new_this_month,
            'total_dependents' => $actual_total_dependents
        ]
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error fetching patients: ' . $e->getMessage()]);
}
?>


