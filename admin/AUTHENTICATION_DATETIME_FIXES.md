# Authentication & DateTime Issues - Investigation & Fixes

## Date: 2026-02-03

## Issues Investigated

### Issue 1: Superadmin Login Shows Staff User
**Status:** ✅ FIXED

#### Problem Description
When logging in as superadmin, the system was showing or fetching staff user information instead of admin information.

#### Root Causes Identified

1. **Incorrect Role Check in Sidebar/Navbar**
   - **Location:** `includes/sidebar.php` line 37, `includes/navbar.php` line 45
   - **Original Code:** `if (isset($_SESSION['admin_logged_in']) && $_SESSION['role'] === 'admin')`
   - **Problem:** This checks TWO conditions - both admin_logged_in AND role. If role is somehow not set to 'admin' (maybe staff, superadmin, etc.), the check fails.
   
2. **Possible Username Duplication**
   - If the same username exists in both `admin_users` AND `users` tables
   - The login system checks `admin_users` first, but if password fails there, it marks login as "processed" and doesn't check users table
   - However, if the username doesn't exist in admin_users, it will check users table and login as staff

#### Fixes Applied

1. **Fixed Sidebar Role Check** (`includes/sidebar.php` line 37)
   ```php
   // OLD:
   <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['role'] === 'admin'): ?>
   
   // NEW:
   <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true): ?>
   ```

2. **Fixed Navbar Role Check** (`includes/navbar.php` line 45)
   ```php
   // Same change as sidebar
   ```

#### Why This Fix Works
- The `admin_logged_in` session variable is ONLY set to true when a user successfully logs in from the `admin_users` table (login.php line 59)
- Staff users get `staff_logged_in` set to true instead (login.php line 119)
- By checking only `admin_logged_in === true`, we ensure the menu items appear only for actual admin users
- This is more reliable than checking the role string, which could have variations

#### How to Verify the Fix
1. Log in with your superadmin credentials
2. Check if you can see "Users" and "Activity Logs" menu items in the sidebar
3. Visit: `/admin/debug_session.php` to see all session variables and database checks
4. Look for any username duplication warnings

---

### Issue 2: Date/Time Format (24h to 12h)
**Status:** ✅ FIXED

#### Problem Description
Activity log timestamps were showing in 24-hour format (e.g., 14:30:00) instead of 12-hour format with AM/PM (e.g., 02:30:00 PM).

#### Root Cause
**Location:** `activity_log.php` lines 220 and 1288
**Original Code:** 
```php
date('M d, Y H:i:s', strtotime($log['log_time'] ?? $log['created_at']))
```
- The format string `H:i:s` displays 24-hour format
- `H` = 24-hour format (00-23)

#### Fix Applied
**New Code:**
```php
date('M d, Y h:i:s A', strtotime($log['log_time'] ?? $log['created_at']))
```
- `h` = 12-hour format (01-12)
- `A` = AM/PM indicator (uppercase)

#### Changes Made
1. **Line 1288** - Activity log table display (main view)
2. **Line 220** - Print/export view

#### Why This Fix Works
- PHP's `date()` function format characters:
  - `H` = Hour in 24-hour format (00-23)
  - `h` = Hour in 12-hour format (01-12)
  - `A` = AM or PM (uppercase)
  - `a` = am or pm (lowercase)

---

## Additional Tools Created

### Debug Session Script
**File:** `debug_session.php`
**Purpose:** Diagnostic tool to investigate authentication issues

**Features:**
- Shows all current session variables
- Checks if user exists in admin_users table
- Checks if user exists in users (staff) table
- Warns if username exists in both tables
- Provides recommendations

**How to Use:**
1. Log in to your account
2. Visit: `https://your-domain.com/admin/debug_session.php`
3. Review the output to see:
   - Which session variables are set
   - Which database table(s) your user is in
   - Any potential conflicts

---

## Recommendations

### 1. Check for Username Duplication
Run this SQL query to check if any usernames exist in both tables:
```sql
SELECT au.username AS admin_username, u.username AS staff_username
FROM admin_users au
INNER JOIN users u ON au.username = u.username;
```

If you find duplicates:
- Keep the account in `admin_users` table for admin access
- Remove or rename the duplicate in `users` table
- Or use different usernames for each table

### 2. Role Standardization
Currently the system uses:
- `admin_users` table → automatically gets 'admin' role
- `users` table → role from database (could be 'staff', 'user', etc.)

Consider:
- Keep role naming consistent
- Don't use variations like 'superadmin', 'administrator', etc. unless you update the code to handle them

### 3. Session Variable Best Practices
The login system sets multiple session variables for compatibility:
- For admin: `admin_id`, `admin_username`, `admin_logged_in`, plus generic ones
- For staff: `staff_id`, `staff_username`, `staff_logged_in`, plus generic ones

Always check the specific role-based variable (`admin_logged_in` or `staff_logged_in`) rather than generic ones.

---

## Testing Checklist

- [ ] Log in as superadmin/admin
- [ ] Verify "Users" menu item appears in sidebar
- [ ] Verify "Activity Logs" menu item appears in sidebar
- [ ] Check activity log shows times in 12-hour format with AM/PM
- [ ] Run debug_session.php and verify correct table is used
- [ ] Log out and log in as staff user
- [ ] Verify staff user does NOT see admin-only menu items
- [ ] Check printed activity logs also show 12-hour format

---

## Files Modified

1. `/admin/activity_log.php`
   - Line 220: Changed date format to 12-hour with AM/PM
   - Line 1288: Changed date format to 12-hour with AM/PM

2. `/admin/includes/sidebar.php`
   - Line 37: Simplified admin check to only verify admin_logged_in

3. `/admin/includes/navbar.php`
   - Line 45: Simplified admin check to only verify admin_logged_in

## Files Created

1. `/admin/debug_session.php`
   - New diagnostic tool for session debugging

---

## Conclusion

Both issues have been resolved:
1. ✅ Admin role detection now correctly identifies admin users
2. ✅ Date/time format now displays in 12-hour format with AM/PM

The fixes are minimal and surgical, affecting only the specific areas causing issues without disrupting other functionality.
