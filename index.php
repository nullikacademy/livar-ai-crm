<?php
/**
 * index.php — application shell.
 */
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

// Page load, not an API call: unauthenticated visitors get redirected to
// the login form rather than a JSON 401.
require_auth(false);

/**
 * Appends the file's last-modified time to an asset URL so browsers pick up
 * changes immediately after an upload instead of serving a stale cached copy.
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
<title>LiVAR Packaging — CRM</title>
<meta name="theme-color" content="#FFFFFF" />
<meta name="description" content="Customer support and sales CRM for LiVAR Packaging Solutions." />

<!-- Installable / fullscreen on mobile home screens -->
<meta name="mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="default" />

<!--
    Inter is self-hosted (assets/fonts/). It used to come from
    fonts.googleapis.com through a render-blocking <link>, which put two
    extra DNS+TLS handshakes on the critical path -- and a white screen
    for as long as that host took to answer.
-->
<!--
    No ?v= on the preload: @font-face inside inter.css asks for the plain
    filename, and a mismatched URL downloads the file a second time
    rather than priming the one the CSS will use. The font never changes
    without its name changing, so there is nothing to bust.
-->
<link rel="preload" href="assets/fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= asset('assets/fonts/inter.css') ?>" />
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>" />
</head>
<body>

<div class="app" id="app">
    <?php include __DIR__ . '/components/sidebar.php'; ?>
    <?php include __DIR__ . '/components/chat.php'; ?>
    <?php include __DIR__ . '/components/customer_form.php'; ?>
</div>

<!-- Toast notifications (Copied, errors, etc.) -->
<div class="toast-stack" id="toastStack" aria-live="polite"></div>

<script src="<?= asset('assets/js/app.js') ?>"></script>
</body>
</html>
