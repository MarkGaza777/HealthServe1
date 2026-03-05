<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get all inventory items with quantity > 0, grouped by category
    $stmt = $pdo->query("
        SELECT DISTINCT category 
        FROM inventory 
        WHERE category IS NOT NULL AND category != '' AND quantity > 0
        ORDER BY category ASC
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get all medicines with their categories
    $stmt = $pdo->query("
        SELECT id, item_name, category, quantity, unit
        FROM inventory 
        WHERE quantity > 0
        ORDER BY category ASC, item_name ASC
    ");
    $medicines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group medicines by category
    $medicinesByCategory = [];
    foreach ($medicines as $medicine) {
        $category = $medicine['category'] ?: 'Other';
        if (!isset($medicinesByCategory[$category])) {
            $medicinesByCategory[$category] = [];
        }
        $medicinesByCategory[$category][] = [
            'id' => $medicine['id'],
            'name' => $medicine['item_name'],
            'quantity' => $medicine['quantity'],
            'unit' => $medicine['unit']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'categories' => $categories,
        'medicines' => $medicines,
        'medicinesByCategory' => $medicinesByCategory
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

