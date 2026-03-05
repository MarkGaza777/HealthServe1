<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pharmacist') {
	header("Location: Login.php");
	exit();
}

// Handle Delete Request
if (isset($_GET['delete_id'])) {
	try {
		$stmt = $pdo->prepare("DELETE FROM inventory WHERE id = ?");
		$stmt->execute([$_GET['delete_id']]);
		$_SESSION['success_message'] = "Item deleted successfully!";
		header("Location: pharmacist_inventory.php");
		exit();
	} catch(PDOException $e) {
		$error = "Error deleting item: " . $e->getMessage();
	}
}

try {
	$stmt = $pdo->query("SELECT COUNT(*) as total_items FROM inventory");
	$total_items = $stmt->fetchColumn();

	$stmt = $pdo->query("SELECT COUNT(*) as low_stock FROM inventory WHERE quantity <= reorder_level");
	$low_stock = $stmt->fetchColumn();

	$stmt = $pdo->query("SELECT COUNT(*) as out_of_stock FROM inventory WHERE quantity = 0");
	$out_of_stock = $stmt->fetchColumn();

	$stmt = $pdo->query("SELECT COUNT(*) as expiring_soon FROM inventory WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date IS NOT NULL");
	$expiring_soon = $stmt->fetchColumn();

	$filter = $_GET['filter'] ?? 'all';
	$search = $_GET['search'] ?? '';

	$where_clause = "1=1";
	$params = [];

	if ($filter === 'low_stock') {
		$where_clause .= " AND quantity <= reorder_level";
	} elseif ($filter === 'out_of_stock') {
		$where_clause .= " AND quantity = 0";
	} elseif ($filter === 'expiring') {
		$where_clause .= " AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date IS NOT NULL";
	}

	if (!empty($search)) {
		$where_clause .= " AND (item_name LIKE ? OR notes LIKE ? OR category LIKE ? OR badge_number LIKE ?)";
		$search_param = "%$search%";
		$params = [$search_param, $search_param, $search_param, $search_param];
	}

	$stmt = $pdo->prepare("
        SELECT * FROM inventory 
        WHERE $where_clause 
        ORDER BY 
            CASE WHEN quantity = 0 THEN 1 
                 WHEN quantity <= reorder_level THEN 2 
                 ELSE 3 END,
            item_name ASC
    ");
	$stmt->execute($params);
	$inventory_items = $stmt->fetchAll();
	
	// Check if there are items without badge numbers
	$badgeCheckStmt = $pdo->query("SELECT COUNT(*) FROM inventory WHERE badge_number IS NULL");
	$itemsWithoutBadge = $badgeCheckStmt->fetchColumn();
} catch(PDOException $e) {
	$error = "Error fetching inventory: " . $e->getMessage();
	$itemsWithoutBadge = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Inventory - Pharmacist</title>
	<link rel="stylesheet" href="assets/Style1.css">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
	<script src="custom_modal.js"></script>
	<script src="custom_notification.js"></script>
	<style>
		.search-container { position: relative; display: flex; align-items: center; background: white; border: 2px solid #E0E0E0; border-radius: 25px; padding: 8px 15px; margin-right: 15px; min-width: 300px; }
		.search-container i { color: #999; margin-right: 10px; }
		.search-container input { border: none; outline: none; background: transparent; flex: 1; font-size: 14px; }
		.filter-tabs { padding: 15px 30px; background: #F8F9FA; border-bottom: 1px solid #E0E0E0; display: flex; gap: 10px; }
		
		.modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; display: none; overflow-y: auto; padding: 20px; box-sizing: border-box; }
		.modal-content { position: relative; background: white; padding: 30px; border-radius: 15px; width: 100%; max-width: 600px; margin: 20px auto; box-shadow: 0 12px 40px rgba(0,0,0,0.25); min-height: fit-content; }
		.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #E0E0E0; padding-bottom: 15px; position: sticky; top: 0; background: white; z-index: 10; }
		.modal-header h3 { color: #2E7D32; margin: 0; font-size: 22px; }
		.close { font-size: 24px; cursor: pointer; color: #666; background: #F3F4F6; border: none; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.3s ease; line-height: 1; padding: 0; }
		.close:hover { background: #E0E0E0; color: #333; }
		
		.form-group { margin-bottom: 20px; }
		.form-group label { display: block; margin-bottom: 8px; color: #333; font-weight: 600; font-size: 14px; }
		.form-control { width: 100%; padding: 12px 15px; border: 2px solid #E0E0E0; border-radius: 8px; font-size: 14px; transition: all 0.3s ease; background: #F8F9FA; box-sizing: border-box; }
		.form-control:focus { outline: none; border-color: #66BB6A; background: white; }
		.form-control:hover { border-color: #C8E6C9; }
		textarea.form-control { resize: vertical; min-height: 100px; font-family: inherit; }
		
		.form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 25px; padding-top: 20px; border-top: 2px solid #E0E0E0; }
		.btn-save { background: #2E7D32; color: white; border: none; padding: 12px 30px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s ease; }
		.btn-save:hover { background: #388E3C; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(46, 125, 50, 0.3); }
		.btn-secondary { background: #F5F5F5; color: #666; border: none; padding: 12px 30px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; }
		.btn-secondary:hover { background: #E0E0E0; }
		
		.success-message { background: #E8F5E9; color: #2E7D32; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #66BB6A; display: flex; align-items: center; gap: 10px; }
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
					<a href="pharmacist_prescriptions.php" class="nav-link">
						<div class="nav-icon"><i class="fas fa-prescription"></i></div>
						Prescriptions
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
				<h2 class="page-title">Inventory Management</h2>
			</div>

			<?php if (!empty($error)): ?>
				<div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
			<?php endif; ?>

			<?php if (isset($_SESSION['success_message'])): ?>
				<div class="success-message">
					<i class="fas fa-check-circle"></i>
					<?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
				</div>
			<?php endif; ?>
			
			<?php if (isset($itemsWithoutBadge) && $itemsWithoutBadge > 0): ?>
				<div style="background: #FFF3E0; color: #E65100; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #FF9800; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
					<div style="display: flex; align-items: center; gap: 10px;">
						<i class="fas fa-exclamation-triangle" style="font-size: 20px;"></i>
						<div>
							<strong><?php echo $itemsWithoutBadge; ?> item(s) without badge numbers</strong>
							<br><small>Assign badge numbers to existing inventory items for better tracking.</small>
						</div>
					</div>
					<a href="backfill_badge_numbers.php" class="btn btn-primary" style="background: #FF9800; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: 600; white-space: nowrap;">
						<i class="fas fa-tag"></i> Assign Badge Numbers
					</a>
				</div>
			<?php endif; ?>

			<!-- Quick Inventory Stats -->
			<div class="stats-grid">
				<div class="stat-card">
					<div class="stat-icon inventory">
						<i class="fas fa-clipboard-list"></i>
					</div>
					<div class="stat-details">
						<h3><?php echo $total_items; ?></h3>
						<p>Total Items</p>
					</div>
				</div>

				<div class="stat-card">
					<div class="stat-icon appointments">
						<i class="fas fa-chart-line"></i>
					</div>
					<div class="stat-details">
						<h3><?php echo $low_stock; ?></h3>
						<p>Low Stock</p>
					</div>
				</div>

				<div class="stat-card">
					<div class="stat-icon patients">
						<i class="fas fa-box-open"></i>
					</div>
					<div class="stat-details">
						<h3><?php echo $out_of_stock; ?></h3>
						<p>Out of Stock</p>
					</div>
				</div>

				<div class="stat-card">
					<div class="stat-icon staff">
						<i class="fas fa-clock"></i>
					</div>
					<div class="stat-details">
						<h3><?php echo $expiring_soon; ?></h3>
						<p>Expiring Soon</p>
					</div>
				</div>
			</div>

			<!-- Search and Filter Controls + Table -->
			<div class="table-container">
				<div class="table-header">
					<div class="table-filters">
						<div class="search-container">
							<i class="fas fa-search"></i>
							<input type="text" id="inventory-search" placeholder="Search by name or type" 
								   value="<?php echo htmlspecialchars($search); ?>"
								   onkeyup="searchInventory(event)">
						</div>
						<button class="filter-btn" onclick="toggleFilter()">
							<i class="fas fa-filter"></i> Filter
						</button>
						<a href="pharmacist_add_inventory.php" class="btn btn-primary">
							<i class="fas fa-plus"></i> Add New Item
						</a>
					</div>
				</div>

				<div class="filter-tabs">
					<button class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>" 
							onclick="filterItems('all')">All Items</button>
					<button class="filter-btn <?php echo $filter === 'low_stock' ? 'active' : ''; ?>" 
							onclick="filterItems('low_stock')">Low Stock</button>
					<button class="filter-btn <?php echo $filter === 'out_of_stock' ? 'active' : ''; ?>" 
							onclick="filterItems('out_of_stock')">Out of Stock</button>
					<button class="filter-btn <?php echo $filter === 'expiring' ? 'active' : ''; ?>" 
							onclick="filterItems('expiring')">Expiring Soon</button>
				</div>

				<table class="data-table">
					<thead>
						<tr>
							<th>Badge Number</th>
							<th>Item Name</th>
							<th>Category</th>
							<th>Current Stock</th>
							<th>Unit</th>
							<th>Status</th>
							<th>Expiration Date</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php if (empty($inventory_items)): ?>
						<tr>
							<td colspan="8" style="text-align: center; padding: 40px; color: #999;">
								<i class="fas fa-boxes" style="font-size: 48px; margin-bottom: 15px;"></i><br>
								No inventory items found.
							</td>
						</tr>
						<?php else: ?>
						<?php foreach ($inventory_items as $item): ?>
						<tr>
							<td>
								<?php if (!empty($item['badge_number'])): ?>
									<span style="display: inline-block; background: #E3F2FD; color: #1976D2; padding: 4px 10px; border-radius: 6px; font-weight: 600; font-size: 12px; font-family: 'Courier New', monospace;">
										<?php echo htmlspecialchars($item['badge_number']); ?>
									</span>
								<?php else: ?>
									<span style="color: #999; font-style: italic;">N/A</span>
								<?php endif; ?>
							</td>
							<td>
								<strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
								<?php if (!empty($item['notes'])): ?>
									<br><small style="color: #666;"><?php echo htmlspecialchars(substr($item['notes'], 0, 50)) . '...'; ?></small>
								<?php endif; ?>
							</td>
							<td><?php echo ucfirst(htmlspecialchars($item['category'] ?? '')); ?></td>
							<td>
								<strong style="color: <?php echo $item['quantity'] == 0 ? '#C62828' : ($item['quantity'] <= $item['reorder_level'] ? '#F57C00' : '#2E7D32'); ?>;">
									<?php echo $item['quantity']; ?>
								</strong>
								<br><small style="color: #666;">Reorder at: <?php echo $item['reorder_level']; ?></small>
							</td>
							<td><?php echo htmlspecialchars($item['unit']); ?></td>
							<td>
								<?php if ($item['quantity'] == 0): ?>
									<span class="status-badge status-out-of-stock">No Stock</span>
								<?php elseif ($item['quantity'] <= $item['reorder_level']): ?>
									<span class="status-badge status-low-stock">Low Stock</span>
								<?php else: ?>
									<span class="status-badge status-active">In Stock</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ($item['expiry_date']): ?>
									<?php 
									$exp_date = new DateTime($item['expiry_date']);
									$today = new DateTime();
									$diff = $today->diff($exp_date)->days;
									$is_expired = $exp_date < $today;
									$is_expiring_soon = $diff <= 30 && !$is_expired;
									?>
									<span style="color: <?php echo $is_expired ? '#C62828' : ($is_expiring_soon ? '#F57C00' : '#333'); ?>;">
										<?php echo $exp_date->format('M j, Y'); ?>
									</span>
									<?php if ($is_expired): ?>
										<br><small style="color: #C62828;">Expired</small>
									<?php elseif ($is_expiring_soon): ?>
										<br><small style="color: #F57C00;">Expires in <?php echo $diff; ?> days</small>
									<?php endif; ?>
								<?php else: ?>
									<span style="color: #999;">-</span>
								<?php endif; ?>
							</td>
							<td>
								<button class="action-btn btn-edit" onclick="editItem(<?php echo $item['id']; ?>)" title="Edit Item">
									<i class="fas fa-edit"></i> Edit
								</button>
								<button class="action-btn btn-delete" onclick="deleteItem(<?php echo $item['id']; ?>, '<?php echo addslashes($item['item_name']); ?>')" title="Delete Item">
									<i class="fas fa-trash"></i> Delete
								</button>
							</td>
						</tr>
						<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<!-- Edit Item Modal -->
	<div id="editModal" class="modal">
		<div class="modal-content">
			<div class="modal-header">
				<h3><i class="fas fa-edit"></i> Edit Inventory Item</h3>
				<button class="close" onclick="closeEditModal()">&times;</button>
			</div>
			<form id="editForm">
				<input type="hidden" name="item_id" id="edit_item_id">
				
				<div class="form-group">
					<label for="edit_badge_number">Badge Number</label>
					<input type="text" id="edit_badge_number" name="badge_number" class="form-control" readonly style="background-color: #F5F5F5; cursor: not-allowed; font-family: 'Courier New', monospace; font-weight: 600; color: #1976D2;">
					<small style="color: #666; font-size: 12px;">Badge number is automatically assigned and cannot be changed.</small>
				</div>
				
				<div class="form-group">
					<label for="edit_item_name">Medicine Name *</label>
					<input type="text" id="edit_item_name" name="item_name" class="form-control" required>
				</div>
				
				<div class="form-group">
					<label for="edit_quantity">Quantity in Stock *</label>
					<input type="number" id="edit_quantity" name="quantity" class="form-control" min="0" required>
				</div>
				
				<div class="form-group">
					<label for="edit_expiry_date">Expiration Date</label>
					<input type="date" id="edit_expiry_date" name="expiry_date" class="form-control">
				</div>
				
				<div class="form-group">
					<label for="edit_category">Medicine Category/Type *</label>
					<select id="edit_category" name="category" class="form-control" required>
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
					<label for="edit_unit">Unit *</label>
					<select id="edit_unit" name="unit" class="form-control" required>
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
				
				<div class="form-group">
					<label for="edit_reorder_level">Reorder Level *</label>
					<input type="number" id="edit_reorder_level" name="reorder_level" class="form-control" min="0" required>
				</div>
				
				<div class="form-group">
					<label for="edit_notes">Optional Notes (e.g., recalls, special instructions)</label>
					<textarea id="edit_notes" name="notes" class="form-control" placeholder="Enter any special notes, recalls, or instructions..."></textarea>
				</div>
				
				<!-- Add Stock to Existing Inventory Section -->
				<div class="form-group">
					<label for="edit_current_stock_display">Current Stock</label>
					<input type="text" id="edit_current_stock_display" class="form-control" readonly style="background-color: #F5F5F5; cursor: not-allowed; font-weight: 600; color: #2E7D32;">
					<input type="hidden" id="edit_current_stock_value">
				</div>
				
				<div class="form-group">
					<label for="edit_add_quantity">Quantity to Add</label>
					<input type="number" id="edit_add_quantity" name="add_quantity" class="form-control" min="0" placeholder="Enter quantity to add (leave 0 to not add)">
					<small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
						This will be added to the current stock. The new total will be calculated automatically.
					</small>
				</div>
				
				<div class="form-group">
					<label for="edit_add_stock_expiry_date">Expiration Date for New Stock (Optional)</label>
					<input type="date" id="edit_add_stock_expiry_date" name="add_stock_expiry_date" class="form-control">
					<small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">
						Leave empty to keep existing expiry date, or set a new one for this batch.
					</small>
				</div>
				
				<div class="form-group">
					<label>New Total Stock After Addition</label>
					<div style="background: #E8F5E9; padding: 12px 15px; border-radius: 8px; border-left: 4px solid #2E7D32;">
						<strong style="color: #2E7D32;">New Total:</strong>
						<span id="edit_new_total_stock" style="font-size: 16px; font-weight: 600; color: #2E7D32; margin-left: 10px;">-</span>
					</div>
				</div>
				
				<div class="form-actions">
					<button type="button" class="btn-secondary" onclick="closeEditModal()">Cancel</button>
					<button type="submit" class="btn-save">
						<i class="fas fa-save"></i> Save Changes
					</button>
				</div>
			</form>
		</div>
	</div>

	<script>
		function searchInventory(event) {
			if (event.key === 'Enter') {
				const search = document.getElementById('inventory-search').value;
				window.location.href = `pharmacist_inventory.php?search=${encodeURIComponent(search)}&filter=<?php echo $filter; ?>`;
			}
		}
		
		function filterItems(filter) {
			window.location.href = `pharmacist_inventory.php?filter=${filter}&search=<?php echo urlencode($search); ?>`;
		}
		
		function toggleFilter() {
			const tabs = document.querySelector('.filter-tabs');
			tabs.style.display = tabs.style.display === 'none' ? 'flex' : 'none';
		}
		
		function editItem(id) {
			fetch(`pharmacist_get_item.php?id=${id}`)
				.then(r => r.json())
				.then(data => {
					if (data.error) {
						alert('Error loading item: ' + data.error);
						return;
					}
					
					const currentQty = parseInt(data.quantity) || 0;
					const unit = data.unit || 'units';
					
					document.getElementById('edit_item_id').value = data.id;
					document.getElementById('edit_badge_number').value = data.badge_number || 'N/A';
					document.getElementById('edit_item_name').value = data.item_name;
					document.getElementById('edit_quantity').value = data.quantity;
					document.getElementById('edit_expiry_date').value = data.expiry_date || '';
					document.getElementById('edit_category').value = data.category || '';
					document.getElementById('edit_unit').value = data.unit || '';
					document.getElementById('edit_reorder_level').value = data.reorder_level || 10;
					document.getElementById('edit_notes').value = data.notes || '';
					
					// Populate add stock section
					document.getElementById('edit_current_stock_display').value = currentQty + ' ' + unit;
					document.getElementById('edit_current_stock_value').value = currentQty;
					document.getElementById('edit_add_quantity').value = '';
					document.getElementById('edit_add_stock_expiry_date').value = '';
					document.getElementById('edit_new_total_stock').textContent = currentQty + ' ' + unit;
					
					document.getElementById('editModal').style.display = 'block';
					document.body.style.overflow = 'hidden';
					// Scroll to top of modal
					document.getElementById('editModal').scrollTop = 0;
				})
				.catch(err => {
					console.error('Error:', err);
					alert('Error loading item data');
				});
		}
		
		function closeEditModal() {
			document.getElementById('editModal').style.display = 'none';
			document.body.style.overflow = '';
		}
		
		// Calculate new total when add quantity changes
		document.getElementById('edit_add_quantity').addEventListener('input', function() {
			const currentQty = parseInt(document.getElementById('edit_current_stock_value').value) || 0;
			const addQty = parseInt(this.value) || 0;
			const unit = document.getElementById('edit_unit').value || 'units';
			const newTotal = currentQty + addQty;
			document.getElementById('edit_new_total_stock').textContent = newTotal + ' ' + unit;
		});
		
		function deleteItem(id, itemName) {
			showDeleteConfirm(itemName, 'Inventory Item', function() {
				window.location.href = `pharmacist_inventory.php?delete_id=${id}`;
			}, 'This action cannot be undone.');
		}
		
		// Handle edit form submission
		document.getElementById('editForm').addEventListener('submit', function(e) {
			e.preventDefault();
			
			const formData = new FormData(this);
			const addQuantity = parseInt(formData.get('add_quantity')) || 0;
			const addStockExpiry = formData.get('add_stock_expiry_date');
			
			// If stock is being added, handle it separately first
			if (addQuantity > 0) {
				const addStockData = new FormData();
				addStockData.append('item_id', formData.get('item_id'));
				addStockData.append('add_quantity', addQuantity);
				if (addStockExpiry) {
					addStockData.append('expiry_date', addStockExpiry);
				}
				
				fetch('pharmacist_add_stock.php', {
					method: 'POST',
					body: addStockData
				})
				.then(r => r.json())
				.then(data => {
					if (data.success) {
						// After adding stock, update the quantity field in the form
						document.getElementById('edit_quantity').value = data.new_quantity;
						// Update formData with new quantity
						formData.set('quantity', data.new_quantity);
						formData.delete('add_quantity');
						formData.delete('add_stock_expiry_date');
						
						// Now update the item with other changes
						return fetch('pharmacist_update_item.php', {
							method: 'POST',
							body: formData
						});
					} else {
						throw new Error(data.message);
					}
				})
				.then(r => r.json())
				.then(data => {
					if (data.success) {
						alert('Item updated and stock added successfully!');
						closeEditModal();
						window.location.reload();
					} else {
						alert('Error: ' + data.message);
					}
				})
				.catch(err => {
					console.error('Error:', err);
					alert('Error updating item: ' + err.message);
				});
			} else {
				// No stock addition, just update normally
				formData.delete('add_quantity');
				formData.delete('add_stock_expiry_date');
				
				fetch('pharmacist_update_item.php', {
					method: 'POST',
					body: formData
				})
				.then(r => r.json())
				.then(data => {
					if (data.success) {
						alert('Item updated successfully!');
						closeEditModal();
						window.location.reload();
					} else {
						alert('Error: ' + data.message);
					}
				})
				.catch(err => {
					console.error('Error:', err);
					alert('Error updating item');
				});
			}
		});
		
		// Close modal when clicking outside
		window.addEventListener('click', function(e) {
			const editModal = document.getElementById('editModal');
			if (e.target === editModal) {
				closeEditModal();
			}
		});
	</script>

</body>
</html>