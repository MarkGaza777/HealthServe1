<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$appointment_id = isset($_GET['appointment_id']) ? (int) $_GET['appointment_id'] : 0;

if ($appointment_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid appointment ID']);
    exit();
}

try {
    // Check if triage_records table exists
    $table_check = $pdo->query("SHOW TABLES LIKE 'triage_records'");
    $table_exists = $table_check->rowCount() > 0;
    
    if (!$table_exists) {
        echo json_encode(['success' => false, 'message' => 'Triage table not found']);
        exit();
    }
    
    // Get triage for this appointment
    $stmt = $pdo->prepare("
        SELECT tr.*, 
               CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as recorded_by_name
        FROM triage_records tr
        LEFT JOIN users u ON u.id = tr.recorded_by
        WHERE tr.appointment_id = ?
        ORDER BY tr.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$appointment_id]);
    $triage = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($triage) {
        echo json_encode(['success' => true, 'triage' => $triage]);
    } else {
        echo json_encode(['success' => true, 'triage' => null]);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

