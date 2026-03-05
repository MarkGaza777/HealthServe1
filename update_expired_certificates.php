<?php
/**
 * Update Expired Medical Certificates
 * 
 * This script automatically updates the status of expired medical certificates.
 * It should be run daily via cron job or manually.
 * 
 * Usage:
 * - Manual: php update_expired_certificates.php
 * - Cron: 0 0 * * * php /path/to/update_expired_certificates.php
 */

require_once 'db.php';

try {
    // Check if medical_certificates table exists
    $table_check = $pdo->query("SHOW TABLES LIKE 'medical_certificates'");
    if ($table_check->rowCount() === 0) {
        echo "Medical certificates table does not exist. Please run the migration first.\n";
        exit(0);
    }
    
    $today = date('Y-m-d');
    
    // Update expired certificates
    $stmt = $pdo->prepare("
        UPDATE medical_certificates 
        SET status = 'expired' 
        WHERE expiration_date < ? 
        AND status = 'active'
    ");
    $stmt->execute([$today]);
    
    $updated_count = $stmt->rowCount();
    
    if ($updated_count > 0) {
        echo "Successfully updated $updated_count expired certificate(s).\n";
    } else {
        echo "No expired certificates to update.\n";
    }
    
    // Also update certificates that are expired but still marked as active
    // (in case the script wasn't run for a while)
    $stmt = $pdo->prepare("
        UPDATE medical_certificates 
        SET status = 'expired' 
        WHERE expiration_date < ? 
        AND status = 'active'
    ");
    $stmt->execute([$today]);
    
    echo "Expiration check completed.\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

