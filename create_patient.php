<?php
require 'db.php';

// Demo patient seed (safe to run multiple times)
$username = 'patient_demo';
$password = 'patient123'; // demo only, change in production
$email = 'patient_demo@example.com';
$full_name = 'Demo Patient';
$role = 'patient';

// Optional extended profile
$date_of_birth = '1995-05-15';
$gender = 'female';
$allergies = 'None reported';
$medical_history = 'N/A';
$emergency_contact = 'Jane Doe - 09123456789';

try {
    // Check if user exists
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$username, $email]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        echo "<h2>Demo patient already exists.</h2>";
        echo "<p>Credentials:</p><ul>";
        echo "<li><strong>Username:</strong> {$username}</li>";
        echo "<li><strong>Password:</strong> {$password}</li>";
        echo "</ul><p><a href='Login.php'>Login</a></p>";
        exit;
    }

    // Use 'patient' role as default since we've merged user and patient roles
    $role = 'patient';

    // Create user (be compatible with schemas without contact_no/address)
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $hasContact = false; $hasAddress = false;
    try {
        $res = $pdo->query("SHOW COLUMNS FROM users LIKE 'contact_no'");
        $hasContact = (bool)$res->fetchColumn();
    } catch (Exception $e) {}
    try {
        $res = $pdo->query("SHOW COLUMNS FROM users LIKE 'address'");
        $hasAddress = (bool)$res->fetchColumn();
    } catch (Exception $e) {}

    if ($hasContact && $hasAddress) {
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, email, role, full_name, contact_no, address) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$username, $password_hash, $email, $role, $full_name, '09112223344', 'Payatas B']);
    } else {
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, email, role, full_name) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$username, $password_hash, $email, $role, $full_name]);
    }
    $userId = (int)$pdo->lastInsertId();

    // Create patient profile (if table exists)
    $pdo->exec('CREATE TABLE IF NOT EXISTS patient_profiles (patient_id INT PRIMARY KEY, date_of_birth DATE NULL, gender ENUM(\'male\',\'female\',\'other\') NULL, medical_history TEXT NULL, allergies TEXT NULL, emergency_contact VARCHAR(255) NULL)');
    $stmt = $pdo->prepare('INSERT INTO patient_profiles (patient_id, date_of_birth, gender, medical_history, allergies, emergency_contact) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $date_of_birth, $gender, $medical_history, $allergies, $emergency_contact]);

    echo "<h2>Demo patient created successfully!</h2>";
    echo "<p>Credentials:</p><ul>";
    echo "<li><strong>Username:</strong> {$username}</li>";
    echo "<li><strong>Password:</strong> {$password}</li>";
    echo "</ul><p><a href='Login.php'>Click here to login</a></p>";

} catch (Exception $e) {
    echo "<h2>Error creating demo patient</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>


