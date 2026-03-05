<?php
/**
 * Badge Number Helper Functions
 * Generates unique badge numbers for inventory items
 * Format: MED-YYYY-XXXX (e.g., MED-2025-0001)
 */

/**
 * Generate a unique badge number for a new inventory item
 * Format: MED-YYYY-XXXX
 * 
 * @param PDO $pdo Database connection
 * @return string Unique badge number
 */
function generateBadgeNumber($pdo) {
    $currentYear = date('Y');
    $prefix = "MED-{$currentYear}-";
    
    // Get the highest sequence number for the current year
    $stmt = $pdo->prepare("
        SELECT badge_number 
        FROM inventory 
        WHERE badge_number LIKE ? 
        ORDER BY badge_number DESC 
        LIMIT 1
    ");
    $stmt->execute([$prefix . '%']);
    $lastBadge = $stmt->fetchColumn();
    
    if ($lastBadge) {
        // Extract the sequence number from the last badge (e.g., "MED-2025-0001" -> 1)
        $parts = explode('-', $lastBadge);
        if (count($parts) === 3 && $parts[0] === 'MED' && $parts[1] === $currentYear) {
            $sequence = (int)$parts[2];
            $sequence++;
        } else {
            $sequence = 1;
        }
    } else {
        $sequence = 1;
    }
    
    // Format sequence number with leading zeros (4 digits)
    $formattedSequence = str_pad($sequence, 4, '0', STR_PAD_LEFT);
    
    return "{$prefix}{$formattedSequence}";
}

