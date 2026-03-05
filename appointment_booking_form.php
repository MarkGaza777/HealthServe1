<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HealthServe - Appointment Booking</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #e8f5e9 0%, #f1f8e9 100%);
            min-height: 100vh;
            padding: 20px;
        }

        /* Navigation Bar */
        .navbar {
            background: white;
            padding: 20px 50px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo {
            width: 45px;
            height: 45px;
            background: #2e7d32;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 20px;
        }

        .brand-name {
            font-size: 18px;
            font-weight: 600;
            color: #2e7d32;
        }

        .nav-links {
            display: flex;
            gap: 35px;
            list-style: none;
        }

        .nav-links a {
            text-decoration: none;
            color: #666;
            font-size: 15px;
            transition: color 0.3s;
            padding-bottom: 5px;
        }

        .nav-links a:hover,
        .nav-links a.active {
            color: #2e7d32;
            border-bottom: 2px solid #2e7d32;
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .notification-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 22px;
            position: relative;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: #2e7d32;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }

        .logout-btn {
            background: #2e7d32;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 15px;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            background: #1b5e20;
            transform: translateY(-1px);
        }

        /* Main Content */
        .main-content {
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-title {
            font-size: 36px;
            color: #1b5e20;
            margin-bottom: 30px;
            padding-left: 10px;
        }

        /* Form Container */
        .form-container {
            background: white;
            padding: 50px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        .form-title {
            text-align: center;
            font-size: 28px;
            color: #1b5e20;
            margin-bottom: 40px;
            font-weight: 600;
        }

        /* Form Sections */
        .form-section {
            margin-bottom: 35px;
        }

        .section-title {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
        }

        .form-group input,
        .form-group select {
            padding: 14px 18px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 15px;
            color: #333;
            transition: all 0.3s;
            background: white;
            cursor: pointer;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        .form-group input::placeholder {
            color: #999;
        }

        .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 18px center;
            padding-right: 45px;
        }

        /* Confirmation Section */
        .confirmation-section {
            margin: 35px 0;
        }

        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .checkbox-container input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: #2e7d32;
        }

        .checkbox-container label {
            font-size: 15px;
            color: #333;
            cursor: pointer;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 40px;
        }

        .btn {
            padding: 14px 40px;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            font-weight: 500;
        }

        .btn-primary {
            background: #2e7d32;
            color: white;
        }

        .btn-primary:hover {
            background: #1b5e20;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 125, 50, 0.3);
        }

        .btn-primary:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        .btn-secondary {
            background: #f5f5f5;
            color: #666;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        /* Requirements Note */
        .requirements-note {
            background: #f1f8e9;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            border-left: 4px solid #4CAF50;
        }

        .requirements-note p {
            font-size: 14px;
            color: #2e7d32;
            font-style: italic;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .navbar {
                padding: 15px 20px;
            }

            .nav-links {
                display: none;
            }

            .form-container {
                padding: 30px 20px;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .page-title {
                font-size: 28px;
            }

            .form-title {
                font-size: 24px;
            }
        }

        /* Success Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            padding: 40px;
            border-radius: 15px;
            text-align: center;
            max-width: 500px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }

        .modal-icon {
            font-size: 60px;
            margin-bottom: 20px;
        }

        .modal-content h2 {
            color: #2e7d32;
            margin-bottom: 15px;
        }

        .modal-content p {
            color: #666;
            margin-bottom: 25px;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="logo-section">
            <div class="logo">H</div>
            <span class="brand-name">HealthServe - Payatas B</span>
        </div>
        <ul class="nav-links">
            <li><a href="#dashboard">Dashboard</a></li>
            <li><a href="#record">My Record</a></li>
            <li><a href="#appointments" class="active">Appointments</a></li>
            <li><a href="#news">Health Tips & News</a></li>
        </ul>
        <div class="nav-right">
            <button class="notification-btn" onclick="toggleNotifications()">🔔</button>
            <div class="user-avatar">Z</div>
            <button class="logout-btn" onclick="logout()">Log out</button>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <h1 class="page-title">My Appointments</h1>

        <div class="form-container">
            <h2 class="form-title">Appointment Booking Form</h2>

            <form id="appointmentForm">
                <!-- Personal Information Section -->
                <div class="form-section">
                    <div class="form-row">
                        <div>
                            <h3 class="section-title">Personal Information</h3>
                            <div class="form-group">
                                <input type="text" id="fullName" placeholder="Full Name" required>
                            </div>
                        </div>
                        <div>
                            <h3 class="section-title">Requirements are:</h3>
                            <div class="form-group">
                                <select id="dateFormat" required>
                                    <option value="">MM/DD/YYYY</option>
                                    <option value="01/01/1990">01/01/1990</option>
                                    <option value="02/15/1985">02/15/1985</option>
                                    <option value="03/20/1995">03/20/1995</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <input type="date" id="dateOfBirth" placeholder="Date of Birth" required>
                        </div>
                        <div class="form-group">
                            <input type="tel" id="contactNumber" placeholder="Contact Number" required>
                        </div>
                    </div>
                </div>

                <!-- Appointment Details Section -->
                <div class="form-section">
                    <h3 class="section-title">Appointment Details</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <select id="reason" required>
                                <option value="">Reason for Appointment</option>
                                <option value="general-checkup">General Check-up</option>
                                <option value="consultation">Medical Consultation</option>
                                <option value="immunization">Immunization</option>
                                <option value="laboratory">Laboratory Tests</option>
                                <option value="dental">Dental Care</option>
                                <option value="maternal">Maternal Care</option>
                                <option value="family-planning">Family Planning</option>
                                <option value="follow-up">Follow-up Visit</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <select id="doctor" required>
                                <option value="">Preferred Doctor</option>
                                <option value="dr-santos">Dr. Maria Santos</option>
                                <option value="dr-reyes">Dr. Juan Reyes</option>
                                <option value="dr-cruz">Dr. Ana Cruz</option>
                                <option value="dr-garcia">Dr. Pedro Garcia</option>
                                <option value="any">Any Available Doctor</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <select id="preferredDate" required>
                                <option value="">Preferred Date</option>
                                <option value="2025-10-01">October 1, 2025</option>
                                <option value="2025-10-02">October 2, 2025</option>
                                <option value="2025-10-03">October 3, 2025</option>
                                <option value="2025-10-04">October 4, 2025</option>
                                <option value="2025-10-05">October 5, 2025</option>
                                <option value="2025-10-08">October 8, 2025</option>
                                <option value="2025-10-09">October 9, 2025</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <select id="preferredTime" required>
                                <option value="">Preferred Time</option>
                                <option value="08:00">8:00 AM</option>
                                <option value="09:00">9:00 AM</option>
                                <option value="10:00">10:00 AM</option>
                                <option value="11:00">11:00 AM</option>
                                <option value="13:00">1:00 PM</option>
                                <option value="14:00">2:00 PM</option>
                                <option value="15:00">3:00 PM</option>
                                <option value="16:00">4:00 PM</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Confirmation Section -->
                <div class="confirmation-section">
                    <h3 class="section-title">Confirmation</h3>
                    <div class="checkbox-container">
                        <input type="checkbox" id="confirmCheckbox" required>
                        <label for="confirmCheckbox">I confirm that the information provided is correct.</label>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" id="submitBtn">Book Appointment</button>
                    <button type="button" class="btn btn-secondary" onclick="cancelForm()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal" id="successModal">
        <div class="modal-content">
            <div class="modal-icon">✅</div>
            <h2>Appointment Booked Successfully!</h2>
            <p>Your appointment has been confirmed. You will receive a confirmation message shortly.</p>
            <button class="btn btn-primary" onclick="closeModal()">OK</button>
        </div>
    </div>

    <script>
        // Form Submission
        document.getElementById('appointmentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const fullName = document.getElementById('fullName').value;
            const dateOfBirth = document.getElementById('dateOfBirth').value;
            const contactNumber = document.getElementById('contactNumber').value;
            const reason = document.getElementById('reason').value;
            const doctor = document.getElementById('doctor').value;
            const preferredDate = document.getElementById('preferredDate').value;
            const preferredTime = document.getElementById('preferredTime').value;
            const confirmed = document.getElementById('confirmCheckbox').checked;

            if (!confirmed) {
                alert('Please confirm that the information provided is correct.');
                return;
            }

            // Validate all fields
            if (!fullName || !dateOfBirth || !contactNumber || !reason || !doctor || !preferredDate || !preferredTime) {
                alert('Please fill in all required fields.');
                return;
            }

            // Show success modal
            document.getElementById('successModal').classList.add('show');
            
            // Log appointment data
            console.log('Appointment Details:', {
                fullName,
                dateOfBirth,
                contactNumber,
                reason,
                doctor,
                preferredDate,
                preferredTime
            });
        });

        // Close Modal
        function closeModal() {
            document.getElementById('successModal').classList.remove('show');
            document.getElementById('appointmentForm').reset();
        }

        // Cancel Form
        function cancelForm() {
            if (confirm('Are you sure you want to cancel? All entered information will be lost.')) {
                document.getElementById('appointmentForm').reset();
                alert('Form cleared successfully.');
            }
        }

        // Toggle Notifications
        function toggleNotifications() {
            alert('You have 3 new notifications:\n1. Appointment reminder for tomorrow\n2. Lab results ready\n3. New health tip available');
        }

        // Logout Function
        function logout() {
            if(confirm('Are you sure you want to log out?')) {
                alert('Logging out...');
            }
        }

        // Enable/Disable Submit Button based on confirmation checkbox
        document.getElementById('confirmCheckbox').addEventListener('change', function() {
            const submitBtn = document.getElementById('submitBtn');
            if (this.checked) {
                submitBtn.disabled = false;
            } else {
                submitBtn.disabled = true;
            }
        });

        // Initialize submit button as disabled
        document.getElementById('submitBtn').disabled = true;
    </script>
</body>
</html>