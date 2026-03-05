<?php
session_start();
require 'db.php';
require_once 'admin_helpers_simple.php';
require_once 'clinic_time_slots.php';
require_once 'residency_verification_helper.php';
require_once 'appointment_code_helper.php';

if(empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'patient') { 
    header('Location: login.php'); 
    exit; 
}

// Payatas residency: only verified residents can book
$user_id = (int)$_SESSION['user']['id'];
if (!isPatientResidencyVerified($user_id)) {
    $_SESSION['booking_error'] = residencyRestrictedMessage();
    header('Location: user_appointments.php');
    exit;
}

// Function to check slot availability
function checkSlotAvailability($start_datetime, $pdo) {
    // Booking rules
    $SLOT_CAPACITY = getSlotCapacity();  // Use master capacity (3)
    $MIN_LEAD_SECONDS = 60 * 60;     // 1 hour lead time
    
    $appointment_date = date('Y-m-d', strtotime($start_datetime));
    $appointment_time = date('H:i:s', strtotime($start_datetime));
    
    // Past time restriction + minimum advance booking rule
    $start_ts = strtotime($start_datetime);
    if ($start_ts === false) return false;
    if ($start_ts <= time()) return false;
    if ($start_ts < (time() + $MIN_LEAD_SECONDS)) return false;
    
    // Validate against master time slot list - NO interval calculation
    $time_str = date('H:i', $start_ts);
    if (!isValidClinicTimeSlot($time_str)) {
        return false; // Time slot not in master list
    }
    
    // Check if the time slot is blocked by doctor
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as blocked_count
            FROM doctor_blocked_times
            WHERE start_date <= ?
            AND end_date >= ?
            AND (
                (start_time <= ? AND end_time > ?)
                OR (start_time < ? AND end_time >= ?)
                OR (start_time >= ? AND end_time <= ?)
            )
        ");
        $end_time = date('H:i:s', strtotime($start_datetime . ' +20 minutes')); // Default 20 min duration
        $stmt->execute([
            $appointment_date,
            $appointment_date,
            $appointment_time,
            $appointment_time,
            $end_time,
            $end_time,
            $appointment_time,
            $end_time
        ]);
        $blocked_result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($blocked_result && (int)$blocked_result['blocked_count'] > 0) {
            return false; // Blocked by doctor
        }
    } catch (Exception $e) {
        // Table might not exist, continue
        error_log("Error checking doctor blocked times: " . $e->getMessage());
    }
    
    // Check if the time slot is blocked by FDO (schedules table)
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as blocked_count
            FROM schedules
            WHERE date = ?
            AND availability = 'blocked'
            AND (
                (time_start <= ? AND time_end > ?)
                OR (time_start < ? AND time_end >= ?)
                OR (time_start >= ? AND time_end <= ?)
            )
        ");
        $end_time = date('H:i:s', strtotime($start_datetime . ' +20 minutes')); // Default 20 min duration
        $stmt->execute([
            $appointment_date,
            $appointment_time,
            $appointment_time,
            $end_time,
            $end_time,
            $appointment_time,
            $end_time
        ]);
        $blocked_result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($blocked_result && (int)$blocked_result['blocked_count'] > 0) {
            return false; // Blocked by FDO
        }
    } catch (Exception $e) {
        // Table might not exist or column might not exist, continue
        error_log("Error checking FDO blocked times: " . $e->getMessage());
    }
    
    // Count booked appointments for this exact start time (per-slot capacity)
    // Use FOR UPDATE to reduce race conditions when called inside a transaction.
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as booked_count
        FROM appointments
        WHERE start_datetime = ?
        AND status IN ('pending', 'approved', 'completed')
        FOR UPDATE
    ");
    $stmt->execute([$start_datetime]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $booked = (int)($result['booked_count'] ?? 0);
    
    return $booked < $SLOT_CAPACITY;
}

$err = ''; 
$msg = '';

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_appt'])) {
    // Validate and sanitize input
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $start = $_POST['start_datetime'] ?? null;
    $dur = intval($_POST['duration_minutes'] ?? 20);
    $notes = trim($_POST['notes'] ?? '');
    $booking_for = $_POST['booking_for'] ?? 'self';
    $dependent_id = !empty($_POST['dependent_id']) ? intval($_POST['dependent_id']) : null;
    
    // Additional validation
    if (empty($first_name) || empty($last_name)) {
        $err = 'First name and last name are required';
    } elseif (!$start || strtotime($start) === false) {
        $err = 'Invalid date and time format';
    } elseif (strtotime($start) < time()) {
        $err = 'Appointment time must be in the future';
    } elseif (strtotime($start) < (time() + 60 * 60)) {
        $err = 'Appointment must be booked at least 1 hour in advance';
    } elseif (!checkSlotAvailability($start, $pdo)) {
        $err = 'This time slot is no longer available. Please select another date or time period.';
    } elseif ($dur <= 0 || $dur > 120) {
        $err = 'Invalid duration';
    } elseif (!empty($phone) && !preg_match('/^[0-9+\-() ]{10,15}$/', $phone)) {
        $err = 'Invalid phone number format';
    } elseif ($booking_for === 'dependent' && empty($dependent_id)) {
        $err = 'Please select a dependent';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Re-check slot availability under transaction (real-time validation)
            if (!checkSlotAvailability($start, $pdo)) {
                throw new Exception('This time slot is no longer available. Please refresh and select another available slot.');
            }
            
            $user_id = $_SESSION['user']['id'];
            $patient_id = null; // Will be set based on booking type
            $patient_name = trim($first_name . ' ' . ($middle_name ? $middle_name . ' ' : '') . $last_name);
            
            // Get parent's name (will be used for dependent appointments)
            $parent_name = '';
            $stmt = $pdo->prepare('SELECT first_name, middle_name, last_name FROM users WHERE id = ?');
            $stmt->execute([$user_id]);
            $parent = $stmt->fetch();
            if ($parent) {
                $parent_name = trim(($parent['first_name'] ?? '') . ' ' . ($parent['middle_name'] ?? '') . ' ' . ($parent['last_name'] ?? ''));
            }
            
            // Handle dependent booking
            if ($booking_for === 'dependent' && $dependent_id) {
                // Verify dependent belongs to this user
                $stmt = $pdo->prepare('SELECT * FROM dependents WHERE id = ? AND patient_id = ?');
                $stmt->execute([$dependent_id, $user_id]);
                $dependent = $stmt->fetch();
                
                if (!$dependent) {
                    throw new Exception('Invalid dependent selected');
                }
                
                // Use dependent's name from the form (which was auto-filled)
                $patient_name = trim($first_name . ' ' . ($middle_name ? $middle_name . ' ' : '') . $last_name);
                
                // Create a patient record for the dependent appointment (or use existing)
                // This is stored in the patients table for reference, but patient_id in appointments will be NULL
                $stmt = $pdo->prepare('SELECT id FROM patients WHERE created_by_user_id = ? AND first_name = ? AND last_name = ? LIMIT 1');
                $stmt->execute([$user_id, $first_name, $last_name]);
                $existing_patient = $stmt->fetch();
                
                if (!$existing_patient) {
                    // Create patient record for dependent (for tracking purposes)
                    $stmt = $pdo->prepare('INSERT INTO patients (first_name, middle_name, last_name, phone, created_by_user_id, created_at) VALUES (?,?,?,?,?,NOW())');
                    $stmt->execute([$first_name, $middle_name ?: null, $last_name, $phone, $user_id]);
                }
                
                // For dependents, patient_id in appointments should be NULL
                // because the foreign key constraint requires it to reference users.id
                // Dependents don't have user accounts, so we set it to NULL
                $patient_id = null;
            } else {
                // Booking for self - use the user's own ID as patient_id
                // Since the user is registered in the users table, we use their user_id
                $patient_id = $user_id;
                
                // Also update/create patient record in patients table for consistency
                $stmt = $pdo->prepare('SELECT id FROM patients WHERE created_by_user_id = ? LIMIT 1');
                $stmt->execute([$user_id]);
                $patient = $stmt->fetch();
                
                if ($patient) {
                    // Update patient info if provided
                    if ($first_name || $last_name || $phone) {
                        $updateFields = [];
                        $updateParams = [];
                        if ($first_name) {
                            $updateFields[] = 'first_name = ?';
                            $updateParams[] = $first_name;
                        }
                        if ($middle_name !== '') {
                            $updateFields[] = 'middle_name = ?';
                            $updateParams[] = $middle_name ?: null;
                        }
                        if ($last_name) {
                            $updateFields[] = 'last_name = ?';
                            $updateParams[] = $last_name;
                        }
                        if ($phone) {
                            $updateFields[] = 'phone = ?';
                            $updateParams[] = $phone;
                        }
                        if (!empty($updateFields)) {
                            $updateParams[] = $patient['id'];
                            $stmt = $pdo->prepare('UPDATE patients SET ' . implode(', ', $updateFields) . ' WHERE id = ?');
                            $stmt->execute($updateParams);
                        }
                    }
                } else {
                    // Create new patient record for consistency
                    $stmt = $pdo->prepare('INSERT INTO patients (first_name, middle_name, last_name, phone, created_by_user_id, created_at) VALUES (?,?,?,?,?,NOW())');
                    $stmt->execute([$first_name, $middle_name ?: null, $last_name, $phone, $user_id]);
                }
            }
            
            // Get reason from form
            $reason = trim($_POST['reason'] ?? '');
            $other_reason = trim($_POST['other_reason'] ?? '');
            if ($reason === 'others' && !empty($other_reason)) {
                $reason = $other_reason;
            }
            
            // Get doctor_id if provided
            $doctor_id = null;
            if (!empty($_POST['doctor_id'])) {
                $doctor_id = intval($_POST['doctor_id']);
                // Verify doctor exists
                $stmt = $pdo->prepare('SELECT id FROM doctors WHERE id = ?');
                $stmt->execute([$doctor_id]);
                if (!$stmt->fetch()) {
                    $doctor_id = null; // Invalid doctor_id, set to null
                }
            }
            
            // Get FDO ID (if available, can be assigned later)
            $fdo_id = null;
            
            // Combine reason and notes (don't include patient name in notes - it's redundant)
            $combined_notes = '';
            if (!empty($reason)) {
                $combined_notes = 'Reason: ' . $reason;
            }
            if (!empty($notes)) {
                $combined_notes .= ($combined_notes ? "\n" : '') . 'Notes: ' . $notes;
            }
            // Store dependent info separately (not in notes) - we'll extract it from patient record
            
            // Create appointment - use user_id and patient_id (with unique appointment code if column exists)
            if (appointmentCodeColumnExists($pdo)) {
                $appointment_code = generateUniqueAppointmentCode($pdo);
                $stmt = $pdo->prepare('INSERT INTO appointments (user_id, patient_id, doctor_id, fdo_id, start_datetime, duration_minutes, status, notes, appointment_code) VALUES (?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$user_id, $patient_id, $doctor_id, $fdo_id, $start, $dur, 'pending', $combined_notes, $appointment_code]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO appointments (user_id, patient_id, doctor_id, fdo_id, start_datetime, duration_minutes, status, notes) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$user_id, $patient_id, $doctor_id, $fdo_id, $start, $dur, 'pending', $combined_notes]);
            }
            
            $appointment_id = $pdo->lastInsertId();
            
            // Log appointment creation
            logAuditEvent('Appointment Created', 'Appointment', $appointment_id, "Patient created appointment for " . date('Y-m-d H:i', strtotime($start)) . " with doctor ID: {$doctor_id}");
            
            // Create FDO notifications: one per Front Desk Officer (real-time notification for new appointment request)
            $appointment_date = date('Y-m-d', strtotime($start));
            $appointment_time = date('H:i:s', strtotime($start));
            $complaint = $reason ?: (trim($combined_notes) ? substr(trim($combined_notes), 0, 500) : null);
            $fdo_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'fdo'");
            $fdo_stmt->execute();
            $fdo_users = $fdo_stmt->fetchAll(PDO::FETCH_ASSOC);
            $ins = $pdo->prepare("
                INSERT INTO fdo_notifications (user_id, appointment_id, patient_name, appointment_date, appointment_time, complaint, appointment_status, is_read)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', 0)
            ");
            foreach ($fdo_users as $fdo) {
                try {
                    $ins->execute([$fdo['id'], $appointment_id, $patient_name, $appointment_date, $appointment_time, $complaint]);
                } catch (PDOException $e) {
                    // Table may not exist yet; log and continue so booking still succeeds
                    error_log("FDO notification insert failed (run add_fdo_notifications migration): " . $e->getMessage());
                }
            }
            
            $pdo->commit();
            $_SESSION['booking_success'] = 'Appointment request received. You will be notified once it is approved.';
            header('Location: user_appointments.php');
            exit;
            
        } catch(Exception $e) {
            $pdo->rollback();
            error_log("Appointment booking error: " . $e->getMessage());
            $err = 'Failed to book appointment: ' . $e->getMessage();
        }
    }
}

// Store error message in session if there is one
if($err) {
    $_SESSION['booking_error'] = $err;
    header('Location: user_appointments.php');
    exit;
}

// Only redirect if we haven't already (in the success case)
if(!isset($_SESSION['booking_success'])) {
    header('Location: user_appointments.php');
    exit;
}
?>
