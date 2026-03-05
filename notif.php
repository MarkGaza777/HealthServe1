<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HealthServe - Payatas B</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #e8f5e8 0%, #f0f8f0 100%);
            min-height: 100vh;
        }

        /* Header Styles */
        .header {
            background: white;
            padding: 12px 24px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo {
            width: 40px;
            height: 40px;
            background: linear-gradient(45deg, #4CAF50, #2E7D32);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        .title {
            font-size: 18px;
            font-weight: 600;
            color: #2E7D32;
        }

        .nav-menu {
            display: flex;
            align-items: center;
            gap: 32px;
        }

        .nav-item {
            color: #555;
            text-decoration: none;
            font-weight: 500;
            padding: 8px 12px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .nav-item:hover {
            background: #f5f5f5;
            color: #2E7D32;
        }

        .nav-item.active {
            color: #2E7D32;
            font-weight: 600;
        }

        /* Right Section */
        .right-section {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* Notification Button */
        .notification-container {
            position: relative;
        }

        .notification-btn {
            background: #4CAF50;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(76, 175, 80, 0.3);
            position: relative;
        }

        .notification-btn:hover {
            background: #45a049;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.4);
        }

        .notification-icon {
            color: white;
            font-size: 18px;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ff4444;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        /* Notification Dropdown */
        .notification-dropdown {
            position: absolute;
            top: 50px;
            right: 0;
            width: 350px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1000;
            border: 1px solid #e0e0e0;
        }

        .notification-dropdown.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .notification-header {
            padding: 16px 20px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
        }

        .clear-all {
            color: #4CAF50;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
        }

        .clear-all:hover {
            text-decoration: underline;
        }

        .notification-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .notification-item {
            padding: 16px 20px;
            border-bottom: 1px solid #f8f8f8;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            gap: 12px;
            text-decoration: none;
            color: inherit;
        }

        .notification-item:hover {
            background: #f8fdf8;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-icon-wrapper {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .notification-icon-wrapper.appointment {
            background: rgba(76, 175, 80, 0.1);
            color: #4CAF50;
        }

        .notification-icon-wrapper.prescription {
            background: rgba(33, 150, 243, 0.1);
            color: #2196F3;
        }

        .notification-icon-wrapper.announcement {
            background: rgba(255, 193, 7, 0.1);
            color: #FFC107;
        }

        .notification-content {
            flex: 1;
        }

        .notification-text {
            font-size: 14px;
            color: #333;
            margin-bottom: 4px;
            line-height: 1.4;
        }

        .notification-time {
            font-size: 12px;
            color: #888;
        }

        .notification-dot {
            width: 8px;
            height: 8px;
            background: #4CAF50;
            border-radius: 50%;
            margin-left: auto;
            flex-shrink: 0;
        }

        .notification-item.read .notification-dot {
            display: none;
        }

        .notification-item.read .notification-text {
            color: #666;
        }

        /* User Profile */
        .user-profile {
            background: #333;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .logout-btn {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: #45a049;
        }

        /* Main Content */
        .main-content {
            padding: 40px 24px;
        }

        .page-title {
            font-size: 32px;
            font-weight: 600;
            color: #2E7D32;
            margin-bottom: 32px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .nav-menu {
                gap: 16px;
            }
            
            .nav-item {
                font-size: 14px;
            }

            .notification-dropdown {
                width: 300px;
                right: -20px;
            }
        }

        /* Overlay for mobile */
        .notification-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.3);
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 999;
        }

        .notification-overlay.active {
            opacity: 1;
            visibility: visible;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo-section">
            <div class="logo">H</div>
            <span class="title">HealthServe - Payatas B</span>
        </div>
        
        <nav class="nav-menu">
            <a href="#" class="nav-item">Dashboard</a>
            <a href="#" class="nav-item active">My Record</a>
            <a href="#" class="nav-item">Appointments</a>
            <a href="#" class="nav-item">Health Tips & News</a>
        </nav>

        <div class="right-section">
            <div class="notification-container">
                <button class="notification-btn" id="notificationBtn">
                    <span class="notification-icon">🔔</span>
                    <span class="notification-badge" id="notificationBadge">3</span>
                </button>
                
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <span class="notification-title">Notifications</span>
                        <a href="#" class="clear-all" id="clearAll">Clear all</a>
                    </div>
                    <div class="notification-list" id="notificationList">
                        <!-- Notifications will be inserted here by JavaScript -->
                    </div>
                </div>
            </div>
            
            <button class="user-profile">👤</button>
            <button class="logout-btn">Log out</button>
        </div>
    </header>

    <div class="notification-overlay" id="notificationOverlay"></div>

    <main class="main-content">
        <h1 class="page-title">My Record</h1>
        <p>Welcome to your health dashboard. Your notifications are now active in the top bar.</p>
    </main>

    <script>
        class NotificationSystem {
            constructor() {
                this.notifications = [
                    {
                        id: 1,
                        type: 'appointment',
                        icon: '📅',
                        text: 'Appointment Reminder: You have a check-up tomorrow at 2 PM',
                        time: '2 hours ago',
                        read: false,
                        link: '/appointments'
                    },
                    {
                        id: 2,
                        type: 'prescription',
                        icon: '💊',
                        text: 'Prescription Update: Your new prescription is ready for pick-up',
                        time: '5 hours ago',
                        read: false,
                        link: '/prescriptions'
                    },
                    {
                        id: 3,
                        type: 'announcement',
                        icon: '📢',
                        text: 'Announcement: Health Center will be closed on Oct 1, 2025',
                        time: '1 day ago',
                        read: false,
                        link: '/announcements'
                    },
                    {
                        id: 4,
                        type: 'appointment',
                        icon: '✅',
                        text: 'Your appointment has been confirmed for next week',
                        time: '2 days ago',
                        read: true,
                        link: '/appointments'
                    },
                    {
                        id: 5,
                        type: 'prescription',
                        icon: '🩺',
                        text: 'New medical supply available: Blood pressure monitors',
                        time: '3 days ago',
                        read: true,
                        link: '/medical-supplies'
                    }
                ];

                this.init();
            }

            init() {
                this.renderNotifications();
                this.updateBadge();
                this.bindEvents();
            }

            bindEvents() {
                const notificationBtn = document.getElementById('notificationBtn');
                const notificationDropdown = document.getElementById('notificationDropdown');
                const notificationOverlay = document.getElementById('notificationOverlay');
                const clearAll = document.getElementById('clearAll');

                notificationBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.toggleDropdown();
                });

                notificationOverlay.addEventListener('click', () => {
                    this.closeDropdown();
                });

                clearAll.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.clearAllNotifications();
                });

                document.addEventListener('click', (e) => {
                    if (!notificationDropdown.contains(e.target) && !notificationBtn.contains(e.target)) {
                        this.closeDropdown();
                    }
                });

                // Handle escape key
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        this.closeDropdown();
                    }
                });
            }

            renderNotifications() {
                const notificationList = document.getElementById('notificationList');
                notificationList.innerHTML = '';

                this.notifications.forEach(notification => {
                    const notificationElement = this.createNotificationElement(notification);
                    notificationList.appendChild(notificationElement);
                });
            }

            createNotificationElement(notification) {
                const div = document.createElement('a');
                div.className = `notification-item ${notification.read ? 'read' : ''}`;
                div.href = '#';
                div.setAttribute('data-id', notification.id);
                
                div.innerHTML = `
                    <div class="notification-icon-wrapper ${notification.type}">
                        <span>${notification.icon}</span>
                    </div>
                    <div class="notification-content">
                        <div class="notification-text">${notification.text}</div>
                        <div class="notification-time">${notification.time}</div>
                    </div>
                    ${!notification.read ? '<div class="notification-dot"></div>' : ''}
                `;

                div.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.handleNotificationClick(notification.id, notification.link);
                });

                return div;
            }

            handleNotificationClick(notificationId, link) {
                // Mark as read
                const notification = this.notifications.find(n => n.id === notificationId);
                if (notification && !notification.read) {
                    notification.read = true;
                    this.renderNotifications();
                    this.updateBadge();
                }

                // Simulate navigation (replace with actual routing)
                console.log(`Navigating to: ${link}`);
                alert(`This would navigate to: ${link}\n\nIn a real application, this would redirect to the appropriate page.`);
                
                this.closeDropdown();
            }

            toggleDropdown() {
                const dropdown = document.getElementById('notificationDropdown');
                const overlay = document.getElementById('notificationOverlay');
                const isActive = dropdown.classList.contains('active');

                if (isActive) {
                    this.closeDropdown();
                } else {
                    dropdown.classList.add('active');
                    overlay.classList.add('active');
                }
            }

            closeDropdown() {
                const dropdown = document.getElementById('notificationDropdown');
                const overlay = document.getElementById('notificationOverlay');
                
                dropdown.classList.remove('active');
                overlay.classList.remove('active');
            }

            updateBadge() {
                const badge = document.getElementById('notificationBadge');
                const unreadCount = this.notifications.filter(n => !n.read).length;
                
                if (unreadCount > 0) {
                    badge.textContent = unreadCount;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            }

            clearAllNotifications() {
                this.notifications.forEach(notification => {
                    notification.read = true;
                });
                
                this.renderNotifications();
                this.updateBadge();
                this.closeDropdown();
            }

            // Method to add new notifications (for demo purposes)
            addNotification(notification) {
                notification.id = Date.now();
                this.notifications.unshift(notification);
                this.renderNotifications();
                this.updateBadge();
            }
        }

        // Initialize the notification system when the page loads
        document.addEventListener('DOMContentLoaded', () => {
            window.notificationSystem = new NotificationSystem();
            
            // Demo: Add a new notification after 5 seconds
            setTimeout(() => {
                window.notificationSystem.addNotification({
                    type: 'appointment',
                    icon: '🩺',
                    text: 'Doctor updated your medical record with new test results',
                    time: 'Just now',
                    read: false,
                    link: '/medical-records'
                });
            }, 5000);
        });
    </script>
</body>
</html>