<?php
/**
 * View residency verification document (image/PDF).
 * - Patients can view their own documents (session).
 * - Admins can view any document (session role admin).
 */
session_start();
require_once 'db.php';
require_once 'residency_verification_helper.php';

$id = (int)($_GET['id'] ?? 0);
$t = $_GET['t'] ?? '';

if (!$id) {
    header('HTTP/1.0 404 Not Found');
    exit('Not found');
}

$user_id = $_SESSION['user']['id'] ?? null;
$role = $_SESSION['user']['role'] ?? null;

$stmt = $pdo->prepare("
    SELECT d.id, d.request_id, d.document_type, d.file_path, d.original_name, r.user_id
    FROM residency_verification_documents d
    JOIN residency_verification_requests r ON r.id = d.request_id
    WHERE d.id = ?
");
$stmt->execute([$id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    header('HTTP/1.0 404 Not Found');
    exit('Not found');
}

$allowed = false;
if ($role === 'admin') {
    $allowed = true;
} elseif ($role === 'patient' && $user_id && (int)$doc['user_id'] === (int)$user_id) {
    $token_ok = ($t === md5($id . session_id()));
    $allowed = $token_ok;
}

if (!$allowed) {
    header('HTTP/1.0 403 Forbidden');
    exit('Access denied');
}

$path = __DIR__ . '/' . $doc['file_path'];
if (!file_exists($path) || !is_readable($path)) {
    header('HTTP/1.0 404 Not Found');
    exit('File not found');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'pdf' => 'application/pdf',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . ($doc['original_name'] ?: 'document.' . $ext) . '"');
readfile($path);
exit;
