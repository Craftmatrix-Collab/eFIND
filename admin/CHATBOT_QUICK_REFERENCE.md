# Chatbot Quick Reference Guide

## 🚀 Quick Start

### For Users
1. Look for the blue chat button at the bottom-right of the page
2. Click to open the chatbot widget
3. Type your question or click a quick action button
4. Get instant AI-powered answers about documents

### For Administrators
1. Ensure n8n workflow is active
2. Check API endpoint: `/admin/api.php/health`
3. Monitor logs in `/admin/logs/chatbot_activity.log`

## 📍 Pages with Chatbot

✅ **Ordinances** - `/admin/ordinances.php`  
✅ **Resolutions** - `/admin/resolutions.php`  
✅ **Meeting Minutes** - `/admin/minutes_of_meeting.php`

## 🎯 Quick Actions Available

| Button | Query |
|--------|-------|
| 📄 Latest Ordinances | "What are the latest ordinances?" |
| ⚖️ Recent Resolutions | "Show me recent resolutions" |
| 📋 Meeting Minutes | "What are the latest meeting minutes?" |
| 🔍 Search Help | "How do I search for documents?" |

## 🛠️ Common Tasks

### Add Chatbot to New Page
```php
<!-- Before closing </body> tag -->
<?php include(__DIR__ . '/includes/chatbot_widget.php'); ?>
```

### Update Webhook URL
Edit `/admin/api.php`:
```php
$N8N_WEBHOOK_URL = "https://your-n8n-instance/webhook/YOUR-ID";
```

### Check API Status
```bash
curl http://your-domain/admin/api.php/health
```

### View Logs
```bash
tail -f /admin/logs/chatbot_activity.log
tail -f /admin/logs/chatbot_errors.log
```

## 🎨 Customization

### Change Button Position
In `chatbot_widget.php`, modify:
```css
.chatbot-float-btn {
    bottom: 20px;  /* Change this */
    right: 20px;   /* Change this */
}
```

### Change Colors
```css
Primary: #4361ee  (Blue gradient start)
Secondary: #3a0ca3  (Blue gradient end)
Accent: #ff6d00  (Orange for badge)
```

### Adjust Widget Size
```css
.chatbot-widget {
    width: 400px;   /* Desktop width */
    height: 600px;  /* Desktop height */
}
```

## 🔧 Troubleshooting

### Problem: Chatbot button not showing
**Solution:**
1. Check if file exists: `admin/includes/chatbot_widget.php`
2. Verify include statement is present
3. Clear browser cache (Ctrl+Shift+R)

### Problem: Messages not sending
**Solution:**
1. Check n8n workflow is active
2. Verify webhook URL in `api.php`
3. Check browser console for errors
4. Test API: `/admin/api.php/health`

### Problem: Styling looks broken
**Solution:**
1. Ensure Font Awesome CSS is loaded
2. Clear browser cache
3. Check for CSS conflicts
4. Verify Bootstrap 5 is loaded

## 📊 API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api.php/health` | GET | Health check |
| `/api.php/chat` | POST | Send message |
| `/api.php/categories` | GET | Get categories |

## 🔒 Security Notes

- All user input is sanitized
- XSS protection enabled
- Session-based tracking
- CORS configured properly

## 📱 Mobile Responsive

The chatbot automatically adapts to mobile screens:
- Widget resizes to fit screen
- Touch-friendly buttons
- Optimized for vertical scrolling

## 🔍 Testing Commands

### Test Welcome Message
Open chatbot → Should show welcome message and quick actions

### Test Message Send
Type "Hello" → Press Enter → Should get response

### Test Quick Actions
Click any quick action button → Should send message automatically

### Test API
```bash
curl -X POST http://your-domain/admin/api.php/chat \
  -H "Content-Type: application/json" \
  -d '{"message":"test","sessionId":"test123"}'
```

## 📈 Performance Tips

1. **Cache API responses** where appropriate
2. **Lazy load** chatbot widget
3. **Minify CSS/JS** in production
4. **Monitor API latency** regularly

## 🆘 Emergency Contacts

| Issue | Contact |
|-------|---------|
| Technical Problems | IT Support |
| API/Backend Issues | Development Team |
| Content Updates | Content Manager |

## 📚 Related Documentation

- Full Implementation Guide: `CHATBOT_IMPLEMENTATION.md`
- API Documentation: `api.php` (inline comments)
- N8N Workflow: Check n8n dashboard

## ✨ Best Practices

1. **Keep webhook active** - Ensure n8n workflow is always running
2. **Monitor logs** - Check weekly for errors or issues
3. **Update regularly** - Retrain AI with new documents
4. **Test changes** - Always test in staging before production
5. **Backup config** - Save API settings and workflow exports

## 🎯 Success Metrics

Track these KPIs:
- Number of chat sessions per day
- Average response time
- User satisfaction rating
- Most common queries
- Error rate percentage

## 🔄 Version Info

**Current Version:** 1.0.0  
**Last Updated:** December 17, 2025  
**Compatibility:** PHP 7.4+, Modern Browsers

---

**Quick Tip:** Press `Ctrl+Shift+C` to open browser console for debugging!
