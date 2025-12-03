# .htaccess Configuration for eFIND System - Production

## Overview
Production-ready `.htaccess` configuration files have been created to hide filenames, improve security, and enable clean URLs during login sessions.

## Files Created

### 1. `/eFIND/.htaccess` (Root Directory)
**Purpose**: Main application security and URL rewriting
**Features**:
- ✅ Hide .php extensions from URLs
- ✅ Security headers (X-Frame-Options, XSS Protection, etc.)
- ✅ Prevent directory listing
- ✅ Block access to sensitive files (.env, config, etc.)
- ✅ Custom error pages
- ✅ Compression and caching
- ✅ Block malicious bots

### 2. `/eFIND/admin/.htaccess` (Admin Panel)
**Purpose**: Enhanced admin panel security with clean URLs
**Features**:
- ✅ Hide .php extensions for all admin pages
- ✅ Clean URLs: `/admin/dashboard` instead of `/admin/dashboard.php`
- ✅ Strict CSP (Content Security Policy)
- ✅ Protect sensitive directories (includes, uploads)
- ✅ Session security headers
- ✅ SQL injection protection
- ✅ Block access to test files in production
- ✅ Custom error pages

### 3. `/eFIND/admin/uploads/.htaccess`
**Purpose**: Prevent script execution in uploads directory
**Features**:
- ✅ Disable PHP execution completely
- ✅ Allow only specific file types (images, PDFs)
- ✅ Prevent directory listing
- ✅ Security headers

### 4. `/eFIND/admin/error/` (Error Pages)
- `403.php` - Forbidden access
- `404.php` - Page not found
- `500.php` - Internal server error

## URL Behavior

### Before .htaccess (Development):
```
http://yoursite.com/admin/login.php
http://yoursite.com/admin/dashboard.php
http://yoursite.com/admin/ordinances.php
http://yoursite.com/admin/resolutions.php
```

### After .htaccess (Production):
```
http://yoursite.com/admin/login
http://yoursite.com/admin/dashboard
http://yoursite.com/admin/ordinances
http://yoursite.com/admin/resolutions
```

**Note**: Old URLs with `.php` will automatically redirect to clean URLs (301 redirect).

## Security Features

### 1. Hidden Filenames
```apache
# Hide .php extensions
RewriteCond %{REQUEST_FILENAME}.php -f
RewriteRule ^([^\.]+)$ $1.php [NC,L,QSA]

# Redirect .php URLs to clean URLs
RewriteCond %{THE_REQUEST} ^GET\ /admin/(.*)\.php
RewriteRule ^(.*)\.php$ /admin/$1 [R=301,L,QSA]
```

### 2. Security Headers
```apache
Header always set X-Frame-Options "DENY"
Header always set X-Content-Type-Options "nosniff"
Header always set X-XSS-Protection "1; mode=block"
Header always set Content-Security-Policy "..."
```

### 3. Protected Directories
- `/admin/includes/` - Forbidden (403)
- `/admin/uploads/` - No PHP execution
- Configuration files blocked
- Version control files blocked (.git, .svn)

### 4. Session Security
```apache
php_value session.cookie_httponly 1
php_value session.cookie_secure 1
php_value session.use_strict_mode 1
php_value session.cookie_samesite Strict
```

### 5. SQL Injection Protection
```apache
RewriteCond %{QUERY_STRING} (union.*select|select.*from|insert.*into) [NC]
RewriteRule .* - [F,L]
```

### 6. Bot Protection
Blocks malicious bots like:
- nikto, sqlmap, fimap
- nessus, openvas, metasploit
- havij, acunetix, etc.

## Testing

### 1. Test Clean URLs
```bash
# Access admin dashboard without .php
curl -I http://yoursite.com/admin/dashboard

# Should redirect from .php to clean URL
curl -I http://yoursite.com/admin/dashboard.php
```

### 2. Test Security
```bash
# Should return 403 Forbidden
curl -I http://yoursite.com/admin/includes/config.php

# Should return 403 Forbidden
curl -I http://yoursite.com/admin/uploads/test.php
```

### 3. Test Error Pages
```bash
# Should show custom 404 page
curl http://yoursite.com/admin/nonexistent-page
```

## Important Notes

### 1. Apache Modules Required
Ensure these Apache modules are enabled:
```bash
sudo a2enmod rewrite
sudo a2enmod headers
sudo a2enmod deflate
sudo a2enmod expires
sudo systemctl restart apache2
```

### 2. AllowOverride Directive
Your Apache configuration must allow `.htaccess` overrides:
```apache
<Directory /var/www/html/eFIND>
    AllowOverride All
</Directory>
```

### 3. HTTPS Configuration
For production, uncomment HTTPS redirect in root `.htaccess`:
```apache
# Uncomment these lines:
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### 4. Session Cookie Security
The session cookie will be:
- ✅ HTTP-only (not accessible via JavaScript)
- ✅ Secure (only sent over HTTPS)
- ✅ SameSite=Strict (CSRF protection)
- ✅ Custom name: `EFIND_ADMIN_SESSION`

## File Structure
```
eFIND/
├── .htaccess                    # Root security config
├── admin/
│   ├── .htaccess               # Admin panel config
│   ├── uploads/
│   │   └── .htaccess          # Upload security
│   ├── error/
│   │   ├── 403.php            # Forbidden page
│   │   ├── 404.php            # Not found page
│   │   └── 500.php            # Server error page
│   ├── login.php              # Accessible as /admin/login
│   ├── dashboard.php          # Accessible as /admin/dashboard
│   ├── ordinances.php         # Accessible as /admin/ordinances
│   └── ...
└── ...
```

## URL Examples

### Login Pages (Always accessible with .php)
```
✅ /admin/login
✅ /admin/login.php (redirects to /admin/login)
✅ /admin/logout
✅ /admin/register
✅ /admin/forgot-password
```

### Admin Pages (Clean URLs after login)
```
✅ /admin/dashboard
✅ /admin/ordinances
✅ /admin/resolutions
✅ /admin/minutes_of_meeting
✅ /admin/activity_log
✅ /admin/admin_profile
```

### API Endpoints (Clean URLs)
```
✅ /admin/api
✅ /admin/get_document_content
✅ /admin/update_ordinance_content
```

## Troubleshooting

### Issue: 500 Internal Server Error
**Solution**: Check if mod_rewrite is enabled
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### Issue: Clean URLs not working
**Solution**: Check AllowOverride in Apache config
```bash
sudo nano /etc/apache2/sites-available/000-default.conf
# Add: AllowOverride All
sudo systemctl restart apache2
```

### Issue: CSS/JS not loading
**Solution**: Check if static files are excluded from rewriting
```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
```

### Issue: Login redirect loop
**Solution**: Ensure login.php is accessible directly
```apache
RewriteCond %{REQUEST_URI} ^/admin/(login|logout|register)$
RewriteCond %{REQUEST_FILENAME}.php -f
RewriteRule ^(.*)$ $1.php [L,QSA]
```

## Performance Optimizations

### 1. Compression Enabled
All text-based files are compressed (HTML, CSS, JS, JSON)

### 2. Browser Caching
- Images: 1 year
- CSS/JS: 1 month
- HTML: No cache (always fresh)

### 3. File Upload Limits
```apache
php_value upload_max_filesize 10M
php_value post_max_size 12M
php_value max_execution_time 300
php_value memory_limit 256M
```

## Security Checklist

- ✅ PHP file extensions hidden
- ✅ Directory listing disabled
- ✅ Sensitive files protected
- ✅ Script execution disabled in uploads
- ✅ Security headers implemented
- ✅ Session security configured
- ✅ SQL injection protection active
- ✅ Bot protection enabled
- ✅ Custom error pages created
- ✅ Compression enabled
- ✅ Browser caching configured
- ✅ File upload limits set

## Deployment Steps

1. **Backup existing files**
```bash
cp -r /var/www/html/eFIND /var/www/html/eFIND_backup
```

2. **Copy .htaccess files**
```bash
cp .htaccess /var/www/html/eFIND/
cp admin/.htaccess /var/www/html/eFIND/admin/
cp admin/uploads/.htaccess /var/www/html/eFIND/admin/uploads/
```

3. **Copy error pages**
```bash
cp -r admin/error /var/www/html/eFIND/admin/
```

4. **Enable required modules**
```bash
sudo a2enmod rewrite headers deflate expires
sudo systemctl restart apache2
```

5. **Test configuration**
```bash
# Test clean URLs
curl -I http://yoursite.com/admin/dashboard

# Test security
curl -I http://yoursite.com/admin/includes/config.php
```

6. **Monitor logs**
```bash
tail -f /var/log/apache2/error.log
tail -f /var/log/apache2/access.log
```

## Maintenance

### Update .htaccess
When making changes, always test first:
```bash
# Test Apache configuration
sudo apachectl configtest

# Restart Apache
sudo systemctl restart apache2
```

### Clear browser cache
After .htaccess changes, clear browser cache or use incognito mode for testing.

## Summary

✅ Production-ready .htaccess files created
✅ Clean URLs enabled (no .php extensions)
✅ Comprehensive security implemented
✅ Custom error pages added
✅ Session security configured
✅ Performance optimizations applied
✅ File upload protection in place

The system is now ready for production deployment with enhanced security and clean URLs! 🚀
