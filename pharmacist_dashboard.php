<?php
session_start();
require_once 'db.php';
require_once 'admin_helpers_simple.php';
require_once 'auto_audit_log.php'; // Auto-log page access

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pharmacist') {
	header("Location: Login.php");
	exit();
}

// Check maintenance mode - redirect to maintenance page
if (isMaintenanceMode()) {
    header('Location: maintenance_mode.php');
    exit;
}

// Basic pharmacist dashboard stats (inventory-focused)
try {
	$total_items = (int)$pdo->query("SELECT COUNT(*) FROM inventory")->fetchColumn();
	$low_stock = (int)$pdo->query("SELECT COUNT(*) FROM inventory WHERE quantity <= reorder_level")->fetchColumn();
	$out_of_stock = (int)$pdo->query("SELECT COUNT(*) FROM inventory WHERE quantity = 0")->fetchColumn();
	$expiring_soon = (int)$pdo->query("SELECT COUNT(*) FROM inventory WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date IS NOT NULL")->fetchColumn();
} catch(PDOException $e) {
	$error = "Error fetching data: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Pharmacist Dashboard - HealthServe</title>
	<link rel="stylesheet" href="assets/Style1.css">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
	<!-- Sidebar (Admin UI style) -->
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
					<a href="pharmacist_dashboard.php" class="nav-link active">
						<div class="nav-icon"><i class="fas fa-th-large"></i></div>
						Dashboard
					</a>
				</li>
				<li class="nav-item">
					<a href="pharmacist_inventory.php" class="nav-link">
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
		<!-- Topbar -->
		<header class="admin-header">
			<div class="header-title">
				<img src="assets/payatas logo.png" alt="Payatas B Logo" class="barangay-seal">
				<div>
					<h1>HealthServe - Payatas B</h1>
					<p>Pharmacist Portal</p>
				</div>
			</div>
		</header>

		<!-- Content Area -->
		<div class="content-area">
			<div class="page-header">
				<h2 class="page-title">Dashboard</h2>
			</div>

			<?php if (!empty($error)): ?>
				<div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
			<?php endif; ?>

			<!-- Inventory-focused quick stats -->
			<div class="stats-grid">
				<div class="stat-card">
					<div class="stat-icon inventory">
						<i class="fas fa-clipboard-list"></i>
					</div>
					<div class="stat-details">
						<h3><?php echo (int)$total_items; ?></h3>
						<p>Total Items</p>
					</div>
				</div>

				<div class="stat-card">
					<div class="stat-icon patients">
						<i class="fas fa-box-open"></i>
					</div>
					<div class="stat-details">
						<h3><?php echo (int)$out_of_stock; ?></h3>
						<p>Out of Stock</p>
					</div>
				</div>

				<div class="stat-card">
					<div class="stat-icon appointments">
						<i class="fas fa-triangle-exclamation"></i>
					</div>
					<div class="stat-details">
						<h3><?php echo (int)$low_stock; ?></h3>
						<p>Low Stock</p>
					</div>
				</div>

				<div class="stat-card">
					<div class="stat-icon staff">
						<i class="fas fa-clock"></i>
					</div>
					<div class="stat-details">
						<h3><?php echo (int)$expiring_soon; ?></h3>
						<p>Expiring Soon</p>
					</div>
				</div>
			</div>

			<!-- Inventory Overview (visual bars, uses existing palette) -->
			<div class="table-container">
				<div class="table-header">
					<h3 style="color:#2E7D32;margin:0;">Inventory Overview</h3>
				</div>
				<div style="padding:24px;">
					<div id="inventory-chart" style="display:flex;align-items:flex-end;gap:24px;justify-content:space-around;min-height:220px;">
						<!-- Chart bars will be dynamically generated here -->
					</div>
				</div>
			</div>

			<!-- Notifications (styled list) -->
			<div class="table-container">
				<div class="table-header" style="display: flex; justify-content: space-between; align-items: center;">
					<h3 style="color:#2E7D32;margin:0;">Notifications</h3>
					<div class="notification-filters" style="display: flex; gap: 8px;">
						<button class="filter-btn active" data-filter="active" style="padding: 6px 12px; border: 1px solid #2E7D32; background: #2E7D32; color: white; border-radius: 4px; cursor: pointer; font-size: 13px;">Active</button>
						<button class="filter-btn" data-filter="archived" style="padding: 6px 12px; border: 1px solid #e0e0e0; background: white; color: #666; border-radius: 4px; cursor: pointer; font-size: 13px;">Archived</button>
					</div>
				</div>
				<div id="notifications-container" style="padding:16px 24px;">
					<!-- Notifications will be dynamically generated here -->
					<div style="text-align:center;padding:2rem;color:#999;">
						<i class="fas fa-spinner fa-spin"></i> Loading notifications...
					</div>
				</div>
			</div>
		</div>
	</div>

	<style>
		.chart-bar-container {
			text-align: center;
			position: relative;
		}
		.chart-bar {
			position: relative;
			width: 60px;
			border-radius: 8px 8px 0 0;
			box-shadow: 0 4px 10px rgba(0,0,0,0.08);
			transition: all 0.3s ease;
			cursor: pointer;
		}
		.chart-bar:hover {
			transform: scale(1.05);
			box-shadow: 0 6px 15px rgba(0,0,0,0.15);
		}
		.chart-bar-label {
			margin-top: 8px;
			color: #4b5563;
			font-weight: 600;
		}
		.tooltip {
			position: absolute;
			top: -45px;
			left: 50%;
			transform: translateX(-50%);
			background: #333;
			color: white;
			padding: 8px 12px;
			border-radius: 6px;
			font-size: 12px;
			white-space: nowrap;
			opacity: 0;
			pointer-events: none;
			transition: opacity 0.3s ease;
			z-index: 1000;
		}
		.tooltip::after {
			content: '';
			position: absolute;
			top: 100%;
			left: 50%;
			transform: translateX(-50%);
			border: 5px solid transparent;
			border-top-color: #333;
		}
		.chart-bar-container:hover .tooltip {
			opacity: 1;
		}
		.notification-item {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 12px 0;
			border-bottom: 1px solid #E0E0E0;
		}
		.notification-item:last-child {
			border-bottom: none;
		}
		.notification-content {
			flex: 1;
		}
		.notification-message {
			color: #333;
			margin-bottom: 4px;
		}
		.notification-time {
			color: #999;
			font-size: 12px;
		}
		
		/* Action Buttons - Matching Pharmacist Inventory Style */
		.notification-actions .action-btn {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			padding: 12px 20px;
			border: none;
			border-radius: 8px;
			cursor: pointer;
			font-size: 14px;
			font-weight: 500;
			transition: all 0.3s ease;
			text-decoration: none;
			min-height: 40px;
			min-width: 80px;
			white-space: nowrap;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
			justify-content: center;
			margin: 2px;
		}

		.notification-actions .action-btn:hover {
			transform: translateY(-1px);
			box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
		}

		.notification-actions .action-btn i {
			font-size: 14px;
			width: 16px;
			text-align: center;
		}

		.notification-actions .action-btn.btn-archive {
			background: #fff3e0;
			color: #f57c00;
			border: 1px solid rgba(245, 124, 0, 0.2);
		}

		.notification-actions .action-btn.btn-archive:hover {
			background: #ffe0b2;
			color: #ef6c00;
		}

		.notification-actions .action-btn.btn-restore {
			background: #e8f5e9;
			color: #2e7d32;
			border: 1px solid rgba(46, 125, 50, 0.2);
		}

		.notification-actions .action-btn.btn-restore:hover {
			background: #c8e6c9;
			color: #1b5e20;
		}

		.notification-actions .action-btn.btn-delete {
			background: #ffebee;
			color: #d32f2f;
			border: 1px solid rgba(211, 47, 47, 0.2);
		}

		.notification-actions .action-btn.btn-delete:hover {
			background: #ffcdd2;
			color: #c62828;
		}
	</style>

	<script>
		// Load inventory chart data
		async function loadInventoryChart() {
			try {
				const response = await fetch('get_inventory_chart_data.php');
				const data = await response.json();
				
				if (data.success) {
					const chartContainer = document.getElementById('inventory-chart');
					chartContainer.innerHTML = '';
					
					const chartData = data.data;
					const maxQuantity = data.max_quantity || 1; // Avoid division by zero
					const maxHeight = 180; // Maximum bar height in pixels
					
					const categories = [
						{ name: 'Antibiotics', color: '#66BB6A', data: chartData['Antibiotics'] || { count: 0, total_quantity: 0 } },
						{ name: 'Vitamins', color: '#81C784', data: chartData['Vitamins'] || { count: 0, total_quantity: 0 } },
						{ name: 'Pain Relievers', color: '#AB47BC', data: chartData['Pain Relievers'] || { count: 0, total_quantity: 0 } },
						{ name: 'Others', color: '#26A69A', data: chartData['Others'] || { count: 0, total_quantity: 0 } }
					];
					
					categories.forEach(category => {
						const height = maxQuantity > 0 ? (category.data.total_quantity / maxQuantity) * maxHeight : 0;
						const minHeight = category.data.total_quantity > 0 ? 20 : 0; // Minimum height if there's data
						const finalHeight = Math.max(height, minHeight);
						
						const barContainer = document.createElement('div');
						barContainer.className = 'chart-bar-container';
						const tooltipText = category.data.total_quantity > 0 
							? `${category.data.total_quantity} ${category.name.toLowerCase()} in stock`
							: `No ${category.name.toLowerCase()} in stock`;
						barContainer.innerHTML = `
							<div class="tooltip">${tooltipText}</div>
							<div class="chart-bar" style="height:${finalHeight}px;background:${category.color};"></div>
							<div class="chart-bar-label">${category.name}</div>
						`;
						chartContainer.appendChild(barContainer);
					});
				}
			} catch (error) {
				console.error('Error loading chart data:', error);
			}
		}
		
		// Real-time Notification System
		(function(){
			class NotificationSystem{
				constructor(){
					this.notifications = [];
					this.pollInterval = null;
					this.currentFilter = 'active';
					this.init();
				}
				
				async init(){
					await this.fetchNotifications();
					this.bindEvents();
					this.startPolling();
				}
				
				bindEvents(){
					const filters = document.querySelectorAll('.filter-btn');
					filters.forEach(btn => {
						btn.addEventListener('click', e => {
							const filter = btn.getAttribute('data-filter');
							filters.forEach(b => {
								b.classList.remove('active');
								b.style.background = 'white';
								b.style.color = '#666';
								b.style.border = '1px solid #e0e0e0';
							});
							btn.classList.add('active');
							btn.style.background = '#2E7D32';
							btn.style.color = 'white';
							btn.style.border = '1px solid #2E7D32';
							this.currentFilter = filter;
							this.fetchNotifications(filter);
						});
					});
				}
				
				async fetchNotifications(filter = 'active'){
					try {
						this.currentFilter = filter;
						const response = await fetch(`get_pharmacist_notifications.php?action=fetch&filter=${filter}`);
						const data = await response.json();
						if(data.success){
							this.notifications = data.notifications;
							this.renderNotifications();
						}
					} catch(e){
						console.error('Error fetching notifications:', e);
					}
				}
				
				getBadgeType(type, message) {
					if (type === 'inventory_low' || message.toLowerCase().includes('low')) {
						return { text: 'LOW', class: 'status-pending' };
					} else if (type === 'inventory_expiring' || message.toLowerCase().includes('expiring')) {
						return { text: 'EXPIRING', class: 'status-confirmed' };
					} else if (type === 'announcement' || message.toLowerCase().includes('announcement')) {
						return { text: 'NEW', class: 'status-confirmed' };
					}
					return { text: 'INFO', class: 'status-active' };
				}
				
				renderNotifications(){
					const container = document.getElementById('notifications-container');
					if(!container) return;
					
					if(this.notifications.length === 0){
						container.innerHTML = '<div style="text-align:center;padding:2rem;color:#999;">No notifications</div>';
						return;
					}
					
					container.innerHTML = '';
					
					const isArchived = this.currentFilter === 'archived';
					
					this.notifications.forEach(notif => {
						const badge = this.getBadgeType(notif.type, notif.message);
						const item = document.createElement('div');
						item.className = 'notification-item';
						item.style.cssText = 'display: flex; align-items: center; justify-content: space-between; padding: 16px; border-bottom: 1px solid #f0f0f0; gap: 16px;';
						
						item.innerHTML = `
							<div class="notification-content" style="flex: 1;">
								<div class="notification-message">${this.escapeHtml(notif.message)}</div>
								<div class="notification-time" style="color: #999; font-size: 12px; margin-top: 4px;">${notif.time_ago}</div>
							</div>
							<div style="display: flex; align-items: center; gap: 12px;">
								<span class="status-badge ${badge.class}">${badge.text}</span>
								<div class="notification-actions" style="display: flex; gap: 8px;">
									${isArchived ? 
										`<button class="action-btn btn-restore restore-btn" data-id="${notif.id}" title="Restore">
											<i class="fas fa-undo"></i> Restore
										</button>` :
										`<button class="action-btn btn-archive archive-btn" data-id="${notif.id}" title="Archive">
											<i class="fas fa-archive"></i> Archive
										</button>`
									}
									<button class="action-btn btn-delete delete-btn" data-id="${notif.id}" title="Delete">
										<i class="fas fa-trash"></i> Delete
									</button>
								</div>
							</div>
						`;
						
						// Archive button
						const archiveBtn = item.querySelector('.archive-btn');
						if(archiveBtn){
							archiveBtn.addEventListener('click', e => {
								e.stopPropagation();
								this.archiveNotification(notif.id);
							});
						}
						
						// Restore button
						const restoreBtn = item.querySelector('.restore-btn');
						if(restoreBtn){
							restoreBtn.addEventListener('click', e => {
								e.stopPropagation();
								this.restoreNotification(notif.id);
							});
						}
						
						// Delete button
						const deleteBtn = item.querySelector('.delete-btn');
						deleteBtn.addEventListener('click', e => {
							e.stopPropagation();
							this.confirmDelete(notif.id, notif.message);
						});
						
						container.appendChild(item);
					});
				}
				
				async archiveNotification(id){
					try {
						const formData = new FormData();
						formData.append('notification_id', id);
						const response = await fetch('get_pharmacist_notifications.php?action=archive', {
							method: 'POST',
							body: formData
						});
						const data = await response.json();
						if(data.success){
							// Switch to archived view after archiving
							this.currentFilter = 'archived';
							// Update filter buttons
							const filters = document.querySelectorAll('.filter-btn');
							filters.forEach(b => {
								b.classList.remove('active');
								b.style.background = 'white';
								b.style.color = '#666';
								b.style.border = '1px solid #e0e0e0';
							});
							const archivedBtn = document.querySelector('.filter-btn[data-filter="archived"]');
							if (archivedBtn) {
								archivedBtn.classList.add('active');
								archivedBtn.style.background = '#2E7D32';
								archivedBtn.style.color = 'white';
								archivedBtn.style.border = '1px solid #2E7D32';
							}
							await this.fetchNotifications('archived');
						} else {
							alert('Error: ' + (data.message || 'Failed to archive notification'));
						}
					} catch(e){
						console.error('Error archiving notification:', e);
						alert('Error archiving notification');
					}
				}
				
				async restoreNotification(id){
					try {
						const formData = new FormData();
						formData.append('notification_id', id);
						const response = await fetch('get_pharmacist_notifications.php?action=restore', {
							method: 'POST',
							body: formData
						});
						const data = await response.json();
						if(data.success){
							await this.fetchNotifications(this.currentFilter);
						} else {
							alert('Error: ' + (data.message || 'Failed to restore notification'));
						}
					} catch(e){
						console.error('Error restoring notification:', e);
						alert('Error restoring notification');
					}
				}
				
				confirmDelete(id, text){
					const message = `Are you sure you want to permanently delete this notification?\n\n"${text.substring(0, 50)}${text.length > 50 ? '...' : ''}"\n\nThis action cannot be undone.`;
					if(confirm(message)){
						this.deleteNotification(id);
					}
				}
				
				async deleteNotification(id){
					try {
						const formData = new FormData();
						formData.append('notification_id', id);
						const response = await fetch('get_pharmacist_notifications.php?action=delete', {
							method: 'POST',
							body: formData
						});
						const data = await response.json();
						if(data.success){
							await this.fetchNotifications(this.currentFilter);
						} else {
							alert('Error: ' + (data.message || 'Failed to delete notification'));
						}
					} catch(e){
						console.error('Error deleting notification:', e);
						alert('Error deleting notification');
					}
				}
				
				escapeHtml(text) {
					const div = document.createElement('div');
					div.textContent = text;
					return div.innerHTML;
				}
				
				startPolling(){
					// Poll for new notifications every 10 seconds
					this.pollInterval = setInterval(() => {
						this.fetchNotifications();
					}, 10000);
				}
				
				stopPolling(){
					if(this.pollInterval){
						clearInterval(this.pollInterval);
						this.pollInterval = null;
					}
				}
			}
			
			window.notificationSystem = new NotificationSystem();
		})();
		
		// Load chart on page load
		loadInventoryChart();
		
		// Check for inventory notifications on page load
		fetch('check_inventory_notifications.php').catch(err => console.error('Error checking inventory:', err));
	</script>

</body>
</html>


