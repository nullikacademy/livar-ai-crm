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
        // Highest message id already on screen. The conversation poll asks
        // for rows after this one instead of re-fetching the thread.
        maxMessageId: 0,
    };

    /** How often to look for new rows, in ms. */
    const POLL_MESSAGES_MS = 8000;
    const POLL_SIDEBAR_MS = 25000;

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
        // wa_profile_name is whatever the customer calls themselves on
        // WhatsApp. It sits below an agent-entered name but above the
        // bare number, which is what a first contact would otherwise show.
        return name
            || customer.username
            || customer.wa_profile_name
            || customer.phone
            || 'Unnamed customer';
    }

    function initials(customer) {
        const name = fullName(customer);
        const parts = name.split(' ').filter(Boolean);
        if (parts.length === 0) return '?';
        if (parts.length === 1) return parts[0].slice(0, 2);
        return (parts[0][0] + parts[1][0]);
    }

    function relativeTime(idOrDate) {
        // n8n_chat_history now carries created_at, so the sidebar shows
        // when the last message actually happened; the customer's own
        // created_at is the fallback for a conversation with no messages.
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
                    <span class="customer-item__time">${escapeHtml(relativeTime(customer.last_activity_at || customer.created_at))}</span>
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
        state.maxMessageId = 0;
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

    /** Full teardown and rebuild. Only for switching conversations. */
    function renderMessages({ scroll = false, highlightLastAi = false } = {}) {
        clearMessageBubbles();

        if (state.messages.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'chat__empty-state';
            empty.textContent = 'No messages yet. Start the conversation below.';
            el.chatMessages.appendChild(empty);
            trackMaxId(state.messages);
            return;
        }

        const frag = document.createDocumentFragment();
        let lastAiRow = null;

        state.messages.forEach((msg) => {
            const row = buildBubbleRow(msg);
            frag.appendChild(row);
            if (isOutbound(msg)) lastAiRow = row;
        });

        el.chatMessages.appendChild(frag);
        trackMaxId(state.messages);

        if (highlightLastAi && lastAiRow) {
            const bubble = lastAiRow.querySelector('.bubble');
            requestAnimationFrame(() => bubble.classList.add('is-new'));
        }

        if (scroll) scrollMessagesToBottom();
    }

    /**
     * Adds rows without touching the ones already on screen.
     *
     * renderMessages() rebuilds everything, which with media re-requests
     * and re-flashes every image on each poll tick. The polling path uses
     * this instead.
     */
    function appendMessages(newOnes) {
        if (!newOnes || newOnes.length === 0) return;

        // The first arrival replaces the empty-state placeholder.
        el.chatMessages.querySelector('.chat__empty-state')?.remove();

        // Stay pinned to the bottom only if we were already there, so a
        // new message can't yank the view away from someone reading back.
        const { scrollTop, scrollHeight, clientHeight } = el.chatMessages;
        const wasAtBottom = scrollHeight - scrollTop - clientHeight < 120;

        const frag = document.createDocumentFragment();
        newOnes.forEach((msg) => {
            state.messages.push(msg);
            frag.appendChild(buildBubbleRow(msg));
        });
        el.chatMessages.appendChild(frag);
        trackMaxId(newOnes);

        if (wasAtBottom) scrollMessagesToBottom(true);
    }

    function trackMaxId(messages) {
        messages.forEach((m) => {
            const id = Number(m.id);
            if (Number.isFinite(id) && id > state.maxMessageId) state.maxMessageId = id;
        });
    }

    /**
     * Which side a bubble sits on.
     *
     * `direction` is authoritative for anything WhatsApp touched; rows
     * written before it existed only have the LangChain `type`.
     */
    function isOutbound(msg) {
        if (msg.direction === 'out') return true;
        if (msg.direction === 'in') return false;
        return msg.type === 'ai';
    }

    function buildBubbleRow(msg) {
        const isOurs = isOutbound(msg);
        const row = document.createElement('div');
        row.className = `bubble-row ${isOurs ? 'bubble-row--ours' : 'bubble-row--customer'}`;
        if (msg.id) row.dataset.messageId = String(msg.id);

        const bubble = document.createElement('div');
        bubble.className = `bubble ${isOurs ? 'bubble--ours' : 'bubble--customer'}`;
        if (msg.pending) bubble.classList.add('is-pending');

        const kind = msg.msg_type || 'text';
        if (kind !== 'text' && kind !== 'location' && kind !== 'unsupported' && msg.media_url) {
            bubble.classList.add('bubble--media');
        }

        switch (kind) {
            case 'image':
            case 'sticker':
                buildImageBody(bubble, msg);
                break;
            case 'video':
                buildVideoBody(bubble, msg);
                break;
            case 'audio':
                buildAudioBody(bubble, msg);
                break;
            case 'document':
                buildDocumentBody(bubble, msg);
                break;
            case 'location':
                buildLocationBody(bubble, msg);
                break;
            case 'unsupported':
                buildUnsupportedBody(bubble);
                break;
            default:
                buildTextBody(bubble, msg);
        }

        if (isOurs) {
            appendStatus(bubble, msg);
            // Copy only makes sense for something with words in it.
            if (msg.content) appendCopyAction(bubble, msg.content);
        }

        row.appendChild(bubble);
        return row;
    }

    // ------------------------------------------------------------------
    // Bubble bodies
    //
    // Every URL below is assigned through a DOM property (img.src = url),
    // never interpolated into an HTML string: escapeHtml() does not
    // escape quotes, so attribute interpolation is injectable. All of
    // them are same-origin api/media.php?id=<int> anyway.
    // ------------------------------------------------------------------

    function buildTextBody(bubble, msg) {
        const text = document.createElement('div');
        text.className = 'bubble__text';
        linkifyInto(text, msg.content || '');
        bubble.appendChild(text);
    }

    function buildImageBody(bubble, msg) {
        if (!msg.media_url) {
            buildMissingMediaBody(bubble, 'Photo');
            return;
        }
        const img = document.createElement('img');
        img.className = 'bubble__image';
        img.loading = 'lazy';
        img.alt = msg.content || 'Photo';
        img.src = msg.media_url;
        img.addEventListener('click', () => openLightbox(msg.media_url, img.alt));
        bubble.appendChild(img);
        appendCaption(bubble, msg.content);
    }

    function buildVideoBody(bubble, msg) {
        if (!msg.media_url) {
            buildMissingMediaBody(bubble, 'Video');
            return;
        }
        const video = document.createElement('video');
        video.className = 'bubble__video';
        video.controls = true;
        video.preload = 'metadata';
        video.src = msg.media_url;
        bubble.appendChild(video);
        appendCaption(bubble, msg.content);
    }

    function buildAudioBody(bubble, msg) {
        if (!msg.media_url) {
            buildMissingMediaBody(bubble, 'Voice message');
            return;
        }
        const audio = document.createElement('audio');
        audio.className = 'bubble__audio';
        audio.controls = true;
        audio.preload = 'metadata';
        audio.src = msg.media_url;
        bubble.appendChild(audio);
        appendCaption(bubble, msg.content);
    }

    function buildDocumentBody(bubble, msg) {
        if (!msg.media_url) {
            buildMissingMediaBody(bubble, 'Document');
            return;
        }
        const link = document.createElement('a');
        link.className = 'bubble__doc';
        link.href = msg.media_url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';

        const icon = document.createElement('span');
        icon.className = 'bubble__doc-icon';
        icon.innerHTML = docIconSvg();

        const body = document.createElement('span');
        body.className = 'bubble__doc-body';

        const name = document.createElement('span');
        name.className = 'bubble__doc-name';
        name.textContent = msg.media_name || 'Document';

        const meta = document.createElement('span');
        meta.className = 'bubble__doc-meta';
        meta.textContent = msg.media_size ? formatBytes(msg.media_size) : 'Open';

        body.append(name, meta);
        link.append(icon, body);
        bubble.appendChild(link);
        appendCaption(bubble, msg.content);
    }

    function buildLocationBody(bubble, msg) {
        const link = document.createElement('a');
        link.className = 'bubble__location';
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        // Built with URLSearchParams rather than string concatenation so
        // the coordinates can't smuggle anything into the query.
        const params = new URLSearchParams({ q: `${msg.latitude},${msg.longitude}` });
        link.href = `https://www.google.com/maps?${params.toString()}`;

        const pin = document.createElement('span');
        pin.className = 'bubble__location-pin';
        pin.innerHTML = pinIconSvg();

        const body = document.createElement('span');

        const name = document.createElement('span');
        name.className = 'bubble__location-name';
        name.textContent = msg.place_name || 'Shared location';

        const address = document.createElement('span');
        address.className = 'bubble__location-address';
        address.textContent = msg.place_address
            || `${Number(msg.latitude).toFixed(5)}, ${Number(msg.longitude).toFixed(5)}`;

        body.append(name, address);
        link.append(pin, body);
        bubble.appendChild(link);
    }

    function buildUnsupportedBody(bubble) {
        const note = document.createElement('div');
        note.className = 'bubble__unsupported';
        note.textContent = 'Unsupported message type — open WhatsApp to view it.';
        bubble.appendChild(note);
    }

    /** A media row whose file never made it to disk. */
    function buildMissingMediaBody(bubble, label) {
        bubble.classList.remove('bubble--media');
        const note = document.createElement('div');
        note.className = 'bubble__unsupported';
        note.textContent = `${label} — no longer available.`;
        bubble.appendChild(note);
    }

    function appendCaption(bubble, caption) {
        if (!caption) return;
        const node = document.createElement('div');
        node.className = 'bubble__caption';
        linkifyInto(node, caption);
        bubble.appendChild(node);
    }

    function appendStatus(bubble, msg) {
        if (!msg.wa_status) return;
        const node = document.createElement('div');
        node.className = 'bubble__status';

        if (msg.wa_status === 'failed') {
            node.classList.add('is-failed');
            node.textContent = 'Not delivered';
        } else if (msg.wa_status === 'read') {
            node.classList.add('is-read');
            node.textContent = '✓✓ Read';
        } else if (msg.wa_status === 'delivered') {
            node.textContent = '✓✓ Delivered';
        } else {
            node.textContent = '✓ Sent';
        }

        bubble.appendChild(node);
    }

    function appendCopyAction(bubble, content) {
        const actions = document.createElement('div');
        actions.className = 'bubble__actions';
        const copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.className = 'bubble__copy';
        copyBtn.innerHTML = copyIconSvg() + '<span>Copy</span>';
        copyBtn.addEventListener('click', () => copyToClipboard(content, copyBtn));
        actions.appendChild(copyBtn);
        bubble.appendChild(actions);
    }

    /**
     * Writes text into a node, turning bare URLs into anchors.
     *
     * Deliberately builds nodes rather than assembling HTML: the anchor
     * text and href both go in through DOM properties, so no part of a
     * customer's message is ever parsed as markup.
     */
    function linkifyInto(node, text) {
        const pattern = /https?:\/\/[^\s<>"']+/g;
        let lastIndex = 0;
        let match;

        while ((match = pattern.exec(text)) !== null) {
            if (match.index > lastIndex) {
                node.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
            }
            // Trailing punctuation is almost never part of the link.
            let url = match[0];
            const trailing = url.match(/[.,;:!?)\]]+$/);
            if (trailing) url = url.slice(0, -trailing[0].length);

            const anchor = document.createElement('a');
            anchor.className = 'bubble__link';
            anchor.href = url;
            anchor.target = '_blank';
            anchor.rel = 'noopener noreferrer';
            anchor.textContent = url;
            node.appendChild(anchor);

            if (trailing) node.appendChild(document.createTextNode(trailing[0]));
            lastIndex = match.index + match[0].length;
        }

        if (lastIndex < text.length) {
            node.appendChild(document.createTextNode(text.slice(lastIndex)));
        }
    }

    function formatBytes(bytes) {
        const n = Number(bytes);
        if (!Number.isFinite(n) || n <= 0) return '';
        if (n < 1024) return `${n} B`;
        if (n < 1048576) return `${(n / 1024).toFixed(0)} KB`;
        return `${(n / 1048576).toFixed(1)} MB`;
    }

    // ------------------------------------------------------------------
    // Lightbox
    // ------------------------------------------------------------------

    function openLightbox(url, alt) {
        closeLightbox();

        const box = document.createElement('div');
        box.className = 'lightbox';
        box.id = 'lightbox';

        const img = document.createElement('img');
        img.className = 'lightbox__image';
        img.alt = alt || '';
        img.src = url;
        // Clicking the image itself shouldn't dismiss; clicking around it should.
        img.addEventListener('click', (e) => e.stopPropagation());

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'lightbox__close';
        close.setAttribute('aria-label', 'Close');
        close.innerHTML = closeIconSvg();

        box.append(img, close);
        box.addEventListener('click', closeLightbox);
        document.body.appendChild(box);
    }

    function closeLightbox() {
        document.getElementById('lightbox')?.remove();
    }

    function docIconSvg() {
        return '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>';
    }

    function pinIconSvg() {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
    }

    function closeIconSvg() {
        return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>';
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
        state.maxMessageId = 0;
        renderMessages({ scroll, highlightLastAi });
    }

    // ------------------------------------------------------------------
    // Polling
    //
    // Messages now arrive from a phone rather than from something the
    // agent just did, so without this an inbound WhatsApp message would
    // never show up until the conversation was reopened.
    // ------------------------------------------------------------------

    let messagePollTimer = null;
    let sidebarPollTimer = null;

    function startPolling() {
        stopPolling();
        if (document.hidden) return;

        messagePollTimer = setInterval(pollMessages, POLL_MESSAGES_MS);
        sidebarPollTimer = setInterval(pollSidebar, POLL_SIDEBAR_MS);
    }

    function stopPolling() {
        if (messagePollTimer !== null) clearInterval(messagePollTimer);
        if (sidebarPollTimer !== null) clearInterval(sidebarPollTimer);
        messagePollTimer = null;
        sidebarPollTimer = null;
    }

    /** Asks only for rows newer than the last one on screen. */
    async function pollMessages() {
        const sessionId = state.selectedSessionId;
        if (!sessionId || state.isSending) return;

        try {
            const params = new URLSearchParams({
                session_id: sessionId,
                since_id: String(state.maxMessageId),
            });
            const data = await api(`${API.messages}?${params.toString()}`);

            // The agent may have switched conversations mid-request.
            if (sessionId !== state.selectedSessionId) return;
            if (!data.messages || data.messages.length === 0) return;

            appendMessages(data.messages);
        } catch {
            /* A dropped poll is not worth a toast; the next tick retries. */
        }
    }

    /** Refreshes page 0 of the sidebar and merges it in place. */
    async function pollSidebar() {
        if (state.isLoadingCustomers || state.search !== '') return;

        try {
            const params = new URLSearchParams({ limit: PAGE_SIZE, offset: 0, search: '' });
            const data = await api(`${API.customers}?${params.toString()}`);

            const seen = new Set(data.customers.map((c) => c.session_id));
            // Keep anything already loaded further down the list, in its
            // existing order, behind the freshly ordered first page.
            const tail = state.customers.filter((c) => !seen.has(c.session_id));
            state.customers = [...data.customers, ...tail];

            clearCustomerListDom();
            renderCustomers(state.customers);
            markSelectedInList(state.selectedSessionId);
        } catch {
            /* Same: the next tick will catch up. */
        }
    }

    // A background tab should not keep polling; coming back should not
    // wait a full interval to catch up.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopPolling();
            return;
        }
        startPolling();
        pollMessages();
        pollSidebar();
    });

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
            if (document.getElementById('lightbox')) {
                closeLightbox();
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
    startPolling();
})();
