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
        --chatbot-bg: #F5F7FA;
        --chatbot-surface: #F5F7FA;
        --chatbot-border: #DCE2EA;
        --chatbot-text: #000000;
        --chatbot-muted: #8E8E93;
        --chatbot-user: #0084FF;
        --chatbot-bot: #F5F7FA;
        --chatbot-message-bg: #F5F7FA;
        --chatbot-message-border: #D2D8E1;
        --chatbot-message-border-accent: #C1C8D2;
        --chatbot-success: #22C55E;
        --chatbot-shadow: 0 10px 28px rgba(16, 24, 40, 0.12);
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
        border: 1px solid var(--chatbot-border);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        background: var(--chatbot-surface);
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
        z-index: 9998;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .chatbot-float-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 22px rgba(15, 23, 42, 0.17);
    }

    .chatbot-float-btn:focus-visible {
        outline: 3px solid rgba(0, 132, 255, 0.25);
        outline-offset: 2px;
    }

    .chatbot-float-icon {
        width: 34px;
        height: 34px;
        object-fit: contain;
        display: block;
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
        border-radius: 12px;
        border: 1px solid var(--chatbot-border);
        background: var(--chatbot-surface);
        box-shadow: var(--chatbot-shadow);
        z-index: 9999;
    }

    .chatbot-widget.active {
        display: flex;
    }

    .chatbot-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 16px;
        color: var(--chatbot-text);
        background: var(--chatbot-surface);
        border-bottom: 1px solid var(--chatbot-border);
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
        background: var(--chatbot-bg);
        border: 1px solid var(--chatbot-border);
        flex-shrink: 0;
        overflow: hidden;
    }

    .chatbot-logo-image {
        width: 28px;
        height: 28px;
        object-fit: contain;
        display: block;
    }

    .chatbot-title h4 {
        margin: 0;
        font-size: 20px;
        font-weight: 700;
        line-height: 1.2;
        color: var(--chatbot-text);
        letter-spacing: 0.01em;
    }

    .chatbot-status {
        margin: 4px 0 0;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        font-weight: 600;
        line-height: 1.2;
        color: var(--chatbot-muted);
    }

    .chatbot-status::before {
        content: "";
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--chatbot-success);
        box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.16);
    }

    .chatbot-close-btn {
        width: 34px;
        height: 34px;
        border: 1px solid var(--chatbot-border);
        border-radius: 10px;
        padding: 0;
        color: #4B5563;
        background: var(--chatbot-surface);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s ease, transform 0.2s ease;
    }

    .chatbot-close-btn:hover {
        background: var(--chatbot-bg);
        transform: scale(1.03);
    }

    .chatbot-close-btn:focus-visible {
        outline: 2px solid rgba(0, 132, 255, 0.28);
        outline-offset: 2px;
    }

    .chatbot-messages {
        flex: 1;
        min-height: 0;
        max-height: 100%;
        overflow-y: auto;
        scroll-behavior: smooth;
        padding: 16px;
        background: var(--chatbot-bg);
    }

    .welcome-message {
        margin-bottom: 12px;
        padding: 16px;
        border: 1px solid var(--chatbot-border);
        border-radius: 12px;
        background: var(--chatbot-surface);
        color: var(--chatbot-text);
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
    }

    .welcome-logo-image {
        width: 36px;
        height: 36px;
        object-fit: contain;
        display: block;
        margin-bottom: 10px;
    }

    .welcome-message h3 {
        margin: 0 0 6px;
        font-size: 17px;
        font-weight: 700;
        line-height: 1.3;
        color: var(--chatbot-text);
    }

    .welcome-message p {
        margin: 0;
        font-size: 13px;
        line-height: 1.5;
        color: #4B5563;
    }

    .quick-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
    }

    .quick-action-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid #CFE2F9;
        border-radius: 999px;
        padding: 8px 12px;
        font-size: 12px;
        font-weight: 600;
        color: #006FD6;
        background: var(--chatbot-surface);
        cursor: pointer;
        transition: background 0.2s ease, transform 0.2s ease;
    }

    .quick-action-btn:hover {
        background: #EEF6FF;
        transform: translateY(-1px);
    }

    .chatbot-message {
        margin-bottom: 10px;
        display: flex;
        align-items: flex-end;
        gap: 10px;
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
        background: var(--chatbot-surface);
    }

    .message-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
        display: block;
    }

    .bot-avatar {
        background: var(--chatbot-surface);
    }

    .user-avatar {
        color: #FFFFFF;
        background: var(--chatbot-user);
        border-color: transparent;
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
        border-radius: 14px;
        padding: 12px 16px;
        font-size: 13.5px;
        line-height: 1.5;
        word-wrap: break-word;
        white-space: pre-wrap;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
        color: var(--chatbot-text);
        background: var(--chatbot-message-bg);
        border: 1px solid var(--chatbot-message-border);
    }

    .message-bubble strong,
    .message-bubble b {
        color: #111827;
        font-weight: 700;
    }

    .bot-message .message-bubble {
        border-left: 3px solid var(--chatbot-message-border-accent);
    }

    .user-message .message-bubble {
        border-right: 3px solid var(--chatbot-message-border-accent);
    }

    .message-time {
        margin-top: 4px;
        padding: 0 2px;
        font-size: 11px;
        font-weight: 500;
        color: var(--chatbot-muted);
    }

    .chatbot-sources-note {
        margin: -2px 0 12px 40px;
        border-left: 2px solid #C7D2E0;
        padding-left: 10px;
        font-size: 11.5px;
        line-height: 1.45;
        color: #475569;
    }

    .chatbot-sources-note strong {
        color: #111827;
    }

    .chatbot-doc-link {
        color: #006FD6;
        text-decoration: underline;
        text-underline-offset: 2px;
        cursor: pointer;
    }

    .chatbot-doc-link:hover,
    .chatbot-doc-link:focus {
        color: #0053A3;
    }

    .typing-indicator {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        border-radius: 14px;
        border-bottom-left-radius: 6px;
        background: var(--chatbot-bot);
        padding: 10px 13px;
    }

    .typing-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #8E8E93;
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
            transform: translateY(-6px);
            opacity: 1;
        }
    }

    .chatbot-warning-host {
        display: none;
        padding: 0 16px 12px;
        background: var(--chatbot-surface);
    }

    .chatbot-warning-host:not(:empty) {
        display: block;
    }

    .chatbot-warning-banner {
        display: flex;
        gap: 10px;
        border: 1px solid #F8B26A;
        border-radius: 10px;
        padding: 10px 12px;
        background: #FFF4E6;
        color: #8A4B08;
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
        border: 1px solid #E38A2E;
        border-radius: 999px;
        padding: 4px 10px;
        font-size: 11px;
        font-weight: 600;
        color: #8A4B08;
        background: #FFFFFF;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: background 0.2s ease, transform 0.2s ease;
    }

    .chatbot-warning-btn i {
        font-size: 10px;
    }

    .chatbot-warning-btn:hover {
        background: #FFE8CF;
        transform: translateY(-1px);
    }

    .chatbot-warning-btn:disabled {
        opacity: 0.55;
        cursor: not-allowed;
        transform: none;
    }

    .chatbot-warning-btn.secondary {
        border-color: #EFB47B;
        background: #FFF7ED;
    }

    .chatbot-input-area {
        position: sticky;
        bottom: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px 16px;
        border-top: 1px solid var(--chatbot-border);
        background: var(--chatbot-surface);
        z-index: 2;
    }

    .chatbot-input {
        flex: 1;
        min-width: 0;
        border: 1px solid var(--chatbot-border);
        border-radius: 22px;
        padding: 11px 14px;
        font-size: 13.5px;
        color: var(--chatbot-text);
        background: var(--chatbot-bg);
        outline: none;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .chatbot-input::placeholder {
        color: var(--chatbot-muted);
    }

    .chatbot-input:focus {
        border-color: var(--chatbot-user);
        box-shadow: 0 0 0 3px rgba(0, 132, 255, 0.15);
    }

    .chatbot-send-btn {
        min-width: 94px;
        border: 0;
        border-radius: 22px;
        padding: 10px 14px;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.01em;
        color: #FFFFFF;
        background: var(--chatbot-user);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        box-shadow: 0 6px 14px rgba(0, 132, 255, 0.28);
    }

    .chatbot-send-icon {
        display: inline-block;
        font-size: 14px;
        line-height: 1;
        transform: translateY(-1px);
    }

    .chatbot-send-btn:hover:not(:disabled) {
        transform: translateY(-1px);
        box-shadow: 0 8px 18px rgba(0, 132, 255, 0.34);
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
        background: var(--chatbot-surface);
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
        background-color: var(--chatbot-user);
    }

    #chatbotDocumentImageModal .chatbot-doc-empty {
        margin: 0;
        padding: 24px;
        text-align: center;
        color: #6B7280;
    }

    .chatbot-messages::-webkit-scrollbar {
        width: 7px;
    }

    .chatbot-messages::-webkit-scrollbar-track {
        background: transparent;
    }

    .chatbot-messages::-webkit-scrollbar-thumb {
        background: #C8CED8;
        border-radius: 999px;
    }

    .chatbot-messages::-webkit-scrollbar-thumb:hover {
        background: #A7AFBC;
    }

    @media (max-width: 768px) {
        .chatbot-widget {
            right: 10px;
            bottom: 86px;
            width: calc(100vw - 20px);
            height: calc(100vh - 112px);
            border-radius: 12px;
        }

        .chatbot-float-btn {
            right: 12px;
            bottom: 18px;
            width: 56px;
            height: 56px;
        }

        .chatbot-float-icon {
            width: 30px;
            height: 30px;
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
            border-radius: 50%;
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
            font-size: 18px;
        }

        .message-bubble {
            font-size: 13px;
            padding: 12px 16px;
        }
    }
</style>

<!-- Chatbot Float Button -->
<button class="chatbot-float-btn" id="chatbotFloatBtn" onclick="toggleChatbot()">
    <img src="images/logo_pbsth.png" alt="eFINDBot logo" class="chatbot-float-icon">
</button>

<!-- Chatbot Widget -->
<div class="chatbot-widget" id="chatbotWidget">
    <!-- Header -->
    <div class="chatbot-header">
        <div class="chatbot-header-info">
            <div class="chatbot-avatar">
                <img src="images/logo_pbsth.png" alt="eFINDBot logo" class="chatbot-logo-image">
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
            <img src="images/logo_pbsth.png" alt="eFINDBot logo" class="welcome-logo-image">
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
            <span class="chatbot-send-icon" aria-hidden="true">&#10148;</span>
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
const chatbotBotLogoUrl = 'images/logo_pbsth.png';
const chatbotSessionStorageKey = `efind_chatbot_session_${chatbotUserId}`;
const chatbotHistoryStorageKey = `efind_chatbot_history_${chatbotUserId}`;
const chatbotDocumentMentionMatchers = [
    {
        type: 'resolution',
        regex: /\b(resolution(?:\s*(?:no\.?|number|#))?)\s*[:#-]?\s*((?=[A-Za-z0-9\-\/]*\d)[A-Za-z0-9][A-Za-z0-9\-\/]*)\b/gi,
        numberGroup: 2
    },
    {
        type: 'executive_order',
        regex: /\b(executive\s+order(?:\s*(?:no\.?|number|#))?)\s*[:#-]?\s*((?=[A-Za-z0-9\-\/]*\d)[A-Za-z0-9][A-Za-z0-9\-\/]*)\b/gi,
        numberGroup: 2
    },
    {
        type: 'minutes',
        regex: /\b((?:(?:minutes?\s+of\s+meeting|meeting\s+minutes)(?:\s*(?:no\.?|number|#))?|session\s*(?:no\.?|number|#)))\s*[:#-]?\s*((?=[A-Za-z0-9\-\/]*\d)[A-Za-z0-9][A-Za-z0-9\-\/]*)\b/gi,
        numberGroup: 2
    },
    {
        type: 'minutes',
        regex: /\b(\d{1,3}(?:st|nd|rd|th)\s+regular\s+session)\b/gi,
        numberGroup: 1
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
                    <button class="chatbot-warning-btn" type="button" onclick="retryLastMessage()" ${isProcessing ? 'disabled' : ''}><i class="fas fa-rotate-left" aria-hidden="true"></i>Retry</button>
                    <button class="chatbot-warning-btn secondary" type="button" onclick="refreshChatConversation()"><i class="fas fa-arrows-rotate" aria-hidden="true"></i>Refresh</button>
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
            <img src="images/logo_pbsth.png" alt="eFINDBot logo" class="welcome-logo-image">
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

    const avatarImageSource = sender === 'bot' ? chatbotBotLogoUrl : chatbotUserAvatarUrl;
    if (avatarImageSource) {
        const avatarImg = document.createElement('img');
        avatarImg.alt = sender === 'bot' ? 'eFINDBot logo' : '';
        avatarImg.onload = function() {
            avatarDiv.innerHTML = '';
            avatarDiv.appendChild(avatarImg);
        };
        avatarImg.onerror = function() {
            avatarDiv.innerHTML = fallbackIcon;
        };
        avatarImg.src = avatarImageSource;
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
            <img src="${chatbotBotLogoUrl}" alt="eFINDBot logo">
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
