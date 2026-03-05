<?php
session_start();
require 'db.php';

if (empty($_SESSION['user'])) {
    header('Location: Login.php');
    exit;
}

$user_id = $_SESSION['user']['id'];

// Get pending follow-up appointments
try {
    // Check if table exists
    $check_table = $pdo->query("SHOW TABLES LIKE 'follow_up_appointments'");
    if ($check_table->rowCount() == 0) {
        $followups = [];
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                f.*,
                a.start_datetime as original_appointment_date
            FROM follow_up_appointments f
            LEFT JOIN appointments a ON f.original_appointment_id = a.id
            WHERE (f.user_id = ? OR f.patient_id = ?)
            AND f.status = 'pending_patient_confirmation'
            ORDER BY f.proposed_datetime ASC
        ");
        $stmt->execute([$user_id, $user_id]);
        $followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $followups = [];
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Follow-Up Appointments - HealthServe</title>
    <link rel="stylesheet" href="assets/Style1.css">
    <style>
        .followup-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .followup-card h3 {
            color: #2E7D32;
            margin-bottom: 16px;
        }
        .option-card {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .option-card:hover {
            border-color: #4CAF50;
            background: #f1f8f4;
        }
        .option-card.selected {
            border-color: #4CAF50;
            background: #e8f5e9;
        }
        .option-date {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
        }
        .option-time {
            font-size: 16px;
            color: #666;
        }
        .btn-submit {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 12px 32px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 20px;
        }
        .btn-submit:hover {
            background: #388E3C;
        }
        .btn-submit:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <main style="max-width: 900px; margin: 40px auto; padding: 0 20px;">
        <h1 style="color: #2E7D32; margin-bottom: 30px;">Follow-Up Appointments</h1>
        
        <?php if (empty($followups)): ?>
            <div class="followup-card">
                <p style="color: #666; text-align: center; padding: 40px;">No pending follow-up appointments.</p>
            </div>
        <?php else: ?>
            <?php foreach ($followups as $followup): 
                $proposed_date = date('F j, Y', strtotime($followup['proposed_datetime']));
                $proposed_time = date('g:i A', strtotime($followup['proposed_datetime']));
                $original_date = $followup['original_appointment_date'] ? date('F j, Y', strtotime($followup['original_appointment_date'])) : 'N/A';
            ?>
                <div class="followup-card">
                    <h3>Follow-Up Appointment Request</h3>
                    <p style="color: #666; margin-bottom: 20px;">
                        <strong>Original Appointment:</strong> <?= htmlspecialchars($original_date) ?><br>
                        <?php if (!empty($followup['notes'])): ?>
                            <strong>Notes:</strong> <?= htmlspecialchars($followup['notes']) ?>
                        <?php endif; ?>
                    </p>
                    
                    <p style="font-weight: 600; margin-bottom: 16px; color: #333;">Please select your preferred date and time:</p>
                    
                    <form id="followupForm<?= $followup['id'] ?>" onsubmit="selectFollowUp(event, <?= $followup['id'] ?>)">
                        <input type="hidden" name="follow_up_id" value="<?= $followup['id'] ?>">
                        
                        <!-- Proposed Option -->
                        <div class="option-card" onclick="selectOption(<?= $followup['id'] ?>, 'proposed')">
                            <input type="radio" name="selected_option" value="proposed" id="option_proposed_<?= $followup['id'] ?>" required>
                            <label for="option_proposed_<?= $followup['id'] ?>" style="cursor: pointer; display: block;">
                                <div class="option-date"><?= $proposed_date ?></div>
                                <div class="option-time"><?= $proposed_time ?></div>
                            </label>
                        </div>
                        
                        <!-- Alternative Options -->
                        <?php if (!empty($followup['alternative_datetime_1'])): 
                            $alt1_date = date('F j, Y', strtotime($followup['alternative_datetime_1']));
                            $alt1_time = date('g:i A', strtotime($followup['alternative_datetime_1']));
                        ?>
                            <div class="option-card" onclick="selectOption(<?= $followup['id'] ?>, '1')">
                                <input type="radio" name="selected_option" value="1" id="option_1_<?= $followup['id'] ?>">
                                <label for="option_1_<?= $followup['id'] ?>" style="cursor: pointer; display: block;">
                                    <div class="option-date"><?= $alt1_date ?></div>
                                    <div class="option-time"><?= $alt1_time ?></div>
                                </label>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($followup['alternative_datetime_2'])): 
                            $alt2_date = date('F j, Y', strtotime($followup['alternative_datetime_2']));
                            $alt2_time = date('g:i A', strtotime($followup['alternative_datetime_2']));
                        ?>
                            <div class="option-card" onclick="selectOption(<?= $followup['id'] ?>, '2')">
                                <input type="radio" name="selected_option" value="2" id="option_2_<?= $followup['id'] ?>">
                                <label for="option_2_<?= $followup['id'] ?>" style="cursor: pointer; display: block;">
                                    <div class="option-date"><?= $alt2_date ?></div>
                                    <div class="option-time"><?= $alt2_time ?></div>
                                </label>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($followup['alternative_datetime_3'])): 
                            $alt3_date = date('F j, Y', strtotime($followup['alternative_datetime_3']));
                            $alt3_time = date('g:i A', strtotime($followup['alternative_datetime_3']));
                        ?>
                            <div class="option-card" onclick="selectOption(<?= $followup['id'] ?>, '3')">
                                <input type="radio" name="selected_option" value="3" id="option_3_<?= $followup['id'] ?>">
                                <label for="option_3_<?= $followup['id'] ?>" style="cursor: pointer; display: block;">
                                    <div class="option-date"><?= $alt3_date ?></div>
                                    <div class="option-time"><?= $alt3_time ?></div>
                                </label>
                            </div>
                        <?php endif; ?>
                        
                        <button type="submit" class="btn-submit" id="submitBtn<?= $followup['id'] ?>" disabled>Confirm Selection</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
    
    <script>
        function selectOption(followUpId, option) {
            const radio = document.getElementById('option_' + option + '_' + followUpId);
            if (radio) {
                radio.checked = true;
                const submitBtn = document.getElementById('submitBtn' + followUpId);
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
                
                // Update visual selection
                const form = document.getElementById('followupForm' + followUpId);
                const cards = form.querySelectorAll('.option-card');
                cards.forEach(card => card.classList.remove('selected'));
                event.currentTarget.classList.add('selected');
            }
        }
        
        async function selectFollowUp(event, followUpId) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            formData.append('action', 'select_followup');
            
            const submitBtn = document.getElementById('submitBtn' + followUpId);
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Processing...';
            }
            
            try {
                const response = await fetch('patient_followup_selection.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Follow-up appointment selected successfully! It is now pending doctor approval.');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to select follow-up'));
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Confirm Selection';
                    }
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error selecting follow-up appointment. Please try again.');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Confirm Selection';
                }
            }
        }
    </script>
</body>
</html>

