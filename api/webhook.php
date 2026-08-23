<?php
/**
 * api/webhook.php
 *
 * POST /api/webhook.php   body: { "session_id": "...", "message": "..." }
 *
 * Forwards the customer message to the n8n workflow, which:
 *   1. Inserts the human message into n8n_chat_history
 *   2. Runs the AI Agent
 *   3. Inserts the AI reply into n8n_chat_history
 *   4. Responds to the webhook with a small acknowledgement
 *
 * The CRM never writes to n8n_chat_history itself -- n8n is the single
 * writer, Supabase is the single source of truth. This endpoint's job is
 * just to call n8n and wait; the frontend always re-reads
 * n8n_chat_history from Supabase afterwards rather than trusting this
 * response body for the actual message content. We still try to surface
 * any text n8n sends back, purely as a best-effort fallback.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

try {
    $data      = read_json_body();
    $sessionId = input_str($data, 'session_id');
    $message   = input_str($data, 'message');

    if ($sessionId === '' || $message === '') {
        json_error('session_id and message are required', 422);
    }

    // The customer/human message is NOT saved by the CRM -- n8n inserts
    // both the human and AI turns itself (see api/webhook.php docblock
    // and README "n8n workflow" section). The CRM only sends the text
    // for the agent to act on and re-reads history afterward.
    $payload = json_encode([
        'session_id' => $sessionId,
        'message'    => $message,
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

    // Best-effort extraction of a preview reply, tolerant of several
    // reasonable n8n response shapes. Never fatal if this fails.
    $preview = null;
    $decoded = json_decode((string) $responseBody, true);
    if (is_array($decoded)) {
        $candidate = $decoded['reply']
            ?? $decoded['message']
            ?? $decoded['output']
            ?? $decoded['content']
            ?? null;
        if (is_string($candidate)) {
            $preview = $candidate;
        }
    }

    json_response([
        'success' => true,
        'preview' => $preview, // may be null; frontend re-fetches from DB regardless
    ]);
} catch (Throwable $e) {
    error_log('[api/webhook] ' . $e->getMessage());
    json_error('Something went wrong while generating the answer.', 500);
}
