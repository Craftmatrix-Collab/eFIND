# AI Chatbot Implementation for Ordinances, Resolutions, and Meeting Minutes

## Overview
This document describes the AI-powered chatbot implementation for the eFIND Document Management System, specifically integrated into the Ordinances, Resolutions, and Meeting Minutes sections.

## Features

### 1. **Floating Chat Button**
- Fixed position button at the bottom-right corner of the page
- Eye-catching gradient design with hover effects
- Badge notification system to attract user attention
- Smooth animations and transitions

### 2. **Interactive Chat Widget**
- Clean, modern interface with professional design
- Real-time messaging with typing indicators
- Smooth slide-up animation when opening
- Responsive design that works on all devices

### 3. **Quick Action Buttons**
- Pre-configured questions for common queries:
  - "What are the latest ordinances?"
  - "Show me recent resolutions"
  - "What are the latest meeting minutes?"
  - "How do I search for documents?"

### 4. **AI-Powered Responses**
- Integrated with n8n workflow for intelligent responses
- Context-aware replies based on document categories
- Source attribution for transparency
- Session-based conversation tracking

## Files Structure

```
admin/
├── includes/
│   └── chatbot_widget.php       # Main chatbot widget component
├── api.php                       # Backend API for chatbot communication
├── ordinances.php                # Updated with chatbot widget
├── resolutions.php               # Updated with chatbot widget
└── minutes_of_meeting.php        # Updated with chatbot widget
```

## Implementation Details

### Frontend (chatbot_widget.php)

**Key Components:**
1. **Floating Button** - Toggles chatbot visibility
2. **Chat Widget Container** - Houses all chat functionality
3. **Message Display Area** - Shows conversation history
4. **Input Area** - User message input and send button

**JavaScript Functions:**
- `toggleChatbot()` - Opens/closes the chat widget
- `sendMessage()` - Sends user message to backend API
- `addMessageToChat()` - Adds messages to chat display
- `showTypingIndicator()` - Shows bot is typing
- `handleChatKeyPress()` - Handles Enter key submission

### Backend (api.php)

**Endpoints:**
- `/api.php/chat` - Main chat endpoint
- `/api.php/health` - Health check endpoint
- `/api.php/categories` - Get available categories

**API Request Format:**
```json
{
  "message": "What are the latest ordinances?",
  "sessionId": "session_1234567890_abc123",
  "userId": "guest",
  "context": {
    "page": "documents",
    "categories": ["ordinances", "resolutions", "minutes"]
  },
  "timestamp": "2025-12-17T06:10:20.284Z"
}
```

**API Response Format:**
```json
{
  "output": "Here are the latest ordinances...",
  "timestamp": "2025-12-17T06:10:25.284Z",
  "sources": ["Ordinance No. 2024-001", "Ordinance No. 2024-002"],
  "confidence": 0.9,
  "sessionId": "session_1234567890_abc123",
  "status": "success"
}
```

## Integration Steps

### 1. **Add Chatbot Widget to Pages**
The chatbot widget has been added to three main pages:

```php
<!-- At the bottom of the page, before </body> -->
<?php include(__DIR__ . '/includes/chatbot_widget.php'); ?>
```

### 2. **Configure N8N Webhook**
Update the webhook URL in `api.php`:

```php
$N8N_WEBHOOK_URL = "https://n8n-efind.craftmatrix.org/webhook/YOUR-WEBHOOK-ID";
```

### 3. **Ensure API Endpoint is Accessible**
The API endpoint should be accessible at:
```
/admin/api.php/chat
```

## Styling & Customization

### Color Scheme
```css
Primary Blue: #4361ee
Secondary Blue: #3a0ca3
Accent Orange: #ff6d00
Background: #f8f9fa
```

### Button Position
The chatbot button is positioned at:
```css
bottom: 20px;
right: 20px;
```

To adjust position, modify the `.chatbot-float-btn` class in `chatbot_widget.php`.

### Widget Size
Default dimensions:
```css
Width: 400px
Height: 600px
```

For mobile devices (< 768px):
```css
Width: calc(100vw - 40px)
Height: calc(100vh - 140px)
```

## User Experience Flow

1. **Initial State:**
   - Floating button visible at bottom-right
   - Badge notification shows after 5 seconds

2. **Opening Chatbot:**
   - User clicks floating button
   - Widget slides up smoothly
   - Welcome message displayed with quick actions

3. **Sending Message:**
   - User types message or clicks quick action
   - Message appears in chat as "user" bubble
   - Typing indicator shows bot is processing
   - Bot response appears with timestamp

4. **Continuous Conversation:**
   - Session maintained across messages
   - Scroll automatically to latest message
   - Previous messages remain visible

## Security Features

1. **Input Sanitization:**
   - All user input is escaped before display
   - HTML entities are converted to prevent XSS

2. **Session Management:**
   - Unique session ID generated per user
   - Session stored in cookies for 30 days

3. **API Protection:**
   - CORS headers configured
   - POST method required for chat endpoint
   - Request validation and error handling

## Testing Checklist

- [ ] Chatbot button appears on all three pages
- [ ] Button click opens/closes widget smoothly
- [ ] Welcome message displays correctly
- [ ] Quick action buttons work
- [ ] User can type and send messages
- [ ] Bot responses appear correctly
- [ ] Typing indicator shows during processing
- [ ] Widget is responsive on mobile devices
- [ ] API endpoint returns proper responses
- [ ] Error handling works when API is unavailable

## Troubleshooting

### Chatbot Not Appearing
1. Check if `chatbot_widget.php` exists in `includes/` folder
2. Verify include statement is before `</body>` tag
3. Check browser console for JavaScript errors

### API Errors
1. Verify n8n webhook URL is correct and active
2. Check `logs/chatbot_errors.log` for error details
3. Test API health endpoint: `/admin/api.php/health`
4. Ensure n8n workflow is in production mode (not test mode)

### Styling Issues
1. Clear browser cache
2. Check for CSS conflicts with existing styles
3. Verify Font Awesome icons are loaded

## Browser Support

- ✅ Chrome (latest)
- ✅ Firefox (latest)
- ✅ Safari (latest)
- ✅ Edge (latest)
- ✅ Mobile browsers (iOS Safari, Chrome Mobile)

## Future Enhancements

### Planned Features:
1. **Document Search Integration**
   - Chatbot can search and return specific documents
   - Direct links to relevant ordinances/resolutions

2. **Multi-language Support**
   - English and Filipino language options
   - Auto-detect user language preference

3. **Voice Input**
   - Speech-to-text for user messages
   - Voice output for bot responses

4. **File Upload**
   - Users can upload documents for analysis
   - OCR integration for document queries

5. **Analytics Dashboard**
   - Track most asked questions
   - Monitor chatbot usage metrics
   - User satisfaction ratings

6. **Conversation History**
   - Save chat sessions for logged-in users
   - Export conversation transcripts

## Maintenance

### Regular Tasks:
1. **Monitor Logs:**
   - Check `logs/chatbot_activity.log` weekly
   - Review error patterns in `logs/chatbot_errors.log`

2. **Update AI Model:**
   - Retrain with new document data monthly
   - Update n8n workflow as needed

3. **Performance Optimization:**
   - Monitor API response times
   - Optimize database queries if needed

### Backup Procedures:
1. Backup `api.php` configuration
2. Export n8n workflow regularly
3. Save chat logs before cleanup

## Support

For technical issues or questions:
- **Email:** support@efind-system.local
- **Documentation:** `/admin/CHATBOT_IMPLEMENTATION.md`
- **Logs Location:** `/admin/logs/`

## Version History

### v1.0.0 (2025-12-17)
- Initial implementation
- Integration with ordinances, resolutions, and meeting minutes pages
- Basic chat functionality with n8n backend
- Quick action buttons
- Responsive design

---

**Last Updated:** December 17, 2025  
**Maintainer:** eFIND Development Team
