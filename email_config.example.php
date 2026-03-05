<?php
/**
 * Email Configuration Template
 * 
 * Copy this file to email_config.php and update with your email settings
 * DO NOT commit email_config.php to version control
 */

return [
    'smtp_host' => 'smtp.gmail.com',        // Your SMTP server
    'smtp_port' => 587,                      // SMTP port (587 for TLS, 465 for SSL)
    'smtp_username' => 'your-email@gmail.com', // Your email address
    'smtp_password' => 'your-app-password',   // Your app password (not regular password)
    'smtp_encryption' => 'tls',              // 'tls' or 'ssl'
    'from_email' => 'noreply@healthserve.ph', // Sender email
    'from_name' => 'HealthServe - Payatas B', // Sender name
];

