<!-- components/chat.php -->
<main class="chat" id="chat">

    <!-- Shown when no customer is selected yet (desktop) -->
    <div class="chat__placeholder" id="chatPlaceholder">
        <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <h2>Select a conversation</h2>
        <p>Choose a customer from the list to view their chat history.</p>
    </div>

    <!-- Active conversation view -->
    <div class="chat__conversation" id="chatConversation" hidden>

        <header class="chat__header">
            <button class="btn btn--icon btn--ghost chat__back" id="chatBackBtn" aria-label="Back to list">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
            </button>

            <button class="chat__customer" id="chatCustomerBtn" title="Edit customer details">
                <span class="avatar" id="chatAvatar">?</span>
                <span class="chat__customer-info">
                    <span class="chat__customer-name" id="chatCustomerName">—</span>
                    <span class="chat__customer-phone" id="chatCustomerPhone">—</span>
                </span>
            </button>

            <!-- How much of WhatsApp's 24h free-form reply window is left -->
            <span class="window-pill" id="windowPill" hidden></span>

            <button class="btn btn--icon btn--ghost" id="chatDetailsBtn" title="Customer details (I)">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 16v-5M12 8h.01"/></svg>
            </button>
        </header>

        <div class="chat__messages" id="chatMessages">
            <!-- Message bubbles injected by JS; skeleton shown while loading -->
            <div class="skeleton-messages" id="messagesSkeleton" hidden>
                <div class="skeleton skeleton--bubble skeleton--bubble-left"></div>
                <div class="skeleton skeleton--bubble skeleton--bubble-right"></div>
                <div class="skeleton skeleton--bubble skeleton--bubble-left"></div>
            </div>
        </div>

        <div class="chat__scroll-jump" id="scrollJumpBtn" hidden>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M6 13l6 6 6-6"/></svg>
        </div>

        <!-- Shown when the 24h window has closed; disables sending. -->
        <div class="window-notice" id="windowNotice" hidden></div>

        <!-- Staged attachment, above the composer -->
        <div class="attach-chip" id="attachChip" hidden>
            <span class="attach-chip__icon" id="attachChipIcon"></span>
            <span class="attach-chip__body">
                <span class="attach-chip__name" id="attachChipName"></span>
                <span class="attach-chip__meta" id="attachChipMeta"></span>
            </span>
            <button type="button" class="attach-chip__remove" id="attachChipRemove" aria-label="Remove attachment">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <!--
            An optional brief for the AI, written before pressing Draft.
            Its own row rather than another button in the composer: that
            row already holds four controls and is the tightest part of
            the phone layout.
        -->
        <div class="draft-guide">
            <button type="button" class="draft-guide__toggle" id="draftGuideToggle"
                    aria-expanded="false" aria-controls="draftGuidePanel">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                <span id="draftGuideToggleLabel">Guide the draft</span>
                <span class="draft-guide__dot" id="draftGuideDot" hidden></span>
            </button>

            <div class="draft-guide__panel" id="draftGuidePanel" hidden>
                <input
                    type="text"
                    id="draftGuideInput"
                    class="draft-guide__input"
                    maxlength="600"
                    placeholder="What should this reply say? e.g. quote AED 0.42/unit, 10% off above 1,000"
                    aria-label="What this reply should say"
                />
                <button type="button" class="draft-guide__clear" id="draftGuideClear" aria-label="Clear the brief">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <footer class="composer">
            <button class="btn btn--icon btn--ghost composer__attach" id="attachBtn" title="Attach a file or location">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
            </button>
            <input type="file" id="attachInput" hidden />

            <textarea
                id="composerInput"
                class="composer__input"
                placeholder="Write your reply…"
                rows="1"
                maxlength="4000"
            ></textarea>

            <button class="btn btn--ghost composer__draft" id="generateBtn" title="Draft a reply with AI (⌘/Ctrl + G)">
                <svg class="composer__draft-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 4V2M15 10V8M12.5 6h-2M19.5 6h-2M9 20l10-10-3-3L6 17z"/><path d="M5 5l.7 2L8 7.7 5.7 8.4 5 11l-.7-2.6L2 7.7 4.3 7z"/></svg>
                <span class="composer__draft-label">Draft</span>
            </button>

            <button class="btn btn--primary composer__send" id="sendBtn" title="Send on WhatsApp (⌘/Ctrl + Enter)">
                <span class="composer__send-label">Send</span>
                <svg class="composer__send-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
            </button>
        </footer>
    </div>
</main>
