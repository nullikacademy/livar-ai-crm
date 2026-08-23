<?php
/**
 * config/load_config.php
 *
 * Loads config/config.php, which is gitignored and therefore absent from a
 * fresh clone. Without this guard PHP would die with a bare
 * "Failed opening required" fatal; instead we log the real cause and show
 * the same generic JSON error shape the rest of the API uses.
 */

declare(strict_types=1);

if (!is_file(__DIR__ . '/config.php')) {
    error_log('[Supabase] config/config.php is missing. Copy config/config.example.php to config/config.php and fill in your Supabase values.');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'Not configured yet: copy config/config.example.php to config/config.php and fill it in.',
    ]);
    exit;
}

require_once __DIR__ . '/config.php';
