<?php
session_start();
require_once 'db.php';
require_once 'admin_helpers_simple.php';
require_once 'residency_verification_helper.php';

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: Login.php');
    exit;
}

ensureResidencyVerificationSchema();

$pending = $pdo->query("
    SELECT r.*, 
           u.first_name, u.middle_name, u.last_name, u.email, u.contact_no, u.address, u.barangay, u.city
    FROM residency_verification_requests r
    JOIN users u ON u.id = r.user_id
    WHERE r.status = 'pending'
    ORDER BY r.submitted_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($pending as &$row) {
    $row['documents'] = getResidencyVerificationDocuments($row['id']);
}
unset($row);

// Recent audit log (all actions)
$audit = $pdo->query("
    SELECT a.*, u.first_name as patient_first, u.last_name as patient_last
    FROM residency_verification_audit_log a
    JOIN users u ON u.id = a.user_id
    ORDER BY a.created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Residency Verification - Admin - HealthServe</title>
    <link rel="stylesheet" href="assets/Style1.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .residency-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .residency-table th { background: #2e7d32; color: #fff; padding: 12px 16px; text-align: left; font-weight: 600; }
        .residency-table td { padding: 12px 16px; border-bottom: 1px solid #eee; }
        .residency-table tr:hover { background: #f5f5f5; }
        .doc-thumb { max-width: 120px; max-height: 90px; object-fit: contain; border: 1px solid #ddd; border-radius: 6px; cursor: pointer; }
        .doc-thumb:hover { opacity: 0.9; }
        .doc-list { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-start; }
        .doc-item { display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .doc-item a { font-size: 12px; color: #1976d2; }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 9999; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: #fff; padding: 24px; border-radius: 12px; max-width: 480px; width: 90%; }
        .modal-box h3 { margin-top: 0; margin-bottom: 16px; }
        .modal-box textarea { width: 100%; min-height: 100px; padding: 10px; border: 1px solid #ccc; border-radius: 6px; resize: vertical; }
        .modal-box select.modal-select { width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        .modal-actions { margin-top: 16px; display: flex; gap: 10px; justify-content: flex-end; }
        .btn-approve { background: #2e7d32; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; }
        .btn-reject { background: #c62828; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; }
        .btn-approve:hover, .btn-reject:hover { opacity: 0.9; }
        .img-preview-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.9); display: none; align-items: center; justify-content: center; z-index: 10000; cursor: pointer; }
        .img-preview-overlay.active { display: flex; }
        .img-preview-overlay img { max-width: 95%; max-height: 95%; object-fit: contain; }
        .audit-table { font-size: 13px; }
        .empty-state { text-align: center; padding: 40px 20px; color: #666; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="admin-profile">
            <div class="profile-info">
                <div class="profile-avatar"><i class="fas fa-user-shield"></i></div>
                <div class="profile-details">
                    <h3>Admin</h3>
                    <div class="profile-status">Online</div>
                </div>
            </div>
        </div>
        <nav class="nav-section">
            <div class="nav-title">General</div>
            <ul class="nav-menu">
                <li class="nav-item"><a href="Admin_dashboard1.php" class="nav-link"><div class="nav-icon"><i class="fas fa-th-large"></i></div>Dashboard</a></li>
                <li class="nav-item"><a href="admin_staff_management.php" class="nav-link"><div class="nav-icon"><i class="fas fa-user-friends"></i></div>Staffs</a></li>
                <li class="nav-item"><a href="admin_doctors_management.php" class="nav-link"><div class="nav-icon"><i class="fas fa-user-md"></i></div>Doctors</a></li>
                <li class="nav-item"><a href="admin_residency_verification.php" class="nav-link active"><div class="nav-icon"><i class="fas fa-id-card"></i></div>Residency Verification</a></li>
                <li class="nav-item"><a href="admin_announcements.php" class="nav-link"><div class="nav-icon"><i class="fas fa-bullhorn"></i></div>Announcements</a></li>
                <li class="nav-item"><a href="admin_notifications.php" class="nav-link"><div class="nav-icon"><i class="fas fa-bell"></i></div>Notifications</a></li>
                <li class="nav-item"><a href="admin_settings.php" class="nav-link"><div class="nav-icon"><i class="fas fa-cog"></i></div>Settings</a></li>
            </ul>
        </nav>
        <div class="logout-section">
            <a href="logout.php" class="logout-link"><div class="nav-icon"><i class="fas fa-sign-out-alt"></i></div>Logout</a>
        </div>
    </div>

    <div class="main-content">
        <header class="admin-header">
            <div class="header-title">
                <img src="assets/payatas logo.png" alt="Payatas B Logo" class="barangay-seal">
                <div>
                    <h1>HealthServe - Payatas B</h1>
                    <p>Residency Verification</p>
                </div>
            </div>
        </header>

        <div class="content-area">
            <div class="page-header">
                <h2 class="page-title">Payatas Residency Verification</h2>
            </div>

            <p style="margin-bottom: 1.5rem; color: #555;">Review and approve or reject patient residency verification requests. Only verified residents of Barangay Payatas, Quezon City can access HealthServe services.</p>

            <?php if (empty($pending)): ?>
            <div class="empty-state">
                <p><i class="fas fa-check-circle" style="font-size: 48px; color: #2e7d32;"></i></p>
                <p><strong>No pending verification requests.</strong></p>
            </div>
            <?php else: ?>
            <div class="content-card" style="margin-bottom: 2rem;">
                <h3 style="margin-top: 0;">Pending Requests (<?= count($pending) ?>)</h3>
                <div style="overflow-x: auto;">
                    <table class="residency-table">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Contact</th>
                                <th>Address</th>
                                <th>Submitted</th>
                                <th>Documents</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending as $row): 
                                $full_name = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                            ?>
                            <tr data-request-id="<?= (int)$row['id'] ?>">
                                <td><strong><?= htmlspecialchars($full_name) ?></strong><br><small><?= htmlspecialchars($row['email'] ?? '') ?></small></td>
                                <td><?= htmlspecialchars($row['contact_no'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['address'] ?? '') ?><br><?= htmlspecialchars(($row['barangay'] ?? '') . ', ' . ($row['city'] ?? '')) ?></td>
                                <td><?= date('M j, Y g:i A', strtotime($row['submitted_at'])) ?></td>
                                <td>
                                    <div class="doc-list">
                                        <?php foreach ($row['documents'] as $doc): 
                                            $view_url = 'view_residency_document.php?id=' . $doc['id'];
                                            $is_img = in_array(strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif']);
                                        ?>
                                        <div class="doc-item">
                                            <?php if ($is_img): ?>
                                            <img src="<?= htmlspecialchars($view_url) ?>" alt="Doc" class="doc-thumb doc-preview" data-src="<?= htmlspecialchars($view_url) ?>">
                                            <?php else: ?>
                                            <a href="<?= htmlspecialchars($view_url) ?>" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> <?= htmlspecialchars($doc['original_name'] ?? 'Document') ?></a>
                                            <?php endif; ?>
                                            <a href="<?= htmlspecialchars($view_url) ?>" target="_blank" rel="noopener">View / Zoom</a>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td>
                                    <button type="button" class="btn-approve btn-residency-approve" data-request-id="<?= (int)$row['id'] ?>">Approve</button>
                                    <button type="button" class="btn-reject btn-residency-reject" data-request-id="<?= (int)$row['id'] ?>">Reject</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="content-card">
                <h3 style="margin-top: 0;">Verification Audit Log</h3>
                <div style="overflow-x: auto;">
                    <table class="residency-table audit-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Patient</th>
                                <th>Admin</th>
                                <th>Action</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($audit as $a): ?>
                            <tr>
                                <td><?= date('M j, Y g:i A', strtotime($a['created_at'])) ?></td>
                                <td><?= htmlspecialchars(trim(($a['patient_first'] ?? '') . ' ' . ($a['patient_last'] ?? ''))) ?></td>
                                <td><?= htmlspecialchars($a['admin_name'] ?? '') ?></td>
                                <td><span style="color: <?= $a['action'] === 'approved' ? '#2e7d32' : '#c62828' ?>;"><?= htmlspecialchars(ucfirst($a['action'])) ?></span></td>
                                <td><?= htmlspecialchars($a['reason'] ?? '—') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="rejectModal">
        <div class="modal-box">
            <h3>Reject verification</h3>
            <p>Select a reason for rejection. The patient will see this reason in their notifications.</p>
            <label for="rejectReasonSelect" style="display:block; margin-bottom: 6px; font-weight: 600; color: #333;">Reason</label>
            <select id="rejectReasonSelect" class="modal-select" style="width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px;">
                <option value="">— Select a reason —</option>
                <option value="Address not within Payatas">Address not within Payatas</option>
                <option value="Invalid or expired ID">Invalid or expired ID</option>
                <option value="ID image unclear or unreadable">ID image unclear or unreadable</option>
                <option value="Document does not show Payatas address">Document does not show Payatas address</option>
                <option value="Document is not an accepted ID type">Document is not an accepted ID type</option>
                <option value="other">Other</option>
            </select>
            <div id="rejectReasonOtherWrap" style="display: none; margin-top: 12px;">
                <label for="rejectReasonOther" style="display: block; margin-bottom: 6px; font-weight: 600; color: #333;">Specify reason</label>
                <input type="text" id="rejectReasonOther" placeholder="Enter reason..." style="width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 6px;">
            </div>
            <input type="hidden" id="rejectRequestId" value="">
            <div class="modal-actions">
                <button type="button" class="btn-secondary" id="rejectModalCancel">Cancel</button>
                <button type="button" class="btn-reject" id="rejectModalConfirm">Reject</button>
            </div>
        </div>
    </div>

    <div class="img-preview-overlay" id="imgPreviewOverlay">
        <img src="" alt="Preview" id="imgPreviewImg">
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var rejectModal = document.getElementById('rejectModal');
        var rejectReasonSelect = document.getElementById('rejectReasonSelect');
        var rejectReasonOtherWrap = document.getElementById('rejectReasonOtherWrap');
        var rejectReasonOther = document.getElementById('rejectReasonOther');
        var rejectRequestId = document.getElementById('rejectRequestId');
        var imgOverlay = document.getElementById('imgPreviewOverlay');
        var imgPreviewImg = document.getElementById('imgPreviewImg');

        rejectReasonSelect.addEventListener('change', function() {
            rejectReasonOtherWrap.style.display = this.value === 'other' ? 'block' : 'none';
            if (this.value !== 'other') rejectReasonOther.value = '';
        });

        document.querySelectorAll('.btn-residency-approve').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.getAttribute('data-request-id');
                if (!confirm('Approve this residency verification? The patient will be able to use all HealthServe services.')) return;
                sendAction('approve', id, '');
            });
        });
        document.querySelectorAll('.btn-residency-reject').forEach(function(btn) {
            btn.addEventListener('click', function() {
                rejectRequestId.value = this.getAttribute('data-request-id');
                rejectReasonSelect.value = '';
                rejectReasonOther.value = '';
                rejectReasonOtherWrap.style.display = 'none';
                rejectModal.classList.add('active');
            });
        });
        document.getElementById('rejectModalCancel').addEventListener('click', function() {
            rejectModal.classList.remove('active');
        });
        document.getElementById('rejectModalConfirm').addEventListener('click', function() {
            var selected = rejectReasonSelect.value;
            var reason = '';
            if (selected === 'other') {
                reason = rejectReasonOther.value.trim();
                if (!reason) {
                    alert('Please specify the rejection reason.');
                    return;
                }
            } else if (selected) {
                reason = selected;
            }
            if (!reason) {
                alert('Please select a rejection reason.');
                return;
            }
            sendAction('reject', rejectRequestId.value, reason);
            rejectModal.classList.remove('active');
        });
        imgOverlay.addEventListener('click', function() {
            imgOverlay.classList.remove('active');
        });
        document.querySelectorAll('.doc-preview').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.preventDefault();
                imgPreviewImg.src = this.getAttribute('data-src') || this.src;
                imgOverlay.classList.add('active');
            });
        });

        function sendAction(action, requestId, reason) {
            var formData = new FormData();
            formData.append('action', action);
            formData.append('request_id', requestId);
            if (reason) formData.append('reason', reason);
            fetch('admin_residency_verification_action.php', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert(data.message || 'Action failed.');
                    }
                })
                .catch(function() { alert('Network error.'); });
        }
    });
    </script>
</body>
</html>
