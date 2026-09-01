<?php
/**
 * api/read.php
 *
 *   POST /api/read.php   body: { "session_id": "wa_34600111222" }
 *
 * Marks a conversation as read, which clears its unread badge in the
 * sidebar. Called when an agent opens a conversation, and again when a
 * message arrives in the one already on screen -- a message you are
 * looking at is not unread.
 *
 * Its own endpoint rather than a field on api/customers.php: `last_read_at`
 * is not a profile fact somebody types, it is a record of something that
 * happened, and it is deliberately absent from CUSTOMER_PROFILE_FIELDS so
 * the details form cannot write it. The time stamped is the server's, never
 * one the browser sent.
 *
 * The CRM has a single shared login, so "read" means read by the business
 * rather than by one agent -- clearing it on one device clears it on all
 * of them, which is what you want when the same person is on a phone and
 * a laptop.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

try {
    $data      = read_json_body();
    $sessionId = input_str($data, 'session_id');

    if ($sessionId === '') {
        json_error('session_id is required', 422);
    }

    // Checked rather than blindly patched, so a bad session_id answers
    // 404 instead of silently updating nothing and reporting success.
    if (getCustomer($sessionId) === null) {
        json_error('Customer not found', 404);
    }

    markConversationRead($sessionId);

    json_response(['success' => true]);
} catch (SupabaseException $e) {
    error_log('[api/read] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    error_log('[api/read] ' . $e->getMessage());
    json_error('Something went wrong while marking that conversation read.', 500);
}
