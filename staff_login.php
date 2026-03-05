<?php
session_start();
require 'db.php';
require_once 'admin_helpers_simple.php';

$err = '';
$success = '';
$maintenance_mode = isMaintenanceMode();

// Helper: check if a column exists (for backward compatibility across DBs)
function hasColumn($pdo, $table, $column) {
    try {
        // Ensure connection is alive before checking
        $pdo = ensureConnection($pdo);
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (PDOException $e) {
        // If connection error, try to reconnect once
        if (strpos($e->getMessage(), '2006') !== false || 
            strpos($e->getMessage(), 'gone away') !== false ||
            strpos($e->getMessage(), 'HY000') !== false) {
            try {
                $pdo = getPDOConnection();
                $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
                $stmt->execute([$column]);
                return (bool)$stmt->fetch();
            } catch (Exception $e2) {
                return false;
            }
        }
        return false;
    } catch (Exception $e) { 
        return false; 
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    
    try {
        // Ensure connection is alive before executing queries
        $pdo = ensureConnection($pdo);
        
        // Allow login by username OR email OR contact_no (if column exists)
        $contactExists = false;
        try {
            $pdo = ensureConnection($pdo);
            $contactExists = hasColumn($pdo, 'users', 'contact_no');
        } catch (Exception $e) {
            // If column check fails, assume contact_no doesn't exist
            $contactExists = false;
        }
        
        $selectFields = 'id, username, password_hash, role, email';
        
        // Ensure connection is alive before main query
        $pdo = ensureConnection($pdo);
        
        if ($contactExists) {
            $stmt = $pdo->prepare("SELECT $selectFields FROM users WHERE username = ? OR email = ? OR contact_no = ? LIMIT 1");
            $stmt->execute([$u, $u, $u]);
        } else {
            $stmt = $pdo->prepare("SELECT $selectFields FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$u, $u]);
        }
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Handle "MySQL server has gone away" error specifically
        if (strpos($e->getMessage(), '2006') !== false || strpos($e->getMessage(), 'gone away') !== false) {
            try {
                // Try to reconnect and retry the query once
                $pdo = getPDOConnection();
                
                $contactExists = false;
                try {
                    $contactExists = hasColumn($pdo, 'users', 'contact_no');
                } catch (Exception $e2) {
                    $contactExists = false;
                }
                
                $selectFields = 'id, username, password_hash, role, email';
                
                if ($contactExists) {
                    $stmt = $pdo->prepare("SELECT $selectFields FROM users WHERE username = ? OR email = ? OR contact_no = ? LIMIT 1");
                    $stmt->execute([$u, $u, $u]);
                } else {
                    $stmt = $pdo->prepare("SELECT $selectFields FROM users WHERE username = ? OR email = ? LIMIT 1");
                    $stmt->execute([$u, $u]);
                }
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e2) {
                error_log("Login retry failed: " . $e2->getMessage());
                $err = 'Database connection error. Please try again.';
                $user = null;
            }
        } else {
            error_log("Login error: " . $e->getMessage());
            $err = 'An error occurred during login. Please try again.';
            $user = null;
        }
    }
    
    if ($user && password_verify($p, $user['password_hash'])) {
        // Only allow staff roles (admin, doctor, pharmacist, fdo)
        if (!in_array($user['role'], ['admin', 'doctor', 'pharmacist', 'fdo'])) {
            $err = 'This login is for staff members only. Please use the patient login page.';
        } else {
            // Check maintenance mode (block non-admin during maintenance)
            if (isMaintenanceMode() && $user['role'] !== 'admin') {
                // Redirect non-admin staff to maintenance page
                header('Location: maintenance_mode.php');
                exit;
            } else {
                $_SESSION['user'] = ['id'=>$user['id'],'username'=>$user['username'],'role'=>$user['role']];
                
                // Log successful login
                logAuditEvent('Staff Login', 'authentication', $user['id'], ucfirst($user['role']) . " logged in successfully");
                
                // Redirect based on user role
                if ($user['role'] === 'admin') {
                    header('Location: Admin_dashboard1.php');
                } elseif ($user['role'] === 'pharmacist') {
                    header('Location: pharmacist_dashboard.php');
                } elseif ($user['role'] === 'doctor') {
                    header('Location: doctors_page.php');
                } elseif ($user['role'] === 'fdo') {
                    header('Location: fdo_page.php');
                } else {
                    header('Location: Admin_dashboard1.php');
                }
                exit;
            }
        }
    } else {
        $err = 'Invalid credentials';
        // Log failed login attempt
        if (!empty($u)) {
            logAuditEvent('Failed Login Attempt', 'authentication', null, "Failed staff login attempt for username/email: " . htmlspecialchars($u));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login - HealthServe - Payatas B</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #e8f5e8 0%, #c8e6c9 50%, #a5d6a7 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            color: #2e3b4e;
        }

        .staff-login-container {
            background: white;
            border-radius: 24px;
            padding: 3rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 420px;
            position: relative;
        }

        .logo-section {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo-section img {
            width: 80px;
            height: 80px;
            object-fit: contain;
            margin-bottom: 1rem;
        }

        .logo-section h1 {
            font-size: 1.5rem;
            color: #2e3b4e;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .logo-section p {
            font-size: 0.875rem;
            color: #666;
            font-weight: 400;
        }

        .login-title {
            font-size: 1.75rem;
            color: #2e3b4e;
            margin-bottom: 0.5rem;
            font-weight: 600;
            text-align: center;
        }

        .login-subtitle {
            color: #4caf50;
            font-size: 0.95rem;
            margin-bottom: 2rem;
            font-weight: 500;
            text-align: center;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #2e3b4e;
            font-weight: 500;
            font-size: 0.95rem;
        }

        .form-input {
            width: 100%;
            padding: 0.875rem 1.25rem;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .form-input:focus {
            outline: none;
            border-color: #4caf50;
            background: white;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        .btn-primary {
            width: 100%;
            background: #4caf50;
            color: white;
            border: none;
            padding: 0.875rem;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }

        .btn-primary:hover {
            background: #45a049;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(76, 175, 80, 0.3);
        }

        .alert {
            padding: 0.875rem 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .alert-error {
            background: #ffebee;
            color: #d32f2f;
            border: 1px solid #ffcdd2;
        }

        .alert-success {
            background: #E8F5E9;
            color: #2E7D32;
            border: 1px solid #66BB6A;
        }

        .auth-link {
            color: #4caf50;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .auth-link:hover {
            text-decoration: underline;
        }

        .link-section {
            text-align: center;
            margin-top: 1rem;
        }

        .link-section a {
            color: #4caf50;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .link-section a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .staff-login-container {
                padding: 2rem 1.5rem;
            }

            .logo-section img {
                width: 60px;
                height: 60px;
            }

            .login-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="staff-login-container">
        <div class="logo-section">
            <img src="assets/payatas logo.png" alt="Payatas B Logo">
            <h1>HealthServe</h1>
            <p>Payatas B Health Center</p>
        </div>

        <div class="login-title">Staff Login</div>
        <div class="login-subtitle">Access your staff account</div>
        
        <?php if($maintenance_mode): ?>
            <div class="alert alert-warning" style="background: #fff3e0; color: #f57c00; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ff9800; text-align: left;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                    <i class="fas fa-tools" style="font-size: 20px;"></i>
                    <strong style="font-size: 16px;">System Under Maintenance</strong>
                </div>
                <p style="margin: 0; font-size: 14px; line-height: 1.5;">
                    We are currently performing scheduled maintenance to improve our services. 
                    The system will be back online shortly. Admin users can still access the system.
                </p>
            </div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="alert alert-success">
                <?=htmlspecialchars($success)?>
            </div>
        <?php endif; ?>
        
        <?php if($err): ?>
            <div class="alert alert-error"><?=htmlspecialchars($err)?></div>
        <?php endif; ?>
        
        <form method="post">
            <div class="form-group">
                <label for="username">Username or Email</label>
                <input type="text" id="username" name="username" class="form-input" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-input" required>
            </div>
            
            <button type="submit" class="btn-primary">Log in</button>
            
            <div class="link-section">
                <a href="forgot_password.php">Forgot Password?</a>
            </div>
            
            <div class="link-section" style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e0e0e0;">
                <span style="color: #666; font-size: 0.875rem;">Patient? </span>
                <a href="Login.php">Login as Patient</a>
            </div>
        </form>
    </div>
</body>
</html>

