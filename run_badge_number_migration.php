<?php
/**
 * Run Badge Number Migration
 * 
 * This script adds the badge_number column to the inventory table.
 * Run this once to set up the badge number system.
 * 
 * Access: Run via browser or command line
 */

require_once 'db.php';

// Optional: Add authentication check if running via browser
// Uncomment the following lines if you want to restrict access
/*
session_start();
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] !== 'admin' && $_SESSION['user']['role'] !== 'pharmacist')) {
    die('Unauthorized access. Please log in as admin or pharmacist.');
}
*/

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Badge Number Migration</title>
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
        .success {
            background: #E8F5E9;
            color: #2E7D32;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #4CAF50;
            margin: 15px 0;
        }
        .error {
            background: #FFEBEE;
            color: #C62828;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #F44336;
            margin: 15px 0;
        }
        .info {
            background: #E3F2FD;
            color: #1976D2;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #2196F3;
            margin: 15px 0;
        }
        h1 {
            color: #2E7D32;
        }
        code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Badge Number Migration</h1>
        
        <?php
        try {
            // Check if column already exists
            $checkStmt = $pdo->query("
                SELECT COUNT(*) 
                FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'inventory' 
                AND COLUMN_NAME = 'badge_number'
            ");
            $columnExists = $checkStmt->fetchColumn() > 0;
            
            if ($columnExists) {
                echo '<div class="info">';
                echo '<strong>✓ Column Already Exists</strong><br>';
                echo 'The <code>badge_number</code> column already exists in the inventory table.';
                echo '</div>';
            } else {
                // Add the badge_number column
                echo '<div class="info">';
                echo '<strong>Adding badge_number column...</strong><br>';
                echo '</div>';
                
                $pdo->exec("
                    ALTER TABLE inventory
                    ADD COLUMN badge_number VARCHAR(20) UNIQUE NULL AFTER id
                ");
                
                // Create index
                $pdo->exec("
                    CREATE INDEX idx_badge_number ON inventory(badge_number)
                ");
                
                echo '<div class="success">';
                echo '<strong>✓ Migration Successful!</strong><br>';
                echo 'The <code>badge_number</code> column has been added to the inventory table.';
                echo '</div>';
            }
            
            // Show current table structure
            echo '<div class="info">';
            echo '<strong>Current Inventory Table Structure:</strong><br>';
            $descStmt = $pdo->query("DESCRIBE inventory");
            $columns = $descStmt->fetchAll(PDO::FETCH_ASSOC);
            echo '<table style="width: 100%; margin-top: 10px; border-collapse: collapse;">';
            echo '<tr style="background: #f5f5f5;"><th style="text-align: left; padding: 8px; border: 1px solid #ddd;">Field</th><th style="text-align: left; padding: 8px; border: 1px solid #ddd;">Type</th><th style="text-align: left; padding: 8px; border: 1px solid #ddd;">Null</th><th style="text-align: left; padding: 8px; border: 1px solid #ddd;">Key</th></tr>';
            foreach ($columns as $col) {
                $highlight = ($col['Field'] === 'badge_number') ? 'background: #E8F5E9;' : '';
                echo '<tr style="' . $highlight . '">';
                echo '<td style="padding: 8px; border: 1px solid #ddd;"><strong>' . htmlspecialchars($col['Field']) . '</strong></td>';
                echo '<td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($col['Type']) . '</td>';
                echo '<td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($col['Null']) . '</td>';
                echo '<td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($col['Key']) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '</div>';
            
            echo '<div class="info">';
            echo '<strong>Next Steps:</strong><br>';
            echo '1. You can now add new inventory items - badge numbers will be auto-generated.<br>';
            echo '2. (Optional) Run <code>migrations/backfill_badge_numbers.php</code> to assign badge numbers to existing items.';
            echo '</div>';
            
        } catch (PDOException $e) {
            echo '<div class="error">';
            echo '<strong>✗ Migration Failed</strong><br>';
            echo 'Error: ' . htmlspecialchars($e->getMessage());
            echo '</div>';
        }
        ?>
    </div>
</body>
</html>

