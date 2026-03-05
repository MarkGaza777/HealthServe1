-- Create triage_records table for staff to record patient vitals before consultation
-- This table stores initial vitals taken during triage/screening

CREATE TABLE IF NOT EXISTS `triage_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) NOT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `blood_pressure` varchar(20) DEFAULT NULL,
  `temperature` decimal(5,2) DEFAULT NULL,
  `weight` decimal(6,2) DEFAULT NULL,
  `pulse_rate` int(11) DEFAULT NULL,
  `oxygen_saturation` decimal(5,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL COMMENT 'Staff member who recorded the triage',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_appointment` (`appointment_id`),
  KEY `idx_patient` (`patient_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_recorded_by` (`recorded_by`),
  CONSTRAINT `fk_triage_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_triage_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: patient_id and user_id are stored for reference but don't have foreign keys
-- because they can reference either the patients table or users table depending on patient type

