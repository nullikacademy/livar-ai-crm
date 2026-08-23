<!-- components/sidebar.php -->
<aside class="sidebar" id="sidebar">
    <header class="sidebar__header">
        <div class="sidebar__title">
            <span class="sidebar__logo">LiVAR</span>
            <span class="sidebar__logo-sub">Packaging CRM</span>
        </div>
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
