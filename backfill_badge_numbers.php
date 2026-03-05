<?php
/**
 * Backfill Badge Numbers for Existing Inventory Items
 * 
 * This script assigns badge numbers to ALL existing inventory items that don't have one.
 * Badge numbers are assigned based on the year the item was created.
 * 
 * Usage: Run this script from browser (ensure proper authentication)
 */

session_start();
require_once 'db.php';
require_once 'badge_number_helper.php';

// Check authentication
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] !== 'admin' && $_SESSION['user']['role'] !== 'pharmacist')) {
    die('Unauthorized access. Please log in as admin or pharmacist.');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backfill Badge Numbers</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 900px;
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
        .warning {
            background: #FFF3E0;
            color: #E65100;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #FF9800;
            margin: 15px 0;
        }
        h1 {
            color: #2E7D32;
        }
        .btn {
            background: #2E7D32;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
            margin-top: 10px;
        }
        .btn:hover {
            background: #388E3C;
        }
        .btn-secondary {
            background: #757575;
        }
        .btn-secondary:hover {
            background: #616161;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th {
            background: #f5f5f5;
            font-weight: 600;
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
        <h1>Backfill Badge Numbers for Existing Items</h1>
        
        <?php
        // Check if badge_number column exists
        try {
            $checkStmt = $pdo->query("
                SELECT COUNT(*) 
                FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'inventory' 
                AND COLUMN_NAME = 'badge_number'
            ");
            $columnExists = $checkStmt->fetchColumn() > 0;
            
            if (!$columnExists) {
                echo '<div class="error">';
                echo '<strong>✗ Column Not Found</strong><br>';
                echo 'The <code>badge_number</code> column does not exist. Please run the migration first: ';
                echo '<a href="run_badge_number_migration.php" class="btn">Run Migration</a>';
                echo '</div>';
            } else {
                // Check if there are items without badge numbers
                $countStmt = $pdo->query("SELECT COUNT(*) FROM inventory WHERE badge_number IS NULL");
                $itemsNeedingBadge = $countStmt->fetchColumn();
                
                if ($itemsNeedingBadge == 0) {
                    echo '<div class="success">';
                    echo '<strong>✓ All Items Have Badge Numbers</strong><br>';
                    echo 'All inventory items already have badge numbers assigned.';
                    echo '</div>';
                    
                    // Show summary
                    $summaryStmt = $pdo->query("
                        SELECT 
                            COUNT(*) as total,
                            COUNT(DISTINCT YEAR(created_at)) as years
                        FROM inventory 
                        WHERE badge_number IS NOT NULL
                    ");
                    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
                    echo '<div class="info">';
                    echo '<strong>Summary:</strong><br>';
                    echo "Total items with badge numbers: <strong>{$summary['total']}</strong><br>";
                    echo "Years covered: <strong>{$summary['years']}</strong>";
                    echo '</div>';
                } else {
                    // Process the backfill
                    if (isset($_GET['run']) && $_GET['run'] === 'true') {
                        echo '<div class="info">';
                        echo '<strong>Processing...</strong><br>';
                        echo "Found {$itemsNeedingBadge} items without badge numbers.";
                        echo '</div>';
                        
                        // Get all items without badge numbers, grouped by year
                        $stmt = $pdo->query("
                            SELECT id, created_at, item_name
                            FROM inventory 
                            WHERE badge_number IS NULL 
                            ORDER BY created_at ASC
                        ");
                        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        $updated = 0;
                        $errors = [];
                        $yearSequences = []; // Track sequence per year
                        
                        foreach ($items as $item) {
                            try {
                                $createdYear = date('Y', strtotime($item['created_at']));
                                
                                // Initialize sequence for this year if not set
                                if (!isset($yearSequences[$createdYear])) {
                                    // Get the highest sequence number for this year (including existing badge numbers)
                                    $prefix = "MED-{$createdYear}-";
                                    $seqStmt = $pdo->prepare("
                                        SELECT badge_number 
                                        FROM inventory 
                                        WHERE badge_number LIKE ? 
                                        ORDER BY badge_number DESC 
                                        LIMIT 1
                                    ");
                                    $seqStmt->execute([$prefix . '%']);
                                    $lastBadge = $seqStmt->fetchColumn();
                                    
                                    if ($lastBadge) {
                                        $parts = explode('-', $lastBadge);
                                        if (count($parts) === 3 && $parts[0] === 'MED' && $parts[1] === $createdYear) {
                                            $yearSequences[$createdYear] = (int)$parts[2];
                                        } else {
                                            $yearSequences[$createdYear] = 0;
                                        }
                                    } else {
                                        $yearSequences[$createdYear] = 0;
                                    }
                                }
                                
                                // Increment sequence for this year
                                $yearSequences[$createdYear]++;
                                $formattedSequence = str_pad($yearSequences[$createdYear], 4, '0', STR_PAD_LEFT);
                                $badge_number = "MED-{$createdYear}-{$formattedSequence}";
                                
                                // Update the item with the badge number
                                $updateStmt = $pdo->prepare("UPDATE inventory SET badge_number = ? WHERE id = ?");
                                $updateStmt->execute([$badge_number, $item['id']]);
                                
                                $updated++;
                                
                            } catch (PDOException $e) {
                                $errors[] = "Item ID {$item['id']} ({$item['item_name']}): " . $e->getMessage();
                            }
                        }
                        
                        echo '<div class="success">';
                        echo '<strong>✓ Backfill Completed!</strong><br>';
                        echo "Successfully assigned badge numbers to <strong>{$updated}</strong> items.";
                        echo '</div>';
                        
                        if (!empty($errors)) {
                            echo '<div class="error">';
                            echo '<strong>Errors encountered:</strong><br>';
                            echo '<ul>';
                            foreach ($errors as $error) {
                                echo '<li>' . htmlspecialchars($error) . '</li>';
                            }
                            echo '</ul>';
                            echo '</div>';
                        }
                        
                        // Show sample of updated items
                        $sampleStmt = $pdo->query("
                            SELECT badge_number, item_name, created_at
                            FROM inventory 
                            WHERE badge_number IS NOT NULL
                            ORDER BY badge_number DESC
                            LIMIT 10
                        ");
                        $samples = $sampleStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (!empty($samples)) {
                            echo '<div class="info">';
                            echo '<strong>Sample of Updated Items:</strong>';
                            echo '<table>';
                            echo '<tr><th>Badge Number</th><th>Item Name</th><th>Created</th></tr>';
                            foreach ($samples as $sample) {
                                echo '<tr>';
                                echo '<td><code>' . htmlspecialchars($sample['badge_number']) . '</code></td>';
                                echo '<td>' . htmlspecialchars($sample['item_name']) . '</td>';
                                echo '<td>' . date('M j, Y', strtotime($sample['created_at'])) . '</td>';
                                echo '</tr>';
                            }
                            echo '</table>';
                            echo '</div>';
                        }
                        
                        echo '<div style="margin-top: 20px;">';
                        echo '<a href="pharmacist_inventory.php" class="btn">View Inventory</a>';
                        echo '</div>';
                        
                    } else {
                        // Show confirmation
                        echo '<div class="warning">';
                        echo '<strong>⚠ Ready to Backfill</strong><br>';
                        echo "Found <strong>{$itemsNeedingBadge}</strong> items without badge numbers.<br>";
                        echo "This will assign badge numbers to all existing inventory items based on their creation year.";
                        echo '</div>';
                        
                        echo '<div class="info">';
                        echo '<strong>What will happen:</strong><br>';
                        echo '• Each item will receive a unique badge number in the format: <code>MED-YYYY-XXXX</code><br>';
                        echo '• The year (YYYY) will be based on when the item was created<br>';
                        echo '• The sequence number (XXXX) will be auto-incremented per year<br>';
                        echo '• This process cannot be undone, but badge numbers can be regenerated if needed';
                        echo '</div>';
                        
                        echo '<div style="margin-top: 20px;">';
                        echo '<a href="?run=true" class="btn">Start Backfill Process</a>';
                        echo '<a href="pharmacist_inventory.php" class="btn btn-secondary" style="margin-left: 10px;">Cancel</a>';
                        echo '</div>';
                    }
                }
            }
            
        } catch (PDOException $e) {
            echo '<div class="error">';
            echo '<strong>✗ Error</strong><br>';
            echo 'Database error: ' . htmlspecialchars($e->getMessage());
            echo '</div>';
        }
        ?>
    </div>
</body>
</html>

