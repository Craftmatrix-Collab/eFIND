# OTP-Based Password Reset Implementation

## Overview
This implementation adds OTP (One-Time Password) functionality to the forgot password feature using Resend email service.

## Features
- ✅ 6-digit OTP generation
- ✅ Email delivery via Resend API
- ✅ OTP expiration (15 minutes)
- ✅ Rate limiting (5 attempts max)
- ✅ Resend OTP functionality
- ✅ Secure password reset flow
- ✅ Password strength validation
- ✅ Modern, responsive UI

## Files Created/Modified

### New Files:
1. **composer.json** - PHP dependency management with Resend SDK
2. **verify-otp.php** - OTP verification page with 6-digit input
3. **reset-password.php** - Password reset page with strength checker

### Modified Files:
1. **forgot-password.php** - Updated to send OTP via Resend
2. **login.php** - Added success message after password reset
3. **includes/config.php** - Added Resend API configuration

## Setup Instructions

### 1. Install Dependencies
```bash
cd /home/delfin/code/clone/eFIND/admin
php composer.phar install
```

### 2. Configure Resend API
1. Sign up at https://resend.com
2. Get your API key from the dashboard
3. Update `includes/config.php`:
   ```php
   define('RESEND_API_KEY', 'your-actual-api-key-here');
   define('FROM_EMAIL', 'your-verified-domain@yourdomain.com');
   ```

### 3. Database Schema
Ensure the `admin_users` table has these columns:
```sql
ALTER TABLE admin_users 
ADD COLUMN reset_token VARCHAR(255) NULL,
ADD COLUMN reset_expires DATETIME NULL;
```

## User Flow

1. **User visits forgot-password.php**
   - Enters email address
   - System checks if email exists

2. **OTP Generation & Email**
   - 6-digit OTP generated
   - Stored in database with 15-min expiration
   - Email sent via Resend

3. **User redirected to verify-otp.php**
   - Enters 6-digit OTP
   - Can resend OTP if needed
   - Max 5 attempts before lockout

4. **OTP Verified → redirect to reset-password.php**
   - User creates new password
   - Password strength validation
   - Password must meet requirements:
     - At least 8 characters
     - One uppercase letter
     - One lowercase letter
     - One number

5. **Success → redirect to login.php**
   - Success message displayed
   - User can login with new password

## Security Features

- **OTP Expiration**: 15 minutes validity
- **Rate Limiting**: Max 5 failed attempts
- **Password Hashing**: bcrypt via `password_hash()`
- **Session Security**: OTP verification required before password reset
- **Token Cleanup**: Reset tokens cleared after successful password change

## Email Configuration

The OTP email includes:
- Professional HTML template
- 6-digit OTP code prominently displayed
- Expiration time (15 minutes)
- Security notice
- Branding (eFIND System)

## Testing

### Test the Flow:
1. Navigate to: `forgot-password.php`
2. Enter a valid email from `admin_users` table
3. Check email for OTP (check spam folder too)
4. Enter OTP in verification page
5. Create new password
6. Login with new credentials

### Important Notes:
- **Default Resend Email**: `onboarding@resend.dev` (for testing only)
- **Production**: Use your own verified domain
- **Email Delivery**: May take a few seconds
- **Spam Folder**: Check if email not in inbox

## API Usage

### Resend SDK Example:
```php
use Resend\Resend;

$resend = Resend::client(RESEND_API_KEY);

$resend->emails->send([
    'from' => 'noreply@yourdomain.com',
    'to' => ['user@example.com'],
    'subject' => 'Your OTP Code',
    'html' => '<h1>Your OTP: 123456</h1>'
]);
```

## Customization

### Change OTP Length:
In `forgot-password.php` and `verify-otp.php`:
```php
// For 4-digit OTP:
$otp = sprintf("%04d", mt_rand(0, 9999));
```

### Change Expiration Time:
```php
// For 30 minutes:
$expires = date("Y-m-d H:i:s", strtotime('+30 minutes'));
```

### Customize Email Template:
Edit the `$html_content` variable in `forgot-password.php`

## Troubleshooting

### Email Not Sending:
1. Check RESEND_API_KEY is correct
2. Verify FROM_EMAIL is authorized
3. Check error logs: `/logs/php_errors.log`
4. Test API key at https://resend.com/docs

### OTP Not Matching:
1. Check database `reset_token` column
2. Verify OTP not expired
3. Clear browser cache
4. Check session variables

### Database Errors:
1. Ensure columns exist: `reset_token`, `reset_expires`
2. Check database connection in `config.php`
3. Verify user permissions

## Dependencies

- **PHP**: >= 7.4
- **Composer**: For package management
- **Resend PHP SDK**: ^0.13.0
- **Bootstrap**: 5.3.0 (CDN)
- **Font Awesome**: 6.4.0 (CDN)

## Support

For issues or questions:
1. Check Resend documentation: https://resend.com/docs
2. Review error logs
3. Verify all configuration settings

## License

Part of the eFIND System - Barangay Poblacion South
