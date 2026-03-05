<?php
/**
 * Admin: Approve or Reject a residency verification request.
 */
session_start();
require_once 'db.php';
require_once 'admin_helpers_simple.php';
require_once 'residency_verification_helper.php';

header('Content-Type: application/json');

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? ''; // 'approve' or 'reject'
$request_id = (int)($_POST['request_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

if (!in_array($action, ['approve', 'reject'], true) || $request_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

if ($action === 'reject' && $reason === '') {
    echo json_encode(['success' => false, 'message' => 'Please provide a rejection reason.']);
    exit;
}

ensureResidencyVerificationSchema();

$stmt = $pdo->prepare("SELECT * FROM residency_verification_requests WHERE id = ? AND status = 'pending'");
$stmt->execute([$request_id]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) {
    echo json_encode(['success' => false, 'message' => 'Request not found or already processed.']);
    exit;
}

$user_id = (int)$req['user_id'];
$admin_id = (int)$_SESSION['user']['id'];
$admin_name = trim(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? ''));
if (empty($admin_name)) {
    $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $stmt->execute([$admin_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    $admin_name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
}

try {
    $pdo->beginTransaction();

    if ($action === 'approve') {
        $pdo->prepare("UPDATE residency_verification_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = NULL WHERE id = ?")->execute([$admin_id, $request_id]);
        $pdo->prepare("UPDATE users SET residency_status = 'verified', residency_rejected_reason = NULL WHERE id = ?")->execute([$user_id]);
        $log_action = 'approved';
        $log_reason = null;
        $notif_msg = 'Your Payatas residency verification has been approved. You can now book appointments, request lab tests, and receive prescriptions.';
    } else {
        $pdo->prepare("UPDATE residency_verification_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ? WHERE id = ?")->execute([$admin_id, $reason, $request_id]);
        $pdo->prepare("UPDATE users SET residency_status = 'rejected', residency_rejected_reason = ? WHERE id = ?")->execute([$reason, $user_id]);
        $log_action = 'rejected';
        $log_reason = $reason;
        $notif_msg = 'Your Payatas residency verification was not approved. Reason: ' . $reason . '. You may submit new documents from your Profile.';
    }

    $pdo->prepare("
        INSERT INTO residency_verification_audit_log (request_id, user_id, admin_id, admin_name, action, reason)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$request_id, $user_id, $admin_id, $admin_name, $log_action, $log_reason]);

    // Notify patient
    ensureNotificationsTypeColumn();
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, 'residency_verification', 'unread')");
    $stmt->execute([$user_id, $notif_msg]);

    $pdo->commit();

    logAuditEvent('Residency verification ' . $log_action, 'Residency Verification', $request_id, 'Request #' . $request_id . ' for user ' . $user_id . ' by ' . $admin_name);

    echo json_encode(['success' => true, 'message' => $action === 'approve' ? 'Verification approved.' : 'Verification rejected.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Residency action error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Action failed.']);
}
