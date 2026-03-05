<?php
/**
 * Returns true if the appointments table has an appointment_code column.
 *
 * @param PDO $pdo Database connection
 * @return bool
 */
function appointmentCodeColumnExists(PDO $pdo) {
    try {
        $check = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'appointment_code'");
        return $check->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Generates a unique appointment code in format HS-APPT-XXXXXX (alphanumeric).
 * Use when creating a new appointment. Ensures uniqueness against the appointments table.
 *
 * @param PDO $pdo Database connection
 * @return string Unique code e.g. HS-APPT-A1B2C3
 */
function generateUniqueAppointmentCode(PDO $pdo) {
    $prefix = 'HS-APPT-';
    $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $length = 6;
    $maxAttempts = 50;

    // Check if column exists (for backward compatibility during migration)
    try {
        $check = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'appointment_code'");
        if ($check->rowCount() === 0) {
            return $prefix . strtoupper(substr(bin2hex(random_bytes(4)), 0, $length));
        }
    } catch (PDOException $e) {
        return $prefix . strtoupper(substr(bin2hex(random_bytes(4)), 0, $length));
    }

    $stmt = $pdo->prepare('SELECT id FROM appointments WHERE appointment_code = ? LIMIT 1');

    for ($i = 0; $i < $maxAttempts; $i++) {
        $code = '';
        for ($j = 0; $j < $length; $j++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $fullCode = $prefix . $code;

        $stmt->execute([$fullCode]);
        if ($stmt->fetch() === false) {
            return $fullCode;
        }
    }

    // Fallback: use microtime + random to reduce collision
    $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, $length));
    return $prefix . $code;
}
