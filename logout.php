<?php
session_start();
require_once 'db.php';
require_once 'admin_helpers_simple.php';

// Get user information before destroying session
$user_id = $_SESSION['user']['id'] ?? null;
$user_role = $_SESSION['user']['role'] ?? null;
$username = $_SESSION['user']['username'] ?? null;

// Log logout before destroying session
if ($user_id) {
    logAuditEvent('User Logout', 'authentication', $user_id, ucfirst($user_role ?? 'User') . " logged out");
}

// Destroy all session data
session_destroy();

// Redirect based on user role
if (in_array($user_role, ['admin', 'doctor', 'pharmacist', 'fdo'])) {
    // Staff members go to staff login page
    header("Location: staff_login.php");
} else {
    // Patients and others go to patient login page
    header("Location: Login.php");
}
exit();
?>