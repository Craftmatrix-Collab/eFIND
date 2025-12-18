# 🚀 Quick Start - OTP Password Reset

## ⚡ 3-Minute Setup

### 1. Get Resend API Key
```
Visit: https://resend.com
Sign up (free)
Copy API key
```

### 2. Configure
```bash
# Edit: includes/config.php
define('RESEND_API_KEY', 'your-api-key-here');
define('FROM_EMAIL', 'onboarding@resend.dev'); # or your domain
```

### 3. Database Migration
```sql
ALTER TABLE admin_users 
ADD COLUMN reset_token VARCHAR(255) NULL,
ADD COLUMN reset_expires DATETIME NULL;
```

### 4. Test
```bash
php test-resend.php your@email.com
```

## 📁 Key Files

| File | Purpose |
|------|---------|
| `forgot-password.php` | Email input & OTP generation |
| `verify-otp.php` | OTP verification (6 digits) |
| `reset-password.php` | New password creation |
| `test-resend.php` | Email testing tool |

## 🔑 Configuration

**Location:** `includes/config.php`

```php
// Required settings
define('RESEND_API_KEY', 'YOUR_KEY');
define('FROM_EMAIL', 'noreply@yourdomain.com');
```

## 🧪 Testing

```bash
# Test email delivery
php test-resend.php your@email.com

# Check if OTP email arrives
# Check spam folder if needed
```

## 🌊 User Flow

```
1. forgot-password.php
   ↓ (enters email)
2. Email sent with OTP
   ↓
3. verify-otp.php
   ↓ (enters 6-digit OTP)
4. reset-password.php
   ↓ (creates new password)
5. login.php
   ↓ (login successful)
```

## 🔒 Security Settings

| Feature | Value | Location |
|---------|-------|----------|
| OTP Length | 6 digits | `forgot-password.php` |
| Expiration | 15 minutes | `forgot-password.php` |
| Max Attempts | 5 | `verify-otp.php` |
| Password Min | 8 chars | `reset-password.php` |

## 🎨 Customization

### Change OTP Length (4 digits)
```php
// In forgot-password.php
$otp = sprintf("%04d", mt_rand(0, 9999));
```

### Change Expiration (30 minutes)
```php
// In forgot-password.php
$expires = date("Y-m-d H:i:s", strtotime('+30 minutes'));
```

### Change Max Attempts
```php
// In verify-otp.php
if ($attempts >= 10) { // was 5
```

## 🐛 Troubleshooting

### Email not sending?
- Check API key is correct
- Verify FROM_EMAIL domain
- Check logs: `logs/php_errors.log`
- Run test: `php test-resend.php`

### Database error?
- Run migration script
- Check column names
- Verify connection

### OTP not working?
- Check expiration time
- Clear browser cache
- Try resend OTP
- Check session variables

## 📞 Support

- **Detailed Docs:** `OTP_IMPLEMENTATION_README.md`
- **Setup Guide:** `SETUP_GUIDE.txt`
- **Resend Docs:** https://resend.com/docs

## ✅ Pre-Launch Checklist

- [ ] Resend API key configured
- [ ] Database migration run
- [ ] Test email sent successfully
- [ ] Full user flow tested
- [ ] Production domain verified
- [ ] Error logs checked

---

**Status:** ✅ Ready for Production  
**Version:** 1.0  
**Date:** 2025-12-18
