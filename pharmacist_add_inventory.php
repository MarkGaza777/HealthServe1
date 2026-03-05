<?php
session_start();
require_once 'db.php';
require_once 'badge_number_helper.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pharmacist') {
	header("Location: Login.php");
	exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	try {
		// Check if badge_number column exists, if not, create it
		$checkStmt = $pdo->query("
			SELECT COUNT(*) 
			FROM information_schema.COLUMNS 
			WHERE TABLE_SCHEMA = DATABASE() 
			AND TABLE_NAME = 'inventory' 
			AND COLUMN_NAME = 'badge_number'
		");
		$columnExists = $checkStmt->fetchColumn() > 0;
		
		if (!$columnExists) {
			// Automatically add the column if it doesn't exist
			try {
				$pdo->exec("
					ALTER TABLE inventory
					ADD COLUMN badge_number VARCHAR(20) UNIQUE NULL AFTER id
				");
				$pdo->exec("
					CREATE INDEX idx_badge_number ON inventory(badge_number)
				");
			} catch (PDOException $e) {
				// If adding column fails, show helpful error
				$error = "Database setup required. Please run the migration script: <a href='run_badge_number_migration.php' style='color: #1976D2; text-decoration: underline;'>run_badge_number_migration.php</a> Error: " . $e->getMessage();
				throw new Exception($error);
			}
		}
		
		$item_name = trim($_POST['item_name']);
		$new_quantity = (int)$_POST['quantity'];
		$expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
		$reorder_level = !empty($_POST['reorder_level']) ? $_POST['reorder_level'] : 10;
		
		// Check if item already exists (case-insensitive match)
		// This implements accumulative stock update: new quantities are added to existing stock
		$check_stmt = $pdo->prepare("
			SELECT id, quantity, item_name, reorder_level, badge_number
			FROM inventory 
			WHERE LOWER(TRIM(item_name)) = LOWER(?)
			LIMIT 1
		");
		$check_stmt->execute([$item_name]);
		$existing_item = $check_stmt->fetch(PDO::FETCH_ASSOC);
		
		if ($existing_item) {
			// Item exists - update quantity by adding new quantity to existing (accumulative stock)
			// Prevents accidental overwriting of existing stock values
			$current_quantity = (int)$existing_item['quantity'];
			$updated_quantity = $current_quantity + $new_quantity;
			
			// Preserve existing reorder_level when restocking
			// Other metadata (category, unit) can be updated if needed
			$preserved_reorder_level = $existing_item['reorder_level'];
			
			// Update existing item with accumulated quantity
			$update_stmt = $pdo->prepare("
				UPDATE inventory 
				SET quantity = ?,
					category = ?,
					unit = ?,
					reorder_level = ?,
					expiry_date = ?,
					notes = ?
				WHERE id = ?
			");
			
			$update_stmt->execute([
				$updated_quantity,
				$_POST['category'],
				$_POST['unit'],
				$preserved_reorder_level,
				$expiry_date,
				$_POST['notes'],
				$existing_item['id']
			]);
			
			$quantity = $updated_quantity; // For notification checks
			$effective_reorder_level = $preserved_reorder_level; // Use preserved reorder_level for notifications
			$is_new_item = false;
		} else {
			// Item doesn't exist - create new record
			$badge_number = generateBadgeNumber($pdo);
			
			$stmt = $pdo->prepare("
				INSERT INTO inventory (badge_number, item_name, category, quantity, unit, reorder_level, expiry_date, notes) 
				VALUES (?, ?, ?, ?, ?, ?, ?, ?)
			");
			
			$stmt->execute([
				$badge_number,
				$item_name,
				$_POST['category'],
				$new_quantity,
				$_POST['unit'],
				$reorder_level,
				$expiry_date,
				$_POST['notes']
			]);
			
			$quantity = $new_quantity; // For notification checks
			$effective_reorder_level = $reorder_level; // Use form reorder_level for new items
			$is_new_item = true;
		}
		
		// Get pharmacist user IDs to notify
		$pharmacist_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'pharmacist'");
		$pharmacist_stmt->execute();
		$pharmacists = $pharmacist_stmt->fetchAll(PDO::FETCH_COLUMN);
		
		// Check for low stock
		if ($quantity <= $effective_reorder_level && $quantity > 0) {
			$message = "Medicine Running Low — {$item_name} ({$quantity} remaining)";
			$notif_stmt = $pdo->prepare("
				INSERT INTO notifications (user_id, message, type, status) 
				VALUES (?, ?, 'inventory_low', 'unread')
			");
			foreach ($pharmacists as $pharmacist_id) {
				$notif_stmt->execute([$pharmacist_id, $message]);
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
					$notif_stmt->execute([$pharmacist_id, $message]);
				}
			}
		}
		
		if ($is_new_item) {
			$_SESSION['success_message'] = "Item added successfully!";
		} else {
			$_SESSION['success_message'] = "Stock updated successfully! Added {$new_quantity} units to existing item. Total stock: {$quantity}";
		}
		header("Location: pharmacist_inventory.php");
		exit();
	} catch(PDOException $e) {
		$error = "Error adding item: " . $e->getMessage();
	} catch(Exception $e) {
		$error = $e->getMessage();
	}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Add New Inventory Item - Pharmacist</title>
	<link rel="stylesheet" href="assets/Style1.css">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
	<style>
		.form-section {
			background: white;
			border-radius: 12px;
			padding: 30px;
			box-shadow: 0 2px 8px rgba(0,0,0,0.08);
			max-width: 900px;
			margin: 0 auto;
		}
		
		.form-section h3 {
			color: #2E7D32;
			margin-bottom: 10px;
			font-size: 24px;
		}
		
		.form-section .subtitle {
			color: #666;
			margin-bottom: 30px;
			font-size: 14px;
		}
		
		.form-group {
			margin-bottom: 24px;
		}
		
		.form-group label {
			display: block;
			margin-bottom: 8px;
			color: #333;
			font-weight: 600;
			font-size: 14px;
		}
		
		.form-control {
			width: 100%;
			padding: 12px 15px;
			border: 2px solid #E0E0E0;
			border-radius: 8px;
			font-size: 14px;
			transition: all 0.3s ease;
			background: #F8F9FA;
		}
		
		.form-control:focus {
			outline: none;
			border-color: #66BB6A;
			background: white;
		}
		
		.form-control:hover {
			border-color: #C8E6C9;
		}
		
		textarea.form-control {
			resize: vertical;
			min-height: 120px;
			font-family: inherit;
		}
		
		.form-row {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 20px;
		}
		
		.btn-submit {
			background: #2E7D32;
			color: white;
			border: none;
			padding: 12px 30px;
			border-radius: 8px;
			font-size: 14px;
			font-weight: 600;
			cursor: pointer;
			display: inline-flex;
			align-items: center;
			gap: 8px;
			transition: all 0.3s ease;
			margin-top: 20px;
		}
		
		.btn-submit:hover {
			background: #388E3C;
			transform: translateY(-2px);
			box-shadow: 0 4px 12px rgba(46, 125, 50, 0.3);
		}
		
		.btn-cancel {
			background: #F5F5F5;
			color: #666;
			border: none;
			padding: 12px 30px;
			border-radius: 8px;
			font-size: 14px;
			font-weight: 600;
			cursor: pointer;
			transition: all 0.3s ease;
			margin-top: 20px;
			margin-left: 10px;
		}
		
		.btn-cancel:hover {
			background: #E0E0E0;
		}
		
		.alert-error {
			background: #FFEBEE;
			color: #C62828;
			padding: 15px;
			border-radius: 8px;
			margin-bottom: 20px;
			border-left: 4px solid #C62828;
		}
		
		@media (max-width: 768px) {
			.form-row {
				grid-template-columns: 1fr;
			}
		}
	</style>
</head>
<body>
	<!-- Sidebar -->
	<div class="sidebar">
		<div class="admin-profile">
			<div class="profile-info">
				<div class="profile-avatar">
					<i class="fas fa-pills"></i>
				</div>
				<div class="profile-details">
					<h3>Pharmacist</h3>
					<div class="profile-status">Online</div>
				</div>
			</div>
		</div>

		<nav class="nav-section">
			<div class="nav-title">General</div>
			<ul class="nav-menu">
				<li class="nav-item">
					<a href="pharmacist_dashboard.php" class="nav-link">
						<div class="nav-icon"><i class="fas fa-th-large"></i></div>
						Dashboard
					</a>
				</li>
				<li class="nav-item">
					<a href="pharmacist_inventory.php" class="nav-link active">
						<div class="nav-icon"><i class="fas fa-boxes"></i></div>
						Inventory
					</a>
				</li>
				<li class="nav-item">
					<a href="pharmacist_announcements.php" class="nav-link">
						<div class="nav-icon"><i class="fas fa-bullhorn"></i></div>
						Announcements
					</a>
				</li>
			</ul>
		</nav>

		<div class="logout-section">
			<a href="logout.php" class="logout-link">
				<div class="nav-icon"><i class="fas fa-sign-out-alt"></i></div>
				Logout
			</a>
		</div>
	</div>

	<!-- Main Content -->
	<div class="main-content">
		<header class="admin-header">
			<div class="header-title">
				<img src="assets/payatas logo.png" alt="Payatas B Logo" class="barangay-seal">
				<div>
					<h1>HealthServe - Payatas B</h1>
					<p>Inventory Management</p>
				</div>
			</div>
		</header>

		<div class="content-area">
			<div class="page-header">
				<h2 class="page-title">Add New Inventory Item</h2>
				<div class="breadcrumb">Dashboard > Inventory > Add New Item</div>
			</div>

			<?php if (!empty($error)): ?>
				<div class="alert-error">
					<i class="fas fa-exclamation-circle"></i>
					<?php echo $error; ?>
				</div>
			<?php endif; ?>

			<div class="form-section">
				<h3>Add New Inventory Item</h3>
				<p class="subtitle">Fill in the details to add a new medical supply to the inventory.</p>

				<form method="POST" action="">
					<div class="form-group">
						<label for="item_name">Item Name *</label>
						<input type="text" id="item_name" name="item_name" class="form-control" 
							   placeholder="Enter item name" required>
					</div>

					<div class="form-row">
						<div class="form-group">
							<label for="category">Category *</label>
							<select id="category" name="category" class="form-control" required>
								<option value="">Select category</option>
								<option value="medicine">Medicine</option>
								<option value="vaccine">Vaccine</option>
								<option value="supply">Medical Supply</option>
								<option value="equipment">Equipment</option>
								<option value="vitamin">Vitamin/Supplement</option>
								<option value="antibiotic">Antibiotic</option>
								<option value="maintenance">Maintenance</option>
								<option value="pain_reliever">Pain Reliever</option>
								<option value="other">Other</option>
							</select>
						</div>

						<div class="form-group">
							<label for="unit">Unit *</label>
							<select id="unit" name="unit" class="form-control" required>
								<option value="">Select unit</option>
								<option value="tablet">Tablet</option>
								<option value="capsule">Capsule</option>
								<option value="bottle">Bottle</option>
								<option value="box">Box</option>
								<option value="vial">Vial</option>
								<option value="pack">Pack</option>
								<option value="piece">Piece</option>
								<option value="ml">Milliliter (ml)</option>
								<option value="mg">Milligram (mg)</option>
							</select>
						</div>
					</div>

					<div class="form-row">
						<div class="form-group">
							<label for="quantity">Quantity *</label>
							<input type="number" id="quantity" name="quantity" class="form-control" 
								   placeholder="Enter quantity" min="0" required>
						</div>

						<div class="form-group">
							<label for="reorder_level">Reorder Level *</label>
							<input type="number" id="reorder_level" name="reorder_level" class="form-control" 
								   placeholder="Enter reorder level" min="0" value="10" required>
						</div>
					</div>

					<div class="form-group">
						<label for="expiry_date">Expiration Date</label>
						<input type="date" id="expiry_date" name="expiry_date" class="form-control">
					</div>

					<div class="form-group">
						<label for="notes">Description / Notes</label>
						<textarea id="notes" name="notes" class="form-control" 
								  placeholder="Enter additional information about this item..."></textarea>
					</div>

					<div style="display: flex; justify-content: flex-end; gap: 10px;">
						<button type="button" class="btn-cancel" onclick="window.location.href='pharmacist_inventory.php'">
							Cancel
						</button>
						<button type="submit" class="btn-submit">
							<i class="fas fa-plus"></i> Add Item
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</body>
</html>