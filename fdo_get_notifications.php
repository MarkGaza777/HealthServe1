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

$user_id = (int) $_SESSION['user']['id'];
$action = $_GET['action'] ?? 'fetch';

try {
    // Ensure fdo_notifications table exists (create if missing for backward compatibility)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `fdo_notifications` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `appointment_id` int(11) NOT NULL,
          `patient_name` varchar(255) NOT NULL,
          `appointment_date` date NOT NULL,
          `appointment_time` time NOT NULL,
          `complaint` varchar(500) DEFAULT NULL,
          `appointment_status` varchar(50) NOT NULL DEFAULT 'pending',
          `is_read` tinyint(1) NOT NULL DEFAULT 0,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `idx_fdo_user_read` (`user_id`, `is_read`),
          KEY `idx_fdo_created` (`user_id`, `created_at`),
          KEY `idx_appointment` (`appointment_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if ($action === 'unread_count') {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM fdo_notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'unread_count' => (int) ($row['cnt'] ?? 0)]);
        exit;
    }

    if ($action === 'mark_read') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE fdo_notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $user_id]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE fdo_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // fetch: list all notifications for this FDO, newest first
    $stmt = $pdo->prepare("
        SELECT id, appointment_id, patient_name, appointment_date, appointment_time, complaint, appointment_status, is_read, created_at
        FROM fdo_notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifications = [];
    foreach ($rows as $r) {
        $dateStr = date('F j, Y', strtotime($r['appointment_date']));
        $timeStr = date('g:i A', strtotime($r['appointment_time']));
        $statusLabel = $r['appointment_status'] === 'pending' ? 'Pending' : ucfirst(strtolower($r['appointment_status']));
        $notifications[] = [
            'id' => (int) $r['id'],
            'appointment_id' => (int) $r['appointment_id'],
            'patient_name' => $r['patient_name'],
            'date' => $dateStr,
            'time' => $timeStr,
            'complaint' => $r['complaint'] ?: '—',
            'status' => $statusLabel,
            'appointment_status' => $r['appointment_status'],
            'is_read' => (bool) $r['is_read'],
            'created_at' => $r['created_at'],
        ];
    }

    echo json_encode(['success' => true, 'notifications' => $notifications]);
} catch (Exception $e) {
    error_log('FDO notifications error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
