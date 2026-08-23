<?php
declare(strict_types=1);
require_once __DIR__ . '/app.php';

function start_crm_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => true]);
        session_start();
    }
}

function is_logged_in(): bool
{
    start_crm_session();
    return ($_SESSION['crm_authenticated'] ?? false) === true;
}

function attempt_login(string $password): bool
{
    start_crm_session();
    if (defined('CRM_PASSWORD_HASH') && password_verify($password, CRM_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['crm_authenticated'] = true;
        return true;
    }
    usleep(350000);
    return false;
}

function logout(): void
{
    start_crm_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function require_auth(): void
{
    if (is_logged_in()) return;
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (str_contains($script, '/api/')) json_error('Authentication required', 401);
    header('Location: login.php', true, 302);
    exit;
}

