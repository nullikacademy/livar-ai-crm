<?php
/**
 * api/whatsapp_webhook.php
 *
 * Where WhatsApp messages arrive. Register this with 360dialog as:
 *
 *   https://your-crm.example.com/api/whatsapp_webhook.php?token=<WHATSAPP_WEBHOOK_TOKEN>
 *
 * This is the ONE endpoint not behind require_auth() -- 360dialog cannot
 * log in. 360dialog does not sign its webhook calls either, so the
 * unguessable token in the URL above is the whole credential, compared
 * with hash_equals(). A mismatch answers 404 rather than 401: there is
 * no reason to confirm to a prober that the endpoint exists.
 *
 * Two rules shape the rest of this file:
 *
 *  - It always answers 200, even when something inside failed. A non-2xx
 *    makes 360dialog redeliver the whole batch, and redelivering a batch
 *    that was half-inserted is worse than one logged error. Everything
 *    is logged with error_log('[WhatsApp] ...').
 *  - It acknowledges before downloading media. Media downloads are slow
 *    and 360dialog's patience is not; the ack is flushed first with
 *    fastcgi_finish_request() where php-fpm provides it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/whatsapp.php';
require_once __DIR__ . '/../config/media.php';

// ---------------------------------------------------------------------
// 1. Authenticate by URL token
// ---------------------------------------------------------------------

$expected = defined('WHATSAPP_WEBHOOK_TOKEN') ? (string) WHATSAPP_WEBHOOK_TOKEN : '';
$supplied = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';

if ($expected === '' || str_starts_with($expected, 'REPLACE_WITH')) {
    error_log('[WhatsApp] WHATSAPP_WEBHOOK_TOKEN is not set in config/config.php; refusing all webhook calls.');
    webhook_not_found();
}
if (!hash_equals($expected, $supplied)) {
    error_log('[WhatsApp] webhook called with a bad token from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    webhook_not_found();
}

// Some providers verify a webhook with a GET before sending anything.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo $_GET['hub_challenge'] ?? 'ok';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    webhook_not_found();
}

// ---------------------------------------------------------------------
// 2. Parse and store, then ack, then fetch media
// ---------------------------------------------------------------------

/** @var array<int, array{id: int, media_id: string}> filled in during parsing */
$pendingMedia = [];

try {
    $payload      = read_json_body();
    $pendingMedia = handle_payload($payload);
} catch (Throwable $e) {
    // Deliberately swallowed: see the 200-always rule in the docblock.
    error_log('[WhatsApp] webhook failed: ' . $e->getMessage());
}

webhook_ack();

// Everything past this point runs after the response has gone back to
// 360dialog, so a slow CDN cannot cause a redelivery.
if ($pendingMedia) {
    download_pending_media($pendingMedia);
}

exit;

// ---------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------

/**
 * Walks a 360dialog webhook body and stores everything in it.
 *
 * @param array<string, mixed> $payload
 * @return array<int, array{id: int, media_id: string}> rows still needing their bytes
 */
function handle_payload(array $payload): array
{
    $pending = [];

    foreach (($payload['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $change) {
            $value = $change['value'] ?? null;
            if (!is_array($value)) {
                continue;
            }

            // contacts[] carries the WhatsApp profile name, keyed by the
            // same wa_id the messages use.
            $profileNames = [];
            foreach (($value['contacts'] ?? []) as $contact) {
                $waId = normalizeWaId((string) ($contact['wa_id'] ?? ''));
                $name = (string) ($contact['profile']['name'] ?? '');
                if ($waId !== '' && $name !== '') {
                    $profileNames[$waId] = $name;
                }
            }

            foreach (($value['messages'] ?? []) as $message) {
                if (!is_array($message)) {
                    continue;
                }
                try {
                    $row = handle_message($message, $profileNames);
                    if ($row !== null) {
                        $pending[] = $row;
                    }
                } catch (Throwable $e) {
                    // One bad message must not lose the rest of the batch.
                    error_log('[WhatsApp] could not store an inbound message: ' . $e->getMessage());
                }
            }

            foreach (($value['statuses'] ?? []) as $status) {
                if (!is_array($status)) {
                    continue;
                }
                try {
                    handle_status($status);
                } catch (Throwable $e) {
                    error_log('[WhatsApp] could not apply a status update: ' . $e->getMessage());
                }
            }

            foreach (($value['errors'] ?? []) as $error) {
                error_log('[WhatsApp] provider error: ' . json_encode($error));
            }
        }
    }

    return $pending;
}

/**
 * Stores one inbound message.
 *
 * @param array<string, mixed> $message
 * @param array<string, string> $profileNames
 * @return array{id: int, media_id: string}|null a row whose media still needs downloading
 */
function handle_message(array $message, array $profileNames): ?array
{
    $waId = normalizeWaId((string) ($message['from'] ?? ''));
    if ($waId === '') {
        error_log('[WhatsApp] inbound message with no sender, skipped: ' . json_encode($message));
        return null;
    }

    $customer  = getOrCreateCustomerByWaId($waId, $profileNames[$waId] ?? '');
    $sessionId = (string) $customer['session_id'];

    $fields = extract_message_fields($message);
    $fields['wa_message_id'] = (string) ($message['id'] ?? '');

    // WhatsApp sends a unix timestamp; without it every row in a
    // redelivered batch would look like it arrived just now.
    if (isset($message['timestamp']) && ctype_digit((string) $message['timestamp'])) {
        $fields['created_at'] = gmdate('c', (int) $message['timestamp']);
    }

    $row = insertWhatsAppMessage($sessionId, $fields);

    if ($row === null) {
        // The unique index rejected it: 360dialog is redelivering a batch
        // we already handled. That is success, not an error.
        return null;
    }

    // Only after the row exists, so a failure below cannot lose the
    // message itself -- and so the window opens even for media we could
    // not download.
    touchLastInbound($sessionId, $fields['created_at'] ?? null);

    $mediaId = (string) ($fields['wa_media_id'] ?? '');
    if ($mediaId !== '' && isset($row['id'])) {
        return ['id' => (int) $row['id'], 'media_id' => $mediaId];
    }

    return null;
}

/**
 * Maps a Cloud API message object onto our columns.
 *
 * @param array<string, mixed> $message
 * @return array<string, mixed>
 */
function extract_message_fields(array $message): array
{
    $type = (string) ($message['type'] ?? 'text');

    switch ($type) {
        case 'text':
            return [
                'direction' => 'in',
                'msg_type'  => 'text',
                'content'   => (string) ($message['text']['body'] ?? ''),
            ];

        case 'image':
        case 'video':
        case 'audio':
        case 'document':
        case 'sticker':
            $media = is_array($message[$type] ?? null) ? $message[$type] : [];
            return [
                'direction'  => 'in',
                'msg_type'   => $type,
                // A voice note comes through as audio with voice=true;
                // both render the same way, so no separate type.
                'content'    => (string) ($media['caption'] ?? ''),
                'media_mime' => media_normalize_mime((string) ($media['mime_type'] ?? '')),
                'media_name' => (string) ($media['filename'] ?? ''),
                // Kept on the row so api/media.php can re-download later
                // if the deferred fetch below never happened.
                'wa_media_id' => (string) ($media['id'] ?? ''),
            ];

        case 'location':
            $loc = is_array($message['location'] ?? null) ? $message['location'] : [];
            return [
                'direction'     => 'in',
                'msg_type'      => 'location',
                'content'       => (string) ($loc['name'] ?? ''),
                'latitude'      => isset($loc['latitude']) ? (float) $loc['latitude'] : null,
                'longitude'     => isset($loc['longitude']) ? (float) $loc['longitude'] : null,
                'place_name'    => (string) ($loc['name'] ?? ''),
                'place_address' => (string) ($loc['address'] ?? ''),
            ];

        case 'button':
            return [
                'direction' => 'in',
                'msg_type'  => 'text',
                'content'   => (string) ($message['button']['text'] ?? ''),
            ];

        case 'interactive':
            $i = is_array($message['interactive'] ?? null) ? $message['interactive'] : [];
            $title = $i['button_reply']['title'] ?? $i['list_reply']['title'] ?? '';
            return [
                'direction' => 'in',
                'msg_type'  => 'text',
                'content'   => (string) $title,
            ];

        default:
            // Contacts, reactions, orders, system messages... Recorded so
            // the thread shows that *something* arrived, rather than a
            // silent gap the agent can't explain to the customer.
            error_log('[WhatsApp] unsupported inbound message type: ' . $type);
            return [
                'direction' => 'in',
                'msg_type'  => 'unsupported',
                'content'   => '',
            ];
    }
}

/**
 * Applies one statuses[] entry.
 *
 * @param array<string, mixed> $status
 */
function handle_status(array $status): void
{
    $waMessageId = (string) ($status['id'] ?? '');
    $state       = (string) ($status['status'] ?? '');

    if ($waMessageId === '' || $state === '') {
        return;
    }

    $error = '';
    if (isset($status['errors'][0])) {
        $first = $status['errors'][0];
        $error = trim(
            (string) ($first['code'] ?? '') . ' ' .
            (string) ($first['title'] ?? $first['message'] ?? '')
        );
    }

    updateMessageStatus($waMessageId, $state, $error);
}

/**
 * Downloads the media for rows stored a moment ago and records it.
 *
 * Runs after the ack. Meta deletes media after roughly 30 days, so this
 * is eager on purpose -- api/media.php can re-fetch on demand, but only
 * while the file still exists upstream, which makes lazy fetching a
 * fallback rather than the plan.
 *
 * @param array<int, array{id: int, media_id: string}> $pending
 */
function download_pending_media(array $pending): void
{
    foreach ($pending as $item) {
        try {
            $file = WhatsApp::client()->fetchMedia($item['media_id']);
            $saved = media_store($file['bytes'], $file['mime']);
            setMessageMedia($item['id'], $saved['path'], $saved['mime'], $saved['size']);
        } catch (Throwable $e) {
            // The row is already in the thread; api/media.php will try
            // again the first time somebody opens it.
            error_log("[WhatsApp] media download failed for row {$item['id']}: " . $e->getMessage());
        }
    }

    log_media_usage();
}

/**
 * Logs how much disk the media store is using.
 *
 * Sampled rather than measured every time -- walking the tree on every
 * inbound message would be silly -- but frequent enough that growth
 * against the hosting quota shows up in the error log before it becomes
 * a "the site stopped working" call.
 */
function log_media_usage(): void
{
    if (random_int(1, 50) !== 1) {
        return;
    }

    try {
        $bytes = media_total_bytes();
        error_log(sprintf('[WhatsApp] media store now holds %.1f MB', $bytes / 1048576));
    } catch (Throwable $e) {
        error_log('[WhatsApp] could not measure the media store: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Responses
// ---------------------------------------------------------------------

/**
 * Answers 200 and, where the SAPI allows it, releases the connection so
 * the media downloads below do not hold 360dialog waiting.
 */
function webhook_ack(): void
{
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Connection: close');
    echo '{"success":true}';

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    // No php-fpm: the best we can do is keep running after the client
    // gives up, and flush what we already wrote. Shared hosting has no
    // job queue, so the download then happens inline.
    ignore_user_abort(true);
    if (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}

/**
 * The response to anything that isn't an authenticated 360dialog call.
 */
function webhook_not_found(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}
