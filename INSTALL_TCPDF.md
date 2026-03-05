# How to Install TCPDF for PDF Generation

## Problem
If you see the error: `Fatal error: Uncaught Error: Class "TCPDF" not found`, it means TCPDF library is not installed.

## Solution

### Option 1: Install via Composer (Recommended)

1. Open your terminal/command prompt
2. Navigate to your project directory:
   ```bash
   cd "c:\Users\Fiona Quel Dalida\Downloads\newest\healthyc - Copy\healthyc"
   ```

3. Run this command:
   ```bash
   composer require tecnickcom/tcpdf
   ```

4. Wait for the installation to complete

### Option 2: Manual Installation

If Composer is not working, you can download TCPDF manually:

1. Download TCPDF from: https://github.com/tecnickcom/TCPDF
2. Extract the files to: `vendor/tecnickcom/tcpdf/`
3. The structure should be: `vendor/tecnickcom/tcpdf/tcpdf.php`

### Option 3: Use Alternative PDF Library

If you prefer not to use TCPDF, you can use FPDF or DomPDF instead. Let me know if you'd like me to update the code to use a different library.

## Verify Installation

After installation, check if the file exists:
- `vendor/tecnickcom/tcpdf/tcpdf.php`

If this file exists, the PDF generation should work.

## Testing

After installation, try downloading a prescription PDF again from the patient's "My Record" tab.

