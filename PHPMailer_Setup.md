# PHPMailer Setup Instructions

## Installation

### Option 1: Using Composer (Recommended)
1. Install Composer if you haven't already: https://getcomposer.org/
2. In your project root directory, run:
   ```bash
   composer require phpmailer/phpmailer
   ```

### Option 2: Manual Installation
1. Download PHPMailer from: https://github.com/PHPMailer/PHPMailer
2. Extract the files to a `vendor/PHPMailer` folder in your project
3. Update the require statements in `forgot_password.php`:
   ```php
   require_once 'vendor/PHPMailer/PHPMailer/src/PHPMailer.php';
   require_once 'vendor/PHPMailer/PHPMailer/src/SMTP.php';
   require_once 'vendor/PHPMailer/PHPMailer/src/Exception.php';
   ```

## Email Configuration

### For Gmail:
1. Go to your Google Account settings
2. Enable 2-Step Verification
3. Generate an App Password:
   - Go to: https://myaccount.google.com/apppasswords
   - Select "Mail" and your device
   - Copy the generated 16-character password
4. Update `forgot_password.php` with your credentials:
   ```php
   $mail->Username = 'your-email@gmail.com';
   $mail->Password = 'your-16-char-app-password';
   ```

### For Other Email Providers:
- **Outlook/Hotmail**: Use `smtp-mail.outlook.com`, Port 587
- **Yahoo**: Use `smtp.mail.yahoo.com`, Port 587
- **Custom SMTP**: Update Host, Port, and encryption settings accordingly

## Security Notes
- Never commit email credentials to version control
- Consider using environment variables for sensitive data
- Use App Passwords instead of your main account password
- The reset token expires after 1 hour for security

