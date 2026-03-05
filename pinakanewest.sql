-- phpMyAdmin SQL Dump
-- HealthServe Payatas B - Complete Database Schema
-- Version: 2.0 - Fully Functional
-- Generated: 2025
-- 
-- This schema supports all system functionalities:
-- - User management (admin, doctor, pharmacist, fdo, patient)
-- - Appointments (online only)
-- - Prescriptions and medications
-- - Inventory management
-- - Announcements and notifications
-- - Medical records and consultations
-- - Staff management
-- - Patient dependents

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `health_center1`
-- Note: Changed from health_center5 to match db.php configuration
--

DROP DATABASE IF EXISTS `health_center1`;
CREATE DATABASE `health_center1` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `health_center1`;

-- Disable foreign key checks temporarily to avoid constraint errors during import
SET FOREIGN_KEY_CHECKS=0;

-- --------------------------------------------------------

--
-- Table structure for table `users`
-- Central user management for all roles
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `role` enum('admin','doctor','pharmacist','fdo','patient') NOT NULL,
  `first_name` varchar(120) DEFAULT NULL,
  `middle_name` varchar(120) DEFAULT NULL,
  `last_name` varchar(120) DEFAULT NULL,
  `contact_no` varchar(11) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_role` (`role`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `doctors`
-- Doctor-specific information linked to users
--

CREATE TABLE `doctors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `specialization` varchar(120) DEFAULT NULL,
  `clinic_room` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_doctors_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patient_profiles`
-- Detailed patient information linked to users
--

CREATE TABLE `patient_profiles` (
  `patient_id` int(11) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `sex` enum('male','female','other') DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `blood_type` enum('A+','A-','B+','B-','AB+','AB-','O+','O-') DEFAULT NULL,
  `height_cm` decimal(5,2) DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `marital_status` enum('single','married','separated','widowed','other') DEFAULT NULL,
  `occupation` varchar(150) DEFAULT NULL,
  `emergency_contact_name` varchar(255) DEFAULT NULL,
  `emergency_contact_relationship` varchar(100) DEFAULT NULL,
  `emergency_contact_phone` varchar(50) DEFAULT NULL,
  `emergency_contact` varchar(255) DEFAULT NULL,
  `medical_history` text DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  PRIMARY KEY (`patient_id`),
  CONSTRAINT `fk_patient_profiles_user` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patients`
-- Separate patients table (used in some legacy code)
--

CREATE TABLE `patients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(120) DEFAULT NULL,
  `last_name` varchar(120) DEFAULT NULL,
  `middle_name` varchar(120) DEFAULT NULL,
  `sex` enum('male','female') DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `civil_status` enum('single','married','divorced','widowed') DEFAULT NULL,
  `philhealth_no` varchar(50) DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `emergency_contact` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created_by` (`created_by_user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_patients_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `schedules`
-- Doctor availability and time slots
--

CREATE TABLE `schedules` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `doctor_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `time_start` time NOT NULL,
  `time_end` time NOT NULL,
  `availability` enum('available','occupied','blocked') DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`schedule_id`),
  KEY `idx_doctor_date` (`doctor_id`,`date`),
  KEY `idx_date` (`date`),
  CONSTRAINT `fk_schedules_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
-- Patient appointments with FDO, doctor, and patient links
-- Supports both user_id and patient_id patterns
--

CREATE TABLE `appointments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `doctor_id` int(11) DEFAULT NULL,
  `fdo_id` int(11) DEFAULT NULL,
  `start_datetime` datetime NOT NULL,
  `duration_minutes` int(11) DEFAULT 20,
  `status` enum('pending','approved','completed','cancelled','rescheduled','declined') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `diagnosis` text DEFAULT NULL,
  `prescription` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `appointment_date` date GENERATED ALWAYS AS (cast(`start_datetime` as date)) STORED,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_patient` (`patient_id`),
  KEY `idx_doctor` (`doctor_id`),
  KEY `idx_fdo` (`fdo_id`),
  KEY `idx_date` (`appointment_date`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_appointments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_fdo` FOREIGN KEY (`fdo_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prescriptions`
-- Prescriptions issued by doctors
-- Supports both old and new schema patterns
--

CREATE TABLE `prescriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) DEFAULT NULL,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `date_issued` date DEFAULT NULL,
  `medication` text DEFAULT NULL,
  `dosage` varchar(255) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `diagnosis` text DEFAULT NULL,
  `refill_count` int(11) DEFAULT 0,
  `status` enum('draft','active','sent','completed','cancelled') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_appointment` (`appointment_id`),
  KEY `idx_patient` (`patient_id`),
  KEY `idx_doctor` (`doctor_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_prescriptions_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_prescriptions_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prescriptions_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `medications`
-- Individual medications in prescriptions (new schema)
--

CREATE TABLE `medications` (
  `medication_id` int(11) NOT NULL AUTO_INCREMENT,
  `prescription_id` int(11) NOT NULL,
  `drug_name` varchar(255) NOT NULL,
  `dosage` varchar(100) DEFAULT NULL,
  `frequency` varchar(100) DEFAULT NULL,
  `duration` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`medication_id`),
  KEY `idx_prescription` (`prescription_id`),
  CONSTRAINT `fk_medications_prescription` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prescription_items`
-- Prescription items (alternative schema used by doctor portal)
--

CREATE TABLE `prescription_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `prescription_id` int(11) NOT NULL,
  `medicine_name` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `dosage` varchar(100) DEFAULT NULL,
  `frequency` varchar(100) DEFAULT NULL,
  `duration` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_prescription` (`prescription_id`),
  CONSTRAINT `fk_prescription_items_prescription` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
-- Inventory items management
--

CREATE TABLE `inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_name` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `quantity` int(11) DEFAULT 0,
  `reorder_level` int(11) DEFAULT 10,
  `unit` varchar(50) DEFAULT 'pcs',
  `supplier` varchar(255) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `batch_no` varchar(100) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `last_dispensed_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category`),
  KEY `idx_expiry` (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_transactions`
-- Inventory movement tracking
--

CREATE TABLE `inventory_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `medicine_id` int(11) DEFAULT NULL,
  `inventory_item_id` int(11) DEFAULT NULL,
  `type` enum('in','out') NOT NULL,
  `quantity` int(11) NOT NULL,
  `reference` text DEFAULT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `performed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_medicine` (`medicine_id`),
  KEY `idx_inventory_item` (`inventory_item_id`),
  KEY `idx_performed_by` (`performed_by`),
  CONSTRAINT `fk_inventory_trans_medicine` FOREIGN KEY (`medicine_id`) REFERENCES `medications` (`medication_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_trans_item` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_trans_user` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
-- System announcements for all users
--

CREATE TABLE `announcements` (
  `announcement_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `category` enum('General','Event','Health Tip','Training','Program','Reminder') DEFAULT 'General',
  `target_audience` enum('all','patients','doctors','pharmacists','fdo','admin') DEFAULT 'all',
  `posted_by` int(11) NOT NULL,
  `status` enum('pending','approved','rejected','active','inactive') DEFAULT 'pending',
  `fdo_approved_by` int(11) DEFAULT NULL,
  `fdo_approved_at` timestamp NULL DEFAULT NULL,
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `schedule` varchar(255) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `date_posted` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`announcement_id`),
  KEY `idx_posted_by` (`posted_by`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`start_date`,`end_date`),
  KEY `idx_fdo_approved_by` (`fdo_approved_by`),
  CONSTRAINT `fk_announcements_user` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_announcements_fdo_approved` FOREIGN KEY (`fdo_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
-- User notifications system
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) DEFAULT NULL,
  `status` enum('unread','read') DEFAULT 'unread',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dependents`
-- Patient family members/dependents
--

CREATE TABLE `dependents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `first_name` varchar(120) NOT NULL,
  `middle_name` varchar(120) DEFAULT NULL,
  `last_name` varchar(120) NOT NULL,
  `relationship` enum('Son','Daughter','Spouse','Parent','Sibling','Grandparent','Others') NOT NULL,
  `date_of_birth` date NOT NULL,
  `age` int(11) DEFAULT NULL,
  `sex` enum('Male','Female','Prefer not to say') DEFAULT NULL,
  `medical_conditions` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_patient` (`patient_id`),
  CONSTRAINT `fk_dependents_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `medical_records`
-- Medical records for diagnosis/treatment per appointment
--

CREATE TABLE `medical_records` (
  `record_id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `diagnosis` text DEFAULT NULL,
  `date_recorded` date DEFAULT (CURRENT_DATE),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`record_id`),
  KEY `idx_patient` (`patient_id`),
  KEY `idx_doctor` (`doctor_id`),
  KEY `idx_appointment` (`appointment_id`),
  CONSTRAINT `fk_medical_records_patient` FOREIGN KEY (`patient_id`) REFERENCES `patient_profiles` (`patient_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_medical_records_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_medical_records_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `doctor_consultations`
-- Doctor consultation records
--

CREATE TABLE `doctor_consultations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `doctor_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `findings` text DEFAULT NULL,
  `diagnosis` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `chief_complaint` text DEFAULT NULL,
  `temperature` varchar(20) DEFAULT NULL,
  `blood_pressure` varchar(20) DEFAULT NULL,
  `pulse_rate` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_doctor` (`doctor_id`),
  KEY `idx_patient` (`patient_id`),
  KEY `idx_appointment` (`appointment_id`),
  CONSTRAINT `fk_consultations_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_consultations_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_consultations_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patient_followups`
-- Patient follow-up appointments
--

CREATE TABLE `patient_followups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `followup_datetime` datetime NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','completed','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_patient` (`patient_id`),
  KEY `idx_doctor` (`doctor_id`),
  KEY `idx_datetime` (`followup_datetime`),
  CONSTRAINT `fk_followups_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_followups_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vital_signs`
-- Patient vital signs per appointment
--

CREATE TABLE `vital_signs` (
  `vitals_id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `blood_pressure` varchar(20) DEFAULT NULL,
  `temperature` decimal(4,1) DEFAULT NULL,
  `weight` decimal(6,2) DEFAULT NULL,
  `height` decimal(6,2) DEFAULT NULL,
  `pulse_rate` int(11) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`vitals_id`),
  KEY `idx_patient` (`patient_id`),
  KEY `idx_appointment` (`appointment_id`),
  CONSTRAINT `fk_vitals_patient` FOREIGN KEY (`patient_id`) REFERENCES `patient_profiles` (`patient_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vitals_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff`
-- Staff management (used by admin)
--

CREATE TABLE `staff` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(120) NOT NULL,
  `middle_name` varchar(120) DEFAULT NULL,
  `last_name` varchar(120) NOT NULL,
  `role` enum('physician','nurse','midwife','bhw','admin') NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `access_level` enum('full','limited','read_only','department_only') DEFAULT 'limited',
  `status` enum('active','inactive') DEFAULT 'active',
  `shift_status` enum('on_duty','off_duty') DEFAULT 'off_duty',
  `photo_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_status` (`status`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dispenses`
-- Medication dispensing records
--

CREATE TABLE `dispenses` (
  `dispense_id` int(11) NOT NULL AUTO_INCREMENT,
  `prescription_id` int(11) NOT NULL,
  `medication_id` int(11) DEFAULT NULL,
  `inventory_item_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `dispensed_by` int(11) NOT NULL,
  `dispensed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`dispense_id`),
  KEY `idx_prescription` (`prescription_id`),
  KEY `idx_medication` (`medication_id`),
  KEY `idx_inventory` (`inventory_item_id`),
  KEY `idx_dispensed_by` (`dispensed_by`),
  CONSTRAINT `fk_dispenses_prescription` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dispenses_medication` FOREIGN KEY (`medication_id`) REFERENCES `medications` (`medication_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dispenses_inventory` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dispenses_user` FOREIGN KEY (`dispensed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Dumping data for table `users`
-- Sample users for all roles
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `email`, `role`, `first_name`, `middle_name`, `last_name`, `contact_no`, `address`) VALUES
-- Admin
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@healthserve.ph', 'admin', 'Jerry', NULL, 'Sandoval', '09896531827', 'Health Center Office'),
-- Doctors
(2, 'drgumiran', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'drgumiran@healthserve.ph', 'doctor', 'Nomer', NULL, 'Gumiran', '09111111111', 'Health Center'),
(3, 'drdelacosta', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'drdelacosta@healthserve.ph', 'doctor', 'Maria', NULL, 'Dela Costa', '09222222222', 'Health Center'),
(4, 'drsantos', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'drsantos@healthserve.ph', 'doctor', 'Juan', NULL, 'Santos', '09333333333', 'Health Center'),
-- Pharmacist
(5, 'pharmacist', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'pharmacist@healthserve.ph', 'pharmacist', 'Michelle', NULL, 'Honrubia', '09773238989', 'Pharmacy Department'),
-- FDO
(6, 'fdo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'fdo@healthserve.ph', 'fdo', 'Christine', 'Joy', 'Juanir', '09128734275', 'Front Desk'),
-- Patients
(7, 'patient1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'patient1@email.com', 'patient', 'Juan', 'Dela', 'Cruz', '09666666666', 'Payatas B, Quezon City'),
(8, 'patient2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'patient2@email.com', 'patient', 'Maria', NULL, 'Santos', '09777777777', 'Payatas B, Quezon City'),
(9, 'patient3', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'patient3@email.com', 'patient', 'Pablo', NULL, 'Escobar', '09888888888', 'Payatas B, Quezon City');

-- Note: Default password for all users is 'password' (hashed with bcrypt)

-- --------------------------------------------------------

--
-- Dumping data for table `doctors`
--

INSERT INTO `doctors` (`id`, `user_id`, `specialization`, `clinic_room`) VALUES
(1, 2, 'General Medicine', 'Room 101'),
(2, 3, 'Pediatrics', 'Room 102'),
(3, 4, 'Family Medicine', 'Room 103');

-- --------------------------------------------------------

--
-- Dumping data for table `patient_profiles`
--

INSERT INTO `patient_profiles` (`patient_id`, `date_of_birth`, `sex`, `gender`, `blood_type`, `height_cm`, `weight_kg`, `marital_status`, `occupation`, `emergency_contact_name`, `emergency_contact_relationship`, `emergency_contact_phone`, `emergency_contact`) VALUES
(7, '1990-01-15', 'male', 'male', 'O+', 170.00, 70.00, 'married', 'Construction Worker', 'Maria Dela Cruz', 'Wife', '09111111111', 'Maria Dela Cruz - Wife - 09111111111'),
(8, '1992-05-20', 'female', 'female', 'A+', 160.00, 55.00, 'single', 'Teacher', 'Juan Santos', 'Father', '09222222222', 'Juan Santos - Father - 09222222222'),
(9, '1985-08-10', 'male', 'male', 'B+', 175.00, 80.00, 'married', 'Driver', 'Ana Escobar', 'Wife', '09333333333', 'Ana Escobar - Wife - 09333333333');

-- --------------------------------------------------------

--
-- Dumping data for table `schedules`
-- Sample doctor schedules
--

INSERT INTO `schedules` (`schedule_id`, `doctor_id`, `date`, `time_start`, `time_end`, `availability`) VALUES
-- Dr. Gumiran - Today
(1, 1, CURDATE(), '08:00:00', '09:00:00', 'available'),
(2, 1, CURDATE(), '09:00:00', '10:00:00', 'available'),
(3, 1, CURDATE(), '10:00:00', '11:00:00', 'available'),
(4, 1, CURDATE(), '11:00:00', '12:00:00', 'occupied'),
(5, 1, CURDATE(), '12:00:00', '13:00:00', 'occupied'),
(6, 1, CURDATE(), '13:00:00', '14:00:00', 'available'),
(7, 1, CURDATE(), '14:00:00', '15:00:00', 'available'),
(8, 1, CURDATE(), '15:00:00', '16:00:00', 'available'),
-- Dr. Dela Costa - Today
(9, 2, CURDATE(), '08:00:00', '09:00:00', 'available'),
(10, 2, CURDATE(), '09:00:00', '10:00:00', 'available'),
(11, 2, CURDATE(), '10:00:00', '11:00:00', 'available'),
(12, 2, CURDATE(), '11:00:00', '12:00:00', 'occupied'),
(13, 2, CURDATE(), '12:00:00', '13:00:00', 'occupied'),
(14, 2, CURDATE(), '13:00:00', '14:00:00', 'available'),
(15, 2, CURDATE(), '14:00:00', '15:00:00', 'available'),
(16, 2, CURDATE(), '15:00:00', '16:00:00', 'available');

-- --------------------------------------------------------

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`id`, `user_id`, `patient_id`, `doctor_id`, `fdo_id`, `start_datetime`, `duration_minutes`, `status`, `notes`) VALUES
(1, 7, 7, 1, 6, DATE_ADD(NOW(), INTERVAL 1 DAY), 30, 'pending', 'Regular checkup'),
(2, 8, 8, 2, 6, DATE_ADD(NOW(), INTERVAL 2 DAY), 20, 'approved', 'Follow-up appointment'),
(3, 7, 7, 1, 6, NOW(), 30, 'completed', 'Completed consultation'),
(4, 9, 9, 1, 6, DATE_SUB(NOW(), INTERVAL 1 DAY), 30, 'completed', 'Previous consultation'),
(5, 8, 8, 2, 6, DATE_ADD(NOW(), INTERVAL 3 DAY), 20, 'pending', 'New patient consultation');

-- --------------------------------------------------------

--
-- Dumping data for table `prescriptions`
--

INSERT INTO `prescriptions` (`id`, `appointment_id`, `patient_id`, `doctor_id`, `date_issued`, `notes`, `status`) VALUES
(1, 3, 7, 1, CURDATE(), 'Take medication as prescribed', 'active'),
(2, 4, 9, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Complete full course', 'active');

-- --------------------------------------------------------

--
-- Dumping data for table `medications`
--

INSERT INTO `medications` (`medication_id`, `prescription_id`, `drug_name`, `dosage`, `frequency`, `duration`) VALUES
(1, 1, 'Paracetamol 500mg', '1 tablet', 'Every 6 hours', '5 days'),
(2, 1, 'Amoxicillin 250mg', '1 capsule', 'Twice daily', '7 days'),
(3, 2, 'Cetirizine 10mg', '1 tablet', 'Once daily', '3 days'),
(4, 2, 'Loperamide 2mg', '1 tablet', 'As needed', '3 days');

-- --------------------------------------------------------

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`id`, `item_name`, `category`, `quantity`, `reorder_level`, `unit`, `supplier`, `batch_no`, `expiry_date`, `notes`) VALUES
(1, 'Paracetamol 500mg', 'Pain Relief', 45, 10, 'tablets', 'MedPharm Supplies', 'B001', DATE_ADD(CURDATE(), INTERVAL 180 DAY), 'For pain and fever relief'),
(2, 'Amoxicillin 250mg', 'Antibiotics', 15, 5, 'capsules', 'HealthCare Distributors', 'A002', DATE_ADD(CURDATE(), INTERVAL 90 DAY), 'Antibiotic for infections'),
(3, 'Loperamide 2mg', 'Digestive', 25, 8, 'tablets', 'MedPharm Supplies', 'L003', DATE_ADD(CURDATE(), INTERVAL 120 DAY), 'For diarrhea'),
(4, 'Cetirizine 10mg', 'Allergy', 35, 10, 'tablets', 'HealthCare Distributors', 'C004', DATE_ADD(CURDATE(), INTERVAL 150 DAY), 'Antihistamine for allergies'),
(5, 'Oral Rehydration Solution', 'Emergency', 20, 15, 'sachets', 'MedPharm Supplies', 'O005', DATE_ADD(CURDATE(), INTERVAL 200 DAY), 'For dehydration'),
(6, 'Ibuprofen 400mg', 'Pain Relief', 30, 10, 'tablets', 'MedPharm Supplies', 'I006', DATE_ADD(CURDATE(), INTERVAL 160 DAY), 'Anti-inflammatory'),
(7, 'Cough Syrup', 'Respiratory', 12, 5, 'bottles', 'HealthCare Distributors', 'CS007', DATE_ADD(CURDATE(), INTERVAL 60 DAY), 'For cough relief');

-- --------------------------------------------------------

--
-- Dumping data for table `inventory_transactions`
--

INSERT INTO `inventory_transactions` (`id`, `inventory_item_id`, `type`, `quantity`, `reference`, `performed_by`) VALUES
(1, 1, 'in', 50, 'Initial stock', 5),
(2, 1, 'out', 5, 'Prescription #1', 5),
(3, 2, 'in', 20, 'Initial stock', 5),
(4, 2, 'out', 5, 'Prescription #1', 5),
(5, 3, 'in', 30, 'Initial stock', 5),
(6, 4, 'in', 40, 'Initial stock', 5),
(7, 5, 'in', 25, 'Initial stock', 5);

-- --------------------------------------------------------

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`announcement_id`, `title`, `content`, `category`, `target_audience`, `posted_by`, `status`, `start_date`, `end_date`) VALUES
(1, 'Children Immunization Program', 'Free vaccination for children following DOH immunization schedule. Every Wednesday & Friday from 8 AM to 12 NN at Barangay Health Center, 2nd Floor, Room 5.', 'Program', 'all', 1, 'approved', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY)),
(2, 'Prenatal Psychology Training', 'Join us for an informative session on prenatal psychology and preparing for parenthood. Learn about maternal mental health, bonding with your baby, and managing stress during pregnancy. November 24, 2025 from 2 PM to 4 PM at Barangay Payatas B. Covered Court beside Health Center.', 'Training', 'all', 1, 'approved', NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY)),
(3, 'Health Tips: Stay Hydrated', 'Remember to drink at least 8 glasses of water daily to maintain good health and prevent dehydration.', 'Health Tip', 'all', 1, 'approved', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY)),
(4, 'New Medicine Stock Arrival', 'New batch of Paracetamol and Amoxicillin has arrived. Available for dispensing.', 'General', 'pharmacists', 5, 'pending', NOW(), NULL);

-- --------------------------------------------------------

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `type`, `status`) VALUES
(1, 7, 'Your appointment has been approved for tomorrow at 2:00 PM', 'appointment', 'unread'),
(2, 8, 'Your prescription is ready for pickup', 'prescription', 'unread'),
(3, 1, 'New appointment request from patient1', 'appointment', 'unread');

-- --------------------------------------------------------

--
-- AUTO_INCREMENT for dumped tables
--

ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

ALTER TABLE `doctors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `patients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `schedules`
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

ALTER TABLE `appointments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

ALTER TABLE `prescriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `medications`
  MODIFY `medication_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `prescription_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

ALTER TABLE `inventory_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

ALTER TABLE `announcements`
  MODIFY `announcement_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `dependents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `medical_records`
  MODIFY `record_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `doctor_consultations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `patient_followups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `vital_signs`
  MODIFY `vitals_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `staff`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `dispenses`
  MODIFY `dispense_id` int(11) NOT NULL AUTO_INCREMENT;

-- Re-enable foreign key checks after all tables are created
SET FOREIGN_KEY_CHECKS=1;

-- --------------------------------------------------------

--
-- Views for legacy UI compatibility
--

CREATE OR REPLACE VIEW `v_doctors` AS
    SELECT `id` AS `user_id`, COALESCE(`first_name`, `username`) AS `name`, `email`
    FROM `users` WHERE `role`='doctor';

CREATE OR REPLACE VIEW `v_patients` AS
    SELECT `u`.`id` AS `user_id`, COALESCE(`u`.`first_name`, `u`.`username`) AS `name`, `u`.`email`,
           `pp`.`date_of_birth`, `pp`.`gender`, `pp`.`allergies`
    FROM `users` `u`
    LEFT JOIN `patient_profiles` `pp` ON `pp`.`patient_id` = `u`.`id`
    WHERE `u`.`role`='patient';

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
