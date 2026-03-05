<?php
/**
 * Simplified Admin Settings Helper Functions
 */

require_once 'db.php';

/**
 * Ensure system_settings table exists, create if it doesn't
 */
function ensureSystemSettingsTable() {
    global $pdo;
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'system_settings'");
        if ($tableCheck->rowCount() == 0) {
            // Table doesn't exist, create it
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `system_settings` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `setting_key` varchar(100) NOT NULL,
                  `setting_value` text DEFAULT NULL,
                  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                  `updated_by` int(11) DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `setting_key` (`setting_key`),
                  KEY `idx_updated_by` (`updated_by`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Insert default values
            $defaults = [
                ['health_center_name', 'Barangay Payatas B Health Center'],
                ['health_center_address', 'Payatas B, Quezon City'],
                ['health_center_contact', '+63 2 8123 4567'],
                ['health_center_email', 'info@payatasbhealth.gov.ph'],
                ['health_center_operating_hours', 'Monday - Friday: 8:00 AM - 5:00 PM'],
                ['maintenance_mode', '0']
            ];
            
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
            foreach ($defaults as $default) {
                try {
                    $stmt->execute($default);
                } catch (PDOException $e) {
                    // Ignore duplicate key errors
                    if (strpos($e->getMessage(), 'Duplicate') === false) {
                        throw $e;
                    }
                }
            }
        }
        return true;
    } catch (PDOException $e) {
        error_log("Error ensuring system_settings table exists: " . $e->getMessage());
        return false;
    }
}

/**
 * Get system setting value
 */
function getSystemSetting($key, $default = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['setting_value'] : $default;
    } catch (PDOException $e) {
        error_log("Error getting system setting: " . $e->getMessage());
        return $default;
    }
}

/**
 * Set system setting value
 */
function setSystemSetting($key, $value, $updated_by = null) {
    global $pdo;
    try {
        // Ensure the table exists
        if (!ensureSystemSettingsTable()) {
            throw new Exception("Failed to create or verify system_settings table");
        }
        
        // Validate updated_by if provided (check if user exists)
        if ($updated_by !== null) {
            try {
                $userCheck = $pdo->prepare("SELECT id FROM users WHERE id = ?");
                $userCheck->execute([$updated_by]);
                if ($userCheck->rowCount() == 0) {
                    // User doesn't exist, set to null instead
                    $updated_by = null;
                }
            } catch (PDOException $e) {
                // If user check fails, just set to null
                $updated_by = null;
            }
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, updated_by) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
        ");
        $result = $stmt->execute([$key, $value, $updated_by]);
        
        if (!$result) {
            $errorInfo = $stmt->errorInfo();
            $errorMsg = $errorInfo[2] ?? 'Unknown database error';
            error_log("Error setting system setting '$key': " . $errorMsg);
            throw new Exception("Failed to save setting: $key - " . $errorMsg);
        }
        
        return true;
    } catch (PDOException $e) {
        $errorMsg = $e->getMessage();
        error_log("PDO Error setting system setting '$key': " . $errorMsg);
        
        // Provide more helpful error messages
        if (strpos($errorMsg, 'foreign key') !== false) {
            throw new Exception("Database constraint error: The user ID may not exist in the users table");
        } elseif (strpos($errorMsg, 'Duplicate entry') !== false) {
            // This shouldn't happen with ON DUPLICATE KEY UPDATE, but handle it
            throw new Exception("Setting '$key' already exists and could not be updated");
        } else {
            throw new Exception("Database error saving setting '$key': " . $errorMsg);
        }
    } catch (Exception $e) {
        error_log("Error setting system setting '$key': " . $e->getMessage());
        throw $e;
    }
}

/**
 * Check if maintenance mode is enabled
 */
function isMaintenanceMode() {
    return getSystemSetting('maintenance_mode', '0') === '1';
}

/**
 * Get health center information
 */
function getHealthCenterInfo() {
    return [
        'name' => getSystemSetting('health_center_name', 'Barangay Payatas B Health Center'),
        'address' => getSystemSetting('health_center_address', 'Payatas B, Quezon City'),
        'contact' => getSystemSetting('health_center_contact', '+63 2 8123 4567'),
        'email' => getSystemSetting('health_center_email', 'info@payatasbhealth.gov.ph'),
        'operating_hours' => getSystemSetting('health_center_operating_hours', 'Monday - Friday: 8:00 AM - 5:00 PM')
    ];
}

/**
 * Log audit event (immutable)
 * Records all system actions for accountability and monitoring
 */
function logAuditEvent($action, $entity_type = null, $entity_id = null, $details = null) {
    global $pdo;
    try {
        // Get user information from session
        $user_id = $_SESSION['user']['id'] ?? null;
        $user_role = $_SESSION['user']['role'] ?? null;
        $username = $_SESSION['user']['username'] ?? null;
        
        // Get user name if available
        $user_name = null;
        if ($user_id) {
            try {
                $stmt = $pdo->prepare("SELECT first_name, last_name, username FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $user_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                    if (empty($user_name)) {
                        $user_name = $user['username'] ?? 'Unknown';
                    }
                    if (empty($username)) {
                        $username = $user['username'] ?? null;
                    }
                }
            } catch (PDOException $e) {
                // If user lookup fails, continue with available data
                error_log("Error fetching user for audit log: " . $e->getMessage());
            }
        }
        
        // Get IP address (handle proxy/load balancer scenarios)
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded_ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip_address = trim($forwarded_ips[0]);
        }
        
        // Insert audit log (immutable - no updates/deletes allowed)
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, user_role, action, entity_type, entity_id, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $user_role, $action, $entity_type, $entity_id, $details, $ip_address]);
        return true;
    } catch (PDOException $e) {
        // Log error but don't throw exception to avoid disrupting normal operations
        error_log("Error logging audit event: " . $e->getMessage());
        return false;
    }
}

/**
 * Get audit logs with pagination and date filtering
 * @param int $limit Maximum number of logs to return
 * @param int $offset Number of logs to skip
 * @param string|null $date_from Start date (Y-m-d format) or null for no filter
 * @param string|null $date_to End date (Y-m-d format) or null for no filter
 * @param string|null $role_filter Filter by user role or null for all roles
 * @return array Array of audit log entries
 */
function getAuditLogs($limit = 100, $offset = 0, $date_from = null, $date_to = null, $role_filter = null) {
    global $pdo;
    try {
        // Build WHERE clause for date filtering
        $where_conditions = [];
        $params = [];
        
        if ($date_from) {
            $where_conditions[] = "DATE(al.created_at) >= ?";
            $params[] = $date_from;
        }
        
        if ($date_to) {
            $where_conditions[] = "DATE(al.created_at) <= ?";
            $params[] = $date_to;
        }
        
        if ($role_filter) {
            $where_conditions[] = "al.user_role = ?";
            $params[] = $role_filter;
        }
        
        $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
        
        // Build query with proper user name resolution
        $query = "
            SELECT 
                al.id,
                al.user_id,
                al.user_role,
                al.action,
                al.entity_type,
                al.entity_id,
                al.details,
                al.ip_address,
                al.created_at,
                u.username,
                u.first_name,
                u.last_name,
                CASE 
                    WHEN u.first_name IS NOT NULL AND u.last_name IS NOT NULL 
                    THEN CONCAT(u.first_name, ' ', u.last_name)
                    WHEN u.username IS NOT NULL 
                    THEN u.username
                    ELSE 'System'
                END AS user_name
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            {$where_clause}
            ORDER BY al.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting audit logs: " . $e->getMessage());
        return [];
    }
}

/**
 * Get count of audit logs matching filter criteria
 * @param string|null $date_from Start date (Y-m-d format) or null
 * @param string|null $date_to End date (Y-m-d format) or null
 * @param string|null $role_filter Filter by user role or null
 * @return int Total count of matching logs
 */
function getAuditLogsCount($date_from = null, $date_to = null, $role_filter = null) {
    global $pdo;
    try {
        $where_conditions = [];
        $params = [];
        
        if ($date_from) {
            $where_conditions[] = "DATE(created_at) >= ?";
            $params[] = $date_from;
        }
        
        if ($date_to) {
            $where_conditions[] = "DATE(created_at) <= ?";
            $params[] = $date_to;
        }
        
        if ($role_filter) {
            $where_conditions[] = "user_role = ?";
            $params[] = $role_filter;
        }
        
        $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM audit_logs {$where_clause}");
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['total'] ?? 0);
    } catch (PDOException $e) {
        error_log("Error getting audit logs count: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get backup list
 */
function getBackups($limit = 50) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT b.*, u.username, u.first_name, u.last_name
            FROM backups b
            LEFT JOIN users u ON b.created_by = u.id
            ORDER BY b.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting backups: " . $e->getMessage());
        return [];
    }
}

/**
 * Get system usage statistics
 * @param string|null $date_from Start date (Y-m-d format) or null
 * @param string|null $date_to End date (Y-m-d format) or null
 * @return array Usage statistics
 */
function getSystemUsageStats($date_from = null, $date_to = null) {
    global $pdo;
    try {
        // Get logins per role from audit_logs
        // Include both 'User Login' (patients) and 'Staff Login' (staff members)
        $login_stats = [];
        $login_conditions = ["(action = 'User Login' OR action = 'Staff Login')"];
        $login_params = [];
        
        if ($date_from) {
            $login_conditions[] = "DATE(created_at) >= ?";
            $login_params[] = $date_from;
        }
        if ($date_to) {
            $login_conditions[] = "DATE(created_at) <= ?";
            $login_params[] = $date_to;
        }
        
        // Count total login events (not distinct users) to get accurate login counts
        // Each login event represents one login session
        $login_query = "
            SELECT user_role, COUNT(*) as login_count
            FROM audit_logs
            WHERE " . implode(" AND ", $login_conditions) . "
            GROUP BY user_role
        ";
        
        $stmt = $pdo->prepare($login_query);
        $stmt->execute($login_params);
        $login_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($login_results as $row) {
            $role = strtolower(trim($row['user_role'] ?? 'unknown'));
            if (!empty($role) && $role !== 'null') {
                $login_stats[$role] = (int)$row['login_count'];
            }
        }
        
        // Ensure all roles are present with 0 if no logins
        $login_stats['admin'] = $login_stats['admin'] ?? 0;
        $login_stats['doctor'] = $login_stats['doctor'] ?? 0;
        $login_stats['fdo'] = $login_stats['fdo'] ?? 0;
        $login_stats['pharmacist'] = $login_stats['pharmacist'] ?? 0;
        $login_stats['patient'] = $login_stats['patient'] ?? 0;
        
        // Calculate Staff count (FDO + Pharmacist combined)
        $staff_count = $login_stats['fdo'] + $login_stats['pharmacist'];
        
        // Get total appointments created
        $appointments_conditions = [];
        $appointments_params = [];
        if ($date_from) {
            $appointments_conditions[] = "DATE(created_at) >= ?";
            $appointments_params[] = $date_from;
        }
        if ($date_to) {
            $appointments_conditions[] = "DATE(created_at) <= ?";
            $appointments_params[] = $date_to;
        }
        
        $appointments_query = "SELECT COUNT(*) as total FROM appointments";
        if (!empty($appointments_conditions)) {
            $appointments_query .= " WHERE " . implode(" AND ", $appointments_conditions);
        }
        $stmt = $pdo->prepare($appointments_query);
        $stmt->execute($appointments_params);
        $appointments_total = (int)$stmt->fetchColumn();
        
        // Get total announcements posted
        $announcements_conditions = [];
        $announcements_params = [];
        if ($date_from) {
            $announcements_conditions[] = "DATE(date_posted) >= ?";
            $announcements_params[] = $date_from;
        }
        if ($date_to) {
            $announcements_conditions[] = "DATE(date_posted) <= ?";
            $announcements_params[] = $date_to;
        }
        
        $announcements_query = "SELECT COUNT(*) as total FROM announcements";
        if (!empty($announcements_conditions)) {
            $announcements_query .= " WHERE " . implode(" AND ", $announcements_conditions);
        }
        $stmt = $pdo->prepare($announcements_query);
        $stmt->execute($announcements_params);
        $announcements_total = (int)$stmt->fetchColumn();
        
        // Get total notifications sent
        $notifications_conditions = [];
        $notifications_params = [];
        if ($date_from) {
            $notifications_conditions[] = "DATE(created_at) >= ?";
            $notifications_params[] = $date_from;
        }
        if ($date_to) {
            $notifications_conditions[] = "DATE(created_at) <= ?";
            $notifications_params[] = $date_to;
        }
        
        $notifications_query = "SELECT COUNT(*) as total FROM notifications";
        if (!empty($notifications_conditions)) {
            $notifications_query .= " WHERE " . implode(" AND ", $notifications_conditions);
        }
        $stmt = $pdo->prepare($notifications_query);
        $stmt->execute($notifications_params);
        $notifications_total = (int)$stmt->fetchColumn();
        
        // Calculate total logins (excluding patient logins for staff-focused report)
        $total_staff_logins = $login_stats['admin'] + $login_stats['doctor'] + $login_stats['fdo'] + $login_stats['pharmacist'];
        
        return [
            'logins' => [
                'admin' => $login_stats['admin'],
                'doctor' => $login_stats['doctor'],
                'fdo' => $login_stats['fdo'],
                'pharmacist' => $login_stats['pharmacist'],
                'staff' => $staff_count, // FDO + Pharmacist combined
                'total' => $total_staff_logins // Total of all staff/admin/doctor logins
            ],
            'appointments' => $appointments_total,
            'announcements' => $announcements_total,
            'notifications' => $notifications_total
        ];
    } catch (PDOException $e) {
        error_log("Error getting system usage stats: " . $e->getMessage());
        return [
            'logins' => ['admin' => 0, 'doctor' => 0, 'fdo' => 0, 'pharmacist' => 0, 'staff' => 0, 'total' => 0],
            'appointments' => 0,
            'announcements' => 0,
            'notifications' => 0
        ];
    }
}

