<?php
/**
 * config/app.php
 *
 * Small shared helpers used across the API layer.
 *
 * Settings are NOT defined here -- they all live in config/config.php,
 * which this file loads.
 */

declare(strict_types=1);

require_once __DIR__ . '/load_config.php';

/**
 * Sends a JSON response and stops execution.
 *
 * Every JSON endpoint here returns live state -- a health check, an
 * unread conversation, a delivery status. None of it may be cached: a
 * browser reusing a stored 200 for `api/health.php?check=storage` will
 * happily show yesterday's failure after you have fixed the problem,
 * and pressing Re-check never reaches the server to say otherwise.
 *
 * Media is the deliberate exception and does not pass through here --
 * api/media.php streams bytes and sets its own long cache header,
 * because the bytes behind a message id never change.
 */
function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Sends a standardized error response.
 */
function json_error(string $message, int $statusCode = 400): void
{
    json_response(['success' => false, 'error' => $message], $statusCode);
}

/**
 * Reads and decodes the JSON request body. Returns [] if empty/invalid.
 */
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Trims a string field from an input array, returning a default if missing.
 */
function input_str(array $data, string $key, string $default = ''): string
{
    if (!isset($data[$key]) || !is_string($data[$key])) {
        return $default;
    }
    return trim($data[$key]);
}

/**
 * Generates a unique session id for a new customer conversation.
 */
function generate_session_id(): string
{
    return 'sess_' . bin2hex(random_bytes(12)) . '_' . time();
}
