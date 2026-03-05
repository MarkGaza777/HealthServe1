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
	$item_name = $_POST['item_name'] ?? null;
	$quantity = $_POST['quantity'] ?? null;
	$category = $_POST['category'] ?? null;
	$unit = $_POST['unit'] ?? null;
	$reorder_level = $_POST['reorder_level'] ?? 10;
	$expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
	$notes = $_POST['notes'] ?? '';
	
	if (!$item_id || !$item_name || $quantity === null || !$category || !$unit) {
		echo json_encode(['success' => false, 'message' => 'Missing required fields']);
		exit();
	}
	
	$stmt = $pdo->prepare("
		UPDATE inventory 
		SET item_name = ?, 
			quantity = ?, 
			category = ?, 
			unit = ?, 
			reorder_level = ?, 
			expiry_date = ?, 
			notes = ?
		WHERE id = ?
	");
	
	$stmt->execute([
		$item_name,
		$quantity,
		$category,
		$unit,
		$reorder_level,
		$expiry_date,
		$notes,
		$item_id
	]);
	
	// Get pharmacist user IDs to notify
	$pharmacist_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'pharmacist'");
	$pharmacist_stmt->execute();
	$pharmacists = $pharmacist_stmt->fetchAll(PDO::FETCH_COLUMN);
	
	// Check for low stock
	if ($quantity <= $reorder_level && $quantity > 0) {
		$message = "Medicine Running Low — {$item_name} ({$quantity} remaining)";
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
			$message = "Expiring Medicine — {$item_name} ({$expiry_formatted})";
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
	
	echo json_encode(['success' => true, 'message' => 'Item updated successfully']);
	
} catch(PDOException $e) {
	echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>