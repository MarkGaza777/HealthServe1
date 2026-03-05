<?php
require 'db.php';

// Create pharmacist user
$username = 'pharmacist';
$password = 'pharmacist123'; // Change to a secure password in production
$email = 'pharmacist@healthcenter.com';
$full_name = 'Pharmacist User';
$role = 'pharmacist';

try {
    // Check if pharmacist already exists
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR role = ?');
    $stmt->execute([$username, 'pharmacist']);

    if ($stmt->fetch()) {
        echo "<h2>Pharmacist user already exists!</h2>";
        echo "<p>Pharmacist credentials:</p>";
        echo "<ul>";
        echo "<li><strong>Username:</strong> pharmacist</li>";
        echo "<li><strong>Password:</strong> pharmacist123</li>";
        echo "</ul>";
        echo "<p><a href='Login.php'>Click here to login</a></p>";
    } else {
        // Create pharmacist user
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, email, role, full_name) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$username, $password_hash, $email, $role, $full_name]);

        echo "<h2>Pharmacist user created successfully!</h2>";
        echo "<p>Pharmacist credentials:</p>";
        echo "<ul>";
        echo "<li><strong>Username:</strong> pharmacist</li>";
        echo "<li><strong>Password:</strong> pharmacist123</li>";
        echo "</ul>";
        echo "<p><a href='Login.php'>Click here to login</a></p>";
    }
} catch (Exception $e) {
    echo "<h2>Error creating pharmacist user</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>


