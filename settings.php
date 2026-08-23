<?php
/**
 * settings.php — connection health.
 *
 * Read-only. Nothing here changes a setting; config/config.php stays the
 * single place anything is edited. This page exists to answer one
 * question quickly: when the CRM misbehaves, which of Supabase,
 * 360dialog or n8n is actually at fault?
 *
 * Each row is fetched from api/health.php separately so a dead service
 * cannot hold up the others.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

require_auth(false);

/**
 * Same cache-busting helper index.php uses.
 */
function asset(string $relativePath): string
{
    $full = __DIR__ . '/' . ltrim($relativePath, '/');
    $version = is_file($full) ? (string) filemtime($full) : '1';
    return $relativePath . '?v=' . $version;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<title>Connection health — LiVAR Packaging CRM</title>
<meta name="theme-color" content="#FFFFFF" />
<meta name="robots" content="noindex, nofollow" />

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>" />
</head>
<body>

<div class="settings">
    <header class="settings__header">
        <a class="btn btn--icon btn--ghost" href="index.php" aria-label="Back to the inbox">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
        </a>
        <div class="settings__title">
            <h1>Connection health</h1>
            <p>Supabase, 360dialog and n8n, checked live.</p>
        </div>
        <button class="btn btn--primary" id="recheckBtn">Re-check</button>
    </header>

    <main class="settings__body">
        <div class="health-summary" id="healthSummary" hidden></div>

        <ul class="health-list" id="healthList"></ul>

        <section class="settings__section">
            <h2>Deeper tests</h2>
            <p class="settings__note">
                These make real calls, so they are not part of the checks above.
            </p>
            <div class="settings__actions">
                <button class="btn btn--ghost settings__action" id="n8nLiveBtn">
                    Run a live draft test
                    <span class="settings__action-note">Runs the AI agent once — costs a model call</span>
                </button>
            </div>
            <div class="health-item health-item--standalone" id="n8nLiveResult" hidden></div>
        </section>

        <p class="settings__footer">
            Settings are edited in <code>config/config.php</code>, never here.
            Detailed errors are in the PHP error log on the
            <code>[Supabase]</code> and <code>[WhatsApp]</code> lines.
            &nbsp;·&nbsp; <a href="login.php?logout=1">Sign out</a>
        </p>
    </main>
</div>

<div class="toast-stack" id="toastStack" aria-live="polite"></div>

<script src="<?= asset('assets/js/settings.js') ?>"></script>
</body>
</html>
