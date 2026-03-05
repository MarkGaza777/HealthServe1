<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pharmacist') {
	echo json_encode(['error' => 'Unauthorized access']);
	exit();
}

if (!isset($_GET['id'])) {
	echo json_encode(['error' => 'Item ID not provided']);
	exit();
}

try {
	$stmt = $pdo->prepare("SELECT * FROM inventory WHERE id = ?");
	$stmt->execute([$_GET['id']]);
	$item = $stmt->fetch(PDO::FETCH_ASSOC);
	
	if ($item) {
		echo json_encode($item);
	} else {
		echo json_encode(['error' => 'Item not found']);
	}
} catch(PDOException $e) {
	echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>