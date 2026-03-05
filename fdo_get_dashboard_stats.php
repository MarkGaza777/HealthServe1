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

try {
    // First, automatically mark announcements as expired if their end_date has passed
    $now = date('Y-m-d H:i:s');
    $expire_stmt = $pdo->prepare("
        UPDATE announcements 
        SET status = 'expired' 
        WHERE status != 'expired' 
        AND end_date IS NOT NULL 
        AND end_date <= ?
    ");
    $expire_stmt->execute([$now]);
    
    // 1. Count Pending Appointments (all pending appointments)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM appointments a
        WHERE (a.fdo_id = ? OR a.fdo_id IS NULL)
        AND a.status = 'pending'
    ");
    $stmt->execute([$fdo_id]);
    $pendingResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $pendingAppointments = (int)($pendingResult['count'] ?? 0);
    
    // 2. Count Today's Appointments (all statuses for today)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM appointments a
        WHERE (a.fdo_id = ? OR a.fdo_id IS NULL)
        AND DATE(a.start_datetime) = CURDATE()
    ");
    $stmt->execute([$fdo_id]);
    $todayResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $todayAppointments = (int)($todayResult['count'] ?? 0);
    
    // 3. Count Active Announcements (approved announcements, excluding expired)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM announcements
        WHERE status = 'approved'
    ");
    $stmt->execute();
    $activeResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $activeAnnouncements = (int)($activeResult['count'] ?? 0);
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'pending_appointments' => $pendingAppointments,
            'today_appointments' => $todayAppointments,
            'active_announcements' => $activeAnnouncements
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

