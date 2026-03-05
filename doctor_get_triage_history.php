<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : null;
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : null;
$single_date = isset($_GET['single_date']) ? $_GET['single_date'] : null;

if ($patient_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid patient ID']);
    exit();
}

try {
    // Check if triage_records table exists
    $table_check = $pdo->query("SHOW TABLES LIKE 'triage_records'");
    $table_exists = $table_check->rowCount() > 0;
    
    if (!$table_exists) {
        echo json_encode(['success' => true, 'records' => []]);
        exit();
    }
    
    // Get doctor_id to ensure we only show records from this doctor's appointments
    $user_id = $_SESSION['user']['id'];
    $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    $doctor_id = $doctor ? $doctor['id'] : 0;
    
    // Build query to get all triage records for this patient
    // Match the same logic used in doctor_consultation.php for patient matching
    $sql = "
        SELECT tr.*, 
               CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as recorded_by_name,
               a.start_datetime as appointment_date
        FROM triage_records tr
        INNER JOIN appointments a ON a.id = tr.appointment_id
        LEFT JOIN users u ON u.id = tr.recorded_by
        WHERE a.doctor_id = ?
        AND (
            (a.user_id = ? AND a.patient_id = ?) 
            OR (a.user_id = ? AND a.patient_id IS NULL)
            OR a.patient_id = ?
        )
    ";
    
    $params = [$doctor_id, $patient_id, $patient_id, $patient_id, $patient_id];
    
    // Add date filtering
    if ($single_date) {
        // Filter by single date
        $sql .= " AND DATE(tr.created_at) = ?";
        $params[] = $single_date;
    } elseif ($date_from && $date_to) {
        // Filter by date range
        $sql .= " AND DATE(tr.created_at) BETWEEN ? AND ?";
        $params[] = $date_from;
        $params[] = $date_to;
    } elseif ($date_from) {
        // Only from date specified
        $sql .= " AND DATE(tr.created_at) >= ?";
        $params[] = $date_from;
    } elseif ($date_to) {
        // Only to date specified
        $sql .= " AND DATE(tr.created_at) <= ?";
        $params[] = $date_to;
    }
    
    // Order by most recent first
    $sql .= " ORDER BY tr.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'records' => $records]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

