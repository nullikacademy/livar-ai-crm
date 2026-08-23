<?php
/** Receives inbound messages and status callbacks from 360dialog. */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/whatsapp.php';

$expectedToken = defined('WHATSAPP_WEBHOOK_TOKEN') ? WHATSAPP_WEBHOOK_TOKEN : '';
$providedToken = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
if (
    $expectedToken === ''
    || str_starts_with($expectedToken, 'REPLACE_WITH')
    || !hash_equals($expectedToken, $providedToken)
) {
    http_response_code(404);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$mediaJobs = [];
try {
    $raw = file_get_contents('php://input');
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Webhook body was not valid JSON.');
    }

    foreach (($payload['entry'] ?? []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        foreach (($entry['changes'] ?? []) as $change) {
            $value = is_array($change) && is_array($change['value'] ?? null)
                ? $change['value']
                : [];
            processProviderErrors(is_array($value['errors'] ?? null) ? $value['errors'] : []);
            processStatuses(is_array($value['statuses'] ?? null) ? $value['statuses'] : []);
            processInboundMessages($value, $mediaJobs);
        }
    }
} catch (Throwable $e) {
    error_log('[WhatsApp] Webhook processing failed: ' . $e->getMessage());
}

acknowledgeWebhook();

// Media work happens after the acknowledgement under php-fpm. On shared hosts
// without fastcgi_finish_request(), the same loop runs inline as a fallback.
foreach ($mediaJobs as $job) {
    try {
        $media = WhatsApp::client()->fetchMedia($job['media_id']);
        $stored = storeWhatsAppMedia($media);
        setMessageMedia($job['row_id'], $stored['path'], $stored['mime'], $stored['size']);
    } catch (Throwable $e) {
        error_log('[WhatsApp] Media download failed for row ' . $job['row_id'] . ': ' . $e->getMessage());
    }
}

if ($mediaJobs) {
    try {
        error_log('[WhatsApp] Media storage usage: ' . mediaDiskUsageBytes() . ' bytes');
    } catch (Throwable $e) {
        error_log('[WhatsApp] Could not calculate media storage usage: ' . $e->getMessage());
    }
}

/** @param array<int, mixed> $statuses */
function processStatuses(array $statuses): void
{
    foreach ($statuses as $status) {
        if (!is_array($status)) {
            continue;
        }

        try {
            $messageId = is_string($status['id'] ?? null) ? $status['id'] : '';
            $state = is_string($status['status'] ?? null) ? $status['status'] : '';
            if ($messageId === '' || $state === '') {
                continue;
            }

            $parts = [];
            foreach (($status['errors'] ?? []) as $error) {
                if (!is_array($error)) {
                    continue;
                }
                $part = $error['message']
                    ?? $error['title']
                    ?? $error['error_data']['details']
                    ?? null;
                if (is_string($part) && $part !== '') {
                    $parts[] = $part;
                }
            }
            updateMessageStatus($messageId, $state, implode('; ', array_unique($parts)));
        } catch (Throwable $e) {
            error_log('[WhatsApp] Status update failed: ' . $e->getMessage());
        }
    }
}

/** @param array<int, mixed> $errors */
function processProviderErrors(array $errors): void
{
    foreach ($errors as $error) {
        if (!is_array($error)) {
            continue;
        }
        $code = isset($error['code']) ? (string) $error['code'] : 'unknown';
        $message = $error['message']
            ?? $error['title']
            ?? $error['error_data']['details']
            ?? 'Unknown webhook error';
        error_log('[WhatsApp] Webhook provider error ' . $code . ': ' . (string) $message);
    }
}

/**
 * @param array<string, mixed> $value
 * @param array<int, array{row_id:int, media_id:string}> $mediaJobs
 */
function processInboundMessages(array $value, array &$mediaJobs): void
{
    $contacts = [];
    foreach (($value['contacts'] ?? []) as $contact) {
        if (!is_array($contact)) {
            continue;
        }
        $waId = normalizeWaId((string) ($contact['wa_id'] ?? ''));
        if ($waId !== '') {
            $contacts[$waId] = (string) ($contact['profile']['name'] ?? '');
        }
    }

    foreach (($value['messages'] ?? []) as $message) {
        if (!is_array($message)) {
            continue;
        }

        try {
            $waId = normalizeWaId((string) ($message['from'] ?? ''));
            if ($waId === '') {
                throw new InvalidArgumentException('Inbound message did not contain a sender.');
            }

            $customer = getOrCreateCustomerByWaId($waId, $contacts[$waId] ?? '');
            $fields = inboundMessageFields($message);
            $row = insertWhatsAppMessage((string) $customer['session_id'], $fields);
            if ($row === null) {
                continue; // Webhook retry: the unique wa_message_id already exists.
            }

            touchLastInbound((string) $customer['session_id']);
            $rowId = (int) ($row['id'] ?? 0);
            $mediaId = is_string($fields['wa_media_id'] ?? null) ? $fields['wa_media_id'] : '';
            if ($rowId > 0 && $mediaId !== '') {
                $mediaJobs[] = ['row_id' => $rowId, 'media_id' => $mediaId];
            }
        } catch (Throwable $e) {
            error_log('[WhatsApp] Inbound message failed: ' . $e->getMessage());
        }
    }
}

/** @param array<string, mixed> $message @return array<string, mixed> */
function inboundMessageFields(array $message): array
{
    $providerType = is_string($message['type'] ?? null) ? $message['type'] : 'unsupported';
    $messageId = is_string($message['id'] ?? null) ? $message['id'] : '';
    $timestamp = isset($message['timestamp']) ? (int) $message['timestamp'] : 0;

    $fields = [
        'created_at'    => $timestamp > 0 ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : gmdate('Y-m-d\TH:i:s\Z'),
        'direction'     => 'in',
        'wa_message_id' => $messageId === '' ? null : $messageId,
        'msg_type'      => $providerType,
        'content'       => '',
    ];

    switch ($providerType) {
        case 'text':
            $fields['content'] = (string) ($message['text']['body'] ?? '');
            break;

        case 'image':
        case 'video':
        case 'audio':
        case 'document':
        case 'sticker':
            $media = is_array($message[$providerType] ?? null) ? $message[$providerType] : [];
            $fields['wa_media_id'] = (string) ($media['id'] ?? '');
            $fields['media_mime'] = (string) ($media['mime_type'] ?? '');
            $fields['content'] = (string) ($media['caption'] ?? '');
            if ($providerType === 'document') {
                $fields['media_name'] = (string) ($media['filename'] ?? 'Document');
            }
            break;

        case 'location':
            $location = is_array($message['location'] ?? null) ? $message['location'] : [];
            $fields['latitude'] = isset($location['latitude']) ? (float) $location['latitude'] : null;
            $fields['longitude'] = isset($location['longitude']) ? (float) $location['longitude'] : null;
            $fields['place_name'] = (string) ($location['name'] ?? '');
            $fields['place_address'] = (string) ($location['address'] ?? '');
            $fields['content'] = trim($fields['place_name'] . ' ' . $fields['place_address']);
            break;

        default:
            $fields['msg_type'] = 'unsupported';
            $fields['content'] = 'Unsupported message type';
            break;
    }

    return $fields;
}

/** Sends the 200 response before deferred media downloads where possible. */
function acknowledgeWebhook(): void
{
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Connection: close');
    $body = '{"success":true}';
    header('Content-Length: ' . strlen($body));
    echo $body;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    ignore_user_abort(true);
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}
