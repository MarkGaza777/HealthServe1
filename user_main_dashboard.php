<?php
session_start();
require 'db.php';
require_once 'admin_helpers_simple.php';
require_once 'auto_audit_log.php'; // Auto-log page access
require_once 'residency_verification_helper.php';

if(empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'patient') { 
    header('Location: Login.php'); 
    exit; 
}

// Check maintenance mode - redirect to maintenance page
if (isMaintenanceMode()) {
    header('Location: maintenance_mode.php');
    exit;
}

// Get user information
$user_id = $_SESSION['user']['id'];
$username = $_SESSION['user']['username'];
$residency_verified = isPatientResidencyVerified($user_id);

// Get patient's first name and photo_path from users table
$stmt = $pdo->prepare('SELECT first_name, photo_path FROM users WHERE id = ?');
$stmt->execute([$user_id]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);
$first_name = $user_data['first_name'] ?? $username; // Fallback to username if first_name is not set
$user_photo_path = $user_data['photo_path'] ?? null;

// Get appointment statistics
$today = date('Y-m-d');

// Today's appointments
$stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE user_id = ? AND DATE(start_datetime) = ? AND status != "declined"');
$stmt->execute([$user_id, $today]);
$today_count = $stmt->fetchColumn();

// Upcoming appointments (tomorrow and future dates, excluding today)
// Includes pending, approved, and rescheduled appointments
$stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE user_id = ? AND DATE(start_datetime) > ? AND status IN ("pending", "approved", "rescheduled")');
$stmt->execute([$user_id, $today]);
$upcoming_count = $stmt->fetchColumn();

// Completed appointments
$stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE user_id = ? AND status = "completed"');
$stmt->execute([$user_id]);
$completed_count = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HealthServe - Payatas B Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="User_main_dashboard.css">
    <style>
        .residency-banner {
            background: linear-gradient(90deg, #ffcdd2 0%, #ef9a9a 100%);
            color: #b71c1c;
            padding: 14px 20px;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(183, 28, 28, 0.2);
        }
        .residency-banner a { color: #8b0000; }
        .consult-btn-disabled { opacity: 0.85; }
        .consult-btn-disabled:hover { opacity: 1; }
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
            <a href="user_main_dashboard.php" class="active">Dashboard</a>
            <a href="user_records.php">My Record</a>
            <a href="user_appointments.php">Appointments</a>
            <a href="health_tips.php">Announcements</a>
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
    <div class="notification-overlay" id="notificationOverlay"></div>

    <!-- Main Content -->
    <main class="main-content">
        <?php if (!$residency_verified): ?>
        <div class="residency-banner" role="alert">
            <strong>Complete your Payatas residency verification</strong> to book appointments, request lab tests, and receive prescriptions. 
            <a href="user_profile.php#verification" style="color: #8b0000; text-decoration: underline;">Upload your ID in Profile &rarr;</a>
        </div>
        <?php endif; ?>
        <!-- Hero Section -->
        <section class="hero-section">
            <div class="hero-content">
                <p>Welcome, <?=htmlspecialchars($first_name)?>!</p>
                <h1>How can we help you today?</h1>
                <?php if ($residency_verified): ?>
                <a href="user_appointments.php?open=1#appointment_booking_form" class="consult-btn" style="display:inline-block;text-decoration:none">Consult Now</a>
                <?php else: ?>
                <button type="button" class="consult-btn consult-btn-disabled" id="consultBtnRestricted" style="cursor:pointer;border:none;">Consult Now</button>
                <?php endif; ?>
            </div>
            <div class="hero-image"></div>
        </section>

        <!-- Dashboard Stats -->
        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-icon">📅</div>
                <div class="stat-number"><?=$today_count?></div>
                <div class="stat-label">Today's Appointments</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📋</div>
                <div class="stat-number"><?=$upcoming_count?></div>
                <div class="stat-label">Upcoming Appointments</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-number"><?=$completed_count?></div>
                <div class="stat-label">Completed Appointments</div>
            </div>
        </div>





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
                    
                    nBtn.addEventListener('click', e => {
                        e.stopPropagation();
                        this.toggleDropdown();
                    });
                    
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
                    
                    document.addEventListener('click', e => {
                        if(!nDrop.contains(e.target) && !nBtn.contains(e.target)){
                            this.closeDropdown();
                        }
                    });
                    
                    document.addEventListener('keydown', e => {
                        if(e.key === 'Escape'){
                            this.closeDropdown();
                        }
                    });
                }
                
                renderNotifications(){
                    const list = document.getElementById('notificationList');
                    if(!list) return;
                    list.innerHTML = '';
                    
                    if(this.notifications.length === 0){
                        list.innerHTML = '<div style="padding: 2rem; text-align: center; color: #888;">No notifications</div>';
                        return;
                    }
                    
                    this.notifications.forEach(n => {
                        list.appendChild(this.createNotificationElement(n));
                    });
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
                    try {
                        const response = await fetch('get_announcements.php');
                        const data = await response.json();
                        if(data.success){
                            const announcement = data.announcements.find(a => a.announcement_id == announcementId);
                            if(announcement){
                                // Navigate to health_tips page and open the announcement
                                window.location.href = `health_tips.php?announcement=${announcementId}`;
                            } else {
                                // Fallback: just go to health_tips page
                                window.location.href = 'health_tips.php';
                            }
                        } else {
                            window.location.href = 'health_tips.php';
                        }
                    } catch(e){
                        console.error('Error loading announcement:', e);
                        window.location.href = 'health_tips.php';
                    }
                }
                
                toggleDropdown(){
                    const d = document.getElementById('notificationDropdown');
                    if(!d) return;
                    const isActive = d.classList.contains('active');
                    if(isActive){
                        this.closeDropdown();
                    } else {
                        d.classList.add('active');
                    }
                }
                
                closeDropdown(){
                    const d = document.getElementById('notificationDropdown');
                    if(!d) return;
                    d.classList.remove('active');
                }
                
                async updateBadge(){
                    const b = document.getElementById('notificationBadge');
                    if(!b) return;
                    try {
                        const response = await fetch('get_patient_notifications.php?action=count');
                        const data = await response.json();
                        if(data.success){
                            const count = data.count || 0;
                            if(count > 0){
                                b.textContent = count;
                                b.style.display = 'flex';
                            } else {
                                b.style.display = 'none';
                            }
                        }
                    } catch(e){
                        // Fallback to local count
                        const unread = this.notifications.filter(n => !n.read).length;
                        if(unread > 0){
                            b.textContent = unread;
                            b.style.display = 'flex';
                        } else {
                            b.style.display = 'none';
                        }
                    }
                }
                
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
                var restrictedBtn = document.getElementById('consultBtnRestricted');
                if (restrictedBtn) {
                    restrictedBtn.addEventListener('click', function(){
                        alert('Only verified residents of Barangay Payatas are allowed to access HealthServe services. Please complete your verification.');
                        window.location.href = 'user_profile.php';
                    });
                }
            });
        })();

        // Consult Now (handled by link)
    </script>
</body>
</html>