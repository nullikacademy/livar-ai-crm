<!-- components/sidebar.php -->
<aside class="sidebar" id="sidebar">
    <header class="sidebar__header">
        <div class="sidebar__title">
            <span class="sidebar__logo">LiVAR</span>
            <span class="sidebar__logo-sub">Packaging CRM</span>
        </div>
        <a class="btn btn--icon btn--ghost" href="settings.php" id="settingsLink" title="Settings and connection health">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        </a>

        <button class="btn btn--icon btn--primary" id="newChatBtn" title="New chat (N)">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            <span class="btn__label">New Chat</span>
        </button>
    </header>

    <div class="sidebar__search">
        <svg class="sidebar__search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
        <input type="text" id="searchInput" placeholder="Search name, phone, email…" autocomplete="off" />
        <button class="sidebar__search-clear" id="searchClear" hidden aria-label="Clear search">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>
    </div>

    <div class="customer-list" id="customerList" role="listbox" aria-label="Customers">
        <!-- Skeleton loading state, replaced by JS on first load -->
        <div class="skeleton-list" id="customerSkeleton">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <div class="skeleton-item">
                <div class="skeleton skeleton--avatar"></div>
                <div class="skeleton-item__body">
                    <div class="skeleton skeleton--line" style="width: 55%"></div>
                    <div class="skeleton skeleton--line" style="width: 80%"></div>
                </div>
            </div>
            <?php endfor; ?>
        </div>
    </div>

    <div class="customer-list__empty" id="customerListEmpty" hidden>
        <p>No customers match that search.</p>
    </div>
</aside>
