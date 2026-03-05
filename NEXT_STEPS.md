# Next Steps After Installing Composer

## ✅ Step 1: Install PHPMailer (You may have just done this!)

Run this command in your project directory:
```bash
composer require phpmailer/phpmailer
```

This should create a `vendor` folder with PHPMailer.

## 📧 Step 2: Configure Email Settings

1. **Copy the example config file:**
   - Copy `email_config.example.php` 
   - Rename it to `email_config.php`
   - **Important:** Never commit `email_config.php` to git (it contains passwords!)

2. **Edit `email_config.php` with your email credentials:**

### For Gmail (Recommended for testing):
```php
<?php
return [
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_username' => 'your-email@gmail.com',  // Your Gmail address
    'smtp_password' => 'your-16-char-app-password',  // See instructions below
    'smtp_encryption' => 'tls',
    'from_email' => 'noreply@healthserve.ph',
    'from_name' => 'HealthServe - Payatas B',
];
```

### How to Get Gmail App Password:
1. Go to: https://myaccount.google.com/
2. Click **Security** in the left menu
3. Enable **2-Step Verification** (if not already enabled)
4. Go to: https://myaccount.google.com/apppasswords
5. Select:
   - App: **Mail**
   - Device: **Other (Custom name)** → Type "HealthServe"
6. Click **Generate**
7. Copy the 16-character password (it looks like: `abcd efgh ijkl mnop`)
8. Remove spaces and use it in `email_config.php`

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

## 🗄️ Step 3: Database Table (Auto-Created)

The `password_resets` table will be automatically created when someone requests a password reset. 

However, if you want to create it manually, run the SQL from `create_password_resets_table.sql` in your database.

## ✅ Step 4: Test the System

1. Go to: `http://localhost/healthyc - Copy/healthyc/Login.php`
2. Click **"Forgot Password?"** link
3. Enter an email address that exists in your `users` table
4. Check the email inbox for the reset link
5. Click the link and set a new password

## 🔍 Troubleshooting

### "PHPMailer not found" Error?
- Make sure you ran `composer require phpmailer/phpmailer` in the correct directory
- Check that `vendor/autoload.php` exists
- Verify you're in: `healthyc - Copy/healthyc/` directory

### Email Not Sending?
- ✅ Check `email_config.php` exists and has correct credentials
- ✅ For Gmail: Make sure you're using an **App Password**, not your regular password
- ✅ Check PHP error logs for SMTP connection errors
- ✅ Verify firewall isn't blocking port 587
- ✅ Test with a simple email first

### Token Not Working?
- Tokens expire after **1 hour**
- Each token can only be used **once**
- Make sure the `password_resets` table exists

## 📝 Quick Checklist

- [ ] PHPMailer installed (`vendor/autoload.php` exists)
- [ ] `email_config.php` created from example
- [ ] Email credentials configured in `email_config.php`
- [ ] Gmail App Password generated (if using Gmail)
- [ ] Test password reset flow
- [ ] Check email inbox for reset link

## 🎉 You're Done!

Once you complete these steps, the password reset system will be fully functional!

