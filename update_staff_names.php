<?php
/**
 * Update Staff Names Script
 * Updates names for Pharmacist, Admin, and FDO
 */

require_once 'db.php';

try {
    // Update Pharmacist name: Michelle Honrubia with phone 09773238989
    $stmt = $pdo->prepare("
        UPDATE users 
        SET first_name = 'Michelle', 
            last_name = 'Honrubia',
            middle_name = NULL,
            contact_no = '09773238989'
        WHERE role = 'pharmacist'
        LIMIT 1
    ");
    $stmt->execute();
    $pharmacist_updated = $stmt->rowCount();
    
    // Update Admin name: Jerry Sandoval with phone 09896531827
    $stmt = $pdo->prepare("
        UPDATE users 
        SET first_name = 'Jerry', 
            last_name = 'Sandoval',
            middle_name = NULL,
            contact_no = '09896531827'
        WHERE role = 'admin'
        LIMIT 1
    ");
    $stmt->execute();
    $admin_updated = $stmt->rowCount();
    
    // Update FDO name: Christine Joy Juanir with phone 09128734275
    $stmt = $pdo->prepare("
        UPDATE users 
        SET first_name = 'Christine', 
            middle_name = 'Joy',
            last_name = 'Juanir',
            contact_no = '09128734275'
        WHERE role = 'fdo'
        LIMIT 1
    ");
    $stmt->execute();
    $fdo_updated = $stmt->rowCount();
    
    echo "<h2>Staff Names and Contact Numbers Updated Successfully!</h2>";
    echo "<p>Pharmacist updated: " . ($pharmacist_updated > 0 ? "Yes (Michelle Honrubia - 09773238989)" : "No (may already be correct or not found)") . "</p>";
    echo "<p>Admin updated: " . ($admin_updated > 0 ? "Yes (Jerry Sandoval - 09896531827)" : "No (may already be correct or not found)") . "</p>";
    echo "<p>FDO updated: " . ($fdo_updated > 0 ? "Yes (Christine Joy Juanir - 09128734275)" : "No (may already be correct or not found)") . "</p>";
    
    // Show current staff
    echo "<h3>Current Staff:</h3>";
    
    // Get Pharmacist
    $stmt = $pdo->prepare("SELECT first_name, middle_name, last_name, contact_no, role FROM users WHERE role = 'pharmacist'");
    $stmt->execute();
    $pharmacist = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($pharmacist) {
        $name = trim(($pharmacist['first_name'] ?? '') . ' ' . ($pharmacist['middle_name'] ?? '') . ' ' . ($pharmacist['last_name'] ?? ''));
        echo "<p><strong>Pharmacist:</strong> " . htmlspecialchars($name) . " - " . htmlspecialchars($pharmacist['contact_no'] ?? 'N/A') . "</p>";
    }
    
    // Get Admin
    $stmt = $pdo->prepare("SELECT first_name, middle_name, last_name, contact_no, role FROM users WHERE role = 'admin'");
    $stmt->execute();
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($admin) {
        $name = trim(($admin['first_name'] ?? '') . ' ' . ($admin['middle_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));
        echo "<p><strong>Admin:</strong> " . htmlspecialchars($name) . " - " . htmlspecialchars($admin['contact_no'] ?? 'N/A') . "</p>";
    }
    
    // Get FDO
    $stmt = $pdo->prepare("SELECT first_name, middle_name, last_name, contact_no, role FROM users WHERE role = 'fdo'");
    $stmt->execute();
    $fdo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fdo) {
        $name = trim(($fdo['first_name'] ?? '') . ' ' . ($fdo['middle_name'] ?? '') . ' ' . ($fdo['last_name'] ?? ''));
        echo "<p><strong>FDO:</strong> " . htmlspecialchars($name) . " - " . htmlspecialchars($fdo['contact_no'] ?? 'N/A') . "</p>";
    }
    
    // Get Doctors
    $stmt = $pdo->prepare("
        SELECT u.first_name, u.middle_name, u.last_name, d.specialization
        FROM doctors d
        LEFT JOIN users u ON d.user_id = u.id
        WHERE u.role = 'doctor'
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute();
    $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($doctors) {
        echo "<p><strong>Doctors:</strong></p><ul>";
        foreach ($doctors as $doctor) {
            $name = trim(($doctor['first_name'] ?? '') . ' ' . ($doctor['middle_name'] ?? '') . ' ' . ($doctor['last_name'] ?? ''));
            echo "<li>" . htmlspecialchars($name) . " - " . htmlspecialchars($doctor['specialization'] ?? 'N/A') . "</li>";
        }
        echo "</ul>";
    }
    
    echo "<p><a href='admin_staff_management.php'>Go to Staff Management</a></p>";
    
} catch(PDOException $e) {
    echo "<h2>Error:</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

