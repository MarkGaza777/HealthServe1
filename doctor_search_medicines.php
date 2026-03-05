<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$search = isset($_GET['q']) ? trim($_GET['q']) : '';

try {
    if (empty($search)) {
        // Return all medicines if no search query
        $stmt = $pdo->query("
            SELECT id, item_name, category, quantity, unit
            FROM inventory 
            WHERE quantity > 0
            ORDER BY item_name ASC
            LIMIT 100
        ");
    } else {
        // Search medicines by name: only show items that START with the typed letter(s)
        // e.g. "A" -> medicines starting with A; "Am" -> starting with Am
        $searchParam = $search . '%';
        $stmt = $pdo->prepare("
            SELECT id, item_name, category, quantity, unit
            FROM inventory 
            WHERE quantity > 0 AND item_name LIKE ?
            ORDER BY item_name ASC
            LIMIT 50
        ");
        $stmt->execute([$searchParam]);
    }
    
    $medicines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results = [];
    foreach ($medicines as $medicine) {
        $results[] = [
            'id' => $medicine['id'],
            'name' => $medicine['item_name'],
            'category' => $medicine['category'] ?? '',
            'quantity' => $medicine['quantity'],
            'unit' => $medicine['unit'] ?? 'pcs',
            'display' => $medicine['item_name'] . ' (' . $medicine['quantity'] . ' ' . ($medicine['unit'] ?? 'pcs') . ' available)'
        ];
    }
    
    echo json_encode([
        'success' => true,
        'medicines' => $results
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

