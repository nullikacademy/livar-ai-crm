<?php
/**
 * api/webhook.php
 *
 *   POST /api/webhook.php   body: { "session_id": "..." }
 *   ->  { "success": true, "draft": "suggested reply text" }
 *
 * Asks n8n to draft a reply. Nothing more.
 *
 * This used to be the write path: n8n owned n8n_chat_history and saved
 * both turns itself. That is inverted now. The CRM is the only writer --
 * inbound rows come from api/whatsapp_webhook.php and outbound ones from
 * api/send.php -- and n8n is a stateless draft generator: it receives
 * the conversation history, returns text, and touches no table. Its
 * workflow must therefore have no Supabase insert nodes and no Postgres
 * Chat Memory node, or history would be written twice (see README
 * section 5).
 *
 * The draft is never persisted here either. It goes into the composer
 * for the agent to edit, and only becomes a message if they press Send.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

/** How many past turns to send as context. */
const DRAFT_HISTORY_LIMIT = 40;

try {
    $data      = read_json_body();
    $sessionId = input_str($data, 'session_id');

    if ($sessionId === '') {
        json_error('session_id is required', 422);
    }

    $customer = getCustomer($sessionId);
    if ($customer === null) {
        json_error('Customer not found', 404);
    }

    $history = buildHistory($sessionId);
    if (!$history) {
        json_error('There is nothing to reply to yet.', 422);
    }

    $payload = json_encode([
        'session_id' => $sessionId,
        'history'    => $history,
        'customer'   => [
            'first_name'  => $customer['first_name'] ?? null,
            'last_name'   => $customer['last_name'] ?? null,
            'company'     => $customer['username'] ?? null,
            'country'     => $customer['country'] ?? null,
            'city'        => $customer['city'] ?? null,
            'email'       => $customer['email'] ?? null,
            'phone'       => $customer['phone'] ?? null,
            'wa_id'       => $customer['wa_id'] ?? null,
            'notes'       => $customer['details'] ?? null,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(N8N_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => N8N_TIMEOUT_SECONDS, // AI generation can take a while.
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $responseBody = curl_exec($ch);
    $curlErrno    = curl_errno($ch);
    $curlError    = curl_error($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0) {
        error_log("[api/webhook] curl error ({$curlErrno}): {$curlError}");
        json_error('Could not reach the AI service. Please try again.', 502);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("[api/webhook] n8n returned HTTP {$httpCode}: {$responseBody}");
        json_error('The AI service returned an error. Please try again.', 502);
    }

    $draft = extractDraft((string) $responseBody);
    if ($draft === null) {
        error_log("[api/webhook] could not find a draft in n8n's response: {$responseBody}");
        json_error('The AI service did not return a draft. Please try again.', 502);
    }

    json_response(['success' => true, 'draft' => $draft]);
} catch (SupabaseException $e) {
    error_log('[api/webhook] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    error_log('[api/webhook] ' . $e->getMessage());
    json_error('Something went wrong while generating the draft.', 500);
}

/**
 * Builds the conversation for the agent to read.
 *
 * n8n no longer has a memory node reading the table, so the history has
 * to travel with the request. Media rows become a short description --
 * "[photo]" tells the model something arrived, which is more useful than
 * an empty string.
 *
 * @return array<int, array{role: string, content: string}>
 */
function buildHistory(string $sessionId): array
{
    $messages = getMessages($sessionId, 0, DRAFT_HISTORY_LIMIT);
    $history  = [];

    foreach ($messages as $msg) {
        $role = ($msg['direction'] ?? null) === 'out' || $msg['type'] === 'ai' ? 'assistant' : 'user';

        $content = (string) $msg['content'];
        $label   = match ($msg['msg_type'] ?? 'text') {
            'image'    => '[photo]',
            'video'    => '[video]',
            'audio'    => '[voice message]',
            'document' => '[document' . ($msg['media_name'] ? ': ' . $msg['media_name'] : '') . ']',
            'location' => '[location' . ($msg['place_name'] ? ': ' . $msg['place_name'] : '') . ']',
            'sticker'  => '[sticker]',
            default    => '',
        };

        if ($label !== '') {
            // A location's content IS its place name, which the label
            // already carries -- don't say it twice.
            $content = ($content !== '' && !str_contains($label, $content))
                ? $label . ' ' . $content
                : $label;
        }
        if ($content === '') {
            continue;
        }

        $history[] = ['role' => $role, 'content' => $content];
    }

    return $history;
}

/**
 * Pulls the draft text out of n8n's response.
 *
 * `draft` is what the documented workflow returns, but the AI Agent
 * node's own output field is commonly `output` or `text`, and it is easy
 * to wire the Respond node straight to it -- so those are accepted too
 * rather than failing on a detail the agent cannot see from the CRM.
 */
function extractDraft(string $body): ?string
{
    $decoded = json_decode($body, true);

    if (is_string($decoded)) {
        $decoded = trim($decoded);
        return $decoded !== '' ? $decoded : null;
    }

    // n8n often responds with a single-item array.
    if (is_array($decoded) && array_is_list($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
        $decoded = $decoded[0];
    }

    if (!is_array($decoded)) {
        // Not JSON at all: a plain-text Respond node body is still usable.
        $body = trim($body);
        return $body !== '' ? $body : null;
    }

    foreach (['draft', 'output', 'text', 'reply', 'message', 'content'] as $key) {
        if (isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
            return trim($decoded[$key]);
        }
    }

    return null;
}
