<?php
/**
 * login.php — shared-password sign-in.
 *
 * Posts to itself. On success it redirects to `next` (validated to be a
 * same-origin path) or to index.php. Reuses the app's existing .field and
 * .btn styles rather than introducing a second visual language.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

$next  = auth_safe_next((string) ($_GET['next'] ?? $_POST['next'] ?? ''));
$error = '';

// login.php?logout=1 is the sign-out route -- there is no separate page.
if (isset($_GET['logout'])) {
    logout();
    header('Location: login.php');
    exit;
}

// Already signed in? Don't show the form again.
if (is_logged_in() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $next);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';

    if (attempt_login($password)) {
        header('Location: ' . $next);
        exit;
    }

    $error = 'That password is not right.';
}

/**
 * Same cache-busting helper index.php uses, duplicated here so the login
 * page has no dependency on the application shell.
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
<title>Sign in — LiVAR Packaging CRM</title>
<meta name="theme-color" content="#FFFFFF" />
<meta name="robots" content="noindex, nofollow" />

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>" />
</head>
<body>

<main class="login">
    <form class="login__card" method="post" action="login.php" autocomplete="on">
        <div class="login__brand">
            <span class="login__logo">LiVAR</span>
            <span class="login__logo-sub">Packaging CRM</span>
        </div>

        <p class="login__intro">Sign in to open the WhatsApp inbox.</p>

        <?php if ($error !== ''): ?>
            <p class="login__error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>" />

        <div class="field">
            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                autocomplete="current-password"
                autofocus
                required
            />
        </div>

        <button type="submit" class="btn btn--primary btn--block">Sign in</button>
    </form>
</main>

</body>
</html>
