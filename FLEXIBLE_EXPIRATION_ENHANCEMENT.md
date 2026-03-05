# Flexible Expiration Enhancement

## Overview
This enhancement adds flexible validity options for both Prescriptions and Medical Certificates, allowing doctors to select from predefined periods (7, 14, 30 days) or set a custom expiration date.

## New Features

### 1. Flexible Validity Periods

#### Prescriptions
- **7 days**: Short-term prescriptions
- **14 days**: Standard prescriptions (default)
- **30 days**: Long-term prescriptions
- **Custom expiration date**: Set a specific expiration date

#### Medical Certificates
- **7 days**: Short-term certificates
- **14 days**: Standard certificates (default)
- **30 days**: Extended certificates
- **Custom expiration date**: Set a specific expiration date

### 2. Custom Expiration Date
- Doctors can select a specific expiration date using a date picker
- Date must be in the future
- Automatically calculates validity period days from the custom date
- Provides maximum flexibility for special cases

### 3. Automatic Date Calculation
- System automatically computes expiration dates from validity periods
- For custom dates, calculates validity period days automatically
- Stores both validity_period_days and expiration_date in database

### 4. Enhanced PDF Display
- **Prescription PDFs**: Clearly show "Date" (issued) and "Valid Until" (expiration)
- **Medical Certificate PDFs**: Display issued date and expiration date in validity section
- Dates formatted in readable format (e.g., "January 15, 2025")

### 5. Expiration Handling
- Expired prescriptions are automatically blocked from patient download
- Expired medical certificates are automatically disabled
- Status clearly shown to patients (Valid/Expired)
- Automatic status updates when PDFs are accessed

## Database Changes

No new columns required - existing `validity_period_days` and `expiration_date` columns are used.

## Installation

No migration required - the enhancement uses existing database structure.

## Usage Guide

### For Doctors

#### Setting Prescription Validity:
1. Complete consultation form
2. Scroll to "Prescription Validity Period" section
3. Select from:
   - 7 days
   - 14 days (default)
   - 30 days
   - Custom expiration date
4. If "Custom expiration date" is selected:
   - Date picker appears
   - Select a future date
   - System calculates validity period automatically

#### Setting Medical Certificate Validity:
1. Scroll to "Medical Certificate" section
2. Select certificate type
3. Select validity period:
   - 7 days
   - 14 days (default)
   - 30 days
   - Custom expiration date
4. If "Custom expiration date" is selected:
   - Date picker appears
   - Select a future date
   - System calculates validity period automatically

### For Patients

#### Viewing Prescription Status:
- Go to "My Records" page
- Prescriptions show:
  - "Valid until [date]" for active prescriptions
  - "Expired on [date]" for expired prescriptions
  - Download button only for valid prescriptions

#### Viewing Medical Certificate Status:
- Go to "My Records" page
- Certificates show:
  - "Active" status with expiration date
  - "Expired" status with expiration date
  - Download button only for active certificates

## Technical Implementation

### Frontend Changes
1. **doctor_consultation.php**:
   - Added custom date input fields
   - Added toggle function for custom date visibility
   - Updated form validation
   - Added custom date to form submission

### Backend Changes
1. **doctor_consultation_handler.php**:
   - Updated prescription creation to handle custom dates
   - Calculates validity period from custom date
   - Validates custom dates are in the future

2. **doctor_medical_certificate_handler.php**:
   - Updated certificate creation to handle custom dates
   - Supports 30-day option
   - Calculates validity period from custom date

### PDF Generation
- **generate_prescription_pdf.php**: Already displays issued and expiration dates
- **generate_medical_certificate_pdf.php**: Already displays issued and expiration dates
- Both PDFs block expired documents from patient access

## Validation Rules

1. **Custom Date Validation**:
   - Must be in the future
   - Cannot be today or past dates
   - Date picker enforces minimum date

2. **Validity Period**:
   - Must select a validity option
   - Custom date required if "custom" is selected
   - System calculates period automatically

3. **Expiration Handling**:
   - Expired documents automatically blocked
   - Status updated when accessed
   - Clear messaging to patients

## Examples

### Example 1: Prescription with Custom Date
- Doctor selects "Custom expiration date"
- Sets expiration to "2025-02-15"
- System calculates: 31 days validity
- Patient sees: "Valid until February 15, 2025"

### Example 2: Medical Certificate with 30 Days
- Doctor selects "30 days"
- System calculates: Expires 30 days from today
- Patient sees: "Active - Expires [date]"

## Benefits

1. **Flexibility**: Doctors can set any expiration date needed
2. **Clarity**: Clear display of issued and expiration dates
3. **Automation**: Automatic calculation and validation
4. **Security**: Expired documents automatically blocked
5. **User Experience**: Clear status indicators for patients

## Backward Compatibility

- Existing prescriptions and certificates continue to work
- Default behavior unchanged (14 days)
- No breaking changes to existing functionality

## Future Enhancements

Potential improvements:
- Email reminders before expiration
- Bulk expiration date updates
- Expiration date templates
- Automatic renewal suggestions

---

**Enhancement Date**: 2025
**Version**: 3.0

