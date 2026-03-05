<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pharmacist') {
	echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
	exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	echo json_encode(['success' => false, 'message' => 'Invalid request method']);
	exit();
}

try {
	$item_id = $_POST['item_id'] ?? null;
	$add_quantity = isset($_POST['add_quantity']) ? (int)$_POST['add_quantity'] : null;
	$expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
	
	if (!$item_id || $add_quantity === null || $add_quantity <= 0) {
		echo json_encode(['success' => false, 'message' => 'Invalid item ID or quantity. Quantity must be greater than 0.']);
		exit();
	}
	
	// Get current item details
	$stmt = $pdo->prepare("
		SELECT id, item_name, quantity, reorder_level, unit
		FROM inventory 
		WHERE id = ?
	");
	$stmt->execute([$item_id]);
	$item = $stmt->fetch(PDO::FETCH_ASSOC);
	
	if (!$item) {
		echo json_encode(['success' => false, 'message' => 'Item not found']);
		exit();
	}
	
	// Calculate new quantity (accumulative)
	$current_quantity = (int)$item['quantity'];
	$new_quantity = $current_quantity + $add_quantity;
	
	// Update inventory with accumulated quantity
	$update_stmt = $pdo->prepare("
		UPDATE inventory 
		SET quantity = ?,
			expiry_date = COALESCE(?, expiry_date)
		WHERE id = ?
	");
	
	$update_stmt->execute([
		$new_quantity,
		$expiry_date,
		$item_id
	]);
	
	// Get pharmacist user IDs to notify
	$pharmacist_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'pharmacist'");
	$pharmacist_stmt->execute();
	$pharmacists = $pharmacist_stmt->fetchAll(PDO::FETCH_COLUMN);
	
	// Check for low stock
	if ($new_quantity <= $item['reorder_level'] && $new_quantity > 0) {
		$message = "Medicine Running Low — {$item['item_name']} ({$new_quantity} remaining)";
		$notif_stmt = $pdo->prepare("
			INSERT INTO notifications (user_id, message, type, status) 
			VALUES (?, ?, 'inventory_low', 'unread')
		");
		foreach ($pharmacists as $pharmacist_id) {
			// Check if notification already exists to avoid duplicates
			$check_stmt = $pdo->prepare("
				SELECT notification_id FROM notifications 
				WHERE user_id = ? AND message = ? AND status = 'unread' 
				AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
				LIMIT 1
			");
			$check_stmt->execute([$pharmacist_id, $message]);
			if (!$check_stmt->fetch()) {
				$notif_stmt->execute([$pharmacist_id, $message]);
			}
		}
	}
	
	// Check for expiring soon (within 30 days)
	if ($expiry_date) {
		$expiry_check = $pdo->prepare("
			SELECT DATEDIFF(?, CURDATE()) as days_until_expiry
		");
		$expiry_check->execute([$expiry_date]);
		$expiry_result = $expiry_check->fetch(PDO::FETCH_ASSOC);
		$days_until = (int)($expiry_result['days_until_expiry'] ?? 999);
		
		if ($days_until <= 30 && $days_until >= 0) {
			$expiry_formatted = date('M j, Y', strtotime($expiry_date));
			$message = "Expiring Medicine — {$item['item_name']} ({$expiry_formatted})";
			$notif_stmt = $pdo->prepare("
				INSERT INTO notifications (user_id, message, type, status) 
				VALUES (?, ?, 'inventory_expiring', 'unread')
			");
			foreach ($pharmacists as $pharmacist_id) {
				// Check if notification already exists to avoid duplicates
				$check_stmt = $pdo->prepare("
					SELECT notification_id FROM notifications 
					WHERE user_id = ? AND message = ? AND status = 'unread' 
					AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
					LIMIT 1
				");
				$check_stmt->execute([$pharmacist_id, $message]);
				if (!$check_stmt->fetch()) {
					$notif_stmt->execute([$pharmacist_id, $message]);
				}
			}
		}
	}
	
	echo json_encode([
		'success' => true, 
		'message' => "Stock updated successfully! Added {$add_quantity} units. Previous: {$current_quantity}, New Total: {$new_quantity}",
		'new_quantity' => $new_quantity,
		'previous_quantity' => $current_quantity,
		'added_quantity' => $add_quantity
	]);
	
} catch(PDOException $e) {
	echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch(Exception $e) {
	echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

