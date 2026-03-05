<?php
session_start();
require 'db.php';
if(empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'patient') { 
    header('Location: login.php'); 
    exit; 
}

// Get appointment statistics
$user_id = $_SESSION['user']['id'];
$today = date('Y-m-d');

// Get user's photo_path for header avatar
$stmt = $pdo->prepare('SELECT photo_path FROM users WHERE id = ?');
$stmt->execute([$user_id]);
$user_photo_data = $stmt->fetch(PDO::FETCH_ASSOC);
$user_photo_path = $user_photo_data['photo_path'] ?? null;

// Today's appointments
$stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE user_id = ? AND DATE(start_datetime) = ? AND status != "declined"');
$stmt->execute([$user_id, $today]);
$today_count = $stmt->fetchColumn();

// Upcoming appointments
$stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE user_id = ? AND DATE(start_datetime) > ? AND status = "approved"');
$stmt->execute([$user_id, $today]);
$upcoming_count = $stmt->fetchColumn();

// Missed appointments
$stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE user_id = ? AND DATE(start_datetime) < ? AND status = "approved"');
$stmt->execute([$user_id, $today]);
$missed_count = $stmt->fetchColumn();

// Get recent appointments
$stmt = $pdo->prepare('SELECT a.*, d.name as doctor_name, p.first_name, p.last_name FROM appointments a 
                      LEFT JOIN doctors d ON d.id = a.doctor_id 
                      LEFT JOIN patients p ON p.id = a.patient_id 
                      WHERE a.user_id = ? 
                      ORDER BY a.start_datetime DESC LIMIT 10');
$stmt->execute([$user_id]);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Appointments - HealthServe Payatas B</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .notification-container{position:relative}
        .notification-btn{background:#4CAF50;border:none;border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .3s ease;box-shadow:0 2px 8px rgba(76,175,80,.3);position:relative}
        .notification-btn:hover{background:#45a049;transform:translateY(-1px);box-shadow:0 4px 12px rgba(76,175,80,.4)}
        .notification-badge{position:absolute;top:-5px;right:-5px;background:#ff4444;color:#fff;border-radius:50%;width:20px;height:20px;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center}
        .notification-dropdown{position:absolute;top:50px;right:0;width:350px;background:#fff;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.15);opacity:0;visibility:hidden;transform:translateY(-10px);transition:all .3s ease;z-index:1000;border:1px solid #e0e0e0}
        .notification-dropdown.active{opacity:1;visibility:visible;transform:translateY(0)}
        .notification-header{padding:16px 20px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center}
        .notification-title{font-size:16px;font-weight:600;color:#333}
        .clear-all{color:#4CAF50;font-size:14px;cursor:pointer;text-decoration:none}
        .notification-list{max-height:400px;overflow-y:auto}
        .notification-item{padding:16px 20px;border-bottom:1px solid #f8f8f8;cursor:pointer;transition:all .3s ease;display:flex;gap:12px;text-decoration:none;color:inherit}
        .notification-item:hover{background:#f8fdf8}
        .notification-item:last-child{border-bottom:none}
        .notification-icon-wrapper{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .notification-icon-wrapper.appointment{background:rgba(76,175,80,.1);color:#4CAF50}
        .notification-icon-wrapper.prescription{background:rgba(33,150,243,.1);color:#2196F3}
        .notification-icon-wrapper.announcement{background:rgba(255,193,7,.1);color:#FFC107}
        .notification-content{flex:1}
        .notification-text{font-size:14px;color:#333;margin-bottom:4px;line-height:1.4}
        .notification-time{font-size:12px;color:#888}
        .notification-dot{width:8px;height:8px;background:#4CAF50;border-radius:50%;margin-left:auto;flex-shrink:0}
        .notification-item.read .notification-dot{display:none}
        .notification-overlay{display:none}
        @media (max-width:768px){.notification-dropdown{width:300px;right:-20px}}
    </style>
    <script src="custom_modal.js"></script>
    <script src="custom_notification.js"></script>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-logo">
            <img src="assets/payatas logo.png" alt="Payatas B Logo">
            <h1>HealthServe - Payatas B</h1>
        </div>
        <nav class="header-nav">
            <a href="user_dashboard.php">Dashboard</a>
            <a href="user_records.php">My Record</a>
            <a href="user_appointments.php" class="active">Appointments</a>
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
                    <div class="notification-list" id="notificationList"></div>
                </div>
            </div>
            <a href="user_profile.php" title="My Profile" style="text-decoration:none">
                <div class="user-avatar" style="overflow: hidden; position: relative;">
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
        <h1 class="page-title">My Appointments</h1>

        <!-- Dashboard Stats -->
        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-icon">📅</div>
                <div class="stat-number"><?=$today_count?></div>
                <div class="stat-label">Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📋</div>
                <div class="stat-number"><?=$upcoming_count?></div>
                <div class="stat-label">Upcoming</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-number"><?=$missed_count?></div>
                <div class="stat-label">Missed</div>
            </div>
        </div>

        <!-- Book Appointment Button -->
        <button class="btn-book" onclick="toggleBookingForm()">Book an Appointment</button>
        <div style="clear: both;"></div>

        <!-- Booking Form (Initially Hidden) -->
        <div id="booking-form" class="content-card" style="display: none;">
            <h2 class="card-title">Appointment Booking Form</h2>
            <form method="post" action="book_appointment.php" class="form-container">
                <div class="form-section">
                    <div class="section-title">📋 Patient Information</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="patient_name">Patient Name*</label>
                            <input type="text" id="patient_name" name="patient_name" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" class="form-input">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="start_datetime">Preferred Date & Time*</label>
                            <input type="datetime-local" id="start_datetime" name="start_datetime" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label for="duration_minutes">Duration (minutes)</label>
                            <select id="duration_minutes" name="duration_minutes" class="form-input">
                                <option value="20">20 minutes</option>
                                <option value="30">30 minutes</option>
                                <option value="45">45 minutes</option>
                                <option value="60">1 hour</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="reason">Reason for Appointment</label>
                            <select id="reason" name="reason" class="form-input">
                                <option value="">Select reason...</option>
                                <option value="general-checkup">General Check-up</option>
                                <option value="consultation">Medical Consultation</option>
                                <option value="follow-up">Follow-up Visit</option>
                                <option value="immunization">Immunization</option>
                                <option value="laboratory">Laboratory Tests</option>
                                <option value="others">Others</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="doctor_id">Preferred Doctor (Optional)</label>
                            <select id="doctor_id" name="doctor_id" class="form-input">
                                <option value="">Any Available Doctor</option>
                                <?php
                                try {
                                    $stmt = $pdo->prepare("
                                        SELECT d.id, CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, '')) as doctor_name, d.specialization
                                        FROM doctors d
                                        LEFT JOIN users u ON d.user_id = u.id
                                        ORDER BY u.last_name, u.first_name
                                    ");
                                    $stmt->execute();
                                    $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($doctors as $doc) {
                                        $name = trim(preg_replace('/\s+/', ' ', $doc['doctor_name']));
                                        $spec = $doc['specialization'] ? ' - ' . htmlspecialchars($doc['specialization']) : '';
                                        echo '<option value="' . $doc['id'] . '">Dr. ' . htmlspecialchars($name) . $spec . '</option>';
                                    }
                                } catch (Exception $e) {
                                    // Silently fail, just show "Any Available Doctor"
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="notes">Additional Notes</label>
                        <textarea id="notes" name="notes" class="form-input" rows="4" placeholder="Any additional information..."></textarea>
                    </div>
                </div>
                <button type="submit" name="create_appt" class="btn-primary">Book an Appointment</button>
            </form>
        </div>

        <!-- Appointments List -->
        <div class="content-card">
            <div class="card-header">
                <h2 class="card-title">Appointments List</h2>
            </div>
            
            <table class="appointments-table">
                <thead>
                    <tr>
                        <th>Appointment</th>
                        <th>Date and Time</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($appointments as $a): 
                        $status_class = '';
                        $status_text = $a['status'];
                        $appointment_date = new DateTime($a['start_datetime']);
                        $now = new DateTime();
                        
                        if ($appointment_date < $now && $a['status'] == 'approved') {
                            $status_class = 'status-completed';
                            $status_text = 'Completed';
                        } elseif ($appointment_date < $now) {
                            $status_class = 'status-missed';
                            $status_text = 'Missed';
                        } elseif ($a['status'] == 'approved') {
                            $status_class = 'status-upcoming';
                            $status_text = 'Upcoming';
                        }
                    ?>
                    <tr>
                        <td>
                            <div><?=htmlspecialchars($a['notes'] ?: 'Check-up')?></div>
                            <small style="color: #666;">with <?=htmlspecialchars($a['doctor_name'] ?: 'Dr. TBA')?></small>
                        </td>
                        <td><?=date('F j, Y g:i A', strtotime($a['start_datetime']))?></td>
                        <td><span class="status-badge <?=$status_class?>"><?=$status_text?></span></td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon btn-view" title="View Details">📄</button>
                                <?php if($a['status'] == 'pending'): ?>
                                <button class="btn-icon btn-delete" title="Cancel" onclick="cancelAppointment(<?=$a['id']?>)">🗑️</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if(empty($appointments)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 2rem; color: #666;">
                            No appointments found. <a href="#" onclick="toggleBookingForm()" class="auth-link">Book your first appointment</a>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <script>
        (function(){
            class NotificationSystem{
                constructor(){
                    this.notifications=[
                        {id:1,type:'appointment',icon:'📅',text:'Appointment Reminder: You have a check-up tomorrow at 2 PM',time:'2 hours ago',read:false,link:'user_appointments.php'},
                        {id:2,type:'announcement',icon:'📢',text:'Announcement: Health Center will be closed on Oct 1, 2025',time:'1 day ago',read:false,link:'health_tips.php'},
                        {id:3,type:'prescription',icon:'💊',text:'Prescription Update: Your new prescription is ready for pick-up',time:'5 hours ago',read:false,link:'user_records.php'}
                    ];
                    this.init();
                }
                
                init(){
                    this.renderNotifications();
                    this.updateBadge();
                    this.bindEvents();
                }
                
                bindEvents(){
                    const nBtn = document.getElementById('notificationBtn');
                    const nDrop = document.getElementById('notificationDropdown');
                    const clear = document.getElementById('clearAll');
                    
                    if(!nBtn || !nDrop || !clear) return;
                    
                    nBtn.addEventListener('click', e => {
                        e.stopPropagation();
                        this.toggleDropdown();
                    });
                    
                    clear.addEventListener('click', e => {
                        e.preventDefault();
                        this.clearAllNotifications();
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
                    this.notifications.forEach(n => {
                        list.appendChild(this.createNotificationElement(n));
                    });
                }
                
                createNotificationElement(n){
                    const a = document.createElement('a');
                    a.className = `notification-item ${n.read ? 'read' : ''}`;
                    a.href = '#';
                    a.setAttribute('data-id', n.id);
                    a.innerHTML = `
                        <div class="notification-icon-wrapper ${n.type}">
                            <span>${n.icon}</span>
                        </div>
                        <div class="notification-content">
                            <div class="notification-text">${n.text}</div>
                            <div class="notification-time">${n.time}</div>
                        </div>
                        ${!n.read ? '<div class="notification-dot"></div>' : ''}
                    `;
                    a.addEventListener('click', e => {
                        e.preventDefault();
                        this.handleNotificationClick(n.id, n.link);
                    });
                    return a;
                }
                
                handleNotificationClick(id, link){
                    const n = this.notifications.find(x => x.id === id);
                    if(n && !n.read){
                        n.read = true;
                        this.renderNotifications();
                        this.updateBadge();
                    }
                    if(link){
                        window.location.href = link;
                    }
                    this.closeDropdown();
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
                
                updateBadge(){
                    const b = document.getElementById('notificationBadge');
                    if(!b) return;
                    const unread = this.notifications.filter(n => !n.read).length;
                    if(unread > 0){
                        b.textContent = unread;
                        b.style.display = 'flex';
                    } else {
                        b.style.display = 'none';
                    }
                }
                
                clearAllNotifications(){
                    this.notifications.forEach(n => n.read = true);
                    this.renderNotifications();
                    this.updateBadge();
                    this.closeDropdown();
                }
            }
            
            document.addEventListener('DOMContentLoaded', function(){
                window.notificationSystem = new NotificationSystem();
            });
        })();
    </script>
        function toggleBookingForm() {
            const form = document.getElementById('booking-form');
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
            
            if (form.style.display === 'block') {
                form.scrollIntoView({ behavior: 'smooth' });
            }
        }
        
        async function cancelAppointment(id) {
            const confirmed = await confirm('Are you sure you want to cancel this appointment?');
            if (confirmed) {
                // Create form and submit
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'cancel_appointment.php';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'appointment_id';
                input.value = id;
                
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Set minimum date to today
        document.getElementById('start_datetime').min = new Date().toISOString().slice(0, 16);
    </script>
</body>
</html>