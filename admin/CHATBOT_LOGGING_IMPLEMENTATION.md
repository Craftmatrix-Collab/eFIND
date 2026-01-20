# Chatbot Activity Logging Implementation

## Overview
Successfully integrated chatbot message logging into the eFIND activity logs system. All chatbot interactions are now tracked and visible in the Activity Logs page alongside other user activities.

## Implementation Date
January 20, 2026

## Changes Made

### 1. Modified `/admin/api.php`
**Purpose**: Added database logging functionality to the chatbot API endpoint.

**Key Changes**:
- Added session start for user tracking
- Included database connection via `config.php`
- Updated `BarangayChatbotAPI` constructor to accept database connection
- Created new method `logChatToDatabase()` to log all chatbot interactions

**Logging Features**:
- Logs **user questions** with action type "chatbot"
- Logs **bot responses** with context
- Logs **error messages** when chatbot fails
- Captures session ID, user info, IP address, and timestamps
- Stores full message content in the `details` field

### 2. Modified `/admin/activity_log.php`
**Purpose**: Added chatbot action filtering and display styling.

**Key Changes**:
- Added "Chatbot" option to the Action Type filter dropdown
- Created `.badge-chatbot` CSS class (purple/lavender theme: `#e3d7ff` background, `#5a1f7c` text)
- Updated action badge switch statements to handle 'chatbot' action
- Applied styling to both regular display and print preview

### 3. Database Structure
**Table**: `activity_logs`

**Logged Fields**:
- `user_id`: User ID (0 for guest users)
- `user_name`: Display name from users table or "Guest"
- `user_role`: User role (admin, staff, user, guest)
- `action`: Always "chatbot"
- `description`: Short summary (e.g., "Asked chatbot: What are the latest ordinances?")
- `details`: Full message content with context
- `ip_address`: User's IP address
- `log_time`: Timestamp of interaction

### 4. Logged Interaction Types

#### User Message
- **Description**: "Asked chatbot: [first 100 chars of question]"
- **Details**: Full question + session ID

#### Bot Response
- **Description**: "Chatbot responded"
- **Details**: Bot response (200 chars) + user question context (100 chars) + session ID

#### Bot Error
- **Description**: "Chatbot error occurred"
- **Details**: Error message + user question + session ID

## Features

### Activity Log Display
✅ All chatbot messages appear in the main activity logs table
✅ Purple badge styling distinguishes chatbot actions
✅ Filterable by "Chatbot" action type
✅ Searchable by message content
✅ Includes user information (even for guests)
✅ Truncated text with tooltips for long messages
✅ Sortable by date, user, action

### Print Reports
✅ Chatbot logs included in printed reports
✅ Proper badge styling in print view
✅ Can filter by date range before printing

### User Tracking
✅ Tracks logged-in users (admin, staff, regular users)
✅ Tracks guest users as "Guest"
✅ Links to user ID when available
✅ Captures session information

## Usage

### For Administrators
1. Navigate to **Activity Logs** page
2. Use **Action Type** filter and select "Chatbot" to view only chatbot interactions
3. View full message content by hovering over truncated text
4. Print reports with chatbot logs included

### For Developers
The logging happens automatically when users interact with the chatbot widget:
- Every message sent through `/admin/api.php/chat` is logged
- Both successful and failed responses are tracked
- No additional code needed in chatbot widget

## Benefits

1. **Accountability**: Track who asked what questions
2. **Analytics**: Understand what users are searching for
3. **Debugging**: Review bot responses and errors
4. **Compliance**: Maintain records of all chatbot interactions
5. **Insights**: Identify common questions and improve content

## Technical Notes

### Database Connection
- Uses existing `$conn` from `includes/config.php`
- Gracefully handles missing database connection
- No fatal errors if logging fails

### Performance
- Asynchronous logging (doesn't block chatbot response)
- Minimal database queries (1-2 per interaction)
- Efficient prepared statements

### Security
- All user input is sanitized before database insertion
- Uses parameterized queries to prevent SQL injection
- IP addresses logged for security auditing

## Testing Recommendations

1. **Test as logged-in user**: Send a chatbot message and verify it appears in activity logs
2. **Test as guest**: Use chatbot without logging in and verify "Guest" appears
3. **Test error handling**: Disable n8n webhook and verify error is logged
4. **Test filtering**: Use the "Chatbot" filter to view only chatbot logs
5. **Test printing**: Generate a print report with chatbot logs included

## Files Modified

```
/admin/api.php                  - Added database logging functionality
/admin/activity_log.php         - Added chatbot filter and styling
```

## Color Scheme

**Chatbot Badge**:
- Background: `#e3d7ff` (light purple/lavender)
- Text: `#5a1f7c` (dark purple)
- Matches the profile_update badge theme

## Future Enhancements (Optional)

1. Add chatbot-specific analytics dashboard
2. Track conversation threads (multi-turn conversations)
3. Add sentiment analysis to logged messages
4. Create chatbot usage reports
5. Add export functionality for chatbot logs only
6. Implement user feedback logging (thumbs up/down)

## Support

For issues or questions about chatbot logging:
- Check `/admin/logs/chatbot_activity.log` for file-based logs
- Check `/admin/logs/chatbot_errors.log` for error details
- Verify database connection in `includes/config.php`
- Ensure `activity_logs` table has all required columns

---

**Status**: ✅ Implemented and Ready for Production
**Version**: 1.0
**Last Updated**: January 20, 2026
