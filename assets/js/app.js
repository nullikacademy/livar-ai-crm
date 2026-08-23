/**
 * LiVAR Packaging CRM — authenticated WhatsApp inbox frontend.
 */
(() => {
    'use strict';

    const API = {
        customers: 'api/customers.php',
        messages: 'api/messages.php',
        draft: 'api/webhook.php',
        send: 'api/send.php',
        upload: 'api/upload.php',
    };
    const PAGE_SIZE = 30;
    const DESKTOP_QUERY = '(min-width: 900px)';
    const MESSAGE_POLL_MS = 8000;
    const SIDEBAR_POLL_MS = 25000;

    const el = {
        app: document.getElementById('app'),
        sidebar: document.getElementById('sidebar'),
        searchInput: document.getElementById('searchInput'),
        searchClear: document.getElementById('searchClear'),
        customerList: document.getElementById('customerList'),
        customerSkeleton: document.getElementById('customerSkeleton'),
        customerListEmpty: document.getElementById('customerListEmpty'),
        newChatBtn: document.getElementById('newChatBtn'),
        chat: document.getElementById('chat'),
        chatPlaceholder: document.getElementById('chatPlaceholder'),
        chatConversation: document.getElementById('chatConversation'),
        chatBackBtn: document.getElementById('chatBackBtn'),
        chatCustomerBtn: document.getElementById('chatCustomerBtn'),
        chatAvatar: document.getElementById('chatAvatar'),
        chatCustomerName: document.getElementById('chatCustomerName'),
        chatCustomerPhone: document.getElementById('chatCustomerPhone'),
        chatWindowStatus: document.getElementById('chatWindowStatus'),
        chatDetailsBtn: document.getElementById('chatDetailsBtn'),
        chatMessages: document.getElementById('chatMessages'),
        messagesSkeleton: document.getElementById('messagesSkeleton'),
        scrollJumpBtn: document.getElementById('scrollJumpBtn'),
        windowNotice: document.getElementById('windowNotice'),
        composerInput: document.getElementById('composerInput'),
        generateBtn: document.getElementById('generateBtn'),
        sendBtn: document.getElementById('sendBtn'),
        attachBtn: document.getElementById('attachBtn'),
        attachInput: document.getElementById('attachInput'),
        attachMenu: document.getElementById('attachMenu'),
        chooseFileBtn: document.getElementById('chooseFileBtn'),
        chooseLocationBtn: document.getElementById('chooseLocationBtn'),
        attachmentPreview: document.getElementById('attachmentPreview'),
        attachmentThumb: document.getElementById('attachmentThumb'),
        attachmentName: document.getElementById('attachmentName'),
        attachmentMeta: document.getElementById('attachmentMeta'),
        removeAttachmentBtn: document.getElementById('removeAttachmentBtn'),
        locationForm: document.getElementById('locationForm'),
        locationLatitude: document.getElementById('locationLatitude'),
        locationLongitude: document.getElementById('locationLongitude'),
        locationName: document.getElementById('locationName'),
        locationAddress: document.getElementById('locationAddress'),
        cancelLocationBtn: document.getElementById('cancelLocationBtn'),
        applyLocationBtn: document.getElementById('applyLocationBtn'),
        lightbox: document.getElementById('lightbox'),
        lightboxImage: document.getElementById('lightboxImage'),
        lightboxClose: document.getElementById('lightboxClose'),
        panelOverlay: document.getElementById('panelOverlay'),
        detailsPanel: document.getElementById('detailsPanel'),
        closeDetailsBtn: document.getElementById('closeDetailsBtn'),
        detailsForm: document.getElementById('detailsForm'),
        saveDetailsBtn: document.getElementById('saveDetailsBtn'),
        toastStack: document.getElementById('toastStack'),
    };

    const state = {
        customers: [],
        offset: 0,
        hasMore: true,
        isLoadingCustomers: false,
        isPollingSidebar: false,
        isPollingMessages: false,
        search: '',
        selectedSessionId: null,
        selectedCustomer: null,
        messages: [],
        isSending: false,
        isDrafting: false,
        isUploading: false,
        attachment: null,
        uploadSequence: 0,
        windowOpen: false,
        messagePollTimer: null,
        sidebarPollTimer: null,
    };

    function debounce(fn, wait) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), wait);
        };
    }

    function fullName(customer) {
        const name = [customer.first_name, customer.last_name].filter(Boolean).join(' ').trim();
        return name
            || customer.wa_profile_name
            || customer.username
            || customer.phone
            || customer.wa_id
            || 'Unnamed customer';
    }

    function initials(customer) {
        const parts = fullName(customer).split(' ').filter(Boolean);
        if (parts.length === 0) return '?';
        if (parts.length === 1) return parts[0].slice(0, 2);
        return parts[0][0] + parts[1][0];
    }

    function relativeTime(value) {
        if (!value) return '';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '';
        const minutes = Math.max(0, Math.round((Date.now() - date.getTime()) / 60000));
        if (minutes < 1) return 'now';
        if (minutes < 60) return `${minutes}m`;
        const hours = Math.round(minutes / 60);
        if (hours < 24) return `${hours}h`;
        const days = Math.round(hours / 24);
        if (days < 7) return `${days}d`;
        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    function truncate(value, max) {
        const text = String(value || '');
        return text.length > max ? text.slice(0, max - 1) + '…' : text;
    }

    function formatBytes(size) {
        const bytes = Number(size);
        if (!Number.isFinite(bytes) || bytes <= 0) return '';
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    function toast(message, variant = 'success') {
        const node = document.createElement('div');
        node.className = `toast toast--${variant}`;
        node.textContent = message;
        el.toastStack.appendChild(node);
        setTimeout(() => node.remove(), 3200);
    }

    async function api(url, options = {}) {
        const request = { ...options };
        const headers = new Headers(options.headers || {});
        if (options.body && !(options.body instanceof FormData) && !headers.has('Content-Type')) {
            headers.set('Content-Type', 'application/json');
        }
        request.headers = headers;

        const response = await fetch(url, request);
        let data;
        try {
            data = await response.json();
        } catch {
            throw new Error('Unexpected server response.');
        }
        if (response.status === 401) {
            window.location.assign('login.php');
            throw new Error('Authentication required.');
        }
        if (!response.ok || data.success === false) {
            throw new Error(data.error || 'Something went wrong.');
        }
        return data;
    }

    function isDesktop() {
        return typeof window.matchMedia === 'function'
            ? window.matchMedia(DESKTOP_QUERY).matches
            : window.innerWidth >= 900;
    }

    // ------------------------------------------------------------------
    // Sidebar
    // ------------------------------------------------------------------

    async function loadCustomers({ reset = false } = {}) {
        if (state.isLoadingCustomers || (!reset && !state.hasMore)) return;
        state.isLoadingCustomers = true;

        if (reset) {
            state.offset = 0;
            state.hasMore = true;
            el.customerSkeleton.hidden = false;
            el.customerListEmpty.hidden = true;
        }

        try {
            const params = new URLSearchParams({
                limit: String(PAGE_SIZE),
                offset: String(state.offset),
                search: state.search,
            });
            const data = await api(`${API.customers}?${params.toString()}`);

            if (reset) {
                state.customers = data.customers;
            } else {
                mergeCustomerRows(data.customers, false);
            }
            state.hasMore = data.hasMore;
            state.offset = data.nextOffset;
            renderCustomerList();
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            el.customerSkeleton.hidden = true;
            state.isLoadingCustomers = false;
        }
    }

    function mergeCustomerRows(rows, putFirst) {
        const incomingIds = new Set(rows.map((row) => row.session_id));
        const existing = new Map(state.customers.map((row) => [row.session_id, row]));
        const merged = rows.map((row) => ({ ...(existing.get(row.session_id) || {}), ...row }));
        const remainder = state.customers.filter((row) => !incomingIds.has(row.session_id));
        state.customers = putFirst ? [...merged, ...remainder] : [...remainder, ...merged];
    }

    function renderCustomerList() {
        el.customerList.querySelectorAll('.customer-item').forEach((node) => node.remove());
        const fragment = document.createDocumentFragment();
        state.customers.forEach((customer) => fragment.appendChild(buildCustomerItem(customer)));
        el.customerList.appendChild(fragment);
        el.customerListEmpty.hidden = state.customers.length !== 0;
    }

    function buildCustomerItem(customer) {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'customer-item';
        item.setAttribute('role', 'option');
        item.dataset.sessionId = customer.session_id;
        item.classList.toggle('is-selected', customer.session_id === state.selectedSessionId);

        const avatar = document.createElement('span');
        avatar.className = 'avatar';
        avatar.textContent = initials(customer);

        const body = document.createElement('span');
        body.className = 'customer-item__body';
        const top = document.createElement('span');
        top.className = 'customer-item__top';
        const name = document.createElement('span');
        name.className = 'customer-item__name';
        name.textContent = fullName(customer);
        const time = document.createElement('span');
        time.className = 'customer-item__time';
        time.textContent = relativeTime(customer.last_message_created_at || customer.created_at);
        top.append(name, time);

        const preview = document.createElement('span');
        preview.className = 'customer-item__preview';
        if (customer.last_message) {
            if (customer.last_message_type === 'ai') {
                const prefix = document.createElement('span');
                prefix.className = 'customer-item__preview-prefix';
                prefix.textContent = 'You: ';
                preview.appendChild(prefix);
            }
            preview.appendChild(document.createTextNode(truncate(customer.last_message, 46)));
        } else {
            const empty = document.createElement('span');
            empty.className = 'customer-item__phone';
            empty.textContent = 'No messages yet';
            preview.appendChild(empty);
        }

        body.append(top, preview);
        item.append(avatar, body);
        item.addEventListener('click', () => selectCustomer(customer.session_id, { focusComposer: true }));
        return item;
    }

    function updateCustomerInState(customer, moveFirst = false) {
        const index = state.customers.findIndex((row) => row.session_id === customer.session_id);
        const merged = { ...(index >= 0 ? state.customers[index] : {}), ...customer };
        if (index >= 0) state.customers.splice(index, 1);
        if (moveFirst) {
            state.customers.unshift(merged);
        } else if (index >= 0) {
            state.customers.splice(index, 0, merged);
        } else {
            state.customers.push(merged);
        }
        renderCustomerList();
    }

    function markSelectedInList(sessionId) {
        el.customerList.querySelectorAll('.customer-item').forEach((node) => {
            node.classList.toggle('is-selected', node.dataset.sessionId === sessionId);
        });
    }

    const onSearchInput = debounce((value) => {
        state.search = value.trim();
        el.searchClear.hidden = state.search === '';
        loadCustomers({ reset: true });
    }, 300);

    el.searchInput.addEventListener('input', (event) => onSearchInput(event.target.value));
    el.searchClear.addEventListener('click', () => {
        el.searchInput.value = '';
        el.searchClear.hidden = true;
        state.search = '';
        loadCustomers({ reset: true });
        el.searchInput.focus();
    });
    el.customerList.addEventListener('scroll', () => {
        const { scrollTop, scrollHeight, clientHeight } = el.customerList;
        if (scrollHeight - scrollTop - clientHeight < 160) loadCustomers();
    });

    // ------------------------------------------------------------------
    // Conversation loading and polling
    // ------------------------------------------------------------------

    async function selectCustomer(sessionId, { focusComposer = false } = {}) {
        state.selectedSessionId = sessionId;
        state.selectedCustomer = null;
        state.messages = [];
        markSelectedInList(sessionId);
        clearAttachment();
        el.composerInput.value = '';
        autoGrowComposer();

        el.app.classList.add('is-chat-open');
        el.chatPlaceholder.hidden = true;
        el.chatConversation.hidden = false;
        el.messagesSkeleton.hidden = false;
        clearMessageBubbles();

        const known = state.customers.find((customer) => customer.session_id === sessionId);
        if (known) {
            state.selectedCustomer = known;
            applyCustomerToHeader(known);
            updateWindowState(known);
        }

        try {
            const [customerData, messageData] = await Promise.all([
                api(`${API.customers}?session_id=${encodeURIComponent(sessionId)}`),
                api(`${API.messages}?session_id=${encodeURIComponent(sessionId)}&since_id=0&limit=200`),
            ]);
            if (sessionId !== state.selectedSessionId) return;

            state.selectedCustomer = customerData.customer;
            state.messages = messageData.messages;
            applyStatuses(messageData.statuses || []);
            applyCustomerToHeader(customerData.customer);
            updateWindowState(customerData.customer);
            renderMessages({ scroll: true });
            startPolling();

            if (focusComposer && isDesktop()) el.composerInput.focus();
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            el.messagesSkeleton.hidden = true;
        }
    }

    function applyCustomerToHeader(customer) {
        el.chatAvatar.textContent = initials(customer);
        el.chatCustomerName.textContent = fullName(customer);
        const phone = customer.wa_id ? `+${customer.wa_id}` : (customer.phone || customer.email || 'No phone on file');
        el.chatCustomerPhone.textContent = phone;
    }

    function maxMessageId() {
        return state.messages.reduce((max, message) => {
            const id = Number(message.id);
            return Number.isFinite(id) ? Math.max(max, id) : max;
        }, 0);
    }

    async function pollOpenConversation() {
        if (
            state.isPollingMessages
            || !state.selectedSessionId
            || document.hidden
            || (!isDesktop() && !el.app.classList.contains('is-chat-open'))
        ) return;

        state.isPollingMessages = true;
        const sessionId = state.selectedSessionId;
        try {
            const sinceId = maxMessageId();
            const [messageData, customerData] = await Promise.all([
                api(`${API.messages}?session_id=${encodeURIComponent(sessionId)}&since_id=${sinceId}&limit=200`),
                api(`${API.customers}?session_id=${encodeURIComponent(sessionId)}`),
            ]);
            if (sessionId !== state.selectedSessionId) return;

            appendMessages(messageData.messages || []);
            applyStatuses(messageData.statuses || []);
            state.selectedCustomer = customerData.customer;
            applyCustomerToHeader(customerData.customer);
            updateWindowState(customerData.customer);
            updateCustomerInState(customerData.customer, false);
        } catch (error) {
            console.warn('[LiVAR] Conversation poll failed:', error.message);
        } finally {
            state.isPollingMessages = false;
        }
    }

    async function refreshSidebarFirstPage() {
        if (state.isPollingSidebar || state.isLoadingCustomers || document.hidden) return;
        state.isPollingSidebar = true;
        try {
            const params = new URLSearchParams({
                limit: String(PAGE_SIZE),
                offset: '0',
                search: state.search,
            });
            const data = await api(`${API.customers}?${params.toString()}`);
            mergeCustomerRows(data.customers, true);
            renderCustomerList();
        } catch (error) {
            console.warn('[LiVAR] Sidebar poll failed:', error.message);
        } finally {
            state.isPollingSidebar = false;
        }
    }

    function startPolling() {
        stopPolling();
        if (document.hidden) return;
        state.messagePollTimer = window.setInterval(pollOpenConversation, MESSAGE_POLL_MS);
        state.sidebarPollTimer = window.setInterval(refreshSidebarFirstPage, SIDEBAR_POLL_MS);
    }

    function stopPolling() {
        if (state.messagePollTimer !== null) clearInterval(state.messagePollTimer);
        if (state.sidebarPollTimer !== null) clearInterval(state.sidebarPollTimer);
        state.messagePollTimer = null;
        state.sidebarPollTimer = null;
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopPolling();
            return;
        }
        pollOpenConversation();
        refreshSidebarFirstPage();
        startPolling();
    });

    // ------------------------------------------------------------------
    // Message rendering
    // ------------------------------------------------------------------

    function clearMessageBubbles() {
        el.chatMessages
            .querySelectorAll('.bubble-row, .chat__day-divider, .chat__empty-state')
            .forEach((node) => node.remove());
    }

    function renderMessages({ scroll = false } = {}) {
        clearMessageBubbles();
        if (state.messages.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'chat__empty-state';
            empty.textContent = 'No messages yet.';
            el.chatMessages.appendChild(empty);
            return;
        }

        const fragment = document.createDocumentFragment();
        state.messages.forEach((message) => fragment.appendChild(buildBubbleRow(message)));
        el.chatMessages.appendChild(fragment);
        if (scroll) scrollMessagesToBottom();
    }

    function appendMessages(messages) {
        if (!messages.length) return;
        const existing = new Set(state.messages.map((message) => String(message.id)));
        const newOnes = messages.filter((message) => !existing.has(String(message.id)));
        if (!newOnes.length) return;

        const nearBottom = el.chatMessages.scrollHeight - el.chatMessages.scrollTop - el.chatMessages.clientHeight < 160;
        el.chatMessages.querySelector('.chat__empty-state')?.remove();
        const fragment = document.createDocumentFragment();
        newOnes.forEach((message) => {
            state.messages.push(message);
            fragment.appendChild(buildBubbleRow(message));
        });
        el.chatMessages.appendChild(fragment);
        if (nearBottom) scrollMessagesToBottom(true);
    }

    function isOurMessage(message) {
        return message.direction === 'out'
            || (message.direction == null && message.type === 'ai');
    }

    function buildBubbleRow(message) {
        const ours = isOurMessage(message);
        const row = document.createElement('div');
        row.className = `bubble-row ${ours ? 'bubble-row--ours' : 'bubble-row--customer'}`;
        row.dataset.messageId = String(message.id);

        const bubble = document.createElement('div');
        bubble.className = `bubble ${ours ? 'bubble--ours' : 'bubble--customer'}`;
        if (message.pending) bubble.classList.add('is-pending');

        const mediaType = ['image', 'video', 'audio', 'document', 'location', 'sticker'].includes(message.msg_type);
        if (mediaType) bubble.classList.add('bubble--media');
        appendMessageBody(bubble, message);

        if (mediaType && message.content) {
            const caption = document.createElement('div');
            caption.className = 'bubble__caption';
            appendLinkifiedText(caption, message.content);
            bubble.appendChild(caption);
        }

        if (ours && message.content && message.msg_type === 'text') {
            const actions = document.createElement('div');
            actions.className = 'bubble__actions';
            const copyButton = document.createElement('button');
            copyButton.type = 'button';
            copyButton.className = 'bubble__copy';
            copyButton.innerHTML = copyIconSvg() + '<span>Copy</span>';
            copyButton.addEventListener('click', () => copyToClipboard(message.content, copyButton));
            actions.appendChild(copyButton);
            bubble.appendChild(actions);
        }

        row.appendChild(bubble);
        updateBubbleStatusNode(row, message);
        return row;
    }

    function appendMessageBody(bubble, message) {
        switch (message.msg_type) {
            case 'image':
            case 'sticker': {
                if (!message.media_url) {
                    appendUnsupported(bubble, 'Media unavailable');
                    return;
                }
                const image = document.createElement('img');
                image.className = 'bubble__image';
                image.loading = 'lazy';
                image.alt = message.msg_type === 'sticker' ? 'Sticker' : 'Photo attachment';
                image.src = message.media_url;
                image.addEventListener('click', () => openLightbox(message.media_url));
                bubble.appendChild(image);
                return;
            }
            case 'video': {
                const video = document.createElement('video');
                video.className = 'bubble__video';
                video.controls = true;
                video.preload = 'metadata';
                if (message.media_url) video.src = message.media_url;
                bubble.appendChild(video);
                return;
            }
            case 'audio': {
                const audio = document.createElement('audio');
                audio.className = 'bubble__audio';
                audio.controls = true;
                audio.preload = 'metadata';
                if (message.media_url) audio.src = message.media_url;
                bubble.appendChild(audio);
                return;
            }
            case 'document': {
                const link = document.createElement('a');
                link.className = 'bubble__doc';
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                if (message.media_url) link.href = message.media_url;
                const icon = document.createElement('span');
                icon.className = 'bubble__doc-icon';
                icon.textContent = '📄';
                const meta = document.createElement('span');
                meta.className = 'bubble__doc-meta';
                const filename = document.createElement('strong');
                filename.textContent = message.media_name || 'Document';
                const size = document.createElement('small');
                size.textContent = formatBytes(message.media_size) || 'Open document';
                meta.append(filename, size);
                link.append(icon, meta);
                bubble.appendChild(link);
                return;
            }
            case 'location': {
                const card = document.createElement('a');
                card.className = 'bubble__location';
                card.target = '_blank';
                card.rel = 'noopener noreferrer';
                if (Number.isFinite(Number(message.latitude)) && Number.isFinite(Number(message.longitude))) {
                    card.href = `https://www.google.com/maps?q=${encodeURIComponent(message.latitude)},${encodeURIComponent(message.longitude)}`;
                }
                const icon = document.createElement('span');
                icon.textContent = '📍';
                const details = document.createElement('span');
                const name = document.createElement('strong');
                name.textContent = message.place_name || 'Location';
                const address = document.createElement('small');
                address.textContent = message.place_address || 'Open in Google Maps';
                details.append(name, address);
                card.append(icon, details);
                bubble.appendChild(card);
                return;
            }
            case 'unsupported':
                appendUnsupported(bubble, 'Unsupported message type');
                return;
            case 'text':
            default: {
                const text = document.createElement('div');
                text.className = 'bubble__text';
                appendLinkifiedText(text, message.content || '');
                bubble.appendChild(text);
            }
        }
    }

    function appendUnsupported(bubble, label) {
        const unsupported = document.createElement('div');
        unsupported.className = 'bubble__unsupported';
        unsupported.textContent = label;
        bubble.appendChild(unsupported);
    }

    function appendLinkifiedText(container, text) {
        const value = String(text || '');
        const regex = /https?:\/\/[^\s<>"']+/gi;
        let cursor = 0;
        let match;
        while ((match = regex.exec(value)) !== null) {
            if (match.index > cursor) {
                container.appendChild(document.createTextNode(value.slice(cursor, match.index)));
            }
            const anchor = document.createElement('a');
            anchor.href = match[0];
            anchor.target = '_blank';
            anchor.rel = 'noopener noreferrer';
            anchor.textContent = match[0];
            container.appendChild(anchor);
            cursor = match.index + match[0].length;
        }
        if (cursor < value.length) {
            container.appendChild(document.createTextNode(value.slice(cursor)));
        }
    }

    function updateBubbleStatusNode(row, message) {
        row.querySelector('.bubble__status')?.remove();
        if (!isOurMessage(message)) return;

        const status = document.createElement('div');
        status.className = 'bubble__status';
        if (message.pending || message.wa_status === 'sending') {
            status.textContent = 'Sending…';
        } else if (message.wa_status === 'failed') {
            status.classList.add('is-failed');
            status.textContent = 'Failed';
            if (message.wa_error) status.title = message.wa_error;
        } else if (message.wa_status === 'read') {
            status.classList.add('is-read');
            status.textContent = '✓✓';
            status.title = 'Read';
        } else if (message.wa_status === 'delivered') {
            status.textContent = '✓✓';
            status.title = 'Delivered';
        } else {
            status.textContent = '✓';
            status.title = 'Sent';
        }
        row.querySelector('.bubble')?.appendChild(status);
    }

    function applyStatuses(statuses) {
        if (!statuses.length) return;
        const byId = new Map(statuses.map((status) => [String(status.id), status]));
        state.messages.forEach((message) => {
            const status = byId.get(String(message.id));
            if (!status) return;
            message.wa_status = status.wa_status;
            message.wa_error = status.wa_error;
            const row = el.chatMessages.querySelector(`.bubble-row[data-message-id="${cssEscape(String(message.id))}"]`);
            if (row) updateBubbleStatusNode(row, message);
        });
    }

    function cssEscape(value) {
        return window.CSS && CSS.escape
            ? CSS.escape(value)
            : value.replace(/["\\]/g, '\\$&');
    }

    function copyIconSvg() {
        return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
    }

    async function copyToClipboard(text, button) {
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            textarea.remove();
        }
        const label = button.querySelector('span');
        const original = label.textContent;
        button.classList.add('is-copied');
        label.textContent = 'Copied';
        setTimeout(() => {
            button.classList.remove('is-copied');
            label.textContent = original;
        }, 1500);
    }

    function openLightbox(url) {
        el.lightboxImage.src = url;
        el.lightbox.hidden = false;
        document.body.classList.add('is-lightbox-open');
    }

    function closeLightbox() {
        el.lightbox.hidden = true;
        el.lightboxImage.removeAttribute('src');
        document.body.classList.remove('is-lightbox-open');
    }

    el.lightboxClose.addEventListener('click', closeLightbox);
    el.lightbox.addEventListener('click', (event) => {
        if (event.target === el.lightbox) closeLightbox();
    });

    function scrollMessagesToBottom(smooth = false) {
        const target = el.chatMessages.scrollHeight;
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
        const distance = el.chatMessages.scrollHeight - el.chatMessages.scrollTop - el.chatMessages.clientHeight;
        el.scrollJumpBtn.hidden = distance < 200;
    });
    el.scrollJumpBtn.addEventListener('click', () => scrollMessagesToBottom(true));

    function closeChat() {
        el.app.classList.remove('is-chat-open');
        el.composerInput.blur();
    }
    el.chatBackBtn.addEventListener('click', closeChat);

    // ------------------------------------------------------------------
    // Reply window and composer
    // ------------------------------------------------------------------

    function updateWindowState(customer) {
        const inbound = customer?.last_inbound_at ? new Date(customer.last_inbound_at) : null;
        const inboundAgeMs = inbound && !Number.isNaN(inbound.getTime())
            ? Date.now() - inbound.getTime()
            : Number.POSITIVE_INFINITY;
        const remainingMs = inbound && !Number.isNaN(inbound.getTime())
            ? inbound.getTime() + 24 * 60 * 60 * 1000 - Date.now()
            : -1;
        const hasWhatsApp = Boolean(customer?.wa_id);
        state.windowOpen = hasWhatsApp && inboundAgeMs >= -5 * 60 * 1000 && remainingMs > 0;

        el.chatWindowStatus.hidden = false;
        el.chatWindowStatus.classList.toggle('is-open', state.windowOpen);
        el.chatWindowStatus.classList.toggle('is-closed', !state.windowOpen);
        if (!hasWhatsApp) {
            el.chatWindowStatus.textContent = 'No WhatsApp';
            el.windowNotice.textContent = 'Add a WhatsApp ID to this customer before sending.';
            el.windowNotice.hidden = false;
        } else if (state.windowOpen) {
            const hours = Math.max(1, Math.ceil(remainingMs / (60 * 60 * 1000)));
            el.chatWindowStatus.textContent = `Replies open · ${hours}h left`;
            el.windowNotice.hidden = true;
        } else {
            el.chatWindowStatus.textContent = 'Reply window closed';
            el.windowNotice.textContent = 'The 24-hour reply window has expired. An approved template is required.';
            el.windowNotice.hidden = false;
        }
        syncComposerControls();
    }

    function autoGrowComposer() {
        el.composerInput.style.height = 'auto';
        el.composerInput.style.height = Math.min(el.composerInput.scrollHeight, 148) + 'px';
    }

    function hasSendableContent() {
        if (state.attachment?.kind === 'location') return true;
        if (state.attachment?.kind === 'file') return Boolean(state.attachment.media_ref);
        return el.composerInput.value.trim() !== '';
    }

    function syncComposerControls() {
        const busy = state.isSending || state.isDrafting;
        el.sendBtn.disabled = busy || state.isUploading || !state.windowOpen || !hasSendableContent();
        el.generateBtn.disabled = busy || !state.selectedSessionId;
        el.attachBtn.disabled = state.isSending;
        el.composerInput.disabled = state.isSending;
    }

    el.composerInput.addEventListener('input', () => {
        autoGrowComposer();
        syncComposerControls();
    });
    el.composerInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
            event.preventDefault();
            handleSend();
        }
    });
    el.generateBtn.addEventListener('click', handleGenerateAnswer);
    el.sendBtn.addEventListener('click', handleSend);

    async function handleGenerateAnswer() {
        if (state.isDrafting || state.isSending) return;
        if (!state.selectedSessionId) {
            toast('Select a customer first.', 'error');
            return;
        }

        state.isDrafting = true;
        el.generateBtn.classList.add('is-loading');
        syncComposerControls();
        const sessionId = state.selectedSessionId;
        try {
            const data = await api(API.draft, {
                method: 'POST',
                body: JSON.stringify({ session_id: sessionId }),
            });
            if (sessionId !== state.selectedSessionId) return;
            el.composerInput.value = data.draft;
            autoGrowComposer();
            el.composerInput.focus();
            toast('Draft ready to edit.');
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            state.isDrafting = false;
            el.generateBtn.classList.remove('is-loading');
            syncComposerControls();
        }
    }

    async function handleSend() {
        if (state.isSending || state.isDrafting || state.isUploading) return;
        if (!state.selectedSessionId || !state.selectedCustomer) {
            toast('Select a customer first.', 'error');
            return;
        }
        if (!state.windowOpen) {
            toast('The WhatsApp reply window is closed.', 'error');
            return;
        }

        const text = el.composerInput.value.trim();
        const attachment = state.attachment;
        if (!attachment && text === '') {
            toast('Write a reply before sending.', 'error');
            return;
        }

        const payload = {
            session_id: state.selectedSessionId,
            type: attachment?.type || 'text',
            text,
        };
        if (attachment?.kind === 'file') {
            payload.media_ref = attachment.media_ref;
        } else if (attachment?.kind === 'location') {
            payload.latitude = attachment.latitude;
            payload.longitude = attachment.longitude;
            payload.place_name = attachment.name;
            payload.place_address = attachment.address;
        }

        const optimistic = {
            id: `pending_${Date.now()}`,
            type: 'ai',
            direction: 'out',
            msg_type: payload.type,
            content: text,
            pending: true,
            wa_status: 'sending',
        };
        if (attachment?.kind === 'file') {
            optimistic.media_url = attachment.localUrl;
            optimistic.media_mime = attachment.mime;
            optimistic.media_size = attachment.size;
            optimistic.media_name = attachment.name;
        } else if (attachment?.kind === 'location') {
            optimistic.latitude = attachment.latitude;
            optimistic.longitude = attachment.longitude;
            optimistic.place_name = attachment.name;
            optimistic.place_address = attachment.address;
            optimistic.content = [attachment.name, attachment.address].filter(Boolean).join(' ');
        }

        state.isSending = true;
        syncComposerControls();
        const savedText = text;
        const savedAttachment = attachment;
        const sendingSessionId = state.selectedSessionId;
        state.messages.push(optimistic);
        el.chatMessages.querySelector('.chat__empty-state')?.remove();
        el.chatMessages.appendChild(buildBubbleRow(optimistic));
        scrollMessagesToBottom(true);
        el.composerInput.value = '';
        autoGrowComposer();
        detachAttachmentForSend();

        try {
            const data = await api(API.send, {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            if (sendingSessionId !== state.selectedSessionId) {
                if (savedAttachment?.localUrl) URL.revokeObjectURL(savedAttachment.localUrl);
                refreshSidebarFirstPage();
                return;
            }
            const index = state.messages.findIndex((message) => message.id === optimistic.id);
            if (index >= 0) state.messages[index] = data.message;
            const pendingRow = el.chatMessages.querySelector(
                `.bubble-row[data-message-id="${cssEscape(String(optimistic.id))}"]`
            );
            if (pendingRow) pendingRow.replaceWith(buildBubbleRow(data.message));
            if (savedAttachment?.localUrl) URL.revokeObjectURL(savedAttachment.localUrl);
            // WhatsApp locations have no caption field. Preserve any typed
            // reply so it can be sent separately instead of silently dropping it.
            if (savedAttachment?.kind === 'location' && savedText) {
                el.composerInput.value = savedText;
                autoGrowComposer();
            }
            refreshSidebarFirstPage();
        } catch (error) {
            if (sendingSessionId !== state.selectedSessionId) {
                if (savedAttachment?.localUrl) URL.revokeObjectURL(savedAttachment.localUrl);
                toast(error.message, 'error');
                return;
            }
            state.messages = state.messages.filter((message) => message.id !== optimistic.id);
            el.chatMessages.querySelector(
                `.bubble-row[data-message-id="${cssEscape(String(optimistic.id))}"]`
            )?.remove();
            el.composerInput.value = savedText;
            state.attachment = savedAttachment;
            renderAttachmentPreview();
            autoGrowComposer();
            toast(error.message, 'error');
        } finally {
            state.isSending = false;
            syncComposerControls();
        }
    }

    // ------------------------------------------------------------------
    // Attachments and location
    // ------------------------------------------------------------------

    el.attachBtn.addEventListener('click', () => {
        el.attachMenu.hidden = !el.attachMenu.hidden;
    });
    el.chooseFileBtn.addEventListener('click', () => {
        el.attachMenu.hidden = true;
        el.attachInput.click();
    });
    el.chooseLocationBtn.addEventListener('click', () => {
        el.attachMenu.hidden = true;
        el.locationForm.hidden = false;
        el.locationLatitude.focus();
    });
    el.cancelLocationBtn.addEventListener('click', () => {
        el.locationForm.hidden = true;
    });
    el.applyLocationBtn.addEventListener('click', applyLocationAttachment);
    el.removeAttachmentBtn.addEventListener('click', () => clearAttachment());
    document.addEventListener('click', (event) => {
        if (
            !el.attachMenu.hidden
            && !el.attachMenu.contains(event.target)
            && !el.attachBtn.contains(event.target)
        ) {
            el.attachMenu.hidden = true;
        }
    });

    el.attachInput.addEventListener('change', async () => {
        const file = el.attachInput.files?.[0];
        el.attachInput.value = '';
        if (!file) return;

        clearAttachment();
        const sequence = ++state.uploadSequence;
        const localUrl = file.type.startsWith('image/') || file.type.startsWith('video/')
            ? URL.createObjectURL(file)
            : null;
        state.attachment = {
            kind: 'file',
            type: file.type.startsWith('image/') ? 'image' : (file.type.startsWith('video/') ? 'video' : 'document'),
            media_ref: null,
            name: file.name,
            mime: file.type,
            size: file.size,
            localUrl,
            uploading: true,
        };
        state.isUploading = true;
        renderAttachmentPreview();
        syncComposerControls();

        try {
            const form = new FormData();
            form.append('file', file);
            const data = await api(API.upload, { method: 'POST', body: form });
            if (sequence !== state.uploadSequence || state.attachment === null) return;
            state.attachment = {
                ...state.attachment,
                type: data.type,
                media_ref: data.media_ref,
                name: data.name,
                mime: data.mime,
                size: data.size,
                uploading: false,
            };
            renderAttachmentPreview();
        } catch (error) {
            if (sequence === state.uploadSequence) clearAttachment();
            toast(error.message, 'error');
        } finally {
            if (sequence === state.uploadSequence) state.isUploading = false;
            syncComposerControls();
        }
    });

    function applyLocationAttachment() {
        const latitude = Number(el.locationLatitude.value);
        const longitude = Number(el.locationLongitude.value);
        if (
            !Number.isFinite(latitude)
            || !Number.isFinite(longitude)
            || latitude < -90
            || latitude > 90
            || longitude < -180
            || longitude > 180
        ) {
            toast('Enter a valid latitude and longitude.', 'error');
            return;
        }

        clearAttachment();
        state.attachment = {
            kind: 'location',
            type: 'location',
            latitude,
            longitude,
            name: el.locationName.value.trim(),
            address: el.locationAddress.value.trim(),
        };
        el.locationForm.hidden = true;
        renderAttachmentPreview();
        syncComposerControls();
    }

    function clearAttachment({ revoke = true } = {}) {
        state.uploadSequence += 1;
        if (revoke && state.attachment?.localUrl) {
            URL.revokeObjectURL(state.attachment.localUrl);
        }
        state.attachment = null;
        state.isUploading = false;
        renderAttachmentPreview();
        syncComposerControls();
    }

    function detachAttachmentForSend() {
        state.attachment = null;
        renderAttachmentPreview();
    }

    function renderAttachmentPreview() {
        const attachment = state.attachment;
        el.attachmentThumb.replaceChildren();
        if (!attachment) {
            el.attachmentPreview.hidden = true;
            return;
        }

        el.attachmentPreview.hidden = false;
        el.attachmentName.textContent = attachment.kind === 'location'
            ? (attachment.name || 'Location')
            : attachment.name;
        el.attachmentMeta.textContent = attachment.kind === 'location'
            ? `${attachment.latitude}, ${attachment.longitude}`
            : (attachment.uploading ? 'Uploading…' : [attachment.type, formatBytes(attachment.size)].filter(Boolean).join(' · '));

        if (attachment.type === 'image' && attachment.localUrl) {
            const image = document.createElement('img');
            image.alt = '';
            image.src = attachment.localUrl;
            el.attachmentThumb.appendChild(image);
        } else {
            el.attachmentThumb.textContent = attachment.type === 'video'
                ? '🎥'
                : (attachment.type === 'location' ? '📍' : '📄');
        }
    }

    // ------------------------------------------------------------------
    // New chat and customer details
    // ------------------------------------------------------------------

    el.newChatBtn.addEventListener('click', createNewChat);
    async function createNewChat() {
        el.newChatBtn.disabled = true;
        try {
            const data = await api(API.customers, {
                method: 'POST',
                body: JSON.stringify({}),
            });
            state.customers.unshift(data.customer);
            renderCustomerList();
            await selectCustomer(data.customer.session_id);
            openDetailsPanel();
            toast('New conversation started.');
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            el.newChatBtn.disabled = false;
        }
    }

    const FORM_FIELDS = [
        'first_name', 'last_name', 'username', 'phone', 'wa_id',
        'wa_profile_name', 'country', 'email', 'city', 'address',
        'tax_id', 'details',
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
        requestAnimationFrame(() => el.detailsPanel.classList.add('is-open'));
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
    el.detailsForm.addEventListener('submit', async (event) => {
        event.preventDefault();
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
            updateWindowState(data.customer);
            updateCustomerInState(data.customer);
            toast('Customer details saved.');
            closeDetailsPanel();
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            el.saveDetailsBtn.disabled = false;
            el.saveDetailsBtn.textContent = 'Save changes';
        }
    });

    // ------------------------------------------------------------------
    // Keyboard, gestures, viewport
    // ------------------------------------------------------------------

    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'g') {
            event.preventDefault();
            handleGenerateAnswer();
            return;
        }

        const typingInField = ['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName);
        if (event.key === 'Escape') {
            if (!el.lightbox.hidden) {
                closeLightbox();
            } else if (!el.locationForm.hidden) {
                el.locationForm.hidden = true;
            } else if (el.detailsPanel.classList.contains('is-open')) {
                closeDetailsPanel();
            } else if (el.app.classList.contains('is-chat-open') && !isDesktop()) {
                closeChat();
            } else if (typingInField) {
                document.activeElement.blur();
            }
            return;
        }
        if (typingInField) return;

        if (event.key.toLowerCase() === 'n') {
            event.preventDefault();
            createNewChat();
        } else if (event.key.toLowerCase() === 'i' && state.selectedSessionId) {
            event.preventDefault();
            openDetailsPanel();
        } else if (event.key === '/') {
            event.preventDefault();
            el.searchInput.focus();
        }
    });

    (() => {
        let startX = null;
        let startY = null;
        let tracking = false;
        el.chat.addEventListener('touchstart', (event) => {
            if (isDesktop() || event.touches.length !== 1) return;
            startX = event.touches[0].clientX;
            startY = event.touches[0].clientY;
            tracking = startX < 44;
        }, { passive: true });
        el.chat.addEventListener('touchend', (event) => {
            if (!tracking || startX === null || isDesktop()) {
                tracking = false;
                startX = null;
                return;
            }
            const dx = event.changedTouches[0].clientX - startX;
            const dy = event.changedTouches[0].clientY - startY;
            if (dx > 70 && Math.abs(dy) < 55) closeChat();
            tracking = false;
            startX = null;
            startY = null;
        }, { passive: true });
    })();

    function handleBreakpointChange(matches) {
        if (!matches) return;
        const hasConversation = Boolean(state.selectedSessionId);
        el.chatPlaceholder.hidden = hasConversation;
        el.chatConversation.hidden = !hasConversation;
    }

    if (typeof window.matchMedia === 'function') {
        const query = window.matchMedia(DESKTOP_QUERY);
        const onChange = (event) => handleBreakpointChange(event.matches);
        if (typeof query.addEventListener === 'function') {
            query.addEventListener('change', onChange);
        } else if (typeof query.addListener === 'function') {
            query.addListener(onChange);
        }
    }

    // ------------------------------------------------------------------
    // Boot
    // ------------------------------------------------------------------

    syncComposerControls();
    loadCustomers({ reset: true });
    startPolling();
})();
