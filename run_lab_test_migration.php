<?php
/**
 * Run Lab Test Request Migration
 * This script creates the lab_test_requests and lab_test_results tables
 * Run this once to set up the database tables
 */

require_once 'db.php';

try {
    echo "Creating lab_test_requests table...\n";
    
    // Create lab_test_requests table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `lab_test_requests` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `patient_id` int(11) NOT NULL,
          `doctor_id` int(11) NOT NULL,
          `appointment_id` int(11) DEFAULT NULL,
          `consultation_id` int(11) DEFAULT NULL,
          `test_name` varchar(255) NOT NULL,
          `laboratory_name` varchar(255) DEFAULT NULL,
          `laboratory_type` enum('select', 'custom') DEFAULT 'select',
          `notes` text DEFAULT NULL,
          `status` enum('pending', 'completed', 'cancelled') DEFAULT 'pending',
          `requested_date` date DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `idx_patient` (`patient_id`),
          KEY `idx_doctor` (`doctor_id`),
          KEY `idx_appointment` (`appointment_id`),
          KEY `idx_consultation` (`consultation_id`),
          KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    echo "✓ lab_test_requests table created successfully\n";
    
    echo "Creating lab_test_results table...\n";
    
    // Create lab_test_results table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `lab_test_results` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `lab_test_request_id` int(11) NOT NULL,
          `patient_id` int(11) NOT NULL,
          `doctor_id` int(11) DEFAULT NULL,
          `file_path` varchar(500) NOT NULL,
          `file_name` varchar(255) NOT NULL,
          `file_type` varchar(50) DEFAULT NULL,
          `file_size` int(11) DEFAULT NULL,
          `uploaded_by` int(11) NOT NULL COMMENT 'User ID of the person who uploaded (patient or doctor)',
          `notes` text DEFAULT NULL,
          `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `idx_lab_request` (`lab_test_request_id`),
          KEY `idx_patient` (`patient_id`),
          KEY `idx_doctor` (`doctor_id`),
          KEY `idx_uploaded_by` (`uploaded_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    echo "✓ lab_test_results table created successfully\n";
    
    // Try to add foreign keys (optional - will fail silently if they already exist or if referenced tables don't exist)
    echo "Adding foreign key constraints (if possible)...\n";
    
    $foreign_keys = [
        "ALTER TABLE `lab_test_requests` ADD CONSTRAINT `fk_lab_request_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE",
        "ALTER TABLE `lab_test_requests` ADD CONSTRAINT `fk_lab_request_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`) ON DELETE CASCADE",
        "ALTER TABLE `lab_test_requests` ADD CONSTRAINT `fk_lab_request_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL",
        "ALTER TABLE `lab_test_results` ADD CONSTRAINT `fk_lab_result_request` FOREIGN KEY (`lab_test_request_id`) REFERENCES `lab_test_requests` (`id`) ON DELETE CASCADE",
        "ALTER TABLE `lab_test_results` ADD CONSTRAINT `fk_lab_result_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE",
        "ALTER TABLE `lab_test_results` ADD CONSTRAINT `fk_lab_result_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`) ON DELETE SET NULL",
        "ALTER TABLE `lab_test_results` ADD CONSTRAINT `fk_lab_result_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE"
    ];
    
    foreach ($foreign_keys as $fk_sql) {
        try {
            $pdo->exec($fk_sql);
            echo "✓ Foreign key added\n";
        } catch (PDOException $e) {
            // Foreign key might already exist or referenced table might not exist - that's okay
            echo "  (Foreign key constraint skipped - may already exist)\n";
        }
    }
    
    echo "\n✅ Migration completed successfully!\n";
    echo "The lab_test_requests and lab_test_results tables are now ready to use.\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

