<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

// Check if user is logged in and is a pharmacist
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pharmacist') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get inventory counts by category
    // Map categories to chart categories - handle various category formats
    $antibiotic_categories = ['Antibiotics', 'Antibiotic', 'antibiotic'];
    $vitamin_categories = ['Vitamins', 'Vitamin', 'vitamin', 'Vitamin/Supplement'];
    $pain_reliever_categories = ['Pain Relief', 'Pain Reliever', 'pain_reliever', 'Pain Relievers'];
    
    $chart_data = [];
    $max_quantity = 0;
    
    // Antibiotics
    $placeholders = implode(',', array_fill(0, count($antibiotic_categories), '?'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(quantity), 0) as total_quantity
        FROM inventory
        WHERE category IN ($placeholders)
    ");
    $stmt->execute($antibiotic_categories);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $antibiotic_count = (int)($result['count'] ?? 0);
    $antibiotic_quantity = (int)($result['total_quantity'] ?? 0);
    $chart_data['Antibiotics'] = ['count' => $antibiotic_count, 'total_quantity' => $antibiotic_quantity];
    if ($antibiotic_quantity > $max_quantity) $max_quantity = $antibiotic_quantity;
    
    // Vitamins
    $placeholders = implode(',', array_fill(0, count($vitamin_categories), '?'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(quantity), 0) as total_quantity
        FROM inventory
        WHERE category IN ($placeholders)
    ");
    $stmt->execute($vitamin_categories);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $vitamin_count = (int)($result['count'] ?? 0);
    $vitamin_quantity = (int)($result['total_quantity'] ?? 0);
    $chart_data['Vitamins'] = ['count' => $vitamin_count, 'total_quantity' => $vitamin_quantity];
    if ($vitamin_quantity > $max_quantity) $max_quantity = $vitamin_quantity;
    
    // Pain Relievers
    $placeholders = implode(',', array_fill(0, count($pain_reliever_categories), '?'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(quantity), 0) as total_quantity
        FROM inventory
        WHERE category IN ($placeholders)
    ");
    $stmt->execute($pain_reliever_categories);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $pain_count = (int)($result['count'] ?? 0);
    $pain_quantity = (int)($result['total_quantity'] ?? 0);
    $chart_data['Pain Relievers'] = ['count' => $pain_count, 'total_quantity' => $pain_quantity];
    if ($pain_quantity > $max_quantity) $max_quantity = $pain_quantity;
    
    // Others - everything that doesn't match the above categories
    $all_categories = array_merge($antibiotic_categories, $vitamin_categories, $pain_reliever_categories);
    $placeholders = implode(',', array_fill(0, count($all_categories), '?'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(quantity), 0) as total_quantity
        FROM inventory
        WHERE (category NOT IN ($placeholders) OR category IS NULL)
    ");
    $stmt->execute($all_categories);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $other_count = (int)($result['count'] ?? 0);
    $other_quantity = (int)($result['total_quantity'] ?? 0);
    $chart_data['Others'] = ['count' => $other_count, 'total_quantity' => $other_quantity];
    if ($other_quantity > $max_quantity) $max_quantity = $other_quantity;
    
    echo json_encode([
        'success' => true,
        'data' => $chart_data,
        'max_quantity' => $max_quantity
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

