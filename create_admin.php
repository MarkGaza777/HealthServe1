<?php
require 'db.php';

// Create admin user
$username = 'admin';
$password = 'admin123'; // Change this to a secure password
$email = 'admin@healthcenter.com';
$full_name = 'System Administrator';
$role = 'admin';

try {
    // Check if admin already exists
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR role = ?');
    $stmt->execute([$username, 'admin']);
    
    if ($stmt->fetch()) {
        echo "<h2>Admin user already exists!</h2>";
        echo "<p>Admin credentials:</p>";
        echo "<ul>";
        echo "<li><strong>Username:</strong> admin</li>";
        echo "<li><strong>Password:</strong> admin123</li>";
        echo "</ul>";
        echo "<p><a href='Login.php'>Click here to login</a></p>";
    } else {
        // Create admin user
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, email, role, full_name) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$username, $password_hash, $email, $role, $full_name]);
        
        echo "<h2>Admin user created successfully!</h2>";
        echo "<p>Admin credentials:</p>";
        echo "<ul>";
        echo "<li><strong>Username:</strong> admin</li>";
        echo "<li><strong>Password:</strong> admin123</li>";
        echo "</ul>";
        echo "<p><a href='Login.php'>Click here to login</a></p>";
    }
} catch (Exception $e) {
    echo "<h2>Error creating admin user</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>