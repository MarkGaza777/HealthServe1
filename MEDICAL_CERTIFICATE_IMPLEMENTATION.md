# Medical Certificate Feature Implementation

## Overview
This implementation adds a comprehensive Medical Certificate feature that allows doctors to generate medical certificates for patients after consultations, with automatic expiration handling and PDF generation.

## Features Implemented

### 1. Database Schema
- **Migration File**: `migrations/add_medical_certificates.sql`
- **Table**: `medical_certificates`
- **Fields**:
  - `id`: Primary key
  - `patient_id`: Patient ID (from users or patients table)
  - `doctor_id`: Doctor ID (from doctors table)
  - `appointment_id`: Related appointment (optional)
  - `consultation_id`: Related consultation (optional)
  - `validity_period_days`: Certificate validity (7 or 14 days)
  - `issued_date`: Date when certificate was issued
  - `expiration_date`: Certificate expiration date
  - `status`: Certificate status (active/expired)
  - `created_at`: Timestamp

### 2. Backend Components

#### `doctor_medical_certificate_handler.php`
- Handles certificate generation requests from doctors
- Validates doctor access to patient records
- Supports certificate generation with 7 or 14 day validity
- Auto-calculates expiration dates
- Provides API endpoints:
  - `generate_certificate`: Create a new medical certificate
  - `get_certificates`: Retrieve certificates for a patient

#### `generate_medical_certificate_pdf.php`
- Generates professional PDF medical certificates
- Includes patient and doctor information
- Displays issued date and expiration date
- Shows diagnosis from consultation
- Automatically checks and updates expiration status
- Prevents patients from accessing expired certificates
- Accessible by both doctors and patients (with proper authorization)

#### `update_expired_certificates.php`
- Automated script to update expired certificates
- Can be run manually or via cron job
- Updates status of certificates past expiration date
- Recommended: Run daily via cron

### 3. Frontend Components

#### Doctor Consultation Page (`doctor_consultation.php`)
- Added Medical Certificate section with:
  - Validity period selector (7 or 14 days)
  - Generate Certificate button
  - Display of existing certificates
  - Real-time status updates
- Integrated with consultation workflow
- Auto-fills patient and doctor details
- Links certificates to appointments and consultations

#### Patient Records Page (`user_records.php`)
- Added Medical Certificates section displaying:
  - All certificates issued to the patient
  - Issued and expiration dates
  - Certificate status (Active/Expired)
  - Download PDF button for active certificates
  - Clear indication of expired certificates
- Automatically filters expired certificates from patient access
- Shows doctor information who issued the certificate

## Installation Steps

### 1. Run Database Migration
```bash
php run_medical_certificate_migration.php
```

Or manually execute the SQL file:
```sql
-- Run migrations/add_medical_certificates.sql
```

### 2. Set Up Automatic Expiration (Optional but Recommended)
Add to your cron jobs to run daily:
```bash
0 0 * * * php /path/to/update_expired_certificates.php
```

Or run manually when needed:
```bash
php update_expired_certificates.php
```

### 3. Verify Installation
1. Log in as a doctor
2. Open a patient consultation
3. Scroll to the "Medical Certificate" section
4. Select validity period and click "Generate Medical Certificate"
5. Verify the certificate appears in the list
6. Log in as the patient and check "My Records" page
7. Verify the certificate is visible and downloadable

## Usage Guide

### For Doctors

1. **Generate a Certificate**:
   - Open a patient consultation
   - Complete the consultation form
   - Scroll to "Medical Certificate" section
   - Select validity period (7 or 14 days)
   - Click "Generate Medical Certificate"
   - Certificate is automatically created with today's date

2. **View Generated Certificates**:
   - Certificates appear in the consultation page
   - Shows issued date, expiration date, and status
   - Click "View PDF" to download the certificate

### For Patients

1. **Access Certificates**:
   - Log in to patient portal
   - Go to "My Records" page
   - Scroll to "Medical Certificates" section
   - View all certificates issued to you

2. **Download Certificates**:
   - Active certificates have a "Download PDF" button
   - Expired certificates are marked and cannot be downloaded
   - PDF includes all certificate details and doctor signature

## Certificate Details

Each medical certificate includes:
- Patient name, age, and gender
- Issued date
- Expiration date
- Validity period (7 or 14 days)
- Diagnosis from consultation
- Doctor name and specialization
- Clinic information
- Professional formatting suitable for official use

## Expiration Handling

### Automatic Expiration
- Certificates automatically expire based on expiration date
- Status is updated to "expired" when accessed after expiration
- Expired certificates are hidden from patient download access
- Expiration check runs when:
  - Certificate PDF is accessed
  - Patient views records page
  - Automated script runs (if configured)

### Manual Expiration Update
Run the expiration update script:
```bash
php update_expired_certificates.php
```

## Security Features

1. **Access Control**:
   - Doctors can only generate certificates for patients they have access to
   - Patients can only view their own certificates
   - Expired certificates are blocked from patient access

2. **Data Validation**:
   - Validity period limited to 7 or 14 days
   - Patient and doctor information auto-validated
   - Appointment/consultation linking verified

## File Structure

```
healthyc/
├── migrations/
│   └── add_medical_certificates.sql
├── doctor_medical_certificate_handler.php
├── generate_medical_certificate_pdf.php
├── update_expired_certificates.php
├── run_medical_certificate_migration.php
├── doctor_consultation.php (modified)
├── user_records.php (modified)
└── MEDICAL_CERTIFICATE_IMPLEMENTATION.md (this file)
```

## Troubleshooting

### Certificate Not Generating
- Check database migration was run successfully
- Verify doctor has access to patient
- Check browser console for JavaScript errors
- Verify PHP error logs

### PDF Not Downloading
- Ensure TCPDF library is installed: `composer require tecnickcom/tcpdf`
- Check file permissions
- Verify certificate is not expired

### Expired Certificates Still Showing
- Run `update_expired_certificates.php` manually
- Check expiration date calculation
- Verify database connection

## Future Enhancements

Potential improvements:
- Email certificate to patient automatically
- Certificate templates customization
- Multiple validity period options
- Certificate revocation feature
- Digital signature integration
- Certificate history tracking

## Support

For issues or questions:
1. Check this documentation
2. Review error logs
3. Verify database schema
4. Test with a fresh consultation

---

**Implementation Date**: 2025
**Version**: 1.0

