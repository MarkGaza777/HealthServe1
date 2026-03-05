<?php
/**
 * Setup script to create the triage_records table
 * Run this file once to set up the triage functionality
 */

require_once 'db.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Triage Table - HealthServe</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
            margin: 0;
            padding: 40px 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 10px 35px rgba(0,0,0,0.08);
        }
        h1 {
            color: #2E7D32;
            margin-bottom: 10px;
        }
        .success {
            background: #E8F5E9;
            color: #2E7D32;
            padding: 15px 20px;
            border-radius: 8px;
            margin: 20px 0;
            border: 1px solid #66BB6A;
        }
        .error {
            background: #ffebee;
            color: #d32f2f;
            padding: 15px 20px;
            border-radius: 8px;
            margin: 20px 0;
            border: 1px solid #ffcdd2;
        }
        .info {
            background: #e3f2fd;
            color: #1976d2;
            padding: 15px 20px;
            border-radius: 8px;
            margin: 20px 0;
            border: 1px solid #bbdefb;
        }
        pre {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            border: 1px solid #e0e0e0;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            margin-top: 20px;
            font-weight: 600;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #45a049;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Triage Table Setup</h1>
        
        <?php
        try {
            // Check if table already exists
            $table_check = $pdo->query("SHOW TABLES LIKE 'triage_records'");
            $table_exists = $table_check->rowCount() > 0;
            
            if ($table_exists) {
                echo '<div class="info">';
                echo '<strong>✓ Table Already Exists</strong><br>';
                echo 'The triage_records table already exists in the database.';
                echo '</div>';
            } else {
                // Create the table
                $sql = "
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
                  `recorded_by` int(11) DEFAULT NULL COMMENT 'Staff member or doctor who recorded the triage',
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `idx_appointment` (`appointment_id`),
                  KEY `idx_patient` (`patient_id`),
                  KEY `idx_user` (`user_id`),
                  KEY `idx_recorded_by` (`recorded_by`),
                  CONSTRAINT `fk_triage_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `fk_triage_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ";
                
                $pdo->exec($sql);
                
                // Check if oxygen_saturation column exists, if not add it
                try {
                    $column_check = $pdo->query("SHOW COLUMNS FROM triage_records LIKE 'oxygen_saturation'");
                    if ($column_check->rowCount() === 0) {
                        $pdo->exec("ALTER TABLE triage_records ADD COLUMN oxygen_saturation decimal(5,2) DEFAULT NULL AFTER pulse_rate");
                        echo '<div class="info">Added oxygen_saturation column to existing table.</div>';
                    }
                } catch (PDOException $e) {
                    // Column might already exist or table was just created
                }
                
                $pdo->exec($sql);
                
                echo '<div class="success">';
                echo '<strong>✓ Success!</strong><br>';
                echo 'The triage_records table has been created successfully.';
                echo '</div>';
            }
            
            // Verify table structure
            $stmt = $pdo->query("DESCRIBE triage_records");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo '<div class="info">';
            echo '<strong>Table Structure:</strong><br>';
            echo '<pre>';
            echo "Table: triage_records\n";
            echo "Columns:\n";
            foreach ($columns as $col) {
                echo "  - {$col['Field']} ({$col['Type']})\n";
            }
            echo '</pre>';
            echo '</div>';
            
            echo '<div class="success">';
            echo '<strong>Setup Complete!</strong><br>';
            echo 'The triage functionality is now ready to use.';
            echo '</div>';
            
            echo '<a href="doctors_page.php" class="btn">Go to Doctor\'s Page</a>';
            echo '<a href="fdo_page.php" class="btn" style="margin-left: 10px;">Go to FDO Page</a>';
            
        } catch (PDOException $e) {
            echo '<div class="error">';
            echo '<strong>✗ Error:</strong><br>';
            echo htmlspecialchars($e->getMessage());
            echo '</div>';
            
            echo '<div class="info">';
            echo '<strong>Manual Setup:</strong><br>';
            echo 'If automatic setup failed, you can run the SQL manually from <code>create_triage_table.sql</code>';
            echo '</div>';
        }
        ?>
    </div>
</body>
</html>

