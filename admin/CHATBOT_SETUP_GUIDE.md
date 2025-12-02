# 🤖 eFIND Chatbot Setup Guide

## Overview
The chatbot system uses a secure architecture with PHP middleware (api.php) that routes requests to n8n workflows.

## Architecture
```
User Browser → dashboard.php → api.php → n8n Webhook → api.php → Browser
```

**Benefits:**
- ✅ Webhook URL hidden from public
- ✅ Server-side validation & error handling
- ✅ Activity logging for debugging
- ✅ Rate limiting capability
- ✅ Fallback responses when n8n is down

---

## 🚀 Quick Start

### Step 1: Activate n8n Workflow

1. **Login to n8n Dashboard**
   - URL: https://n8n-efind.craftmatrix.org

2. **Open Your Chatbot Workflow**
   - Find the workflow that handles chatbot requests

3. **Activate Production Mode**
   - Click the **"Inactive"** toggle at the top
   - It should turn to **"Active"** (green)
   - This keeps the workflow running 24/7

4. **Get Production Webhook URL**
   - Click on the "Webhook" node in your workflow
   - Copy the **Production URL** (NOT Test URL)
   - Format: `https://n8n-efind.craftmatrix.org/webhook/YOUR-PRODUCTION-ID`

### Step 2: Update api.php Configuration

1. **Open api.php**
   ```bash
   nano /home/delfin/code/clone/eFIND/admin/api.php
   ```

2. **Update Line 17 with Production Webhook URL**
   ```php
   // BEFORE (placeholder):
   $N8N_WEBHOOK_URL = "https://n8n-efind.craftmatrix.org/webhook-test/5eaeb40b-8411-43ce-bee1-c32fc14e04f1";
   
   // AFTER (your production URL):
   $N8N_WEBHOOK_URL = "https://n8n-efind.craftmatrix.org/webhook/YOUR-PRODUCTION-WEBHOOK-ID";
   ```

3. **Save and close** (Ctrl+X, Y, Enter)

### Step 3: Test the API

```bash
# Test health endpoint
curl http://localhost/admin/api.php/health

# Test chat endpoint
curl -X POST http://localhost/admin/api.php/chat \
  -H "Content-Type: application/json" \
  -d '{"message":"Hello, how can you help me?"}'
```

**Expected Response:**
```json
{
  "output": "Hello! I'm the eFIND Assistant...",
  "timestamp": "2025-12-02T16:42:00+00:00",
  "status": "success",
  "sessionId": "session_..."
}
```

---

## 📝 Configuration Files

### 1. api.php
- **Purpose**: Middleware API that handles chatbot requests
- **Location**: `/admin/api.php`
- **Key Settings**:
  - `$N8N_WEBHOOK_URL` (line 17) - Your production webhook URL
  - Logging enabled to `/admin/logs/chatbot_activity.log`
  - Error logging to `/admin/logs/chatbot_errors.log`

### 2. dashboard.php
- **Purpose**: Frontend chatbot UI
- **Location**: `/admin/dashboard.php`
- **Key Settings**:
  - `API_URL` (line 1568) - Set to `'api.php/chat'` (already configured)
  - Uses api.php as middleware (no direct n8n access)

---

## 🔧 n8n Workflow Configuration

Your n8n workflow should:

### Required Response Format
```json
{
  "output": "Your response text here",
  "sources": ["source1", "source2"],  // Optional
  "confidence": 0.95                   // Optional
}
```

### Webhook Node Settings
1. **HTTP Method**: POST
2. **Path**: `/webhook/YOUR-UNIQUE-ID`
3. **Response Mode**: "When Last Node Finishes"
4. **Response Data**: "First Entry JSON"

### Recommended Workflow Structure
```
Webhook → Validate Input → Query Database → AI Processing → Format Response → Respond to Webhook
```

---

## 📊 Monitoring & Debugging

### Check Logs
```bash
# Activity log (all requests)
tail -f /home/delfin/code/clone/eFIND/admin/logs/chatbot_activity.log

# Error log (errors only)
tail -f /home/delfin/code/clone/eFIND/admin/logs/chatbot_errors.log

# PHP errors
tail -f /home/delfin/code/clone/eFIND/admin/logs/php_errors.log
```

### Common Log Messages
```
[2025-12-02 16:42:00] [127.0.0.1] Request received: /chat [POST]
[2025-12-02 16:42:00] [127.0.0.1] Chat request - User: 1, Session: session_abc123
[2025-12-02 16:42:01] [127.0.0.1] Sending to n8n: Hello, how can you help me?
[2025-12-02 16:42:02] [127.0.0.1] N8N Response - HTTP Code: 200
[2025-12-02 16:42:02] [127.0.0.1] N8N response parsed successfully
```

---

## 🐛 Troubleshooting

### Issue 1: "Webhook not registered" Error

**Symptoms:**
- Error message: "The requested webhook is not registered"
- HTTP 404 response

**Solution:**
1. Ensure n8n workflow is **Active** (not Inactive)
2. Use **production** webhook URL (not test URL)
3. Verify webhook node is configured correctly

### Issue 2: "N8N service unavailable"

**Symptoms:**
- HTTP 500, 502, or timeout errors
- Fallback response shown to users

**Possible Causes:**
- n8n server is down
- Network connectivity issues
- Webhook URL incorrect

**Solution:**
1. Check n8n server status
2. Verify webhook URL in api.php
3. Test with curl: `curl -X POST YOUR_WEBHOOK_URL -H "Content-Type: application/json" -d '{"message":"test"}'`

### Issue 3: "Invalid JSON payload"

**Symptoms:**
- Error: "Invalid JSON payload"
- HTTP 400 response

**Solution:**
- Ensure request body is valid JSON
- Check Content-Type header is `application/json`

### Issue 4: Chatbot not responding

**Symptoms:**
- No response in chat window
- Console shows errors

**Debugging Steps:**
1. Open browser DevTools (F12)
2. Check Console tab for JavaScript errors
3. Check Network tab for failed requests
4. Review chatbot logs on server

---

## 🔒 Security Features

### Implemented
✅ Webhook URL hidden from client-side  
✅ Server-side input validation  
✅ Error logging for audit trail  
✅ CORS headers configured  
✅ Timeout protection (30 seconds)  
✅ Session tracking  

### Recommended Additions
- Rate limiting (prevent spam)
- User authentication validation
- Message content filtering
- Database logging of conversations

---

## 📈 Performance Optimization

### Current Settings
- Timeout: 30 seconds
- Message history: Last 20 messages
- Session cookies: 30 days

### Recommendations
- Monitor response times in logs
- Adjust timeout based on n8n performance
- Consider caching frequent queries
- Implement queue for high traffic

---

## 🔄 Updating the Chatbot

### Update n8n Workflow
1. Make changes in n8n dashboard
2. Test using the test webhook
3. When satisfied, workflow updates are live immediately

### Update API Logic
1. Edit `api.php`
2. No restart required (PHP is interpreted)
3. Changes apply immediately

### Update Frontend UI
1. Edit `dashboard.php`
2. Clear browser cache (Ctrl+F5)
3. Changes visible immediately

---

## 📞 Support

### Error Code Reference

| Code | Meaning | Action |
|------|---------|--------|
| 200 | Success | Normal operation |
| 400 | Bad Request | Check request format |
| 404 | Not Found | Check webhook URL and n8n status |
| 405 | Method Not Allowed | Use POST for chat endpoint |
| 500 | Server Error | Check logs and n8n connection |

### Contact
- Check logs first
- Review this guide
- Test with curl commands
- Contact administrator if issue persists

---

## ✅ Verification Checklist

Before going live:

- [ ] n8n workflow is **Active** (not in test mode)
- [ ] Production webhook URL copied correctly to api.php line 17
- [ ] Test API health endpoint returns 200
- [ ] Test chat endpoint returns valid response
- [ ] Check logs directory exists and is writable
- [ ] Browser console shows no errors
- [ ] Test conversation works end-to-end
- [ ] Review error handling (disconnect n8n, verify fallback works)

---

## 📚 Additional Resources

- n8n Documentation: https://docs.n8n.io
- Webhook Documentation: https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.webhook/
- PHP cURL: https://www.php.net/manual/en/book.curl.php

---

**Last Updated**: December 2, 2025  
**Version**: 1.0  
**Status**: Production Ready
