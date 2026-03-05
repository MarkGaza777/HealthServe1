<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: Login.php");
    exit();
}

// Get inventory statistics
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total_items FROM inventory");
    $total_items = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as low_stock FROM inventory WHERE quantity <= reorder_level");
    $low_stock = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as out_of_stock FROM inventory WHERE quantity = 0");
    $out_of_stock = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as expiring_soon FROM inventory WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date IS NOT NULL");
    $expiring_soon = $stmt->fetchColumn();
    
    // Get inventory items with filtering
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
        $where_clause .= " AND (item_name LIKE ? OR notes LIKE ? OR category LIKE ?)";
        $search_param = "%$search%";
        $params = [$search_param, $search_param, $search_param];
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
    
} catch(PDOException $e) {
    $error = "Error fetching inventory: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - HealthServe Admin</title>
    <link rel="stylesheet" href="assets/Style1.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="custom_modal.js"></script>
    <script src="custom_notification.js"></script>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="admin-profile">
            <div class="profile-info">
                <div class="profile-avatar">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="profile-details">
                    <h3>Admin</h3>
                    <div class="profile-status">Online</div>
                </div>
            </div>
        </div>

        <nav class="nav-section">
            <div class="nav-title">General</div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="Admin_dashboard1.php" class="nav-link">
                        <div class="nav-icon"><i class="fas fa-th-large"></i></div>
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_staff_management.php" class="nav-link">
                        <div class="nav-icon"><i class="fas fa-user-friends"></i></div>
                        Staffs
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_settings.php" class="nav-link">
                        <div class="nav-icon"><i class="fas fa-cog"></i></div>
                        Settings
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_announcements.php" class="nav-link">
                        <div class="nav-icon"><i class="fas fa-bullhorn"></i></div>
                        Announcements
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_notifications.php" class="nav-link">
                        <div class="nav-icon"><i class="fas fa-bell"></i></div>
                        Notifications
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
        <!-- Header -->
        <header class="admin-header">
            <div class="header-title">
                <img src="assets/payatas logo.png" alt="Payatas B Logo" class="barangay-seal">
                <div>
                    <h1>HealthServe - Payatas B</h1>
                    <p>Barangay Health Center Management System</p>
                </div>
            </div>
        </header>

        <!-- Content Area -->
        <div class="content-area">
            <div class="page-header">
                <h2 class="page-title">Inventory Management</h2>
            </div>

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

            <!-- Search and Filter Controls -->
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
                        <a href="admin_add_inventory.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add New Item
                        </a>
                    </div>
                </div>

                <!-- Filter Tabs -->
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

                <!-- Inventory Table -->
                <table class="data-table">
                    <thead>
                        <tr>
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
                            <td colspan="7" style="text-align: center; padding: 40px; color: #999;">
                                <i class="fas fa-boxes" style="font-size: 48px; margin-bottom: 15px;"></i><br>
                                No inventory items found.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($inventory_items as $item): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                <?php if (!empty($item['notes'])): ?>
                                    <br><small style="color: #666;"><?php echo htmlspecialchars(substr($item['notes'], 0, 50)) . '...'; ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo ucfirst(htmlspecialchars($item['category'] ?? '')); ?></td>
                            <td>
                                <strong style="color: <?php echo $item['quantity'] == 0 ? '#C62828' : ($item['quantity'] <= $item['reorder_level'] ? '#F57C00' : '#2E7D32'); ?>">
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
                                    <span style="color: <?php echo $is_expired ? '#C62828' : ($is_expiring_soon ? '#F57C00' : '#333'); ?>">
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
                                <button class="action-btn btn-view" onclick="adjustStock(<?php echo $item['id']; ?>)" title="Adjust Stock">
                                    <i class="fas fa-plus-minus"></i> Adjust
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

    <!-- Stock Adjustment Modal -->
    <div id="stockModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Adjust Stock</h3>
                <span class="close" onclick="closeStockModal()">&times;</span>
            </div>
            <form method="POST" action="admin_adjust_stock.php">
                <input type="hidden" name="item_id" id="stock_item_id">
                
                <div class="form-group">
                    <label>Item Name</label>
                    <input type="text" id="stock_item_name" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label>Current Stock</label>
                    <input type="text" id="current_stock" class="form-control" readonly>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Adjustment Type</label>
                        <select name="adjustment_type" id="adjustment_type" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="add">Add Stock</option>
                            <option value="subtract">Remove Stock</option>
                            <option value="set">Set Stock Level</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Quantity</label>
                        <input type="number" name="quantity" id="adjustment_quantity" class="form-control" min="0" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Reason</label>
                    <select name="reason" class="form-control" required>
                        <option value="">Select Reason</option>
                        <option value="received">Stock Received</option>
                        <option value="dispensed">Dispensed to Patient</option>
                        <option value="expired">Expired/Damaged</option>
                        <option value="lost">Lost/Stolen</option>
                        <option value="correction">Stock Correction</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Notes (Optional)</label>
                    <textarea name="notes" class="form-control" placeholder="Additional notes about this adjustment"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Update Stock</button>
                    <button type="button" class="btn btn-secondary" onclick="closeStockModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function searchInventory(event) {
            if (event.key === 'Enter') {
                const search = document.getElementById('inventory-search').value;
                window.location.href = `admin_inventory_management.php?search=${encodeURIComponent(search)}&filter=<?php echo $filter; ?>`;
            }
        }
        
        function filterItems(filter) {
            window.location.href = `admin_inventory_management.php?filter=${filter}&search=<?php echo urlencode($search); ?>`;
        }
        
        function editItem(id) {
            window.location.href = `admin_edit_inventory.php?id=${id}`;
        }
        
        function adjustStock(id) {
            // In a real implementation, fetch item data via AJAX
            fetch(`admin_get_item.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('stock_item_id').value = data.id;
                    document.getElementById('stock_item_name').value = data.item_name;
                    document.getElementById('current_stock').value = data.quantity + ' ' + data.unit;
                    document.getElementById('stockModal').style.display = 'block';
                })
                .catch(error => {
                    alert('Error loading item data');
                });
        }
        
        function deleteItem(id, itemName) {
            showDeleteConfirm(itemName, 'Inventory Item', function() {
                window.location.href = `admin_delete_inventory.php?id=${id}`;
            }, 'This action cannot be undone.');
        }
        
        function closeStockModal() {
            document.getElementById('stockModal').style.display = 'none';
        }
        
        // Calculate new stock level preview
        document.getElementById('adjustment_type').addEventListener('change', updateStockPreview);
        document.getElementById('adjustment_quantity').addEventListener('input', updateStockPreview);
        
        function updateStockPreview() {
            const type = document.getElementById('adjustment_type').value;
            const quantity = parseInt(document.getElementById('adjustment_quantity').value) || 0;
            const currentStock = parseInt(document.getElementById('current_stock').value) || 0;
            
            let newStock = currentStock;
            if (type === 'add') {
                newStock = currentStock + quantity;
            } else if (type === 'subtract') {
                newStock = Math.max(0, currentStock - quantity);
            } else if (type === 'set') {
                newStock = quantity;
            }
            
            // You could add a preview element here
        }
    </script>

    <style>
        .search-container {
            position: relative;
            display: flex;
            align-items: center;
            background: white;
            border: 2px solid #E0E0E0;
            border-radius: 25px;
            padding: 8px 15px;
            margin-right: 15px;
            min-width: 300px;
        }
        
        .search-container i {
            color: #999;
            margin-right: 10px;
        }
        
        .search-container input {
            border: none;
            outline: none;
            background: transparent;
            flex: 1;
            font-size: 14px;
        }
        
        .filter-tabs {
            padding: 15px 30px;
            background: #F8F9FA;
            border-bottom: 1px solid #E0E0E0;
            display: flex;
            gap: 10px;
        }
        
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        
        .modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #E0E0E0;
            padding-bottom: 15px;
        }
        
        .close {
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #E0E0E0;
        }
        
        .btn-more {
            background: #E3F2FD;
            color: #1565C0;
        }
        
        .status-out-of-stock {
            background: #FFEBEE;
            color: #C62828;
        }
        
        /* Action Buttons - Professional styling */
        .action-btn {
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
        
        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        .action-btn i {
            font-size: 14px;
            width: 16px;
            text-align: center;
        }
        
        .action-btn.btn-view {
            background: #e3f2fd;
            color: #1976d2;
            border: 1px solid rgba(25, 118, 210, 0.2);
        }
        
        .action-btn.btn-view:hover {
            background: #bbdefb;
            color: #1565c0;
        }
        
        .action-btn.btn-edit {
            background: #fff3e0;
            color: #f57c00;
            border: 1px solid rgba(245, 124, 0, 0.2);
        }
        
        .action-btn.btn-edit:hover {
            background: #ffe0b2;
            color: #ef6c00;
        }
        
        .action-btn.btn-delete {
            background: #ffebee;
            color: #d32f2f;
            border: 1px solid rgba(211, 47, 47, 0.2);
        }
        
        .action-btn.btn-delete:hover {
            background: #ffcdd2;
            color: #c62828;
        }
    </style>
</body>
</html>