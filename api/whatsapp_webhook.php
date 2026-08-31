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
require_once __DIR__ . '/../config/ai.php';

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

            // Messages the business sent from the WhatsApp app itself
            // rather than through this CRM. See handle_echo().
            foreach (($value['message_echoes'] ?? []) as $echo) {
                if (!is_array($echo)) {
                    continue;
                }
                try {
                    $row = handle_echo($echo);
                    if ($row !== null) {
                        $pending[] = $row;
                    }
                } catch (Throwable $e) {
                    error_log('[WhatsApp] could not store an echoed message: ' . $e->getMessage());
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
 * Stores one message the business sent from the WhatsApp app.
 *
 * This is the `smb_message_echoes` webhook, which only exists on a
 * number running WhatsApp Coexistence -- the Business app and the Cloud
 * API on the same line. Without it, a reply an agent taps out on their
 * phone is invisible here, and the CRM shows a customer question with no
 * answer under it while the customer has already been answered. That is
 * worse than not showing the thread at all, because it reads as a
 * dropped conversation and someone answers it twice.
 *
 * Two things are deliberately NOT done here:
 *
 *  - `last_inbound_at` is not touched. The 24-hour window is opened by
 *    the customer speaking, and us replying from a phone is not that.
 *  - Nothing guards against echoing back a message this CRM itself sent.
 *    Meta only echoes what the app sent, and if that ever changes the
 *    unique index on wa_message_id turns the duplicate into a no-op --
 *    which is the same protection webhook retries already rely on.
 *
 * @param array<string, mixed> $echo
 * @return array{id: int, media_id: string}|null a row whose media still needs downloading
 */
function handle_echo(array $echo): ?array
{
    $type = (string) ($echo['type'] ?? '');

    // An edit or a deletion refers to a message already in the thread
    // rather than adding one.
    if ($type === 'revoke' || $type === 'edit') {
        return apply_echo_change($type, $echo);
    }

    // `to` is the customer here: in an echo the business is the sender,
    // so reading `from` would file every one of these against our own
    // number.
    $waId = normalizeWaId((string) ($echo['to'] ?? ''));
    if ($waId === '') {
        error_log('[WhatsApp] echoed message with no recipient, skipped: ' . json_encode($echo));
        return null;
    }

    // An echo carries no contacts[], so there is no profile name to
    // learn from it -- but the number may still be one we have never
    // seen, if the first thing that ever happened was us messaging them.
    $customer  = getOrCreateCustomerByWaId($waId);
    $sessionId = (string) $customer['session_id'];

    $fields = extract_message_fields($echo, 'out');
    $fields['wa_message_id'] = (string) ($echo['id'] ?? '');
    $fields['wa_status']     = 'sent';
    // What separates this from a row api/send.php wrote. The thread says
    // so, because "who already replied to this, and from where" is the
    // question an agent opening a mirrored conversation actually has.
    $fields['wa_source']     = 'app';

    if (isset($echo['timestamp']) && ctype_digit((string) $echo['timestamp'])) {
        $fields['created_at'] = gmdate('c', (int) $echo['timestamp']);
    }

    $row = insertWhatsAppMessage($sessionId, $fields);
    if ($row === null) {
        // Already stored: a redelivered batch, or a message this CRM
        // sent that came back to us as an echo.
        return null;
    }

    $mediaId = (string) ($fields['wa_media_id'] ?? '');
    if ($mediaId !== '' && isset($row['id'])) {
        return ['id' => (int) $row['id'], 'media_id' => $mediaId];
    }

    return null;
}

/**
 * Applies an `edit` or `revoke` echo to the row it refers to.
 *
 * Both are conservative on purpose. Each carries the id of the message
 * it changes, but under a key that varies between the payload versions
 * seen in the wild, so the id is looked for in the documented places and
 * the whole echo is logged when none of them has it. Nothing is invented:
 * an edit with no new text and a revoke with no id both do nothing but
 * leave a line in the log naming the payload that defeated them.
 *
 * @param array<string, mixed> $echo
 */
function apply_echo_change(string $type, array $echo): ?array
{
    $detail = is_array($echo[$type] ?? null) ? $echo[$type] : [];

    $targetId = (string) (
        $detail['message_id']
        ?? $detail['id']
        ?? $echo['message_id']
        ?? ''
    );

    if ($targetId === '') {
        error_log("[WhatsApp] {$type} echo names no message to change: " . json_encode($echo));
        return null;
    }

    if ($type === 'revoke') {
        // Not a delete: the row stays and the bubble says it was deleted.
        // A message that was in the thread and then withdrawn is a fact
        // about the conversation, and erasing it makes the reply that
        // followed unreadable.
        updateMessageStatus($targetId, 'deleted');
        return null;
    }

    // An edit carries the replacement in the same shape a new message
    // would, keyed by its own type.
    $updatedType = (string) ($detail['type'] ?? 'text');
    $updated     = extract_message_fields($detail + ['type' => $updatedType], 'out');
    $content     = trim((string) ($updated['content'] ?? ''));

    if ($content === '') {
        error_log('[WhatsApp] edit echo carried no new text: ' . json_encode($echo));
        return null;
    }

    setMessageContent($targetId, $content);
    return null;
}

/**
 * Maps a Cloud API message object onto our columns.
 *
 * $direction is 'in' for something a customer sent and 'out' for a
 * message echoed back from the WhatsApp app. The shapes are identical --
 * an echo is a normal message object with `to` beside `from` -- which is
 * the whole reason this takes a parameter rather than being written
 * twice.
 *
 * @param array<string, mixed> $message
 * @return array<string, mixed>
 */
function extract_message_fields(array $message, string $direction = 'in'): array
{
    $type = (string) ($message['type'] ?? 'text');

    switch ($type) {
        case 'text':
            return [
                'direction' => $direction,
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
                'direction'  => $direction,
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
                'direction'     => $direction,
                'msg_type'      => 'location',
                'content'       => (string) ($loc['name'] ?? ''),
                'latitude'      => isset($loc['latitude']) ? (float) $loc['latitude'] : null,
                'longitude'     => isset($loc['longitude']) ? (float) $loc['longitude'] : null,
                'place_name'    => (string) ($loc['name'] ?? ''),
                'place_address' => (string) ($loc['address'] ?? ''),
            ];

        // A tap on a quick-reply button under a template we sent.
        case 'button':
            $button = is_array($message['button'] ?? null) ? $message['button'] : [];
            return [
                'direction' => $direction,
                'msg_type'  => 'reply',
                // `payload` is the button's configured value and `text`
                // is what the customer saw. The text is what belongs in
                // the thread; the payload only matters to whoever built
                // the template, and is identical often enough that
                // showing both would just read as a stutter.
                'content'   => (string) ($button['text'] ?? $button['payload'] ?? ''),
            ];

        // A tap on a button or list option from an interactive message
        // -- the answer to a question the CRM asked. See sendButtons()
        // in config/whatsapp.php.
        case 'interactive':
            $i     = is_array($message['interactive'] ?? null) ? $message['interactive'] : [];
            $reply = $i['button_reply'] ?? $i['list_reply'] ?? [];
            $title = is_array($reply) ? (string) ($reply['title'] ?? '') : '';
            // A list option can carry a description under its title; it
            // is part of what the customer chose, so it is part of the
            // answer.
            $note  = is_array($reply) ? trim((string) ($reply['description'] ?? '')) : '';

            return [
                'direction' => $direction,
                'msg_type'  => 'reply',
                'content'   => $note !== '' ? $title . ' — ' . $note : $title,
            ];

        default:
            // Contacts, reactions, orders, system messages... Recorded so
            // the thread shows that *something* arrived, rather than a
            // silent gap the agent can't explain to the customer.
            error_log('[WhatsApp] unsupported inbound message type: ' . $type);
            return [
                'direction' => $direction,
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

            if (str_starts_with($saved['mime'], 'image/')) {
                caption_photo($item['id'], $saved['abs'], $saved['mime']);
            }
        } catch (Throwable $e) {
            // The row is already in the thread; api/media.php will try
            // again the first time somebody opens it.
            error_log("[WhatsApp] media download failed for row {$item['id']}: " . $e->getMessage());
        }
    }

    log_media_usage();
}

/**
 * Asks the vision model for a one-line description of a photo.
 *
 * Runs here, once, rather than at draft time, because the sidebar needs
 * the label before anyone opens the conversation -- "📷 Photo" tells an
 * agent nothing about which of forty threads to answer first.
 *
 * Never fatal: a photo with no caption still renders, still attaches to
 * a draft as a real image, and can still be replied to. An unconfigured
 * or failing AI must not cost us the message.
 */
function caption_photo(int $rowId, string $absPath, string $mime): void
{
    if (!AI::isConfigured()) {
        return;
    }

    try {
        $caption = AI::client()->describeImage($absPath, $mime, getSetting('ai_model'));
        if ($caption !== '') {
            setMessageCaption($rowId, mb_substr($caption, 0, 300));
        }
    } catch (Throwable $e) {
        error_log("[AI] could not caption photo for row {$rowId}: " . $e->getMessage());
    }
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
