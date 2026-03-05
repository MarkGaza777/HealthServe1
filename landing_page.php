<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HealthServe - Payatas B | We Care About Your Health</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
        }

        /* Header Styles */
        header {
            background: linear-gradient(135deg, #c8e6c9 0%, #f0f4f8 100%);
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        nav {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 2rem;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-size: 1.1rem;
            font-weight: 700;
            color: #2e7d32;
        }

        .logo-icon {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            border: 2px solid rgba(255,255,255,0.8);
            background: white;
        }

        .nav-links {
            display: flex;
            gap: 2rem;
            list-style: none;
        }

        .nav-links a {
            text-decoration: none;
            color: #333;
            font-weight: 500;
            transition: color 0.3s;
        }

        .nav-links a:hover {
            color: #2e7d32;
        }

        .auth-buttons {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .btn-auth {
            padding: 0.7rem 1.8rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(46, 125, 50, 0.15);
        }

        .btn-signup {
            background: #2e7d32;
            color: white;
            border: none;
        }

        .btn-signup:hover {
            background: #1b5e20;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(46, 125, 50, 0.3);
        }

        .btn-login {
            background: white;
            color: #2e7d32;
            border: 2px solid #2e7d32;
            box-shadow: none;
        }

        .btn-login:hover {
            background: rgba(46, 125, 50, 0.08);
            transform: translateY(-2px);
        }

        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, rgba(200, 230, 201, 0.8) 0%, rgba(240, 244, 248, 0.8) 100%), url('assets/payatasbhc.jpg');
            background-size: cover;
            background-position: center;
            padding: 4rem 2rem 2rem;
            min-height: 600px;
            position: relative;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.5);
        }

        .hero-content {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .hero-text h1 {
            font-size: 3.5rem;
            color: #1b5e20;
            margin-bottom: 1.5rem;
            line-height: 1.2;
        }

        .btn-consult {
            background: #2e7d32;
            color: white;
            padding: 1rem 2.5rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1.1rem;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(46, 125, 50, 0.3);
        }

        .btn-consult:hover {
            background: #1b5e20;
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(46, 125, 50, 0.4);
        }

        .hero-image {
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .doctor-illustration {
            width: 100%;
            max-width: 440px;
            height: auto;
            border-radius: 20px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.15);
        }

        .help-card {
            position: absolute;
            bottom: 10%;
            right: 5%;
            background: white;
            padding: 1.5rem;
            border-radius: 20px;
            box-shadow: 0 20px 45px rgba(0,0,0,0.15);
            border-left: 6px solid #4caf50;
            width: min(260px, 80%);
            animation: gentleFloat 6s ease-in-out infinite;
        }

        .help-title {
            font-size: 1.2rem;
            color: #1b5e20;
            font-weight: 700;
            margin: 0;
        }

        @keyframes gentleFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-6px); }
        }

        .carousel-dots {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #bbb;
            cursor: pointer;
            transition: all 0.3s;
        }

        .dot.active {
            background: #2e7d32;
            width: 30px;
            border-radius: 5px;
        }

        /* Services Section */
        .services {
            padding: 4rem 2rem;
            background: white;
        }

        .services-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .services h2 {
            text-align: center;
            font-size: 2.5rem;
            color: #1b5e20;
            margin-bottom: 3rem;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }

        .service-card {
            background: white;
            padding: 2rem 1.5rem;
            border-radius: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid #f0f4f8;
        }

        .service-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .service-card.active {
            border-color: #4caf50;
            box-shadow: 0 8px 25px rgba(76, 175, 80, 0.2);
        }

        .service-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .service-card h3 {
            color: #2e7d32;
            font-size: 1.1rem;
        }

        /* Announcements Section */
        .announcements {
            padding: 4rem 2rem;
            background: #fafafa;
        }

        .announcements-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .announcements h2 {
            text-align: center;
            font-size: 2.5rem;
            color: #1b5e20;
            margin-bottom: 3rem;
        }

        .announcements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }

        .announcement-card {
            background: linear-gradient(135deg, rgba(76, 175, 80, 0.8), rgba(46, 125, 50, 0.9));
            border-radius: 15px;
            padding: 3rem 2rem;
            color: white;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }

        .announcement-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 35px rgba(0,0,0,0.2);
        }

        .announcement-card h3 {
            font-size: 1.4rem;
            margin-bottom: 1rem;
        }

        .announcement-card p {
            font-size: 1rem;
            opacity: 0.95;
        }

        /* FAQ Section */
        .faq {
            padding: 4rem 2rem;
            background: white;
        }

        .faq-container {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            align-items: start;
        }

        .faq-header {
            text-align: center;
            grid-column: 1 / -1;
            margin-bottom: 2rem;
        }

        .faq-header p {
            color: #4caf50;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .faq-header h2 {
            font-size: 2.5rem;
            color: #1b5e20;
        }

        .faq-image {
            position: relative;
        }

        .faq-image img {
            width: 100%;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            object-fit: cover;
            max-height: 360px;
        }

        .care-badge {
            position: absolute;
            bottom: -20px;
            left: -20px;
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 50px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .faq-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .faq-item {
            background: white;
            border: 2px solid #f0f4f8;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s;
        }

        .faq-item:hover {
            border-color: #4caf50;
        }

        .faq-question {
            padding: 1.5rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            color: #333;
        }

        .faq-question:hover {
            background: #f9f9f9;
        }

        .faq-answer {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            padding: 0 1.5rem;
            color: #666;
        }

        .faq-item.active .faq-answer {
            max-height: 200px;
            padding: 0 1.5rem 1.5rem;
        }

        .faq-toggle {
            font-size: 1.5rem;
            color: #4caf50;
            transition: transform 0.3s;
        }

        .faq-item.active .faq-toggle {
            transform: rotate(45deg);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .hero-content {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .hero-text h1 {
                font-size: 2.5rem;
            }

            .nav-links {
                display: none;
            }

            .faq-container {
                grid-template-columns: 1fr;
            }

            .services-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <nav>
            <div class="logo">
                <img src="assets/payatas logo.png" alt="Payatas B Logo" class="logo-icon">
                <span>HealthServe - Payatas B</span>
            </div>
            <ul class="nav-links">
                <li><a href="#hero">Dashboard</a></li>
                <li><a href="#services">Our Services</a></li>
                <li><a href="#faq">FAQ's</a></li>
            </ul>
            <div class="auth-buttons">
                <a href="Login.php" class="btn-auth btn-login">Login</a>
                <a href="signup.php" class="btn-auth btn-signup">Sign Up</a>
            </div>
        </nav>
    </header>

    <!-- Hero Section -->
    <section class="hero" id="hero">
        <div class="hero-content">
            <div class="hero-text">
                <h1>We care about your health</h1>
                <button class="btn-consult" onclick="handleConsult()">Consult Now</button>
            </div>
            <div class="hero-image">
                <img src="assets/doctorate.png" alt="Doctorate illustration" class="doctor-illustration">
                <div class="help-card">
                    <p class="help-title">How can we help you today?</p>
                </div>
            </div>
        </div>
        <div class="carousel-dots">
            <div class="dot active"></div>
            <div class="dot"></div>
        </div>
    </section>

    <!-- Services Section -->
    <section class="services" id="services">
        <div class="services-container">
            <h2>Our Services</h2>
            <div class="services-grid">
                <div class="service-card" onclick="selectService(this)">
                    <div class="service-icon">💉</div>
                    <h3>Immunization</h3>
                </div>
                <div class="service-card" onclick="selectService(this)">
                    <div class="service-icon">🏥</div>
                    <h3>Hospital Referrals</h3>
                </div>
                <div class="service-card active" onclick="selectService(this)">
                    <div class="service-icon">👨‍⚕️</div>
                    <h3>Consultation</h3>
                </div>
                <div class="service-card" onclick="selectService(this)">
                    <div class="service-icon">👨‍👩‍👧‍👦</div>
                    <h3>Family Planning</h3>
                </div>
            </div>
        </div>
    </section>

    <!-- Announcements Section -->
    <section class="announcements">
        <div class="announcements-container">
            <h2>Announcements</h2>
            <div class="announcements-grid">
                <div class="announcement-card" onclick="showAnnouncement('immunization')">
                    <h3>Children Immunization Program</h3>
                    <p>Every Wednesday and Friday</p>
                </div>
                <div class="announcement-card" onclick="showAnnouncement('prenatal')">
                    <h3>Prenatal Psychology Training</h3>
                    <p>November 24, 2025 (2-4 pm)</p>
                </div>
                <div class="announcement-card" onclick="showAnnouncement('dengue')">
                    <h3>Anti-Dengue Fogging Drive</h3>
                    <p>November 25-27, 2025</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="faq" id="faq">
        <div class="faq-container">
            <div class="faq-header">
                <p>Get Your Answer</p>
                <h2>Frequently Asked Questions</h2>
            </div>
            <div class="faq-image">
                <img src="assets/payatasbhc.jpg" alt="Payatas B Health Center">
                <div class="care-badge">
                    <span style="font-size: 1.5rem;">😊</span>
                    <div>
                        <div style="font-weight: 600;">We care</div>
                        <div style="font-size: 0.9rem; color: #666;">about you</div>
                    </div>
                </div>
            </div>
            <div class="faq-list">
                <div class="faq-item" onclick="toggleFAQ(this)">
                    <div class="faq-question">
                        <span>How do I book an appointment?</span>
                        <span class="faq-toggle">+</span>
                    </div>
                    <div class="faq-answer">
                        You can book an appointment by logging in our website.
                    </div>
                </div>
                <div class="faq-item" onclick="toggleFAQ(this)">
                    <div class="faq-question">
                        <span>What are your operating hours?</span>
                        <span class="faq-toggle">+</span>
                    </div>
                    <div class="faq-answer">
                        We are open Monday to Friday from 8:00 AM to 5:00 PM. Special programs may have different schedules.
                    </div>
                </div>
                <div class="faq-item" onclick="toggleFAQ(this)">
                    <div class="faq-question">
                        <span>How do I get a medical certificate?</span>
                        <span class="faq-toggle">+</span>
                    </div>
                    <div class="faq-answer">
                        Medical certificates are issued after consultation with our doctors. Please bring a valid ID.
                    </div>
                </div>
                <div class="faq-item" onclick="toggleFAQ(this)">
                    <div class="faq-question">
                        <span>Can I get a prescription refill?</span>
                        <span class="faq-toggle">+</span>
                    </div>
                    <div class="faq-answer">
                        Yes, prescription refills require a brief consultation to ensure the medication is still appropriate for your condition.
                    </div>
                </div>
            </div>
        </div>
    </section>


    <script>
        // Service Selection
        function selectService(card) {
            document.querySelectorAll('.service-card').forEach(c => c.classList.remove('active'));
            card.classList.add('active');
        }

        // FAQ Toggle
        function toggleFAQ(item) {
            const isActive = item.classList.contains('active');
            document.querySelectorAll('.faq-item').forEach(i => i.classList.remove('active'));
            if (!isActive) {
                item.classList.add('active');
            }
        }

        // Button Handlers
        function handleConsult() {
            window.location.href = 'signup.php';
        }

        function showAnnouncement(type) {
            const messages = {
                immunization: 'Children Immunization Program runs every Wednesday and Friday. Please bring your child\'s immunization card.',
                prenatal: 'Prenatal Psychology Training on November 24, 2025 from 2-4 PM. Pre-registration required.',
                dengue: 'Anti-Dengue Fogging Drive from November 25-27, 2025. Please secure your pets and cover water containers.'
            };
            alert(messages[type]);
        }

        // Carousel dots animation
        let currentDot = 0;
        const dots = document.querySelectorAll('.dot');
        
        setInterval(() => {
            dots[currentDot].classList.remove('active');
            currentDot = (currentDot + 1) % dots.length;
            dots[currentDot].classList.add('active');
        }, 4000);
    </script>
</body>
</html>