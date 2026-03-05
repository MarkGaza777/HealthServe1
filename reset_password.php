<?php
session_start();
require 'db.php';

$error = '';
$success = false;
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error = 'Invalid or missing reset token.';
} else {
    // Verify token
    $stmt = $pdo->prepare('
        SELECT pr.id, pr.user_id, pr.email, pr.expires_at, u.username 
        FROM password_resets pr 
        JOIN users u ON pr.user_id = u.id 
        WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW() 
        LIMIT 1
    ');
    $stmt->execute([$token]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reset) {
        $error = 'Invalid or expired reset token. Please request a new password reset link.';
    } else {
        // Handle password reset form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            if (empty($password)) {
                $error = 'Please enter a new password';
            } elseif (strlen($password) < 6) {
                $error = 'Password must be at least 6 characters long';
            } elseif ($password !== $confirm_password) {
                $error = 'Passwords do not match';
            } else {
                try {
                    // Update password
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                    $stmt->execute([$password_hash, $reset['user_id']]);
                    
                    // Mark token as used
                    $stmt = $pdo->prepare('UPDATE password_resets SET used = 1 WHERE id = ?');
                    $stmt->execute([$reset['id']]);
                    
                    $success = true;
                } catch (PDOException $e) {
                    $error = 'An error occurred. Please try again later.';
                    error_log("Password reset error: " . $e->getMessage());
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - HealthServe</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-title">Reset Password</div>
            
            <?php if($success): ?>
                <div class="alert alert-success" style="background: #E8F5E9; color: #2E7D32; padding: 12px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #66BB6A;">
                    Your password has been reset successfully! You can now <a href="Login.php" style="color: #2E7D32; font-weight: bold;">log in</a> with your new password.
                </div>
            <?php else: ?>
                <?php if($error): ?>
                    <div class="alert alert-error"><?=htmlspecialchars($error)?></div>
                <?php endif; ?>
                
                <?php if($reset): ?>
                    <div class="auth-subtitle">Hello <?=htmlspecialchars($reset['username'])?>,<br>Please enter your new password below</div>
                    
                    <form method="post">
                        <div class="form-group">
                            <label for="password">New Password</label>
                            <input type="password" id="password" name="password" class="form-input" required minlength="6" placeholder="Enter new password (min. 6 characters)">
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-input" required minlength="6" placeholder="Confirm new password">
                        </div>
                        
                        <button type="submit" class="btn-primary">Reset Password</button>
                        
                        <div style="text-align: center; margin-top: 1rem;">
                            <a href="Login.php" class="auth-link">Back to Login</a>
                        </div>
                    </form>
                <?php else: ?>
                    <div style="text-align: center; margin-top: 2rem;">
                        <a href="forgot_password.php" class="auth-link">Request New Reset Link</a>
                    </div>
                    <div style="text-align: center; margin-top: 1rem;">
                        <a href="Login.php" class="auth-link">Back to Login</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Password confirmation validation
        document.getElementById('confirm_password')?.addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (password !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>

