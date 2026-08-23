<?php declare(strict_types=1); ?>
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

            <span class="chat__window-status" id="chatWindowStatus" hidden></span>

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

        <div class="composer-shell">
            <div class="window-notice" id="windowNotice" role="status" hidden></div>

            <div class="attachment-preview" id="attachmentPreview" hidden>
                <div class="attachment-preview__thumb" id="attachmentThumb"></div>
                <div class="attachment-preview__body">
                    <strong id="attachmentName">Attachment</strong>
                    <span id="attachmentMeta"></span>
                </div>
                <button class="btn btn--icon btn--ghost" id="removeAttachmentBtn" type="button" aria-label="Remove attachment">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="location-form" id="locationForm" hidden>
                <div class="location-form__grid">
                    <div class="field">
                        <label for="locationLatitude">Latitude</label>
                        <input id="locationLatitude" type="number" min="-90" max="90" step="any" inputmode="decimal" />
                    </div>
                    <div class="field">
                        <label for="locationLongitude">Longitude</label>
                        <input id="locationLongitude" type="number" min="-180" max="180" step="any" inputmode="decimal" />
                    </div>
                </div>
                <div class="field">
                    <label for="locationName">Place label</label>
                    <input id="locationName" type="text" maxlength="120" placeholder="Warehouse, office…" />
                </div>
                <div class="field">
                    <label for="locationAddress">Address</label>
                    <input id="locationAddress" type="text" maxlength="240" />
                </div>
                <div class="location-form__actions">
                    <button class="btn btn--ghost" id="cancelLocationBtn" type="button">Cancel</button>
                    <button class="btn btn--primary" id="applyLocationBtn" type="button">Attach location</button>
                </div>
            </div>

            <div class="attach-menu" id="attachMenu" hidden>
                <button type="button" id="chooseFileBtn">
                    <span aria-hidden="true">📎</span>
                    <span><strong>File</strong><small>Image, video, or document</small></span>
                </button>
                <button type="button" id="chooseLocationBtn">
                    <span aria-hidden="true">📍</span>
                    <span><strong>Send location</strong><small>Latitude, longitude, and label</small></span>
                </button>
            </div>

            <footer class="composer">
                <button class="btn btn--icon btn--ghost composer__attach" id="attachBtn" type="button" aria-label="Attach" title="Attach">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.4 11.6l-8.9 8.9a6 6 0 01-8.5-8.5l9.6-9.6a4 4 0 015.7 5.7l-9.6 9.6a2 2 0 01-2.8-2.8l8.9-8.9"/></svg>
                </button>
                <input
                    id="attachInput"
                    type="file"
                    accept="image/jpeg,image/png,video/mp4,video/3gpp,application/pdf,text/plain,text/csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip"
                    hidden
                />
                <textarea
                    id="composerInput"
                    class="composer__input"
                    placeholder="Write your reply…"
                    rows="1"
                    maxlength="4000"
                ></textarea>
                <button class="btn btn--secondary composer__draft" id="generateBtn" type="button" title="Draft (⌘/Ctrl + G)">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.3 4.2L17.5 8.5l-4.2 1.3L12 14l-1.3-4.2-4.2-1.3 4.2-1.3L12 3z"/><path d="M19 14l.7 2.3L22 17l-2.3.7L19 20l-.7-2.3L16 17l2.3-.7L19 14z"/></svg>
                    <span>Draft</span>
                </button>
                <button class="btn btn--primary composer__send" id="sendBtn" type="button" title="Send (⌘/Ctrl + Enter)">
                    <span class="composer__send-label">Send</span>
                    <svg class="composer__send-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                </button>
            </footer>
        </div>
    </div>

    <div class="lightbox" id="lightbox" hidden role="dialog" aria-modal="true" aria-label="Image preview">
        <button class="lightbox__close" id="lightboxClose" type="button" aria-label="Close image preview">×</button>
        <img id="lightboxImage" alt="Message attachment preview" />
    </div>
</main>
