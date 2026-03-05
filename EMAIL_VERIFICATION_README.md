# Email Verification with OTP

This system implements email verification using One-Time Password (OTP) codes for new patient registrations.

## Features

- **OTP Generation**: 6-digit numeric codes generated for each signup
- **Email Delivery**: OTP codes sent via email using PHPMailer
- **15-minute Expiration**: OTP codes expire after 15 minutes for security
- **Resend Functionality**: Users can request a new OTP code if needed
- **Login Protection**: Patients cannot log in until their email is verified
- **Automatic Setup**: Database tables and columns are created automatically

## Setup Instructions

### 1. Run the Setup Script

Navigate to `setup_email_verification.php` in your browser to automatically create the required database tables and columns.

Alternatively, you can run the SQL file manually:
```bash
mysql -u your_username -p your_database < create_email_verifications_table.sql
```

### 2. Configure Email Settings

Make sure you have `email_config.php` configured with your SMTP settings. If you don't have it yet:

1. Copy `email_config.example.php` to `email_config.php`
2. Update with your email credentials (Gmail, Outlook, etc.)
3. For Gmail, you'll need to generate an App Password

See `NEXT_STEPS.md` or `SETUP_PASSWORD_RESET.md` for detailed email configuration instructions.

### 3. Verify PHPMailer is Installed

The system requires PHPMailer. If not already installed:
```bash
composer require phpmailer/phpmailer
```

## How It Works

### Sign Up Flow

1. User fills out the signup form with all required information
2. System creates the user account with `email_verified = 0` (unverified)
3. System generates a 6-digit OTP code
4. OTP is stored in `email_verifications` table with 15-minute expiration
5. OTP is sent to the user's email address
6. User is redirected to `verify_email.php`

### Verification Flow

1. User enters the 6-digit OTP code received via email
2. System validates the OTP:
   - Checks if OTP exists
   - Verifies it hasn't expired (15 minutes)
   - Ensures it hasn't been used before
3. Upon successful verification:
   - `email_verified` is set to `1` in users table
   - Verification record is marked as used
   - User is redirected to login page

### Login Flow

1. User attempts to log in with credentials
2. System checks if user is a patient
3. If patient and `email_verified = 0`:
   - Login is blocked
   - User is redirected to verification page
4. If verified or non-patient role:
   - Login proceeds normally

### Resend OTP

- Users can click "Resend Code" if they didn't receive the email
- A new OTP is generated and sent
- Previous OTPs remain in database but are marked as expired/used

## Database Schema

### email_verifications Table
- `id`: Primary key
- `user_id`: Foreign key to users table
- `email`: Email address being verified
- `otp_code`: 6-digit OTP code
- `expires_at`: Expiration timestamp (15 minutes from creation)
- `verified_at`: Timestamp when OTP was verified (NULL if unused)
- `created_at`: Creation timestamp

### users Table (New Column)
- `email_verified`: TINYINT(1), default 0
  - `0` = Email not verified
  - `1` = Email verified

## Security Features

- OTP codes expire after 15 minutes
- Each OTP can only be used once
- OTP codes are stored securely in database
- Email verification required only for patient role
- Admin, doctor, pharmacist, and FDO roles bypass verification

## Files Modified/Created

### New Files
- `verify_email.php` - OTP verification page
- `create_email_verifications_table.sql` - Database schema
- `setup_email_verification.php` - Setup script
- `EMAIL_VERIFICATION_README.md` - This file

### Modified Files
- `signup.php` - Added OTP generation and email sending
- `Login.php` - Added email verification check

## Troubleshooting

### OTP Not Received
1. Check spam/junk folder
2. Verify email configuration in `email_config.php`
3. Check server error logs for email sending errors
4. Use "Resend Code" button to generate a new OTP

### Cannot Login After Signup
- Make sure you've verified your email by entering the OTP code
- Check that `email_verified = 1` in the database for your user account
- If stuck, contact administrator to manually verify your account

### Database Errors
- Run `setup_email_verification.php` to ensure tables/columns exist
- Check database connection in `db.php`
- Verify foreign key constraints are properly set

## Notes

- Email verification is only enforced for patients
- Other roles (admin, doctor, etc.) can log in without verification
- The system automatically creates tables/columns on first use if setup script wasn't run
- OTP codes are numeric only (6 digits)
- Multiple OTP codes can exist for the same user (only the latest unexpired one should be used)

