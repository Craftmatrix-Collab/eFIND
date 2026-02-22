<?php
/**
 * Resend Email Test Script
 * 
 * This script tests the Resend API integration
 * Usage: php test-resend.php your-email@example.com
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/config.php';

// Check if email argument is provided
if ($argc < 2) {
    echo "Usage: php test-resend.php your-email@example.com\n";
    exit(1);
}

$testEmail = $argv[1];

// Validate email
if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
    echo "Error: Invalid email address\n";
    exit(1);
}

echo "=================================\n";
echo "Resend API Test\n";
echo "=================================\n";
echo "Testing email delivery to: $testEmail\n\n";

// Check if API key is configured
if (RESEND_API_KEY === 'your-resend-api-key-here') {
    echo "❌ ERROR: Resend API key not configured!\n";
    echo "Please update RESEND_API_KEY in includes/config.php\n";
    exit(1);
}

// Generate test OTP
$testOTP = sprintf("%06d", mt_rand(0, 999999));

echo "Generated test OTP: $testOTP\n";
echo "Sending email...\n\n";

try {
    $resend = \Resend::client(RESEND_API_KEY);
    
    $html_content = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #4361ee, #3a0ca3); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
            .otp-box { background: white; padding: 20px; text-align: center; margin: 20px 0; border-radius: 8px; border: 2px dashed #4361ee; }
            .otp-code { font-size: 32px; font-weight: bold; color: #4361ee; letter-spacing: 5px; }
            .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🧪 Test Email - Resend Integration</h1>
            </div>
            <div class='content'>
                <p><strong>This is a test email from eFIND System</strong></p>
                <p>If you're receiving this, the Resend integration is working correctly!</p>
                <div class='otp-box'>
                    <p style='margin: 0; color: #666;'>Test OTP Code:</p>
                    <div class='otp-code'>{$testOTP}</div>
                </div>
                <p>This is a test OTP for verification purposes.</p>
                <div class='footer'>
                    <p>eFIND System - Powered by Resend</p>
                    <p>&copy; " . date('Y') . " Barangay Poblacion South</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $result = $resend->emails->send([
        'from' => FROM_EMAIL,
        'to' => [$testEmail],
        'subject' => 'Test Email - eFIND OTP System',
        'html' => $html_content
    ]);
    
    echo "✅ SUCCESS! Email sent successfully.\n";
    echo "Email ID: " . $result->id . "\n\n";
    echo "Please check your inbox (and spam folder) for the test email.\n";
    echo "=================================\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: Failed to send email\n";
    echo "Error message: " . $e->getMessage() . "\n";
    echo "\nTroubleshooting:\n";
    echo "1. Verify your API key is correct\n";
    echo "2. Check if FROM_EMAIL domain is verified in Resend\n";
    echo "3. Visit https://resend.com/docs for more help\n";
    echo "=================================\n";
    exit(1);
}
?>
