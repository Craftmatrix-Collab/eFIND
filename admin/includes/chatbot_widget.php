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
<!-- AI Chatbot Widget for Executive Orders, Resolutions, and Meeting Minutes -->
<style>
    .chatbot-widget,
    .chatbot-widget * {
        box-sizing: border-box;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
    }

    .chatbot-float-btn {
        position: fixed;
        bottom: 96px;
        right: 20px;
        width: 56px;
        height: 56px;
        background: #4a90d9;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 8px 18px rgba(74, 144, 217, 0.35);
        z-index: 9998;
        transition: background 0.3s ease, transform 0.2s ease;
        border: none;
    }

    .chatbot-float-btn:hover {
        background: #3a7bc8;
        transform: scale(1.05);
    }

    .chatbot-float-btn i {
        color: white;
        font-size: 22px;
    }

    .chatbot-widget {
        position: fixed;
        bottom: 164px;
        right: 20px;
        width: min(450px, calc(100vw - 20px));
        height: 640px;
        max-height: calc(100vh - 90px);
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
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

    .chatbot-header {
        background: #4a90d9;
        color: white;
        padding: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .chatbot-header-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .chatbot-avatar {
        width: 40px;
        height: 40px;
        background: white;
        color: #4a90d9;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        border: none;
    }

    .chatbot-title h4 {
        margin: 0;
        font-size: 18px;
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
        font-size: 22px;
        cursor: pointer;
        padding: 0;
        width: 34px;
        height: 34px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .chatbot-close-btn:hover {
        opacity: 0.85;
    }

    .chatbot-messages {
        flex: 1;
        overflow-y: auto;
        padding: 20px;
        background: #f9f9f9;
    }

    .chatbot-message {
        margin-bottom: 12px;
        display: flex;
        align-items: flex-end;
        gap: 10px;
        animation: fadeIn 0.3s ease;
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
        background: #e8e8e8;
        color: #4a90d9;
    }

    .user-avatar {
        background: #d9e8f7;
        color: #4a90d9;
    }

    .message-content {
        max-width: 80%;
        display: flex;
        flex-direction: column;
    }

    .bot-message .message-content {
        align-items: flex-start;
    }

    .user-message .message-content {
        align-items: flex-end;
    }

    .message-bubble {
        max-width: 100%;
        padding: 12px 16px;
        border-radius: 16px;
        font-size: 14px;
        line-height: 1.5;
        word-wrap: break-word;
    }

    .bot-message .message-bubble {
        background: #e8e8e8;
        color: #333;
        border-bottom-left-radius: 4px;
    }

    .user-message {
        flex-direction: row-reverse;
    }

    .user-message .message-bubble {
        background: #4a90d9;
        color: white;
        border-bottom-right-radius: 4px;
    }

    .message-time {
        font-size: 11px;
        color: #8a8a8a;
        margin-top: 4px;
        padding: 0 2px;
    }

    .chatbot-sources-note {
        margin: -4px 0 12px 42px;
        font-size: 12px;
        color: #666;
        line-height: 1.4;
    }

    .chatbot-sources-note strong {
        color: #333;
    }

    .chatbot-doc-link {
        color: #0d6efd;
        text-decoration: underline;
        cursor: pointer;
    }

    .chatbot-doc-link:hover,
    .chatbot-doc-link:focus {
        color: #0d6efd;
    }

    #chatbotDocumentImageModal .modal-body {
        background: #f8f9fb;
        padding: 0;
    }

    #chatbotDocumentImageModal .carousel-inner {
        max-height: 70vh;
    }

    #chatbotDocumentImageModal .carousel-item {
        height: 70vh;
        padding: 16px;
    }

    #chatbotDocumentImageModal .carousel-item img {
        max-height: 100%;
        max-width: 100%;
        width: auto;
        object-fit: contain;
        display: block;
        margin: 0 auto;
        border-radius: 8px;
    }

    #chatbotDocumentImageModal .carousel-indicators [data-bs-target] {
        background-color: #4a90d9;
    }

    #chatbotDocumentImageModal .chatbot-doc-empty {
        margin: 0;
        padding: 24px;
        text-align: center;
        color: #6c757d;
    }

    .typing-indicator {
        display: flex;
        gap: 4px;
        padding: 12px 16px;
        background: #e8e8e8;
        border-radius: 16px;
        border-bottom-left-radius: 4px;
    }

    .typing-dot {
        width: 8px;
        height: 8px;
        background: #4a90d9;
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

    .chatbot-input-area {
        padding: 16px;
        background: white;
        border-top: 1px solid #eee;
        display: flex;
        gap: 12px;
        align-items: center;
    }

    .chatbot-input {
        flex: 1;
        padding: 12px 16px;
        border: 2px solid #e0e0e0;
        border-radius: 24px;
        font-size: 14px;
        outline: none;
        transition: border-color 0.3s ease;
    }

    .chatbot-input:focus {
        border-color: #4a90d9;
    }

    .chatbot-send-btn {
        background: #4a90d9;
        color: white;
        border: none;
        padding: 12px 22px;
        border-radius: 24px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.3s ease, transform 0.2s ease;
        min-width: 78px;
    }

    .chatbot-send-btn:hover:not(:disabled) {
        background: #3a7bc8;
    }

    .chatbot-send-btn:active:not(:disabled) {
        transform: scale(0.95);
    }

    .chatbot-send-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .quick-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px;
    }

    .quick-action-btn {
        background: white;
        border: 1px solid #d9d9d9;
        color: #4a4a4a;
        padding: 6px 12px;
        border-radius: 16px;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .quick-action-btn:hover {
        background: #f2f7fd;
        border-color: #4a90d9;
        color: #3a7bc8;
    }

    .welcome-message {
        color: #666;
    }

    .welcome-message i {
        font-size: 28px;
        color: #4a90d9;
        margin-bottom: 10px;
    }

    .welcome-message h3 {
        font-size: 18px;
        margin-bottom: 8px;
        color: #333;
    }

    .welcome-message p {
        font-size: 14px;
        margin-bottom: 12px;
    }

    @media (max-width: 768px) {
        .chatbot-widget {
            width: calc(100vw - 20px);
            right: 10px;
            bottom: 84px;
            height: calc(100vh - 110px);
        }

        .chatbot-float-btn {
            right: 12px;
            bottom: 18px;
        }

        #chatbotDocumentImageModal .carousel-item {
            height: 55vh;
            padding: 12px;
        }
    }

    .chatbot-messages::-webkit-scrollbar {
        width: 6px;
    }

    .chatbot-messages::-webkit-scrollbar-track {
        background: transparent;
    }

    .chatbot-messages::-webkit-scrollbar-thumb {
        background: #ccc;
        border-radius: 3px;
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
                <p>Online</p>
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
            <p>I can assist you with information about executive_orders, resolutions, and meeting minutes.</p>
            <div class="quick-actions">
                <button class="quick-action-btn" onclick="sendQuickMessage('What are the latest executive_orders?')">
                    <i class="fas fa-file-alt"></i> Latest Executive Orders
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
        <button class="chatbot-send-btn" id="chatbotSendBtn" onclick="sendMessage()">Send</button>
    </div>
</div>

<!-- Chatbot Document Image Modal -->
<div class="modal fade" id="chatbotDocumentImageModal" tabindex="-1" aria-labelledby="chatbotDocumentImageModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="chatbotDocumentImageModalLabel">Document Image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="chatbotDocumentImageCarousel" class="carousel slide" data-bs-interval="false">
                    <div class="carousel-indicators" id="chatbotDocumentImageIndicators"></div>
                    <div class="carousel-inner" id="chatbotDocumentImageBody"></div>
                    <button class="carousel-control-prev" id="chatbotDocumentImagePrev" type="button" data-bs-target="#chatbotDocumentImageCarousel" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Previous</span>
                    </button>
                    <button class="carousel-control-next" id="chatbotDocumentImageNext" type="button" data-bs-target="#chatbotDocumentImageCarousel" data-bs-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Next</span>
                    </button>
                </div>
            </div>
        </div>
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
const chatbotDocumentMentionMatchers = [
    {
        type: 'resolution',
        regex: /\b(resolution(?:\s*(?:no\.?|number|#))?)\s*[:#-]?\s*([A-Za-z0-9][A-Za-z0-9\-\/]*\d[A-Za-z0-9\-\/]*)\b/gi,
        numberGroup: 2
    },
    {
        type: 'executive_order',
        regex: /\b(executive\s+order(?:\s*(?:no\.?|number|#))?)\s*[:#-]?\s*([A-Za-z0-9][A-Za-z0-9\-\/]*\d[A-Za-z0-9\-\/]*)\b/gi,
        numberGroup: 2
    },
    {
        type: 'minutes',
        regex: /\b((?:(?:minutes?\s+of\s+meeting|meeting\s+minutes)(?:\s*(?:no\.?|number|#))?|session\s*(?:no\.?|number|#)))\s*[:#-]?\s*([A-Za-z0-9][A-Za-z0-9\-\/]*\d[A-Za-z0-9\-\/]*)\b/gi,
        numberGroup: 2
    },
    {
        type: 'executive_order',
        regex: /\b(EO[-\s]?\d{4}[-\/]\d+)\b/gi,
        numberGroup: 1
    },
    {
        type: 'resolution',
        regex: /\b(RES[-\s]?\d{4}[-\/]\d+)\b/gi,
        numberGroup: 1
    }
];

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

function getChatbotApiBasePath() {
    return window.location.pathname.includes('/admin/') ? 'api.php' : '/admin/api.php';
}

function collectDocumentMentions(message) {
    if (typeof message !== 'string' || message.length === 0) {
        return [];
    }
    
    const matches = [];
    chatbotDocumentMentionMatchers.forEach((matcher) => {
        matcher.regex.lastIndex = 0;
        let match;
        while ((match = matcher.regex.exec(message)) !== null) {
            const matchedNumber = (match[matcher.numberGroup] || '').trim();
            if (!matchedNumber) {
                continue;
            }
            
            matches.push({
                start: match.index,
                end: match.index + match[0].length,
                label: match[0].trim(),
                type: matcher.type,
                number: matchedNumber
            });
        }
    });
    
    if (matches.length === 0) {
        return [];
    }
    
    matches.sort((a, b) => (a.start - b.start) || (b.end - a.end));
    
    const dedupedMatches = [];
    let currentEnd = -1;
    matches.forEach((match) => {
        if (match.start < currentEnd) {
            return;
        }
        dedupedMatches.push(match);
        currentEnd = match.end;
    });
    
    return dedupedMatches;
}

function formatMessageWithDocumentLinks(message, sender) {
    const safeMessage = typeof message === 'string' ? message : String(message ?? '');
    if (sender !== 'bot') {
        return escapeHtml(safeMessage);
    }
    
    const mentions = collectDocumentMentions(safeMessage);
    if (mentions.length === 0) {
        return escapeHtml(safeMessage);
    }
    
    let html = '';
    let cursor = 0;
    
    mentions.forEach((mention) => {
        html += escapeHtml(safeMessage.slice(cursor, mention.start));
        html += `<a href="#" class="chatbot-doc-link" data-doc-type="${mention.type}" data-doc-number="${encodeURIComponent(mention.number)}">${escapeHtml(mention.label)}</a>`;
        cursor = mention.end;
    });
    
    html += escapeHtml(safeMessage.slice(cursor));
    return html;
}

async function handleChatbotDocumentLinkClick(event) {
    const link = event.target.closest('.chatbot-doc-link');
    if (!link) {
        return;
    }
    
    event.preventDefault();
    
    const documentType = (link.getAttribute('data-doc-type') || '').trim();
    const encodedNumber = link.getAttribute('data-doc-number') || '';
    const documentNumber = decodeURIComponent(encodedNumber);
    
    if (!documentType || !documentNumber) {
        return;
    }
    
    const lookupPath = `${getChatbotApiBasePath()}/document-image?type=${encodeURIComponent(documentType)}&number=${encodeURIComponent(documentNumber)}`;
    
    try {
        const response = await fetch(lookupPath, {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            }
        });
        const data = await response.json();
        
        if (!response.ok || !data || data.status !== 'success' || !data.document) {
            throw new Error(data && data.error ? data.error : 'Document image not found.');
        }
        
        openChatbotDocumentImage(data.document);
    } catch (error) {
        console.error('Document image preview error:', error);
        addMessageToChat("I couldn't load that document image.", 'bot');
    }
}

function openChatbotDocumentImage(documentData) {
    const imagePaths = (Array.isArray(documentData.image_paths) ? documentData.image_paths : [])
        .map((path) => String(path).trim())
        .filter(Boolean);

    if (!imagePaths.length) {
        return;
    }

    const modalElement = document.getElementById('chatbotDocumentImageModal');
    const modalTitle = document.getElementById('chatbotDocumentImageModalLabel');
    const modalBody = document.getElementById('chatbotDocumentImageBody');
    const modalIndicators = document.getElementById('chatbotDocumentImageIndicators');
    const prevControl = document.getElementById('chatbotDocumentImagePrev');
    const nextControl = document.getElementById('chatbotDocumentImageNext');
    const carouselElement = document.getElementById('chatbotDocumentImageCarousel');

    if (!modalElement || !modalBody || !modalIndicators || !prevControl || !nextControl || !carouselElement) {
        window.open(imagePaths[0], '_blank', 'noopener,noreferrer');
        return;
    }

    modalBody.innerHTML = '';
    modalIndicators.innerHTML = '';
    if (modalTitle) {
        const numberText = (documentData && documentData.number) ? String(documentData.number).trim() : '';
        modalTitle.textContent = numberText ? `Document Image - ${numberText}` : 'Document Image';
    }

    imagePaths.forEach((src, index) => {
        const item = document.createElement('div');
        item.className = `carousel-item${index === 0 ? ' active' : ''}`;

        const img = document.createElement('img');
        img.src = src;
        img.alt = `Document image ${index + 1}`;
        item.appendChild(img);
        modalBody.appendChild(item);

        const indicator = document.createElement('button');
        indicator.type = 'button';
        indicator.setAttribute('data-bs-target', '#chatbotDocumentImageCarousel');
        indicator.setAttribute('data-bs-slide-to', String(index));
        indicator.setAttribute('aria-label', `Slide ${index + 1}`);
        if (index === 0) {
            indicator.className = 'active';
            indicator.setAttribute('aria-current', 'true');
        }
        modalIndicators.appendChild(indicator);
    });

    const hasMultipleImages = imagePaths.length > 1;
    prevControl.style.display = hasMultipleImages ? '' : 'none';
    nextControl.style.display = hasMultipleImages ? '' : 'none';
    modalIndicators.style.display = hasMultipleImages ? '' : 'none';

    if (window.bootstrap && window.bootstrap.Carousel) {
        bootstrap.Carousel.getOrCreateInstance(carouselElement).to(0);
    }

    if (window.bootstrap && window.bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalElement).show();
    } else {
        window.open(imagePaths[0], '_blank', 'noopener,noreferrer');
    }
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
        const apiPath = `${getChatbotApiBasePath()}/chat`;
        
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
                    categories: ['executive_orders', 'resolutions', 'minutes']
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
        <div class="message-bubble">${formatMessageWithDocumentLinks(message, sender)}</div>
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
    const messagesContainer = document.getElementById('chatbotMessages');
    if (messagesContainer) {
        messagesContainer.addEventListener('click', handleChatbotDocumentLinkClick);
    }
});
</script>
