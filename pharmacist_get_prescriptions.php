<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pharmacist') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get patients with pending prescriptions (status = 'sent' or 'active')
    // prescriptions.patient_id can reference either users.id or patients.id
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            pr.patient_id,
            CASE 
                WHEN u.id IS NOT NULL THEN TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')))
                WHEN pt.id IS NOT NULL THEN TRIM(CONCAT(COALESCE(pt.first_name, ''), ' ', COALESCE(pt.middle_name, ''), ' ', COALESCE(pt.last_name, '')))
                ELSE 'Unknown Patient'
            END as patient_name,
            COUNT(DISTINCT pr.id) as prescription_count
        FROM prescriptions pr
        LEFT JOIN users u ON pr.patient_id = u.id AND u.role = 'patient'
        LEFT JOIN patients pt ON pr.patient_id = pt.id
        WHERE pr.status IN ('sent', 'active')
        GROUP BY pr.patient_id, patient_name
        HAVING prescription_count > 0
        ORDER BY patient_name
    ");
    
    $stmt->execute();
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Clean up patient names
    foreach ($patients as &$patient) {
        $patient['patient_name'] = trim(preg_replace('/\s+/', ' ', $patient['patient_name']));
    }
    unset($patient);
    
    echo json_encode(['success' => true, 'patients' => $patients]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

