<?php
/**
 * Maintenance Mode Page
 * Displays when system is under maintenance for non-admin users
 */
require_once 'db.php';
require_once 'admin_helpers_simple.php';

// Check if maintenance mode is actually enabled
// If not, redirect to appropriate login page
if (!isMaintenanceMode()) {
    // Check if user is logged in
    session_start();
    if (isset($_SESSION['user'])) {
        $role = $_SESSION['user']['role'];
        if ($role === 'admin') {
            header('Location: Admin_dashboard1.php');
        } elseif ($role === 'doctor') {
            header('Location: doctors_page.php');
        } elseif ($role === 'pharmacist') {
            header('Location: pharmacist_dashboard.php');
        } elseif ($role === 'fdo') {
            header('Location: fdo_page.php');
        } elseif ($role === 'patient') {
            header('Location: user_main_dashboard.php');
        } else {
            header('Location: Login.php');
        }
    } else {
        header('Location: Login.php');
    }
    exit;
}

// Get health center info for display
$health_center = getHealthCenterInfo();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Maintenance - HealthServe</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            padding: 20px;
        }

        .maintenance-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            max-width: 600px;
            width: 100%;
            padding: 50px 40px;
            text-align: center;
        }

        .maintenance-icon {
            width: 120px;
            height: 120px;
            margin: 0 auto 30px;
            background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
            color: white;
            box-shadow: 0 8px 25px rgba(255, 152, 0, 0.3);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 8px 25px rgba(255, 152, 0, 0.3);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 12px 35px rgba(255, 152, 0, 0.4);
            }
        }

        .maintenance-title {
            font-size: 32px;
            font-weight: 700;
            color: #2E7D32;
            margin-bottom: 15px;
        }

        .maintenance-subtitle {
            font-size: 18px;
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .maintenance-message {
            background: #fff3e0;
            border-left: 4px solid #ff9800;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            text-align: left;
        }

        .maintenance-message h3 {
            color: #f57c00;
            margin-bottom: 10px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .maintenance-message p {
            color: #666;
            line-height: 1.6;
            margin: 0;
        }

        .health-center-info {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 2px solid #e0e0e0;
        }

        .health-center-info h4 {
            color: #2E7D32;
            margin-bottom: 15px;
            font-size: 20px;
        }

        .health-center-info p {
            color: #666;
            margin: 5px 0;
            font-size: 14px;
        }

        .refresh-btn {
            margin-top: 30px;
            padding: 12px 30px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .refresh-btn:hover {
            background: #45a049;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
        }

        .logo {
            margin-bottom: 20px;
        }

        .logo img {
            max-width: 150px;
            height: auto;
        }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <div class="logo">
            <img src="assets/payatas logo.png" alt="HealthServe Logo" onerror="this.style.display='none'">
        </div>
        
        <div class="maintenance-icon">
            <i class="fas fa-tools"></i>
        </div>

        <h1 class="maintenance-title">System Under Maintenance</h1>
        <p class="maintenance-subtitle"><?php echo htmlspecialchars($health_center['name']); ?></p>

        <div class="maintenance-message">
            <h3>
                <i class="fas fa-info-circle"></i>
                Maintenance in Progress
            </h3>
            <p>
                We are currently performing scheduled maintenance to improve our services. 
                The system will be back online shortly.
            </p>
            <p style="margin-top: 10px;">
                <strong>We apologize for any inconvenience.</strong> Please check back soon.
            </p>
        </div>

        <div class="health-center-info">
            <h4><i class="fas fa-hospital"></i> Contact Information</h4>
            <p><strong>Address:</strong> <?php echo htmlspecialchars($health_center['address']); ?></p>
            <p><strong>Contact:</strong> <?php echo htmlspecialchars($health_center['contact']); ?></p>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($health_center['email']); ?></p>
            <p><strong>Operating Hours:</strong> <?php echo htmlspecialchars($health_center['operating_hours']); ?></p>
        </div>

        <button class="refresh-btn" onclick="location.reload()">
            <i class="fas fa-sync-alt"></i> Refresh Page
        </button>
    </div>

    <script>
        // Auto-refresh every 30 seconds to check if maintenance is over
        setTimeout(function() {
            location.reload();
        }, 30000);
    </script>
</body>
</html>

