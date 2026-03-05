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

try {
    $search = $_GET['search'] ?? '';
    $params = [];
    
    // Get registered patients (users with role 'patient')
    $registered_where = "u.role = 'patient'";
    if (!empty($search)) {
        $registered_where .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.contact_no LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            u.id as id,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.contact_no as phone,
            u.email,
            'registered' as patient_type,
            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as full_name
        FROM users u
        WHERE $registered_where 
        ORDER BY u.last_name, u.first_name
        LIMIT 100
    ");
    $stmt->execute($params);
    $registered_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get dependents (patients with created_by_user_id set)
    $dependent_where = "p.created_by_user_id IS NOT NULL";
    $dependent_params = [];
    if (!empty($search)) {
        $dependent_where .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.phone LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?)";
        $search_param = "%$search%";
        $dependent_params = [$search_param, $search_param, $search_param, $search_param];
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            p.id as id,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.phone,
            NULL as email,
            'dependent' as patient_type,
            CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.middle_name, ''), ' ', COALESCE(p.last_name, '')) as full_name,
            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as parent_name,
            p.created_by_user_id as parent_user_id
        FROM patients p
        INNER JOIN users u ON u.id = p.created_by_user_id
        WHERE $dependent_where
        ORDER BY p.last_name, p.first_name
        LIMIT 100
    ");
    $stmt->execute($dependent_params);
    $dependent_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Combine both lists
    $patients = array_merge($registered_patients, $dependent_patients);
    
    // Format names
    foreach ($patients as &$patient) {
        $patient['full_name'] = trim(preg_replace('/\s+/', ' ', $patient['full_name']));
        if (!empty($patient['parent_name'])) {
            $patient['parent_name'] = trim(preg_replace('/\s+/', ' ', $patient['parent_name']));
        }
    }
    unset($patient);
    
    echo json_encode(['success' => true, 'patients' => $patients]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

