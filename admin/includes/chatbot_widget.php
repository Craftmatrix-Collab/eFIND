<?php
$chatbot_profile_picture_raw = trim((string)($_SESSION['profile_picture'] ?? ''));
$chatbot_profile_picture_path = '';
if ($chatbot_profile_picture_raw !== '') {
    if (preg_match('#^(https?:)?//#i', $chatbot_profile_picture_raw) || stripos($chatbot_profile_picture_raw, 'data:image/') === 0) {
        $chatbot_profile_picture_path = $chatbot_profile_picture_raw;
    } else {
        $chatbot_profile_picture_path = 'uploads/profiles/' . basename($chatbot_profile_picture_raw);
    }
    if (strpos($chatbot_profile_picture_path, 'data:') !== 0) {
        $chatbot_profile_picture_path .= (strpos($chatbot_profile_picture_path, '?') === false ? '?t=' : '&t=') . time();
    }
}
?>
<!-- AI Chatbot Widget for Ordinances, Resolutions, and Meeting Minutes -->
<style>
    /* Chatbot Button */
    .chatbot-float-btn {
        position: fixed;
        bottom: 100px;
        right: 20px;
        width: 60px;
        height: 60px;
        background: #0084ff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 8px 20px rgba(0, 132, 255, 0.35);
        z-index: 9998;
        transition: all 0.3s ease;
        border: none;
    }
    
    .chatbot-float-btn:hover {
        transform: scale(1.06);
        box-shadow: 0 10px 24px rgba(0, 132, 255, 0.5);
    }
    
    .chatbot-float-btn i {
        color: white;
        font-size: 24px;
    }
    
    /* Chatbot Container */
    .chatbot-widget {
        position: fixed;
        bottom: 170px;
        right: 20px;
        width: 380px;
        height: 620px;
        background: white;
        border-radius: 20px;
        border: 1px solid #d9dde3;
        box-shadow: 0 18px 42px rgba(16, 24, 40, 0.2);
        display: none;
        flex-direction: column;
        z-index: 9999;
        overflow: hidden;
        animation: slideUp 0.3s ease-out;
    }
    
    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .chatbot-widget.active {
        display: flex;
    }
    
    /* Chatbot Header */
    .chatbot-header {
        background: #0084ff;
        color: white;
        padding: 14px 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid rgba(0, 0, 0, 0.04);
    }
    
    .chatbot-header-info {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .chatbot-avatar {
        width: 36px;
        height: 36px;
        background: rgba(255, 255, 255, 0.25);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .chatbot-avatar i {
        font-size: 18px;
    }
    
    .chatbot-title h4 {
        margin: 0;
        font-size: 15px;
        font-weight: 600;
    }
    
    .chatbot-title p {
        margin: 0;
        font-size: 11px;
        opacity: 0.95;
    }
    
    .chatbot-close-btn {
        background: none;
        border: none;
        color: white;
        font-size: 20px;
        cursor: pointer;
        padding: 0;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }
    
    .chatbot-close-btn:hover {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
    }
    
    /* Chatbot Messages Area */
    .chatbot-messages {
        flex: 1;
        padding: 16px 14px;
        overflow-y: auto;
        background: #f0f2f5;
        scroll-behavior: smooth;
    }
    
    .chatbot-message {
        margin-bottom: 10px;
        display: flex;
        align-items: flex-end;
        gap: 8px;
        animation: fadeIn 0.3s ease-out;
    }
    
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .message-avatar {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    
    .message-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
        display: block;
    }
    
    .bot-avatar {
        background: #0084ff;
        color: white;
    }
    
    .user-avatar {
        background: #dbe7ff;
        color: #0084ff;
    }
    
    .message-content {
        max-width: 78%;
        display: flex;
        flex-direction: column;
    }
    
    .message-bubble {
        padding: 10px 14px;
        border-radius: 18px;
        word-wrap: break-word;
        line-height: 1.5;
        font-size: 14px;
        display: inline-block;
        max-width: 100%;
    }
    
    .bot-message .message-bubble {
        background: #e4e6eb;
        color: #050505;
        border: none;
        border-radius: 18px 18px 18px 6px;
    }
    
    .user-message {
        flex-direction: row-reverse;
    }
    
    .bot-message .message-content {
        align-items: flex-start;
    }
    
    .user-message .message-content {
        align-items: flex-end;
    }
    
    .user-message .message-bubble {
        background: #0084ff;
        color: white;
        border-radius: 18px 18px 6px 18px;
    }
    
    .message-time {
        font-size: 10px;
        color: #65676b;
        margin-top: 3px;
        padding: 0 4px;
        display: block;
    }
    
    .chatbot-sources-note {
        margin: -2px 0 10px 36px;
        font-size: 11px;
        color: #65676b;
        line-height: 1.4;
    }
    
    .chatbot-sources-note strong {
        color: #1c1e21;
    }
    
    .typing-indicator {
        display: flex;
        gap: 4px;
        padding: 10px 14px;
        background: #e4e6eb;
        border-radius: 18px;
        border: none;
        width: fit-content;
    }
    
    .typing-dot {
        width: 8px;
        height: 8px;
        background: #4361ee;
        border-radius: 50%;
        animation: typing 1.4s infinite;
    }
    
    .typing-dot:nth-child(2) {
        animation-delay: 0.2s;
    }
    
    .typing-dot:nth-child(3) {
        animation-delay: 0.4s;
    }
    
    @keyframes typing {
        0%, 60%, 100% {
            transform: translateY(0);
            opacity: 0.7;
        }
        30% {
            transform: translateY(-10px);
            opacity: 1;
        }
    }
    
    /* Chatbot Input */
    .chatbot-input-area {
        padding: 10px 12px;
        background: white;
        border-top: 1px solid #d9dde3;
        display: flex;
        gap: 8px;
        align-items: center;
    }
    
    .chatbot-input {
        flex: 1;
        border: none;
        border-radius: 20px;
        background: #f0f2f5;
        padding: 10px 14px;
        font-size: 14px;
        outline: none;
        transition: all 0.2s;
        font-family: inherit;
    }
    
    .chatbot-input:focus {
        box-shadow: inset 0 0 0 2px rgba(0, 132, 255, 0.35);
    }
    
    .chatbot-send-btn {
        width: 36px;
        height: 36px;
        background: #0084ff;
        border: none;
        border-radius: 50%;
        color: white;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        flex-shrink: 0;
    }
    
    .chatbot-send-btn:hover:not(:disabled) {
        transform: scale(1.06);
        box-shadow: 0 8px 16px rgba(0, 132, 255, 0.4);
    }
    
    .chatbot-send-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    
    /* Quick Actions */
    .quick-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 12px;
    }
    
    .quick-action-btn {
        background: white;
        border: 1px solid #ccd0d5;
        color: #1c1e21;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 11px;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .quick-action-btn:hover {
        background: #e7f3ff;
        border-color: #0084ff;
        color: #0084ff;
    }
    
    /* Welcome Message */
    .welcome-message {
        text-align: left;
        padding: 16px 10px;
        color: #65676b;
    }
    
    .welcome-message i {
        font-size: 28px;
        color: #0084ff;
        margin-bottom: 10px;
    }
    
    .welcome-message h3 {
        font-size: 16px;
        margin-bottom: 8px;
        color: #1c1e21;
    }
    
    .welcome-message p {
        font-size: 13px;
        margin-bottom: 10px;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .chatbot-widget {
            width: calc(100vw - 40px);
            height: calc(100vh - 140px);
            right: 20px;
        }
    }
    
    /* Scrollbar */
    .chatbot-messages::-webkit-scrollbar {
        width: 6px;
    }
    
    .chatbot-messages::-webkit-scrollbar-track {
        background: #e9ecef;
    }
    
    .chatbot-messages::-webkit-scrollbar-thumb {
        background: #c5ccd5;
        border-radius: 3px;
    }
    
    .chatbot-messages::-webkit-scrollbar-thumb:hover {
        background: #a8b3c2;
    }
</style>

<!-- Chatbot Float Button -->
<button class="chatbot-float-btn" id="chatbotFloatBtn" onclick="toggleChatbot()">
    <i class="fas fa-comments"></i>
</button>

<!-- Chatbot Widget -->
<div class="chatbot-widget" id="chatbotWidget">
    <!-- Header -->
    <div class="chatbot-header">
        <div class="chatbot-header-info">
            <div class="chatbot-avatar">
                <i class="fas fa-robot"></i>
            </div>
            <div class="chatbot-title">
                <h4>Document Assistant</h4>
                <p>Active now</p>
            </div>
        </div>
        <button class="chatbot-close-btn" onclick="toggleChatbot()">
            <i class="fas fa-times"></i>
        </button>
    </div>
    
    <!-- Messages Area -->
    <div class="chatbot-messages" id="chatbotMessages">
        <div class="welcome-message">
            <i class="fas fa-comments"></i>
            <h3>Hello! How can I help you today?</h3>
            <p>I can assist you with information about ordinances, resolutions, and meeting minutes.</p>
            <div class="quick-actions">
                <button class="quick-action-btn" onclick="sendQuickMessage('What are the latest ordinances?')">
                    <i class="fas fa-file-alt"></i> Latest Ordinances
                </button>
                <button class="quick-action-btn" onclick="sendQuickMessage('Show me recent resolutions')">
                    <i class="fas fa-gavel"></i> Recent Resolutions
                </button>
                <button class="quick-action-btn" onclick="sendQuickMessage('What are the latest meeting minutes?')">
                    <i class="fas fa-clipboard"></i> Meeting Minutes
                </button>
                <button class="quick-action-btn" onclick="sendQuickMessage('How do I search for documents?')">
                    <i class="fas fa-search"></i> Search Help
                </button>
            </div>
        </div>
    </div>
    
    <!-- Input Area -->
    <div class="chatbot-input-area">
        <input 
            type="text" 
            class="chatbot-input" 
            id="chatbotInput" 
            placeholder="Type your question here..."
            onkeypress="handleChatKeyPress(event)"
        >
        <button class="chatbot-send-btn" id="chatbotSendBtn" onclick="sendMessage()">
            <i class="fas fa-paper-plane"></i>
        </button>
    </div>
</div>

<script>
// Chatbot Widget JavaScript
let chatbotIsOpen = false;
let chatbotSessionId = null;
let isProcessing = false;
let chatbotHistory = [];
const chatbotUserId = '<?php echo isset($_SESSION["user_id"]) ? $_SESSION["user_id"] : "guest"; ?>';
const chatbotUserAvatarUrl = <?php echo json_encode($chatbot_profile_picture_path); ?>;
const chatbotSessionStorageKey = `efind_chatbot_session_${chatbotUserId}`;
const chatbotHistoryStorageKey = `efind_chatbot_history_${chatbotUserId}`;

// Initialize chatbot session
function initChatbotSession() {
    if (!chatbotSessionId) {
        const storedSessionId = localStorage.getItem(chatbotSessionStorageKey);
        chatbotSessionId = storedSessionId || ('session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9));
        localStorage.setItem(chatbotSessionStorageKey, chatbotSessionId);
    }
}

// Restore chat history from storage
function restoreChatHistory() {
    const messagesContainer = document.getElementById('chatbotMessages');
    const storedHistory = localStorage.getItem(chatbotHistoryStorageKey);
    
    if (!storedHistory) {
        return;
    }
    
    try {
        const parsedHistory = JSON.parse(storedHistory);
        
        if (!Array.isArray(parsedHistory) || parsedHistory.length === 0) {
            return;
        }
        
        chatbotHistory = parsedHistory;
        
        const welcomeMsg = messagesContainer.querySelector('.welcome-message');
        if (welcomeMsg) {
            welcomeMsg.remove();
        }
        
        parsedHistory.forEach((entry) => {
            if (entry.type === 'message') {
                addMessageToChat(entry.message, entry.sender, entry.time, false);
            } else if (entry.type === 'sources' && Array.isArray(entry.sources)) {
                addSourcesToChat(entry.sources, false);
            }
        });
    } catch (error) {
        console.error('Failed to restore chatbot history:', error);
        localStorage.removeItem(chatbotHistoryStorageKey);
        chatbotHistory = [];
    }
}

// Save chat history to storage
function persistChatHistory() {
    localStorage.setItem(chatbotHistoryStorageKey, JSON.stringify(chatbotHistory));
}

// Toggle chatbot visibility
function toggleChatbot() {
    const widget = document.getElementById('chatbotWidget');
    const floatBtn = document.getElementById('chatbotFloatBtn');
    
    chatbotIsOpen = !chatbotIsOpen;
    
    if (chatbotIsOpen) {
        widget.classList.add('active');
        floatBtn.style.transform = 'rotate(180deg)';
        initChatbotSession();
        
        // Focus input
        setTimeout(() => {
            document.getElementById('chatbotInput').focus();
        }, 300);
    } else {
        widget.classList.remove('active');
        floatBtn.style.transform = 'rotate(0deg)';
    }
}

// Handle Enter key press
function handleChatKeyPress(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
}

// Send quick message
function sendQuickMessage(message) {
    document.getElementById('chatbotInput').value = message;
    sendMessage();
}

// Send message to chatbot
async function sendMessage() {
    const input = document.getElementById('chatbotInput');
    const message = input.value.trim();
    
    if (!message || isProcessing) return;
    
    // Clear input
    input.value = '';
    
    // Add user message to chat
    addMessageToChat(message, 'user');
    
    // Show typing indicator
    showTypingIndicator();
    
    isProcessing = true;
    const sendBtn = document.getElementById('chatbotSendBtn');
    sendBtn.disabled = true;
    
    try {
        // Determine the correct API path based on current location
        const currentPath = window.location.pathname;
        const apiPath = currentPath.includes('/admin/') 
            ? 'api.php/chat' 
            : '/admin/api.php/chat';
        
        console.log('Sending message to:', apiPath);
        
        // Send to API
        const response = await fetch(apiPath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                message: message,
                sessionId: chatbotSessionId,
                userId: chatbotUserId,
                context: {
                    page: 'documents',
                    categories: ['ordinances', 'resolutions', 'minutes']
                },
                timestamp: new Date().toISOString()
            })
        });
        
        console.log('Response status:', response.status);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        console.log('Response data:', data);
        
        // Remove typing indicator
        hideTypingIndicator();
        
        // Add bot response
        if (data.output) {
            addMessageToChat(data.output, 'bot');
            
            // Show sources if available
            if (data.sources && data.sources.length > 0) {
                addSourcesToChat(data.sources);
            }
        } else if (data.error) {
            addMessageToChat('Sorry, I encountered an error: ' + data.error, 'bot');
        } else {
            addMessageToChat('Sorry, I received an unexpected response format.', 'bot');
        }
        
    } catch (error) {
        console.error('Chatbot error details:', error);
        hideTypingIndicator();
        addMessageToChat('Sorry, I\'m having trouble connecting. Please check the console for details. Error: ' + error.message, 'bot');
    } finally {
        isProcessing = false;
        sendBtn.disabled = false;
        input.focus();
    }
}

// Add message to chat
function createMessageAvatar(sender) {
    const avatarDiv = document.createElement('div');
    avatarDiv.className = `message-avatar ${sender}-avatar`;
    const fallbackIcon = `<i class="fas fa-${sender === 'bot' ? 'robot' : 'user'}"></i>`;
    avatarDiv.innerHTML = fallbackIcon;
    
    if (sender === 'user' && chatbotUserAvatarUrl) {
        const avatarImg = document.createElement('img');
        avatarImg.alt = '';
        avatarImg.onload = function() {
            avatarDiv.innerHTML = '';
            avatarDiv.appendChild(avatarImg);
        };
        avatarImg.onerror = function() {
            avatarDiv.innerHTML = fallbackIcon;
        };
        avatarImg.src = chatbotUserAvatarUrl;
    }
    
    return avatarDiv;
}

function addMessageToChat(message, sender, time = null, persist = true) {
    const messagesContainer = document.getElementById('chatbotMessages');
    const welcomeMsg = messagesContainer.querySelector('.welcome-message');
    
    // Remove welcome message if exists
    if (welcomeMsg) {
        welcomeMsg.remove();
    }
    
    const messageDiv = document.createElement('div');
    messageDiv.className = `chatbot-message ${sender}-message`;
    
    const messageTime = time || new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    const avatarDiv = createMessageAvatar(sender);
    const contentDiv = document.createElement('div');
    contentDiv.className = 'message-content';
    contentDiv.innerHTML = `
        <div class="message-bubble">${escapeHtml(message)}</div>
        <span class="message-time">${messageTime}</span>
    `;
    
    messageDiv.appendChild(avatarDiv);
    messageDiv.appendChild(contentDiv);
    messagesContainer.appendChild(messageDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
    
    if (persist) {
        chatbotHistory.push({
            type: 'message',
            sender: sender,
            message: message,
            time: messageTime
        });
        persistChatHistory();
    }
}

// Add sources to chat
function addSourcesToChat(sources, persist = true) {
    if (!Array.isArray(sources) || sources.length === 0) {
        return;
    }
    
    const messagesContainer = document.getElementById('chatbotMessages');
    
    const sourcesDiv = document.createElement('div');
    sourcesDiv.className = 'chatbot-sources-note';
    sourcesDiv.innerHTML = `<strong>Sources:</strong> ${sources.map((source, index) => `${index + 1}. ${escapeHtml(source)}`).join(', ')}`;
    messagesContainer.appendChild(sourcesDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
    
    if (persist) {
        chatbotHistory.push({
            type: 'sources',
            sources: sources
        });
        persistChatHistory();
    }
}

// Show typing indicator
function showTypingIndicator() {
    const messagesContainer = document.getElementById('chatbotMessages');
    
    const typingDiv = document.createElement('div');
    typingDiv.id = 'typingIndicator';
    typingDiv.className = 'chatbot-message bot-message';
    typingDiv.innerHTML = `
        <div class="message-avatar bot-avatar">
            <i class="fas fa-robot"></i>
        </div>
        <div class="message-content">
            <div class="typing-indicator">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
            </div>
        </div>
    `;
    
    messagesContainer.appendChild(typingDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

// Hide typing indicator
function hideTypingIndicator() {
    const indicator = document.getElementById('typingIndicator');
    if (indicator) {
        indicator.remove();
    }
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initChatbotSession();
    restoreChatHistory();
});
</script>
