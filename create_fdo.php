<?php
require 'db.php';

$username = 'fdo';
$password = 'fdo123';
$email = 'fdo@healthcenter.com';
$full_name = 'Front Desk Officer';

// Check if FDO user already exists
$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR role = ?');
$stmt->execute([$username, 'fdo']);
$existing = $stmt->fetch();

if ($existing) {
    // Update existing FDO user
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, email = ?, full_name = ?, role = ? WHERE username = ? OR role = ?');
    $stmt->execute([$hash, $email, $full_name, 'fdo', $username, 'fdo']);
    echo "FDO user updated successfully!<br>";
    echo "Username: $username<br>";
    echo "Password: $password<br>";
} else {
    // Create new FDO user
    $hash = password_hash($password, PASSWORD_BCRYPT);
    
    // First, check if role 'fdo' exists in ENUM, if not, we'll need to alter the table
    try {
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, email, role, full_name) VALUES (?,?,?,?,?)');
        $stmt->execute([$username, $hash, $email, 'fdo', $full_name]);
        echo "FDO user created successfully!<br>";
        echo "Username: $username<br>";
        echo "Password: $password<br>";
    } catch (PDOException $e) {
        // If role 'fdo' doesn't exist, alter the table
        if (strpos($e->getMessage(), 'Unknown column') === false && strpos($e->getMessage(), 'fdo') !== false) {
            echo "Updating users table to support 'fdo' role...<br>";
            try {
                $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','patient','pharmacist','doctor','fdo') NOT NULL DEFAULT 'patient'");
                $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, email, role, full_name) VALUES (?,?,?,?,?)');
                $stmt->execute([$username, $hash, $email, 'fdo', $full_name]);
                echo "FDO user created successfully!<br>";
                echo "Username: $username<br>";
                echo "Password: $password<br>";
            } catch (PDOException $e2) {
                echo "Error: " . $e2->getMessage();
            }
        } else {
            echo "Error: " . $e->getMessage();
        }
    }
}

echo "<br><a href='Login.php'>Go to Login Page</a>";
?>

