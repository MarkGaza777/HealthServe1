<?php
session_start();
require 'db.php';

// Check if PHPMailer is available
$phpmailer_loaded = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    $phpmailer_loaded = class_exists('PHPMailer\PHPMailer\PHPMailer');
}

$err = '';
$success = '';
$user_id = $_SESSION['pending_verification_user_id'] ?? null;
$email = $_SESSION['pending_verification_email'] ?? null;

// Check if redirected from login due to unverified email
if (isset($_GET['unverified']) && $_GET['unverified'] == '1') {
    $err = 'Please verify your email address before logging in.';
}

// If no pending verification, redirect to signup
if (!$user_id || !$email) {
    header('Location: signup.php');
    exit;
}

// Get user info
$stmt = $pdo->prepare('SELECT id, first_name, last_name, email FROM users WHERE id = ? AND email = ? LIMIT 1');
$stmt->execute([$user_id, $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: signup.php');
    exit;
}

// Handle OTP verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['verify_otp'])) {
        $otp = trim($_POST['otp'] ?? '');
        
        if (empty($otp) || strlen($otp) !== 6) {
            $err = 'Please enter a valid 6-digit OTP code';
        } else {
            // Check OTP
            $stmt = $pdo->prepare('SELECT id, expires_at, verified_at FROM email_verifications 
                                   WHERE user_id = ? AND email = ? AND otp_code = ? 
                                   ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([$user_id, $email, $otp]);
            $verification = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($verification) {
                // Check if already verified
                if ($verification['verified_at']) {
                    $err = 'This OTP has already been used';
                } elseif (strtotime($verification['expires_at']) < time()) {
                    $err = 'This OTP has expired. Please request a new one.';
                } else {
                    // Mark as verified
                    $pdo->beginTransaction();
                    try {
                        // Update verification record
                        $stmt = $pdo->prepare('UPDATE email_verifications SET verified_at = NOW() WHERE id = ?');
                        $stmt->execute([$verification['id']]);
                        
                        // Update user email_verified status
                        $stmt = $pdo->prepare('UPDATE users SET email_verified = 1 WHERE id = ?');
                        $stmt->execute([$user_id]);
                        
                        $pdo->commit();
                        
                        // Clear session
                        unset($_SESSION['pending_verification_user_id']);
                        unset($_SESSION['pending_verification_email']);
                        
                        // Redirect to login with success message
                        header('Location: Login.php?verified=success');
                        exit;
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $err = 'An error occurred during verification: ' . $e->getMessage();
                    }
                }
            } else {
                $err = 'Invalid OTP code. Please check and try again.';
            }
        }
    } elseif (isset($_POST['resend_otp'])) {
        // Generate new OTP
        $otp_code = str_pad((string)rand(100000, 999999), 6, '0', STR_PAD_LEFT);
        $otp_expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        
        // Store new OTP
        $stmt = $pdo->prepare('INSERT INTO email_verifications (user_id, email, otp_code, expires_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$user_id, $email, $otp_code, $otp_expires]);
        
        // Send OTP email
        if ($phpmailer_loaded) {
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                
                // Load email configuration
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
                $mail->addAddress($email, trim($user['first_name'] . ' ' . $user['last_name']));
                $mail->isHTML(true);
                $mail->Subject = 'Email Verification Code - HealthServe';
                $mail->Body = "
                    <html>
                    <head>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                            .header { background: #2E7D32; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
                            .otp-box { background: #E8F5E9; border: 2px solid #2E7D32; border-radius: 8px; padding: 20px; text-align: center; margin: 20px 0; }
                            .otp-code { font-size: 32px; font-weight: bold; color: #2E7D32; letter-spacing: 5px; }
                            .warning { color: #F57C00; font-weight: bold; margin-top: 20px; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h2>HealthServe - Payatas B</h2>
                            </div>
                            <div class='content'>
                                <h3>New Verification Code</h3>
                                <p>Hello " . htmlspecialchars($user['first_name']) . ",</p>
                                <p>You have requested a new verification code. Please enter the OTP code below:</p>
                                
                                <div class='otp-box'>
                                    <div style='font-size: 14px; color: #666; margin-bottom: 10px;'>Your verification code is:</div>
                                    <div class='otp-code'>" . htmlspecialchars($otp_code) . "</div>
                                </div>
                                
                                <p>This code will expire in 15 minutes.</p>
                                <p class='warning'>⚠️ If you did not request this code, please ignore this email.</p>
                                <p>Best regards,<br>HealthServe - Payatas B Team</p>
                            </div>
                        </div>
                    </body>
                    </html>
                ";
                
                $mail->send();
                $success = 'A new verification code has been sent to your email.';
            } catch (Exception $e) {
                $err = 'Failed to send verification email. Please try again later.';
                error_log("Failed to send OTP email: " . $e->getMessage());
            }
        } else {
            $err = 'Email service is not available. Please contact support.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email - HealthServe Payatas B</title>
    <link rel="stylesheet" href="assets/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .otp-input-container {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 20px 0;
        }
        .otp-input {
            width: 50px;
            height: 60px;
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            border: 2px solid #ddd;
            border-radius: 8px;
            transition: border-color 0.3s;
        }
        .otp-input:focus {
            outline: none;
            border-color: #2E7D32;
        }
        .otp-single-input {
            width: 100%;
            max-width: 300px;
            height: 50px;
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            letter-spacing: 8px;
            border: 2px solid #ddd;
            border-radius: 8px;
            transition: border-color 0.3s;
        }
        .otp-single-input:focus {
            outline: none;
            border-color: #2E7D32;
        }
        .info-box {
            background: #E3F2FD;
            border-left: 4px solid #2196F3;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .resend-link {
            text-align: center;
            margin-top: 20px;
        }
        .resend-link form {
            display: inline;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-title">Verify Your Email</div>
            <div class="auth-subtitle">We've sent a verification code to<br><strong><?= htmlspecialchars($email) ?></strong></div>
            
            <?php if($success): ?>
                <div class="alert alert-success" style="background: #E8F5E9; color: #2E7D32; padding: 12px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #66BB6A;">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            
            <?php if($err): ?>
                <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
            <?php endif; ?>
            
            <div class="info-box">
                <i class="fas fa-info-circle"></i> Please check your email and enter the 6-digit verification code below. The code expires in 15 minutes.
            </div>
            
            <form method="post" id="verifyForm">
                <div class="form-group" style="text-align: center;">
                    <label for="otp" style="display: block; margin-bottom: 10px; font-weight: 600;">Enter Verification Code</label>
                    <input 
                        type="text" 
                        id="otp" 
                        name="otp" 
                        class="otp-single-input" 
                        maxlength="6" 
                        pattern="[0-9]{6}" 
                        inputmode="numeric"
                        autocomplete="off"
                        required
                        autofocus
                        placeholder="000000"
                    >
                    <small style="display: block; margin-top: 8px; color: #666;">Enter the 6-digit code sent to your email</small>
                </div>
                
                <button type="submit" name="verify_otp" class="btn-primary" style="width: 100%;">
                    <i class="fas fa-check-circle"></i> Verify Email
                </button>
            </form>
            
            <div class="resend-link">
                <p style="color: #666; margin-bottom: 10px;">Didn't receive the code?</p>
                <form method="post" style="display: inline;">
                    <button type="submit" name="resend_otp" class="btn-secondary" style="background: transparent; color: #2E7D32; border: 1px solid #2E7D32;">
                        <i class="fas fa-redo"></i> Resend Code
                    </button>
                </form>
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <a href="signup.php" class="auth-link" style="font-size: 0.9rem;">Back to Sign Up</a>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const otpInput = document.getElementById('otp');
            
            // Only allow numbers
            otpInput.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 6);
            });
            
            // Auto-submit when 6 digits are entered (optional)
            otpInput.addEventListener('input', function() {
                if (this.value.length === 6) {
                    // Optional: auto-submit after a short delay
                    // setTimeout(() => document.getElementById('verifyForm').submit(), 500);
                }
            });
        });
    </script>
</body>
</html>

