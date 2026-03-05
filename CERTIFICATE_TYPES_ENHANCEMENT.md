# Medical Certificate Types Enhancement

## Overview
This enhancement adds selectable certificate types to the Medical Certificate feature, allowing doctors to generate specific types of medical certificates with appropriate content and fit/unfit status for work-related certificates.

## New Features

### 1. Certificate Types
The system now supports the following certificate categories:

#### Work-Related Certificates
- **Sick Leave**: For employees needing time off due to illness
- **Fit-to-Work**: Certifying fitness for work duties
- **Food Handler**: For food service workers
- **High-Risk Work**: For workers in high-risk occupations

#### Education Certificates
- **School Clearance**: For students returning to school after illness

#### Travel Certificates
- **Travel Clearance**: Medical clearance for travel purposes

#### Licensing & Permits
- **Driver's License**: Medical certificate for driver's license application
- **Professional License**: Medical certificate for professional license applications

#### General Certificates
- **General Health Check-up**: General medical clearance
- **PWD Registration**: Certificate for Persons with Disabilities registration

### 2. Fit/Unfit Status
For work-related certificates, doctors can now specify:
- **Fit to Work**: Patient is medically fit for work duties
- **Unfit to Work**: Patient is medically unfit for work duties

### 3. Dynamic PDF Content
PDF certificates now automatically adjust their content based on:
- Certificate type and subtype
- Fit/unfit status (for work-related certificates)
- Appropriate medical language for each certificate type

## Database Changes

### New Columns Added to `medical_certificates` Table:
- `certificate_type` (VARCHAR): Main category (work_related, education, travel, licensing, general)
- `certificate_subtype` (VARCHAR): Specific certificate type (sick_leave, fit_to_work, etc.)
- `fit_status` (ENUM): Fit or unfit status for work-related certificates

## Installation

### Step 1: Run the Migration
```bash
php run_certificate_types_migration.php
```

Or manually execute:
```sql
-- Run migrations/add_certificate_types.sql
```

### Step 2: Verify Installation
1. Log in as a doctor
2. Open a patient consultation
3. Scroll to "Medical Certificate" section
4. Verify certificate type dropdown appears
5. Select a work-related certificate type
6. Verify fit/unfit status dropdown appears
7. Generate a certificate and verify PDF content

## Usage Guide

### For Doctors

1. **Select Certificate Type**:
   - Open patient consultation
   - Scroll to "Medical Certificate" section
   - Select certificate type from dropdown
   - Options are organized by category

2. **For Work-Related Certificates**:
   - After selecting a work-related type, "Work Status" dropdown appears
   - Select "Fit to Work" or "Unfit to Work"
   - This is required for work-related certificates

3. **Select Validity Period**:
   - Choose 7 or 14 days validity
   - Default is 14 days

4. **Generate Certificate**:
   - Click "Generate Medical Certificate"
   - Certificate is created with appropriate content
   - PDF automatically reflects certificate type and status

### For Patients

1. **View Certificates**:
   - Log in to patient portal
   - Go to "My Records" page
   - Scroll to "Medical Certificates" section
   - Certificates display with their specific type and status

2. **Certificate Information**:
   - Certificate title shows the specific type (e.g., "Sick Leave (Unfit to Work)")
   - Issued and expiration dates are clearly displayed
   - Status (Active/Expired) is shown

3. **Download Certificates**:
   - Active certificates have "Download PDF" button
   - PDF content matches the certificate type
   - Expired certificates cannot be downloaded

## Certificate Content Examples

### Work-Related - Sick Leave (Unfit)
```
This is to certify that [Patient Name], [age] years old, [gender],
was examined on [Date] and was found to be
UNFIT FOR WORK/DUTY
and is granted sick leave for a period of [X] days from the date of examination.
```

### Work-Related - Fit-to-Work (Fit)
```
This is to certify that [Patient Name], [age] years old, [gender],
was examined on [Date] and was found to be
FIT FOR WORK/DUTY
This certificate is valid for [X] days from the date of examination.
```

### School Clearance
```
This is to certify that [Patient Name], [age] years old, [gender],
was examined on [Date] and is
CLEARED FOR SCHOOL ATTENDANCE
This certificate is valid for [X] days from the date of examination.
```

### Driver's License
```
This is to certify that [Patient Name], [age] years old, [gender],
was examined on [Date] and is
MEDICALLY FIT TO DRIVE
This certificate is valid for [X] days from the date of examination.
```

## Technical Details

### Files Modified
1. **migrations/add_certificate_types.sql**: Database migration
2. **doctor_medical_certificate_handler.php**: Backend handler updated
3. **doctor_consultation.php**: UI updated with type selection
4. **generate_medical_certificate_pdf.php**: PDF generation updated
5. **user_records.php**: Patient view updated

### Files Created
1. **run_certificate_types_migration.php**: Migration runner script
2. **CERTIFICATE_TYPES_ENHANCEMENT.md**: This documentation

## Validation Rules

1. **Certificate Type**: Required for all certificates
2. **Fit Status**: Required only for work-related certificates
3. **Validity Period**: Required, must be 7 or 14 days
4. **Patient Access**: Expired certificates are automatically blocked

## Backward Compatibility

- Existing certificates without type information will display as "Medical Certificate"
- The system gracefully handles missing certificate type data
- Migration script safely adds new columns without affecting existing data

## Future Enhancements

Potential improvements:
- Custom certificate templates per type
- Additional certificate subtypes
- Certificate type-specific validity periods
- Email notifications with certificate type
- Certificate type filtering in patient records

## Support

For issues or questions:
1. Verify migration was run successfully
2. Check browser console for JavaScript errors
3. Verify certificate type is selected before generation
4. Ensure fit status is selected for work-related certificates

---

**Enhancement Date**: 2025
**Version**: 2.0

