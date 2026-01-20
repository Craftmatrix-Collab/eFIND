# Chatbot Logging - Quick Reference Card

## 🎯 What Was Implemented?
Chatbot messages are now automatically logged to the Activity Logs system in the database.

## 📍 Where to View Logs?
**Admin Panel → Activity Logs Page**

## 🔍 How to Filter Chatbot Logs?
1. Go to Activity Logs page
2. Select **"Chatbot"** from **Action Type** dropdown
3. Click **Filter** button

## 💬 What Gets Logged?

### Every Time a User Sends a Message:
```
Action: chatbot
Description: "Asked chatbot: [question preview]"
Details: Full question + session ID
User: Username or "Guest"
Time: Exact timestamp
```

### Every Time Bot Responds:
```
Action: chatbot
Description: "Chatbot responded"
Details: Bot response + context + session ID
User: Same as request
Time: Exact timestamp
```

## 🎨 Visual Identifier
Chatbot actions appear with a **purple badge** labeled "Chatbot"

## 📊 Data Captured
- ✅ User questions
- ✅ Bot responses
- ✅ Error messages
- ✅ Session IDs
- ✅ IP addresses
- ✅ Timestamps
- ✅ User information (even guests)

## 🔒 Security
- SQL injection protected (prepared statements)
- Sensitive data sanitized
- Guest users tracked separately

## 💻 Technical
- **Database**: `activity_logs` table
- **Action Type**: `chatbot`
- **Files Modified**: 
  - `/admin/api.php` (logging logic)
  - `/admin/activity_log.php` (UI display)

## ✅ Testing Steps
1. Open chatbot widget
2. Send a test message
3. Go to Activity Logs
4. Filter by "Chatbot"
5. Verify your message appears

## 📞 Troubleshooting
- **Not seeing logs?** Check database connection in `/admin/includes/config.php`
- **Logs missing user info?** Check session configuration
- **Purple badge not showing?** Clear browser cache

## 📖 Full Documentation
See: `CHATBOT_LOGGING_IMPLEMENTATION.md`

---
**Version**: 1.0 | **Date**: January 20, 2026
