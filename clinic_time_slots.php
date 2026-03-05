<?php
/**
 * CLINIC_TIME_SLOTS - Master Time Slot Configuration
 * 
 * This is the SINGLE SOURCE OF TRUTH for all clinic time slots.
 * All pages must use this array - NO dynamic generation of time slots.
 * 
 * Format: Each slot is in 'HH:MM' format (24-hour)
 * Capacity: Each slot has 3 available appointments
 */

define('CLINIC_TIME_SLOTS', [
    '07:00',  // 07:00 AM (3 slots available)
    '07:30',  // 07:30 AM (3 slots available)
    '08:00',  // 08:00 AM (3 slots available)
    '08:30',  // 08:30 AM (3 slots available)
    '09:00',  // 09:00 AM (3 slots available)
    '09:30',  // 09:30 AM (3 slots available)
    '10:00',  // 10:00 AM (3 slots available)
    '10:30',  // 10:30 AM (3 slots available)
    '11:00',  // 11:00 AM (3 slots available)
    '11:30',  // 11:30 AM (3 slots available)
    '13:00',  // 01:00 PM (3 slots available)
    '13:30',  // 01:30 PM (3 slots available)
    '14:00',  // 02:00 PM (3 slots available)
    '14:30',  // 02:30 PM (3 slots available)
    '15:00'   // 03:00 PM (3 slots available)
]);

/**
 * Get clinic time slots as an array
 * @return array Array of time slots in 'HH:MM' format
 */
function getClinicTimeSlots() {
    return CLINIC_TIME_SLOTS;
}

/**
 * Get clinic time slots formatted for display (12-hour format)
 * @return array Array of time slots in 'g:i A' format (e.g., '7:00 AM')
 */
function getClinicTimeSlotsFormatted() {
    $formatted = [];
    foreach (CLINIC_TIME_SLOTS as $slot) {
        $time = DateTime::createFromFormat('H:i', $slot);
        if ($time) {
            $formatted[] = $time->format('g:i A');
        }
    }
    return $formatted;
}

/**
 * Get clinic time slots with full datetime format (HH:MM:SS)
 * @return array Array of time slots in 'HH:MM:SS' format
 */
function getClinicTimeSlotsWithSeconds() {
    $slots = [];
    foreach (CLINIC_TIME_SLOTS as $slot) {
        $slots[] = $slot . ':00';
    }
    return $slots;
}

/**
 * Check if a given time slot is valid (exists in master list)
 * @param string $time Time in 'HH:MM' or 'HH:MM:SS' format
 * @return bool True if valid, false otherwise
 */
function isValidClinicTimeSlot($time) {
    // Normalize to HH:MM format
    $time = substr($time, 0, 5);
    return in_array($time, CLINIC_TIME_SLOTS);
}

/**
 * Get slot capacity (always 3 per slot)
 * @return int Capacity per slot
 */
function getSlotCapacity() {
    return 3;
}
