<?php
/**
 * settings.php — AI configuration and connection health.
 *
 * Two jobs. It edits the AI system prompt and model, which live in the
 * database precisely so they can be changed here rather than by editing
 * a file on the server. And it answers the question that used to take a
 * log dive: when the CRM misbehaves, which of Supabase, 360dialog or
 * OpenAI is actually at fault?
 *
 * API keys are NOT editable here. They stay in config/config.php, which
 * the app never writes to.
 *
 * Each health row is fetched from api/health.php separately so a dead
 * service cannot hold up the others.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/version.php';

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
<title>Settings — LiVAR Packaging CRM</title>
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
            <h1>Settings</h1>
            <p>AI replies, and the health of Supabase, 360dialog and OpenAI.</p>
        </div>
        <button class="btn btn--primary" id="recheckBtn">Re-check</button>
    </header>

    <main class="settings__body">

        <section class="settings__section settings__section--first">
            <h2>AI replies</h2>
            <p class="settings__note">
                What the <strong>Draft</strong> button sends to OpenAI. Saved to the
                database, so it takes effect immediately — no file to edit, no restart.
            </p>

            <form class="ai-form" id="aiForm">
                <div class="field">
                    <label for="aiModel">Model</label>
                    <input type="text" id="aiModel" list="aiModelList" autocomplete="off" spellcheck="false" />
                    <datalist id="aiModelList"></datalist>
                    <p class="field__help" id="aiModelHelp">
                        Loaded from your OpenAI account. Any model id is accepted —
                        pick one that can read images, or photos will be ignored.
                    </p>
                </div>

                <div class="field">
                    <label for="aiPrompt">System prompt</label>
                    <textarea id="aiPrompt" rows="14" spellcheck="false"></textarea>
                    <p class="field__help">
                        Save an empty box to go back to the built-in prompt.
                    </p>
                </div>

                <div class="ai-form__actions">
                    <button type="button" class="btn btn--ghost" id="aiResetBtn">Restore default</button>
                    <button type="submit" class="btn btn--primary" id="aiSaveBtn">Save</button>
                </div>
            </form>
        </section>

        <section class="settings__section">
            <h2>Connection health</h2>
            <p class="settings__note">Checked live, each one independently.</p>
        </section>

        <div class="health-summary" id="healthSummary" hidden></div>

        <ul class="health-list" id="healthList"></ul>

        <section class="settings__section">
            <h2>Deeper tests</h2>
            <p class="settings__note">
                These make real calls, so they are not part of the checks above.
            </p>
            <div class="settings__actions">
                <button class="btn btn--ghost settings__action" id="aiLiveBtn">
                    Run a live draft test
                    <span class="settings__action-note">Calls OpenAI once — costs a model call</span>
                </button>
            </div>
            <div class="health-item health-item--standalone" id="aiLiveResult" hidden></div>
        </section>

        <p class="settings__footer">
            API keys live in <code>config/config.php</code> and are never editable here.
            Detailed errors are in the PHP error log on the
            <code>[Supabase]</code>, <code>[WhatsApp]</code> and <code>[AI]</code> lines.
            &nbsp;·&nbsp; <a href="login.php?logout=1">Sign out</a>
        </p>

        <!--
            The build actually running. The commit is read from .git at
            request time, so this is the one place that can confirm a
            deploy landed without opening a shell on the server.
        -->
        <p class="settings__version" title="Version, deployed commit and branch">
            LiVAR Packaging CRM
            <span class="settings__version-value"><?= htmlspecialchars(app_version_label(), ENT_QUOTES, 'UTF-8') ?></span>
        </p>
    </main>
</div>

<div class="toast-stack" id="toastStack" aria-live="polite"></div>

<script src="<?= asset('assets/js/settings.js') ?>"></script>
</body>
</html>
