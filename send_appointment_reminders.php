<?php
/**
 * Appointment Reminder Email Cron Job
 * 
 * This script should be run periodically (e.g., every hour via cron) to send
 * 12-hour reminder emails for approved appointments.
 * 
 * Cron setup example (runs every hour):
 * 0 * * * * /usr/bin/php /path/to/healthyc/send_appointment_reminders.php
 * 
 * Or for Windows Task Scheduler, create a scheduled task that runs:
 * php.exe C:\path\to\healthyc\send_appointment_reminders.php
 */

// Set time limit for long-running script
set_time_limit(300); // 5 minutes

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Log script execution
error_log("Appointment reminder cron job started at " . date('Y-m-d H:i:s'));

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/appointment_email_service.php';

try {
    // Ensure the reminder tracking table exists
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `appointment_email_reminders` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `appointment_id` int(11) NOT NULL,
              `reminder_sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `reminder_type` enum('12_hour') DEFAULT '12_hour',
              PRIMARY KEY (`id`),
              UNIQUE KEY `idx_appointment_reminder` (`appointment_id`, `reminder_type`),
              KEY `idx_appointment` (`appointment_id`),
              KEY `idx_sent_at` (`reminder_sent_at`),
              CONSTRAINT `fk_reminder_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks sent appointment reminder emails to prevent duplicates'
        ");
    } catch (PDOException $e) {
        // Table might already exist, continue
        error_log("Note: Reminder table check: " . $e->getMessage());
    }
    
    // Calculate the target time range (approximately 12 hours from now)
    // We'll check appointments between 11.5 and 12.5 hours from now to account for cron timing
    $now = new DateTime();
    $target_start = clone $now;
    $target_start->modify('+11 hours 30 minutes');
    
    $target_end = clone $now;
    $target_end->modify('+12 hours 30 minutes');
    
    // Find approved appointments that are approximately 12 hours away
    // and haven't had a reminder sent yet
    $stmt = $pdo->prepare("
        SELECT 
            a.id,
            a.start_datetime,
            a.status,
            a.user_id,
            a.patient_id,
            pu.email as patient_email
        FROM appointments a
        LEFT JOIN users pu ON (a.user_id = pu.id OR a.patient_id = pu.id)
        LEFT JOIN appointment_email_reminders r ON (
            a.id = r.appointment_id 
            AND r.reminder_type = '12_hour'
        )
        WHERE a.status = 'approved'
          AND a.start_datetime >= ?
          AND a.start_datetime <= ?
          AND r.id IS NULL
          AND pu.email IS NOT NULL
          AND pu.email != ''
        ORDER BY a.start_datetime ASC
    ");
    
    $stmt->execute([
        $target_start->format('Y-m-d H:i:s'),
        $target_end->format('Y-m-d H:i:s')
    ]);
    
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $sent_count = 0;
    $failed_count = 0;
    
    foreach ($appointments as $appointment) {
        try {
            // Send reminder email
            $email_sent = sendAppointmentEmail($appointment['id'], 'reminder');
            
            if ($email_sent) {
                // Record that reminder was sent
                $insert_stmt = $pdo->prepare("
                    INSERT INTO appointment_email_reminders (appointment_id, reminder_type, reminder_sent_at)
                    VALUES (?, '12_hour', NOW())
                    ON DUPLICATE KEY UPDATE reminder_sent_at = NOW()
                ");
                $insert_stmt->execute([$appointment['id']]);
                
                $sent_count++;
                error_log("Reminder sent for appointment ID: " . $appointment['id'] . " (Patient: " . $appointment['patient_email'] . ")");
            } else {
                $failed_count++;
                error_log("Failed to send reminder for appointment ID: " . $appointment['id']);
            }
        } catch (Exception $e) {
            $failed_count++;
            error_log("Error processing reminder for appointment ID " . $appointment['id'] . ": " . $e->getMessage());
        }
    }
    
    // Log summary
    $summary = sprintf(
        "Appointment reminder cron job completed. Sent: %d, Failed: %d, Total checked: %d",
        $sent_count,
        $failed_count,
        count($appointments)
    );
    error_log($summary);
    
    // Output summary if run from command line
    if (php_sapi_name() === 'cli') {
        echo $summary . "\n";
    }
    
} catch (Exception $e) {
    $error_msg = "Fatal error in appointment reminder cron job: " . $e->getMessage();
    error_log($error_msg);
    
    if (php_sapi_name() === 'cli') {
        echo $error_msg . "\n";
    }
    
    exit(1);
}

exit(0);

