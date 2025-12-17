<!-- AI Chatbot Widget for Ordinances, Resolutions, and Meeting Minutes -->
<style>
    /* Chatbot Button */
    .chatbot-float-btn {
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 60px;
        height: 60px;
        background: linear-gradient(135deg, #4361ee, #3a0ca3);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 4px 15px rgba(67, 97, 238, 0.4);
        z-index: 9998;
        transition: all 0.3s ease;
        border: none;
    }
    
    .chatbot-float-btn:hover {
        transform: scale(1.1);
        box-shadow: 0 6px 20px rgba(67, 97, 238, 0.6);
    }
    
    .chatbot-float-btn i {
        color: white;
        font-size: 24px;
    }
    
    .chatbot-badge {
        position: absolute;
        top: -5px;
        right: -5px;
        background: #ff6d00;
        color: white;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        font-size: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
    }
    
    /* Chatbot Container */
    .chatbot-widget {
        position: fixed;
        bottom: 90px;
        right: 20px;
        width: 400px;
        height: 600px;
        background: white;
        border-radius: 16px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
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
        background: linear-gradient(135deg, #4361ee, #3a0ca3);
        color: white;
        padding: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .chatbot-header-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .chatbot-avatar {
        width: 40px;
        height: 40px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .chatbot-avatar i {
        font-size: 20px;
    }
    
    .chatbot-title h4 {
        margin: 0;
        font-size: 16px;
        font-weight: 600;
    }
    
    .chatbot-title p {
        margin: 0;
        font-size: 12px;
        opacity: 0.9;
    }
    
    .chatbot-close-btn {
        background: none;
        border: none;
        color: white;
        font-size: 24px;
        cursor: pointer;
        padding: 0;
        width: 30px;
        height: 30px;
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
        padding: 20px;
        overflow-y: auto;
        background: #f8f9fa;
        scroll-behavior: smooth;
    }
    
    .chatbot-message {
        margin-bottom: 16px;
        display: flex;
        gap: 10px;
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
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    
    .bot-avatar {
        background: linear-gradient(135deg, #4361ee, #3a0ca3);
        color: white;
    }
    
    .user-avatar {
        background: #e8f0fe;
        color: #4361ee;
    }
    
    .message-content {
        flex: 1;
        max-width: calc(100% - 42px);
    }
    
    .message-bubble {
        padding: 12px 16px;
        border-radius: 12px;
        word-wrap: break-word;
        line-height: 1.5;
        font-size: 14px;
    }
    
    .bot-message .message-bubble {
        background: white;
        border: 1px solid #e0e0e0;
        border-radius: 12px 12px 12px 4px;
    }
    
    .user-message {
        flex-direction: row-reverse;
    }
    
    .user-message .message-bubble {
        background: linear-gradient(135deg, #4361ee, #3a0ca3);
        color: white;
        border-radius: 12px 12px 4px 12px;
    }
    
    .message-time {
        font-size: 11px;
        color: #999;
        margin-top: 4px;
        display: block;
    }
    
    .typing-indicator {
        display: flex;
        gap: 4px;
        padding: 12px 16px;
        background: white;
        border-radius: 12px;
        border: 1px solid #e0e0e0;
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
        padding: 15px 20px;
        background: white;
        border-top: 1px solid #e0e0e0;
        display: flex;
        gap: 10px;
        align-items: center;
    }
    
    .chatbot-input {
        flex: 1;
        border: 2px solid #e0e0e0;
        border-radius: 24px;
        padding: 10px 16px;
        font-size: 14px;
        outline: none;
        transition: all 0.2s;
        font-family: inherit;
    }
    
    .chatbot-input:focus {
        border-color: #4361ee;
        box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
    }
    
    .chatbot-send-btn {
        width: 40px;
        height: 40px;
        background: linear-gradient(135deg, #4361ee, #3a0ca3);
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
        transform: scale(1.1);
        box-shadow: 0 4px 12px rgba(67, 97, 238, 0.4);
    }
    
    .chatbot-send-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    
    /* Quick Actions */
    .quick-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 8px;
    }
    
    .quick-action-btn {
        background: white;
        border: 1px solid #4361ee;
        color: #4361ee;
        padding: 6px 12px;
        border-radius: 16px;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .quick-action-btn:hover {
        background: #4361ee;
        color: white;
    }
    
    /* Welcome Message */
    .welcome-message {
        text-align: center;
        padding: 40px 20px;
        color: #666;
    }
    
    .welcome-message i {
        font-size: 48px;
        color: #4361ee;
        margin-bottom: 16px;
    }
    
    .welcome-message h3 {
        font-size: 18px;
        margin-bottom: 8px;
        color: #2b2d42;
    }
    
    .welcome-message p {
        font-size: 14px;
        margin-bottom: 20px;
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
        background: #f1f1f1;
    }
    
    .chatbot-messages::-webkit-scrollbar-thumb {
        background: #4361ee;
        border-radius: 3px;
    }
    
    .chatbot-messages::-webkit-scrollbar-thumb:hover {
        background: #3a0ca3;
    }
</style>

<!-- Chatbot Float Button -->
<button class="chatbot-float-btn" id="chatbotFloatBtn" onclick="toggleChatbot()">
    <i class="fas fa-comments"></i>
    <span class="chatbot-badge" id="chatbotBadge" style="display: none;">1</span>
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
                <p>Ask about ordinances, resolutions & minutes</p>
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

// Initialize chatbot session
function initChatbotSession() {
    if (!chatbotSessionId) {
        chatbotSessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }
}

// Toggle chatbot visibility
function toggleChatbot() {
    const widget = document.getElementById('chatbotWidget');
    const floatBtn = document.getElementById('chatbotFloatBtn');
    const badge = document.getElementById('chatbotBadge');
    
    chatbotIsOpen = !chatbotIsOpen;
    
    if (chatbotIsOpen) {
        widget.classList.add('active');
        floatBtn.style.transform = 'rotate(180deg)';
        badge.style.display = 'none';
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
        // Send to API
        const response = await fetch('/admin/api.php/chat', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                message: message,
                sessionId: chatbotSessionId,
                userId: '<?php echo isset($_SESSION["user_id"]) ? $_SESSION["user_id"] : "guest"; ?>',
                context: {
                    page: 'documents',
                    categories: ['ordinances', 'resolutions', 'minutes']
                },
                timestamp: new Date().toISOString()
            })
        });
        
        const data = await response.json();
        
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
            addMessageToChat('Sorry, I encountered an error. Please try again.', 'bot');
        }
        
    } catch (error) {
        console.error('Chatbot error:', error);
        hideTypingIndicator();
        addMessageToChat('Sorry, I\'m having trouble connecting. Please try again later.', 'bot');
    } finally {
        isProcessing = false;
        sendBtn.disabled = false;
        input.focus();
    }
}

// Add message to chat
function addMessageToChat(message, sender) {
    const messagesContainer = document.getElementById('chatbotMessages');
    const welcomeMsg = messagesContainer.querySelector('.welcome-message');
    
    // Remove welcome message if exists
    if (welcomeMsg) {
        welcomeMsg.remove();
    }
    
    const messageDiv = document.createElement('div');
    messageDiv.className = `chatbot-message ${sender}-message`;
    
    const time = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    
    messageDiv.innerHTML = `
        <div class="message-avatar ${sender}-avatar">
            <i class="fas fa-${sender === 'bot' ? 'robot' : 'user'}"></i>
        </div>
        <div class="message-content">
            <div class="message-bubble">${escapeHtml(message)}</div>
            <span class="message-time">${time}</span>
        </div>
    `;
    
    messagesContainer.appendChild(messageDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

// Add sources to chat
function addSourcesToChat(sources) {
    const messagesContainer = document.getElementById('chatbotMessages');
    
    const sourcesDiv = document.createElement('div');
    sourcesDiv.className = 'chatbot-message bot-message';
    sourcesDiv.style.marginTop = '-8px';
    
    let sourcesHtml = '<div class="message-content"><div class="message-bubble">';
    sourcesHtml += '<strong>Sources:</strong><br>';
    sources.forEach((source, index) => {
        sourcesHtml += `${index + 1}. ${escapeHtml(source)}<br>`;
    });
    sourcesHtml += '</div></div>';
    
    sourcesDiv.innerHTML = sourcesHtml;
    messagesContainer.appendChild(sourcesDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
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

// Show badge notification
function showChatbotNotification() {
    if (!chatbotIsOpen) {
        const badge = document.getElementById('chatbotBadge');
        badge.style.display = 'flex';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initChatbotSession();
    
    // Show notification after 5 seconds if not opened
    setTimeout(() => {
        if (!chatbotIsOpen) {
            showChatbotNotification();
        }
    }, 5000);
});
</script>
