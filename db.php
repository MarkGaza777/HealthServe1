<?php
// db.php - update credentials if needed
$DB_HOST = '127.0.0.1';
$DB_PORT = '3306'; // Default MySQL port
$DB_NAME = 'health_center1';
$DB_USER = 'root';
$DB_PASS = '';

// Set the default timezone
date_default_timezone_set('Asia/Manila');

// Function to check if MySQL server is running
function checkMySQLServer($host, $port = 3306) {
    $connection = @fsockopen($host, $port, $errno, $errstr, 2);
    if ($connection) {
        fclose($connection);
        return true;
    }
    return false;
}

// Function to create/reconnect to database
function getPDOConnection() {
    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS;
    
    // First check if MySQL server is accessible
    if (!checkMySQLServer($DB_HOST, $DB_PORT)) {
        $error_msg = "MySQL server is not running or not accessible at {$DB_HOST}:{$DB_PORT}. ";
        $error_msg .= "Please ensure MySQL/MariaDB is running and try again.";
        throw new Exception($error_msg);
    }
    
    try {
        // Try connecting with port specified
        $dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_PERSISTENT => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'",
        ]);
        
        // Set additional connection settings
        $pdo->exec("SET time_zone = '+08:00'");
        $pdo->exec("SET SESSION wait_timeout = 28800"); // 8 hours
        $pdo->exec("SET SESSION interactive_timeout = 28800"); // 8 hours
        
        return $pdo;
    } catch (PDOException $e) {
        $error_code = $e->getCode();
        $error_message = $e->getMessage();
        
        // Provide helpful error messages based on error code
        if ($error_code == 2002 || strpos($error_message, '2002') !== false) {
            $help_msg = "MySQL server connection refused. Please check:\n";
            $help_msg .= "1. MySQL/MariaDB service is running\n";
            $help_msg .= "2. MySQL is listening on {$DB_HOST}:{$DB_PORT}\n";
            $help_msg .= "3. Firewall is not blocking the connection\n";
            $help_msg .= "4. MySQL credentials are correct\n\n";
            $help_msg .= "Original error: " . $error_message;
            throw new Exception($help_msg);
        } elseif ($error_code == 1045 || strpos($error_message, '1045') !== false) {
            $help_msg = "MySQL authentication failed. Please check:\n";
            $help_msg .= "1. Username: {$DB_USER}\n";
            $help_msg .= "2. Password is correct\n";
            $help_msg .= "3. User has proper permissions\n\n";
            $help_msg .= "Original error: " . $error_message;
            throw new Exception($help_msg);
        } elseif ($error_code == 1049 || strpos($error_message, '1049') !== false) {
            $help_msg = "Database '{$DB_NAME}' does not exist. Please:\n";
            $help_msg .= "1. Create the database\n";
            $help_msg .= "2. Import the database schema\n";
            $help_msg .= "3. Or update DB_NAME in db.php\n\n";
            $help_msg .= "Original error: " . $error_message;
            throw new Exception($help_msg);
        } else {
            throw new Exception("Database connection error: " . $error_message);
        }
    } catch (Exception $e) {
        error_log("Database connection error: " . $e->getMessage());
        throw $e;
    }
}

// Function to check if connection is alive and reconnect if needed
function ensureConnection($pdo) {
    try {
        // Try a simple query to check if connection is alive
        $pdo->query("SELECT 1");
        return $pdo;
    } catch (PDOException $e) {
        // Connection is dead, create a new one
        if (strpos($e->getMessage(), '2006') !== false || 
            strpos($e->getMessage(), 'gone away') !== false ||
            strpos($e->getMessage(), 'HY000') !== false ||
            strpos($e->getMessage(), '2002') !== false) {
            try {
                return getPDOConnection();
            } catch (Exception $e2) {
                error_log("Failed to reconnect: " . $e2->getMessage());
                throw $e2;
            }
        }
        throw $e;
    }
}

try {
    $pdo = getPDOConnection();
} catch (Exception $e) {
    // Display user-friendly error message
    $error_html = "<!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Database Connection Error</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
                background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 2rem;
                margin: 0;
            }
            .error-container {
                background: white;
                border-radius: 12px;
                padding: 2rem;
                max-width: 600px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            }
            h1 {
                color: #d32f2f;
                margin-top: 0;
            }
            .error-message {
                background: #ffebee;
                border-left: 4px solid #d32f2f;
                padding: 1rem;
                margin: 1rem 0;
                border-radius: 4px;
                white-space: pre-wrap;
                font-family: 'Courier New', monospace;
                font-size: 0.9rem;
            }
            .help-section {
                background: #fff3e0;
                border-left: 4px solid #ff9800;
                padding: 1rem;
                margin: 1rem 0;
                border-radius: 4px;
            }
            .help-section h3 {
                margin-top: 0;
                color: #f57c00;
            }
            ul {
                margin: 0.5rem 0;
                padding-left: 1.5rem;
            }
            li {
                margin: 0.5rem 0;
            }
        </style>
    </head>
    <body>
        <div class='error-container'>
            <h1>⚠️ Database Connection Failed</h1>
            <div class='error-message'>" . htmlspecialchars($e->getMessage()) . "</div>
            <div class='help-section'>
                <h3>Quick Fixes:</h3>
                <ul>
                    <li><strong>Start MySQL Service:</strong> Open Services (services.msc) and start MySQL/MariaDB service</li>
                    <li><strong>Check XAMPP/WAMP:</strong> If using XAMPP/WAMP, start MySQL from the control panel</li>
                    <li><strong>Check Port:</strong> Ensure MySQL is running on port 3306 (default)</li>
                    <li><strong>Check Configuration:</strong> Verify database credentials in db.php</li>
                </ul>
            </div>
        </div>
    </body>
    </html>";
    echo $error_html;
    exit;
}
