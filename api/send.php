<?php
/**
 * api/send.php
 *
 *   POST /api/send.php
 *   {
 *     "session_id": "wa_34600111222",
 *     "type": "text" | "image" | "video" | "document" | "location"
 *           | "template" | "buttons" | "catalog",
 *     "text": "...",                 // text body, or a caption for media
 *     "media_ref": "...",            // from api/upload.php
 *     "latitude": 41.3874, "longitude": 2.1686,
 *     "place_name": "...", "place_address": "...",
 *     "template": "order_update", "language": "en", "params": ["..."],
 *     "buttons": ["Yes", "No"]
 *   }
 *
 * Delivers a message over WhatsApp and records it. This is the endpoint
 * that made auth a prerequisite: unguarded, it would let anyone message
 * customers from the business number.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/whatsapp.php';
require_once __DIR__ . '/../config/media.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

try {
    $data      = read_json_body();
    $sessionId = input_str($data, 'session_id');
    $type      = input_str($data, 'type', 'text');
    $text      = isset($data['text']) && is_string($data['text']) ? trim($data['text']) : '';

    if ($sessionId === '') {
        json_error('session_id is required', 422);
    }

    $customer = getCustomer($sessionId);
    if ($customer === null) {
        json_error('Customer not found', 404);
    }

    $waId = normalizeWaId((string) ($customer['wa_id'] ?? ''));
    if ($waId === '') {
        json_error('This customer has no WhatsApp number, so there is nothing to send to.', 422);
    }

    // The window is enforced here, not just shown in the UI. A template
    // is the one thing WhatsApp still carries once it has closed, and is
    // therefore the one type that skips this check.
    if ($type !== 'template' && !isWithin24hWindow($customer['last_inbound_at'] ?? null)) {
        json_error(
            'The 24-hour reply window has closed. WhatsApp only allows a free-form reply within '
            . 'a day of the customer\'s last message; after that an approved template is required.',
            409
        );
    }

    $result = match ($type) {
        'text'     => sendTextMessage($waId, $sessionId, $text),
        'location' => sendLocationMessage($waId, $sessionId, $data),
        'template' => sendTemplateMessage($waId, $sessionId, $data),
        'buttons'  => sendButtonsMessage($waId, $sessionId, $text, $data),
        'catalog'  => sendCatalogMessage($waId, $sessionId, $text),
        default    => sendMediaMessage($waId, $sessionId, $type, $text, input_str($data, 'media_ref')),
    };

    json_response(['success' => true, 'message' => $result]);
} catch (WhatsAppException $e) {
    // The provider's own wording is passed through on purpose. This is
    // the one place the generic-error rule is relaxed: "message failed"
    // with no reason is unusable to an agent who has to decide whether
    // to retry, fix the number, or phone the customer.
    error_log('[api/send] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus >= 400 && $e->httpStatus < 600 ? $e->httpStatus : 502);
} catch (SupabaseException $e) {
    error_log('[api/send] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    error_log('[api/send] ' . $e->getMessage());
    json_error('Something went wrong while sending that message.', 500);
}

/**
 * Sends a plain text reply.
 *
 * @return array<string, mixed> the stored row, frontend-shaped
 */
function sendTextMessage(string $waId, string $sessionId, string $text): array
{
    if ($text === '') {
        json_error('There is nothing to send.', 422);
    }

    $response = WhatsApp::client()->sendText($waId, $text);

    return storeOutbound($sessionId, [
        'msg_type'      => 'text',
        'content'       => $text,
        'wa_message_id' => WhatsApp::messageIdFrom($response),
    ]);
}

/**
 * Sends a pin on the map.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function sendLocationMessage(string $waId, string $sessionId, array $data): array
{
    if (!isset($data['latitude'], $data['longitude']) || !is_numeric($data['latitude']) || !is_numeric($data['longitude'])) {
        json_error('A latitude and longitude are required to send a location.', 422);
    }

    $lat = (float) $data['latitude'];
    $lng = (float) $data['longitude'];

    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        json_error('Those coordinates are not on the map.', 422);
    }

    $name    = input_str($data, 'place_name');
    $address = input_str($data, 'place_address');

    $response = WhatsApp::client()->sendLocation($waId, $lat, $lng, $name, $address);

    return storeOutbound($sessionId, [
        'msg_type'      => 'location',
        'content'       => $name,
        'latitude'      => $lat,
        'longitude'     => $lng,
        'place_name'    => $name,
        'place_address' => $address,
        'wa_message_id' => WhatsApp::messageIdFrom($response),
    ]);
}

/**
 * Sends an approved template.
 *
 * This is the only path that reaches a customer whose 24-hour window has
 * closed. What gets STORED is the template rendered with the values the
 * agent typed, not the raw "Hi {{1}}" -- the thread has to show what the
 * customer actually received, or the next agent to open it is reading a
 * different conversation than the one that happened.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function sendTemplateMessage(string $waId, string $sessionId, array $data): array
{
    $name     = input_str($data, 'template');
    $language = input_str($data, 'language');

    if ($name === '' || $language === '') {
        json_error('Pick a template and its language first.', 422);
    }

    $params = [];
    foreach (($data['params'] ?? []) as $value) {
        if (!is_string($value) && !is_numeric($value)) {
            json_error('Template values must be text.', 422);
        }
        $value = trim((string) $value);
        if ($value === '') {
            json_error('Fill in every value the template asks for.', 422);
        }
        // A newline or a tab in a parameter is rejected by Meta, and a
        // four-character tab is not worth a failed send.
        $params[] = preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    $response = WhatsApp::client()->sendTemplate($waId, $name, $language, $params);

    // The preview the picker built, which is what the customer will see.
    // Bounded: it is text from the client, and a chat row is not the
    // place to discover that.
    $rendered = renderTemplateBody(mb_substr(input_str($data, 'body'), 0, 2000), $params);
    if ($rendered === '') {
        // No preview came from the picker: record enough that the thread
        // is not a blank bubble.
        $rendered = '[template: ' . $name . ']';
    }

    return storeOutbound($sessionId, [
        'msg_type'      => 'template',
        'content'       => $rendered,
        'wa_template'   => $name,
        'wa_message_id' => WhatsApp::messageIdFrom($response),
    ]);
}

/**
 * Substitutes {{1}}, {{2}} ... into a template body for the record.
 *
 * The body text comes from the picker, which got it from
 * api/templates.php, which got it from Meta -- so this is only ever
 * re-rendering something the provider already approved.
 *
 * @param array<int, string> $params
 */
function renderTemplateBody(string $body, array $params): string
{
    if ($body === '') {
        return '';
    }

    foreach ($params as $index => $value) {
        $body = str_replace(['{{' . ($index + 1) . '}}', '{{ ' . ($index + 1) . ' }}'], $value, $body);
    }

    return trim($body);
}

/**
 * Asks a question the customer can answer by tapping.
 *
 * The tap comes back on the webhook as an `interactive` message, so the
 * answer lands in the thread as a normal inbound row -- which is the
 * point: a tapped button is an answer the CRM can read, where "sure, the
 * 500ml one I think" is a sentence somebody has to interpret.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function sendButtonsMessage(string $waId, string $sessionId, string $text, array $data): array
{
    if ($text === '') {
        json_error('Write the question first.', 422);
    }

    $buttons = [];
    foreach (($data['buttons'] ?? []) as $label) {
        if (!is_string($label)) {
            json_error('Each answer button must be text.', 422);
        }
        $label = trim($label);
        if ($label !== '') {
            $buttons[] = $label;
        }
    }

    if (!$buttons) {
        json_error('Add at least one answer button.', 422);
    }

    $response = WhatsApp::client()->sendButtons($waId, $text, $buttons, input_str($data, 'footer'));

    return storeOutbound($sessionId, [
        'msg_type'      => 'buttons',
        'content'       => $text,
        'wa_buttons'    => json_encode($buttons, JSON_UNESCAPED_UNICODE),
        'wa_message_id' => WhatsApp::messageIdFrom($response),
    ]);
}

/**
 * Sends the catalog uploaded on the settings page.
 *
 * One file for the whole business, so this takes no reference from the
 * browser at all: the agent presses a button and the server sends
 * whatever is currently configured. Nothing about which file that is
 * comes from the request.
 *
 * @return array<string, mixed>
 */
function sendCatalogMessage(string $waId, string $sessionId, string $caption): array
{
    $catalog = getCatalogFile();
    if ($catalog === null) {
        json_error(
            'No catalog has been uploaded yet. Add one on the settings page and it will be one click from here.',
            422
        );
    }

    $sendType = media_msg_type_for_mime($catalog['mime']);

    $mediaId  = WhatsApp::client()->uploadMedia($catalog['abs'], $catalog['mime']);
    $response = WhatsApp::client()->sendMedia(
        $waId,
        $sendType,
        $mediaId,
        $caption,
        $sendType === 'document' ? $catalog['name'] : ''
    );

    return storeOutbound($sessionId, [
        'msg_type'      => $sendType,
        'content'       => $caption,
        'media_path'    => $catalog['path'],
        'media_mime'    => $catalog['mime'],
        'media_size'    => $catalog['size'],
        'media_name'    => $catalog['name'],
        'wa_message_id' => WhatsApp::messageIdFrom($response),
    ]);
}

/**
 * Uploads a staged file to WhatsApp and sends it.
 *
 * $type comes from the client but is re-derived from the file's actual
 * mime below, so a mislabelled request cannot make WhatsApp reject a
 * perfectly good file.
 *
 * @return array<string, mixed>
 */
function sendMediaMessage(string $waId, string $sessionId, string $type, string $caption, string $mediaRef): array
{
    if ($mediaRef === '') {
        json_error('Unsupported message type: ' . $type, 422);
    }

    $staged = resolveMediaRef($mediaRef);
    if ($staged === null) {
        json_error('That attachment is no longer available. Please attach it again.', 422);
    }

    $mime     = $staged['mime'];
    $sendType = media_msg_type_for_mime($mime);
    if ($sendType === 'sticker') {
        $sendType = 'image';
    }

    $mediaId  = WhatsApp::client()->uploadMedia($staged['abs'], $mime);
    $response = WhatsApp::client()->sendMedia(
        $waId,
        $sendType,
        $mediaId,
        $caption,
        $sendType === 'document' ? $staged['name'] : ''
    );

    return storeOutbound($sessionId, [
        'msg_type'      => $sendType,
        'content'       => $caption,
        'media_path'    => $staged['path'],
        'media_mime'    => $mime,
        'media_size'    => $staged['size'],
        'media_name'    => $staged['name'],
        'wa_message_id' => WhatsApp::messageIdFrom($response),
    ]);
}

/**
 * Looks a media_ref from api/upload.php back up on disk.
 *
 * The ref is the opaque basename the upload endpoint generated, so it
 * carries no path of its own; the sidecar holds the original filename,
 * which is display-only and never touches the path.
 *
 * @return array{abs: string, path: string, mime: string, size: int, name: string}|null
 */
function resolveMediaRef(string $ref): ?array
{
    // Belt and braces: the ref is server-generated hex plus an
    // extension, so anything else is not one of ours.
    if (!preg_match('/^[0-9a-f]{32}\.[a-z0-9]{1,5}$/', $ref)) {
        error_log('[api/send] rejected a malformed media_ref: ' . $ref);
        return null;
    }

    $relative = 'outbox/' . $ref;
    $abs      = media_abs_path($relative);
    if ($abs === null) {
        return null;
    }

    $mime = '';
    $name = '';
    $meta = @file_get_contents($abs . '.json');
    if ($meta !== false) {
        $decoded = json_decode($meta, true);
        if (is_array($decoded)) {
            $mime = (string) ($decoded['mime'] ?? '');
            $name = (string) ($decoded['name'] ?? '');
        }
    }

    // Trust the file itself over anything recorded beside it. All three
    // functions are checked because disable_functions works per function.
    if (function_exists('finfo_open') && function_exists('finfo_file') && function_exists('finfo_close')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_file($finfo, $abs);
            finfo_close($finfo);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        }
    }

    if (media_ext_for_mime($mime) === null) {
        error_log('[api/send] staged file has a disallowed mime: ' . $mime);
        return null;
    }

    return [
        'abs'  => $abs,
        'path' => $relative,
        'mime' => media_normalize_mime($mime),
        'size' => (int) filesize($abs),
        'name' => $name !== '' ? $name : basename($abs),
    ];
}

/**
 * Records a message we just sent and returns it in the shape the chat
 * renders, so the UI can drop it straight in without a refetch.
 *
 * @param array<string, mixed> $fields
 * @return array<string, mixed>
 */
function storeOutbound(string $sessionId, array $fields): array
{
    $fields['direction'] = 'out';
    $fields['wa_status'] = 'sent';
    // Where this was written. The counterpart is 'app', set by the
    // coexistence echo webhook for a reply somebody typed on their
    // phone -- so an agent opening a thread can tell which replies came
    // from here and which did not.
    $fields['wa_source'] = 'crm';

    $row = insertWhatsAppMessage($sessionId, $fields);
    if ($row === null) {
        // Delivered but not recorded. Say so rather than reporting a
        // failure the agent would retry -- that would send it twice.
        error_log('[api/send] message was sent but could not be stored: ' . json_encode($fields));
        json_error('The message was sent, but the CRM could not record it. Reload before sending again.', 500);
    }

    $id      = (int) $row['id'];
    $msgType = (string) ($fields['msg_type'] ?? 'text');

    return [
        'id'            => $id,
        'type'          => 'ai',
        'content'       => (string) ($fields['content'] ?? ''),
        'created_at'    => $row['created_at'] ?? null,
        'direction'     => 'out',
        'wa_status'     => 'sent',
        'msg_type'      => $msgType,
        'media_url'     => in_array($msgType, MEDIA_MSG_TYPES, true) ? 'api/media.php?id=' . $id : null,
        'media_mime'    => $fields['media_mime'] ?? null,
        'media_size'    => isset($fields['media_size']) ? (int) $fields['media_size'] : null,
        'media_name'    => $fields['media_name'] ?? null,
        'buttons'       => decodeButtonLabels($fields['wa_buttons'] ?? null),
        'wa_template'   => $fields['wa_template'] ?? null,
        'wa_source'     => 'crm',
        'latitude'      => $fields['latitude'] ?? null,
        'longitude'     => $fields['longitude'] ?? null,
        'place_name'    => $fields['place_name'] ?? null,
        'place_address' => $fields['place_address'] ?? null,
    ];
}
