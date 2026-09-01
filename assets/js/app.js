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
        draft: 'api/draft.php',
        send: 'api/send.php',
        upload: 'api/upload.php',
        avatar: 'api/avatar.php',
        catalog: 'api/catalog.php',
        templates: 'api/templates.php',
        read: 'api/read.php',
    };

    /** How each customer label reads on screen. Mirrors CUSTOMER_LABELS. */
    const LABELS = {
        new: 'New',
        old: 'Old',
    };

    /**
     * WhatsApp only allows a free-form reply within 24h of the
     * customer's last message. Kept in sync with WHATSAPP_WINDOW_HOURS.
     */
    const WINDOW_HOURS = 24;

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
        sendBtn: document.getElementById('sendBtn'),
        attachBtn: document.getElementById('attachBtn'),
        attachInput: document.getElementById('attachInput'),
        attachChip: document.getElementById('attachChip'),
        attachChipIcon: document.getElementById('attachChipIcon'),
        attachChipName: document.getElementById('attachChipName'),
        attachChipMeta: document.getElementById('attachChipMeta'),
        attachChipRemove: document.getElementById('attachChipRemove'),
        windowPill: document.getElementById('windowPill'),
        windowNotice: document.getElementById('windowNotice'),
        // Details panel
        panelOverlay: document.getElementById('panelOverlay'),
        detailsPanel: document.getElementById('detailsPanel'),
        closeDetailsBtn: document.getElementById('closeDetailsBtn'),
        detailsForm: document.getElementById('detailsForm'),
        saveDetailsBtn: document.getElementById('saveDetailsBtn'),
        detailsAvatar: document.getElementById('detailsAvatar'),
        avatarInput: document.getElementById('avatarInput'),
        avatarPickBtn: document.getElementById('avatarPickBtn'),
        avatarRemoveBtn: document.getElementById('avatarRemoveBtn'),
        avatarHint: document.getElementById('avatarHint'),
        countryDetected: document.getElementById('countryDetected'),
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
        // A file staged by api/upload.php, waiting for Send.
        attachment: null,
        // The catalog configured on the settings page, if any. Loaded
        // once at boot: it is the same file for every conversation, and
        // asking on every attach-menu open would be a request per tap.
        catalog: null,
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
        // Four names, most deliberate first:
        //   - what an agent typed into the details panel here;
        //   - wa_contact_name, what the business saved for this number in
        //     the WhatsApp app's address book, mirrored by the
        //     smb_app_state_sync webhook -- "Ahmed — Al Fahed Building";
        //   - username, which is the company field;
        //   - wa_profile_name, what the CUSTOMER calls themselves, which
        //     is the only one of the four nobody here chose.
        // The bare number is the last resort a first contact would show.
        return name
            || customer.wa_contact_name
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

    /**
     * Fills an .avatar element with the customer's photo, or initials.
     *
     * The URL goes in through img.src rather than into an HTML string:
     * escapeHtml() does not escape quotes, so attribute interpolation is
     * injectable. It is a same-origin api/avatar.php URL either way.
     *
     * A photo that 404s (the file went missing, or another agent removed
     * it while this tab was open) falls back to the initials rather than
     * leaving a broken-image icon in the list.
     */
    /**
     * The colour an initials circle gets, from the customer's own id.
     *
     * Every avatar used to be the same orange, so a list without photos
     * was a column of identical blobs and the eye had nothing to catch.
     * A colour per contact makes the list scannable — and being derived
     * from session_id rather than from position, a customer keeps their
     * colour as the list reorders around them, which is the entire point.
     *
     * Pairs rather than one hue: the circle is a gradient, and picking
     * both ends by hand keeps white text readable on all of them, which
     * generating a hue at random does not.
     */
    const AVATAR_COLOURS = [
        ['#FFA65C', '#FF7A00'], ['#7FB2FF', '#2563EB'], ['#6EE7B7', '#059669'],
        ['#F9A8D4', '#DB2777'], ['#C4B5FD', '#7C3AED'], ['#FCD34D', '#D97706'],
        ['#67E8F9', '#0891B2'], ['#FCA5A5', '#DC2626'], ['#A3E635', '#4D7C0F'],
        ['#F0ABFC', '#A21CAF'], ['#94A3B8', '#475569'], ['#FDBA74', '#C2410C'],
    ];

    function avatarColour(customer) {
        const key = customer?.session_id || customer?.wa_id || '';
        // FNV-ish: cheap, stable, and spread evenly enough that adjacent
        // numbers do not land on the same colour.
        let hash = 2166136261;
        for (let i = 0; i < key.length; i += 1) {
            hash = Math.imul(hash ^ key.charCodeAt(i), 16777619) >>> 0;
        }
        return AVATAR_COLOURS[hash % AVATAR_COLOURS.length];
    }

    function paintAvatar(node, customer) {
        if (!node) return;
        node.textContent = '';
        node.classList.remove('avatar--photo');
        node.style.background = '';

        const url = customer?.avatar_url;
        if (!url) {
            const [from, to] = avatarColour(customer);
            node.style.background = `linear-gradient(140deg, ${from}, ${to})`;
            node.textContent = initials(customer || {});
            return;
        }

        const img = document.createElement('img');
        img.alt = '';
        img.loading = 'lazy';
        img.addEventListener('error', () => {
            node.classList.remove('avatar--photo');
            const [from, to] = avatarColour(customer);
            node.style.background = `linear-gradient(140deg, ${from}, ${to})`;
            node.textContent = initials(customer);
        });
        img.src = url;

        node.classList.add('avatar--photo');
        node.appendChild(img);
    }

    /** The label chip for a customer, or null when they have none. */
    function buildLabelChip(customer) {
        const label = customer?.label;
        if (!label || !LABELS[label]) return null;

        const chip = document.createElement('span');
        chip.className = `label-chip label-chip--${label}`;
        chip.textContent = LABELS[label];
        chip.title = `${LABELS[label]} customer`;
        return chip;
    }

    /**
     * A flag for a customer, as an image, or null.
     *
     * Not the emoji. Windows ships no country-flag glyphs at all — by
     * design, not by omission — so 🇦🇪 renders there as the bare letters
     * "AE", which is what everyone on Windows was seeing. The artwork is
     * vendored under assets/flags/ (see the LICENSE there), so this
     * costs no third-party request and only the flags actually on screen
     * are fetched.
     *
     * The emoji stays in the payload as the fallback: if a file is ever
     * missing, the letters are still better than a broken image icon.
     */
    function buildFlag(customer) {
        const code = customer?.country_code;
        // Built into a URL, so it is checked rather than trusted, even
        // though it comes from our own table in config/countries.php.
        if (!code || !/^[A-Za-z]{2}$/.test(code)) return null;

        const img = document.createElement('img');
        img.className = 'flag';
        img.alt = '';
        img.title = customer.country_name || code;
        img.addEventListener('error', () => {
            const fallback = document.createElement('span');
            fallback.className = 'flag flag--text';
            fallback.title = img.title;
            fallback.textContent = customer.country_flag || code.toUpperCase();
            img.replaceWith(fallback);
        });
        img.src = `assets/flags/${code.toLowerCase()}.svg`;
        return img;
    }

    /** Writes "🇪🇸 Marta Roig" into a node, flag first. */
    function paintName(node, customer) {
        if (!node) return;
        node.textContent = '';

        const flag = buildFlag(customer);
        if (flag) node.appendChild(flag);

        node.appendChild(document.createTextNode(fullName(customer)));
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

    /**
     * Brings the rendered list in line with state.customers, reusing the
     * rows that have not changed.
     *
     * The sidebar used to be torn down and rebuilt whole on every 25s
     * poll and after every send. With a real inbox that is dozens of rows
     * — each with an avatar image and a flag — destroyed and recreated on
     * a timer, which on a phone is visible as a flicker and wasted work
     * on a conversation nobody touched. It also dropped any :hover and
     * reset the row a finger was on mid-scroll.
     *
     * Rows are keyed by session_id, and only the ones whose rendered
     * content actually differs are replaced. This is the same reasoning
     * as appendMessages() versus renderMessages() in the thread.
     */
    function syncCustomerListDom() {
        const existing = new Map();
        el.customerList.querySelectorAll('.customer-item').forEach((node) => {
            existing.set(node.dataset.sessionId, node);
        });

        let previous = null;

        state.customers.forEach((customer) => {
            const current = existing.get(customer.session_id);
            const signature = rowSignature(customer);
            let node = current;

            if (!node) {
                node = buildCustomerItem(customer);
            } else if (node.dataset.signature !== signature) {
                // Only a row whose visible content changed is rebuilt.
                const fresh = buildCustomerItem(customer);
                node.replaceWith(fresh);
                node = fresh;
            }
            node.dataset.signature = signature;
            existing.delete(customer.session_id);

            // Move into place only when it is not already there, so a
            // reorder touches the rows that moved and no others.
            //
            // Anchored on the customer rows specifically, not on any
            // child: the list also holds the loading skeleton, and
            // comparing against firstElementChild matched that instead
            // and moved every row on every poll — the exact churn this
            // function exists to avoid.
            const expected = previous === null ? firstCustomerNode() : nextCustomerNode(previous);
            if (expected !== node) {
                el.customerList.insertBefore(node, expected);
            }
            previous = node;
        });

        // Anything left is no longer in the list.
        existing.forEach((node) => node.remove());

        markSelectedInList(state.selectedSessionId);
    }

    /**
     * Everything about a customer that the row actually draws.
     *
     * Compared as a string so an unchanged row is left alone: a poll that
     * returns identical data should cost nothing.
     */
    /** The first rendered customer row, skipping the skeleton. */
    function firstCustomerNode() {
        return el.customerList.querySelector('.customer-item');
    }

    /** The next customer row after this one, skipping anything else. */
    function nextCustomerNode(node) {
        let next = node.nextElementSibling;
        while (next && !next.classList.contains('customer-item')) {
            next = next.nextElementSibling;
        }
        return next;
    }

    function rowSignature(customer) {
        return [
            fullName(customer),
            customer.last_message,
            customer.last_message_type,
            customer.last_activity_at,
            customer.unread_count,
            customer.label,
            customer.avatar_url,
            customer.country_code,
        ].join('');
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

        // Marks stripped, not rendered: one truncated line has no room
        // for emphasis, but every reason not to show the asterisks that
        // would have produced it.
        const preview = customer.last_message
            ? escapeHtml(truncate(stripWhatsAppMarks(customer.last_message), 46))
            : '<span class="customer-item__phone">No messages yet</span>';
        const prefix = customer.last_message_type === 'ai'
            ? '<span class="customer-item__preview-prefix">You: </span>'
            : '';

        item.innerHTML = `
            <span class="avatar"></span>
            <span class="customer-item__body">
                <span class="customer-item__top">
                    <span class="customer-item__name"></span>
                    <span class="customer-item__time">${escapeHtml(relativeTime(customer.last_activity_at || customer.created_at))}</span>
                </span>
                <span class="customer-item__bottom">
                    <span class="customer-item__preview">${prefix}${preview}</span>
                </span>
            </span>
        `;

        const unread = Number(customer.unread_count) || 0;
        if (unread > 0) {
            item.classList.add('is-unread');
            const badge = document.createElement('span');
            badge.className = 'unread-badge';
            // 99+ rather than a badge that grows wide enough to shove the
            // preview out of the row.
            badge.textContent = unread > 99 ? '99+' : String(unread);
            badge.title = `${unread} unread message${unread > 1 ? 's' : ''}`;
            item.querySelector('.customer-item__bottom').appendChild(badge);
        }

        // Everything below goes in through DOM properties rather than the
        // template above. The avatar carries a URL and the flag carries a
        // title, and escapeHtml() does not escape quotes — so neither can
        // be interpolated into an attribute.
        paintAvatar(item.querySelector('.avatar'), customer);
        paintName(item.querySelector('.customer-item__name'), customer);

        const chip = buildLabelChip(customer);
        if (chip) item.querySelector('.customer-item__top').insertBefore(chip, item.querySelector('.customer-item__time'));

        item.dataset.signature = rowSignature(customer);
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

            // Opening it is reading it.
            markRead(sessionId);

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
        paintAvatar(el.chatAvatar, customer);
        paintName(el.chatCustomerName, customer);

        const chip = buildLabelChip(customer);
        if (chip) el.chatCustomerName.appendChild(chip);

        el.chatCustomerPhone.textContent = customer.phone || customer.email || 'No phone on file';
        refreshWindowNotice();
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
            case 'buttons':
                buildButtonsBody(bubble, msg);
                break;
            case 'template':
                buildTemplateBody(bubble, msg);
                break;
            case 'reply':
                buildReplyBody(bubble, msg);
                break;
            case 'unsupported':
                buildUnsupportedBody(bubble);
                break;
            default:
                buildTextBody(bubble, msg);
        }

        if (isOurs) {
            appendSourceTag(bubble, msg);
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
        renderWhatsAppText(text, msg.content || '');
        bubble.appendChild(text);
    }

    /**
     * A question we asked, with the options the customer was offered.
     *
     * The options are drawn as they look in WhatsApp — inert, because
     * they are a record of what was sent, not controls. Showing the
     * question without them would make the answer that comes back a
     * non sequitur.
     */
    function buildButtonsBody(bubble, msg) {
        bubble.classList.add('bubble--question');
        buildTextBody(bubble, msg);

        const options = Array.isArray(msg.buttons) ? msg.buttons : [];
        if (options.length === 0) return;

        const list = document.createElement('div');
        list.className = 'bubble__options';
        options.forEach((label) => {
            const option = document.createElement('span');
            option.className = 'bubble__option';
            option.textContent = label;
            list.appendChild(option);
        });
        bubble.appendChild(list);
    }

    /**
     * A template send. The text is the template already filled in — what
     * the customer actually received — and the tag says what it was
     * built from, which is the bit an agent needs when the reply is
     * confusing.
     */
    function buildTemplateBody(bubble, msg) {
        const tag = document.createElement('div');
        tag.className = 'bubble__tag';
        tag.textContent = msg.wa_template ? `Template · ${msg.wa_template}` : 'Template';
        bubble.appendChild(tag);

        buildTextBody(bubble, msg);
    }

    /** The customer tapping one of the options we offered. */
    function buildReplyBody(bubble, msg) {
        const tag = document.createElement('div');
        tag.className = 'bubble__tag';
        tag.textContent = 'Tapped an answer';
        bubble.appendChild(tag);

        buildTextBody(bubble, msg);
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
        // Meta expires media after about 30 days and a download can fail
        // outright, so the file behind a row is not guaranteed to be
        // there. Say so, rather than leaving a broken-image icon.
        img.addEventListener('error', () => replaceWithMissingMedia(bubble, img, 'Photo'));
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
        video.addEventListener('error', () => replaceWithMissingMedia(bubble, video, 'Video'));
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
        audio.addEventListener('error', () => replaceWithMissingMedia(bubble, audio, 'Voice message'));
        bubble.appendChild(audio);
        appendVoiceSummary(bubble, msg);
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

    /**
     * Swaps a media element that failed to load for the same note.
     *
     * The row can carry a media_url whose bytes are gone — Meta expires
     * media after about 30 days, and a download can simply have failed —
     * and a broken-image icon tells an agent nothing about which of
     * those happened or whether it is worth asking the customer again.
     */
    function replaceWithMissingMedia(bubble, node, label) {
        node.remove();

        // The caption, if there is one, stays: it is our own text and
        // still describes what was sent. This fires from a load error,
        // so it lands after the source tag was already prepended --
        // hence going in after that tag rather than at the very top.
        const note = missingMediaNote(label);
        const tag = bubble.querySelector('.bubble__tag');
        if (tag) {
            tag.after(note);
        } else {
            bubble.prepend(note);
        }

        bubble.classList.remove('bubble--media');
    }

    function missingMediaNote(label) {
        const note = document.createElement('div');
        note.className = 'bubble__unsupported';
        note.textContent = `${label} — no longer available.`;
        return note;
    }

    /**
     * The English one-liner under a voice note, and the full transcript
     * behind a toggle.
     *
     * The point is scanning: a thread with six voice notes in a language
     * an agent does not read is otherwise six things they have to press
     * play on. The summary is always English; the transcript is what was
     * actually said, in whatever language it was said, and stays folded
     * away because it is usually long and rambling.
     */
    function appendVoiceSummary(bubble, msg) {
        const summary = (msg.ai_caption || '').trim();
        const transcript = (msg.ai_transcript || '').trim();
        if (!summary && !transcript) return;

        const wrap = document.createElement('div');
        wrap.className = 'bubble__voice';

        if (summary) {
            const line = document.createElement('div');
            line.className = 'bubble__voice-summary';
            line.textContent = summary;
            wrap.appendChild(line);
        }

        // Only worth a toggle when it says more than the summary already
        // does — otherwise it is a control that reveals the same words.
        if (transcript && transcript !== summary) {
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'bubble__voice-toggle';
            toggle.textContent = 'Show transcript';

            const full = document.createElement('div');
            full.className = 'bubble__voice-transcript';
            full.textContent = transcript;
            full.hidden = true;

            toggle.addEventListener('click', () => {
                full.hidden = !full.hidden;
                toggle.textContent = full.hidden ? 'Show transcript' : 'Hide transcript';
            });

            wrap.append(toggle, full);
        }

        bubble.appendChild(wrap);
    }

    function appendCaption(bubble, caption) {
        if (!caption) return;
        const node = document.createElement('div');
        node.className = 'bubble__caption';
        renderWhatsAppText(node, caption);
        bubble.appendChild(node);
    }

    /**
     * Marks a reply that was typed on a phone rather than sent here.
     *
     * Only 'app' is labelled. A bubble with no tag is one this CRM sent,
     * which is the common case and does not need saying — tagging both
     * would be noise on every outbound message in every thread.
     */
    function appendSourceTag(bubble, msg) {
        if (msg.wa_source !== 'app') return;

        const tag = document.createElement('div');
        tag.className = 'bubble__tag';
        tag.textContent = 'Sent from the WhatsApp app';
        // Above the text, like the template tag, so it reads as a label
        // on the message rather than a footnote after it.
        bubble.prepend(tag);
    }

    function appendStatus(bubble, msg) {
        if (!msg.wa_status) return;
        const node = document.createElement('div');
        node.className = 'bubble__status';

        if (msg.wa_status === 'deleted') {
            // Deleted for everyone from the phone. The row stays: a
            // message that was in the thread and then withdrawn is a
            // fact about the conversation, and hiding it makes whatever
            // the customer said next unreadable.
            bubble.classList.add('is-deleted');
            node.classList.add('is-failed');
            node.textContent = 'Deleted';
        } else if (msg.wa_status === 'failed') {
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
     * WhatsApp's own emphasis, as the customer's phone renders it.
     *
     * Drafts are converted to WhatsApp's spelling on the way out of
     * api/draft.php — one asterisk for bold, not two. That fixed what the
     * customer receives, but left the asterisks visible HERE, so an agent
     * read `*AED 0.42*` where the customer saw bold. This is the other
     * half: the thread shows what was actually sent.
     *
     * `mono` is matched first and does not nest — inside a code span the
     * other characters are literal, which is what a code span is for.
     *
     * Written without lookbehind on purpose. Safari only gained it in
     * 16.4, and an unsupported group in a regex LITERAL is a parse error,
     * which would take the whole file down rather than just this feature.
     * Requiring a non-space at both edges does the same job: it is what
     * stops "2 * 3 * 4" from turning into italics.
     */
    const WA_MARKS = [
        { pattern: /```([\s\S]+?)```/, tag: 'code', nest: false },
        { pattern: /\*([^\s*][^*\n]*?[^\s*]|[^\s*])\*/, tag: 'strong', nest: true },
        { pattern: /_([^\s_][^_\n]*?[^\s_]|[^\s_])_/, tag: 'em', nest: true },
        { pattern: /~([^\s~][^~\n]*?[^\s~]|[^\s~])~/, tag: 'del', nest: true },
    ];

    function renderWhatsAppText(node, text) {
        // Whichever mark opens earliest wins, so the spans nest the way
        // they were written rather than in the order this list happens
        // to be in.
        let first = null;
        for (const mark of WA_MARKS) {
            const match = mark.pattern.exec(text);
            if (match && (first === null || match.index < first.match.index)) {
                first = { match, mark };
            }
        }

        if (first === null) {
            linkifyInto(node, text);
            return;
        }

        const { match, mark } = first;

        if (match.index > 0) {
            linkifyInto(node, text.slice(0, match.index));
        }

        // createElement + textContent throughout: none of this text is
        // ever parsed as markup, which is the same rule the rest of the
        // bubble builders follow.
        const span = document.createElement(mark.tag);
        if (mark.nest) {
            renderWhatsAppText(span, match[1]);
        } else {
            span.textContent = match[1];
        }
        node.appendChild(span);

        renderWhatsAppText(node, text.slice(match.index + match[0].length));
    }

    /**
     * The same text with the marks removed rather than applied.
     *
     * For the sidebar preview, which is one truncated line with no room
     * to render emphasis — but every reason not to show the punctuation
     * that would have produced it.
     */
    function stripWhatsAppMarks(text) {
        let out = String(text ?? '');
        let previous;
        do {
            previous = out;
            for (const mark of WA_MARKS) {
                out = out.replace(new RegExp(mark.pattern.source, 'g'), '$1');
            }
        } while (out !== previous);
        return out;
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

    /**
     * Send needs something to send, an open reply window, and nothing
     * already in flight. Draft only needs a conversation -- its whole
     * point is to fill an empty composer.
     */
    function syncSendButton() {
        const hasText = el.composerInput.value.trim() !== '';
        const hasAttachment = state.attachment !== null;
        const open = isWindowOpen();

        el.sendBtn.disabled = state.isSending || !open || (!hasText && !hasAttachment);
        el.generateBtn.disabled = state.isSending || !state.selectedSessionId;
        el.attachBtn.disabled = state.isSending || !state.selectedSessionId || !open;
    }

    el.composerInput.addEventListener('input', () => {
        autoGrowComposer();
        syncSendButton();
    });

    el.composerInput.addEventListener('keydown', (e) => {
        // Cmd/Ctrl+Enter sends. Plain Enter inserts a newline: a reply
        // written here is often several lines long.
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
            e.preventDefault();
            handleSend();
            return;
        }
        // Cmd/Ctrl+G asks for a draft.
        if (e.key.toLowerCase() === 'g' && (e.metaKey || e.ctrlKey)) {
            e.preventDefault();
            handleGenerateAnswer();
        }
    });

    el.generateBtn.addEventListener('click', handleGenerateAnswer);
    el.sendBtn.addEventListener('click', handleSend);

    /**
     * Asks n8n for a suggested reply and puts it in the composer.
     *
     * It does not post a bubble and does not refetch: nothing is written
     * anywhere until the agent reads the draft, edits it, and presses
     * Send.
     */
    async function handleGenerateAnswer() {
        if (state.isSending) return;
        if (!state.selectedSessionId) {
            toast('Select a customer first.', 'error');
            return;
        }

        const sessionId = state.selectedSessionId;

        state.isSending = true;
        syncSendButton();
        el.generateBtn.classList.add('is-loading');
        showTypingIndicator();

        try {
            const data = await api(API.draft, {
                method: 'POST',
                body: JSON.stringify({ session_id: sessionId }),
            });

            if (sessionId !== state.selectedSessionId) return;

            el.composerInput.value = data.draft || '';
            autoGrowComposer();
            el.composerInput.focus();
            // Cursor at the end, ready to edit.
            const end = el.composerInput.value.length;
            el.composerInput.setSelectionRange(end, end);
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            hideTypingIndicator();
            state.isSending = false;
            el.generateBtn.classList.remove('is-loading');
            syncSendButton();
        }
    }

    /**
     * Delivers what is in the composer over WhatsApp.
     */
    async function handleSend() {
        if (state.isSending) return;
        if (!state.selectedSessionId) {
            toast('Select a customer first.', 'error');
            return;
        }
        if (!isWindowOpen()) {
            toast('The 24-hour reply window has closed.', 'error');
            return;
        }

        const text = el.composerInput.value.trim();
        const attachment = state.attachment;

        if (!text && !attachment) {
            toast('Write a reply first.', 'error');
            return;
        }

        const sessionId = state.selectedSessionId;
        state.isSending = true;
        syncSendButton();
        el.sendBtn.classList.add('is-loading');

        // Clear optimistically so the agent can start the next reply.
        el.composerInput.value = '';
        autoGrowComposer();

        const pending = {
            id: 'pending',
            type: 'ai',
            direction: 'out',
            msg_type: attachment ? attachment.msgType : 'text',
            content: text,
            media_url: attachment ? attachment.previewUrl : null,
            media_name: attachment ? attachment.name : null,
            media_size: attachment ? attachment.size : null,
            pending: true,
        };
        appendMessages([pending]);

        try {
            const payload = { session_id: sessionId, type: 'text', text };
            if (attachment) {
                payload.type = attachment.msgType;
                payload.media_ref = attachment.ref;
            }

            const data = await api(API.send, { method: 'POST', body: JSON.stringify(payload) });

            replacePendingBubble(data.message);
            clearAttachment();
            refreshSidebarPreview(sessionId);
        } catch (err) {
            // Nothing was delivered, so take the bubble back down and
            // hand the text back for a retry or an edit.
            removePendingBubble();
            el.composerInput.value = text;
            autoGrowComposer();
            toast(err.message, 'error');
        } finally {
            state.isSending = false;
            el.sendBtn.classList.remove('is-loading');
            syncSendButton();
        }
    }

    /** Swaps the optimistic bubble for the real, stored row. */
    function replacePendingBubble(message) {
        const node = el.chatMessages.querySelector('.bubble-row[data-message-id="pending"]');
        state.messages = state.messages.filter((m) => m.id !== 'pending');

        if (!message) {
            node?.remove();
            return;
        }

        state.messages.push(message);
        trackMaxId([message]);

        const fresh = buildBubbleRow(message);
        if (node) {
            node.replaceWith(fresh);
        } else {
            el.chatMessages.appendChild(fresh);
        }
        scrollMessagesToBottom(true);
    }

    function removePendingBubble() {
        el.chatMessages.querySelector('.bubble-row[data-message-id="pending"]')?.remove();
        state.messages = state.messages.filter((m) => m.id !== 'pending');
    }

    // ------------------------------------------------------------------
    // Staged attachment
    // ------------------------------------------------------------------

    /**
     * The attach button opens a small menu: a file, or a location.
     * A location is not a file, so it cannot share the file picker.
     */
    el.attachBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (document.getElementById('attachMenu')) {
            closeAttachMenu();
            return;
        }
        openAttachMenu();
    });

    function openAttachMenu() {
        const menu = document.createElement('div');
        menu.className = 'attach-menu';
        menu.id = 'attachMenu';

        /** One row of the menu. `icon` is trusted markup from this file. */
        function addItem(icon, label, onClick, { note = '', disabled = false } = {}) {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'attach-menu__item';
            item.disabled = disabled;
            item.innerHTML = icon;

            const text = document.createElement('span');
            text.textContent = label;
            item.appendChild(text);

            if (note) {
                const hint = document.createElement('span');
                hint.className = 'attach-menu__note';
                hint.textContent = note;
                item.appendChild(hint);
            }

            item.addEventListener('click', () => {
                closeAttachMenu();
                onClick();
            });
            menu.appendChild(item);
            return item;
        }

        addItem(paperclipIconSvg(), 'Photo, video or file', () => el.attachInput.click());

        // The catalog is one file for the whole business, uploaded on the
        // settings page. Offered as its own row rather than left to the
        // file picker so sending it is one tap and always the current
        // version — the point of the feature.
        const hasCatalog = state.catalog?.available === true;
        addItem(
            docIconSvg(),
            'Send catalog',
            () => (hasCatalog ? sendCatalog() : toast('Upload a catalog on the settings page first.', 'error')),
            {
                note: hasCatalog ? state.catalog.name : 'None uploaded yet',
                disabled: !hasCatalog,
            },
        );

        addItem(questionIconSvg(), 'Ask a question', openQuestionDialog);
        addItem(pinIconSvg(), 'Send location', openLocationDialog);

        document.body.appendChild(menu);

        // Anchored above the button, clamped to the viewport.
        const rect = el.attachBtn.getBoundingClientRect();
        menu.style.left = `${Math.max(8, rect.left)}px`;
        menu.style.top = `${Math.max(8, rect.top - menu.offsetHeight - 8)}px`;

        setTimeout(() => document.addEventListener('click', closeAttachMenu, { once: true }), 0);
    }

    function closeAttachMenu() {
        document.getElementById('attachMenu')?.remove();
    }

    el.attachInput.addEventListener('change', async () => {
        const file = el.attachInput.files?.[0];
        // Reset immediately so picking the same file twice still fires.
        el.attachInput.value = '';
        if (!file) return;
        await stageAttachment(file);
    });

    /**
     * Uploads a file to api/upload.php and shows it as a chip.
     *
     * The file only reaches WhatsApp when Send is pressed, so an agent
     * who picks the wrong one can just remove the chip.
     */
    async function stageAttachment(file) {
        clearAttachment();

        el.attachBtn.disabled = true;
        el.attachChip.hidden = false;
        el.attachChipName.textContent = file.name;
        el.attachChipMeta.textContent = 'Uploading…';

        try {
            const body = new FormData();
            body.append('file', file);
            // Let the browser set the multipart boundary; api() would
            // otherwise force application/json.
            const res = await fetch(API.upload, { method: 'POST', body });
            const data = await res.json();
            if (!res.ok || data.success === false) {
                throw new Error(data.error || 'That file could not be attached.');
            }

            const previewUrl = data.msg_type === 'image' ? URL.createObjectURL(file) : null;

            state.attachment = {
                ref: data.media_ref,
                name: data.name,
                mime: data.mime,
                size: data.size,
                msgType: data.msg_type,
                previewUrl,
            };

            el.attachChipName.textContent = data.name;
            el.attachChipMeta.textContent = `${data.msg_type} · ${formatBytes(data.size)}`;
            el.attachChipIcon.innerHTML = '';
            if (previewUrl) {
                const thumb = document.createElement('img');
                thumb.src = previewUrl;
                thumb.alt = '';
                el.attachChipIcon.appendChild(thumb);
            } else {
                el.attachChipIcon.innerHTML = docIconSvg();
            }
        } catch (err) {
            clearAttachment();
            toast(err.message, 'error');
        } finally {
            syncSendButton();
        }
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** Reads what is uploaded, so the attach menu can name it. */
    async function loadCatalog() {
        try {
            const data = await api(API.catalog);
            state.catalog = data.catalog;
        } catch {
            // Not worth a toast on page load. The menu row simply says
            // there is none, which is also what an agent should do about it.
            state.catalog = null;
        }
    }

    /**
     * Sends the configured catalog in one call.
     *
     * Nothing about WHICH file is sent comes from here — api/send.php
     * reads the setting itself — so this cannot be used to send some
     * other file off the server.
     */
    async function sendCatalog() {
        if (state.isSending || !state.selectedSessionId) return;
        if (!isWindowOpen()) {
            toast('The 24-hour reply window has closed.', 'error');
            return;
        }

        const sessionId = state.selectedSessionId;
        const caption = el.composerInput.value.trim();

        state.isSending = true;
        syncSendButton();

        try {
            const data = await api(API.send, {
                method: 'POST',
                body: JSON.stringify({ session_id: sessionId, type: 'catalog', text: caption }),
            });

            if (caption) {
                el.composerInput.value = '';
                autoGrowComposer();
            }
            appendMessages([data.message]);
            refreshSidebarPreview(sessionId);
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            state.isSending = false;
            syncSendButton();
        }
    }

    // ------------------------------------------------------------------
    // Ask a question
    //
    // An answer the customer taps is an answer the CRM can read. A typed
    // "yeah the small one I think" is a sentence somebody has to
    // interpret, and half the time it arrives three hours later.
    // ------------------------------------------------------------------

    /** WhatsApp's own limits on an interactive button message. */
    const MAX_BUTTONS = 3;
    const MAX_BUTTON_LABEL = 20;

    function openQuestionDialog() {
        if (!state.selectedSessionId) {
            toast('Select a customer first.', 'error');
            return;
        }
        if (!isWindowOpen()) {
            toast('The 24-hour reply window has closed.', 'error');
            return;
        }

        const overlay = document.createElement('div');
        overlay.className = 'location-dialog';
        overlay.id = 'questionDialog';
        overlay.innerHTML = `
            <form class="location-dialog__card" id="questionForm">
                <h3>Ask a question</h3>
                <p class="dialog__note">
                    The customer answers by tapping. Their choice comes back into this
                    conversation as a normal message.
                </p>
                <div class="field">
                    <label for="qBody">Question</label>
                    <textarea id="qBody" rows="3" maxlength="1024" placeholder="Which size would you like?" required></textarea>
                </div>
                <div class="field">
                    <label>Answers</label>
                    <div class="question-options" id="qOptions"></div>
                    <p class="field__help">
                        Up to ${MAX_BUTTONS}, ${MAX_BUTTON_LABEL} characters each — WhatsApp's limit, not ours.
                    </p>
                </div>
                <div class="location-dialog__actions">
                    <button type="button" class="btn btn--ghost" id="qCancel">Cancel</button>
                    <button type="submit" class="btn btn--primary" id="qSend">Send question</button>
                </div>
            </form>
        `;

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeQuestionDialog();
        });
        document.body.appendChild(overlay);

        const options = document.getElementById('qOptions');
        for (let i = 0; i < MAX_BUTTONS; i += 1) {
            const input = document.createElement('input');
            input.type = 'text';
            input.maxLength = MAX_BUTTON_LABEL;
            input.className = 'question-options__input';
            input.placeholder = i === 0 ? 'Yes' : (i === 1 ? 'No' : 'Optional');
            options.appendChild(input);
        }

        // Anything already typed in the composer is almost certainly the
        // question, so start from it rather than making them retype it.
        document.getElementById('qBody').value = el.composerInput.value.trim();

        document.getElementById('qCancel').addEventListener('click', closeQuestionDialog);
        document.getElementById('questionForm').addEventListener('submit', submitQuestion);
        document.getElementById('qBody').focus();
    }

    function closeQuestionDialog() {
        document.getElementById('questionDialog')?.remove();
    }

    async function submitQuestion(e) {
        e.preventDefault();

        const body = document.getElementById('qBody').value.trim();
        const buttons = [...document.querySelectorAll('.question-options__input')]
            .map((input) => input.value.trim())
            .filter(Boolean);

        if (!body) {
            toast('Write the question first.', 'error');
            return;
        }
        if (buttons.length === 0) {
            toast('Add at least one answer button.', 'error');
            return;
        }

        const sessionId = state.selectedSessionId;
        const btn = document.getElementById('qSend');
        btn.disabled = true;
        btn.textContent = 'Sending…';

        try {
            const data = await api(API.send, {
                method: 'POST',
                body: JSON.stringify({ session_id: sessionId, type: 'buttons', text: body, buttons }),
            });

            closeQuestionDialog();
            // The composer seeded this, so clear whatever it still holds.
            el.composerInput.value = '';
            autoGrowComposer();
            syncSendButton();

            appendMessages([data.message]);
            refreshSidebarPreview(sessionId);
        } catch (err) {
            btn.disabled = false;
            btn.textContent = 'Send question';
            toast(err.message, 'error');
        }
    }

    // ------------------------------------------------------------------
    // Templates
    //
    // The only thing WhatsApp still delivers once the 24-hour window has
    // closed. Without this a conversation that goes quiet overnight can
    // never be restarted from the CRM.
    // ------------------------------------------------------------------

    async function openTemplateDialog() {
        if (!state.selectedSessionId) {
            toast('Select a customer first.', 'error');
            return;
        }

        const overlay = document.createElement('div');
        overlay.className = 'location-dialog';
        overlay.id = 'templateDialog';
        overlay.innerHTML = `
            <form class="location-dialog__card location-dialog__card--wide" id="templateForm">
                <h3>Send a template</h3>
                <p class="dialog__note">
                    Approved in advance with Meta, which is why WhatsApp still carries it
                    outside the 24-hour window. Sending one reopens the window.
                </p>
                <div class="field">
                    <label for="tplPick">Template</label>
                    <select id="tplPick" required>
                        <option value="">Loading…</option>
                    </select>
                </div>
                <div id="tplParams"></div>
                <div class="template-preview" id="tplPreview" hidden></div>
                <div class="location-dialog__actions">
                    <button type="button" class="btn btn--ghost" id="tplCancel">Cancel</button>
                    <button type="submit" class="btn btn--primary" id="tplSend" disabled>Send template</button>
                </div>
            </form>
        `;

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeTemplateDialog();
        });
        document.body.appendChild(overlay);
        document.getElementById('tplCancel').addEventListener('click', closeTemplateDialog);
        document.getElementById('templateForm').addEventListener('submit', submitTemplate);

        const picker = document.getElementById('tplPick');

        let templates = [];
        try {
            const data = await api(API.templates);
            templates = data.templates || [];
        } catch (err) {
            picker.innerHTML = '';
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'Could not load templates';
            picker.appendChild(option);
            toast(err.message, 'error');
            return;
        }

        // The dialog may have been dismissed while the request was out.
        if (!document.getElementById('templateDialog')) return;

        picker.innerHTML = '';
        if (templates.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'This number has no templates yet';
            picker.appendChild(option);
            return;
        }

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Choose a template…';
        picker.appendChild(placeholder);

        templates.forEach((template, index) => {
            const option = document.createElement('option');
            option.value = String(index);
            option.dataset.name = template.name;
            option.dataset.language = template.language;
            // A template that cannot be sent is still listed, greyed out
            // and saying why: "it isn't in the list" is a far worse
            // answer than "it is still pending approval".
            option.textContent = template.sendable
                ? `${template.name} (${template.language})`
                : `${template.name} — ${template.reason}`;
            option.disabled = !template.sendable;
            picker.appendChild(option);
        });

        picker.addEventListener('change', () => {
            const template = templates[Number(picker.value)];
            renderTemplateParams(template);
        });
    }

    /** Draws one input per {{n}} the chosen template has, plus a preview. */
    function renderTemplateParams(template) {
        const holder = document.getElementById('tplParams');
        const preview = document.getElementById('tplPreview');
        const send = document.getElementById('tplSend');
        holder.innerHTML = '';

        if (!template) {
            preview.hidden = true;
            send.disabled = true;
            return;
        }

        for (let i = 1; i <= template.placeholders; i += 1) {
            const field = document.createElement('div');
            field.className = 'field';

            const label = document.createElement('label');
            label.textContent = `Value {{${i}}}`;
            label.setAttribute('for', `tplParam${i}`);

            const input = document.createElement('input');
            input.type = 'text';
            input.id = `tplParam${i}`;
            input.className = 'template-param';
            input.required = true;
            input.addEventListener('input', () => paintTemplatePreview(template));

            field.append(label, input);
            holder.appendChild(field);
        }

        send.disabled = false;
        paintTemplatePreview(template);
    }

    /** Shows the template with the typed values filled in. */
    function paintTemplatePreview(template) {
        const preview = document.getElementById('tplPreview');
        preview.hidden = false;
        preview.textContent = renderTemplateBody(template, currentTemplateParams());
    }

    function currentTemplateParams() {
        return [...document.querySelectorAll('.template-param')].map((input) => input.value.trim());
    }

    /**
     * Substitutes values into a template body.
     *
     * Mirrors renderTemplateBody() in api/send.php, which is what
     * actually gets stored — this one only has to make the preview
     * honest about what will arrive.
     */
    function renderTemplateBody(template, params) {
        let body = template.body || '';
        params.forEach((value, index) => {
            const token = new RegExp(`\\{\\{\\s*${index + 1}\\s*\\}\\}`, 'g');
            body = body.replace(token, value || `{{${index + 1}}}`);
        });
        return [template.header, body, template.footer].filter(Boolean).join('\n\n');
    }

    function closeTemplateDialog() {
        document.getElementById('templateDialog')?.remove();
    }

    async function submitTemplate(e) {
        e.preventDefault();

        const picker = document.getElementById('tplPick');
        if (picker.value === '') {
            toast('Choose a template first.', 'error');
            return;
        }

        const preview = document.getElementById('tplPreview');
        const params = currentTemplateParams();
        if (params.some((value) => value === '')) {
            toast('Fill in every value the template asks for.', 'error');
            return;
        }

        const option = picker.options[picker.selectedIndex];
        const sessionId = state.selectedSessionId;
        const btn = document.getElementById('tplSend');

        btn.disabled = true;
        btn.textContent = 'Sending…';

        try {
            const data = await api(API.send, {
                method: 'POST',
                body: JSON.stringify({
                    session_id: sessionId,
                    type: 'template',
                    template: option.dataset.name,
                    language: option.dataset.language,
                    // Sent so the stored row reads as what the customer
                    // received rather than as "{{1}}".
                    body: preview.textContent,
                    params,
                }),
            });

            closeTemplateDialog();
            appendMessages([data.message]);
            refreshSidebarPreview(sessionId);
            toast('Template sent.');
        } catch (err) {
            btn.disabled = false;
            btn.textContent = 'Send template';
            toast(err.message, 'error');
        }
    }

    // ------------------------------------------------------------------
    // Send location
    // ------------------------------------------------------------------

    function openLocationDialog() {
        const overlay = document.createElement('div');
        overlay.className = 'location-dialog';
        overlay.id = 'locationDialog';
        overlay.innerHTML = `
            <form class="location-dialog__card" id="locationForm">
                <h3>Send a location</h3>
                <div class="location-dialog__row">
                    <div class="field">
                        <label for="locLat">Latitude</label>
                        <input type="text" id="locLat" inputmode="decimal" placeholder="41.38740" required />
                    </div>
                    <div class="field">
                        <label for="locLng">Longitude</label>
                        <input type="text" id="locLng" inputmode="decimal" placeholder="2.16860" required />
                    </div>
                </div>
                <div class="field">
                    <label for="locName">Label (optional)</label>
                    <input type="text" id="locName" placeholder="LiVAR warehouse" />
                </div>
                <div class="field">
                    <label for="locAddress">Address (optional)</label>
                    <input type="text" id="locAddress" placeholder="Carrer de Mallorca 1, Barcelona" />
                </div>
                <div class="location-dialog__actions">
                    <button type="button" class="btn btn--ghost" id="locCancel">Cancel</button>
                    <button type="submit" class="btn btn--primary" id="locSend">Send location</button>
                </div>
            </form>
        `;

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeLocationDialog();
        });
        document.body.appendChild(overlay);
        document.getElementById('locCancel').addEventListener('click', closeLocationDialog);
        document.getElementById('locationForm').addEventListener('submit', submitLocation);
        document.getElementById('locLat').focus();
    }

    function closeLocationDialog() {
        document.getElementById('locationDialog')?.remove();
    }

    async function submitLocation(e) {
        e.preventDefault();

        const lat = parseFloat(document.getElementById('locLat').value.replace(',', '.'));
        const lng = parseFloat(document.getElementById('locLng').value.replace(',', '.'));

        if (!Number.isFinite(lat) || !Number.isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
            toast('Those coordinates are not on the map.', 'error');
            return;
        }

        const placeName = document.getElementById('locName').value.trim();
        const placeAddress = document.getElementById('locAddress').value.trim();
        const sessionId = state.selectedSessionId;
        const btn = document.getElementById('locSend');

        btn.disabled = true;
        btn.textContent = 'Sending…';

        try {
            const data = await api(API.send, {
                method: 'POST',
                body: JSON.stringify({
                    session_id: sessionId,
                    type: 'location',
                    latitude: lat,
                    longitude: lng,
                    place_name: placeName,
                    place_address: placeAddress,
                }),
            });

            closeLocationDialog();
            appendMessages([data.message]);
            refreshSidebarPreview(sessionId);
        } catch (err) {
            btn.disabled = false;
            btn.textContent = 'Send location';
            toast(err.message, 'error');
        }
    }

    function questionIconSvg() {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 2.5-3 4"/><path d="M12 17h.01"/></svg>';
    }

    function paperclipIconSvg() {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>';
    }

    /** Drops the staged file and hides its chip. */
    function clearAttachment() {
        if (state.attachment?.previewUrl?.startsWith('blob:')) {
            URL.revokeObjectURL(state.attachment.previewUrl);
        }
        state.attachment = null;
        el.attachChip.hidden = true;
        el.attachChipIcon.innerHTML = '';
        el.attachChipName.textContent = '';
        el.attachChipMeta.textContent = '';
        syncSendButton();
    }

    el.attachChipRemove.addEventListener('click', clearAttachment);

    // ------------------------------------------------------------------
    // 24-hour reply window
    // ------------------------------------------------------------------

    /** Seconds of free-form reply time left, from the loaded customer. */
    function windowSecondsRemaining() {
        const at = state.selectedCustomer?.last_inbound_at;
        if (!at) return 0;
        const ts = new Date(at).getTime();
        if (Number.isNaN(ts)) return 0;
        return Math.max(0, Math.round((ts + WINDOW_HOURS * 3600000 - Date.now()) / 1000));
    }

    function isWindowOpen() {
        return windowSecondsRemaining() > 0;
    }

    /**
     * Repaints the header pill and the blocking notice.
     *
     * A customer with no wa_id at all is a hand-created record that was
     * never a WhatsApp conversation; there is nothing to say about a
     * window it does not have.
     */
    function refreshWindowNotice() {
        const customer = state.selectedCustomer;

        if (!customer || !customer.wa_id) {
            el.windowPill.hidden = true;
            el.windowNotice.hidden = true;
            syncSendButton();
            return;
        }

        const left = windowSecondsRemaining();

        if (left <= 0) {
            el.windowPill.hidden = false;
            el.windowPill.className = 'window-pill is-closed';
            el.windowPill.textContent = 'replies closed';

            el.windowNotice.hidden = false;
            el.windowNotice.innerHTML = '';
            const strong = document.createElement('strong');
            strong.textContent = 'The 24-hour reply window has closed. ';

            // The notice used to end here, at a dead end. An approved
            // template is the one thing WhatsApp still carries, so the
            // way out of the dead end belongs in the notice itself.
            const action = document.createElement('button');
            action.type = 'button';
            action.className = 'btn btn--primary btn--sm window-notice__action';
            action.textContent = 'Send a template';
            action.addEventListener('click', openTemplateDialog);

            el.windowNotice.append(
                strong,
                document.createTextNode(
                    'WhatsApp only allows a free-form reply within a day of the customer\'s last '
                    + 'message. Sending now needs a template that Meta approved in advance.'
                ),
                action,
            );
        } else {
            const hours = Math.floor(left / 3600);
            const mins = Math.floor((left % 3600) / 60);
            const label = hours > 0 ? `${hours}h left` : `${mins}m left`;

            el.windowPill.hidden = false;
            el.windowPill.className = hours < 2 ? 'window-pill is-closing' : 'window-pill';
            el.windowPill.textContent = `replies open · ${label}`;

            el.windowNotice.hidden = true;
        }

        syncSendButton();
    }

    // The window drains in real time, so re-check it on a slow tick as
    // well as on every poll -- an agent can sit on one conversation for
    // the last hour of it.
    setInterval(refreshWindowNotice, 60000);

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

    /**
     * Clears a conversation's unread badge, here and on the server.
     *
     * The local half matters as much as the request: the sidebar poll
     * runs every 25s and would otherwise paint the badge back on before
     * the server had been told, making it flicker.
     */
    async function markRead(sessionId) {
        const known = state.customers.find((c) => c.session_id === sessionId);
        if (known && Number(known.unread_count) > 0) {
            known.unread_count = 0;
            syncCustomerListDom();
        }

        try {
            await api(API.read, { method: 'POST', body: JSON.stringify({ session_id: sessionId }) });
        } catch {
            /* Not worth a toast. The next open tries again. */
        }
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

            // An inbound message reopens (or extends) the reply window,
            // so keep the header honest without a second request.
            const lastInbound = [...data.messages].reverse()
                .find((m) => m.direction === 'in' && m.created_at);
            if (lastInbound && state.selectedCustomer) {
                state.selectedCustomer.last_inbound_at = lastInbound.created_at;
                // It arrived in the conversation on screen, so it is not
                // something to catch up on later.
                markRead(sessionId);
            }
            refreshWindowNotice();
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

            syncCustomerListDom();
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

            syncCustomerListDom();
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
        'country', 'email', 'city', 'address', 'tax_id', 'details', 'label',
    ];

    function openDetailsPanel() {
        if (!state.selectedCustomer) return;
        FORM_FIELDS.forEach((field) => {
            const input = document.getElementById(`field_${field}`);
            if (input) input.value = state.selectedCustomer[field] || '';
        });
        document.getElementById('field_session_id').value = state.selectedCustomer.session_id;
        document.getElementById('field_session_id_display').textContent = state.selectedCustomer.session_id;

        paintDetailsPhoto();
        paintDetectedCountry();

        // Only meaningful for a real WhatsApp contact.
        const waName = state.selectedCustomer.wa_profile_name || '';
        document.getElementById('waNameField').hidden = waName === '';
        document.getElementById('field_wa_profile_name').textContent = waName || '—';

        // What the business has this number saved as on the phone.
        const contactName = state.selectedCustomer.wa_contact_name || '';
        document.getElementById('waContactField').hidden = contactName === '';
        document.getElementById('field_wa_contact_name').textContent = contactName || '—';

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

    // ------------------------------------------------------------------
    // Profile photo
    //
    // WhatsApp does not hand out a contact's own picture, so this is one
    // an agent sets. With none, the avatar falls back to initials
    // everywhere, exactly as it did before.
    // ------------------------------------------------------------------

    function paintDetailsPhoto() {
        paintAvatar(el.detailsAvatar, state.selectedCustomer);
        el.avatarRemoveBtn.hidden = !state.selectedCustomer?.avatar_url;
        el.avatarPickBtn.textContent = state.selectedCustomer?.avatar_url ? 'Change photo' : 'Add photo';
    }

    el.avatarPickBtn.addEventListener('click', () => el.avatarInput.click());

    el.avatarInput.addEventListener('change', async () => {
        const file = el.avatarInput.files?.[0];
        // Reset immediately so picking the same file twice still fires.
        el.avatarInput.value = '';
        if (!file || !state.selectedCustomer) return;

        const body = new FormData();
        body.append('file', file);
        body.append('session_id', state.selectedCustomer.session_id);

        setAvatarBusy(true, 'Uploading…');

        try {
            // Not through api(): that forces application/json, and the
            // browser has to set the multipart boundary itself.
            const res = await fetch(API.avatar, { method: 'POST', body });
            const data = await res.json();
            if (!res.ok || data.success === false) {
                throw new Error(data.error || 'That photo could not be saved.');
            }
            applySavedCustomer(data.customer);
            toast('Profile photo updated.');
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            setAvatarBusy(false);
        }
    });

    el.avatarRemoveBtn.addEventListener('click', async () => {
        if (!state.selectedCustomer) return;

        setAvatarBusy(true, 'Removing…');

        try {
            const params = new URLSearchParams({ session_id: state.selectedCustomer.session_id });
            const data = await api(`${API.avatar}?${params.toString()}`, { method: 'DELETE' });
            applySavedCustomer(data.customer);
            toast('Profile photo removed.');
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            setAvatarBusy(false);
        }
    });

    function setAvatarBusy(busy, note = '') {
        el.avatarPickBtn.disabled = busy;
        el.avatarRemoveBtn.disabled = busy;
        el.avatarHint.textContent = busy ? note : 'JPEG, PNG, WebP or GIF, up to 5 MB.';
    }

    /** Applies a saved customer everywhere it is on screen. */
    function applySavedCustomer(customer) {
        if (!customer) return;
        state.selectedCustomer = customer;
        applyCustomerToHeader(customer);
        updateCustomerItemInList(customer);
        paintDetailsPhoto();
        paintDetectedCountry();
    }

    /**
     * Says what country the number points at, beside the country field.
     *
     * Only ever a note. The field stays editable and always wins: a
     * number can be a roaming SIM or a virtual line, and a prefix table
     * must not overwrite what an agent actually knows.
     */
    function paintDetectedCountry() {
        const customer = state.selectedCustomer;
        const name = customer?.country_name;

        if (!name) {
            el.countryDetected.hidden = true;
            return;
        }

        el.countryDetected.hidden = false;
        el.countryDetected.textContent = '';

        const flag = buildFlag(customer);
        if (flag) el.countryDetected.appendChild(flag);

        el.countryDetected.appendChild(
            document.createTextNode(`From the number: ${name}`),
        );

        const field = document.getElementById('field_country');
        if (field.value.trim() === '') {
            const use = document.createElement('button');
            use.type = 'button';
            use.className = 'btn-link';
            use.textContent = 'Use';
            use.addEventListener('click', () => {
                field.value = name;
                paintDetectedCountry();
                field.focus();
            });
            el.countryDetected.appendChild(use);
        }
    }

    // Copies the WhatsApp name into the editable fields, so an agent can
    // make it the customer's real name in one click instead of retyping
    // a name that is already on screen.
    /** Splits a one-line name across the first/last fields. */
    function adoptAsName(name) {
        if (!name) return;
        const parts = name.trim().split(/\s+/);
        document.getElementById('field_first_name').value = parts.shift() || '';
        document.getElementById('field_last_name').value = parts.join(' ');
        document.getElementById('field_first_name').focus();
    }

    document.getElementById('useWaNameBtn').addEventListener('click', () => {
        adoptAsName(state.selectedCustomer?.wa_profile_name || '');
    });

    document.getElementById('useContactNameBtn').addEventListener('click', () => {
        adoptAsName(state.selectedCustomer?.wa_contact_name || '');
    });

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

            applySavedCustomer(data.customer);

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
            if (document.getElementById('locationDialog')) {
                closeLocationDialog();
            } else if (document.getElementById('questionDialog')) {
                closeQuestionDialog();
            } else if (document.getElementById('templateDialog')) {
                closeTemplateDialog();
            } else if (document.getElementById('attachMenu')) {
                closeAttachMenu();
            } else if (document.getElementById('lightbox')) {
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
    loadCatalog();
    startPolling();
})();
