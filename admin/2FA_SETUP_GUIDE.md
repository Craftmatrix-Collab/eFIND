# Two-Factor Authentication (2FA) Implementation Guide

## ✅ Installation Complete!

Your eFIND Admin system now has 2FA enabled using **Resend** email service.

## 📋 What Was Installed

1. **Database Tables Created:**
   - `two_factor_codes` - Stores temporary OTP codes
   - Added columns to `admin_users`:
     - `email` - Admin email address
     - `two_fa_enabled` - Toggle 2FA on/off (default: ON)

2. **Files Created:**
   - `/admin/includes/resend.php` - Resend email integration
   - `/admin/verify-2fa.php` - OTP verification page
   - Modified `/admin/index.php` - Added 2FA to login flow

3. **Current Admin Users:**
   - sierra.pacilan1 (sierra.pacilan1@gmail.com) - 2FA Enabled ✓
   - Barts (erwinbartolome4@gmail.com) - 2FA Enabled ✓

## 🔐 How It Works

1. Admin enters username & password on `/admin/`
2. If password is correct:
   - System generates a 6-digit OTP code
   - Sends code via email using Resend API
   - Redirects to `/admin/verify-2fa.php`
3. Admin enters the 6-digit code from email
4. Code is valid for **5 minutes**
5. After successful verification → Dashboard

## ⚙️ Configuration

### Resend API Settings
File: `/admin/includes/resend.php`

```php
define('RESEND_API_KEY', 're_SjPYXdic_HPadBQ3zxymMjahcAbHThMQJ');
define('RESEND_FROM_EMAIL', 'onboarding@resend.dev');
```

### ⚠️ Important: Update FROM Email
The current FROM email is `onboarding@resend.dev` (Resend's default).

**To use your own domain:**
1. Go to [Resend Dashboard](https://resend.com/domains)
2. Add and verify your domain
3. Update `RESEND_FROM_EMAIL` in `/admin/includes/resend.php`
   ```php
   define('RESEND_FROM_EMAIL', 'noreply@yourdomain.com');
   ```

## 🧪 Testing

1. Go to `http://your-domain/admin/`
2. Login with credentials:
   - Username: `sierra.pacilan1` or `Barts`
   - Password: (your password)
3. Check email for 6-digit code
4. Enter code on verification page
5. You should be logged in!

## 📧 Managing Admin Emails

### Update an admin's email:
```sql
UPDATE admin_users SET email = 'newemail@example.com' WHERE username = 'admin_username';
```

### Disable 2FA for specific admin:
```sql
UPDATE admin_users SET two_fa_enabled = 0 WHERE username = 'admin_username';
```

### Enable 2FA:
```sql
UPDATE admin_users SET two_fa_enabled = 1 WHERE username = 'admin_username';
```

## 🔧 Troubleshooting

### Email not received?
1. Check spam/junk folder
2. Verify admin email is set correctly:
   ```sql
   SELECT username, email FROM admin_users;
   ```
3. Check Resend logs at [resend.com/emails](https://resend.com/emails)
4. Verify API key is correct

### "Failed to send verification code"?
- Check `/admin/includes/resend.php` has correct API key
- Ensure `curl` is enabled in PHP
- Check PHP error logs

### Code expired?
- Codes expire after 5 minutes
- Click "Resend Code" button on verification page

### Can't login at all?
Disable 2FA temporarily:
```sql
UPDATE admin_users SET two_fa_enabled = 0 WHERE id = 1;
```

## 📁 File Structure

```
/admin/
├── includes/
│   └── resend.php              # Resend API integration
├── index.php                   # Login page (modified)
├── verify-2fa.php              # OTP verification page
├── run_2fa_setup.php          # Setup script (can delete after setup)
└── setup_2fa_database.sql     # SQL setup file (reference)
```

## 🔒 Security Features

- ✅ OTP codes expire after 5 minutes
- ✅ Used codes are immediately deleted
- ✅ Session regeneration prevents fixation attacks
- ✅ Activity logging for all 2FA events
- ✅ Failed attempts are logged

## 📝 Activity Logs

All 2FA events are logged in `activity_log` table:
- `2fa_initiated` - Code sent
- `2fa_verified` - Successful verification
- `2fa_failed` - Failed verification attempt
- `2fa_send_failed` - Email sending failed

## 🚀 Next Steps

1. ✅ Test the login flow
2. ✅ Update FROM email to your domain (if you have one)
3. ✅ Add emails for any new admin users
4. ✅ Monitor Resend dashboard for email delivery

## 💡 Tips

- Keep the Resend API key secure (don't commit to git)
- Monitor email delivery in Resend dashboard
- Test periodically to ensure emails are delivered
- Consider rate limiting for OTP requests

---

**Need Help?**
- Resend Docs: https://resend.com/docs
- Resend Dashboard: https://resend.com/home
- Check `/admin/logs/activity_*.log` for error details
