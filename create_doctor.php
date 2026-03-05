<?php
require 'db.php';

// Create doctor user
$username = 'doctor';
$password = 'doctor123'; // Change to a secure password in production
$email = 'doctor@healthcenter.com';
$full_name = 'Dr. Nomer Gumiran';
$role = 'doctor';

try {
    // Ensure users.role supports doctor
    try {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','doctor','pharmacist','fdo','patient','user') NOT NULL DEFAULT 'patient'");
        echo "<p>✓ Updated users.role to include 'doctor'</p>";
    } catch (Exception $e) {
        // Role enum might already be correct, ignore error
        echo "<p>✓ Role enum check completed</p>";
    }

    // Check if doctor already exists
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Update existing user to ensure correct role
        $stmt = $pdo->prepare('UPDATE users SET role = ?, password_hash = ? WHERE id = ?');
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt->execute([$role, $password_hash, $existing['id']]);
        
        echo "<h2>Doctor user updated!</h2>";
        echo "<p>Doctor credentials:</p>";
        echo "<ul>";
        echo "<li><strong>Username:</strong> doctor</li>";
        echo "<li><strong>Password:</strong> doctor123</li>";
        echo "</ul>";
        echo "<p><a href='Login.php'>Click here to login</a></p>";
    } else {
        // Create doctor user
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, email, role, full_name) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$username, $password_hash, $email, $role, $full_name]);

        echo "<h2>Doctor user created successfully!</h2>";
        echo "<p>Doctor credentials:</p>";
        echo "<ul>";
        echo "<li><strong>Username:</strong> doctor</li>";
        echo "<li><strong>Password:</strong> doctor123</li>";
        echo "</ul>";
        echo "<p><a href='Login.php'>Click here to login</a></p>";
    }
} catch (Exception $e) {
    echo "<h2>Error creating doctor user</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

