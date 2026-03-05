<?php
require 'db.php';

try {
    // Test the dashboard query
    $stmt = $pdo->query("SELECT COUNT(*) as today_appointments FROM appointments WHERE DATE(start_datetime) = CURDATE()");
    $count = $stmt->fetchColumn();
    echo "Today's appointments count: " . $count . "\n";
    
    // Show all appointments for today
    $stmt = $pdo->query("SELECT id, start_datetime, status FROM appointments WHERE DATE(start_datetime) = CURDATE()");
    $appointments = $stmt->fetchAll();
    echo "Appointments for today:\n";
    foreach($appointments as $apt) {
        echo "- ID: " . $apt['id'] . ", DateTime: " . $apt['start_datetime'] . ", Status: " . $apt['status'] . "\n";
    }
    
    // Show all appointments in the database
    echo "\nAll appointments in database:\n";
    $stmt = $pdo->query("SELECT id, start_datetime, status FROM appointments ORDER BY start_datetime DESC LIMIT 10");
    $all_appointments = $stmt->fetchAll();
    foreach($all_appointments as $apt) {
        echo "- ID: " . $apt['id'] . ", DateTime: " . $apt['start_datetime'] . ", Status: " . $apt['status'] . "\n";
    }
    
} catch(Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
