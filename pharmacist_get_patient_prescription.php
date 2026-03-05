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
    $patient_id = $_GET['patient_id'] ?? null;
    $prescription_id = $_GET['prescription_id'] ?? null;
    
    if (!$patient_id) {
        echo json_encode(['success' => false, 'message' => 'Patient ID required']);
        exit;
    }
    
    // Base query: prescriptions for this patient (sent or active)
    $where = "pr.patient_id = ? AND pr.status IN ('sent', 'active')";
    $params = [$patient_id];
    if ($prescription_id) {
        $where .= " AND pr.id = ?";
        $params[] = $prescription_id;
    }
    $sql = "
        SELECT 
            pr.*,
            CASE 
                WHEN u.id IS NOT NULL THEN CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, ''))
                WHEN pt.id IS NOT NULL THEN CONCAT(COALESCE(pt.first_name, ''), ' ', COALESCE(pt.middle_name, ''), ' ', COALESCE(pt.last_name, ''))
                ELSE 'Unknown'
            END as patient_name,
            COALESCE(
                CONCAT(COALESCE(d.first_name, ''), ' ', COALESCE(d.middle_name, ''), ' ', COALESCE(d.last_name, '')),
                CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')),
                'Unknown Doctor'
            ) as doctor_name
        FROM prescriptions pr
        LEFT JOIN users u ON pr.patient_id = u.id AND u.role = 'patient'
        LEFT JOIN patients pt ON pr.patient_id = pt.id
        LEFT JOIN doctors doc ON pr.doctor_id = doc.id
        LEFT JOIN users d ON doc.user_id = d.id
        LEFT JOIN users du ON pr.doctor_id = du.id AND du.role = 'doctor' AND doc.id IS NULL
        WHERE $where
        ORDER BY pr.created_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    if ($prescription_id) {
        $prescription = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$prescription) {
            echo json_encode(['success' => false, 'message' => 'Prescription not found']);
            exit;
        }
    } else {
        $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($prescriptions)) {
            echo json_encode(['success' => false, 'message' => 'No active prescription found for this patient']);
            exit;
        }
        $patient_name = trim(preg_replace('/\s+/', ' ', $prescriptions[0]['patient_name']));
        foreach ($prescriptions as &$p) {
            $p['patient_name'] = trim(preg_replace('/\s+/', ' ', $p['patient_name']));
            $p['doctor_name'] = trim(preg_replace('/\s+/', ' ', $p['doctor_name']));
        }
        unset($p);
        echo json_encode(['success' => true, 'prescriptions' => $prescriptions, 'patient_name' => $patient_name]);
        exit;
    }
    
    // Single prescription: load medications and return full details
    // Check if prescription_items table has quantity and total_quantity columns
    $has_quantity = false;
    $has_total_quantity = false;
    try {
        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'quantity'");
        $has_quantity = $test_stmt->rowCount() > 0;
        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'total_quantity'");
        $has_total_quantity = $test_stmt->rowCount() > 0;
    } catch (PDOException $e) {}
    
    // Prioritize prescription_items table (has quantity and total_quantity if available)
    $stmt = $pdo->prepare("
        SELECT * FROM prescription_items 
        WHERE prescription_id = ?
        ORDER BY id
    ");
    $stmt->execute([$prescription['id']]);
    $medications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no medications found in prescription_items, try medications table
    if (empty($medications)) {
        $stmt = $pdo->prepare("
            SELECT * FROM medications 
            WHERE prescription_id = ?
            ORDER BY medication_id
        ");
        $stmt->execute([$prescription['id']]);
        $medications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add default quantity of 1 if not present
        foreach ($medications as &$med) {
            if (!isset($med['quantity'])) {
                $med['quantity'] = 1;
            }
            if (!isset($med['total_quantity'])) {
                $med['total_quantity'] = 0;
            }
        }
        unset($med);
    } else {
        // Ensure quantity and total_quantity are set
        foreach ($medications as &$med) {
            if (!isset($med['quantity']) || $med['quantity'] === null || $med['quantity'] == 0) {
                $med['quantity'] = 1;
            }
            // Use total_quantity if available, otherwise fall back to quantity
            if (!isset($med['total_quantity']) || $med['total_quantity'] === null || $med['total_quantity'] == 0) {
                $med['total_quantity'] = $med['quantity'] ?? 1;
            }
        }
        unset($med);
    }
    
    $prescription['medications'] = $medications;
    $prescription['items'] = $medications; // For compatibility
    
    // Clean up names
    $prescription['patient_name'] = trim(preg_replace('/\s+/', ' ', $prescription['patient_name']));
    $prescription['doctor_name'] = trim(preg_replace('/\s+/', ' ', $prescription['doctor_name']));
    
    echo json_encode(['success' => true, 'prescription' => $prescription]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

