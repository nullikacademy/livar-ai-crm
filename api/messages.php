<?php
/**
 * api/messages.php
 *
 *   GET /api/messages.php?session_id=xxx                 -> recent history
 *   GET /api/messages.php?session_id=xxx&since_id=123    -> only newer rows
 *
 * Reads only. Inbound messages are written by api/whatsapp_webhook.php
 * and outbound ones by api/send.php; the frontend polls this endpoint
 * with `since_id` so a conversation picks up new rows without re-fetching
 * (and re-rendering) everything it already has.
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

    $sinceId = isset($_GET['since_id']) ? max(0, (int) $_GET['since_id']) : 0;
    $limit   = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 200;

    // getMessages() carries `_media_path` for the draft builder, which
    // needs the file on disk. The browser gets api/media.php?id=<n>
    // instead -- a path on the server has no business in a response.
    $messages = array_map(static function (array $msg): array {
        unset($msg['_media_path'], $msg['_referral_path']);
        return $msg;
    }, getMessages($sessionId, $sinceId, $limit));

    json_response([
        'success'  => true,
        'messages' => $messages,
        // Only for a since_id poll, and sent even when that poll finds
        // no new messages. A reaction changes a row the browser already
        // has, so an incremental fetch cannot see it -- without this a
        // customer's 👍 on an hour-old message would not appear until
        // the conversation was reopened. Only rows carrying one come
        // back, so in a normal thread this is empty or tiny.
        //
        // A full load needs none of it: every row it returns already
        // carries wa_reaction and wa_reaction_out, and the browser
        // paints them straight off the message. Asking again was a
        // second round trip to Supabase for data the first one had
        // already returned, paid on every conversation switch -- which
        // is precisely when somebody is waiting for the thread.
        'reactions' => $sinceId > 0 ? getReactions($sessionId) : [],
    ]);
} catch (SupabaseException $e) {
    error_log('[api/messages] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    error_log('[api/messages] ' . $e->getMessage());
    json_error('Something went wrong while talking to the database.', 500);
}
