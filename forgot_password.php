<?php
session_start();
require 'db.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address';
    } else {
        // Check if user exists
        $stmt = $pdo->prepare('SELECT id, username, email, first_name FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token expires in 1 hour
            
            // Store token in database (create table if it doesn't exist)
            try {
                // Check if password_resets table exists, create if not
                $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    token VARCHAR(64) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    used TINYINT(1) DEFAULT 0,
                    INDEX idx_token (token),
                    INDEX idx_user (user_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )");
                
                // Delete old unused tokens for this user
                $stmt = $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? AND (used = 1 OR expires_at < NOW())');
                $stmt->execute([$user['id']]);
                
                // Insert new token
                $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, email, token, expires_at) VALUES (?, ?, ?, ?)');
                $stmt->execute([$user['id'], $email, $token, $expires]);
                
                // Send email with reset link
                $reset_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . 
                              "://" . $_SERVER['HTTP_HOST'] . 
                              dirname($_SERVER['PHP_SELF']) . 
                              "/reset_password.php?token=" . $token;
                
                // Try to include PHPMailer (multiple possible locations)
                $phpmailer_loaded = false;
                
                // Try Composer autoload first
                if (file_exists('vendor/autoload.php')) {
                    require_once 'vendor/autoload.php';
                    $phpmailer_loaded = class_exists('PHPMailer\PHPMailer\PHPMailer');
                }
                
                // Try direct PHPMailer includes if Composer didn't work
                if (!$phpmailer_loaded && file_exists('vendor/PHPMailer/PHPMailer/src/PHPMailer.php')) {
                    require_once 'vendor/PHPMailer/PHPMailer/src/PHPMailer.php';
                    require_once 'vendor/PHPMailer/PHPMailer/src/SMTP.php';
                    require_once 'vendor/PHPMailer/PHPMailer/src/Exception.php';
                    $phpmailer_loaded = class_exists('PHPMailer\PHPMailer\PHPMailer');
                }
                
                if ($phpmailer_loaded) {
                    try {
                        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                        
                        // Load email configuration (if config file exists)
                        $email_config = [];
                        if (file_exists('email_config.php')) {
                            $email_config = require 'email_config.php';
                        }
                        
                        // SMTP Configuration
                        $mail->isSMTP();
                        $mail->Host = $email_config['smtp_host'] ?? 'smtp.gmail.com';
                        $mail->SMTPAuth = true;
                        $mail->Username = $email_config['smtp_username'] ?? 'your-email@gmail.com';
                        $mail->Password = $email_config['smtp_password'] ?? 'your-app-password';
                        $mail->SMTPSecure = ($email_config['smtp_encryption'] ?? 'tls') === 'ssl' 
                            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS 
                            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port = $email_config['smtp_port'] ?? 587;
                        $mail->CharSet = 'UTF-8';
                        
                        // From address
                        $from_email = $email_config['from_email'] ?? 'noreply@healthserve.ph';
                        $from_name = $email_config['from_name'] ?? 'HealthServe - Payatas B';
                        
                        // Email content
                        $mail->setFrom($from_email, $from_name);
                        $mail->addAddress($email, $user['username']);
                        $mail->isHTML(true);
                        $mail->Subject = 'Password Reset Request - HealthServe';
                        $mail->Body = "
                            <html>
                            <head>
                                <style>
                                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                                    .header { background: #2E7D32; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                                    .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
                                    .button { display: inline-block; background: #2E7D32; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; margin: 20px 0; }
                                    .button:hover { background: #388E3C; }
                                    .warning { color: #F57C00; font-weight: bold; }
                                </style>
                            </head>
                            <body>
                                <div class='container'>
                                    <div class='header'>
                                        <h2>HealthServe - Payatas B</h2>
                                        <p>Password Reset Request</p>
                                    </div>
                                    <div class='content'>
                                        <p>Hello " . htmlspecialchars($user['first_name'] ?? $user['username']) . ",</p>
                                        <p>We received a request to reset your password for your HealthServe account.</p>
                                        <p>Click the button below to reset your password:</p>
                                        <p style='text-align: center;'>
                                            <a href='" . htmlspecialchars($reset_link) . "' class='button'>Reset Password</a>
                                        </p>
                                        <p>Or copy and paste this link into your browser:</p>
                                        <p style='word-break: break-all; color: #666;'>" . htmlspecialchars($reset_link) . "</p>
                                        <p class='warning'>This link will expire in 1 hour.</p>
                                        <p>If you did not request a password reset, please ignore this email or contact support if you have concerns.</p>
                                        <p>Best regards,<br>HealthServe - Payatas B Team</p>
                                    </div>
                                </div>
                            </body>
                            </html>
                        ";
                        $mail->AltBody = "Hello " . ($user['first_name'] ?? $user['username']) . ",\n\nWe received a request to reset your password.\n\nClick this link to reset: " . $reset_link . "\n\nThis link expires in 1 hour.\n\nIf you didn't request this, please ignore this email.";
                        
                        $mail->send();
                        $message = 'Password reset link has been sent to your email address. Please check your inbox and follow the instructions.';
                    } catch (Exception $e) {
                        // If email fails, still show success message (security: don't reveal if email exists)
                        // Log the error for debugging
                        error_log("Password reset email failed: " . $mail->ErrorInfo);
                        $message = 'If an account with that email exists, a password reset link has been sent. Please check your inbox.';
                    }
                } else {
                    // PHPMailer not installed - show development message
                    // In production, you should install PHPMailer
                    error_log("PHPMailer not found. Reset link for user {$user['id']}: {$reset_link}");
                    $message = 'Password reset functionality requires PHPMailer to be installed. Please contact the administrator.';
                    // For development/testing, you could display the link (remove in production):
                    // $message = 'Development mode: Reset link - ' . $reset_link;
                }
            } catch (PDOException $e) {
                error_log("Database error in password reset: " . $e->getMessage());
                $error = 'An error occurred. Please try again later.';
            }
        } else {
            // Don't reveal if email exists (security best practice)
            $message = 'If an account with that email exists, a password reset link has been sent. Please check your inbox.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - HealthServe</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-title">Forgot Password</div>
            <div class="auth-subtitle">Enter your email address and we'll send you a link to reset your password</div>
            
            <?php if($message): ?>
                <div class="alert alert-success" style="background: #E8F5E9; color: #2E7D32; padding: 12px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #66BB6A;">
                    <?=htmlspecialchars($message)?>
                </div>
            <?php endif; ?>
            
            <?php if($error): ?>
                <div class="alert alert-error"><?=htmlspecialchars($error)?></div>
            <?php endif; ?>
            
            <form method="post">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-input" required placeholder="Enter your email address">
                </div>
                
                <button type="submit" class="btn-primary">Send Reset Link</button>
                
                <div style="text-align: center; margin-top: 1rem;">
                    <a href="Login.php" class="auth-link">Back to Login</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

