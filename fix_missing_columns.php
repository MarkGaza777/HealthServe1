<?php
/**
 * Quick fix script to add missing columns to follow_up_appointments table
 * Run this once to fix the database schema
 */

require 'db.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Missing Columns</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2E7D32;
            margin-top: 0;
        }
        .success {
            background: #E8F5E9;
            border-left: 4px solid #4CAF50;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .error {
            background: #FFEBEE;
            border-left: 4px solid #F44336;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .info {
            background: #E3F2FD;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .btn {
            background: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-top: 20px;
        }
        .btn:hover {
            background: #45a049;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Fix Missing Database Columns</h1>
        
        <?php
        $errors = [];
        $success = [];
        
        try {
            // Check if table exists
            $table_check = $pdo->query("SHOW TABLES LIKE 'follow_up_appointments'");
            if ($table_check->rowCount() == 0) {
                echo '<div class="error">Error: follow_up_appointments table does not exist. Please run the main migration first.</div>';
                exit;
            }
            
            // Check and add reschedule_requested_at column
            $column_check = $pdo->query("SHOW COLUMNS FROM follow_up_appointments LIKE 'reschedule_requested_at'");
            if ($column_check->rowCount() == 0) {
                try {
                    $pdo->exec("ALTER TABLE follow_up_appointments ADD COLUMN reschedule_requested_at DATETIME DEFAULT NULL COMMENT 'When patient requested reschedule' AFTER patient_selected_alternative");
                    $success[] = "Added column: reschedule_requested_at";
                } catch (Exception $e) {
                    $errors[] = "Failed to add reschedule_requested_at: " . $e->getMessage();
                }
            } else {
                $success[] = "Column reschedule_requested_at already exists";
            }
            
            // Check and add follow_up_reason column
            $column_check = $pdo->query("SHOW COLUMNS FROM follow_up_appointments LIKE 'follow_up_reason'");
            if ($column_check->rowCount() == 0) {
                try {
                    $pdo->exec("ALTER TABLE follow_up_appointments ADD COLUMN follow_up_reason VARCHAR(255) DEFAULT NULL AFTER notes");
                    $success[] = "Added column: follow_up_reason";
                } catch (Exception $e) {
                    $errors[] = "Failed to add follow_up_reason: " . $e->getMessage();
                }
            } else {
                $success[] = "Column follow_up_reason already exists";
            }
            
            // Check status enum values
            $enum_check = $pdo->query("SHOW COLUMNS FROM follow_up_appointments WHERE Field = 'status'");
            $enum_row = $enum_check->fetch(PDO::FETCH_ASSOC);
            $enum_values = $enum_row['Type'] ?? '';
            
            $required_statuses = ['doctor_set', 'pending_patient_confirmation', 'pending_doctor_approval', 'approved', 'declined', 'cancelled', 'reschedule_requested'];
            $missing_statuses = [];
            
            foreach ($required_statuses as $status) {
                if (strpos($enum_values, $status) === false) {
                    $missing_statuses[] = $status;
                }
            }
            
            if (!empty($missing_statuses)) {
                try {
                    $enum_list = "'" . implode("','", $required_statuses) . "'";
                    $pdo->exec("ALTER TABLE follow_up_appointments MODIFY COLUMN status ENUM($enum_list) DEFAULT 'doctor_set'");
                    $success[] = "Updated status enum to include: " . implode(", ", $missing_statuses);
                } catch (Exception $e) {
                    $errors[] = "Failed to update status enum: " . $e->getMessage();
                }
            } else {
                $success[] = "Status enum values are correct";
            }
            
            // Display results
            if (!empty($success)) {
                echo '<div class="success"><strong>Success:</strong><ul>';
                foreach ($success as $msg) {
                    echo '<li>' . htmlspecialchars($msg) . '</li>';
                }
                echo '</ul></div>';
            }
            
            if (!empty($errors)) {
                echo '<div class="error"><strong>Errors:</strong><ul>';
                foreach ($errors as $msg) {
                    echo '<li>' . htmlspecialchars($msg) . '</li>';
                }
                echo '</ul></div>';
            }
            
            if (empty($errors)) {
                echo '<div class="info"><strong>All columns are now up to date!</strong> You can now use the reschedule feature.</div>';
            }
            
        } catch (Exception $e) {
            echo '<div class="error">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        ?>
        
        <a href="user_records.php" class="btn">Go Back to Records</a>
    </div>
</body>
</html>

