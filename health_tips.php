<?php
session_start();
require 'db.php';
if(empty($_SESSION['user'])) { 
    header('Location: login.php'); 
    exit; 
}

// Get user's photo_path for header avatar (only for patients)
$user_photo_path = null;
if (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'patient') {
    $user_id = $_SESSION['user']['id'];
    $stmt = $pdo->prepare('SELECT photo_path FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $user_photo_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $user_photo_path = $user_photo_data['photo_path'] ?? null;
}

// Original 4 hardcoded announcements (retained)
$health_programs = [
    [
        'id' => 'immunization',
        'title' => 'Children Immunization Program',
        'schedule' => 'Every Wednesday & Friday | 8 AM - 12 NN',
        'location' => 'At Barangay Health Center, 2nd Floor, Room 5',
        'type' => 'immunization',
        'button_text' => 'Learn More',
        'image' => 'assets/immunization.jpg',
        'content' => 'We will be conducting a Children\'s Immunization Program at the Barangay Payatas B Health Center. This program provides free vaccines for children ages 0–5 years old, including: BCG, DPT, Polio, and Measles. Parents and guardians are encouraged to bring their children\'s health cards for proper recording.',
        'category' => 'Program',
        'start_date' => '2025-10-01 08:00:00',
        'end_date' => null
    ],
    [
        'id' => 'prenatal',
        'title' => 'Prenatal Psychology Training',
        'schedule' => 'November 24, 2025 | 2 PM - 4 PM',
        'location' => 'At Barangay Payatas B. Covered Court beside Health Center',
        'type' => 'training',
        'button_text' => 'Learn More',
        'image' => 'assets/prenatal.jpg',
        'content' => 'Join us for a comprehensive prenatal psychology training session designed for expecting mothers and healthcare providers. Topics covered include mental health during pregnancy, stress management techniques, bonding with your unborn child, and postpartum preparation.',
        'category' => 'Training',
        'start_date' => '2025-11-24 14:00:00',
        'end_date' => '2025-11-24 16:00:00'
    ],
    [
        'id' => 'dengue',
        'title' => 'Anti-Dengue Fogging Drive',
        'schedule' => 'November 25-27, 2025 | 8 AM - 11 AM',
        'location' => 'Around Golden Shower St. to Mahogany St., Barangay Payatas B',
        'type' => 'community',
        'button_text' => 'Learn More',
        'image' => 'assets/dengue.jpg',
        'content' => 'The barangay health unit will conduct a comprehensive anti-dengue fogging operation to eliminate mosquito breeding sites and reduce dengue risk in our community. Please keep windows and doors closed during fogging operations and ensure pets are kept indoors for safety.',
        'category' => 'Program',
        'start_date' => '2025-11-25 08:00:00',
        'end_date' => '2025-11-27 11:00:00'
    ],
    [
        'id' => 'emergency',
        'title' => 'Emergency Ready: Check Your Kit!',
        'schedule' => 'Recommended for families with kids & seniors',
        'location' => 'Keep water, canned food, flashlight & basic meds ready anytime',
        'type' => 'tips',
        'button_text' => 'Learn More',
        'image' => 'assets/emergencybag.jpg',
        'content' => 'Prepare your emergency kit with essential supplies: water, canned food, flashlight, and basic medications. This is especially important for families with children and senior members. Keep your kit accessible and check it regularly to ensure all items are in good condition.',
        'category' => 'Health Tip',
        'start_date' => null,
        'end_date' => null
    ]
];

// Fetch approved announcements from database and add them below the original 4
try {
    // First, automatically mark announcements as expired if their end_date has passed
    $now = date('Y-m-d H:i:s');
    $expire_stmt = $pdo->prepare("
        UPDATE announcements 
        SET status = 'expired' 
        WHERE status != 'expired' 
        AND end_date IS NOT NULL 
        AND end_date <= ?
    ");
    $expire_stmt->execute([$now]);
    
    // Fetch only approved and non-expired announcements
    $stmt = $pdo->prepare("
        SELECT a.*, 
               u.username as posted_by_username,
               COALESCE(
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)),
                   u.username
               ) as posted_by_name
        FROM announcements a
        LEFT JOIN users u ON a.posted_by = u.id
        WHERE a.status = 'approved'
        ORDER BY a.date_posted DESC
    ");
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format announcements for display and add to array
    foreach ($announcements as $ann) {
        // Use schedule if available and not "Not Applicable", otherwise use date range
        $schedule = '';
        if ($ann['schedule'] && $ann['schedule'] !== 'Not Applicable') {
            $schedule = $ann['schedule'];
        } else {
            $startDate = $ann['start_date'] ? new DateTime($ann['start_date']) : null;
            $endDate = $ann['end_date'] ? new DateTime($ann['end_date']) : null;
            if ($startDate && $endDate) {
                if ($startDate->format('Y-m-d') === $endDate->format('Y-m-d')) {
                    $schedule = $startDate->format('F j, Y') . ' | ' . $startDate->format('g:i A') . ' - ' . $endDate->format('g:i A');
                } else {
                    $schedule = $startDate->format('F j, Y') . ' - ' . $endDate->format('F j, Y');
                }
            } else if ($startDate) {
                $schedule = $startDate->format('F j, Y') . ' | ' . $startDate->format('g:i A');
            } else {
                $schedule = 'Ongoing';
            }
        }
        
        // Use content as location/description, or extract first part
        $location = '';
        if ($ann['start_date']) {
            $startDate = new DateTime($ann['start_date']);
            $location = 'Starting ' . $startDate->format('F j, Y');
        } else {
            // If no location from dates, use first sentence of content
            $contentParts = explode('.', $ann['content']);
            $location = trim($contentParts[0]) . '.';
            if (strlen($location) > 100) {
                $location = substr($location, 0, 97) . '...';
            }
        }
        
        // Ensure image path is valid and exists
        $imagePath = $ann['image_path'] ?: 'assets/default-announcement.jpg';
        // Remove leading slash if present to ensure proper path
        $imagePath = ltrim($imagePath, '/');
        // Check if file exists, if not use default
        if ($imagePath !== 'assets/default-announcement.jpg' && !file_exists($imagePath)) {
            $imagePath = 'assets/default-announcement.jpg';
        }
        
        $health_programs[] = [
            'id' => 'db_' . $ann['announcement_id'],
            'title' => $ann['title'],
            'schedule' => $schedule,
            'location' => $location,
            'content' => $ann['content'],
            'category' => $ann['category'] ?: 'General',
            'image' => $imagePath,
            'start_date' => $ann['start_date'],
            'end_date' => $ann['end_date'],
            'date_posted' => $ann['date_posted'],
            'type' => 'database' // Mark as database announcement
        ];
    }
} catch (PDOException $e) {
    error_log("Error fetching announcements: " . $e->getMessage());
    // Continue with original 4 if database query fails
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - HealthServe Payatas B</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        /* Stable notification dropdown styles to prevent header shifts */
        .notification-container{position:relative}
        .notification-btn{background:#4CAF50;border:none;border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .3s ease;box-shadow:0 2px 8px rgba(76,175,80,.3);position:relative}
        .notification-btn:hover{background:#45a049;transform:translateY(-1px);box-shadow:0 4px 12px rgba(76,175,80,.4)}
        .notification-badge{position:absolute;top:-5px;right:-5px;background:#ff4444;color:#fff;border-radius:50%;width:20px;height:20px;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center}
        .notification-dropdown{position:absolute;top:50px;right:0;width:350px;background:#fff;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.15);opacity:0;visibility:hidden;transform:translateY(-10px);transition:opacity .2s ease, transform .2s ease, visibility .2s ease;z-index:1000;border:1px solid #e0e0e0}
        .notification-dropdown.active{opacity:1;visibility:visible;transform:translateY(0)}
        .notification-header{padding:16px 20px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center}
        .notification-title{font-size:16px;font-weight:600;color:#333}
        .clear-all{color:#4CAF50;font-size:14px;cursor:pointer;text-decoration:none}
        .notification-list{max-height:400px;overflow-y:auto}
        .notification-item{padding:16px 20px;border-bottom:1px solid #f8f8f8;cursor:pointer;transition:all .3s ease;display:flex;gap:12px;text-decoration:none;color:inherit}
        .notification-item:hover{background:#f8fdf8}
        .notification-item:last-child{border-bottom:none}
        .notification-icon-wrapper{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .notification-icon-wrapper.appointment{background:rgba(156,39,176,.1);color:#9C27B0}
        .notification-icon-wrapper.record_update{background:rgba(33,150,243,.1);color:#2196F3}
        .notification-icon-wrapper.prescription{background:rgba(33,150,243,.1);color:#2196F3}
        .notification-icon-wrapper.announcement{background:rgba(255,193,7,.1);color:#FFC107}
        .notification-content{flex:1}
        .notification-text{font-size:14px;color:#333;margin-bottom:4px;line-height:1.4}
        .notification-time{font-size:12px;color:#888}
        .notification-dot{width:8px;height:8px;background:#4CAF50;border-radius:50%;margin-left:auto;flex-shrink:0}
        @media (max-width:768px){.notification-dropdown{width:300px;right:0}}
        
        /* Center page title */
        .page-title {
            text-align: center;
        }
        
        /* Prevent image loading glitches */
        .program-image {
            position: relative;
            overflow: hidden;
            background-color: #e8f5e9;
            min-height: 200px;
            width: 100%;
        }
        
        .program-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: opacity 0.2s ease;
        }
        
        /* Ensure images don't cause layout shift */
        .program-image::before {
            content: '';
            display: block;
            padding-top: 56.25%; /* 16:9 aspect ratio */
        }
        
        .program-image img {
            position: absolute;
            top: 0;
            left: 0;
        }
        
        /* Fixed Modal Overlay - Perfectly Centered */
.announcement-modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.6);
    z-index: 9999;
    animation: fadeIn 0.3s ease;
    overflow-y: auto;
    padding: 20px;
    box-sizing: border-box;
}

.announcement-modal-overlay.active {
    display: flex !important;
    align-items: center;
    justify-content: center;
}

/* Modal Content - Responsive and Centered */
.announcement-modal-content {
    background-color: #ffffff;
    margin: auto;
    padding: 0;
    border-radius: 16px;
    width: 90%;
    max-width: 800px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
    animation: slideDown 0.3s ease;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative;
}

/* Modal Header with Image */
.announcement-modal-header {
    position: relative;
    width: 100%;
    height: 200px;
    overflow: hidden;
    border-radius: 16px 16px 0 0;
    flex-shrink: 0;
    background-color: #f5f5f5;
}

.announcement-modal-header img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

/* Close Button */
.announcement-modal-close {
    position: absolute;
    top: 15px;
    right: 15px;
    background: rgba(255, 255, 255, 0.95);
    border: none;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    font-size: 1.5rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    z-index: 10;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.announcement-modal-close:hover {
    background: white;
    transform: rotate(90deg);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

/* Modal Body */
.announcement-modal-body-content {
    padding: 2rem;
    overflow-y: auto;
    flex: 1;
    background-color: #ffffff;
}

.announcement-modal-body-content::-webkit-scrollbar {
    width: 8px;
}

.announcement-modal-body-content::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.announcement-modal-body-content::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 10px;
}

.announcement-modal-body-content::-webkit-scrollbar-thumb:hover {
    background: #555;
}

/* Modal Title and Content */
.announcement-modal-title {
    font-size: 1.75rem;
    font-weight: 600;
    color: #2e3b4e;
    margin-bottom: 0.5rem;
    line-height: 1.3;
}

.announcement-modal-subtitle {
    color: #4CAF50;
    font-size: 1rem;
    font-weight: 500;
    margin-bottom: 1rem;
}

.announcement-modal-section {
    margin-bottom: 1.5rem;
}

.announcement-modal-section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2e3b4e;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.announcement-modal-section ul {
    margin-left: 1.5rem;
    color: #444;
    line-height: 1.8;
}

.announcement-modal-section p {
    color: #444;
    line-height: 1.8;
    margin-bottom: 1rem;
}

.announcement-modal-section ul li {
    margin-bottom: 0.5rem;
}

/* Modal Footer */
.announcement-modal-footer {
    padding: 1.5rem 2rem;
    border-top: 1px solid #e0e0e0;
    display: flex;
    justify-content: flex-end;
    flex-shrink: 0;
    background-color: #ffffff;
}

/* Animations */
@keyframes fadeIn {
    from { 
        opacity: 0;
    }
    to { 
        opacity: 1;
    }
}

@keyframes slideDown {
    from { 
        transform: translateY(-50px) scale(0.95);
        opacity: 0;
    }
    to { 
        transform: translateY(0) scale(1);
        opacity: 1;
    }
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .announcement-modal-overlay {
        padding: 10px;
    }
    
    .announcement-modal-content {
        width: 95%;
        max-height: 95vh;
    }
    
    .announcement-modal-header {
        height: 150px;
    }
    
    .announcement-modal-body-content {
        padding: 1.5rem;
    }
    
    .announcement-modal-title {
        font-size: 1.5rem;
    }
    
    .announcement-modal-footer {
        padding: 1rem 1.5rem;
    }
}

@media (max-width: 480px) {
    .announcement-modal-content {
        width: 100%;
        border-radius: 12px;
    }
    
    .announcement-modal-header {
        height: 120px;
        border-radius: 12px 12px 0 0;
    }
    
    .announcement-modal-body-content {
        padding: 1rem;
    }
    
    .announcement-modal-title {
        font-size: 1.25rem;
    }
}
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-logo">
            <img src="assets/payatas logo.png" alt="Payatas B Logo">
            <h1>HealthServe - Payatas B</h1>
        </div>
        <nav class="header-nav">
            <a href="<?=$_SESSION['user']['role'] == 'admin' ? 'admin_dashboard.php' : 'user_main_dashboard.php'?>">Dashboard</a>
            <?php if($_SESSION['user']['role'] == 'patient'): ?>
            <a href="user_records.php">My Record</a>
            <a href="user_appointments.php">Appointments</a>
            <?php endif; ?>
            <a href="health_tips.php" class="active">Announcements</a>
        </nav>
        <div class="header-user">
            <div class="notification-container">
                <button class="notification-btn" id="notificationBtn">
                    <span class="notification-icon">🔔</span>
                    <span class="notification-badge" id="notificationBadge" style="display:none">0</span>
                </button>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <span class="notification-title">Notifications</span>
                        <a href="#" class="clear-all" id="clearAll">Clear all</a>
                    </div>
                    <div class="notification-filters" id="notificationFilters" style="display: flex; gap: 8px; padding: 8px 16px; border-bottom: 1px solid #f0f0f0;">
                        <button class="filter-btn active" data-filter="active" style="flex: 1; padding: 6px 12px; border: 1px solid #e0e0e0; background: #4CAF50; color: white; border-radius: 4px; cursor: pointer; font-size: 13px;">Active</button>
                        <button class="filter-btn" data-filter="archived" style="flex: 1; padding: 6px 12px; border: 1px solid #e0e0e0; background: white; color: #666; border-radius: 4px; cursor: pointer; font-size: 13px;">Archived</button>
                    </div>
                    <div class="notification-list" id="notificationList"></div>
                </div>
            </div>
            <a href="user_profile.php" title="My Profile" style="text-decoration:none">
                <div class="user-avatar" style="background:#2e7d32; overflow: hidden; position: relative;">
                    <?php if (!empty($user_photo_path) && file_exists($user_photo_path)): ?>
                        <img src="<?php echo htmlspecialchars($user_photo_path); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                    <?php else: ?>
                        👤
                    <?php endif; ?>
                </div>
            </a>
            <a href="logout.php" class="btn-logout">Log out</a>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <h1 class="page-title">📢 Announcements Section</h1>

        <!-- Health Programs Grid -->
        <div class="programs-grid">
            <?php if (empty($health_programs)): ?>
                <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #666;">
                    <p>No announcements available at this time.</p>
                </div>
            <?php else: ?>
                <?php foreach($health_programs as $program): ?>
                <div class="program-card" 
                     onclick="handleAnnouncementClick(this)"
                     <?php if (isset($program['type']) && $program['type'] !== 'database'): ?>
                         data-announcement-type="hardcoded"
                         data-type="<?=htmlspecialchars($program['type'])?>"
                         data-title="<?=htmlspecialchars(addslashes($program['title']))?>"
                         data-schedule="<?=htmlspecialchars(addslashes($program['schedule']))?>"
                         data-location="<?=htmlspecialchars(addslashes($program['location']))?>"
                         data-image="<?=htmlspecialchars($program['image'])?>"
                     <?php else: ?>
                         data-announcement-type="database"
                         data-id="<?=htmlspecialchars($program['id'])?>"
                         data-title="<?=htmlspecialchars(addslashes($program['title']))?>"
                         data-schedule="<?=htmlspecialchars(addslashes($program['schedule']))?>"
                         data-location="<?=htmlspecialchars(addslashes($program['location']))?>"
                         data-image="<?=htmlspecialchars($program['image'])?>"
                         data-content="<?=htmlspecialchars(addslashes($program['content']))?>"
                         data-category="<?=htmlspecialchars($program['category'])?>"
                         data-start-date="<?=$program['start_date'] ?? ''?>"
                         data-end-date="<?=$program['end_date'] ?? ''?>"
                     <?php endif; ?>>
                    <div class="program-image">
                        <?php if (isset($program['type']) && $program['type'] !== 'database'): ?>
                            <!-- Original hardcoded announcements -->
                            <?php if($program['type'] == 'immunization'): ?>
                                <img src="assets/immunization.jpg" alt="<?=htmlspecialchars($program['title'])?>" style="width: 100%; height: 100%; object-fit: cover; display: block;" onload="this.classList.add('loaded');" onerror="this.style.display='none'; this.parentElement.style.background='#e8f5e9';" loading="eager">
                            <?php elseif($program['type'] == 'training'): ?>
                                <img src="assets/prenatal.jpg" alt="<?=htmlspecialchars($program['title'])?>" style="width: 100%; height: 100%; object-fit: cover; display: block;" onload="this.classList.add('loaded');" onerror="this.style.display='none'; this.parentElement.style.background='#e8f5e9';" loading="eager">
                            <?php elseif($program['type'] == 'community'): ?>
                                <img src="assets/dengue.jpg" alt="<?=htmlspecialchars($program['title'])?>" style="width: 100%; height: 100%; object-fit: cover; display: block;" onload="this.classList.add('loaded');" onerror="this.style.display='none'; this.parentElement.style.background='#e8f5e9';" loading="eager">
                            <?php else: ?>
                                <img src="assets/emergencybag.jpg" alt="<?=htmlspecialchars($program['title'])?>" style="width: 100%; height: 100%; object-fit: cover; display: block;" onload="this.classList.add('loaded');" onerror="this.style.display='none'; this.parentElement.style.background='#e8f5e9';" loading="eager">
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- Database announcements -->
                            <img src="<?=htmlspecialchars($program['image'])?>" alt="<?=htmlspecialchars($program['title'])?>" style="width: 100%; height: 100%; object-fit: cover; display: block;" onload="this.classList.add('loaded'); this.onerror=null;" onerror="this.onerror=null; this.src='assets/default-announcement.jpg';" loading="lazy">
                        <?php endif; ?>
                    </div>
                    <div class="program-content">
                        <h3 class="program-title"><?=htmlspecialchars($program['title'])?></h3>
                        <p class="program-schedule"><?=htmlspecialchars($program['schedule'])?></p>
                        <p class="program-description"><?=htmlspecialchars($program['location'])?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Announcement Modal Overlay -->
        <div id="announcementModal" class="announcement-modal-overlay" onclick="if(event.target === this) closeAnnouncementModal()">
            <div class="announcement-modal-content" onclick="event.stopPropagation()">
                <div class="announcement-modal-header">
                    <img id="modalHeaderImage" src="" alt="Announcement Image">
                    <button class="announcement-modal-close" onclick="closeAnnouncementModal()">&times;</button>
                </div>
                <div class="announcement-modal-body-content" id="modalBodyContent">
                    <!-- Content will be dynamically inserted here -->
                </div>
                <div class="announcement-modal-footer">
                    <button class="btn-program" onclick="closeAnnouncementModal()" style="background: #666;">Close</button>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Real-time Notification System connected to database
        (function(){
            class NotificationSystem{
                constructor(){
                    this.notifications = [];
                    this.pollInterval = null;
                    this.currentFilter = 'active';
                    this.init();
                }
                
                async init(){
                    await this.fetchNotifications();
                    this.bindEvents();
                    this.startPolling();
                    // Check for appointment reminders on load (only once per session)
                    if (!sessionStorage.getItem('remindersChecked')) {
                        this.checkAppointmentReminders();
                        sessionStorage.setItem('remindersChecked', 'true');
                    }
                }
                
                async fetchNotifications(filter = 'active'){
                    try {
                        this.currentFilter = filter;
                        const response = await fetch(`get_patient_notifications.php?action=fetch&filter=${filter}`);
                        const data = await response.json();
                        if(data.success){
                            this.notifications = data.notifications.map(n => ({
                                id: n.id,
                                type: n.type,
                                text: n.message,
                                time: n.time_ago,
                                read: n.read,
                                reference_id: n.reference_id || null,
                                link: this.getLinkForType(n.type)
                            }));
                            this.renderNotifications();
                            if(filter === 'active'){
                                this.updateBadge();
                            }
                        }
                    } catch(e){
                        console.error('Error fetching notifications:', e);
                    }
                }
                
                getLinkForType(type){
                    const links = {
                        'appointment': 'user_appointments.php',
                        'announcement': 'health_tips.php',
                        'record_update': 'user_records.php',
                        'prescription': 'user_records.php'
                    };
                    return links[type] || '#';
                }
                
                getIconForType(type){
                    const icons = {
                        'appointment': '📅',
                        'announcement': '📢',
                        'record_update': '💊',
                        'prescription': '💊'
                    };
                    return icons[type] || '🔔';
                }
                
                bindEvents(){
                    const nBtn = document.getElementById('notificationBtn');
                    const nDrop = document.getElementById('notificationDropdown');
                    const clear = document.getElementById('clearAll');
                    const filters = document.querySelectorAll('.filter-btn');
                    if(!nBtn || !nDrop || !clear) return;
                    nBtn.addEventListener('click', e => { e.stopPropagation(); this.toggleDropdown(); });
                    clear.addEventListener('click', e => { 
                        e.preventDefault(); 
                        if(this.currentFilter === 'active'){
                            this.clearAllNotifications();
                        }
                    });
                    // Filter button events
                    filters.forEach(btn => {
                        btn.addEventListener('click', e => {
                            e.stopPropagation();
                            const filter = btn.getAttribute('data-filter');
                            filters.forEach(b => {
                                b.classList.remove('active');
                                b.style.background = 'white';
                                b.style.color = '#666';
                            });
                            btn.classList.add('active');
                            btn.style.background = '#4CAF50';
                            btn.style.color = 'white';
                            this.fetchNotifications(filter);
                        });
                    });
                    document.addEventListener('click', e => { if(!nDrop.contains(e.target) && !nBtn.contains(e.target)){ this.closeDropdown(); }});
                    document.addEventListener('keydown', e => { if(e.key === 'Escape'){ this.closeDropdown(); }});
                }
                renderNotifications(){
                    const list = document.getElementById('notificationList');
                    if(!list) return; list.innerHTML = '';
                    if(this.notifications.length === 0){
                        list.innerHTML = '<div style="padding: 2rem; text-align: center; color: #888;">No notifications</div>';
                        return;
                    }
                    this.notifications.forEach(n => list.appendChild(this.createNotificationElement(n)));
                }
                createNotificationElement(n){
                    const item = document.createElement('div');
                    item.className = `notification-item ${n.read ? 'read' : ''}`;
                    item.setAttribute('data-id', n.id);
                    item.style.cssText = 'display: flex; align-items: center; gap: 8px; padding: 12px 16px; border-bottom: 1px solid #f8f8f8; position: relative;';
                    
                    const isArchived = this.currentFilter === 'archived';
                    
                    // Get background color for icon based on type
                    let iconBgColor = 'rgba(76, 175, 80, 0.1)';
                    let iconTextColor = '#4CAF50';
                    if (n.type === 'appointment') {
                        iconBgColor = 'rgba(156, 39, 176, 0.1)';
                        iconTextColor = '#9C27B0';
                    } else if (n.type === 'record_update' || n.type === 'prescription') {
                        iconBgColor = 'rgba(33, 150, 243, 0.1)';
                        iconTextColor = '#2196F3';
                    } else if (n.type === 'announcement') {
                        iconBgColor = 'rgba(255, 193, 7, 0.1)';
                        iconTextColor = '#FFC107';
                    }
                    
                    item.innerHTML = `
                        <a href="#" class="notification-link" style="flex: 1; display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit;">
                            <div class="notification-icon-wrapper ${n.type}" style="width: 28px; height: 28px; border-radius: 4px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 16px; background: ${iconBgColor}; color: ${iconTextColor};">
                                <span>${this.getIconForType(n.type)}</span>
                            </div>
                            <div class="notification-content" style="flex: 1; min-width: 0;">
                                <div class="notification-text" style="font-size: 14px; color: #333; margin-bottom: 2px; line-height: 1.4;">${n.text}</div>
                                <div class="notification-time" style="font-size: 12px; color: #888;">${n.time}</div>
                            </div>
                            ${!n.read && !isArchived ? '<div class="notification-dot" style="width: 6px; height: 6px; background: #4CAF50; border-radius: 50%; flex-shrink: 0;"></div>' : ''}
                        </a>
                        <div class="notification-actions" style="display: flex; gap: 3px; align-items: center; flex-shrink: 0;">
                            ${isArchived ? 
                                `<button class="action-btn restore-btn" title="Restore" style="padding: 2px; border: 1px solid #4CAF50; background: white; color: #4CAF50; border-radius: 2px; cursor: pointer; font-size: 12px; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; min-width: 20px;">↩</button>` :
                                `<button class="action-btn archive-btn" title="Archive" style="padding: 2px; border: 1px solid #ff9800; background: white; color: #ff9800; border-radius: 2px; cursor: pointer; font-size: 12px; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; min-width: 20px;">📦</button>`
                            }
                            <button class="action-btn delete-btn" title="Delete" style="padding: 2px; border: 1px solid #f44336; background: white; color: #f44336; border-radius: 2px; cursor: pointer; font-size: 12px; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; min-width: 20px;">🗑</button>
                        </div>
                    `;
                    
                    // Link click handler
                    const link = item.querySelector('.notification-link');
                    link.addEventListener('click', e => {
                        e.preventDefault();
                        e.stopPropagation();
                        this.handleNotificationClick(n.id, n.link);
                    });
                    
                    // Archive button
                    const archiveBtn = item.querySelector('.archive-btn');
                    if(archiveBtn){
                        archiveBtn.addEventListener('click', e => {
                            e.preventDefault();
                            e.stopPropagation();
                            this.archiveNotification(n.id);
                        });
                    }
                    
                    // Restore button
                    const restoreBtn = item.querySelector('.restore-btn');
                    if(restoreBtn){
                        restoreBtn.addEventListener('click', e => {
                            e.preventDefault();
                            e.stopPropagation();
                            this.restoreNotification(n.id);
                        });
                    }
                    
                    // Delete button
                    const deleteBtn = item.querySelector('.delete-btn');
                    deleteBtn.addEventListener('click', e => {
                        e.preventDefault();
                        e.stopPropagation();
                        this.confirmDelete(n.id, n.text);
                    });
                    
                    return item;
                }
                async handleNotificationClick(id, link){
                    const n = this.notifications.find(x => x.id === id);
                    if(n && !n.read){
                        try {
                            const formData = new FormData();
                            formData.append('notification_id', id);
                            await fetch('get_patient_notifications.php?action=mark_read', {
                                method: 'POST',
                                body: formData
                            });
                            n.read = true;
                            this.renderNotifications();
                            this.updateBadge();
                        } catch(e){
                            console.error('Error marking notification as read:', e);
                        }
                    }
                    
                    // Handle announcement clicks - open announcement modal
                    if(n && n.type === 'announcement' && n.reference_id){
                        this.openAnnouncementModal(n.reference_id);
                        this.closeDropdown();
                        return;
                    }
                    
                    if(link && link !== '#'){
                        window.location.href = link;
                    }
                    this.closeDropdown();
                }
                
                async openAnnouncementModal(announcementId){
                    // Since we're already on health_tips.php, just scroll to and open the announcement
                    try {
                        const response = await fetch('get_announcements.php');
                        const data = await response.json();
                        if(data.success){
                            const announcement = data.announcements.find(a => a.announcement_id == announcementId);
                            if(announcement && typeof showAnnouncementModalFromDB === 'function'){
                                showAnnouncementModalFromDB(
                                    announcement.announcement_id,
                                    announcement.title,
                                    announcement.schedule || '',
                                    announcement.location || '',
                                    announcement.image_path || 'assets/default-announcement.jpg',
                                    announcement.content,
                                    announcement.category || 'General',
                                    announcement.start_date || null,
                                    announcement.end_date || null
                                );
                            }
                        }
                    } catch(e){
                        console.error('Error loading announcement:', e);
                    }
                }
                toggleDropdown(){ const d = document.getElementById('notificationDropdown'); if(!d) return; d.classList.toggle('active'); }
                closeDropdown(){ const d = document.getElementById('notificationDropdown'); if(!d) return; d.classList.remove('active'); }
                updateBadge(){ const b = document.getElementById('notificationBadge'); if(!b) return; const unread = this.notifications.filter(n => !n.read).length; b.style.display = unread>0?'flex':'none'; b.textContent = unread; }
                async archiveNotification(id){
                    try {
                        const formData = new FormData();
                        formData.append('notification_id', id);
                        const response = await fetch('get_patient_notifications.php?action=archive', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await response.json();
                        if(data.success){
                            await this.fetchNotifications(this.currentFilter);
                            this.updateBadge();
                        } else {
                            alert('Error: ' + (data.message || 'Failed to archive notification'));
                        }
                    } catch(e){
                        console.error('Error archiving notification:', e);
                        alert('Error archiving notification');
                    }
                }
                async restoreNotification(id){
                    try {
                        const formData = new FormData();
                        formData.append('notification_id', id);
                        const response = await fetch('get_patient_notifications.php?action=restore', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await response.json();
                        if(data.success){
                            await this.fetchNotifications(this.currentFilter);
                            this.updateBadge();
                        } else {
                            alert('Error: ' + (data.message || 'Failed to restore notification'));
                        }
                    } catch(e){
                        console.error('Error restoring notification:', e);
                        alert('Error restoring notification');
                    }
                }
                confirmDelete(id, text){
                    const message = `Are you sure you want to permanently delete this notification?\n\n"${text.substring(0, 50)}${text.length > 50 ? '...' : ''}"\n\nThis action cannot be undone.`;
                    if(confirm(message)){
                        this.deleteNotification(id);
                    }
                }
                async deleteNotification(id){
                    try {
                        const formData = new FormData();
                        formData.append('notification_id', id);
                        const response = await fetch('get_patient_notifications.php?action=delete', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await response.json();
                        if(data.success){
                            await this.fetchNotifications(this.currentFilter);
                            this.updateBadge();
                        } else {
                            alert('Error: ' + (data.message || 'Failed to delete notification'));
                        }
                    } catch(e){
                        console.error('Error deleting notification:', e);
                        alert('Error deleting notification');
                    }
                }
                async clearAllNotifications(){
                    try {
                        await fetch('get_patient_notifications.php?action=mark_all_read', {
                            method: 'POST'
                        });
                        this.notifications.forEach(n => n.read = true);
                        this.renderNotifications();
                        this.updateBadge();
                        this.closeDropdown();
                    } catch(e){
                        console.error('Error clearing notifications:', e);
                    }
                }
                startPolling(){
                    // Poll for new notifications every 10 seconds for real-time updates
                    this.pollInterval = setInterval(() => {
                        this.fetchNotifications();
                    }, 10000);
                }
                async checkAppointmentReminders(){
                    try {
                        await fetch('check_appointment_reminders.php');
                    } catch(e){
                        console.error('Error checking appointment reminders:', e);
                    }
                }
            }
            document.addEventListener('DOMContentLoaded', function(){ 
                window.notificationSystem = new NotificationSystem();
                
                // Check if there's an announcement parameter in URL and open it
                const urlParams = new URLSearchParams(window.location.search);
                const announcementId = urlParams.get('announcement');
                if(announcementId){
                    // Wait a bit for page to load, then open the announcement
                    setTimeout(() => {
                        if(typeof showAnnouncementModalFromDB === 'function'){
                            fetch('get_announcements.php')
                                .then(response => response.json())
                                .then(data => {
                                    if(data.success){
                                        const announcement = data.announcements.find(a => a.announcement_id == announcementId);
                                        if(announcement){
                                            showAnnouncementModalFromDB(
                                                announcement.announcement_id,
                                                announcement.title,
                                                announcement.schedule || '',
                                                announcement.location || '',
                                                announcement.image_path || 'assets/default-announcement.jpg',
                                                announcement.content,
                                                announcement.category || 'General',
                                                announcement.start_date || null,
                                                announcement.end_date || null
                                            );
                                            // Remove the parameter from URL
                                            window.history.replaceState({}, document.title, window.location.pathname);
                                        }
                                    }
                                })
                                .catch(e => console.error('Error loading announcement:', e));
                        }
                    }, 500);
                }
            });
        })();

        function showAnnouncementModalFromDB(id, title, schedule, location, image, content, category, startDate, endDate) {
            const modal = document.getElementById('announcementModal');
            const modalImage = document.getElementById('modalHeaderImage');
            const modalBody = document.getElementById('modalBodyContent');
            
            // Set the header image
            modalImage.src = image;
            modalImage.alt = title;
            modalImage.onerror = function() {
                this.src = 'assets/default-announcement.jpg';
            };
            
            // Format dates
            let dateInfo = '';
            if (startDate && endDate) {
                const start = new Date(startDate);
                const end = new Date(endDate);
                if (start.toDateString() === end.toDateString()) {
                    dateInfo = `<p style="color: #666; margin-bottom: 1.5rem;"><strong>Date:</strong> ${start.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })} | ${start.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })} - ${end.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</p>`;
                } else {
                    dateInfo = `<p style="color: #666; margin-bottom: 1.5rem;"><strong>Period:</strong> ${start.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })} ${start.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })} - ${end.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })} ${end.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</p>`;
                }
            } else if (startDate) {
                const start = new Date(startDate);
                dateInfo = `<p style="color: #666; margin-bottom: 1.5rem;"><strong>Starts on:</strong> ${start.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })} | ${start.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</p>`;
            } else if (endDate) {
                const end = new Date(endDate);
                dateInfo = `<p style="color: #666; margin-bottom: 1.5rem;"><strong>Ends on:</strong> ${end.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })} | ${end.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</p>`;
            }
            
            // Format content with line breaks and paragraphs
            let formattedContent = content;
            // Convert double line breaks to paragraphs
            formattedContent = formattedContent.split(/\n\n+/).map(para => {
                if (para.trim()) {
                    return '<p>' + para.trim().replace(/\n/g, '<br>') + '</p>';
                }
                return '';
            }).join('');
            
            // If no paragraphs created, wrap entire content
            if (!formattedContent.includes('<p>')) {
                formattedContent = '<p>' + content.replace(/\n/g, '<br>') + '</p>';
            }
            
            // Generate content from database
            let htmlContent = `
                <h2 class="announcement-modal-title">${title}</h2>
                <p class="announcement-modal-subtitle">${schedule}</p>
                ${location ? `<p style="color: #666; margin-bottom: 1.5rem;"><strong>Location/Details:</strong> ${location}</p>` : ''}
                ${dateInfo}
                <div class="announcement-modal-section">
                    <div class="announcement-modal-section-title">📋 About This ${category || 'Announcement'}</div>
                    ${formattedContent}
                </div>
            `;
            
            modalBody.innerHTML = htmlContent;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function showAnnouncementModal(type, title, schedule, location, image) {
            const modal = document.getElementById('announcementModal');
            const modalImage = document.getElementById('modalHeaderImage');
            const modalBody = document.getElementById('modalBodyContent');
            
            // Set the header image
            modalImage.src = image;
            modalImage.alt = title;
            
            // Generate content based on type
            let content = '';
            
            switch(type) {
                case 'immunization':
                    content = `
                        <h2 class="announcement-modal-title">${title}</h2>
                        <p class="announcement-modal-subtitle">${schedule}</p>
                        <p style="color: #666; margin-bottom: 1.5rem;"><strong>Location:</strong> ${location}</p>
                        <p style="color: #666; margin-bottom: 1.5rem;"><strong>Starts on:</strong> October 1, 2025 (Wednesday)</p>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">📋 About This Program</div>
                            <p>We will be conducting a Children's Immunization Program at the Barangay Payatas B Health Center. This program provides free vaccines for children ages 0–5 years old, including:</p>
                            <ul>
                                <li>BCG (Bacillus Calmette-Guérin)</li>
                                <li>DPT (Diphtheria, Pertussis, Tetanus)</li>
                                <li>Polio</li>
                                <li>Measles</li>
                            </ul>
                        </div>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">📋 Requirements</div>
                            <ul>
                                <li>Child's birth certificate or health card</li>
                                <li>Barangay ID (if needed)</li>
                                <li>Parent/guardian government ID</li>
                            </ul>
                        </div>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">⚠️ Health & Safety Reminders</div>
                            <ul>
                                <li>Make sure child is not sick during vaccination day</li>
                                <li>Feed your child before going</li>
                                <li>Arrive 30 minutes before time to avoid long lines</li>
                            </ul>
                        </div>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">📞 Contact Information</div>
                            <p><strong>Barangay Health Center Hotline:</strong> 09690394762</p>
                            <p><strong>Contact Person:</strong> Nurse Michelle</p>
                            <p>Parents and guardians are encouraged to bring their children's health cards for proper recording. Let's work together to keep our children healthy and protected.</p>
                        </div>
                    `;
                    break;
                    
                case 'training':
                    content = `
                        <h2 class="announcement-modal-title">${title}</h2>
                        <p class="announcement-modal-subtitle">${schedule}</p>
                        <p style="color: #666; margin-bottom: 1.5rem;"><strong>Location:</strong> ${location}</p>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">📚 About This Training</div>
                            <p>Join us for a comprehensive prenatal psychology training session designed for expecting mothers and healthcare providers. This training is free and open to all pregnant women and their support systems in the community.</p>
                        </div>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">📋 Topics Covered</div>
                            <ul>
                                <li>Mental health during pregnancy</li>
                                <li>Stress management techniques</li>
                                <li>Bonding with your unborn child</li>
                                <li>Postpartum preparation</li>
                                <li>Emotional wellness strategies</li>
                                <li>Support system building</li>
                            </ul>
                        </div>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">👥 Who Should Attend</div>
                            <ul>
                                <li>Expecting mothers (all trimesters)</li>
                                <li>Partners and family members</li>
                                <li>Healthcare providers</li>
                                <li>Community health workers</li>
                            </ul>
                        </div>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">📞 Contact Information</div>
                            <p><strong>Barangay Health Center Hotline:</strong> 09690394762</p>
                            <p>For registration and inquiries, please contact the health center or visit in person.</p>
                        </div>
                    `;
                    break;
                    
                case 'community':
                    content = `
                        <h2 class="announcement-modal-title">${title}</h2>
                        <p class="announcement-modal-subtitle">${schedule}</p>
                        <p style="color: #666; margin-bottom: 1.5rem;"><strong>Location:</strong> ${location}</p>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">🦟 About This Drive</div>
                            <p>The barangay health unit will conduct a comprehensive anti-dengue fogging operation to eliminate mosquito breeding sites and reduce dengue risk in our community. This is a preventive measure to protect all residents from dengue fever.</p>
                        </div>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">📍 Areas to be Covered</div>
                            <ul>
                                <li>All residential areas along Golden Shower St. to Mahogany St.</li>
                                <li>Public spaces and parks</li>
                                <li>School premises</li>
                                <li>Market areas</li>
                                <li>Community centers</li>
                            </ul>
                        </div>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">⚠️ Important Safety Reminders</div>
                            <ul>
                                <li>Keep windows and doors closed during fogging operations</li>
                                <li>Ensure pets are kept indoors for safety</li>
                                <li>Cover food and water containers</li>
                                <li>Stay indoors during fogging (approximately 30 minutes)</li>
                                <li>Wash exposed surfaces after fogging</li>
                            </ul>
                        </div>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">🌱 Additional Prevention Tips</div>
                            <ul>
                                <li>Remove standing water from containers</li>
                                <li>Clean gutters and drainage regularly</li>
                                <li>Use mosquito nets and repellents</li>
                                <li>Wear long sleeves during peak mosquito hours</li>
                            </ul>
                        </div>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">📞 Contact Information</div>
                            <p><strong>Barangay Health Center Hotline:</strong> 09690394762</p>
                            <p>For questions or concerns about the fogging schedule, please contact the health center.</p>
                        </div>
                    `;
                    break;
                    
                case 'tips':
                    content = `
                        <h2 class="announcement-modal-title">${title}</h2>
                        <p class="announcement-modal-subtitle">${schedule}</p>
                        <p style="color: #666; margin-bottom: 1.5rem;">${location}</p>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">🆘 Why Emergency Preparedness Matters</div>
                            <p>Being prepared for emergencies can save lives. Having a well-stocked emergency kit ensures that you and your family can stay safe and comfortable during unexpected situations such as natural disasters, power outages, or medical emergencies.</p>
                        </div>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">📦 Essential Emergency Kit Items</div>
                            <ul>
                                <li><strong>Water:</strong> At least 1 gallon per person per day (3-day supply minimum)</li>
                                <li><strong>Food:</strong> Non-perishable canned food, energy bars, dried fruits</li>
                                <li><strong>Flashlight:</strong> With extra batteries</li>
                                <li><strong>First Aid Kit:</strong> Bandages, antiseptic, pain relievers, prescription medications</li>
                                <li><strong>Basic Medications:</strong> Pain relievers, antacids, anti-diarrheal medicine</li>
                                <li><strong>Personal Documents:</strong> IDs, insurance cards, medical records (in waterproof container)</li>
                                <li><strong>Cash:</strong> Small bills and coins</li>
                                <li><strong>Multi-tool:</strong> Swiss army knife or similar</li>
                                <li><strong>Whistle:</strong> To signal for help</li>
                                <li><strong>Dust masks:</strong> For air quality protection</li>
                            </ul>
                        </div>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">👨‍👩‍👧‍👦 Special Considerations</div>
                            <ul>
                                <li><strong>For Families with Kids:</strong> Baby formula, diapers, wipes, comfort items, games</li>
                                <li><strong>For Seniors:</strong> Extra medications, hearing aid batteries, glasses, mobility aids</li>
                                <li><strong>For Pets:</strong> Pet food, water, medications, leash, carrier</li>
                            </ul>
                        </div>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">✅ Maintenance Checklist</div>
                            <ul>
                                <li>Check expiration dates every 6 months</li>
                                <li>Replace water supply every 6 months</li>
                                <li>Update medications as needed</li>
                                <li>Review and update emergency contacts</li>
                                <li>Test flashlights and batteries regularly</li>
                            </ul>
                        </div>
                        
                        <div class="announcement-modal-section">
                            <div class="announcement-modal-section-title">📞 Emergency Contacts</div>
                            <ul>
                                <li><strong>Barangay Health Center:</strong> 09690394762</li>
                                <li><strong>Emergency Hotline:</strong> 911</li>
                                <li><strong>Local Disaster Office:</strong> Contact barangay hall</li>
                            </ul>
                            <p style="margin-top: 1rem;">Keep your emergency kit in an easily accessible location and make sure all family members know where it is stored.
                        </div>
                    `;
                    break;
            }
            
            modalBody.innerHTML = content;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeAnnouncementModal() {
            const modal = document.getElementById('announcementModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        // Universal click handler for all announcement cards
        function handleAnnouncementClick(cardElement) {
            const announcementType = cardElement.getAttribute('data-announcement-type');
            
            if (announcementType === 'hardcoded') {
                // Handle hardcoded announcements
                const type = cardElement.getAttribute('data-type');
                const title = cardElement.getAttribute('data-title');
                const schedule = cardElement.getAttribute('data-schedule');
                const location = cardElement.getAttribute('data-location');
                const image = cardElement.getAttribute('data-image');
                
                showAnnouncementModal(type, title, schedule, location, image);
            } else if (announcementType === 'database') {
                // Handle database announcements (including 5th and all future ones)
                const id = cardElement.getAttribute('data-id');
                const title = cardElement.getAttribute('data-title');
                const schedule = cardElement.getAttribute('data-schedule');
                const location = cardElement.getAttribute('data-location');
                const image = cardElement.getAttribute('data-image');
                const content = cardElement.getAttribute('data-content');
                const category = cardElement.getAttribute('data-category');
                const startDate = cardElement.getAttribute('data-start-date');
                const endDate = cardElement.getAttribute('data-end-date');
                
                showAnnouncementModalFromDB(id, title, schedule, location, image, content, category, startDate, endDate);
            }
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAnnouncementModal();
            }
        });
        
        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Page initialization complete
            console.log('Health Tips page loaded successfully');
        });
    </script>
</body>
</html>