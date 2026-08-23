<?php
/**
 * api/messages.php
 *
 *   GET /api/messages.php?session_id=xxx   -> full chat history
 *
 * This read endpoint supports bounded incremental polling. Inbound and
 * outbound transport endpoints are the only writers; n8n returns drafts.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

try {
    $sessionId = $_GET['session_id'] ?? '';
    if ($sessionId === '') {
        json_error('session_id is required', 422);
    }

    json_response([
        'success'  => true,
        'messages' => getMessages($sessionId, max(0, (int) ($_GET['since_id'] ?? 0)), max(1, min(500, (int) ($_GET['limit'] ?? 200)))),
    ]);
} catch (SupabaseException $e) {
    error_log('[api/messages] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    error_log('[api/messages] ' . $e->getMessage());
    json_error('Something went wrong while talking to the database.', 500);
}

