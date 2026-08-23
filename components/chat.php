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

            <button class="btn btn--icon btn--ghost" id="chatDetailsBtn" title="Customer details (I)">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 16v-5M12 8h.01"/></svg>
            </button>
            <span class="window-status" id="windowStatus"></span>
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

        <div class="window-notice" id="windowNotice" hidden></div>
        <div class="attachment-chip" id="attachmentChip" hidden></div>
        <footer class="composer">
            <button class="btn btn--icon btn--ghost" id="attachBtn" title="Attach file">＋</button>
            <button class="btn btn--icon btn--ghost" id="locationBtn" title="Send location">⌖</button>
            <input type="file" id="attachInput" hidden accept="image/jpeg,image/png,image/webp,video/mp4,application/pdf,text/plain">
            <textarea
                id="composerInput"
                class="composer__input"
                placeholder="Write your reply…"
                rows="1"
                maxlength="4000"
            ></textarea>
            <button class="btn btn--secondary" id="generateBtn" title="Draft (⌘/Ctrl + G)">✨ Draft</button>
            <button class="btn btn--primary composer__send" id="sendBtn" title="Send (⌘/Ctrl + Enter)"><span class="composer__send-label">Send</span><svg class="composer__send-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg></button>
        </footer>
    </div>
</main>

