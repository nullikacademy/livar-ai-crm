<?php
/**
 * Stateless n8n draft generator.
 *
 * POST {"session_id":"..."} -> {"success":true,"draft":"..."}
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_auth();
require_once __DIR__ . '/../config/db_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

try {
    $data = read_json_body();
    $sessionId = input_str($data, 'session_id');
    if ($sessionId === '') {
        json_error('session_id is required', 422);
    }

    $customer = getCustomer($sessionId);
    if ($customer === null) {
        json_error('Customer not found', 404);
    }

    $history = array_map(static function (array $message): array {
        $isAssistant = ($message['direction'] ?? null) === 'out'
            || (($message['direction'] ?? null) === null && ($message['type'] ?? '') === 'ai');
        $content = trim((string) ($message['content'] ?? ''));
        $label = [
            'image' => '[Photo]',
            'video' => '[Video]',
            'audio' => '[Voice message]',
            'document' => '[Document: ' . ($message['media_name'] ?? 'file') . ']',
            'location' => '[Location: ' . ($message['place_name'] ?? '') . ']',
            'sticker' => '[Sticker]',
        ][$message['msg_type'] ?? ''] ?? null;
        if ($label !== null) {
            $content = trim($label . ' ' . $content);
        } elseif ($content === '') {
            $content = '[Unsupported message]';
        }

        return ['role' => $isAssistant ? 'assistant' : 'user', 'content' => $content];
    }, getMessages($sessionId, 0, 200));

    $customerContext = [];
    foreach ([
        'first_name', 'last_name', 'username', 'phone', 'country', 'email',
        'city', 'address', 'tax_id', 'details', 'wa_id', 'wa_profile_name',
    ] as $field) {
        $customerContext[$field] = $customer[$field] ?? null;
    }

    $payload = json_encode([
        'session_id' => $sessionId,
        'history'    => $history,
        'customer'   => $customerContext,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Could not encode the n8n request.');
    }

    $ch = curl_init(N8N_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => N8N_TIMEOUT_SECONDS,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $responseBody = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0) {
        error_log("[api/webhook] curl error ({$curlErrno}): {$curlError}");
        json_error('Could not reach the AI service. Please try again.', 502);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("[api/webhook] n8n returned HTTP {$httpCode}: " . (string) $responseBody);
        json_error('The AI service returned an error. Please try again.', 502);
    }

    $decoded = json_decode((string) $responseBody, true);
    $draft = is_array($decoded)
        ? ($decoded['draft'] ?? $decoded['output'] ?? $decoded['text'] ?? $decoded['reply'] ?? null)
        : null;
    if (!is_string($draft) || trim($draft) === '') {
        error_log('[api/webhook] n8n response did not contain a draft.');
        json_error('The AI service returned an empty draft. Please try again.', 502);
    }

    json_response(['success' => true, 'draft' => trim($draft)]);
} catch (SupabaseException $e) {
    error_log('[api/webhook] ' . $e->getMessage());
    json_error('Could not load the conversation for drafting.', 502);
} catch (Throwable $e) {
    error_log('[api/webhook] ' . $e->getMessage());
    json_error('Something went wrong while generating the draft.', 500);
}
