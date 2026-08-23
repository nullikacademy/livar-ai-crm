<?php
/** Sends an agent-approved message directly through 360dialog. */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_auth();
require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/whatsapp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$providerAccepted = false;
try {
    $data = read_json_body();
    $sessionId = input_str($data, 'session_id');
    $type = input_str($data, 'type', 'text');
    $text = input_str($data, 'text');
    if ($sessionId === '') {
        json_error('session_id is required', 422);
    }

    $customer = getCustomer($sessionId);
    if ($customer === null) {
        json_error('Customer not found', 404);
    }
    $waId = normalizeWaId((string) ($customer['wa_id'] ?? ''));
    if ($waId === '') {
        json_error('This customer does not have a WhatsApp number.', 422);
    }
    if (!isWithin24hWindow(is_string($customer['last_inbound_at'] ?? null) ? $customer['last_inbound_at'] : null)) {
        json_error('The 24-hour WhatsApp reply window has expired. An approved template is required.', 409);
    }

    $client = WhatsApp::client();
    $messageFields = [
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'direction'  => 'out',
        'wa_status'  => 'sent',
        'msg_type'   => $type,
        'content'    => $text,
    ];

    if ($type === 'text') {
        if ($text === '') {
            json_error('Write a reply before sending', 422);
        }
        $response = $client->sendText($waId, $text);
    } elseif (in_array($type, ['image', 'video', 'document'], true)) {
        $mediaRef = input_str($data, 'media_ref');
        $media = resolveOutboxUpload($mediaRef);
        if ($media === null) {
            json_error('The selected attachment is invalid or no longer available.', 422);
        }
        if (($media['type'] ?? '') !== $type) {
            json_error('The attachment type does not match the send request.', 422);
        }

        $providerMediaId = $client->uploadMedia($media['absolute_path'], $media['mime']);
        $response = $client->sendMedia(
            $waId,
            $type,
            $providerMediaId,
            $text,
            $type === 'document' ? (string) $media['name'] : ''
        );
        $messageFields += [
            'wa_media_id' => $providerMediaId,
            'media_path'  => $media['path'],
            'media_mime'  => $media['mime'],
            'media_size'  => (int) $media['size'],
            'media_name'  => $media['name'],
        ];
    } elseif ($type === 'location') {
        if (!is_numeric($data['latitude'] ?? null) || !is_numeric($data['longitude'] ?? null)) {
            json_error('Latitude and longitude are required', 422);
        }
        $lat = (float) $data['latitude'];
        $lng = (float) $data['longitude'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            json_error('Latitude or longitude is outside the valid range', 422);
        }
        $placeName = input_str($data, 'place_name');
        $placeAddress = input_str($data, 'place_address');
        $response = $client->sendLocation($waId, $lat, $lng, $placeName, $placeAddress);
        $messageFields['content'] = trim($placeName . ' ' . $placeAddress);
        $messageFields['latitude'] = $lat;
        $messageFields['longitude'] = $lng;
        $messageFields['place_name'] = $placeName;
        $messageFields['place_address'] = $placeAddress;
    } else {
        json_error('Unsupported outbound message type', 422);
    }

    $providerAccepted = true;
    $waMessageId = $response['messages'][0]['id'] ?? null;
    if (!is_string($waMessageId) || $waMessageId === '') {
        throw new WhatsAppException('WhatsApp accepted the request without returning a message id.', 502);
    }

    $messageFields['wa_message_id'] = $waMessageId;
    $row = insertWhatsAppMessage($sessionId, $messageFields);
    if ($row === null) {
        throw new RuntimeException('The outbound message id already exists.');
    }
    $message = formatChatMessage($row);
    if ($message === null) {
        throw new RuntimeException('The saved outbound message was malformed.');
    }

    json_response(['success' => true, 'message' => $message]);
} catch (WhatsAppException $e) {
    error_log('[api/send] ' . $e->getMessage());
    // This endpoint deliberately surfaces provider detail so an agent knows
    // whether to edit, retry, or escalate the failed message.
    json_error($e->getMessage(), 502);
} catch (SupabaseException $e) {
    error_log('[api/send] ' . $e->getMessage());
    $message = $providerAccepted
        ? 'WhatsApp accepted the message, but CRM could not save it. Verify delivery before retrying.'
        : 'The customer record could not be loaded.';
    json_error($message, 500);
} catch (Throwable $e) {
    error_log('[api/send] ' . $e->getMessage());
    $message = $providerAccepted
        ? 'WhatsApp accepted the message, but CRM could not save it. Verify delivery before retrying.'
        : 'The message could not be sent.';
    json_error($message, 500);
}
