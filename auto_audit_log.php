<?php
/**
 * Auto Audit Log Helper
 * Automatically logs page access and actions for all users
 * Include this file at the top of pages to enable automatic logging
 */

if (!defined('AUTO_AUDIT_ENABLED')) {
    define('AUTO_AUDIT_ENABLED', true);
}

// Only log if session is started and user is logged in
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user'])) {
    require_once __DIR__ . '/admin_helpers_simple.php';
    
    // Get current page/action
    $script_name = basename($_SERVER['PHP_SELF']);
    $request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = 'Page Access';
    
    // Determine entity type based on page
    $entity_type = null;
    $entity_id = null;
    $details = "Accessed: {$script_name}";
    
    // Map common pages to entity types
    $page_entity_map = [
        'user_main_dashboard.php' => ['entity_type' => 'Dashboard', 'action' => 'View Dashboard'],
        'Admin_dashboard1.php' => ['entity_type' => 'Dashboard', 'action' => 'View Admin Dashboard'],
        'doctors_page.php' => ['entity_type' => 'Dashboard', 'action' => 'View Doctor Dashboard'],
        'pharmacist_dashboard.php' => ['entity_type' => 'Dashboard', 'action' => 'View Pharmacist Dashboard'],
        'fdo_page.php' => ['entity_type' => 'Dashboard', 'action' => 'View FDO Dashboard'],
        'admin_settings.php' => ['entity_type' => 'Settings', 'action' => 'View Settings'],
        'admin_staff_management.php' => ['entity_type' => 'Staff Management', 'action' => 'View Staff Management'],
        'admin_doctors_management.php' => ['entity_type' => 'Doctor Management', 'action' => 'View Doctor Management'],
        'user_appointments.php' => ['entity_type' => 'Appointment', 'action' => 'View Appointments'],
    ];
    
    if (isset($page_entity_map[$script_name])) {
        $action = $page_entity_map[$script_name]['action'];
        $entity_type = $page_entity_map[$script_name]['entity_type'];
    }
    
    // Log the access (non-blocking - errors won't stop page load)
    try {
        logAuditEvent($action, $entity_type, $entity_id, $details);
    } catch (Exception $e) {
        // Silently fail to avoid disrupting user experience
        error_log("Auto audit log error: " . $e->getMessage());
    }
}

