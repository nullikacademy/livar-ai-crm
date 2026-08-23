/**
 * assets/js/app.js
 *
 * LiVAR Packaging CRM — vanilla JS frontend.
 * No build step, no framework: everything below talks to /api/*.php
 * over fetch() and renders plain DOM.
 */
(() => {
    'use strict';

    // ------------------------------------------------------------------
    // Config & DOM references
    // ------------------------------------------------------------------

    const API = {
        customers: 'api/customers.php',
        messages: 'api/messages.php',
        webhook: 'api/webhook.php',
    };

    const PAGE_SIZE = 30;

    const el = {
        app: document.getElementById('app'),
        // Sidebar
        sidebar: document.getElementById('sidebar'),
        searchInput: document.getElementById('searchInput'),
        searchClear: document.getElementById('searchClear'),
        customerList: document.getElementById('customerList'),
        customerSkeleton: document.getElementById('customerSkeleton'),
        customerListEmpty: document.getElementById('customerListEmpty'),
        newChatBtn: document.getElementById('newChatBtn'),
        // Chat
        chat: document.getElementById('chat'),
        chatPlaceholder: document.getElementById('chatPlaceholder'),
        chatConversation: document.getElementById('chatConversation'),
        chatBackBtn: document.getElementById('chatBackBtn'),
        chatCustomerBtn: document.getElementById('chatCustomerBtn'),
        chatAvatar: document.getElementById('chatAvatar'),
        chatCustomerName: document.getElementById('chatCustomerName'),
        chatCustomerPhone: document.getElementById('chatCustomerPhone'),
        chatDetailsBtn: document.getElementById('chatDetailsBtn'),
        chatMessages: document.getElementById('chatMessages'),
        messagesSkeleton: document.getElementById('messagesSkeleton'),
        scrollJumpBtn: document.getElementById('scrollJumpBtn'),
        composerInput: document.getElementById('composerInput'),
        generateBtn: document.getElementById('generateBtn'),
        // Details panel
        panelOverlay: document.getElementById('panelOverlay'),
        detailsPanel: document.getElementById('detailsPanel'),
        closeDetailsBtn: document.getElementById('closeDetailsBtn'),
        detailsForm: document.getElementById('detailsForm'),
        saveDetailsBtn: document.getElementById('saveDetailsBtn'),
        // Misc
        toastStack: document.getElementById('toastStack'),
    };

    // ------------------------------------------------------------------
    // App state
    // ------------------------------------------------------------------

    const state = {
        customers: [],          // loaded customer rows (sidebar order)
        offset: 0,
        hasMore: true,
        isLoadingCustomers: false,
        search: '',
        selectedSessionId: null,
        selectedCustomer: null,
        messages: [],
        isSending: false,
    };

    // ------------------------------------------------------------------
    // Small utilities
    // ------------------------------------------------------------------

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }

    function debounce(fn, wait) {
        let t;
        return (...args) => {
            clearTimeout(t);
            t = setTimeout(() => fn(...args), wait);
        };
    }

    function fullName(customer) {
        const name = [customer.first_name, customer.last_name].filter(Boolean).join(' ').trim();
        return name || customer.username || customer.phone || 'Unnamed customer';
    }

    function initials(customer) {
        const name = fullName(customer);
        const parts = name.split(' ').filter(Boolean);
        if (parts.length === 0) return '?';
        if (parts.length === 1) return parts[0].slice(0, 2);
        return (parts[0][0] + parts[1][0]);
    }

    function relativeTime(idOrDate) {
        // We only have sequential message ids for "last activity" in this
        // schema (no timestamp column on n8n_chat_history), so we fall back
        // to the customer's created_at when there is no message yet.
        if (!idOrDate) return '';
        const date = new Date(idOrDate);
        if (isNaN(date.getTime())) return '';
        const diffMs = Date.now() - date.getTime();
        const mins = Math.round(diffMs / 60000);
        if (mins < 1) return 'now';
        if (mins < 60) return `${mins}m`;
        const hours = Math.round(mins / 60);
        if (hours < 24) return `${hours}h`;
        const days = Math.round(hours / 24);
        if (days < 7) return `${days}d`;
        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    function toast(message, variant = 'success') {
        const node = document.createElement('div');
        node.className = `toast toast--${variant}`;
        node.textContent = message;
        el.toastStack.appendChild(node);
        setTimeout(() => node.remove(), 2900);
    }

    async function api(url, options = {}) {
        const res = await fetch(url, {
            headers: { 'Content-Type': 'application/json' },
            ...options,
        });
        let data;
        try {
            data = await res.json();
        } catch {
            throw new Error('Unexpected server response.');
        }
        if (!res.ok || data.success === false) {
            throw new Error(data.error || 'Something went wrong.');
        }
        return data;
    }

    // ------------------------------------------------------------------
    // Customer list
    // ------------------------------------------------------------------

    async function loadCustomers({ reset = false } = {}) {
        if (state.isLoadingCustomers) return;
        if (!reset && !state.hasMore) return;

        state.isLoadingCustomers = true;
        if (reset) {
            state.offset = 0;
            state.hasMore = true;
            el.customerSkeleton.hidden = false;
            el.customerListEmpty.hidden = true;
        }

        try {
            const params = new URLSearchParams({
                limit: PAGE_SIZE,
                offset: state.offset,
                search: state.search,
            });
            const data = await api(`${API.customers}?${params.toString()}`);

            if (reset) {
                state.customers = [];
                clearCustomerListDom();
            }

            state.customers.push(...data.customers);
            state.hasMore = data.hasMore;
            state.offset = data.nextOffset;

            renderCustomers(data.customers, { append: !reset });

            if (state.customers.length === 0) {
                el.customerListEmpty.hidden = false;
            } else {
                el.customerListEmpty.hidden = true;
            }
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            el.customerSkeleton.hidden = true;
            state.isLoadingCustomers = false;
        }
    }

    function clearCustomerListDom() {
        el.customerList.querySelectorAll('.customer-item').forEach((n) => n.remove());
    }

    function renderCustomers(customers) {
        const frag = document.createDocumentFragment();
        customers.forEach((customer) => frag.appendChild(buildCustomerItem(customer)));
        el.customerList.appendChild(frag);
    }

    function buildCustomerItem(customer) {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'customer-item';
        item.setAttribute('role', 'option');
        item.dataset.sessionId = customer.session_id;
        if (customer.session_id === state.selectedSessionId) {
            item.classList.add('is-selected');
        }

        const preview = customer.last_message
            ? escapeHtml(truncate(customer.last_message, 46))
            : '<span class="customer-item__phone">No messages yet</span>';
        const prefix = customer.last_message_type === 'ai'
            ? '<span class="customer-item__preview-prefix">You: </span>'
            : '';

        item.innerHTML = `
            <span class="avatar">${escapeHtml(initials(customer))}</span>
            <span class="customer-item__body">
                <span class="customer-item__top">
                    <span class="customer-item__name">${escapeHtml(fullName(customer))}</span>
                    <span class="customer-item__time">${escapeHtml(relativeTime(customer.created_at))}</span>
                </span>
                <span class="customer-item__preview">${prefix}${preview}</span>
            </span>
        `;

        item.addEventListener('click', () => selectCustomer(customer.session_id, { focusComposer: true }));
        return item;
    }

    function truncate(str, max) {
        if (!str) return '';
        return str.length > max ? str.slice(0, max - 1) + '…' : str;
    }

    function updateCustomerItemInList(customer) {
        const idx = state.customers.findIndex((c) => c.session_id === customer.session_id);
        if (idx !== -1) {
            state.customers[idx] = { ...state.customers[idx], ...customer };
        }
        const node = el.customerList.querySelector(`.customer-item[data-session-id="${cssEscape(customer.session_id)}"]`);
        if (node) {
            const fresh = buildCustomerItem({ ...(idx !== -1 ? state.customers[idx] : customer) });
            node.replaceWith(fresh);
        }
    }

    function cssEscape(str) {
        return window.CSS && CSS.escape ? CSS.escape(str) : str.replace(/["\\]/g, '\\$&');
    }

    function markSelectedInList(sessionId) {
        el.customerList.querySelectorAll('.customer-item').forEach((n) => {
            n.classList.toggle('is-selected', n.dataset.sessionId === sessionId);
        });
    }

    const onSearchInput = debounce((value) => {
        state.search = value.trim();
        el.searchClear.hidden = state.search === '';
        loadCustomers({ reset: true });
    }, 300);

    el.searchInput.addEventListener('input', (e) => onSearchInput(e.target.value));
    el.searchClear.addEventListener('click', () => {
        el.searchInput.value = '';
        el.searchClear.hidden = true;
        state.search = '';
        loadCustomers({ reset: true });
        el.searchInput.focus();
    });

    // Infinite scroll
    el.customerList.addEventListener('scroll', () => {
        const { scrollTop, scrollHeight, clientHeight } = el.customerList;
        if (scrollHeight - scrollTop - clientHeight < 160) {
            loadCustomers();
        }
    });

    // ------------------------------------------------------------------
    // Selecting a customer / loading chat
    // ------------------------------------------------------------------

    async function selectCustomer(sessionId, { focusComposer = false } = {}) {
        state.selectedSessionId = sessionId;
        markSelectedInList(sessionId);

        el.app.classList.add('is-chat-open');
        el.chatPlaceholder.hidden = true;
        el.chatConversation.hidden = false;

        const known = state.customers.find((c) => c.session_id === sessionId);
        if (known) applyCustomerToHeader(known);

        el.messagesSkeleton.hidden = false;
        clearMessageBubbles();

        try {
            const [customerData, messagesData] = await Promise.all([
                api(`${API.customers}?session_id=${encodeURIComponent(sessionId)}`),
                api(`${API.messages}?session_id=${encodeURIComponent(sessionId)}`),
            ]);

            // Guard against a race: the user may have tapped another
            // customer while these requests were still in flight.
            if (sessionId !== state.selectedSessionId) return;

            state.selectedCustomer = customerData.customer;
            applyCustomerToHeader(customerData.customer);

            state.messages = messagesData.messages;
            renderMessages({ scroll: true });

            // Only steal focus on pointer/desktop -- auto-focusing on a
            // phone pops the keyboard over the conversation you just opened.
            if (focusComposer && isDesktop()) el.composerInput.focus();
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            el.messagesSkeleton.hidden = true;
        }
    }

    /** True when the two-pane desktop layout is active (matches the CSS breakpoint). */
    const DESKTOP_QUERY = '(min-width: 900px)';

    function isDesktop() {
        return typeof window.matchMedia === 'function'
            ? window.matchMedia(DESKTOP_QUERY).matches
            : window.innerWidth >= 900;
    }

    function applyCustomerToHeader(customer) {
        el.chatAvatar.textContent = initials(customer);
        el.chatCustomerName.textContent = fullName(customer);
        el.chatCustomerPhone.textContent = customer.phone || customer.email || 'No phone on file';
    }

    function clearMessageBubbles() {
        el.chatMessages.querySelectorAll('.bubble-row, .chat__day-divider, .chat__empty-state').forEach((n) => n.remove());
    }

    function renderMessages({ scroll = false, highlightLastAi = false } = {}) {
        clearMessageBubbles();

        if (state.messages.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'chat__empty-state';
            empty.textContent = 'No messages yet. Start the conversation below.';
            el.chatMessages.appendChild(empty);
            return;
        }

        const frag = document.createDocumentFragment();
        let lastAiRow = null;

        state.messages.forEach((msg) => {
            const row = buildBubbleRow(msg);
            frag.appendChild(row);
            if (msg.type === 'ai') lastAiRow = row;
        });

        el.chatMessages.appendChild(frag);

        if (highlightLastAi && lastAiRow) {
            const bubble = lastAiRow.querySelector('.bubble');
            requestAnimationFrame(() => bubble.classList.add('is-new'));
        }

        if (scroll) scrollMessagesToBottom();
    }

    function buildBubbleRow(msg) {
        const isOurs = msg.type === 'ai';
        const row = document.createElement('div');
        row.className = `bubble-row ${isOurs ? 'bubble-row--ours' : 'bubble-row--customer'}`;

        const bubble = document.createElement('div');
        bubble.className = `bubble ${isOurs ? 'bubble--ours' : 'bubble--customer'}`;
        if (msg.pending) bubble.classList.add('is-pending');

        const text = document.createElement('div');
        text.className = 'bubble__text';
        text.textContent = msg.content;
        bubble.appendChild(text);

        if (isOurs) {
            const actions = document.createElement('div');
            actions.className = 'bubble__actions';
            const copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'bubble__copy';
            copyBtn.innerHTML = copyIconSvg() + '<span>Copy</span>';
            copyBtn.addEventListener('click', () => copyToClipboard(msg.content, copyBtn));
            actions.appendChild(copyBtn);
            bubble.appendChild(actions);
        }

        row.appendChild(bubble);
        return row;
    }

    function copyIconSvg() {
        return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
    }

    async function copyToClipboard(text, btn) {
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            // Fallback for browsers/contexts without Clipboard API access.
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
        }
        btn.classList.add('is-copied');
        const label = btn.querySelector('span');
        const original = label.textContent;
        label.textContent = 'Copied';
        setTimeout(() => {
            btn.classList.remove('is-copied');
            label.textContent = original;
        }, 1500);
    }

    function scrollMessagesToBottom(smooth = false) {
        const target = el.chatMessages.scrollHeight;
        // scrollTo with an options object isn't available everywhere
        // (older Safari, some embedded webviews), so fall back to the
        // always-supported scrollTop assignment.
        if (typeof el.chatMessages.scrollTo === 'function') {
            try {
                el.chatMessages.scrollTo({ top: target, behavior: smooth ? 'smooth' : 'auto' });
            } catch {
                el.chatMessages.scrollTop = target;
            }
        } else {
            el.chatMessages.scrollTop = target;
        }
        el.scrollJumpBtn.hidden = true;
    }

    el.chatMessages.addEventListener('scroll', () => {
        const { scrollTop, scrollHeight, clientHeight } = el.chatMessages;
        const distanceFromBottom = scrollHeight - scrollTop - clientHeight;
        el.scrollJumpBtn.hidden = distanceFromBottom < 200;
    });
    el.scrollJumpBtn.addEventListener('click', () => scrollMessagesToBottom(true));

    // Back to list (mobile)
    /** Returns to the customer list on mobile. */
    function closeChat() {
        el.app.classList.remove('is-chat-open');
        // Dismiss the on-screen keyboard if the composer had focus.
        el.composerInput.blur();
    }

    el.chatBackBtn.addEventListener('click', closeChat);

    // ------------------------------------------------------------------
    // Composer — generate answer flow
    // ------------------------------------------------------------------

    /** Grows the composer with its content, up to the CSS max-height. */
    function autoGrowComposer() {
        el.composerInput.style.height = 'auto';
        el.composerInput.style.height = Math.min(el.composerInput.scrollHeight, 148) + 'px';
    }

    /** Send is enabled only when there's text and nothing already in flight. */
    function syncSendButton() {
        el.generateBtn.disabled = state.isSending || el.composerInput.value.trim() === '';
    }

    el.composerInput.addEventListener('input', () => {
        autoGrowComposer();
        syncSendButton();
    });

    el.composerInput.addEventListener('keydown', (e) => {
        // Cmd/Ctrl+Enter sends. Plain Enter inserts a newline, since support
        // agents routinely paste multi-line customer messages here.
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
            e.preventDefault();
            handleGenerateAnswer();
        }
    });

    el.generateBtn.addEventListener('click', handleGenerateAnswer);

    async function handleGenerateAnswer() {
        if (state.isSending) return;
        if (!state.selectedSessionId) {
            toast('Select a customer first.', 'error');
            return;
        }

        const message = el.composerInput.value.trim();
        if (!message) {
            toast('Type or paste the customer\'s message first.', 'error');
            return;
        }

        state.isSending = true;
        el.generateBtn.disabled = true;
        el.generateBtn.classList.add('is-loading');

        const sessionId = state.selectedSessionId;

        el.composerInput.value = '';
        autoGrowComposer();

        // Show the typed message immediately as a "pending" bubble purely
        // client-side -- nothing is written to Supabase from the CRM. n8n
        // is responsible for saving both the human and AI turns to
        // n8n_chat_history once it receives the webhook call below.
        state.messages.push({ id: 'pending', type: 'human', content: message, pending: true });
        renderMessages({ scroll: true });
        showTypingIndicator();

        try {
            // Ask n8n to run the AI agent. n8n saves the human message and
            // the AI reply to Supabase itself and responds once both are
            // written.
            await api(API.webhook, {
                method: 'POST',
                body: JSON.stringify({ session_id: sessionId, message }),
            });

            hideTypingIndicator();

            // Re-read history from Supabase (source of truth) -- this
            // replaces the pending bubble with the real persisted rows and
            // highlights whatever the newest AI turn turns out to be.
            await refreshMessages(sessionId, { scroll: true, highlightLastAi: true });
            refreshSidebarPreview(sessionId);
        } catch (err) {
            hideTypingIndicator();

            // Nothing was persisted, so drop the pending bubble and give
            // the customer's text back to the composer for a retry.
            state.messages = state.messages.filter((m) => m.id !== 'pending');
            renderMessages({ scroll: true });
            el.composerInput.value = message;
            autoGrowComposer();

            toast(err.message, 'error');
        } finally {
            state.isSending = false;
            el.generateBtn.classList.remove('is-loading');
            syncSendButton();
        }
    }

    function showTypingIndicator() {
        hideTypingIndicator();
        const row = document.createElement('div');
        row.className = 'bubble-row bubble-row--ours';
        row.id = 'typingRow';
        row.innerHTML = '<div class="bubble bubble--customer typing"><span></span><span></span><span></span></div>';
        el.chatMessages.appendChild(row);
        scrollMessagesToBottom(true);
    }

    function hideTypingIndicator() {
        document.getElementById('typingRow')?.remove();
    }

    async function refreshMessages(sessionId, { scroll = false, highlightLastAi = false } = {}) {
        if (sessionId !== state.selectedSessionId) return;
        const data = await api(`${API.messages}?session_id=${encodeURIComponent(sessionId)}`);
        state.messages = data.messages;
        renderMessages({ scroll, highlightLastAi });
    }

    async function refreshSidebarPreview(sessionId) {
        try {
            const data = await api(`${API.customers}?session_id=${encodeURIComponent(sessionId)}`);
            // Move this customer to the top of the list, like WhatsApp does
            // when a conversation gets new activity.
            const idx = state.customers.findIndex((c) => c.session_id === sessionId);
            const merged = { ...(idx !== -1 ? state.customers[idx] : {}), ...data.customer };
            const lastMsg = state.messages[state.messages.length - 1];
            if (lastMsg) {
                merged.last_message = lastMsg.content;
                merged.last_message_type = lastMsg.type;
            }
            if (idx !== -1) state.customers.splice(idx, 1);
            state.customers.unshift(merged);

            clearCustomerListDom();
            renderCustomers(state.customers);
            markSelectedInList(state.selectedSessionId);
        } catch {
            /* Non-critical — sidebar preview will catch up on next load. */
        }
    }

    // ------------------------------------------------------------------
    // New chat
    // ------------------------------------------------------------------

    el.newChatBtn.addEventListener('click', createNewChat);

    async function createNewChat() {
        el.newChatBtn.disabled = true;
        try {
            const data = await api(API.customers, {
                method: 'POST',
                body: JSON.stringify({}),
            });
            const customer = data.customer;

            state.customers.unshift(customer);
            const node = buildCustomerItem(customer);
            el.customerList.prepend(node);
            el.customerListEmpty.hidden = true;

            await selectCustomer(customer.session_id);
            openDetailsPanel();
            toast('New conversation started.');
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            el.newChatBtn.disabled = false;
        }
    }

    // ------------------------------------------------------------------
    // Customer details panel
    // ------------------------------------------------------------------

    const FORM_FIELDS = [
        'first_name', 'last_name', 'username', 'phone',
        'country', 'email', 'city', 'address', 'tax_id', 'details',
    ];

    function openDetailsPanel() {
        if (!state.selectedCustomer) return;
        FORM_FIELDS.forEach((field) => {
            const input = document.getElementById(`field_${field}`);
            if (input) input.value = state.selectedCustomer[field] || '';
        });
        document.getElementById('field_session_id').value = state.selectedCustomer.session_id;
        document.getElementById('field_session_id_display').textContent = state.selectedCustomer.session_id;

        el.panelOverlay.hidden = false;
        requestAnimationFrame(() => {
            el.detailsPanel.classList.add('is-open');
        });
        el.detailsPanel.setAttribute('aria-hidden', 'false');
    }

    function closeDetailsPanel() {
        el.detailsPanel.classList.remove('is-open');
        el.detailsPanel.setAttribute('aria-hidden', 'true');
        setTimeout(() => { el.panelOverlay.hidden = true; }, 220);
    }

    el.chatCustomerBtn.addEventListener('click', openDetailsPanel);
    el.chatDetailsBtn.addEventListener('click', openDetailsPanel);
    el.closeDetailsBtn.addEventListener('click', closeDetailsPanel);
    el.panelOverlay.addEventListener('click', closeDetailsPanel);

    el.detailsForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const sessionId = document.getElementById('field_session_id').value;
        if (!sessionId) return;

        const payload = {};
        FORM_FIELDS.forEach((field) => {
            payload[field] = document.getElementById(`field_${field}`).value.trim();
        });

        el.saveDetailsBtn.disabled = true;
        el.saveDetailsBtn.textContent = 'Saving…';

        try {
            const data = await api(`${API.customers}?session_id=${encodeURIComponent(sessionId)}`, {
                method: 'PUT',
                body: JSON.stringify(payload),
            });

            state.selectedCustomer = data.customer;
            applyCustomerToHeader(data.customer);
            updateCustomerItemInList(data.customer);

            toast('Customer details saved.');
            closeDetailsPanel();
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            el.saveDetailsBtn.disabled = false;
            el.saveDetailsBtn.textContent = 'Save changes';
        }
    });

    // ------------------------------------------------------------------
    // Keyboard shortcuts
    // ------------------------------------------------------------------

    document.addEventListener('keydown', (e) => {
        const typingInField = ['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName);

        if (e.key === 'Escape') {
            if (el.detailsPanel.classList.contains('is-open')) {
                closeDetailsPanel();
            } else if (el.app.classList.contains('is-chat-open') && !isDesktop()) {
                closeChat();
            } else if (typingInField) {
                document.activeElement.blur();
            }
            return;
        }

        if (typingInField) return;

        if (e.key.toLowerCase() === 'n') {
            e.preventDefault();
            createNewChat();
        } else if (e.key.toLowerCase() === 'i' && state.selectedSessionId) {
            e.preventDefault();
            openDetailsPanel();
        } else if (e.key === '/') {
            e.preventDefault();
            el.searchInput.focus();
        }
    });

    // ------------------------------------------------------------------
    // Mobile gesture: edge-swipe right on an open chat to go back
    // ------------------------------------------------------------------

    (() => {
        let startX = null;
        let startY = null;
        let tracking = false;

        el.chat.addEventListener('touchstart', (e) => {
            if (isDesktop() || e.touches.length !== 1) return;
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            // Only an edge swipe counts, so vertical scrolling and text
            // selection inside the thread are never hijacked.
            tracking = startX < 44;
        }, { passive: true });

        el.chat.addEventListener('touchend', (e) => {
            if (!tracking || startX === null || isDesktop()) {
                tracking = false;
                startX = null;
                return;
            }
            const dx = e.changedTouches[0].clientX - startX;
            const dy = e.changedTouches[0].clientY - startY;
            if (dx > 70 && Math.abs(dy) < 55) closeChat();
            tracking = false;
            startX = null;
            startY = null;
        }, { passive: true });
    })();

    // ------------------------------------------------------------------
    // Viewport changes
    // ------------------------------------------------------------------

    // Crossing the breakpoint mid-session shouldn't leave the app in a
    // half-applied state (e.g. desktop showing the placeholder while a
    // conversation is loaded).
    function handleBreakpointChange(matches) {
        if (!matches) return;
        const hasConversation = Boolean(state.selectedSessionId);
        el.chatPlaceholder.hidden = hasConversation;
        el.chatConversation.hidden = !hasConversation;
    }

    if (typeof window.matchMedia === 'function') {
        const mq = window.matchMedia(DESKTOP_QUERY);
        const onChange = (e) => handleBreakpointChange(e.matches);
        // addEventListener is the modern API; addListener keeps this working
        // on older iOS Safari.
        if (typeof mq.addEventListener === 'function') {
            mq.addEventListener('change', onChange);
        } else if (typeof mq.addListener === 'function') {
            mq.addListener(onChange);
        }
    }

    // ------------------------------------------------------------------
    // Boot
    // ------------------------------------------------------------------

    syncSendButton();
    loadCustomers({ reset: true });
})();
