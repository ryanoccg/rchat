(function() {
    'use strict';

    // Widget configuration
    const CONFIG = @json($config);

    // Prevent multiple initializations
    if (window.RChatWidget) {
        console.warn('RChat Widget already initialized');
        return;
    }

    // Widget state
    const state = {
        isOpen: false,
        isMinimized: false,
        isInitialized: false, // Track if messages have been loaded this session
        visitorId: localStorage.getItem('rchat_visitor_' + CONFIG.widgetId) || null,
        visitorInfo: JSON.parse(localStorage.getItem('rchat_visitor_info_' + CONFIG.widgetId) || 'null'),
        conversationId: localStorage.getItem('rchat_conversation_' + CONFIG.widgetId) || null,
        messages: [],
        lastMessageId: null,
        pollInterval: null,
        showPrechatForm: false,
    };

    // Styles
    const styles = `
        .chathero-widget-container {
            --ch-primary: ${CONFIG.primaryColor};
            --ch-primary-dark: ${adjustColor(CONFIG.primaryColor, -20)};
            --ch-primary-light: ${adjustColor(CONFIG.primaryColor, 40)};
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
        }
        .chathero-widget-container * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        .chathero-launcher {
            position: fixed;
            ${CONFIG.position === 'bottom-left' ? 'left: 20px;' : 'right: 20px;'}
            bottom: 20px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--ch-primary);
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.2s, box-shadow 0.2s;
            z-index: 999998;
        }
        .chathero-launcher:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
        }
        .chathero-launcher svg {
            width: 28px;
            height: 28px;
            fill: white;
        }
        .chathero-launcher.open svg.chat-icon {
            display: none;
        }
        .chathero-launcher.open svg.close-icon {
            display: block;
        }
        .chathero-launcher:not(.open) svg.close-icon {
            display: none;
        }
        .chathero-window {
            position: fixed;
            ${CONFIG.position === 'bottom-left' ? 'left: 20px;' : 'right: 20px;'}
            bottom: 90px;
            width: 380px;
            height: 550px;
            max-height: calc(100vh - 120px);
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            display: none;
            flex-direction: column;
            overflow: hidden;
            z-index: 999999;
        }
        .chathero-window.open {
            display: flex;
            animation: chathero-slide-up 0.3s ease;
        }
        @keyframes chathero-slide-up {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .chathero-header {
            background: var(--ch-primary);
            color: white;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .chathero-header-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .chathero-header-avatar svg {
            width: 24px;
            height: 24px;
            fill: white;
        }
        .chathero-header-info {
            flex: 1;
        }
        .chathero-header-title {
            font-size: 16px;
            font-weight: 600;
        }
        .chathero-header-status {
            font-size: 12px;
            opacity: 0.9;
        }
        .chathero-header-typing {
            font-size: 12px;
            opacity: 0.9;
            display: none;
        }
        .chathero-header-typing.active {
            display: block;
        }
        .chathero-messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            background: #f8fafc;
        }
        .chathero-message {
            max-width: 85%;
            padding: 12px 16px;
            border-radius: 16px;
            word-wrap: break-word;
            animation: chathero-fade-in 0.2s ease;
            font-size: 14px;
            line-height: 1.5;
        }
        .chathero-message p,
        .chathero-message div {
            margin: 0;
        }
        @keyframes chathero-fade-in {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .chathero-message.inbound {
            background: #ffffff;
            color: #111827;
            align-self: flex-start;
            border-bottom-left-radius: 4px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
            border: 1px solid #e5e7eb;
        }
        .chathero-message.inbound div:first-child {
            color: #111827;
            font-weight: 400;
        }
        .chathero-message.outbound {
            background: var(--ch-primary-light);
            color: #1f2937;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--ch-primary);
        }
        .chathero-message.outbound div:first-child {
            color: #1f2937;
            font-weight: 400;
        }
        .chathero-message.outbound .chathero-message-time {
            color: #6b7280;
        }
        .chathero-message.system {
            background: var(--ch-primary-light);
            color: #374151;
            align-self: center;
            font-size: 13px;
            text-align: center;
        }
        .chathero-message-time {
            font-size: 11px;
            margin-top: 4px;
        }
        .chathero-message.inbound .chathero-message-time {
            color: #6b7280;
        }
        .chathero-message.outbound .chathero-message-time {
            color: #6b7280;
        }
        .chathero-input-area {
            padding: 12px 16px;
            background: white;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }
        .chathero-input {
            flex: 1;
            border: 1px solid #e5e7eb;
            border-radius: 20px;
            padding: 10px 16px;
            font-size: 14px;
            outline: none;
            resize: none;
            max-height: 100px;
            font-family: inherit;
        }
        .chathero-input:focus {
            border-color: var(--ch-primary);
        }
        .chathero-send {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--ch-primary);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }
        .chathero-send:hover {
            background: var(--ch-primary-dark);
        }
        .chathero-send:disabled {
            background: #d1d5db;
            cursor: not-allowed;
        }
        .chathero-send svg {
            width: 20px;
            height: 20px;
            fill: white;
        }
        .chathero-powered {
            text-align: center;
            padding: 8px;
            font-size: 11px;
            color: #9ca3af;
            background: #f9fafb;
        }
        .chathero-powered a {
            color: var(--ch-primary);
            text-decoration: none;
        }
        .chathero-prechat-form {
            flex: 1;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            background: #f8fafc;
            overflow-y: auto;
        }
        .chathero-prechat-title {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            text-align: center;
            margin-bottom: 8px;
        }
        .chathero-prechat-subtitle {
            font-size: 14px;
            color: #6b7280;
            text-align: center;
            margin-bottom: 16px;
        }
        .chathero-form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .chathero-form-label {
            font-size: 13px;
            font-weight: 500;
            color: #374151;
        }
        .chathero-form-label span {
            color: #ef4444;
        }
        .chathero-form-input {
            padding: 10px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
            font-family: inherit;
        }
        .chathero-form-input:focus {
            border-color: var(--ch-primary);
        }
        .chathero-form-input.error {
            border-color: #ef4444;
        }
        .chathero-form-error {
            font-size: 12px;
            color: #ef4444;
        }
        .chathero-start-chat-btn {
            padding: 12px 24px;
            background: var(--ch-primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 8px;
        }
        .chathero-start-chat-btn:hover {
            background: var(--ch-primary-dark);
        }
        .chathero-start-chat-btn:disabled {
            background: #d1d5db;
            cursor: not-allowed;
        }
        /* Loading screen styles */
        .chathero-loading {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            gap: 16px;
        }
        .chathero-loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #e5e7eb;
            border-top-color: var(--ch-primary);
            border-radius: 50%;
            animation: chathero-spin 0.8s linear infinite;
        }
        @keyframes chathero-spin {
            to { transform: rotate(360deg); }
        }
        .chathero-loading-text {
            color: #6b7280;
            font-size: 14px;
        }
        @media (max-width: 420px) {
            .chathero-window {
                width: calc(100vw - 20px);
                height: calc(100vh - 100px);
                ${CONFIG.position === 'bottom-left' ? 'left: 10px;' : 'right: 10px;'}
                bottom: 80px;
                border-radius: 12px;
            }
        }
    `;

    // Helper function to adjust color brightness
    function adjustColor(color, amount) {
        const clamp = (num) => Math.min(255, Math.max(0, num));
        const hex = color.replace('#', '');
        const num = parseInt(hex, 16);
        const r = clamp((num >> 16) + amount);
        const g = clamp(((num >> 8) & 0x00FF) + amount);
        const b = clamp((num & 0x0000FF) + amount);
        return '#' + (g | (b << 8) | (r << 16)).toString(16).padStart(6, '0');
    }

    // Create widget HTML
    function createWidget() {
        const container = document.createElement('div');
        container.className = 'chathero-widget-container';
        container.innerHTML = `
            <style>${styles}</style>
            <button class="chathero-launcher" aria-label="Open chat">
                <svg class="chat-icon" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>
                <svg class="close-icon" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            </button>
            <div class="chathero-window">
                <div class="chathero-header">
                    <div class="chathero-header-avatar">
                        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>
                    </div>
                    <div class="chathero-header-info">
                        <div class="chathero-header-title">${escapeHtml(CONFIG.title)}</div>
                        <div class="chathero-header-status" id="chathero-status">${escapeHtml(CONFIG.companyName)}</div>
                        <div class="chathero-header-typing" id="chathero-header-typing">AI is typing...</div>
                    </div>
                </div>
                <!-- Pre-chat form (shown when no visitor info) -->
                <div class="chathero-prechat-form" id="chathero-prechat-form" style="display: none;">
                    <div class="chathero-prechat-title">👋 Welcome!</div>
                    <div class="chathero-prechat-subtitle">Please provide your details to start chatting</div>
                    <div class="chathero-form-group">
                        <label class="chathero-form-label">Name <span>*</span></label>
                        <input type="text" class="chathero-form-input" id="chathero-prechat-name" placeholder="Your name" required>
                    </div>
                    <div class="chathero-form-group">
                        <label class="chathero-form-label">Email <span>*</span></label>
                        <input type="email" class="chathero-form-input" id="chathero-prechat-email" placeholder="your@email.com" required>
                    </div>
                    <div class="chathero-form-group">
                        <label class="chathero-form-label">Phone (optional)</label>
                        <input type="tel" class="chathero-form-input" id="chathero-prechat-phone" placeholder="+1 234 567 890">
                    </div>
                    <button class="chathero-start-chat-btn" id="chathero-start-chat-btn">Start Chat</button>
                </div>
                <!-- Loading screen -->
                <div class="chathero-loading" id="chathero-loading" style="display: none;">
                    <div class="chathero-loading-spinner"></div>
                    <div class="chathero-loading-text">Loading messages...</div>
                </div>
                <!-- Chat messages area -->
                <div class="chathero-messages" id="chathero-messages"></div>
                <div class="chathero-input-area" id="chathero-input-area">
                    <textarea class="chathero-input" placeholder="Type a message..." rows="1" id="chathero-input"></textarea>
                    <button class="chathero-send" id="chathero-send" aria-label="Send message">
                        <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                    </button>
                </div>
                <div class="chathero-powered">Powered by <a href="${CONFIG.apiUrl.replace('/api/webchat', '')}" target="_blank">RChat</a></div>
            </div>
        `;
        document.body.appendChild(container);
        return container;
    }

    // Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Format time
    function formatTime(dateString) {
        const date = new Date(dateString);
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    // Add message to UI
    function addMessageToUI(message) {
        const messagesContainer = document.getElementById('chathero-messages');
        // is_from_customer true = customer message (outbound in widget), false = agent/AI message (inbound in widget)
        const cssClass = message.is_from_customer ? 'outbound' : 'inbound';
        
        const msgEl = document.createElement('div');
        msgEl.className = `chathero-message ${cssClass}`;
        msgEl.setAttribute('data-message-id', message.id);
        msgEl.innerHTML = `
            <div>${escapeHtml(message.content)}</div>
            <div class="chathero-message-time">${formatTime(message.created_at)}</div>
        `;
        
        messagesContainer.appendChild(msgEl);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        
        state.lastMessageId = message.id;
    }

    // Show welcome message
    function showWelcomeMessage() {
        const messagesContainer = document.getElementById('chathero-messages');
        const msgEl = document.createElement('div');
        msgEl.className = 'chathero-message system';
        msgEl.textContent = CONFIG.welcomeMessage;
        messagesContainer.appendChild(msgEl);
    }

    // Show typing indicator in header
    function showTyping(show) {
        const typingIndicator = document.getElementById('chathero-header-typing');
        const statusText = document.getElementById('chathero-status');
        
        if (typingIndicator && statusText) {
            if (show) {
                typingIndicator.classList.add('active');
                statusText.style.display = 'none';
            } else {
                typingIndicator.classList.remove('active');
                statusText.style.display = 'block';
            }
        }
    }

    // API calls
    async function apiCall(endpoint, data) {
        try {
            const response = await fetch(`${CONFIG.apiUrl}${endpoint}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify(data),
            });
            const result = await response.json();
            
            // Log errors for debugging
            if (!result.success) {
                console.error('RChat API Error:', {
                    endpoint,
                    status: response.status,
                    data,
                    result
                });
            }
            
            return result;
        } catch (error) {
            console.error('RChat API Error:', error);
            return { success: false, error: error.message };
        }
    }

    // Initialize widget
    async function initWidget() {
        const result = await apiCall('/init', {
            widget_id: String(CONFIG.widgetId),
            visitor_id: state.visitorId,
            visitor_info: state.visitorInfo,
        });

        if (result.success) {
            state.visitorId = result.config.visitor_id;
            state.conversationId = result.conversation_id;
            state.isInitialized = true; // Mark as initialized
            localStorage.setItem('rchat_visitor_' + CONFIG.widgetId, state.visitorId);
            if (state.conversationId) {
                localStorage.setItem('rchat_conversation_' + CONFIG.widgetId, state.conversationId);
            }

            // Clear messages container first to avoid duplicates
            const messagesContainer = document.getElementById('chathero-messages');
            messagesContainer.innerHTML = '';

            // Show existing messages
            if (result.messages && result.messages.length > 0) {
                result.messages.forEach(msg => addMessageToUI(msg));
            } else {
                showWelcomeMessage();
            }
            
            // Ensure scroll to bottom after all messages are loaded
            setTimeout(() => {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }, 100);
        }
    }

    // Send message
    async function sendMessage(content) {
        if (!content.trim()) return;

        const input = document.getElementById('chathero-input');
        const sendBtn = document.getElementById('chathero-send');
        
        // Disable input
        input.disabled = true;
        sendBtn.disabled = true;

        // Add user message to UI immediately
        addMessageToUI({
            id: Date.now(),
            content: content,
            is_from_customer: true,
            created_at: new Date().toISOString(),
        });

        // Clear input
        input.value = '';
        input.style.height = 'auto';

        // Show typing indicator
        showTyping(true);

        // Send to API
        const result = await apiCall('/messages', {
            widget_id: String(CONFIG.widgetId),
            visitor_id: state.visitorId,
            message: content,
        });

        showTyping(false);

        if (result.success) {
            state.conversationId = result.conversation_id;
            localStorage.setItem('rchat_conversation_' + CONFIG.widgetId, state.conversationId);
            
            // Show AI response if any
            if (result.ai_response) {
                addMessageToUI(result.ai_response);
            }
        }

        // Re-enable input
        input.disabled = false;
        sendBtn.disabled = false;
        input.focus();
    }

    // Poll for new messages
    async function pollMessages() {
        if (!state.isOpen || !state.conversationId) return;

        const result = await apiCall('/poll', {
            widget_id: String(CONFIG.widgetId),
            visitor_id: state.visitorId,
            last_message_id: state.lastMessageId,
        });

        if (result.success && result.messages && result.messages.length > 0) {
            result.messages.forEach(msg => {
                // Only show messages from agent/AI (is_from_customer = false)
                if (!msg.is_from_customer) {
                    // Check if message doesn't already exist in DOM
                    const existingMsg = document.querySelector(`[data-message-id="${msg.id}"]`);
                    if (!existingMsg) {
                        addMessageToUI(msg);
                    }
                }
            });
        }
    }

    // Show/hide loading screen
    function showLoading(show) {
        const loadingScreen = document.getElementById('chathero-loading');
        const messagesArea = document.getElementById('chathero-messages');
        const inputArea = document.getElementById('chathero-input-area');
        
        if (show) {
            loadingScreen.style.display = 'flex';
            messagesArea.style.display = 'none';
            inputArea.style.display = 'none';
        } else {
            loadingScreen.style.display = 'none';
            messagesArea.style.display = '';
            inputArea.style.display = '';
        }
    }

    // Toggle chat window
    function toggleChat() {
        state.isOpen = !state.isOpen;
        const launcher = document.querySelector('.chathero-launcher');
        const window = document.querySelector('.chathero-window');
        
        launcher.classList.toggle('open', state.isOpen);
        window.classList.toggle('open', state.isOpen);

        if (state.isOpen) {
            // Check if we need to show pre-chat form
            if (!state.visitorInfo) {
                showPrechatForm(true);
            } else {
                showPrechatForm(false);
                // Always initialize on first open to load messages
                if (!state.isInitialized) {
                    showLoading(true);
                    initWidget().then(() => {
                        showLoading(false);
                    });
                }
                // Start polling
                state.pollInterval = setInterval(pollMessages, 3000);
                document.getElementById('chathero-input').focus();
            }
        } else {
            // Stop polling
            if (state.pollInterval) {
                clearInterval(state.pollInterval);
                state.pollInterval = null;
            }
        }
    }

    // Show/hide pre-chat form
    function showPrechatForm(show) {
        const prechatForm = document.getElementById('chathero-prechat-form');
        const messagesArea = document.getElementById('chathero-messages');
        const inputArea = document.getElementById('chathero-input-area');
        const loadingScreen = document.getElementById('chathero-loading');
        
        if (show) {
            prechatForm.style.display = 'flex';
            messagesArea.style.display = 'none';
            inputArea.style.display = 'none';
            loadingScreen.style.display = 'none';
        } else {
            prechatForm.style.display = 'none';
            messagesArea.style.display = '';
            inputArea.style.display = '';
        }
    }

    // Handle pre-chat form submission
    function handlePrechatSubmit() {
        const nameInput = document.getElementById('chathero-prechat-name');
        const emailInput = document.getElementById('chathero-prechat-email');
        const phoneInput = document.getElementById('chathero-prechat-phone');
        
        // Validate
        let valid = true;
        
        if (!nameInput.value.trim()) {
            nameInput.classList.add('error');
            valid = false;
        } else {
            nameInput.classList.remove('error');
        }
        
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailInput.value.trim() || !emailRegex.test(emailInput.value)) {
            emailInput.classList.add('error');
            valid = false;
        } else {
            emailInput.classList.remove('error');
        }
        
        if (!valid) return;
        
        // Store visitor info
        state.visitorInfo = {
            name: nameInput.value.trim(),
            email: emailInput.value.trim(),
            phone: phoneInput.value.trim() || null,
        };
        localStorage.setItem('rchat_visitor_info_' + CONFIG.widgetId, JSON.stringify(state.visitorInfo));
        
        // Hide form, show loading
        showPrechatForm(false);
        showLoading(true);
        
        // Initialize widget with visitor info
        initWidget().then(() => {
            showLoading(false);
        });
        
        // Start polling
        state.pollInterval = setInterval(pollMessages, 3000);
        document.getElementById('chathero-input').focus();
    }

    // Auto-resize textarea
    function autoResize(textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 100) + 'px';
    }

    // Initialize
    function init() {
        const container = createWidget();

        // Event listeners
        container.querySelector('.chathero-launcher').addEventListener('click', toggleChat);

        // Pre-chat form event listeners
        const startChatBtn = document.getElementById('chathero-start-chat-btn');
        startChatBtn.addEventListener('click', handlePrechatSubmit);
        
        // Allow Enter key to submit pre-chat form
        const prechatInputs = container.querySelectorAll('.chathero-form-input');
        prechatInputs.forEach(input => {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    handlePrechatSubmit();
                }
            });
        });

        const input = document.getElementById('chathero-input');
        const sendBtn = document.getElementById('chathero-send');

        input.addEventListener('input', () => autoResize(input));
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage(input.value);
            }
        });

        sendBtn.addEventListener('click', () => sendMessage(input.value));

        // Expose API
        window.RChatWidget = {
            open: () => { if (!state.isOpen) toggleChat(); },
            close: () => { if (state.isOpen) toggleChat(); },
            toggle: toggleChat,
        };
    }

    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
