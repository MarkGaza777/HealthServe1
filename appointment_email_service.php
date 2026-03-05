<?php
/**
 * Appointment Email Notification Service
 * Handles sending email notifications for appointment status changes and reminders
 */

require_once 'db.php';
require_once 'admin_helpers_simple.php';

// Check if PHPMailer is available
$phpmailer_loaded = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    $phpmailer_loaded = class_exists('PHPMailer\PHPMailer\PHPMailer');
}

/**
 * Get appointment details with patient, doctor, and health center information
 */
function getAppointmentDetails($appointment_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                a.id,
                a.user_id,
                a.patient_id,
                a.doctor_id,
                a.start_datetime,
                a.status,
                a.duration_minutes,
                a.notes,
                -- Patient information
                pu.email as patient_email,
                CONCAT(COALESCE(pu.first_name, ''), ' ', COALESCE(pu.middle_name, ''), ' ', COALESCE(pu.last_name, '')) as patient_name,
                -- Doctor information
                CONCAT(COALESCE(du.first_name, ''), ' ', COALESCE(du.middle_name, ''), ' ', COALESCE(du.last_name, '')) as doctor_name,
                d.specialization
            FROM appointments a
            LEFT JOIN users pu ON (a.user_id = pu.id OR a.patient_id = pu.id)
            LEFT JOIN doctors d ON a.doctor_id = d.id
            LEFT JOIN users du ON d.user_id = du.id
            WHERE a.id = ?
        ");
        $stmt->execute([$appointment_id]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($appointment) {
            // Clean up names
            $appointment['patient_name'] = trim(preg_replace('/\s+/', ' ', $appointment['patient_name']));
            $appointment['doctor_name'] = trim(preg_replace('/\s+/', ' ', $appointment['doctor_name']));
            if (!empty($appointment['doctor_name'])) {
                $appointment['doctor_name'] = 'Dr. ' . $appointment['doctor_name'];
                if (!empty($appointment['specialization'])) {
                    $appointment['doctor_name'] .= ' (' . $appointment['specialization'] . ')';
                }
            }
        }
        
        return $appointment;
    } catch (PDOException $e) {
        error_log("Error fetching appointment details: " . $e->getMessage());
        return null;
    }
}

/**
 * Send appointment notification email
 */
function sendAppointmentEmail($appointment_id, $notification_type) {
    global $pdo, $phpmailer_loaded;
    
    if (!$phpmailer_loaded) {
        error_log("PHPMailer not available. Email notification not sent for appointment ID: $appointment_id");
        return false;
    }
    
    // Get appointment details
    $appointment = getAppointmentDetails($appointment_id);
    if (!$appointment) {
        error_log("Appointment not found: $appointment_id");
        return false;
    }
    
    // Check if patient has email
    if (empty($appointment['patient_email'])) {
        error_log("Patient email not found for appointment ID: $appointment_id");
        return false;
    }
    
    // Get health center information
    $health_center = getHealthCenterInfo();
    
    // Format appointment date and time
    $appointment_datetime = new DateTime($appointment['start_datetime']);
    $appointment_date = $appointment_datetime->format('F d, Y');
    $appointment_time = $appointment_datetime->format('g:i A');
    
    // Determine email subject and body based on notification type
    $subject = '';
    $body = '';
    
    switch ($notification_type) {
        case 'approved':
            $subject = 'Appointment Approved - ' . $health_center['name'];
            $body = getApprovedEmailBody($appointment, $health_center, $appointment_date, $appointment_time);
            break;
            
        case 'declined':
            $subject = 'Appointment Declined - ' . $health_center['name'];
            $body = getDeclinedEmailBody($appointment, $health_center, $appointment_date, $appointment_time);
            break;
            
        case 'rescheduled':
            $subject = 'Appointment Rescheduled - ' . $health_center['name'];
            $body = getRescheduledEmailBody($appointment, $health_center, $appointment_date, $appointment_time);
            break;
            
        case 'reminder':
            $subject = 'Appointment Reminder - ' . $health_center['name'];
            $body = getReminderEmailBody($appointment, $health_center, $appointment_date, $appointment_time);
            break;
            
        default:
            error_log("Unknown notification type: $notification_type");
            return false;
    }
    
    // Load email configuration
    $email_config = [];
    if (file_exists(__DIR__ . '/email_config.php')) {
        $email_config = require __DIR__ . '/email_config.php';
    }
    
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = $email_config['smtp_host'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $email_config['smtp_username'] ?? 'your-email@gmail.com';
        $mail->Password = $email_config['smtp_password'] ?? 'your-app-password';
        $mail->SMTPSecure = ($email_config['smtp_encryption'] ?? 'tls') === 'ssl' 
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS 
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $email_config['smtp_port'] ?? 587;
        $mail->CharSet = 'UTF-8';
        
        // From address
        $from_email = $email_config['from_email'] ?? 'noreply@healthserve.ph';
        $from_name = $email_config['from_name'] ?? $health_center['name'];
        
        // Email content
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($appointment['patient_email'], $appointment['patient_name']);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        // Send email
        $mail->send();
        
        error_log("Appointment email sent successfully: Type=$notification_type, Appointment ID=$appointment_id, Email=" . $appointment['patient_email']);
        return true;
        
    } catch (Exception $e) {
        error_log("Error sending appointment email: " . $e->getMessage());
        return false;
    }
}

/**
 * Get email body for approved appointment
 */
function getApprovedEmailBody($appointment, $health_center, $appointment_date, $appointment_time) {
    $status = ucfirst($appointment['status']);
    $doctor_name = !empty($appointment['doctor_name']) ? $appointment['doctor_name'] : 'To be assigned';
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #4CAF50; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
            .content { background-color: #f9f9f9; padding: 30px; border: 1px solid #ddd; }
            .info-box { background-color: white; padding: 15px; margin: 15px 0; border-left: 4px solid #4CAF50; border-radius: 4px; }
            .info-row { margin: 10px 0; }
            .label { font-weight: bold; color: #555; }
            .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Appointment Approved</h2>
            </div>
            <div class='content'>
                <p>Dear " . htmlspecialchars($appointment['patient_name']) . ",</p>
                <p>Your appointment has been <strong>approved</strong>.</p>
                
                <div class='info-box'>
                    <div class='info-row'>
                        <span class='label'>Appointment Date:</span> " . htmlspecialchars($appointment_date) . "
                    </div>
                    <div class='info-row'>
                        <span class='label'>Appointment Time:</span> " . htmlspecialchars($appointment_time) . "
                    </div>
                    <div class='info-row'>
                        <span class='label'>Appointment Status:</span> " . htmlspecialchars($status) . "
                    </div>
                    <div class='info-row'>
                        <span class='label'>Assigned Doctor:</span> " . htmlspecialchars($doctor_name) . "
                    </div>
                    <div class='info-row'>
                        <span class='label'>Health Center:</span> " . htmlspecialchars($health_center['name']) . "
                    </div>
                </div>
                
                <p>Please arrive on time for your appointment. If you need to reschedule, please contact us at least 24 hours in advance.</p>
                <p>If you have any questions, please contact us at " . htmlspecialchars($health_center['contact']) . ".</p>
            </div>
            <div class='footer'>
                <p>" . htmlspecialchars($health_center['name']) . "</p>
                <p>" . htmlspecialchars($health_center['address']) . "</p>
                <p>Contact: " . htmlspecialchars($health_center['contact']) . "</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Get email body for declined appointment
 */
function getDeclinedEmailBody($appointment, $health_center, $appointment_date, $appointment_time) {
    $status = ucfirst($appointment['status']);
    $doctor_name = !empty($appointment['doctor_name']) ? $appointment['doctor_name'] : 'N/A';
    $decline_reason = !empty($appointment['notes']) ? htmlspecialchars($appointment['notes']) : 'No reason provided.';
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #f44336; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
            .content { background-color: #f9f9f9; padding: 30px; border: 1px solid #ddd; }
            .info-box { background-color: white; padding: 15px; margin: 15px 0; border-left: 4px solid #f44336; border-radius: 4px; }
            .info-row { margin: 10px 0; }
            .label { font-weight: bold; color: #555; }
            .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Appointment Declined</h2>
            </div>
            <div class='content'>
                <p>Dear " . htmlspecialchars($appointment['patient_name']) . ",</p>
                <p>We regret to inform you that your appointment has been <strong>declined</strong>.</p>
                
                <div class='info-box'>
                    <div class='info-row'>
                        <span class='label'>Appointment Date:</span> " . htmlspecialchars($appointment_date) . "
                    </div>
                    <div class='info-row'>
                        <span class='label'>Appointment Time:</span> " . htmlspecialchars($appointment_time) . "
                    </div>
                    <div class='info-row'>
                        <span class='label'>Appointment Status:</span> " . htmlspecialchars($status) . "
                    </div>
                    <div class='info-row'>
                        <span class='label'>Assigned Doctor:</span> " . htmlspecialchars($doctor_name) . "
                    </div>
                    <div class='info-row'>
                        <span class='label'>Health Center:</span> " . htmlspecialchars($health_center['name']) . "
                    </div>
                </div>
                
                <p><strong>Reason:</strong> " . $decline_reason . "</p>
                
                <p>Please feel free to book a new appointment at a different time. If you have any questions, please contact us at " . htmlspecialchars($health_center['contact']) . ".</p>
            </div>
            <div class='footer'>
                <p>" . htmlspecialchars($health_center['name']) . "</p>
                <p>" . htmlspecialchars($health_center['address']) . "</p>
                <p>Contact: " . htmlspecialchars($health_center['contact']) . "</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Get email body for rescheduled appointment
 */
function getRescheduledEmailBody($appointment, $health_center, $appointment_date, $appointment_time) {
    $status = ucfirst($appointment['status']);
    $doctor_name = !empty($appointment['doctor_name']) ? $appointment['doctor_name'] : 'To be assigned';
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #2196F3; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
            .content { background-color: #f9f9f9; padding: 30px; border: 1px solid #ddd; }
            .info-box { background-color: white; padding: 15px; margin: 15px 0; border-left: 4px solid #2196F3; border-radius: 4px; }
            .info-row { margin: 10px 0; }
            .label { font-weight: bold; color: #555; }
            .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Appointment Rescheduled</h2>
            </div>
            <div class='content'>
                <p>Dear " . htmlspecialchars($appointment['patient_name']) . ",</p>
                <p>Your appointment has been <strong>rescheduled</strong>.</p>
                
                <div class='info-box'>
                    <div class='info-row'>
                        <span class='label'>New Appointment Date:</span> " . htmlspecialchars($appointment_date) . "
                    </div>
                    <div class='info-row'>
                        <span class='label'>New Appointment Time:</span> " . htmlspecialchars($appointment_time) . "
                    </div>
                    <div class='info-row'>
                        <span class='label'>Appointment Status:</span> " . htmlspecialchars($status) . "
                    </div>
                    <div class='info-row'>
                        <span class='label'>Assigned Doctor:</span> " . htmlspecialchars($doctor_name) . "
                    </div>
                    <div class='info-row'>
                        <span class='label'>Health Center:</span> " . htmlspecialchars($health_center['name']) . "
                    </div>
                </div>
                
                <p>Please make note of the new date and time. If you need to make further changes, please contact us at least 24 hours in advance.</p>
                <p>If you have any questions, please contact us at " . htmlspecialchars($health_center['contact']) . ".</p>
            </div>
            <div class='footer'>
                <p>" . htmlspecialchars($health_center['name']) . "</p>
                <p>" . htmlspecialchars($health_center['address']) . "</p>
                <p>Contact: " . htmlspecialchars($health_center['contact']) . "</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Get email body for appointment reminder
 */
function getReminderEmailBody($appointment, $health_center, $appointment_date, $appointment_time) {
    $status = ucfirst($appointment['status']);
    $doctor_name = !empty($appointment['doctor_name']) ? $appointment['doctor_name'] : 'To be assigned';
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #FF9800; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
            .content { background-color: #f9f9f9; padding: 30px; border: 1px solid #ddd; }
            .info-box { background-color: white; padding: 15px; margin: 15px 0; border-left: 4px solid #FF9800; border-radius: 4px; }
            .info-row { margin: 10px 0; }
            .label { font-weight: bold; color: #555; }
            .reminder-note { background-color: #fff3cd; padding: 15px; margin: 15px 0; border-left: 4px solid #FF9800; border-radius: 4px; }
            .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Appointment Reminder</h2>
            </div>
            <div class='content'>
                <p>Dear " . htmlspecialchars($appointment['patient_name']) . ",</p>
                <p>This is a friendly reminder that you have an appointment scheduled.</p>
                
                <div class='info-box'>
                    <div class='info-row'>
                        <span class='label'>Appointment Date:</span> " . htmlspecialchars($appointment_date) . "
                    </div>
                    <div class='info-row'>
                        <span class='label'>Appointment Time:</span> " . htmlspecialchars($appointment_time) . "
                    </div>
                    <div class='info-row'>
                        <span class='label'>Appointment Status:</span> " . htmlspecialchars($status) . "
                    </div>
                    <div class='info-row'>
                        <span class='label'>Assigned Doctor:</span> " . htmlspecialchars($doctor_name) . "
                    </div>
                    <div class='info-row'>
                        <span class='label'>Health Center:</span> " . htmlspecialchars($health_center['name']) . "
                    </div>
                </div>
                
                <div class='reminder-note'>
                    <p><strong>⏰ Reminder:</strong> Your appointment is in approximately 12 hours. Please arrive on time.</p>
                </div>
                
                <p>If you need to reschedule or cancel, please contact us as soon as possible at " . htmlspecialchars($health_center['contact']) . ".</p>
            </div>
            <div class='footer'>
                <p>" . htmlspecialchars($health_center['name']) . "</p>
                <p>" . htmlspecialchars($health_center['address']) . "</p>
                <p>Contact: " . htmlspecialchars($health_center['contact']) . "</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

