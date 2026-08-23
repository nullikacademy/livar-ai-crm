<?php
/**
 * Shared-password authentication for CRM pages and API routes.
 */

declare(strict_types=1);

require_once __DIR__ . '/app.php';

const AUTH_SESSION_KEY = 'livar_crm_authenticated';

/** Starts the hardened application session exactly once. */
function start_auth_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('livar_crm');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Returns true when this browser session has passed the shared-password login. */
function is_logged_in(): bool
{
    start_auth_session();
    return ($_SESSION[AUTH_SESSION_KEY] ?? false) === true;
}

/** Verifies the configured bcrypt hash and establishes a fresh session. */
function attempt_login(string $password): bool
{
    start_auth_session();

    $hash = defined('CRM_PASSWORD_HASH') ? CRM_PASSWORD_HASH : '';
    if ($hash !== '' && password_verify($password, $hash)) {
        session_regenerate_id(true);
        $_SESSION[AUTH_SESSION_KEY] = true;
        $_SESSION['livar_crm_authenticated_at'] = time();
        return true;
    }

    // Keep failed guesses expensive without exposing why authentication failed.
    usleep(350000);
    return false;
}

/** Clears the authenticated session and its cookie. */
function logout(): void
{
    start_auth_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_destroy();
}

/**
 * Requires a login. API callers receive JSON; page loads are redirected.
 */
function require_auth(): void
{
    if (is_logged_in()) {
        // API polling and parallel media requests do not mutate the session;
        // release PHP's session lock immediately so they do not serialize.
        session_write_close();
        return;
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_contains($script, '/api/')) {
        json_error('Authentication required', 401);
    }

    header('Location: login.php', true, 302);
    exit;
}
