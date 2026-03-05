<?php
/**
 * Pharmacy Inventory Encoder
 * Inserts all inventory items with specified quantities, reorder levels, and expiration dates
 */

session_start();
require_once 'db.php';
require_once 'badge_number_helper.php';

// Check if badge_number column exists, if not, create it
try {
    $checkStmt = $pdo->query("
        SELECT COUNT(*) 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'inventory' 
        AND COLUMN_NAME = 'badge_number'
    ");
    $columnExists = $checkStmt->fetchColumn() > 0;
    
    if (!$columnExists) {
        $pdo->exec("
            ALTER TABLE inventory
            ADD COLUMN badge_number VARCHAR(20) UNIQUE NULL AFTER id
        ");
        $pdo->exec("
            CREATE INDEX idx_badge_number ON inventory(badge_number)
        ");
    }
} catch (PDOException $e) {
    // Column might already exist, continue
}

// Function to generate random expiration date in 2029
function getRandomExpiryDate2029() {
    $start = strtotime('2029-01-01');
    $end = strtotime('2029-12-31');
    $random = mt_rand($start, $end);
    return date('Y-m-d', $random);
}

// Function to get random quantity (100 or 150)
function getRandomQuantity() {
    return (mt_rand(0, 1) == 0) ? 100 : 150;
}

// Inventory items data
$inventory_items = [
    // PAIN RELIEF / ANTIPYRETIC
    ['Paracetamol 500mg', 'Pain Relief', 'tablets', 'For pain and fever relief'],
    ['Ibuprofen 200mg', 'Pain Relief', 'tablets', 'Anti-inflammatory pain reliever'],
    ['Ibuprofen 400mg', 'Pain Relief', 'tablets', 'Moderate pain and inflammation'],
    ['Mefenamic Acid 500mg', 'Pain Relief', 'capsules', 'Menstrual and body pain'],
    ['Aspirin 300mg', 'Pain Relief', 'tablets', 'Pain and fever relief'],
    
    // ANTIBIOTIC
    ['Amoxicillin 250mg', 'Antibiotic', 'capsules', 'Antibiotic for infections'],
    ['Amoxicillin 500mg', 'Antibiotic', 'capsules', 'Antibiotic for bacterial infections'],
    ['Amoxicillin + Clavulanate', 'Antibiotic', 'tablets', 'Broad-spectrum antibiotic'],
    ['Cephalexin 500mg', 'Antibiotic', 'capsules', 'For skin and respiratory infections'],
    ['Azithromycin 500mg', 'Antibiotic', 'tablets', 'Antibiotic for respiratory infections'],
    ['Cotrimoxazole', 'Antibiotic', 'tablets', 'For urinary and respiratory infections'],
    ['Metronidazole 500mg', 'Antibiotic', 'tablets', 'For amoebiasis and anaerobic infections'],
    
    // COUGH / COLD / ALLERGY
    ['Lagundi Syrup', 'Medicine', 'bottle', 'Herbal cough relief'],
    ['Lagundi Tablet', 'Medicine', 'tablets', 'Herbal cough relief'],
    ['Cough Syrup', 'Medicine', 'bottle', 'For cough relief'],
    ['Carbocisteine', 'Medicine', 'capsules', 'Mucolytic for cough'],
    ['Ambroxol', 'Medicine', 'tablets', 'Expectorant'],
    ['Cetirizine 10mg', 'Allergy', 'tablets', 'Antihistamine for allergies'],
    ['Loratadine 10mg', 'Allergy', 'tablets', 'Non-drowsy allergy relief'],
    ['Phenylephrine', 'Allergy', 'tablets', 'Nasal decongestant'],
    
    // DIGESTIVE
    ['Oral Rehydration Solution', 'Emergency', 'sachets', 'For dehydration'],
    ['Loperamide 2mg', 'Digestive', 'tablets', 'For diarrhea'],
    ['Aluminum + Magnesium Hydroxide', 'Digestive', 'tablets', 'Antacid'],
    ['Omeprazole 20mg', 'Digestive', 'capsules', 'Acid reducer'],
    ['Domperidone', 'Digestive', 'tablets', 'Anti-nausea'],
    
    // VITAMINS / SUPPLEMENTS
    ['Vitamin C', 'Vitamin', 'tablets', 'Immune support'],
    ['Multivitamins', 'Vitamin', 'tablets', 'Daily nutritional supplement'],
    ['Ferrous Sulfate', 'Vitamin', 'tablets', 'Iron supplement'],
    ['Ferrous Sulfate + Folic Acid', 'Vitamin', 'tablets', 'For pregnant women'],
    ['Folic Acid', 'Vitamin', 'tablets', 'Prenatal supplement'],
    ['Vitamin A Capsule', 'Vitamin', 'capsules', 'For child nutrition'],
    ['Vitamin B-Complex', 'Vitamin', 'tablets', 'Nerve health'],
    
    // HYPERTENSION
    ['Amlodipine 5mg', 'Maintenance', 'tablets', 'For high blood pressure'],
    ['Losartan 50mg', 'Maintenance', 'tablets', 'Antihypertensive'],
    ['Captopril 25mg', 'Maintenance', 'tablets', 'Blood pressure control'],
    ['Metoprolol', 'Maintenance', 'tablets', 'Heart rate and BP control'],
    
    // DIABETES
    ['Metformin 500mg', 'Maintenance', 'tablets', 'Blood sugar control'],
    ['Gliclazide', 'Maintenance', 'tablets', 'For type 2 diabetes'],
    ['Insulin', 'Emergency', 'vial', 'Injectable diabetes medication'],
    
    // RESPIRATORY / ASTHMA
    ['Salbutamol Tablet', 'Emergency', 'tablets', 'Bronchodilator'],
    ['Salbutamol Nebule', 'Emergency', 'nebules', 'For nebulization'],
    ['Salbutamol Inhaler', 'Emergency', 'inhaler', 'Asthma relief'],
    ['Prednisone', 'Emergency', 'tablets', 'Anti-inflammatory steroid'],
    ['Montelukast', 'Maintenance', 'tablets', 'Asthma maintenance'],
    
    // DERMATOLOGIC
    ['Hydrocortisone Cream', 'Dermatologic', 'tube', 'Anti-itch and inflammation'],
    ['Betamethasone Cream', 'Dermatologic', 'tube', 'Skin inflammation'],
    ['Clotrimazole Cream', 'Dermatologic', 'tube', 'Antifungal'],
    ['Mupirocin Ointment', 'Dermatologic', 'tube', 'Topical antibiotic'],
    ['Calamine Lotion', 'Dermatologic', 'bottle', 'Skin itch relief'],
    
    // DEWORMING / PARASITIC
    ['Albendazole', 'Deworming', 'tablets', 'Anti-parasitic'],
    ['Mebendazole', 'Deworming', 'tablets', 'Deworming agent'],
    
    // MATERNAL / FAMILY PLANNING
    ['Trust Pills', 'Family Planning', 'blister pack', 'Oral contraceptive'],
    ['Injectable Contraceptive', 'Family Planning', 'vial', 'Birth control injection'],
    ['Condoms', 'Family Planning', 'pieces', 'Barrier contraception'],
    ['Oxytocin', 'Emergency', 'ampule', 'Labor medication'],
    ['Vitamin K', 'Emergency', 'ampule', 'Newborn care'],
    
    // FIRST AID / SUPPLIES
    ['Ethyl Alcohol', 'Supply', 'bottle', 'Antiseptic'],
    ['Povidone Iodine', 'Supply', 'bottle', 'Wound antiseptic'],
    ['Hydrogen Peroxide', 'Supply', 'bottle', 'Wound cleaning'],
    ['Normal Saline', 'Supply', 'bottle', 'Wound irrigation'],
];

$inserted = 0;
$updated = 0;
$errors = [];

try {
    $pdo->beginTransaction();
    
    foreach ($inventory_items as $item) {
        $item_name = $item[0];
        $category = $item[1];
        $unit = $item[2];
        $description = $item[3];
        $quantity = getRandomQuantity();
        $reorder_level = 10;
        $expiry_date = getRandomExpiryDate2029();
        
        // Check if item already exists (case-insensitive match)
        $check_stmt = $pdo->prepare("
            SELECT id, quantity, item_name, reorder_level, badge_number
            FROM inventory 
            WHERE LOWER(TRIM(item_name)) = LOWER(?)
            LIMIT 1
        ");
        $check_stmt->execute([$item_name]);
        $existing_item = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_item) {
            // Item exists - update with new values
            $update_stmt = $pdo->prepare("
                UPDATE inventory 
                SET quantity = ?,
                    category = ?,
                    unit = ?,
                    reorder_level = ?,
                    expiry_date = ?,
                    notes = ?
                WHERE id = ?
            ");
            
            $update_stmt->execute([
                $quantity,
                $category,
                $unit,
                $reorder_level,
                $expiry_date,
                $description,
                $existing_item['id']
            ]);
            
            $updated++;
        } else {
            // Item doesn't exist - create new record
            $badge_number = generateBadgeNumber($pdo);
            
            $stmt = $pdo->prepare("
                INSERT INTO inventory (badge_number, item_name, category, quantity, unit, reorder_level, expiry_date, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $badge_number,
                $item_name,
                $category,
                $quantity,
                $unit,
                $reorder_level,
                $expiry_date,
                $description
            ]);
            
            $inserted++;
        }
    }
    
    $pdo->commit();
    
    echo "<!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Inventory Items Inserted</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
                background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 2rem;
                margin: 0;
            }
            .container {
                background: white;
                border-radius: 12px;
                padding: 2rem;
                max-width: 800px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            }
            h1 {
                color: #1976D2;
                margin-top: 0;
            }
            .success {
                background: #e8f5e9;
                border-left: 4px solid #4caf50;
                padding: 1rem;
                margin: 1rem 0;
                border-radius: 4px;
            }
            .info {
                background: #fff3e0;
                border-left: 4px solid #ff9800;
                padding: 1rem;
                margin: 1rem 0;
                border-radius: 4px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 1rem;
            }
            th, td {
                padding: 0.75rem;
                text-align: left;
                border-bottom: 1px solid #e0e0e0;
            }
            th {
                background: #f5f5f5;
                font-weight: 600;
                color: #424242;
            }
            .badge {
                display: inline-block;
                padding: 0.25rem 0.75rem;
                border-radius: 12px;
                font-size: 0.875rem;
                font-weight: 600;
            }
            .badge-new {
                background: #4caf50;
                color: white;
            }
            .badge-updated {
                background: #ff9800;
                color: white;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <h1>✅ Inventory Items Successfully Processed</h1>
            <div class='success'>
                <strong>Total Items Processed:</strong> " . count($inventory_items) . "<br>
                <strong>New Items Inserted:</strong> <span class='badge badge-new'>{$inserted}</span><br>
                <strong>Existing Items Updated:</strong> <span class='badge badge-updated'>{$updated}</span>
            </div>
            <div class='info'>
                <strong>Note:</strong> All items have been assigned:<br>
                • Quantity: Randomly assigned 100 or 150<br>
                • Reorder Level: 10<br>
                • Expiration Date: Random valid date in 2029<br>
                • Badge Numbers: Auto-generated for new items
            </div>
            <p><a href='pharmacist_inventory.php' style='color: #1976D2; text-decoration: underline;'>View Inventory →</a></p>
        </div>
    </body>
    </html>";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <title>Error</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: #ffebee;
                padding: 2rem;
            }
            .error {
                background: white;
                padding: 2rem;
                border-radius: 8px;
                border-left: 4px solid #d32f2f;
            }
        </style>
    </head>
    <body>
        <div class='error'>
            <h1>Error</h1>
            <p>" . htmlspecialchars($e->getMessage()) . "</p>
        </div>
    </body>
    </html>";
}
?>
