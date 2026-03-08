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
    :root {
        --chatbot-primary: #2563eb;
        --chatbot-primary-dark: #1e40af;
        --chatbot-bg: #f8fafc;
        --chatbot-surface: #ffffff;
        --chatbot-surface-soft: #f3f4f6;
        --chatbot-text: #111827;
        --chatbot-text-muted: #6b7280;
        --chatbot-border: #e5e7eb;
        --chatbot-shadow: 0 16px 40px rgba(15, 23, 42, 0.22);
    }

    .chatbot-widget,
    .chatbot-widget *,
    .chatbot-float-btn,
    .chatbot-float-btn * {
        box-sizing: border-box;
        font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }

    .chatbot-float-btn {
        position: fixed;
        right: 20px;
        bottom: 96px;
        width: 60px;
        height: 60px;
        border: 0;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: #ffffff;
        background: linear-gradient(135deg, var(--chatbot-primary), var(--chatbot-primary-dark));
        box-shadow: 0 12px 28px rgba(37, 99, 235, 0.4);
        z-index: 9998;
        transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
    }

    .chatbot-float-btn:hover {
        transform: translateY(-2px) scale(1.04);
        box-shadow: 0 16px 34px rgba(37, 99, 235, 0.44);
        filter: saturate(1.04);
    }

    .chatbot-float-btn:focus-visible {
        outline: 3px solid rgba(37, 99, 235, 0.3);
        outline-offset: 2px;
    }

    .chatbot-float-btn i {
        font-size: 22px;
        line-height: 1;
    }

    .chatbot-widget {
        position: fixed;
        right: 20px;
        bottom: 166px;
        width: min(420px, calc(100vw - 24px));
        height: min(680px, calc(100vh - 92px));
        max-height: calc(100vh - 92px);
        display: none;
        flex-direction: column;
        overflow: hidden;
        border-radius: 20px;
        border: 1px solid rgba(17, 24, 39, 0.08);
        background: var(--chatbot-surface);
        box-shadow: var(--chatbot-shadow);
        z-index: 9999;
        animation: chatbotWidgetEnter 0.24s ease-out;
    }

    @keyframes chatbotWidgetEnter {
        from {
            opacity: 0;
            transform: translateY(12px) scale(0.99);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .chatbot-widget.active {
        display: flex;
    }

    .chatbot-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 15px 18px;
        color: #ffffff;
        background: linear-gradient(135deg, var(--chatbot-primary), var(--chatbot-primary-dark));
        border-bottom: 1px solid rgba(255, 255, 255, 0.12);
    }

    .chatbot-header-info {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
    }

    .chatbot-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        color: var(--chatbot-primary);
        background: #ffffff;
        border: 1px solid rgba(255, 255, 255, 0.75);
        flex-shrink: 0;
    }

    .chatbot-title h4 {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
        line-height: 1.2;
        letter-spacing: 0.01em;
    }

    .chatbot-status {
        margin: 4px 0 0;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.03em;
        color: rgba(255, 255, 255, 0.94);
    }

    .chatbot-status::before {
        content: "";
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #22c55e;
        box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.25);
    }

    .chatbot-close-btn {
        width: 34px;
        height: 34px;
        border: 1px solid rgba(255, 255, 255, 0.22);
        border-radius: 10px;
        padding: 0;
        color: #ffffff;
        background: rgba(255, 255, 255, 0.12);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s ease, transform 0.2s ease;
    }

    .chatbot-close-btn:hover {
        background: rgba(255, 255, 255, 0.22);
        transform: scale(1.03);
    }

    .chatbot-close-btn:focus-visible {
        outline: 2px solid rgba(255, 255, 255, 0.7);
        outline-offset: 2px;
    }

    .chatbot-messages {
        flex: 1;
        min-height: 0;
        max-height: 100%;
        overflow-y: auto;
        scroll-behavior: smooth;
        padding: 16px;
        background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
    }

    .welcome-message {
        margin-bottom: 12px;
        padding: 18px 16px;
        border: 1px solid var(--chatbot-border);
        border-radius: 16px;
        background: var(--chatbot-surface);
        color: var(--chatbot-text);
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
    }

    .welcome-message i {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        margin-bottom: 10px;
        border-radius: 11px;
        color: #ffffff;
        background: var(--chatbot-primary);
        font-size: 16px;
    }

    .welcome-message h3 {
        margin: 0 0 7px;
        font-size: 17px;
        font-weight: 700;
        line-height: 1.3;
        color: var(--chatbot-text);
    }

    .welcome-message p {
        margin: 0;
        font-size: 13px;
        line-height: 1.55;
        color: var(--chatbot-text-muted);
    }

    .quick-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 14px;
    }

    .quick-action-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid #bfdbfe;
        border-radius: 999px;
        padding: 8px 12px;
        font-size: 12px;
        font-weight: 600;
        color: var(--chatbot-primary);
        background: #eff6ff;
        cursor: pointer;
        transition: border-color 0.2s ease, background 0.2s ease, transform 0.2s ease;
    }

    .quick-action-btn:hover {
        border-color: #93c5fd;
        background: #dbeafe;
        transform: translateY(-1px);
    }

    .chatbot-message {
        margin-bottom: 14px;
        display: flex;
        align-items: flex-end;
        gap: 10px;
        animation: chatbotMessageIn 0.22s ease-out;
    }

    @keyframes chatbotMessageIn {
        from {
            opacity: 0;
            transform: translateY(8px);
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
        border: 1px solid var(--chatbot-border);
    }

    .message-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
        display: block;
    }

    .bot-avatar {
        color: var(--chatbot-primary);
        background: #ffffff;
    }

    .user-avatar {
        color: #ffffff;
        background: var(--chatbot-primary);
    }

    .message-content {
        max-width: 84%;
        display: flex;
        flex-direction: column;
    }

    .bot-message .message-content {
        align-items: flex-start;
    }

    .user-message {
        flex-direction: row-reverse;
    }

    .user-message .message-content {
        align-items: flex-end;
    }

    .message-bubble {
        max-width: 100%;
        border-radius: 16px;
        padding: 11px 14px;
        font-size: 13.5px;
        line-height: 1.55;
        word-wrap: break-word;
        white-space: pre-wrap;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
    }

    .bot-message .message-bubble {
        border: 1px solid var(--chatbot-border);
        border-bottom-left-radius: 6px;
        color: var(--chatbot-text);
        background: var(--chatbot-surface-soft);
    }

    .user-message .message-bubble {
        border: 1px solid transparent;
        border-bottom-right-radius: 6px;
        color: #ffffff;
        background: linear-gradient(135deg, var(--chatbot-primary), var(--chatbot-primary-dark));
    }

    .message-time {
        margin-top: 5px;
        padding: 0 2px;
        font-size: 10px;
        font-weight: 500;
        letter-spacing: 0.01em;
        color: #9ca3af;
    }

    .chatbot-sources-note {
        margin: -2px 0 14px 42px;
        border-left: 2px solid #bfdbfe;
        padding-left: 10px;
        font-size: 11.5px;
        line-height: 1.5;
        color: #475569;
    }

    .chatbot-sources-note strong {
        color: #1f2937;
    }

    .chatbot-doc-link {
        color: var(--chatbot-primary);
        text-decoration: underline;
        text-underline-offset: 2px;
        cursor: pointer;
    }

    .chatbot-doc-link:hover,
    .chatbot-doc-link:focus {
        color: var(--chatbot-primary-dark);
    }

    .typing-indicator {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        border: 1px solid var(--chatbot-border);
        border-radius: 16px;
        border-bottom-left-radius: 6px;
        background: var(--chatbot-surface-soft);
        padding: 10px 13px;
        box-shadow: 0 4px 10px rgba(15, 23, 42, 0.06);
    }

    .typing-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--chatbot-primary);
        animation: chatbotTyping 1.3s infinite;
    }

    .typing-dot:nth-child(2) {
        animation-delay: 0.2s;
    }

    .typing-dot:nth-child(3) {
        animation-delay: 0.4s;
    }

    @keyframes chatbotTyping {
        0%, 60%, 100% {
            transform: translateY(0);
            opacity: 0.7;
        }
        30% {
            transform: translateY(-7px);
            opacity: 1;
        }
    }

    .chatbot-warning-host {
        display: none;
        padding: 0 14px 12px;
        background: var(--chatbot-surface);
    }

    .chatbot-warning-host:not(:empty) {
        display: block;
    }

    .chatbot-warning-banner {
        display: flex;
        gap: 10px;
        border: 1px solid #fcd34d;
        border-radius: 12px;
        padding: 10px 12px;
        background: #fffbeb;
        color: #92400e;
        box-shadow: 0 4px 10px rgba(146, 64, 14, 0.08);
    }

    .chatbot-warning-banner i {
        margin-top: 2px;
        font-size: 14px;
    }

    .chatbot-warning-content p {
        margin: 0;
        font-size: 12px;
        line-height: 1.45;
        font-weight: 600;
    }

    .chatbot-warning-actions {
        margin-top: 8px;
        display: flex;
        gap: 8px;
    }

    .chatbot-warning-btn {
        border: 1px solid #f59e0b;
        border-radius: 999px;
        padding: 4px 10px;
        font-size: 11px;
        font-weight: 600;
        color: #92400e;
        background: #ffffff;
        cursor: pointer;
        transition: background 0.2s ease, transform 0.2s ease;
    }

    .chatbot-warning-btn:hover {
        background: #fef3c7;
        transform: translateY(-1px);
    }

    .chatbot-warning-btn:disabled {
        opacity: 0.55;
        cursor: not-allowed;
        transform: none;
    }

    .chatbot-warning-btn.secondary {
        border-color: #fbbf24;
        color: #78350f;
        background: #fffbeb;
    }

    .chatbot-input-area {
        position: sticky;
        bottom: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 14px;
        border-top: 1px solid var(--chatbot-border);
        background: var(--chatbot-surface);
        z-index: 2;
    }

    .chatbot-input {
        flex: 1;
        min-width: 0;
        border: 1px solid #d1d5db;
        border-radius: 12px;
        padding: 11px 14px;
        font-size: 13.5px;
        color: var(--chatbot-text);
        background: #ffffff;
        outline: none;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .chatbot-input::placeholder {
        color: #9ca3af;
    }

    .chatbot-input:focus {
        border-color: #93c5fd;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.16);
    }

    .chatbot-send-btn {
        min-width: 84px;
        border: 0;
        border-radius: 12px;
        padding: 10px 14px;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.01em;
        color: #ffffff;
        background: linear-gradient(135deg, var(--chatbot-primary), var(--chatbot-primary-dark));
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
        box-shadow: 0 8px 16px rgba(37, 99, 235, 0.28);
    }

    .chatbot-send-btn:hover:not(:disabled) {
        transform: translateY(-1px);
        filter: saturate(1.04);
        box-shadow: 0 10px 20px rgba(37, 99, 235, 0.34);
    }

    .chatbot-send-btn:active:not(:disabled) {
        transform: translateY(1px);
    }

    .chatbot-send-btn:disabled {
        opacity: 0.65;
        cursor: not-allowed;
        box-shadow: none;
    }

    #chatbotDocumentImageModal .modal-body {
        background: #f8fafc;
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
        max-width: 100%;
        max-height: 100%;
        width: auto;
        object-fit: contain;
        display: block;
        margin: 0 auto;
        border-radius: 8px;
        border: 1px solid var(--chatbot-border);
    }

    #chatbotDocumentImageModal .carousel-indicators [data-bs-target] {
        background-color: var(--chatbot-primary);
    }

    #chatbotDocumentImageModal .chatbot-doc-empty {
        margin: 0;
        padding: 24px;
        text-align: center;
        color: #6b7280;
    }

    .chatbot-messages::-webkit-scrollbar {
        width: 7px;
    }

    .chatbot-messages::-webkit-scrollbar-track {
        background: transparent;
    }

    .chatbot-messages::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 999px;
    }

    .chatbot-messages::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    @media (max-width: 768px) {
        .chatbot-widget {
            right: 10px;
            bottom: 86px;
            width: calc(100vw - 20px);
            height: calc(100vh - 112px);
            border-radius: 16px;
        }

        .chatbot-float-btn {
            right: 12px;
            bottom: 18px;
            width: 56px;
            height: 56px;
        }

        .chatbot-messages {
            padding: 14px;
        }

        .message-content {
            max-width: 88%;
        }

        .chatbot-send-btn span {
            display: none;
        }

        .chatbot-send-btn {
            min-width: 46px;
            padding: 10px;
            border-radius: 10px;
        }

        #chatbotDocumentImageModal .carousel-item {
            height: 55vh;
            padding: 12px;
        }
    }

    @media (max-width: 420px) {
        .chatbot-widget {
            right: 8px;
            width: calc(100vw - 16px);
        }

        .chatbot-header {
            padding: 14px;
        }

        .chatbot-title h4 {
            font-size: 15px;
        }

        .message-bubble {
            font-size: 13px;
            padding: 10px 13px;
        }
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
                <h4>eFINDBot</h4>
                <p class="chatbot-status">Online</p>
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
            <h3>Hi, I'm eFINDBot. How can I help?</h3>
            <p>I can help you find information from executive orders, resolutions, and meeting minutes.</p>
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

    <div class="chatbot-warning-host" id="chatbotWarningHost" aria-live="polite"></div>
    
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
            <i class="fas fa-paper-plane" aria-hidden="true"></i>
            <span>Send</span>
        </button>
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
let slowResponseWarningTimer = null;
let lastSubmittedMessage = '';
const chatbotSlowResponseDelayMs = 12000;
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

function formatChatTimestamp(timeValue = null) {
    if (typeof timeValue === 'string') {
        const trimmedValue = timeValue.trim();
        if (/^\d{1,2}:\d{2}\s?[AP]M$/i.test(trimmedValue)) {
            return trimmedValue.toUpperCase();
        }
    }

    const parsedTime = timeValue ? new Date(timeValue) : new Date();
    if (Number.isNaN(parsedTime.getTime())) {
        return new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    }

    return parsedTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
}

function scrollChatbotToLatestMessage(behavior = 'smooth') {
    const messagesContainer = document.getElementById('chatbotMessages');
    if (messagesContainer) {
        try {
            messagesContainer.scrollTo({
                top: messagesContainer.scrollHeight,
                behavior
            });
        } catch (error) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
    }
}

function clearSlowResponseWarningTimer() {
    if (slowResponseWarningTimer) {
        clearTimeout(slowResponseWarningTimer);
        slowResponseWarningTimer = null;
    }
}

function hideChatbotWarningBanner() {
    const warningHost = document.getElementById('chatbotWarningHost');
    if (warningHost) {
        warningHost.innerHTML = '';
    }
}

function showChatbotWarningBanner(message) {
    const warningHost = document.getElementById('chatbotWarningHost');
    if (!warningHost) {
        return;
    }

    warningHost.innerHTML = `
        <div class="chatbot-warning-banner" role="status">
            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
            <div class="chatbot-warning-content">
                <p>${escapeHtml(message)}</p>
                <div class="chatbot-warning-actions">
                    <button class="chatbot-warning-btn" type="button" onclick="retryLastMessage()" ${isProcessing ? 'disabled' : ''}>Retry</button>
                    <button class="chatbot-warning-btn secondary" type="button" onclick="refreshChatConversation()">Refresh</button>
                </div>
            </div>
        </div>
    `;
}

function startSlowResponseWarningTimer() {
    clearSlowResponseWarningTimer();
    slowResponseWarningTimer = setTimeout(() => {
        if (isProcessing) {
            showChatbotWarningBanner('eFINDBot is taking longer than usual. You can refresh the chat or retry once this request finishes.');
        }
    }, chatbotSlowResponseDelayMs);
}

function retryLastMessage() {
    if (isProcessing || !lastSubmittedMessage) {
        return;
    }

    const input = document.getElementById('chatbotInput');
    if (!input) {
        return;
    }

    input.value = lastSubmittedMessage;
    hideChatbotWarningBanner();
    sendMessage();
}

function refreshChatConversation() {
    const messagesContainer = document.getElementById('chatbotMessages');
    const welcomeMarkup = `
        <div class="welcome-message">
            <i class="fas fa-comments"></i>
            <h3>Hi, I'm eFINDBot. How can I help?</h3>
            <p>I can help you find information from executive orders, resolutions, and meeting minutes.</p>
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
    `;

    if (messagesContainer) {
        messagesContainer.innerHTML = welcomeMarkup;
    }

    clearSlowResponseWarningTimer();
    hideTypingIndicator();
    hideChatbotWarningBanner();
    chatbotHistory = [];
    lastSubmittedMessage = '';
    isProcessing = false;
    localStorage.removeItem(chatbotHistoryStorageKey);
    localStorage.removeItem(chatbotSessionStorageKey);
    chatbotSessionId = null;
    initChatbotSession();

    const sendBtn = document.getElementById('chatbotSendBtn');
    if (sendBtn) {
        sendBtn.disabled = false;
    }

    const input = document.getElementById('chatbotInput');
    if (input) {
        input.value = '';
        input.focus();
    }
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
            scrollChatbotToLatestMessage();
            document.getElementById('chatbotInput').focus();
        }, 300);
    } else {
        widget.classList.remove('active');
        floatBtn.style.transform = 'rotate(0deg)';
        hideChatbotWarningBanner();
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

function normalizeBotMessage(message) {
    const normalizedMessage = String(message ?? '').replace(/\r\n?/g, '\n');
    const filteredLines = normalizedMessage.split('\n').filter((line) => {
        const normalizedLine = line.replace(/^\s*[*\-•]\s*/, '').trim().toLowerCase();
        return !normalizedLine.startsWith('**anticipated needs:**')
            && !normalizedLine.startsWith('anticipated needs:')
            && !normalizedLine.startsWith('**suggestions:**')
            && !normalizedLine.startsWith('suggestions:');
    });

    const cleanedMessage = filteredLines
        .join('\n')
        .replace(/^\s*[*-]\s+/gm, '• ')
        .replace(/\n{3,}/g, '\n\n')
        .trim();

    return cleanedMessage || normalizedMessage.trim();
}

function formatBasicMarkdown(messageHtml) {
    return messageHtml.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
}

function formatMessageWithDocumentLinks(message, sender) {
    const safeMessage = typeof message === 'string' ? message : String(message ?? '');
    const normalizedMessage = sender === 'bot' ? normalizeBotMessage(safeMessage) : safeMessage;
    if (sender !== 'bot') {
        return escapeHtml(normalizedMessage);
    }
    
    const mentions = collectDocumentMentions(normalizedMessage);
    if (mentions.length === 0) {
        return formatBasicMarkdown(escapeHtml(normalizedMessage));
    }
    
    let html = '';
    let cursor = 0;
    
    mentions.forEach((mention) => {
        html += escapeHtml(normalizedMessage.slice(cursor, mention.start));
        html += `<a href="#" class="chatbot-doc-link" data-doc-type="${mention.type}" data-doc-number="${encodeURIComponent(mention.number)}">${escapeHtml(mention.label)}</a>`;
        cursor = mention.end;
    });
    
    html += escapeHtml(normalizedMessage.slice(cursor));
    return formatBasicMarkdown(html);
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
    
    lastSubmittedMessage = message;
    hideChatbotWarningBanner();
    clearSlowResponseWarningTimer();

    // Clear input
    input.value = '';
    
    // Add user message to chat
    addMessageToChat(message, 'user');
    
    // Show typing indicator
    showTypingIndicator();
    
    isProcessing = true;
    const sendBtn = document.getElementById('chatbotSendBtn');
    sendBtn.disabled = true;
    startSlowResponseWarningTimer();
    
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
        clearSlowResponseWarningTimer();
        hideChatbotWarningBanner();
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
    
    const messageTime = formatChatTimestamp(time);
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
    scrollChatbotToLatestMessage(persist ? 'smooth' : 'auto');
    
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
    scrollChatbotToLatestMessage(persist ? 'smooth' : 'auto');
    
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
    scrollChatbotToLatestMessage();
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
