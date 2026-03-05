<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pharmacist') {
	header("Location: Login.php");
	exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Announcements - Pharmacist</title>
	<link rel="stylesheet" href="assets/Style1.css">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
	<style>
		/* Modal Overlay */
		.announcement-modal-overlay {
			display: none;
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: rgba(0, 0, 0, 0.5);
			backdrop-filter: blur(4px);
			z-index: 2000;
			overflow-y: auto;
			padding: 40px 20px;
		}

		/* Modal Content Box */
		.announcement-modal-box {
			position: relative;
			background: white;
			max-width: 700px;
			margin: 0 auto;
			border-radius: 20px;
			padding: 50px 60px;
			box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
			animation: modalSlideIn 0.3s ease-out;
		}

		@keyframes modalSlideIn {
			from {
				opacity: 0;
				transform: translateY(-20px);
			}
			to {
				opacity: 1;
				transform: translateY(0);
			}
		}

		/* Modal Title */
		.announcement-modal-title {
			font-size: 28px;
			font-weight: 700;
			color: #1f2937;
			text-align: center;
			margin-bottom: 12px;
			line-height: 1.3;
		}

		/* Modal Subtitle */
		.announcement-modal-subtitle {
			font-size: 16px;
			color: #ff6b6b;
			text-align: center;
			margin-bottom: 8px;
			font-weight: 500;
		}

		/* Modal Start Date */
		.announcement-modal-date {
			font-size: 14px;
			color: #9ca3af;
			text-align: center;
			margin-bottom: 30px;
		}

		/* Modal Body Text */
		.announcement-modal-body {
			color: #333;
			font-size: 15px;
			line-height: 1.8;
			text-align: justify;
			margin-bottom: 20px;
		}

		.announcement-modal-body p {
			margin-bottom: 16px;
		}

		.announcement-modal-body ul {
			margin: 16px 0 16px 24px;
			padding: 0;
		}

		.announcement-modal-body li {
			margin-bottom: 8px;
			color: #333;
		}

		.announcement-modal-body strong {
			color: #1f2937;
		}

		/* Close Button */
		.announcement-modal-close {
			display: block;
			margin: 40px auto 0;
			background: #e5e7eb;
			color: #374151;
			border: none;
			padding: 14px 60px;
			border-radius: 10px;
			font-size: 16px;
			font-weight: 600;
			cursor: pointer;
			transition: all 0.3s ease;
		}

		.announcement-modal-close:hover {
			background: #d1d5db;
			transform: translateY(-2px);
			box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
		}

		@media (max-width: 768px) {
			.announcement-modal-box {
				padding: 40px 30px;
			}
			
			.announcement-modal-title {
				font-size: 24px;
			}
		}
	</style>
</head>
<body>
	<!-- Sidebar -->
	<div class="sidebar">
		<div class="admin-profile">
			<div class="profile-info">
				<div class="profile-avatar">
					<i class="fas fa-pills"></i>
				</div>
				<div class="profile-details">
					<h3>Pharmacist</h3>
					<div class="profile-status">Online</div>
				</div>
			</div>
		</div>

		<nav class="nav-section">
			<div class="nav-title">General</div>
			<ul class="nav-menu">
				<li class="nav-item">
					<a href="pharmacist_dashboard.php" class="nav-link">
						<div class="nav-icon"><i class="fas fa-th-large"></i></div>
						Dashboard
					</a>
				</li>
				<li class="nav-item">
					<a href="pharmacist_inventory.php" class="nav-link">
						<div class="nav-icon"><i class="fas fa-boxes"></i></div>
						Inventory
					</a>
				</li>
				<li class="nav-item">
					<a href="pharmacist_prescriptions.php" class="nav-link">
						<div class="nav-icon"><i class="fas fa-prescription"></i></div>
						Prescriptions
					</a>
				</li>
				<li class="nav-item">
					<a href="pharmacist_announcements.php" class="nav-link active">
						<div class="nav-icon"><i class="fas fa-bullhorn"></i></div>
						Announcements
					</a>
				</li>
			</ul>
		</nav>

		<div class="logout-section">
			<a href="logout.php" class="logout-link">
				<div class="nav-icon"><i class="fas fa-sign-out-alt"></i></div>
				Logout
			</a>
		</div>
	</div>

	<!-- Main Content -->
	<div class="main-content">
		<header class="admin-header">
			<div class="header-title">
				<img src="assets/payatas logo.png" alt="Payatas B Logo" class="barangay-seal">
				<div>
					<h1>HealthServe - Payatas B</h1>
					<p>Announcements</p>
				</div>
			</div>
		</header>

		<div class="content-area">
			<div class="page-header">
				<h2 class="page-title">Announcements</h2>
				<div class="breadcrumb">Dashboard > Announcements</div>
			</div>

			<!-- FDO Approval Reminder Banner -->
			<div id="fdoApprovalBanner" style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-bottom: 20px; border-radius: 4px; display: none;">
				<div style="display: flex; align-items: center; gap: 10px;">
					<i class="fas fa-info-circle" style="color: #856404; font-size: 20px;"></i>
					<div>
						<strong style="color: #856404;">Pending FDO Approval</strong>
						<p style="margin: 5px 0 0 0; color: #856404; font-size: 14px;">
							Your announcement has been submitted and is waiting for Front Desk Officer (FDO) approval before it can be published.
						</p>
					</div>
				</div>
			</div>

			<!-- Announcements Management -->
			<div class="table-container">
				<div class="table-header">
					<h3 style="color:#2E7D32;margin:0;">My Announcements</h3>
					<button class="btn btn-primary" onclick="openAnnouncementModal()">
						<i class="fas fa-plus"></i> Create Announcement
					</button>
				</div>
				<div style="padding:24px;">
					<table class="data-table" style="width: 100%; border-collapse: collapse;">
						<thead>
							<tr style="background: #f5f5f5;">
								<th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Title</th>
								<th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Category</th>
								<th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Date Posted</th>
								<th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Status</th>
								<th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Actions</th>
							</tr>
						</thead>
						<tbody id="announcementsTableBody">
							<tr><td colspan="5" style="text-align: center; padding: 30px;">Loading announcements...</td></tr>
						</tbody>
					</table>
				</div>
				
				<!-- Published Announcements Display -->
				<div style="padding:24px; margin-top: 20px;">
					<h3 style="color:#2E7D32;margin:0 0 16px 0;">Published Announcements</h3>
					<div id="publishedAnnouncementsContainer">
						<div style="text-align: center; padding: 30px; color: #666;">Loading published announcements...</div>
					</div>
				</div>
				
				<!-- Create Announcement Modal -->
				<div id="createAnnouncementModal" class="modal" style="display:none; position:fixed; inset:0; width:100%; height:100%; background:rgba(0,0,0,0.35); backdrop-filter: blur(6px); z-index:1000; overflow:auto;">
					<div class="modal-content" style="position:relative; background:#fff; border-radius:16px; padding:32px 28px; max-width:700px; width:92%; margin:60px auto; box-shadow:0 12px 40px rgba(0,0,0,0.25); max-height:90vh; overflow-y:auto;">
						<button type="button" aria-label="Close" onclick="closeCreateAnnouncementModal()" style="position:absolute; top:12px; right:12px; background:#F3F4F6; border:none; width:36px; height:36px; border-radius:50%; cursor:pointer;">&times;</button>
						<h2 style="margin-bottom:20px;">Add New Announcement</h2>
						
						<!-- FDO Approval Notice -->
						<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
							<div style="display: flex; align-items: center; gap: 10px;">
								<i class="fas fa-info-circle" style="color: #856404; font-size: 20px;"></i>
								<div>
									<strong style="color: #856404;">FDO Approval Required</strong>
									<p style="margin: 5px 0 0 0; color: #856404; font-size: 14px;">
										Your announcement will be submitted for Front Desk Officer (FDO) approval before it can be published. You will be notified once it's approved or rejected.
									</p>
								</div>
							</div>
						</div>

						<form id="announcementForm" onsubmit="submitAnnouncement(event)" enctype="multipart/form-data">
							<div style="margin-bottom:20px;">
								<label style="display:block; margin-bottom:8px; font-weight:600;">Announcement Title *</label>
								<input type="text" class="form-control" id="announcementTitle" name="title" maxlength="100" required placeholder="Enter announcement title..." style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;">
								<div style="font-size:12px; color:#666; margin-top:4px;"><span id="titleCharCount">0</span>/100 characters</div>
							</div>

							<div style="margin-bottom:20px;">
								<label style="display:block; margin-bottom:8px; font-weight:600;">Announcement Description / Content *</label>
								<textarea class="form-control" id="announcementContent" name="content" rows="6" required placeholder="Enter the full details of the announcement..." style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; resize:vertical;"></textarea>
							</div>

							<div style="margin-bottom:20px;">
								<label style="display:block; margin-bottom:8px; font-weight:600;">Category</label>
								<select class="form-control" id="announcementCategory" name="category" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;">
									<option value="General">General</option>
									<option value="Event">Event</option>
									<option value="Health Tip">Health Tip</option>
									<option value="Training">Training</option>
									<option value="Program">Program</option>
									<option value="Reminder">Reminder</option>
								</select>
							</div>

							<div style="margin-bottom:20px;">
								<label style="display:block; margin-bottom:8px; font-weight:600;">Attach Image *</label>
								<input type="file" class="form-control" id="announcementImage" name="image" accept="image/*" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;">
								<div style="font-size:12px; color:#666; margin-top:4px;">Accepted formats: JPG, PNG, GIF (Max 5MB)</div>
								<div id="imagePreview" style="margin-top:10px; display:none;">
									<img id="previewImg" src="" alt="Preview" style="max-width:100%; max-height:200px; border-radius:8px; border:1px solid #ddd;">
								</div>
							</div>

							<div style="margin-bottom:20px;">
								<label style="display:block; margin-bottom:8px; font-weight:600;">Schedule</label>
								<select class="form-control" id="announcementSchedule" name="schedule" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;">
									<option value="Not Applicable">Not Applicable</option>
									<option value="Every Monday | 8 AM - 12 NN">Every Monday | 8 AM - 12 NN</option>
									<option value="Every Tuesday | 8 AM - 12 NN">Every Tuesday | 8 AM - 12 NN</option>
									<option value="Every Wednesday | 8 AM - 12 NN">Every Wednesday | 8 AM - 12 NN</option>
									<option value="Every Thursday | 8 AM - 12 NN">Every Thursday | 8 AM - 12 NN</option>
									<option value="Every Friday | 8 AM - 12 NN">Every Friday | 8 AM - 12 NN</option>
									<option value="Every Saturday | 8 AM - 12 NN">Every Saturday | 8 AM - 12 NN</option>
									<option value="Every Sunday | 8 AM - 12 NN">Every Sunday | 8 AM - 12 NN</option>
									<option value="Every Monday & Wednesday | 8 AM - 12 NN">Every Monday & Wednesday | 8 AM - 12 NN</option>
									<option value="Every Tuesday & Thursday | 8 AM - 12 NN">Every Tuesday & Thursday | 8 AM - 12 NN</option>
									<option value="Every Wednesday & Friday | 8 AM - 12 NN">Every Wednesday & Friday | 8 AM - 12 NN</option>
									<option value="Every Monday, Wednesday & Friday | 8 AM - 12 NN">Every Monday, Wednesday & Friday | 8 AM - 12 NN</option>
									<option value="Custom">Custom (Enter below)</option>
								</select>
								<input type="text" class="form-control" id="announcementScheduleCustom" name="schedule_custom" placeholder="Enter custom schedule (e.g., Every Monday & Friday | 2 PM - 4 PM)" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; margin-top:10px; display:none;">
							</div>

							<div style="margin-bottom:20px;">
								<label style="display:block; margin-bottom:8px; font-weight:600;">Display Start Date & Time *</label>
								<input type="datetime-local" class="form-control" id="announcementStartDate" name="start_date" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;">
							</div>

							<div style="margin-bottom:20px;">
								<label style="display:block; margin-bottom:8px; font-weight:600;">End Date & Time *</label>
								<input type="datetime-local" class="form-control" id="announcementEndDate" name="end_date" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;">
							</div>

							<div style="display:flex; gap:10px; justify-content:flex-end; margin-top:30px;">
								<button type="button" class="btn" onclick="closeCreateAnnouncementModal()" style="padding:10px 20px; background:#f5f5f5; border:none; border-radius:4px; cursor:pointer;">Cancel</button>
								<button type="submit" class="btn btn-primary" style="padding:10px 20px; background:#4CAF50; color:white; border:none; border-radius:4px; cursor:pointer;">Submit for Approval</button>
							</div>
						</form>
					</div>
				</div>

				<!-- View Announcement Modal (NEW DESIGN) -->
				<div id="viewAnnouncementModal" class="announcement-modal-overlay">
					<div class="announcement-modal-box">
						<div id="viewAnnouncementModalBody"></div>
						<button class="announcement-modal-close" onclick="closeViewAnnouncementModal()">Close</button>
					</div>
				</div>
			</div>
		</div>

		<script>
			// Load announcements on page load
			document.addEventListener('DOMContentLoaded', function() {
				loadAnnouncements();
				
				// Character counter for title
				const titleInput = document.getElementById('announcementTitle');
				if (titleInput) {
					titleInput.addEventListener('input', function() {
						const counter = document.getElementById('titleCharCount');
						if (counter) {
							counter.textContent = this.value.length;
						}
					});
				}
			});

			function loadAnnouncements() {
				fetch('get_announcements.php')
					.then(response => response.json())
					.then(data => {
						if (data.success) {
							// My Announcements: Only show announcements created by the current user
							const myAnnouncements = data.my_announcements || [];
							
							// Deduplicate myAnnouncements by announcement_id (safety measure)
							const myAnnouncementsMap = new Map();
							myAnnouncements.forEach(ann => {
								if (ann.announcement_id && !myAnnouncementsMap.has(ann.announcement_id)) {
									myAnnouncementsMap.set(ann.announcement_id, ann);
								}
							});
							const deduplicatedMyAnnouncements = Array.from(myAnnouncementsMap.values());
							
							const tbody = document.getElementById('announcementsTableBody');
							if (tbody) {
								if (deduplicatedMyAnnouncements.length === 0) {
									tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 30px;">No announcements found</td></tr>';
								} else {
									tbody.innerHTML = deduplicatedMyAnnouncements.map(ann => {
										const date = new Date(ann.date_posted);
										let statusBadge = '';
										
										// Status badges: Pending (yellow), Published (green), Rejected (red)
										if (ann.status === 'approved') {
											statusBadge = '<span style="background:#4CAF50;color:white;padding:4px 12px;border-radius:12px;font-size:12px;">Published</span>';
										} else if (ann.status === 'pending') {
											statusBadge = '<span style="background:#ffc107;color:#856404;padding:4px 12px;border-radius:12px;font-size:12px;">Pending FDO Approval</span>';
										} else if (ann.status === 'rejected') {
											statusBadge = '<span style="background:#f44336;color:white;padding:4px 12px;border-radius:12px;font-size:12px;">Rejected</span>';
										}
										
										return `
											<tr>
												<td style="padding:12px; border-bottom:1px solid #eee;">${ann.title || 'N/A'}</td>
												<td style="padding:12px; border-bottom:1px solid #eee;">${ann.category || 'General'}</td>
												<td style="padding:12px; border-bottom:1px solid #eee;">${date.toLocaleDateString()}</td>
												<td style="padding:12px; border-bottom:1px solid #eee;">${statusBadge}</td>
												<td style="padding:12px; border-bottom:1px solid #eee;">
													<button onclick="viewAnnouncement(${ann.announcement_id})" style="background:none;border:none;color:#4CAF50;cursor:pointer;text-decoration:underline;margin-right:12px;">View</button>
													<button onclick="deleteAnnouncement(${ann.announcement_id})" style="background:none;border:none;color:#f44336;cursor:pointer;text-decoration:underline;">Delete</button>
												</td>
											</tr>
										`;
									}).join('');
									
									// Show banner if there are pending announcements
									const pendingCount = deduplicatedMyAnnouncements.filter(a => a.status === 'pending').length;
									const banner = document.getElementById('fdoApprovalBanner');
									if (banner) {
										banner.style.display = pendingCount > 0 ? 'block' : 'none';
									}
								}
							}
							
							// Load published announcements: include approved announcements from other users AND from current user
							// Get approved announcements from myAnnouncements (pharmacist's own published announcements)
							const myApprovedAnnouncements = deduplicatedMyAnnouncements.filter(a => a.status === 'approved');
							
							// Merge with published announcements from other users
							const allPublishedAnnouncements = [...(data.published_announcements || []), ...myApprovedAnnouncements];
							
							// Deduplicate by announcement_id to avoid showing the same announcement twice
							const publishedMap = new Map();
							allPublishedAnnouncements.forEach(ann => {
								if (ann.announcement_id && !publishedMap.has(ann.announcement_id)) {
									publishedMap.set(ann.announcement_id, ann);
								}
							});
							const deduplicatedPublished = Array.from(publishedMap.values());
							
							loadPublishedAnnouncements(deduplicatedPublished);
						}
					})
					.catch(error => {
						console.error('Error loading announcements:', error);
						const tbody = document.getElementById('announcementsTableBody');
						if (tbody) {
							tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 30px; color: red;">Error loading announcements</td></tr>';
						}
					});
			}

			function loadPublishedAnnouncements(publishedAnnouncements) {
				const container = document.getElementById('publishedAnnouncementsContainer');
				
				if (!container) return;
				
				if (!publishedAnnouncements || publishedAnnouncements.length === 0) {
					container.innerHTML = '<div style="text-align: center; padding: 30px; color: #666;">No published announcements yet</div>';
				} else {
					container.innerHTML = publishedAnnouncements.map(ann => {
						const date = new Date(ann.date_posted);
						const startDate = ann.start_date ? new Date(ann.start_date) : null;
						const endDate = ann.end_date ? new Date(ann.end_date) : null;
						
						// Schedule/Short description - match Pharmacist layout
						let scheduleText = '';
						if (ann.schedule && ann.schedule !== 'Not Applicable') {
							scheduleText = ann.schedule;
						} else if (startDate && endDate) {
							scheduleText = `${startDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })} | ${startDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })} - ${endDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}`;
						} else if (startDate) {
							scheduleText = `${startDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })} | ${startDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}`;
						} else {
							scheduleText = ann.category || 'General';
						}
						
						// Date information
						let dateInfo = '';
						if (startDate) {
							dateInfo = `Starting on ${startDate.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}`;
						} else {
							dateInfo = `Posted on ${date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}`;
						}
						
						return `
							<div style="background:#fff;border-radius:12px;padding:20px;margin-bottom:16px;box-shadow:0 4px 16px rgba(0,0,0,0.08);border-left:4px solid #66BB6A;">
								<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
									<div>
										<div style="font-size:18px;font-weight:700;color:#1f2937;">${ann.title || 'N/A'}</div>
										<div style="color:#6b7280;font-size:14px;">${scheduleText}</div>
									</div>
									<button class="btn btn-primary" type="button" onclick="viewAnnouncement(${ann.announcement_id})">View More</button>
								</div>
								<div style="color:#6b7280;font-size:13px;margin-top:8px;">${dateInfo}</div>
							</div>
						`;
					}).join('');
				}
			}

			function openAnnouncementModal() {
				document.getElementById('createAnnouncementModal').style.display = 'block';
				document.body.style.overflow = 'hidden';
				document.getElementById('announcementForm').reset();
				document.getElementById('titleCharCount').textContent = '0';
				document.getElementById('imagePreview').style.display = 'none';
				document.getElementById('announcementScheduleCustom').style.display = 'none';
				
				// Image preview handler
				const imageInput = document.getElementById('announcementImage');
				if (imageInput) {
					imageInput.onchange = function(e) {
						const file = e.target.files[0];
						if (file) {
							const reader = new FileReader();
							reader.onload = function(e) {
								document.getElementById('previewImg').src = e.target.result;
								document.getElementById('imagePreview').style.display = 'block';
							};
							reader.readAsDataURL(file);
						} else {
							document.getElementById('imagePreview').style.display = 'none';
						}
					};
				}
				
				// Schedule custom input handler
				const scheduleSelect = document.getElementById('announcementSchedule');
				if (scheduleSelect) {
					scheduleSelect.onchange = function() {
						const customInput = document.getElementById('announcementScheduleCustom');
						if (this.value === 'Custom') {
							customInput.style.display = 'block';
							customInput.required = true;
						} else {
							customInput.style.display = 'none';
							customInput.required = false;
							customInput.value = '';
						}
					};
				}
			}

			function closeCreateAnnouncementModal() {
				document.getElementById('createAnnouncementModal').style.display = 'none';
				document.body.style.overflow = '';
				document.getElementById('announcementForm').reset();
			}

			function submitAnnouncement(event) {
				event.preventDefault();
				
				const formData = new FormData(document.getElementById('announcementForm'));
				
				// Handle schedule field
				const scheduleSelect = document.getElementById('announcementSchedule');
				const scheduleCustom = document.getElementById('announcementScheduleCustom');
				if (scheduleSelect.value === 'Custom' && scheduleCustom.value) {
					formData.set('schedule', scheduleCustom.value);
				} else {
					formData.set('schedule', scheduleSelect.value);
				}
				
				fetch('submit_announcement.php', {
					method: 'POST',
					body: formData
				})
				.then(response => response.json())
				.then(data => {
					if (data.success) {
						alert(data.message);
						closeCreateAnnouncementModal();
						loadAnnouncements();
					} else {
						alert('Error: ' + data.message);
					}
				})
				.catch(error => {
					console.error('Error:', error);
					alert('Error submitting announcement');
				});
			}

			function viewAnnouncement(id) {
				fetch('get_announcements.php')
					.then(response => response.json())
					.then(data => {
						if (data.success) {
							const announcement = data.announcements.find(a => a.announcement_id == id);
							if (announcement) {
								const date = new Date(announcement.date_posted);
								const startDate = announcement.start_date ? new Date(announcement.start_date) : null;
								const endDate = announcement.end_date ? new Date(announcement.end_date) : null;
								
								let subtitle = '';
								let dateInfo = '';
								
								if (announcement.schedule && announcement.schedule !== 'Not Applicable') {
									subtitle = announcement.schedule;
								} else if (startDate && endDate) {
									subtitle = `${startDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })} - ${endDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}`;
								} else if (startDate) {
									subtitle = startDate.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
									dateInfo = `Starts on ${startDate.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}`;
								} else {
									subtitle = announcement.category || 'General';
									dateInfo = `Posted on ${date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}`;
								}
								
								let imageHtml = '';
								if (announcement.image_path) {
									imageHtml = `<div style="margin-bottom:20px;"><img src="${announcement.image_path}" alt="${announcement.title}" style="width:100%; max-height:400px; object-fit:cover; border-radius:12px; border:1px solid #ddd;"></div>`;
								}
								
								document.getElementById('viewAnnouncementModalBody').innerHTML = `
									${imageHtml}
									<div class="announcement-modal-title">${announcement.title}</div>
									<div class="announcement-modal-subtitle">${subtitle}</div>
									${dateInfo ? `<div class="announcement-modal-date">${dateInfo}</div>` : ''}
									<div class="announcement-modal-body">${formatAnnouncementContent(announcement.content)}</div>
								`;
								
								document.getElementById('viewAnnouncementModal').style.display = 'block';
								document.body.style.overflow = 'hidden';
							}
						}
					})
					.catch(error => {
						console.error('Error:', error);
						alert('Error loading announcement details');
					});
			}

			function closeViewAnnouncementModal() {
				document.getElementById('viewAnnouncementModal').style.display = 'none';
				document.body.style.overflow = '';
			}

			function showAnnouncementModal(type) {
				let title = '';
				let subtitle = '';
				let startDate = '';
				let content = '';
				
				switch(type) {
					case 'immunization':
						title = 'Children Immunization Program';
						subtitle = 'Every Wednesday & Friday, 8 AM - 12 NN';
						startDate = 'Starts on October 1, 2025 (Wednesday)';
						content = `
							<p>We will be conducting a Children's Immunization Program on October 1, 2025 (Wednesday) at the Barangay Payatas B Health Center.</p>
							<p>This program will provide free vaccines for children ages 0–5 years old, including:</p>
							<ul>
								<li>BCG</li>
								<li>DPT</li>
								<li>Polio</li>
								<li>Measles</li>
							</ul>
							<p>Parents and guardians are encouraged to bring their children's health cards for proper recording. Let's work together to keep our children healthy and protected.</p>
						`;
						break;
					case 'prenatal':
						title = 'Prenatal Psychology Training';
						subtitle = 'November 24, 2025 | 2 PM - 4 PM';
						startDate = '';
						content = `
							<p>Join us for a comprehensive prenatal psychology training session designed for expecting mothers and healthcare providers.</p>
							<p><strong>Topics covered:</strong></p>
							<ul>
								<li>Mental health during pregnancy</li>
								<li>Stress management techniques</li>
								<li>Bonding with your unborn child</li>
								<li>Postpartum preparation</li>
							</ul>
							<p>This training is free and open to all pregnant women and their support systems in the community.</p>
						`;
						break;
					case 'dengue':
						title = 'Anti-Dengue Fogging Drive';
						subtitle = 'November 25-27, 2025 | 8 AM - 11 AM';
						startDate = '';
						content = `
							<p>The barangay health unit will conduct a comprehensive anti-dengue fogging operation to eliminate mosquito breeding sites and reduce dengue risk in our community.</p>
							<p><strong>Areas to be covered:</strong></p>
							<ul>
								<li>All residential areas</li>
								<li>Public spaces and parks</li>
								<li>School premises</li>
								<li>Market areas</li>
							</ul>
							<p><strong>Please:</strong> Keep windows and doors closed during fogging operations and ensure pets are kept indoors for safety.</p>
						`;
						break;
				}
				
				document.getElementById('viewAnnouncementModalBody').innerHTML = `
					<div class="announcement-modal-title">${title}</div>
					<div class="announcement-modal-subtitle">${subtitle}</div>
					${startDate ? `<div class="announcement-modal-date">${startDate}</div>` : ''}
					<div class="announcement-modal-body">${content}</div>
				`;
				
				document.getElementById('viewAnnouncementModal').style.display = 'block';
				document.body.style.overflow = 'hidden';
			}

			function formatAnnouncementContent(content) {
				// Convert line breaks to paragraphs
				return '<p>' + content.replace(/\n\n/g, '</p><p>').replace(/\n/g, '<br>') + '</p>';
			}

			function deleteAnnouncement(id) {
				if (!confirm('Are you sure you want to delete this announcement? This action cannot be undone.')) {
					return;
				}
				
				fetch('delete_announcement.php', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ announcement_id: id })
				})
				.then(response => response.json())
				.then(data => {
					if (data.success) {
						alert(data.message);
						loadAnnouncements(); // Reload the announcements list
					} else {
						alert('Error: ' + (data.message || 'Failed to delete announcement'));
					}
				})
				.catch(error => {
					console.error('Error:', error);
					alert('Error deleting announcement');
				});
			}

			// Close modal when clicking outside
			window.addEventListener('click', function(e) {
				const modal = document.getElementById('viewAnnouncementModal');
				if (e.target === modal) {
					closeViewAnnouncementModal();
				}
			});
		</script>
			</div>
		</div>
	</div>

</body>
</html>