<?php
/** Shared-password login page. */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

if (($_GET['action'] ?? '') === 'logout') {
    logout();
}

if (is_logged_in()) {
    header('Location: index.php', true, 302);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if (attempt_login($password)) {
        header('Location: index.php', true, 302);
        exit;
    }
    $error = 'Incorrect password.';
}

$cssVersion = (string) (filemtime(__DIR__ . '/assets/css/style.css') ?: 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Sign in — LiVAR CRM</title>
    <meta name="robots" content="noindex,nofollow" />
    <link rel="stylesheet" href="assets/css/style.css?v=<?= htmlspecialchars($cssVersion, ENT_QUOTES, 'UTF-8') ?>" />
</head>
<body class="login-page">
    <main class="login-card">
        <div class="login-card__brand">LiVAR</div>
        <h1>CRM sign in</h1>
        <p>Enter the shared workspace password to continue.</p>

        <?php if ($error !== ''): ?>
            <div class="login-card__error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" class="login-card__form">
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required autofocus />
            </div>
            <button class="btn btn--primary btn--block" type="submit">Sign in</button>
        </form>
    </main>
</body>
</html>
