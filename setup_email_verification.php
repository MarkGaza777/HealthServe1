<?php
/**
 * Setup Script for Email Verification
 * 
 * This script will:
 * 1. Create the email_verifications table
 * 2. Add email_verified column to users table (if it doesn't exist)
 * 
 * Run this script once to set up the email verification system.
 * You can delete this file after running it.
 */

require 'db.php';

echo "<h2>Setting up Email Verification System</h2>";

try {
    // Create email_verifications table
    echo "<p>Creating email_verifications table...</p>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        email VARCHAR(150) NOT NULL,
        otp_code VARCHAR(6) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        verified_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user_id (user_id),
        KEY idx_email (email),
        KEY idx_otp_code (otp_code),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "<p style='color: green;'>✓ email_verifications table created successfully!</p>";
    
    // Check if email_verified column exists
    echo "<p>Checking for email_verified column in users table...</p>";
    $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'email_verified'");
    $stmt->execute();
    
    if (!$stmt->fetch()) {
        // Add email_verified column
        echo "<p>Adding email_verified column to users table...</p>";
        $pdo->exec("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) DEFAULT 0");
        echo "<p style='color: green;'>✓ email_verified column added successfully!</p>";
        
        // Set existing users (except patients) as verified
        echo "<p>Setting existing non-patient users as verified...</p>";
        $pdo->exec("UPDATE users SET email_verified = 1 WHERE role != 'patient'");
        echo "<p style='color: green;'>✓ Existing non-patient users marked as verified!</p>";
    } else {
        echo "<p style='color: blue;'>✓ email_verified column already exists!</p>";
    }
    
    echo "<h3 style='color: green;'>Setup completed successfully!</h3>";
    echo "<p><a href='signup.php'>Go to Sign Up Page</a> | <a href='Login.php'>Go to Login Page</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

