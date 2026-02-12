#!/bin/bash
# Test chatbot API with actual POST request

echo "Testing Chatbot API..."
echo "====================="
echo ""

# Test 1: Health check
echo "Test 1: Health Check"
curl -s http://localhost/admin/api.php/health | python3 -m json.tool
echo ""

# Test 2: Simple chat message
echo "Test 2: Chat Message"
RESPONSE=$(curl -s -w "\nHTTP_CODE:%{http_code}" http://localhost/admin/api.php/chat \
  -H "Content-Type: application/json" \
  -H "Cookie: PHPSESSID=test123" \
  -d '{"message":"hello","sessionId":"test_'$(date +%s)'","userId":"guest","timestamp":"'$(date -Iseconds)'"}')

HTTP_CODE=$(echo "$RESPONSE" | grep "HTTP_CODE:" | cut -d: -f2)
BODY=$(echo "$RESPONSE" | grep -v "HTTP_CODE:")

echo "HTTP Status: $HTTP_CODE"
echo "Response Body:"
echo "$BODY" | python3 -m json.tool
echo ""

# Test 3: Check logs
echo "Test 3: Recent Logs"
echo "--- Activity Log ---"
tail -5 logs/chatbot_activity.log 2>/dev/null || echo "No activity log"
echo ""
echo "--- Error Log ---"
tail -5 logs/chatbot_errors.log 2>/dev/null || echo "No error log"
