<?php
// One-time setup to enable pharmacist login on the current database (health_center1)
// - Extends users.role to include 'pharmacist'
// - Creates a default pharmacist user if missing
// Run once via browser, then delete this file for security.

session_start();
require 'db.php';

header('Content-Type: text/html; charset=utf-8');

function out($html) { echo $html; flush(); }

try {
    out('<h2>Pharmacist Setup</h2>');

    // 1) Ensure users.role supports pharmacist
    out('<p>Updating users.role to include pharmacist...</p>');
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','doctor','pharmacist','fdo','patient','user') NOT NULL DEFAULT 'user'");
    out('<p>Role enum updated.</p>');

    // 2) Create default pharmacist if not exists
    $username = 'pharmacist';
    $password = 'pharmacist123';
    $email = 'pharmacist@healthcenter.com';
    $full_name = 'Pharmacist User';

    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // If exists but role is not pharmacist, update role safely
        if ($existing['role'] !== 'pharmacist') {
            $upd = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
            $upd->execute(['pharmacist', $existing['id']]);
            out('<p>Existing user updated to role pharmacist.</p>');
        } else {
            out('<p>Pharmacist user already exists.</p>');
        }
    } else {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $ins = $pdo->prepare('INSERT INTO users (username, password_hash, email, role, full_name) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$username, $password_hash, $email, 'pharmacist', $full_name]);
        out('<p>Pharmacist user created.</p>');
    }

    out('<hr><p><strong>Credentials</strong>: username <code>pharmacist</code>, password <code>pharmacist123</code></p>');
    out('<p><a href="Login.php">Go to Login</a></p>');
    out('<p style="color:#a00">Security note: Delete <code>setup_pharmacist.php</code> after running.</p>');
} catch (Exception $e) {
    http_response_code(500);
    out('<h3>Error</h3><pre>' . htmlspecialchars($e->getMessage()) . '</pre>');
}
?>



