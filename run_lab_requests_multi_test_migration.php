<?php
/**
 * Migration: Lab requests with multiple tests per request.
 * Creates lab_requests, lab_request_tests; adds lab_request_id to lab_test_results; migrates data.
 * Run once after deploy.
 */

require_once __DIR__ . '/db.php';

try {
    echo "Checking/Creating lab_requests table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `lab_requests` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `patient_id` int(11) NOT NULL,
          `doctor_id` int(11) NOT NULL,
          `appointment_id` int(11) DEFAULT NULL,
          `consultation_id` int(11) DEFAULT NULL,
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
    echo "✓ lab_requests table ready\n";

    echo "Checking/Creating lab_request_tests table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `lab_request_tests` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `lab_request_id` int(11) NOT NULL,
          `test_name` varchar(255) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `idx_lab_request` (`lab_request_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✓ lab_request_tests table ready\n";

    // Add lab_request_id to lab_test_results if missing; allow lab_test_request_id to be NULL for new flow
    $cols = $pdo->query("SHOW COLUMNS FROM lab_test_results LIKE 'lab_request_id'");
    if ($cols->rowCount() === 0) {
        echo "Adding lab_request_id to lab_test_results...\n";
        $pdo->exec("ALTER TABLE lab_test_results ADD COLUMN lab_request_id int(11) DEFAULT NULL AFTER lab_test_request_id, ADD KEY idx_lab_request_id (lab_request_id)");
        echo "✓ lab_request_id column added\n";
    } else {
        echo "✓ lab_request_id already exists\n";
    }
    try {
        $pdo->exec("ALTER TABLE lab_test_results MODIFY lab_test_request_id int(11) DEFAULT NULL");
        echo "✓ lab_test_request_id now nullable for new upload flow\n";
    } catch (PDOException $e) {
        echo "  (lab_test_request_id nullable skip: " . $e->getMessage() . ")\n";
    }

    // Migrate data from lab_test_requests if that table exists and has rows
    $hasOld = $pdo->query("SHOW TABLES LIKE 'lab_test_requests'");
    if ($hasOld->rowCount() > 0) {
        $count = $pdo->query("SELECT COUNT(*) FROM lab_test_requests")->fetchColumn();
        if ($count > 0) {
            echo "Migrating existing lab_test_requests to lab_requests + lab_request_tests...\n";
            $stmt = $pdo->query("SELECT id, patient_id, doctor_id, appointment_id, consultation_id, laboratory_name, laboratory_type, notes, status, requested_date, created_at, updated_at, test_name FROM lab_test_requests ORDER BY id");
            $insReq = $pdo->prepare("
                INSERT INTO lab_requests (patient_id, doctor_id, appointment_id, consultation_id, laboratory_name, laboratory_type, notes, status, requested_date, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insTest = $pdo->prepare("INSERT INTO lab_request_tests (lab_request_id, test_name) VALUES (?, ?)");
            $updRes = $pdo->prepare("UPDATE lab_test_results SET lab_request_id = ? WHERE lab_test_request_id = ?");
            $oldToNew = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $oldId = (int) $row['id'];
                $insReq->execute([
                    $row['patient_id'], $row['doctor_id'], $row['appointment_id'], $row['consultation_id'],
                    $row['laboratory_name'], $row['laboratory_type'] ?? 'select', $row['notes'], $row['status'] ?? 'pending',
                    $row['requested_date'], $row['created_at'], $row['updated_at']
                ]);
                $newId = (int) $pdo->lastInsertId();
                $insTest->execute([$newId, $row['test_name']]);
                $oldToNew[$oldId] = $newId;
            }
            foreach ($oldToNew as $oldId => $newId) {
                $updRes->execute([$newId, $oldId]);
            }
            echo "✓ Migrated " . count($oldToNew) . " lab test requests\n";
        }
    }

    echo "\n✅ Lab requests multi-test migration completed.\n";
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
