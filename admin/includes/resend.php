<?php
// Resend API Configuration
define('RESEND_API_KEY', 're_SjPYXdic_HPadBQ3zxymMjahcAbHThMQJ');
define('RESEND_FROM_EMAIL', 'onboarding@resend.dev'); // Change to your verified domain email

function sendOTPEmail($toEmail, $toName, $otp) {
    $data = [
        'from' => RESEND_FROM_EMAIL,
        'to' => [$toEmail],
        'subject' => 'Your 2FA Code - eFIND Admin',
        'html' => '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                <h2 style="color: #333;">Two-Factor Authentication</h2>
                <p>Hello ' . htmlspecialchars($toName) . ',</p>
                <p>Your verification code is:</p>
                <div style="background-color: #f4f4f4; padding: 20px; text-align: center; font-size: 32px; font-weight: bold; letter-spacing: 5px; margin: 20px 0; border-radius: 8px;">
                    ' . $otp . '
                </div>
                <p style="color: #666;">This code will expire in 5 minutes.</p>
                <p style="color: #666;">If you did not request this code, please ignore this email.</p>
                <hr style="margin: 30px 0; border: none; border-top: 1px solid #ddd;">
                <p style="color: #999; font-size: 12px; text-align: center;">eFIND Admin System - Barangay Poblacion South</p>
            </div>
        '
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . RESEND_API_KEY,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("Resend API Error: HTTP $httpCode - Response: $response - Error: $error");
        return false;
    }

    return true;
}

function generateOTP($length = 6) {
    return str_pad(random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

function storeOTP($userId, $otp, $type = 'admin') {
    global $conn;
    
    $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    
    // Delete old OTPs for this user
    $stmt = $conn->prepare("DELETE FROM two_factor_codes WHERE user_id = ? AND user_type = ?");
    $stmt->bind_param("is", $userId, $type);
    $stmt->execute();
    $stmt->close();
    
    // Insert new OTP
    $stmt = $conn->prepare("INSERT INTO two_factor_codes (user_id, user_type, code, expires_at) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $userId, $type, $otp, $expiresAt);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

function verifyOTP($userId, $otp, $type = 'admin') {
    global $conn;
    
    $stmt = $conn->prepare("SELECT id FROM two_factor_codes WHERE user_id = ? AND user_type = ? AND code = ? AND expires_at > NOW()");
    $stmt->bind_param("iss", $userId, $type, $otp);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $valid = $result->num_rows > 0;
    
    if ($valid) {
        // Delete used OTP
        $stmt = $conn->prepare("DELETE FROM two_factor_codes WHERE user_id = ? AND user_type = ?");
        $stmt->bind_param("is", $userId, $type);
        $stmt->execute();
    }
    
    $stmt->close();
    return $valid;
}
