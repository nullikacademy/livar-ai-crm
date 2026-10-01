<?php
/**
 * api/draft.php
 *
 *   POST /api/draft.php   body: { "session_id": "...", "guidance": "..." }
 *   ->  { "success": true, "draft": "suggested reply text" }
 *
 * Drafts a reply with OpenAI. This replaced api/webhook.php, which
 * handed the job to n8n: the CRM already owns the conversation, so
 * owning the prompt and the model call too removes a whole service from
 * the path and a second place the prompt could drift.
 *
 * The draft is never persisted. It goes into the composer for the agent
 * to edit, and only becomes a message if they press Send -- at which
 * point api/send.php writes it, after WhatsApp confirms delivery.
 *
 * What the model is SHOWN lives in config/drafting.php, shared with
 * cron/run.php so an automatic reply reasons from exactly the context an
 * agent would have seen. This file is only the HTTP shape around it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/drafting.php';
// For fromMarkdown(). The draft is bound for WhatsApp, and WhatsApp's
// idea of bold is not the model's.
require_once __DIR__ . '/../config/whatsapp.php';

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

    $customer = getCustomer($sessionId);
    if ($customer === null) {
        json_error('Customer not found', 404);
    }

    $settings = getSettings();
    $payload  = buildDraftPayload(
        $customer,
        getMessages($sessionId, 0, DRAFT_HISTORY_LIMIT),
        $settings,
        input_str($data, 'guidance')
    );

    if (!$payload) {
        json_error('There is nothing to reply to yet.', 422);
    }

    // Models write Markdown whatever the prompt says, and WhatsApp bold
    // is one asterisk, not two -- so `**price**` reaches the customer as
    // a bold word wearing a spare asterisk at each end. Converted here
    // rather than left to the prompt, because a prompt is a request and
    // this needs to be a guarantee. The agent sees the corrected text in
    // the composer and can still edit it.
    $draft = WhatsApp::fromMarkdown(AI::client()->chat($payload, $settings['ai_model']));

    json_response(['success' => true, 'draft' => $draft]);
} catch (AIException $e) {
    // OpenAI's own wording is passed through: "the AI failed" cannot tell
    // an agent whether to top up a balance, fix a model name, or retry.
    error_log('[api/draft] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus >= 400 && $e->httpStatus < 600 ? $e->httpStatus : 502);
} catch (SupabaseException $e) {
    error_log('[api/draft] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    error_log('[api/draft] ' . $e->getMessage());
    json_error('Something went wrong while drafting the reply.', 500);
}
