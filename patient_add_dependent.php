<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'patient') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = (int) $_SESSION['user']['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
    exit;
}

$first_name = trim($_POST['first_name'] ?? '');
$middle_name = trim($_POST['middle_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$date_of_birth = trim($_POST['date_of_birth'] ?? '');
$relationship = trim($_POST['relationship'] ?? '');
$address = '';
$contact_no = trim($_POST['contact_no'] ?? '');
if ($contact_no !== '' && (strlen($contact_no) !== 11 || !ctype_digit($contact_no))) {
    echo json_encode(['success' => false, 'error' => 'Contact number dapat eksaktong 11 digits, numbers lang.']);
    exit;
}
$medical_other = trim($_POST['medical_other'] ?? '');
$sex = trim($_POST['sex'] ?? 'Prefer not to say');

// Medical history: from multi-select (medical_history[]) and optional "Other" text.
// When "Other" is selected and user types a disease, save only that disease text (no "Other" word on record).
$medical_history = '';
if (isset($_POST['medical_history']) && is_array($_POST['medical_history'])) {
    $parts = array_map('trim', array_filter($_POST['medical_history']));
    if (in_array('Other', $parts) && $medical_other !== '') {
        $parts = array_values(array_filter($parts, function ($p) { return $p !== 'Other'; }));
        $parts[] = $medical_other;
    }
    $medical_history = implode(', ', $parts);
} elseif (isset($_POST['medical_history']) && is_string($_POST['medical_history'])) {
    $medical_history = trim($_POST['medical_history']);
}

$allowed_relationships = ['Son', 'Daughter', 'Spouse', 'Parent', 'Sibling', 'Grandparent', 'Others'];
if (!in_array($relationship, $allowed_relationships)) {
    $relationship = 'Others';
}

$allowed_sex = ['Male', 'Female', 'Prefer not to say'];
if (!in_array($sex, $allowed_sex)) {
    $sex = 'Prefer not to say';
}

if ($first_name === '' || $last_name === '' || $date_of_birth === '') {
    echo json_encode(['success' => false, 'error' => 'Full name and birthdate are required.']);
    exit;
}

$dob_ts = strtotime($date_of_birth);
if ($dob_ts === false) {
    echo json_encode(['success' => false, 'error' => 'Invalid birthdate.']);
    exit;
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $has_address = false;
    $has_contact = false;
    try {
        $res = $pdo->query("SHOW COLUMNS FROM dependents LIKE 'address'");
        $has_address = (bool)$res->fetch();
    } catch (Exception $e) {}
    try {
        $res = $pdo->query("SHOW COLUMNS FROM dependents LIKE 'contact_no'");
        $has_contact = (bool)$res->fetch();
    } catch (Exception $e) {}

    if ($has_address && $has_contact) {
        $stmt = $pdo->prepare('
            INSERT INTO dependents (patient_id, first_name, middle_name, last_name, relationship, date_of_birth, sex, medical_conditions, address, contact_no)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $user_id,
            $first_name,
            $middle_name ?: null,
            $last_name,
            $relationship,
            date('Y-m-d', $dob_ts),
            $sex,
            $medical_history ?: null,
            $address ?: null,
            $contact_no ?: null
        ]);
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO dependents (patient_id, first_name, middle_name, last_name, relationship, date_of_birth, sex, medical_conditions)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $user_id,
            $first_name,
            $middle_name ?: null,
            $last_name,
            $relationship,
            date('Y-m-d', $dob_ts),
            $sex,
            $medical_history ?: null
        ]);
    }

    $dependent_id = (int) $pdo->lastInsertId();
    $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);

    echo json_encode([
        'success' => true,
        'dependent_id' => $dependent_id,
        'full_name' => $full_name,
        'option_value' => 'dep_' . $dependent_id
    ]);
} catch (PDOException $e) {
    error_log('patient_add_dependent: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not save dependent. Please try again.']);
}
