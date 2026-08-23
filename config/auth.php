<?php
/**
 * config/auth.php
 *
 * Shared-password session auth. The CRM can now send WhatsApp messages
 * from the business number, so every entry point except the 360dialog
 * webhook has to be behind a login -- an open send endpoint would let
 * anyone message customers as LiVAR.
 *
 * There is exactly one credential, CRM_PASSWORD_HASH in config/config.php,
 * stored as a password_hash() string (never plaintext). Sessions are the
 * only state; there is no user table.
 *
 * api/whatsapp_webhook.php is deliberately NOT guarded by this file --
 * 360dialog cannot log in. It authenticates with an unguessable token in
 * its own URL instead (WHATSAPP_WEBHOOK_TOKEN).
 */

declare(strict_types=1);

require_once __DIR__ . '/load_config.php';
require_once __DIR__ . '/app.php';

/**
 * Starts the PHP session with hardened cookie flags, once per request.
 *
 * `secure` is only set when the request actually arrived over HTTPS --
 * forcing it on a plain-HTTP dev server would make the browser drop the
 * cookie and log you out on every click.
 */
function auth_boot_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => auth_is_https(),
    ]);
    session_name('livar_crm');
    session_start();
}

/**
 * True when the current request reached us over TLS, including the
 * common shared-hosting case of a proxy terminating TLS in front of PHP.
 */
function auth_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    return is_string($forwarded) && strtolower($forwarded) === 'https';
}

/**
 * Whether the current session has authenticated.
 */
function is_logged_in(): bool
{
    auth_boot_session();
    return ($_SESSION['crm_authed'] ?? false) === true;
}

/**
 * Checks a submitted password against CRM_PASSWORD_HASH and, on success,
 * marks the session as authenticated.
 *
 * A wrong password costs ~0.4s of wall clock, which makes online guessing
 * against a single shared password impractical without needing any
 * lockout state to store.
 */
function attempt_login(string $password): bool
{
    auth_boot_session();

    $hash = defined('CRM_PASSWORD_HASH') ? (string) CRM_PASSWORD_HASH : '';

    if ($hash === '' || str_starts_with($hash, 'REPLACE_WITH')) {
        error_log('[Auth] CRM_PASSWORD_HASH is not set in config/config.php.');
        usleep(400000);
        return false;
    }

    if ($password === '' || !password_verify($password, $hash)) {
        usleep(400000);
        return false;
    }

    // New id on privilege change, so a session fixated before login is
    // not the one that ends up authenticated.
    session_regenerate_id(true);
    $_SESSION['crm_authed'] = true;
    $_SESSION['crm_login_at'] = time();

    return true;
}

/**
 * Drops the session and its cookie.
 */
function logout(): void
{
    auth_boot_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => auth_is_https(),
        ]);
    }

    session_destroy();
}

/**
 * Gate for everything that isn't the login page or the 360dialog webhook.
 *
 * API callers get a 401 JSON body they can react to; page loads get a
 * redirect to login.php with a `next` parameter so the agent lands back
 * where they were.
 */
function require_auth(bool $isApi = true): void
{
    if (is_logged_in()) {
        return;
    }

    if ($isApi) {
        json_error('Authentication required', 401);
    }

    header('Location: ' . auth_login_url());
    exit;
}

/**
 * Builds the login URL, carrying the current path so login.php can send
 * the agent back to it.
 */
function auth_login_url(): string
{
    $target = $_SERVER['REQUEST_URI'] ?? '';
    if (!is_string($target) || $target === '') {
        return 'login.php';
    }
    return 'login.php?next=' . rawurlencode($target);
}

/**
 * Sanitises a post-login redirect target. Only same-origin, root-relative
 * paths are allowed -- anything else (absolute URLs, protocol-relative
 * "//evil.example") would turn login.php into an open redirect.
 */
function auth_safe_next(string $next): string
{
    if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//')) {
        return 'index.php';
    }
    if (str_contains($next, "\r") || str_contains($next, "\n")) {
        return 'index.php';
    }
    return $next;
}
