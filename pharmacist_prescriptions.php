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
	<title>Prescriptions - Pharmacist</title>
	<link rel="stylesheet" href="assets/Style1.css">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
	<style>
		.patient-list-container {
			background: white;
			border-radius: 12px;
			padding: 24px;
			margin-bottom: 24px;
			box-shadow: 0 2px 8px rgba(0,0,0,0.1);
		}
		
		.patient-item {
			padding: 16px;
			border: 2px solid #E0E0E0;
			border-radius: 8px;
			margin-bottom: 12px;
			cursor: pointer;
			transition: all 0.3s ease;
			display: flex;
			justify-content: space-between;
			align-items: center;
		}
		
		.patient-item:hover {
			border-color: #66BB6A;
			background: #F1F8F4;
			transform: translateX(4px);
		}
		
		.patient-item.active {
			border-color: #2E7D32;
			background: #E8F5E9;
		}
		
		.patient-info h4 {
			margin: 0 0 4px 0;
			color: #333;
			font-size: 16px;
		}
		
		.patient-info p {
			margin: 0;
			color: #666;
			font-size: 14px;
		}
		
		.prescription-badge {
			background: #FF9800;
			color: white;
			padding: 4px 12px;
			border-radius: 12px;
			font-size: 12px;
			font-weight: 600;
		}
		
		.prescription-details-container {
			background: white;
			border-radius: 12px;
			padding: 24px;
			box-shadow: 0 2px 8px rgba(0,0,0,0.1);
			display: none;
		}
		
		.prescription-details-container.active {
			display: block;
		}
		
		.prescription-header {
			border-bottom: 2px solid #E0E0E0;
			padding-bottom: 16px;
			margin-bottom: 24px;
		}
		
		.prescription-header h3 {
			margin: 0 0 8px 0;
			color: #2E7D32;
			font-size: 20px;
		}
		
		.prescription-header p {
			margin: 4px 0;
			color: #666;
			font-size: 14px;
		}
		
		.medication-item {
			padding: 16px;
			border: 1px solid #E0E0E0;
			border-radius: 8px;
			margin-bottom: 12px;
			background: #F8F9FA;
		}
		
		.medication-item h4 {
			margin: 0 0 8px 0;
			color: #333;
			font-size: 16px;
		}
		
		.medication-details {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 12px;
			margin-top: 8px;
		}
		
		.medication-detail {
			display: flex;
			flex-direction: column;
		}
		
		.medication-detail label {
			font-size: 12px;
			color: #999;
			margin-bottom: 4px;
		}
		
		.medication-detail span {
			font-size: 14px;
			color: #333;
			font-weight: 500;
		}
		
		.dispense-section {
			margin-top: 24px;
			padding-top: 24px;
			border-top: 2px solid #E0E0E0;
		}
		
		.btn-dispense {
			background: #2E7D32;
			color: white;
			border: none;
			padding: 14px 32px;
			border-radius: 8px;
			font-size: 16px;
			font-weight: 600;
			cursor: pointer;
			display: inline-flex;
			align-items: center;
			gap: 8px;
			transition: all 0.3s ease;
		}
		
		.btn-dispense:hover {
			background: #388E3C;
			transform: translateY(-2px);
			box-shadow: 0 4px 12px rgba(46, 125, 50, 0.3);
		}
		
		.btn-dispense:disabled {
			background: #CCCCCC;
			cursor: not-allowed;
			transform: none;
		}
		
		.empty-state {
			text-align: center;
			padding: 60px 20px;
			color: #999;
		}
		
		.empty-state i {
			font-size: 64px;
			margin-bottom: 16px;
			color: #E0E0E0;
		}
		
		.search-container {
			position: relative;
			display: flex;
			align-items: center;
			background: white;
			border: 2px solid #E0E0E0;
			border-radius: 25px;
			padding: 8px 15px;
			margin-bottom: 20px;
			min-width: 300px;
		}
		
		.search-container i {
			color: #999;
			margin-right: 10px;
		}
		
		.search-container input {
			border: none;
			outline: none;
			background: transparent;
			flex: 1;
			font-size: 14px;
		}
		
		.status-badge.dispensed {
			background: #4CAF50;
			color: white;
		}
		
		.status-badge.pending {
			background: #FF9800;
			color: white;
		}
		
		.status-badge.external-issued {
			background: #E65100;
			color: white;
		}
		
		.medication-item.external-readonly {
			pointer-events: none;
			opacity: 0.95;
		}
		
		.prescription-list-header {
			margin-bottom: 16px;
			padding-bottom: 12px;
			border-bottom: 1px solid #E0E0E0;
		}
		
		.prescription-list-header h4 {
			margin: 0 0 8px 0;
			color: #2E7D32;
			font-size: 16px;
		}
		
		.prescription-cards {
			display: flex;
			flex-wrap: wrap;
			gap: 12px;
			margin-bottom: 24px;
		}
		
		.prescription-card {
			padding: 12px 16px;
			border: 2px solid #E0E0E0;
			border-radius: 8px;
			background: #FAFAFA;
			cursor: pointer;
			transition: all 0.2s ease;
			min-width: 180px;
		}
		
		.prescription-card:hover {
			border-color: #66BB6A;
			background: #F1F8F4;
		}
		
		.prescription-card.active {
			border-color: #2E7D32;
			background: #E8F5E9;
			box-shadow: 0 2px 8px rgba(46, 125, 50, 0.2);
		}
		
		.prescription-card .card-date {
			font-size: 13px;
			color: #666;
			margin-top: 4px;
		}
		
		.prescription-card .card-doctor {
			font-size: 12px;
			color: #888;
			margin-top: 2px;
		}
		
		.prescription-card .card-status {
			margin-top: 6px;
			font-size: 11px;
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
					<a href="pharmacist_prescriptions.php" class="nav-link active">
						<div class="nav-icon"><i class="fas fa-prescription"></i></div>
						Prescriptions
					</a>
				</li>
				<li class="nav-item">
					<a href="pharmacist_announcements.php" class="nav-link">
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
					<p>Prescriptions Management</p>
				</div>
			</div>
		</header>

		<div class="content-area">
			<div class="page-header">
				<h2 class="page-title">Prescriptions</h2>
			</div>

			<div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 24px;">
				<!-- Patient List -->
				<div class="patient-list-container">
					<h3 style="margin: 0 0 20px 0; color: #2E7D32;">Patients with Prescriptions</h3>
					
					<div class="search-container">
						<i class="fas fa-search"></i>
						<input type="text" id="patient-search" placeholder="Search patients..." onkeyup="searchPatients()">
					</div>
					
					<div id="patients-list">
						<div class="empty-state">
							<i class="fas fa-spinner fa-spin"></i>
							<p>Loading patients...</p>
						</div>
					</div>
				</div>

				<!-- Prescription Details -->
				<div class="prescription-details-container" id="prescription-details">
					<div class="empty-state">
						<i class="fas fa-file-prescription"></i>
						<p>Select a patient to view their prescription</p>
					</div>
				</div>
			</div>
		</div>
	</div>

	<script>
		let selectedPatientId = null;
		let selectedPatientName = null;
		let allPatients = [];
		let currentPrescriptions = [];  // list of prescriptions for selected patient
		let selectedPrescriptionId = null;

		// Load patients with prescriptions
		async function loadPatients() {
			try {
				const response = await fetch('pharmacist_get_prescriptions.php');
				const data = await response.json();
				
				if (data.success) {
					allPatients = data.patients;
					displayPatients(data.patients);
				} else {
					document.getElementById('patients-list').innerHTML = `
						<div class="empty-state">
							<i class="fas fa-exclamation-circle"></i>
							<p>${data.message || 'Error loading patients'}</p>
						</div>
					`;
				}
			} catch (error) {
				console.error('Error loading patients:', error);
				document.getElementById('patients-list').innerHTML = `
					<div class="empty-state">
						<i class="fas fa-exclamation-circle"></i>
						<p>Error loading patients. Please try again.</p>
					</div>
				`;
			}
		}

		function displayPatients(patients) {
			const container = document.getElementById('patients-list');
			
			if (patients.length === 0) {
				container.innerHTML = `
					<div class="empty-state">
						<i class="fas fa-user-injured"></i>
						<p>No patients with pending prescriptions</p>
					</div>
				`;
				return;
			}
			
			container.innerHTML = patients.map(patient => `
				<div class="patient-item" data-patient-id="${patient.patient_id}" data-patient-name="${escapeHtml(patient.patient_name)}">
					<div class="patient-info">
						<h4>${escapeHtml(patient.patient_name)}</h4>
						<p>${patient.prescription_count} prescription(s) pending</p>
					</div>
					<span class="prescription-badge">${patient.prescription_count}</span>
				</div>
			`).join('');
			
			// Add click event listeners
			container.querySelectorAll('.patient-item').forEach(item => {
				item.addEventListener('click', function() {
					const patientId = this.getAttribute('data-patient-id');
					const patientName = this.getAttribute('data-patient-name');
					selectPatient(patientId, patientName, this);
				});
			});
		}

		function searchPatients() {
			const query = document.getElementById('patient-search').value.toLowerCase();
			const filtered = allPatients.filter(patient => 
				patient.patient_name.toLowerCase().includes(query)
			);
			displayPatients(filtered);
		}

		async function selectPatient(patientId, patientName, clickedElement) {
			selectedPatientId = patientId;
			selectedPatientName = patientName;
			currentPrescriptions = [];
			selectedPrescriptionId = null;
			
			// Update active state on patient list
			document.querySelectorAll('.patient-item').forEach(item => {
				item.classList.remove('active');
			});
			if (clickedElement) {
				clickedElement.classList.add('active');
			}
			
			// Load list of prescriptions for this patient (no prescription_id)
			try {
				const response = await fetch(`pharmacist_get_patient_prescription.php?patient_id=${patientId}`);
				const data = await response.json();
				
				if (data.success) {
					if (data.prescriptions && data.prescriptions.length > 0) {
						currentPrescriptions = data.prescriptions;
						const name = data.patient_name || patientName;
						displayPrescriptionList(data.prescriptions, name);
						// Auto-load first prescription's full details
						await loadPrescriptionDetails(patientId, data.prescriptions[0].id, name);
					} else if (data.prescription) {
						// Backward compat: single prescription response
						currentPrescriptions = [data.prescription];
						const name = data.prescription.patient_name || patientName;
						displayPrescriptionList([data.prescription], name);
						const area = document.getElementById('prescription-detail-area');
						if (area) {
							area.innerHTML = buildPrescriptionDetailHtml(data.prescription, name);
							const btn = area.querySelector('.btn-dispense');
							if (btn) btn.onclick = function() { dispensePrescription(data.prescription.id); };
						}
					} else {
						document.getElementById('prescription-details').innerHTML = `
							<div class="empty-state">
								<i class="fas fa-file-prescription"></i>
								<p>No active prescription found for this patient</p>
							</div>
						`;
					}
				} else {
					document.getElementById('prescription-details').innerHTML = `
						<div class="empty-state">
							<i class="fas fa-exclamation-circle"></i>
							<p>${escapeHtml(data.message || 'Error loading prescriptions')}</p>
						</div>
					`;
				}
			} catch (error) {
				console.error('Error loading prescriptions:', error);
				document.getElementById('prescription-details').innerHTML = `
					<div class="empty-state">
						<i class="fas fa-exclamation-circle"></i>
						<p>Error loading prescriptions. Please try again.</p>
					</div>
				`;
			}
		}

		function displayPrescriptionList(prescriptions, patientName) {
			const container = document.getElementById('prescription-details');
			container.classList.add('active');
			
			const statusBadge = (pr) => {
				if (pr.status === 'completed') return '<span class="status-badge dispensed">Dispensed</span>';
				return '<span class="status-badge pending">Pending</span>';
			};
			
			const listHtml = `
				<div class="prescription-list-header">
					<h4><i class="fas fa-user"></i> ${escapeHtml(patientName)}</h4>
					<p style="margin: 0; color: #666; font-size: 14px;">${prescriptions.length} prescription(s) — click one to view details</p>
				</div>
				<div class="prescription-cards" id="prescription-cards">
					${prescriptions.map(pr => `
						<div class="prescription-card" data-prescription-id="${pr.id}" title="Click to view this prescription">
							<strong>Prescription #${pr.id}</strong>
							<div class="card-doctor">Dr. ${escapeHtml(pr.doctor_name || 'N/A')}</div>
							<div class="card-date">${formatDate(pr.date_issued || pr.created_at)}</div>
							<div class="card-status">${statusBadge(pr)}</div>
						</div>
					`).join('')}
				</div>
				<div id="prescription-detail-area"></div>
			`;
			container.innerHTML = listHtml;
			
			container.querySelectorAll('.prescription-card').forEach(card => {
				card.addEventListener('click', function() {
					const prescriptionId = this.getAttribute('data-prescription-id');
					loadPrescriptionDetails(selectedPatientId, prescriptionId, selectedPatientName);
					document.querySelectorAll('.prescription-card').forEach(c => c.classList.remove('active'));
					this.classList.add('active');
				});
			});
		}

		async function loadPrescriptionDetails(patientId, prescriptionId, patientName) {
			selectedPrescriptionId = prescriptionId;
			const detailArea = document.getElementById('prescription-detail-area');
			if (!detailArea) return;
			
			detailArea.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Loading prescription...</p></div>';
			
			try {
				const response = await fetch(`pharmacist_get_patient_prescription.php?patient_id=${patientId}&prescription_id=${prescriptionId}`);
				const data = await response.json();
				
				if (data.success && data.prescription) {
					const detailHtml = buildPrescriptionDetailHtml(data.prescription, patientName);
					detailArea.innerHTML = detailHtml;
					// Re-attach dispense button handler if present
					const btn = detailArea.querySelector('.btn-dispense');
					if (btn) {
						btn.onclick = function() { dispensePrescription(parseInt(prescriptionId, 10)); };
					}
					// Highlight the selected prescription card
					document.querySelectorAll('.prescription-card').forEach(c => {
						c.classList.toggle('active', c.getAttribute('data-prescription-id') === String(prescriptionId));
					});
				} else {
					detailArea.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>${escapeHtml(data.message || 'Failed to load prescription')}</p></div>`;
				}
			} catch (error) {
				console.error('Error loading prescription details:', error);
				detailArea.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading prescription. Please try again.</p></div>';
			}
		}

		function buildPrescriptionDetailHtml(prescription, patientName) {
			if (!prescription) return '';
			const medications = prescription.medications || prescription.items || [];
			const isExternal = (m) => m.is_external == 1 || m.is_external === true || m.is_external === '1';
			const allExternal = medications.length > 0 && medications.every(isExternal);
			const hasDispensable = medications.some(m => !isExternal(m));
			let statusClass, statusText;
			if (prescription.status === 'completed') {
				statusClass = 'dispensed';
				statusText = 'Dispensed';
			} else if (allExternal) {
				statusClass = 'external-issued';
				statusText = 'Issued – External Pharmacy';
			} else {
				statusClass = 'pending';
				statusText = 'Pending';
			}
			const showDispenseButton = prescription.status !== 'completed' && hasDispensable;
			return `
				<div class="prescription-header">
					<h3>Prescription Details</h3>
					<p><strong>Patient:</strong> ${escapeHtml(patientName)}</p>
					<p><strong>Doctor:</strong> ${escapeHtml(prescription.doctor_name || 'N/A')}</p>
					<p><strong>Date Issued:</strong> ${formatDate(prescription.date_issued || prescription.created_at)}</p>
					<p><strong>Status:</strong> <span class="status-badge ${statusClass}">${statusText}</span></p>
					${prescription.diagnosis ? `<p><strong>Diagnosis:</strong> ${escapeHtml(prescription.diagnosis)}</p>` : ''}
					${prescription.instructions ? `<p><strong>Instructions:</strong> ${escapeHtml(prescription.instructions)}</p>` : ''}
				</div>
				
				<div>
					<h4 style="margin: 0 0 16px 0; color: #2E7D32;">Medications</h4>
					${medications.length === 0 ? '<p style="color: #999; padding: 20px; text-align: center;">No medications listed</p>' : ''}
					${medications.map(med => {
						const medIsExternal = isExternal(med);
						// Use total_quantity if available, otherwise fall back to quantity (for inventory items)
						const totalQuantity = med.total_quantity > 0 ? med.total_quantity : (med.quantity || 1);
						return `
						<div class="medication-item ${medIsExternal ? 'external-readonly' : ''}" style="${medIsExternal ? 'border-left: 4px solid #FF9800; background: #FFF8E1;' : ''}">
							<h4>${escapeHtml(med.drug_name || med.medicine_name)}${(med.medicine_form && med.medicine_form.trim()) ? ' <span style="color: #555; font-weight: 500;">(' + escapeHtml(med.medicine_form) + ')</span>' : ''}${medIsExternal ? ' <span style="background: #FF9800; color: #fff; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 4px; margin-left: 8px;">External — To be bought outside the health center</span>' : ''}</h4>
							<div class="medication-details">
								${medIsExternal ? `
									<div class="medication-detail" style="background: #FFF3E0; padding: 8px; border-radius: 6px; border: 1px solid #FFE0B2;">
										<label style="color: #E65100; font-weight: 600;">Not dispensed here — patient buys from external pharmacy</label>
									</div>
								` : `
								<div class="medication-detail" style="background: #E8F5E9; padding: 8px; border-radius: 6px; border: 1px solid #C8E6C9;">
									<label style="color: #2E7D32; font-weight: 600;">Total Quantity to Dispense</label>
									<span style="font-weight: 700; color: #1B5E20; font-size: 18px;">${escapeHtml(totalQuantity)}</span>
								</div>
								`}
								${med.dosage ? `
									<div class="medication-detail">
										<label>Dosage</label>
										<span>${escapeHtml(med.dosage)}</span>
									</div>
								` : ''}
								${med.frequency ? `
									<div class="medication-detail">
										<label>Frequency (Times per Day)</label>
										<span>${escapeHtml(med.frequency)}</span>
									</div>
								` : ''}
								${med.timing_of_intake ? `
									<div class="medication-detail">
										<label>Timing of Intake</label>
										<span>${escapeHtml(med.timing_of_intake)}</span>
									</div>
								` : ''}
								${med.duration ? `
									<div class="medication-detail">
										<label>Duration</label>
										<span>${escapeHtml(med.duration)}</span>
									</div>
								` : ''}
								${med.instructions ? `
									<div class="medication-detail">
										<label>Instructions</label>
										<span>${escapeHtml(med.instructions)}</span>
									</div>
								` : ''}
								${med.category && !medIsExternal ? `
									<div class="medication-detail">
										<label>Category</label>
										<span>${escapeHtml(med.category)}</span>
									</div>
								` : ''}
							</div>
						</div>
					`}).join('')}
				</div>
				
				${showDispenseButton ? `
					<div class="dispense-section">
						<button class="btn-dispense" onclick="dispensePrescription(${prescription.id})">
							<i class="fas fa-check-circle"></i>
							Mark as Dispensed
						</button>
						<p style="margin-top: 12px; color: #666; font-size: 13px;">Only health center inventory medicines will be deducted. External medicines are not dispensed here.</p>
					</div>
				` : allExternal ? `
					<div class="dispense-section" style="border-top: 2px solid #E0E0E0; padding-top: 24px; margin-top: 24px;">
						<p style="color: #E65100; font-weight: 600;"><i class="fas fa-info-circle"></i> This prescription contains only external medicines. Nothing to dispense from health center inventory — patient buys these outside.</p>
					</div>
				` : ''}
			`;
		}

		async function dispensePrescription(prescriptionId) {
			if (!confirm('Mark this prescription as dispensed? Inventory medicines will be deducted from stock. External medicines (to be bought outside) are not dispensed here and will not affect inventory.')) {
				return;
			}
			
			const btn = document.querySelector('.btn-dispense');
			btn.disabled = true;
			btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
			
			try {
				const response = await fetch('pharmacist_dispense_prescription.php', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: `prescription_id=${prescriptionId}`
				});
				
				const data = await response.json();
				
				if (data.success) {
					alert('Prescription marked as dispensed successfully!');
					// Reload the prescription details
					if (selectedPatientId && selectedPatientName) {
						const clickedElement = document.querySelector(`[data-patient-id="${selectedPatientId}"]`);
						selectPatient(selectedPatientId, selectedPatientName, clickedElement);
					}
					// Reload patients list
					loadPatients();
				} else {
					alert('Error: ' + (data.message || 'Failed to dispense prescription'));
					btn.disabled = false;
					btn.innerHTML = '<i class="fas fa-check-circle"></i> Mark as Dispensed';
				}
			} catch (error) {
				console.error('Error dispensing prescription:', error);
				alert('Error dispensing prescription. Please try again.');
				btn.disabled = false;
				btn.innerHTML = '<i class="fas fa-check-circle"></i> Mark as Dispensed';
			}
		}

		function escapeHtml(text) {
			if (!text) return '';
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}

		function formatDate(dateString) {
			if (!dateString) return 'N/A';
			const date = new Date(dateString);
			return date.toLocaleDateString('en-US', { 
				year: 'numeric', 
				month: 'long', 
				day: 'numeric' 
			});
		}

		// Load patients on page load
		loadPatients();
	</script>

</body>
</html>

