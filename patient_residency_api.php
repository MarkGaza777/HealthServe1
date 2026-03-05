<?php
/**
 * Patient Payatas Residency Verification API
 * - GET: return current status and request info
 * - POST: submit verification (upload documents)
 */
session_start();
require_once 'db.php';
require_once 'residency_verification_helper.php';

header('Content-Type: application/json');

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'patient') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = (int)$_SESSION['user']['id'];
ensureResidencyVerificationSchema();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = getPatientResidencyStatus($user_id);
    $request = getResidencyVerificationRequest($user_id);
    $documents = [];
    if ($request) {
        $documents = getResidencyVerificationDocuments($request['id']);
        foreach ($documents as &$d) {
            unset($d['file_path']); // Don't expose path; use view URL
            $d['view_url'] = 'view_residency_document.php?id=' . $d['id'] . '&t=' . md5($d['id'] . session_id());
        }
        unset($d);
    }
    echo json_encode([
        'success' => true,
        'status' => $status['status'],
        'rejected_reason' => $status['rejected_reason'],
        'barangay' => $status['barangay'],
        'city' => $status['city'],
        'request' => $request ? [
            'id' => $request['id'],
            'status' => $request['status'],
            'submitted_at' => $request['submitted_at'],
        ] : null,
        'documents' => $documents,
        'can_submit' => canSubmitResidencyVerification($user_id),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// POST: Submit verification
$action = $_POST['action'] ?? 'submit';
if ($action !== 'submit') {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

if (!canSubmitResidencyVerification($user_id)) {
    echo json_encode(['success' => false, 'message' => 'You already have a pending verification request. Please wait for admin review.']);
    exit;
}

// Require at least one government-issued ID
$gov_id = $_FILES['government_id'] ?? null;
$barangay_clearance = $_FILES['barangay_clearance'] ?? null;

if (!$gov_id || $gov_id['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Please upload at least one valid government-issued ID (Barangay ID, Voter\'s ID, or PhilHealth ID) showing address within Barangay Payatas, Quezon City.']);
    exit;
}

$allowed_ext = ['jpg', 'jpeg', 'png', 'pdf'];
$max_bytes = 5 * 1024 * 1024; // 5 MB

function validateUpload($file, $allowed_ext, $max_bytes) {
    if ($file['error'] !== UPLOAD_ERR_OK) return [false, 'Upload error'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext)) return [false, 'Invalid file type. Use JPG, PNG, or PDF.'];
    if ($file['size'] > $max_bytes) return [false, 'File too large (max 5MB).'];
    return [true, null];
}

list($ok, $err) = validateUpload($gov_id, $allowed_ext, $max_bytes);
if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Government ID: ' . $err]);
    exit;
}
if ($barangay_clearance && $barangay_clearance['error'] === UPLOAD_ERR_OK) {
    list($ok2, $err2) = validateUpload($barangay_clearance, $allowed_ext, $max_bytes);
    if (!$ok2) {
        echo json_encode(['success' => false, 'message' => 'Barangay Clearance: ' . $err2]);
        exit;
    }
}

$upload_dir = __DIR__ . '/uploads/residency_verification/' . $user_id;
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0750, true);
}
// Secure: prevent directory listing
if (!file_exists($upload_dir . '/.htaccess')) {
    file_put_contents($upload_dir . '/.htaccess', "Deny from all\n");
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO residency_verification_requests (user_id, status) VALUES (?, 'pending')");
    $stmt->execute([$user_id]);
    $request_id = (int)$pdo->lastInsertId();

    $saveFile = function ($file, $type) use ($request_id, $user_id, $upload_dir, $pdo) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $name = $type . '_' . $request_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $path = $upload_dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            throw new Exception('Failed to save file');
        }
        $relative = 'uploads/residency_verification/' . $user_id . '/' . $name;
        $stmt = $pdo->prepare("INSERT INTO residency_verification_documents (request_id, document_type, file_path, original_name) VALUES (?, ?, ?, ?)");
        $stmt->execute([$request_id, $type, $relative, $file['name']]);
    };

    $saveFile($gov_id, 'government_id');
    if ($barangay_clearance && $barangay_clearance['error'] === UPLOAD_ERR_OK) {
        $saveFile($barangay_clearance, 'barangay_clearance');
    }

    $pdo->exec("UPDATE users SET residency_status = 'pending', residency_rejected_reason = NULL WHERE id = $user_id");

    $pdo->commit();

    // Notify patient
    ensureNotificationsTypeColumn();
    $notif_msg = 'Your Payatas residency verification has been submitted. Our team will review it shortly.';
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, type, status) VALUES (?, ?, 'residency_verification', 'unread')");
    $stmt->execute([$user_id, $notif_msg]);

    require_once 'admin_helpers_simple.php';
    logAuditEvent('Residency verification submitted', 'Residency Verification', $request_id, "User $user_id submitted documents for Payatas residency.");

    echo json_encode(['success' => true, 'message' => 'Verification submitted successfully. You will be notified once reviewed.', 'request_id' => $request_id]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Residency submit error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to submit: ' . $e->getMessage()]);
}
