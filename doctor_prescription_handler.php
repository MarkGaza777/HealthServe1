<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Check if user is logged in and is a doctor
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$doctor_user_id = $_SESSION['user']['id'];
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'create':
            $patient_id = $_POST['patient_id'] ?? null;
            $appointment_id = $_POST['appointment_id'] ?? null;
            $diagnosis = $_POST['diagnosis'] ?? '';
            $instructions = $_POST['instructions'] ?? '';
            $notes = $_POST['notes'] ?? '';
            $medicines = json_decode($_POST['medicines'] ?? '[]', true);
            
            if (!$patient_id) {
                echo json_encode(['success' => false, 'message' => 'Patient ID required']);
                exit;
            }
            
            // Get doctor_id from doctors table (not user_id)
            $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
            $stmt->execute([$doctor_user_id]);
            $doctor_record = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$doctor_record) {
                echo json_encode(['success' => false, 'message' => 'Doctor record not found']);
                exit;
            }
            $doctor_id = $doctor_record['id'];
            
            // Check if doctor has access to this patient (has approved appointment)
            $access_check = $pdo->prepare("
                SELECT 1 FROM appointments 
                WHERE doctor_id = ? 
                AND status = 'approved'
                AND (
                    (user_id = ? AND patient_id = ?) 
                    OR (user_id = ? AND patient_id IS NULL)
                    OR patient_id = ?
                )
                LIMIT 1
            ");
            $access_check->execute([$doctor_id, $patient_id, $patient_id, $patient_id, $patient_id]);
            
            if (!$access_check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'You do not have access to this patient\'s records']);
                exit;
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            // Get validity period (default to 14 days if not provided)
            $validity_period_days = isset($_POST['prescription_validity_period']) ? (int)$_POST['prescription_validity_period'] : 14;
            // Validate validity period (only allow 7, 14, or 30 days)
            if (!in_array($validity_period_days, [7, 14, 30])) {
                $validity_period_days = 14; // Default to 14 days
            }
            
            // Calculate expiration date
            $date_issued = date('Y-m-d');
            $expiration_date = date('Y-m-d', strtotime("+{$validity_period_days} days"));
            
            // Check if validity_period_days and expiration_date columns exist
            $has_validity_fields = false;
            try {
                $test_stmt = $pdo->query("SHOW COLUMNS FROM prescriptions LIKE 'validity_period_days'");
                $has_validity_fields = $test_stmt->rowCount() > 0;
            } catch (PDOException $e) {}
            
            // Create prescription
            if ($has_validity_fields) {
                $stmt = $pdo->prepare("
                    INSERT INTO prescriptions (patient_id, doctor_id, appointment_id, diagnosis, instructions, notes, status, date_issued, validity_period_days, expiration_date)
                    VALUES (?, ?, ?, ?, ?, ?, 'draft', CURDATE(), ?, ?)
                ");
                $stmt->execute([$patient_id, $doctor_id, $appointment_id, $diagnosis, $instructions, $notes, $validity_period_days, $expiration_date]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO prescriptions (patient_id, doctor_id, appointment_id, diagnosis, instructions, notes, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'draft')
                ");
                $stmt->execute([$patient_id, $doctor_id, $appointment_id, $diagnosis, $instructions, $notes]);
            }
            $prescription_id = $pdo->lastInsertId();
            
            // Add prescription items
            if (!empty($medicines)) {
                $has_medicine_form = false;
                try {
                    $test = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'medicine_form'");
                    $has_medicine_form = $test->rowCount() > 0;
                } catch (PDOException $e) {}
                if ($has_medicine_form) {
                    $stmt = $pdo->prepare("
                        INSERT INTO prescription_items (prescription_id, medicine_name, medicine_form, category, dosage, frequency, duration)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO prescription_items (prescription_id, medicine_name, category, dosage, frequency, duration)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                }
                foreach ($medicines as $medicine) {
                    if (!empty($medicine['name'])) {
                        if ($has_medicine_form) {
                            $stmt->execute([
                                $prescription_id,
                                $medicine['name'],
                                $medicine['medicine_form'] ?? '',
                                $medicine['category'] ?? '',
                                $medicine['dosage'] ?? '',
                                $medicine['frequency'] ?? '',
                                $medicine['duration'] ?? ''
                            ]);
                        } else {
                            $stmt->execute([
                                $prescription_id,
                                $medicine['name'],
                                $medicine['category'] ?? '',
                                $medicine['dosage'] ?? '',
                                $medicine['frequency'] ?? '',
                                $medicine['duration'] ?? ''
                            ]);
                        }
                    }
                }
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Prescription created', 'prescription_id' => $prescription_id]);
            break;
            
        case 'send':
            $prescription_id = $_POST['prescription_id'] ?? null;
            if (!$prescription_id) {
                echo json_encode(['success' => false, 'message' => 'Prescription ID required']);
                exit;
            }
            
            // Get doctor_id from doctors table (not user_id)
            $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
            $stmt->execute([$doctor_user_id]);
            $doctor_record = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$doctor_record) {
                echo json_encode(['success' => false, 'message' => 'Doctor record not found']);
                exit;
            }
            $doctor_id = $doctor_record['id'];
            
            // Verify ownership
            $stmt = $pdo->prepare("SELECT doctor_id FROM prescriptions WHERE id = ?");
            $stmt->execute([$prescription_id]);
            $prescription = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$prescription || $prescription['doctor_id'] != $doctor_id) {
                echo json_encode(['success' => false, 'message' => 'Prescription not found']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE prescriptions SET status = 'sent', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$prescription_id]);
            echo json_encode(['success' => true, 'message' => 'Prescription sent to patient']);
            break;
            
        case 'get_prescriptions':
            $patient_id = $_POST['patient_id'] ?? null;
            if (!$patient_id) {
                echo json_encode(['success' => false, 'message' => 'Patient ID required']);
                exit;
            }
            
            // Get doctor_id from doctors table
            $stmt = $pdo->prepare("SELECT id FROM doctors WHERE user_id = ?");
            $stmt->execute([$doctor_user_id]);
            $doctor_record = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$doctor_record) {
                echo json_encode(['success' => false, 'message' => 'Doctor record not found']);
                exit;
            }
            $doctor_id = $doctor_record['id'];
            
            // Check if doctor has access to this patient (has approved appointment)
            $access_check = $pdo->prepare("
                SELECT 1 FROM appointments 
                WHERE doctor_id = ? 
                AND status = 'approved'
                AND (
                    (user_id = ? AND patient_id = ?) 
                    OR (user_id = ? AND patient_id IS NULL)
                    OR patient_id = ?
                )
                LIMIT 1
            ");
            $access_check->execute([$doctor_id, $patient_id, $patient_id, $patient_id, $patient_id]);
            
            if (!$access_check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'You do not have access to this patient\'s records']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT 
                    p.*,
                    u.full_name as doctor_name
                FROM prescriptions p
                LEFT JOIN users u ON p.doctor_id = u.id
                WHERE p.patient_id = ?
                ORDER BY p.created_at DESC
            ");
            $stmt->execute([$patient_id]);
            $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get items for each prescription
            foreach ($prescriptions as &$prescription) {
                $stmt = $pdo->prepare("SELECT * FROM prescription_items WHERE prescription_id = ?");
                $stmt->execute([$prescription['id']]);
                $prescription['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            echo json_encode(['success' => true, 'prescriptions' => $prescriptions]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

