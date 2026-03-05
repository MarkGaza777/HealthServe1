# Appointment Email Notifications

This document describes the email notification system for patient appointments.

## Features

The system sends email notifications to patients for the following appointment events:

1. **Appointment Approved** - Sent when an appointment is approved by a doctor or FDO
2. **Appointment Declined** - Sent when an appointment is declined by a doctor or FDO
3. **Appointment Rescheduled** - Sent when a patient reschedules their appointment
4. **Appointment Reminder** - Sent approximately 12 hours before the scheduled appointment time (only for approved appointments)

## Email Content

All notification emails include:
- Appointment Date
- Appointment Time
- Appointment Status
- Assigned Doctor Name
- Health Center Name

## Setup Instructions

### 1. Database Migration

Run the migration to create the reminder tracking table:

```sql
-- Run the SQL from migrations/add_appointment_email_reminders.sql
```

Or execute:
```bash
mysql -u username -p database_name < migrations/add_appointment_email_reminders.sql
```

### 2. Email Configuration

Ensure that `email_config.php` is properly configured with your SMTP settings. If you haven't created it yet:

1. Copy `email_config.example.php` to `email_config.php`
2. Update the SMTP settings with your email provider credentials

See `SETUP_PASSWORD_RESET.md` or `NEXT_STEPS.md` for detailed email configuration instructions.

### 3. Cron Job Setup

The reminder emails are sent automatically via a scheduled task (cron job). Set up the cron job to run every hour:

#### Linux/Unix (crontab)

```bash
# Edit crontab
crontab -e

# Add this line (adjust the path to your PHP and script location)
0 * * * * /usr/bin/php /path/to/healthyc/send_appointment_reminders.php >> /path/to/healthyc/logs/reminder_cron.log 2>&1
```

#### Windows Task Scheduler

1. Open Task Scheduler
2. Create a new task
3. Set trigger to run every hour
4. Set action to run:
   ```
   php.exe C:\path\to\healthyc\send_appointment_reminders.php
   ```
5. Save the task

#### Manual Testing

You can also run the reminder script manually from the command line:

```bash
php send_appointment_reminders.php
```

## How It Works

### Immediate Notifications

When an appointment status changes (approved, declined, or rescheduled), the system immediately:
1. Updates the appointment status in the database
2. Sends an email notification to the patient
3. Logs the action

### Reminder Emails

The reminder system:
1. Runs every hour via cron job
2. Finds approved appointments that are approximately 12 hours away (between 11.5 and 12.5 hours)
3. Checks if a reminder has already been sent (to avoid duplicates)
4. Sends reminder emails to patients who have email addresses
5. Records that the reminder was sent in the `appointment_email_reminders` table

## Files

- `appointment_email_service.php` - Core email service with all email templates
- `send_appointment_reminders.php` - Cron job script for sending reminders
- `migrations/add_appointment_email_reminders.sql` - Database migration for reminder tracking

## Integration Points

Email notifications are automatically sent from:
- `doctor_appointment_actions.php` - When doctor approves/declines appointments
- `fdo_appointment_actions.php` - When FDO approves/declines appointments
- `reschedule_appointment.php` - When patient reschedules an appointment

## Troubleshooting

### Emails Not Sending

1. Check that PHPMailer is installed:
   ```bash
   composer require phpmailer/phpmailer
   ```

2. Verify `email_config.php` exists and has correct SMTP credentials

3. Check PHP error logs for email sending errors

4. Test email configuration by checking if password reset emails work

### Reminders Not Sending

1. Verify the cron job is running:
   ```bash
   # Check cron logs
   tail -f /var/log/cron.log
   ```

2. Check that the reminder tracking table exists:
   ```sql
   SHOW TABLES LIKE 'appointment_email_reminders';
   ```

3. Manually run the reminder script to see errors:
   ```bash
   php send_appointment_reminders.php
   ```

4. Check PHP error logs for any issues

### Duplicate Emails

The system prevents duplicate reminders by:
- Using a unique constraint on `appointment_id` and `reminder_type` in the tracking table
- Checking if a reminder was already sent before sending

If you still receive duplicates, check:
- Multiple cron jobs running the same script
- Database constraint issues

## Email Templates

All email templates are HTML-formatted and include:
- Professional styling
- Health center branding
- Clear appointment information
- Contact information

Templates are defined in `appointment_email_service.php`:
- `getApprovedEmailBody()` - Green theme
- `getDeclinedEmailBody()` - Red theme
- `getRescheduledEmailBody()` - Blue theme
- `getReminderEmailBody()` - Orange theme

## Notes

- Reminder emails are only sent for appointments with status 'approved'
- Patients must have a valid email address in the `users` table
- The system logs all email sending attempts for debugging
- Failed email sends are logged but don't prevent the appointment action from completing

