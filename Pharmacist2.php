<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HealthServe - Payatas B Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #90c695 0%, #7ab87f 100%);
            min-height: 100vh;
            display: flex;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-right: 1px solid rgba(255, 255, 255, 0.2);
            padding: 20px 0;
            display: flex;
            flex-direction: column;
        }

        .profile-section {
            display: flex;
            align-items: center;
            padding: 0 20px 30px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 30px;
        }

        .profile-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #5a9fd4, #4a8bc2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
            margin-right: 15px;
            position: relative;
        }

        .profile-avatar::after {
            content: '';
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 16px;
            height: 16px;
            background: #4ade80;
            border: 2px solid white;
            border-radius: 50%;
        }

        .profile-info h3 {
            color: white;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .profile-info .status {
            color: #4ade80;
            font-size: 14px;
            font-weight: 500;
        }

        .nav-section {
            padding: 0 20px;
            flex: 1;
        }

        .nav-section h4 {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .nav-menu {
            list-style: none;
        }

        .nav-item {
            margin-bottom: 8px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .nav-link:hover, .nav-link.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            transform: translateX(4px);
        }

        .nav-icon {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            opacity: 0.8;
        }

        .logout-section {
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }

        .header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 30px;
        }

        .header-title {
            display: flex;
            align-items: center;
            color: white;
        }

        .header-title img {
            width: 40px;
            height: 40px;
            margin-right: 12px;
            border-radius: 8px;
        }

        .header-title h1 {
            font-size: 28px;
            font-weight: 700;
        }

        /* Dashboard Cards */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
        }

        .stat-number {
            font-size: 48px;
            font-weight: 800;
            color: #2d5a3d;
            margin-bottom: 8px;
        }

        .stat-label {
            color: #6b7280;
            font-size: 16px;
            font-weight: 500;
        }

        .stat-card.alert .stat-number {
            color: #ef4444;
        }

        .stat-card.warning .stat-number {
            color: #f59e0b;
        }

        /* Announcements Section */
        .announcements-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .section-title {
            font-size: 24px;
            font-weight: 700;
            color: #2d5a3d;
            margin-bottom: 20px;
        }

        .announcement-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 16px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            border-left: 4px solid #7ab87f;
            transition: all 0.3s ease;
        }

        .announcement-card:hover {
            transform: translateX(4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }

        .announcement-header {
            display: flex;
            justify-content: between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .announcement-title {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 4px;
        }

        .announcement-schedule {
            color: #6b7280;
            font-size: 14px;
            font-weight: 500;
        }

        .announcement-date {
            color: #6b7280;
            font-size: 13px;
        }

        .announcement-content {
            color: #4b5563;
            line-height: 1.6;
            margin-bottom: 16px;
        }

        .vaccine-list {
            margin: 12px 0;
            padding-left: 20px;
        }

        .vaccine-list li {
            margin-bottom: 4px;
            color: #4b5563;
        }

        .view-more-btn {
            background: #7ab87f;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .view-more-btn:hover {
            background: #6aa76f;
            transform: translateY(-1px);
        }

        /* Inventory Chart */
        .inventory-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .chart-container {
            display: flex;
            align-items: end;
            justify-content: space-around;
            height: 200px;
            margin: 30px 0;
        }

        .chart-bar {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
        }

        .bar {
            width: 60px;
            background: #7ab87f;
            border-radius: 8px 8px 0 0;
            transition: all 0.3s ease;
            position: relative;
            cursor: pointer;
        }

        .bar:hover {
            background: #6aa76f;
            transform: scale(1.05);
        }

        .bar-value {
            position: absolute;
            top: -25px;
            left: 50%;
            transform: translateX(-50%);
            font-weight: 600;
            color: #2d5a3d;
            font-size: 14px;
        }

        .bar-label {
            margin-top: 12px;
            font-weight: 500;
            color: #4b5563;
            text-align: center;
        }

        /* Notifications */
        .notifications-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 30px;
            margin-top: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .notification-item {
            display: flex;
            align-items: center;
            padding: 16px;
            margin-bottom: 12px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
        }

        .notification-item:hover {
            transform: translateX(4px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 16px;
            font-weight: bold;
            color: white;
        }

        .notification-icon.warning {
            background: #f59e0b;
        }

        .notification-icon.error {
            background: #ef4444;
        }

        .notification-content h4 {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 4px;
        }

        .notification-content p {
            color: #6b7280;
            font-size: 14px;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 40px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
        }

        .close-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: #f3f4f6;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            color: #6b7280;
            transition: all 0.3s ease;
        }

        .close-btn:hover {
            background: #e5e7eb;
            color: #374151;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 250px;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="profile-section">
            <div class="profile-avatar">DG</div>
            <div class="profile-info">
                <h3>Dr. Norner Gumliran</h3>
                <div class="status">Online</div>
            </div>
        </div>

        <div class="nav-section">
            <h4>General</h4>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="#" class="nav-link active" data-section="dashboard">
                        <span class="nav-icon">📊</span>
                        Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link" data-section="inventory">
                        <span class="nav-icon">📦</span>
                        Inventory
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" class="nav-link" data-section="announcements">
                        <span class="nav-icon">📢</span>
                        Announcements
                    </a>
                </li>
            </ul>
        </div>

        <div class="logout-section">
            <a href="#" class="nav-link">
                <span class="nav-icon">🚪</span>
                Logout
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="header-title">
                <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #4a8bc2, #5a9fd4); border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px; color: white; font-weight: bold;">🏥</div>
                <h1>HealthServe - Payatas B</h1>
            </div>
        </div>

        <!-- Dashboard Section -->
        <div id="dashboard-section">
            <div class="dashboard-grid">
                <div class="stat-card">
                    <div class="stat-number">120</div>
                    <div class="stat-label">Total Medicines in Stock</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-number">5</div>
                    <div class="stat-label">Low Stock Alerts</div>
                </div>
                <div class="stat-card alert">
                    <div class="stat-number">2</div>
                    <div class="stat-label">Out of Stock</div>
                </div>
            </div>

            <div class="inventory-section">
                <h2 class="section-title">Inventory Overview</h2>
                <div class="chart-container">
                    <div class="chart-bar">
                        <div class="bar" style="height: 140px;">
                            <div class="bar-value">58</div>
                        </div>
                        <div class="bar-label">Antibiotics</div>
                    </div>
                    <div class="chart-bar">
                        <div class="bar" style="height: 70px;">
                            <div class="bar-value">30</div>
                        </div>
                        <div class="bar-label">Vitamins</div>
                    </div>
                    <div class="chart-bar">
                        <div class="bar" style="height: 100px;">
                            <div class="bar-value">40</div>
                        </div>
                        <div class="bar-label">Pain Relievers</div>
                    </div>
                    <div class="chart-bar">
                        <div class="bar" style="height: 25px;">
                            <div class="bar-value">10</div>
                        </div>
                        <div class="bar-label">Others</div>
                    </div>
                </div>
            </div>

            <div class="notifications-section">
                <h2 class="section-title">Notifications</h2>
                <div class="notification-item">
                    <div class="notification-icon warning">!</div>
                    <div class="notification-content">
                        <h4>Medicine Running Low</h4>
                        <p>Ibuprofen - 5 tablets remaining</p>
                    </div>
                </div>
                <div class="notification-item">
                    <div class="notification-icon warning">!</div>
                    <div class="notification-content">
                        <h4>Expiring Medicine</h4>
                        <p>Cough Syrup - Expires on Oct 15</p>
                    </div>
                </div>
                <div class="notification-item">
                    <div class="notification-icon error">!</div>
                    <div class="notification-content">
                        <h4>Restock Reminder</h4>
                        <p>Paracetamol - No tablets in stock</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Announcements Section -->
        <div id="announcements-section" style="display: none;">
            <div class="announcements-section">
                <h2 class="section-title">Announcements</h2>
                
                <div class="announcement-card">
                    <div class="announcement-header">
                        <div>
                            <div class="announcement-title">Children Immunization Program</div>
                            <div class="announcement-schedule">Every Wednesday & Friday, 8 AM - 12 NN</div>
                        </div>
                        <button class="view-more-btn" onclick="showAnnouncementModal('immunization')">View More</button>
                    </div>
                    <div class="announcement-date">Starting on October 1, 2025</div>
                </div>

                <div class="announcement-card">
                    <div class="announcement-header">
                        <div>
                            <div class="announcement-title">Prenatal Psychology Training</div>
                            <div class="announcement-schedule">November 24, 2025 | 2 PM - 4 PM</div>
                        </div>
                        <button class="view-more-btn" onclick="showAnnouncementModal('prenatal')">View More</button>
                    </div>
                </div>

                <div class="announcement-card">
                    <div class="announcement-header">
                        <div>
                            <div class="announcement-title">Anti-Dengue Fogging Drive</div>
                            <div class="announcement-schedule">November 25-27, 2025 | 8 AM - 11 AM</div>
                        </div>
                        <button class="view-more-btn" onclick="showAnnouncementModal('dengue')">View More</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inventory Section -->
        <div id="inventory-section" style="display: none;">
            <div class="inventory-section">
                <h2 class="section-title">Inventory Management</h2>
                <p style="color: #6b7280; margin-bottom: 30px;">Detailed inventory management features would be implemented here with full CRUD operations for medicine stock.</p>
                
                <div class="dashboard-grid">
                    <div class="stat-card">
                        <div class="stat-number">58</div>
                        <div class="stat-label">Antibiotics Available</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">30</div>
                        <div class="stat-label">Vitamins in Stock</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">40</div>
                        <div class="stat-label">Pain Relievers</div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-number">10</div>
                        <div class="stat-label">Other Medicines</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Announcements -->
    <div id="announcementModal" class="modal">
        <div class="modal-content">
            <button class="close-btn" onclick="closeModal()">&times;</button>
            <div id="modalContent">
                <!-- Dynamic content will be inserted here -->
            </div>
        </div>
    </div>

    <script>
        // Navigation functionality
        document.addEventListener('DOMContentLoaded', function() {
            const navLinks = document.querySelectorAll('.nav-link[data-section]');
            const sections = {
                'dashboard': document.getElementById('dashboard-section'),
                'inventory': document.getElementById('inventory-section'),
                'announcements': document.getElementById('announcements-section')
            };

            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Remove active class from all links
                    navLinks.forEach(l => l.classList.remove('active'));
                    
                    // Add active class to clicked link
                    this.classList.add('active');
                    
                    // Hide all sections
                    Object.values(sections).forEach(section => {
                        if (section) section.style.display = 'none';
                    });
                    
                    // Show selected section
                    const targetSection = this.getAttribute('data-section');
                    if (sections[targetSection]) {
                        sections[targetSection].style.display = 'block';
                    }
                });
            });

            // Add hover effects to charts
            const bars = document.querySelectorAll('.bar');
            bars.forEach(bar => {
                bar.addEventListener('mouseenter', function() {
                    this.style.filter = 'brightness(1.1)';
                });
                
                bar.addEventListener('mouseleave', function() {
                    this.style.filter = 'brightness(1)';
                });
            });
        });

        // Modal functionality
        function showAnnouncementModal(type) {
            const modal = document.getElementById('announcementModal');
            const modalContent = document.getElementById('modalContent');
            
            let content = '';
            
            switch(type) {
                case 'immunization':
                    content = `
                        <h2 style="color: #2d5a3d; margin-bottom: 20px;">Children Immunization Program</h2>
                        <p style="color: #6b7280; margin-bottom: 16px;"><strong>Schedule:</strong> Every Wednesday & Friday, 8 AM - 12 NN</p>
                        <p style="color: #6b7280; margin-bottom: 20px;"><strong>Starting:</strong> October 1, 2025 (Wednesday)</p>
                        
                        <p style="line-height: 1.6; margin-bottom: 16px;">We will be conducting a Children's Immunization Program on October 1, 2025 (Wednesday) at the Barangay Payatas B Health Center.</p>
                        
                        <p style="margin-bottom: 12px;"><strong>This program will provide free vaccines for children ages 0-5 years old, including:</strong></p>
                        <ul class="vaccine-list">
                            <li>BCG</li>
                            <li>DPT</li>
                            <li>Polio</li>
                            <li>Measles</li>
                        </ul>
                        
                        <p style="line-height: 1.6; margin-top: 16px;">Parents and guardians are encouraged to bring their children's health cards for proper recording. Let's work together to keep our children healthy and protected.</p>
                    `;
                    break;
                case 'prenatal':
                    content = `
                        <h2 style="color: #2d5a3d; margin-bottom: 20px;">Prenatal Psychology Training</h2>
                        <p style="color: #6b7280; margin-bottom: 16px;"><strong>Date:</strong> November 24, 2025</p>
                        <p style="color: #6b7280; margin-bottom: 20px;"><strong>Time:</strong> 2 PM - 4 PM</p>
                        
                        <p style="line-height: 1.6; margin-bottom: 16px;">Join us for a comprehensive prenatal psychology training session designed for expecting mothers and healthcare providers.</p>
                        
                        <p style="margin-bottom: 12px;"><strong>Topics covered:</strong></p>
                        <ul class="vaccine-list">
                            <li>Mental health during pregnancy</li>
                            <li>Stress management techniques</li>
                            <li>Bonding with your unborn child</li>
                            <li>Postpartum preparation</li>
                        </ul>
                        
                        <p style="line-height: 1.6; margin-top: 16px;">This training is free and open to all pregnant women and their support systems in the community.</p>
                    `;
                    break;
                case 'dengue':
                    content = `
                        <h2 style="color: #2d5a3d; margin-bottom: 20px;">Anti-Dengue Fogging Drive</h2>
                        <p style="color: #6b7280; margin-bottom: 16px;"><strong>Dates:</strong> November 25-27, 2025</p>
                        <p style="color: #6b7280; margin-bottom: 20px;"><strong>Time:</strong> 8 AM - 11 AM</p>
                        
                        <p style="line-height: 1.6; margin-bottom: 16px;">The barangay health unit will conduct a comprehensive anti-dengue fogging operation to eliminate mosquito breeding sites and reduce dengue risk in our community.</p>
                        
                        <p style="margin-bottom: 12px;"><strong>Areas to be covered:</strong></p>
                        <ul class="vaccine-list">
                            <li>All residential areas</li>
                            <li>Public spaces and parks</li>
                            <li>School premises</li>
                            <li>Market areas</li>
                        </ul>
                        
                        <p style="line-height: 1.6; margin-top: 16px;"><strong>Please:</strong> Keep windows and doors closed during fogging operations and ensure pets are kept indoors for safety.</p>
                    `;
                    break;
            }
            
            modalContent.innerHTML = content;
            modal.style.display = 'flex';
        }

        function closeModal() {
            const modal = document.getElementById('announcementModal');
            modal.style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('announcementModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }

        // Add some interactive animations
        document.addEventListener('DOMContentLoaded', function() {
            // Animate numbers on page load
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(number => {
                const finalValue = parseInt(number.textContent);
                let currentValue = 0;
                const increment = finalValue / 20;
                
                const timer = setInterval(() => {
                    currentValue += increment;
                    if (currentValue >= finalValue) {
                        number.textContent = finalValue;
                        clearInterval(timer);
                    } else {
                        number.textContent = Math.floor(currentValue);
                    }
                }, 50);
            });
        });
    </script>
</body>
</html>