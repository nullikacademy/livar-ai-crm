<?php
/**
 * api/messages.php
 *
 *   GET /api/messages.php?session_id=xxx   -> full chat history
 *
 * Writing messages is intentionally NOT done here. The n8n workflow
 * behind api/webhook.php saves both the human message and the AI reply
 * to n8n_chat_history itself (see README "n8n workflow" section), so
 * the CRM only ever reads this table -- it never writes to it directly.
 * This keeps Supabase as the single source of truth with exactly one
 * writer for chat history.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';

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
        'messages' => getMessages($sessionId),
    ]);
} catch (SupabaseException $e) {
    error_log('[api/messages] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    error_log('[api/messages] ' . $e->getMessage());
    json_error('Something went wrong while talking to the database.', 500);
}
