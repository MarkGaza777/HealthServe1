<?php
// Start output buffering to catch any warnings/notices
ob_start();

session_start();
require 'db.php';

// Clear any output that might have been generated
ob_clean();

header('Content-Type: application/json');

// Check if user is logged in
if (empty($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Please log in']);
    exit;
}

$user_id = $_SESSION['user']['id'] ?? null;
$user_role = $_SESSION['user']['role'] ?? null;

if (!$user_id || !$user_role) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid session data']);
    exit;
}

try {
    // First, automatically mark announcements as expired if their end_date has passed
    // This ensures announcements expire automatically based on their Period end date
    $now = date('Y-m-d H:i:s');
    $expire_stmt = $pdo->prepare("
        UPDATE announcements 
        SET status = 'expired' 
        WHERE status != 'expired' 
        AND end_date IS NOT NULL 
        AND end_date <= ?
    ");
    $expire_stmt->execute([$now]);
    
    // Base query structure for all roles
    $base_query = "
        SELECT a.*, 
               u.username as posted_by_username,
               COALESCE(
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)),
                   u.username
               ) as posted_by_name,
               u.role as posted_by_role,
               COALESCE(
                   TRIM(CONCAT_WS(' ', fdo_u.first_name, fdo_u.middle_name, fdo_u.last_name)),
                   fdo_u.username,
                   ''
               ) as approved_by_name
        FROM announcements a
        LEFT JOIN users u ON a.posted_by = u.id
        LEFT JOIN users fdo_u ON a.fdo_approved_by = fdo_u.id
    ";
    
    if ($user_role === 'fdo') {
        // FDO can see all announcements for management purposes (including expired for review)
        $stmt = $pdo->prepare($base_query . " ORDER BY a.date_posted DESC");
        $stmt->execute();
        $all_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // For FDO: my_announcements = their own (excluding expired for display)
        $my_announcements = array_filter($all_announcements, function($a) use ($user_id) {
            return $a['posted_by'] == $user_id && strtolower($a['status'] ?? '') !== 'expired';
        });
        // For FDO: published = all approved (excluding expired)
        $published_announcements = array_filter($all_announcements, function($a) {
            return isset($a['status']) && strtolower($a['status']) === 'approved';
        });
        
        // For FDO: announcements array should include ALL announcements (for pending approvals section)
        // But filter out expired for the main display
        $all_announcements_array = array_filter($all_announcements, function($a) {
            return strtolower($a['status'] ?? '') !== 'expired';
        });
    } else {
        // For non-FDO users (pharmacist, admin, doctor):
        // My Announcements: ONLY announcements created by the current user (all statuses except expired)
        $my_stmt = $pdo->prepare($base_query . " WHERE a.posted_by = ? AND a.status != 'expired' ORDER BY a.date_posted DESC");
        $my_stmt->execute([$user_id]);
        $my_announcements = $my_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Published Announcements: ONLY approved announcements from OTHER users (exclude expired and current user's announcements to prevent duplicates)
        $published_stmt = $pdo->prepare($base_query . " WHERE a.status = 'approved' AND a.posted_by != ? ORDER BY a.date_posted DESC");
        $published_stmt->execute([$user_id]);
        $published_announcements = $published_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Ensure arrays are always arrays and re-index
    if (!is_array($my_announcements)) {
        $my_announcements = [];
    } else {
        $my_announcements = array_values($my_announcements);
    }
    
    if (!is_array($published_announcements)) {
        $published_announcements = [];
    } else {
        $published_announcements = array_values($published_announcements);
    }
    
    // Clear output buffer before sending JSON
    ob_clean();
    
    // For FDO: return all announcements in the 'announcements' array (includes pending from all users)
    // For other roles: return only their own + approved from others (deduplicated by announcement_id)
    if ($user_role === 'fdo') {
        $announcements_array = $all_announcements_array;
    } else {
        // Merge and deduplicate by announcement_id to prevent any duplicates
        $merged = array_merge($my_announcements, $published_announcements);
        $seen_ids = [];
        $announcements_array = [];
        foreach ($merged as $ann) {
            $id = $ann['announcement_id'] ?? null;
            if ($id && !isset($seen_ids[$id])) {
                $seen_ids[$id] = true;
                $announcements_array[] = $ann;
            }
        }
    }
    
    echo json_encode([
        'success' => true, 
        'my_announcements' => $my_announcements,
        'published_announcements' => $published_announcements,
        // For FDO: all announcements (including pending from doctors). For others: own + approved
        'announcements' => $announcements_array
    ], JSON_UNESCAPED_UNICODE);
    exit;
    
} catch (PDOException $e) {
    ob_clean();
    http_response_code(500);
    $error_msg = 'Database error: ' . $e->getMessage();
    error_log("Error in get_announcements.php: " . $error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    $error_msg = 'Error: ' . $e->getMessage();
    error_log("Error in get_announcements.php: " . $error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg], JSON_UNESCAPED_UNICODE);
    exit;
}
?>

