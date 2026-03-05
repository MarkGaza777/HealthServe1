<?php
session_start();
require 'db.php';
require_once 'admin_helpers_simple.php';

$err = '';
$success = '';
$maintenance_mode = isMaintenanceMode();
// Check if redirected from successful signup
if (isset($_GET['signup']) && $_GET['signup'] === 'success') {
    $success = 'Account created successfully! Please log in with your credentials.';
}
// Check if redirected from successful email verification
if (isset($_GET['verified']) && $_GET['verified'] === 'success') {
    $success = 'Email verified successfully! You can now log in with your credentials.';
}
// Helper: check if a column exists (for backward compatibility across DBs)
function hasColumn($pdo, $table, $column) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) { return false; }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    // Allow login by username OR email OR contact_no (if column exists)
    $contactExists = hasColumn($pdo, 'users', 'contact_no');
    $emailVerifiedExists = hasColumn($pdo, 'users', 'email_verified');
    
    // Build query to include email_verified if column exists
    $selectFields = 'id, username, password_hash, role, email';
    if ($emailVerifiedExists) {
        $selectFields .= ', email_verified';
    }
    
    if ($contactExists) {
        $stmt = $pdo->prepare("SELECT $selectFields FROM users WHERE username = ? OR email = ? OR contact_no = ? LIMIT 1");
        $stmt->execute([$u, $u, $u]);
    } else {
        $stmt = $pdo->prepare("SELECT $selectFields FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$u, $u]);
    }
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($p, $user['password_hash'])) {
        // Redirect staff members to staff login page
        if (in_array($user['role'], ['admin', 'doctor', 'pharmacist', 'fdo'])) {
            header('Location: staff_login.php');
            exit;
        }
        
        // Only allow patient logins on this page
        if ($user['role'] !== 'patient') {
            $err = 'Please use the staff login page';
        } else {
            // Check maintenance mode - redirect to maintenance page
            if (isMaintenanceMode()) {
                header('Location: maintenance_mode.php');
                exit;
            } else {
                // Check email verification for patient role only
                if ($emailVerifiedExists && isset($user['email_verified'])) {
                    if ($user['email_verified'] == 0) {
                        // Email not verified - redirect to verification page
                        $_SESSION['pending_verification_user_id'] = $user['id'];
                        $_SESSION['pending_verification_email'] = $user['email'];
                        header('Location: verify_email.php?unverified=1');
                        exit;
                    }
                }
                
                $_SESSION['user'] = ['id'=>$user['id'],'username'=>$user['username'],'role'=>$user['role']];
                
                // Log successful login
                logAuditEvent('User Login', 'authentication', $user['id'], "Patient logged in successfully");
                
                header('Location: user_main_dashboard.php');
                exit;
            }
        }
    } else {
        $err = 'Invalid credentials';
        // Log failed login attempt
        if (!empty($u)) {
            logAuditEvent('Failed Login Attempt', 'authentication', null, "Failed login attempt for username/email: " . htmlspecialchars($u));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to HealthServe - Payatas B</title>
    <link rel="stylesheet" href="assets/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-title">Log in</div>
            <div class="auth-subtitle">Welcome to<br>HealthServe - Payatas B</div>
            
            <?php if($maintenance_mode): ?>
                <div class="alert alert-warning" style="background: #fff3e0; color: #f57c00; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ff9800; text-align: left;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                        <i class="fas fa-tools" style="font-size: 20px;"></i>
                        <strong style="font-size: 16px;">System Under Maintenance</strong>
                    </div>
                    <p style="margin: 0; font-size: 14px; line-height: 1.5;">
                        We are currently performing scheduled maintenance to improve our services. 
                        The system will be back online shortly. We apologize for any inconvenience.
                    </p>
                </div>
            <?php endif; ?>
            
            <?php if($success): ?>
                <div class="alert alert-success" style="background: #E8F5E9; color: #2E7D32; padding: 12px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #66BB6A;">
                    <?=htmlspecialchars($success)?>
                </div>
            <?php endif; ?>
            
            <?php if($err): ?>
                <div class="alert alert-error"><?=htmlspecialchars($err)?></div>
            <?php endif; ?>
            
            <form method="post">
                <div class="form-group">
                    <label for="username">Email Address or Mobile Number</label>
                    <input type="text" id="username" name="username" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="password" name="password" class="form-input" required>
                        <button type="button" class="password-toggle" id="passwordToggle" aria-label="Show password" title="Show password">
                            <i class="fas fa-eye" id="passwordToggleIcon"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-primary">Log in</button>
                
                <div style="text-align: center; margin-top: 1rem;">
                    <span>New User? </span>
                    <a href="signup.php" class="auth-link">Sign Up Here</a>
                </div>
                
                <div style="text-align: center; margin-top: 0.5rem;">
                    <a href="forgot_password.php" class="auth-link" style="font-size: 0.9rem;">Forgot Password?</a>
                </div>
            </form>
        </div>
    </div>
    <script>
        (function() {
            var passwordInput = document.getElementById('password');
            var toggleBtn = document.getElementById('passwordToggle');
            var toggleIcon = document.getElementById('passwordToggleIcon');
            if (passwordInput && toggleBtn && toggleIcon) {
                toggleBtn.addEventListener('click', function() {
                    var isHidden = passwordInput.getAttribute('type') === 'password';
                    passwordInput.setAttribute('type', isHidden ? 'text' : 'password');
                    toggleIcon.classList.toggle('fa-eye', !isHidden);
                    toggleIcon.classList.toggle('fa-eye-slash', isHidden);
                    toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
                    toggleBtn.setAttribute('title', isHidden ? 'Hide password' : 'Show password');
                });
            }
        })();
    </script>
</body>
</html>