# Password Reset Setup Guide

## Step 1: Install PHPMailer via Composer

Navigate to your project directory in the terminal/command prompt and run:

```bash
cd "healthyc - Copy/healthyc"
composer require phpmailer/phpmailer
```

This will create a `vendor` folder with PHPMailer installed.

## Step 2: Configure Email Settings

1. Copy the example config file:
   - Copy `email_config.example.php` to `email_config.php`
   - **Important:** Do NOT commit `email_config.php` to version control (add it to .gitignore)

2. Edit `email_config.php` with your email credentials:

### For Gmail:
```php
<?php
return [
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_username' => 'your-email@gmail.com',  // Your Gmail address
    'smtp_password' => 'your-16-char-app-password',  // Gmail App Password (see below)
    'smtp_encryption' => 'tls',
    'from_email' => 'noreply@healthserve.ph',
    'from_name' => 'HealthServe - Payatas B',
];
```

### Getting Gmail App Password:
1. Go to your Google Account: https://myaccount.google.com/
2. Enable **2-Step Verification** (if not already enabled)
3. Go to **App Passwords**: https://myaccount.google.com/apppasswords
4. Select "Mail" and your device
5. Click "Generate"
6. Copy the 16-character password (no spaces) and use it in `email_config.php`

### For Other Email Providers:

**Outlook/Hotmail:**
```php
'smtp_host' => 'smtp-mail.outlook.com',
'smtp_port' => 587,
'smtp_encryption' => 'tls',
```

**Yahoo:**
```php
'smtp_host' => 'smtp.mail.yahoo.com',
'smtp_port' => 587,
'smtp_encryption' => 'tls',
```

## Step 3: Create Database Table (if not auto-created)

The table should be auto-created when someone requests a password reset, but you can also run the SQL manually:

Run the SQL from `create_password_resets_table.sql` in your database, or execute:

```sql
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    used TINYINT(1) DEFAULT 0,
    INDEX idx_token (token),
    INDEX idx_user (user_id),
    INDEX idx_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Step 4: Test the Password Reset

1. Go to the login page: `Login.php`
2. Click "Forgot Password?"
3. Enter a valid email address from your users table
4. Check the email inbox for the reset link
5. Click the link and set a new password

## Troubleshooting

### Email Not Sending?
- Check that `vendor/autoload.php` exists (Composer installed correctly)
- Verify email credentials in `email_config.php`
- Check PHP error logs for SMTP errors
- For Gmail: Make sure you're using an App Password, not your regular password
- Check firewall/security settings that might block SMTP

### "PHPMailer not found" Error?
- Make sure you ran `composer require phpmailer/phpmailer` in the correct directory
- Verify `vendor/autoload.php` exists
- Check file permissions

### Token Not Working?
- Tokens expire after 1 hour
- Each token can only be used once
- Check that the `password_resets` table exists in your database

## Security Notes

- Never commit `email_config.php` to version control
- Use App Passwords instead of regular passwords
- Tokens expire after 1 hour for security
- Old tokens are automatically cleaned up

