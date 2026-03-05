<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pharmacist') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    $prescription_id = $_POST['prescription_id'] ?? null;
    
    if (!$prescription_id) {
        echo json_encode(['success' => false, 'message' => 'Prescription ID required']);
        exit;
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Get prescription details
    $stmt = $pdo->prepare("SELECT * FROM prescriptions WHERE id = ?");
    $stmt->execute([$prescription_id]);
    $prescription = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$prescription) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Prescription not found']);
        exit;
    }
    
    if ($prescription['status'] === 'completed') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Prescription already dispensed']);
        exit;
    }
    
    // Check if prescription_items table has quantity and total_quantity columns
    $has_quantity = false;
    $has_total_quantity = false;
    try {
        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'quantity'");
        $has_quantity = $test_stmt->rowCount() > 0;
        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'total_quantity'");
        $has_total_quantity = $test_stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Error checking quantity columns: " . $e->getMessage());
    }
    
    // If quantity column doesn't exist, try to add it
    if (!$has_quantity) {
        try {
            $pdo->exec("ALTER TABLE prescription_items ADD COLUMN quantity INT(11) DEFAULT 1 AFTER duration");
            $has_quantity = true;
            error_log("Added quantity column to prescription_items table");
        } catch (PDOException $e) {
            error_log("Could not add quantity column: " . $e->getMessage());
        }
    }
    
    // If total_quantity column doesn't exist, try to add it
    if (!$has_total_quantity) {
        try {
            $pdo->exec("ALTER TABLE prescription_items ADD COLUMN total_quantity INT(11) DEFAULT 0 AFTER quantity");
            $has_total_quantity = true;
            error_log("Added total_quantity column to prescription_items table");
        } catch (PDOException $e) {
            error_log("Could not add total_quantity column: " . $e->getMessage());
        }
    }
    
    // Check if prescription_items has is_external column
    $has_is_external = false;
    try {
        $test_stmt = $pdo->query("SHOW COLUMNS FROM prescription_items LIKE 'is_external'");
        $has_is_external = $test_stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Error checking is_external column: " . $e->getMessage());
    }

    // Get medications from prescription_items table first (has quantity, total_quantity, is_external if available)
    if ($has_quantity && $has_total_quantity && $has_is_external) {
        $stmt = $pdo->prepare("SELECT id, prescription_id, medicine_name, category, dosage, frequency, duration, quantity, total_quantity, is_external, created_at FROM prescription_items WHERE prescription_id = ?");
    } elseif ($has_quantity && $has_total_quantity) {
        $stmt = $pdo->prepare("SELECT id, prescription_id, medicine_name, category, dosage, frequency, duration, quantity, total_quantity, created_at FROM prescription_items WHERE prescription_id = ?");
    } elseif ($has_quantity) {
        $stmt = $pdo->prepare("SELECT id, prescription_id, medicine_name, category, dosage, frequency, duration, quantity, created_at FROM prescription_items WHERE prescription_id = ?");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM prescription_items WHERE prescription_id = ?");
    }
    $stmt->execute([$prescription_id]);
    $medications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no medications in prescription_items, check medications table
    if (empty($medications)) {
        $stmt = $pdo->prepare("SELECT * FROM medications WHERE prescription_id = ?");
        $stmt->execute([$prescription_id]);
        $medications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add default quantity of 1 for medications from medications table
        foreach ($medications as &$med) {
            if (!isset($med['quantity'])) {
                $med['quantity'] = 1;
            }
            if (!isset($med['total_quantity'])) {
                $med['total_quantity'] = 0;
            }
        }
        unset($med);
    } else {
        // Ensure quantity, total_quantity, and is_external are set for prescription_items
        foreach ($medications as &$med) {
            // Check if quantity exists and is valid
            if (!isset($med['quantity']) || $med['quantity'] === null || $med['quantity'] === '' || (int)$med['quantity'] <= 0) {
                $med['quantity'] = 1;
            } else {
                // Ensure it's an integer
                $med['quantity'] = (int)$med['quantity'];
            }
            
            // Check if total_quantity exists and is valid
            if (!isset($med['total_quantity']) || $med['total_quantity'] === null || $med['total_quantity'] === '') {
                $med['total_quantity'] = 0;
            } else {
                // Ensure it's an integer
                $med['total_quantity'] = (int)$med['total_quantity'];
            }
            // Normalize is_external (may be missing if column was added later)
            if (!isset($med['is_external'])) {
                $med['is_external'] = 0;
            }
        }
        unset($med);
    }
    
    // Reject dispensing if prescription has only external medicines (nothing to dispense from health center)
    $dispensable_count = 0;
    foreach ($medications as $med) {
        if (empty($med['is_external']) || (int)$med['is_external'] === 0) {
            $dispensable_count++;
        }
    }
    if ($dispensable_count === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'This prescription contains only external medicines. Nothing to dispense from health center inventory.']);
        exit;
    }
    
    // Get pharmacist user ID for notifications
    $pharmacist_user_id = $_SESSION['user']['id'];
    
    // Update inventory for each medication (skip external medicines — to be bought outside health center)
    foreach ($medications as $med) {
        // External medicines do not affect inventory or dispensing
        if (!empty($med['is_external'])) {
            continue;
        }
        
        $medicine_name = $med['drug_name'] ?? $med['medicine_name'];
        
        // Get total_quantity (auto-calculated) - prefer this over quantity
        // If total_quantity is not available or is 0, fall back to quantity
        $prescribed_quantity = 0;
        if (isset($med['total_quantity']) && $med['total_quantity'] > 0) {
            if (is_numeric($med['total_quantity'])) {
                $prescribed_quantity = (int)$med['total_quantity'];
            } elseif (is_string($med['total_quantity']) && is_numeric(trim($med['total_quantity']))) {
                $prescribed_quantity = (int)trim($med['total_quantity']);
            }
        }
        
        // Fall back to quantity if total_quantity is not available or is 0
        if ($prescribed_quantity <= 0 && isset($med['quantity'])) {
            if (is_numeric($med['quantity'])) {
                $prescribed_quantity = (int)$med['quantity'];
            } elseif (is_string($med['quantity']) && is_numeric(trim($med['quantity']))) {
                $prescribed_quantity = (int)trim($med['quantity']);
            }
        }
        
        // Ensure quantity is at least 1 (final fallback)
        if ($prescribed_quantity <= 0) {
            $prescribed_quantity = 1;
        }
        
        // Log for debugging (remove in production if needed)
        error_log("Dispensing prescription #{$prescription_id}: Medicine '{$medicine_name}', Total Quantity: {$prescribed_quantity}");
        
        if (empty($medicine_name)) {
            continue;
        }
        
        // Try to find matching inventory item by name (case-insensitive)
        $stmt = $pdo->prepare("
            SELECT id, quantity, item_name, reorder_level
            FROM inventory 
            WHERE LOWER(TRIM(item_name)) = LOWER(TRIM(?))
            LIMIT 1
        ");
        $stmt->execute([$medicine_name]);
        $inventory_item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($inventory_item) {
            // Subtract prescribed quantity from inventory
            $new_quantity = max(0, $inventory_item['quantity'] - $prescribed_quantity);
            
            $stmt = $pdo->prepare("
                UPDATE inventory 
                SET quantity = ?, 
                    last_dispensed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$new_quantity, $inventory_item['id']]);
            
            // Create notification for pharmacist about inventory update
            $message = "Prescription dispensed — {$prescribed_quantity} {$inventory_item['item_name']} deducted. Remaining: {$new_quantity}";
            $notif_stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, message, type, status) 
                VALUES (?, ?, 'inventory_update', 'unread')
            ");
            $notif_stmt->execute([$pharmacist_user_id, $message]);
            
            // Check if low stock or out of stock after dispensing
            if ($new_quantity == 0) {
                // Out of stock notification
                $message = "Out of Stock — {$inventory_item['item_name']} is now out of stock";
                $notif_stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, message, type, status) 
                    VALUES (?, ?, 'inventory_out', 'unread')
                ");
                $notif_stmt->execute([$pharmacist_user_id, $message]);
            } elseif ($new_quantity <= $inventory_item['reorder_level']) {
                // Low stock notification
                $message = "Medicine Running Low — {$inventory_item['item_name']} ({$new_quantity} remaining, reorder at {$inventory_item['reorder_level']})";
                $notif_stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, message, type, status) 
                    VALUES (?, ?, 'inventory_low', 'unread')
                ");
                $notif_stmt->execute([$pharmacist_user_id, $message]);
            }
        } else {
            // Medicine not found in inventory - create notification
            $message = "Warning: Medicine '{$medicine_name}' not found in inventory for prescription #{$prescription_id}";
            $notif_stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, message, type, status) 
                VALUES (?, ?, 'inventory_warning', 'unread')
            ");
            $notif_stmt->execute([$pharmacist_user_id, $message]);
            error_log("Warning: Medicine '{$medicine_name}' not found in inventory for prescription {$prescription_id}");
        }
    }
    
    // Update prescription status to completed
    $stmt = $pdo->prepare("
        UPDATE prescriptions 
        SET status = 'completed', 
            updated_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$prescription_id]);
    
    $pdo->commit();
    
    echo json_encode(['success' => true, 'message' => 'Prescription dispensed successfully']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

