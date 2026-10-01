<?php
/**
 * cron/run.php — the automation tick.
 *
 *   php /path/to/cron/run.php                       (how cron should call it)
 *   GET /cron/run.php?token=<AUTOMATION_TOKEN>      (only if a token is set)
 *
 * Answers customers who arrived from a Meta ad, without a person.
 *
 * WHY A TIMER AND NOT A WEBHOOK REFLEX. Replying the instant a message
 * lands is the obvious design and the wrong one: people send three
 * messages in a row -- "hello", "do you have 500ml", "what is the price"
 * -- and three separate answers to one thought is how a customer learns
 * they are talking to a machine. Running on a schedule lets a burst
 * finish, so one reply covers all of it. That is the whole reason this
 * file exists instead of a few lines in the webhook.
 *
 * WHAT IT WILL NOT DO. Everything that decides whether a robot may speak
 * lives in get_auto_reply_candidates() in sql/schema.sql, which is worth
 * reading before changing anything here. The short version: only ad
 * conversations, only when the newest message in the thread is the
 * customer's -- so a reply from ANYONE, agent or robot, stands it down --
 * only after the customer has been quiet a while, only inside the
 * 24-hour window, and only until the per-conversation allowance is gone.
 *
 * It is never fatal. One conversation that fails is logged and the run
 * carries on to the next; a run that fails entirely leaves the thread
 * exactly as it was, and the next tick picks it up again.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/drafting.php';
require_once __DIR__ . '/../config/whatsapp.php';
// For media_msg_type_for_mime() when attaching the catalogue. Already
// pulled in by drafting.php, named here because this file uses it.
require_once __DIR__ . '/../config/media.php';

/**
 * Most conversations one tick will answer.
 *
 * A bound on cost and on how long the run can take, not a throttle: the
 * query returns the longest-waiting first, so anything skipped is picked
 * up by the next tick three minutes later.
 */
const AUTOMATION_BATCH = 10;

/**
 * How the model says "and I am attaching the catalogue".
 *
 * A marker it writes on its own line, rather than this code reading the
 * reply and guessing: "I'll send you the catalog" and "we don't have a
 * catalog yet" both contain the word, and a keyword match would attach a
 * PDF to the second one. The marker is stripped before anything is sent,
 * so the customer never sees it.
 */
const CATALOG_MARKER = '[SEND_CATALOG]';

/**
 * The one instruction an automatic reply gets that a drafted one does not.
 *
 * It rides in the same slot as an agent's per-reply brief, which is last
 * in the payload, so it is the most recent thing the model reads. The
 * saved system prompt still sets the voice; this only adds what changes
 * when there is nobody to edit the result before it reaches a customer.
 */
const AUTOMATION_BRIEF = <<<'BRIEF'
This reply will be sent to the customer automatically, with no one
reading it first. So:

- Answer only from what is in this conversation and the customer's
  notes. Never state a price, a lead time, a stock level or a discount
  that does not already appear there.
- If the answer needs a person -- a custom quote, a complaint, a payment
  or anything you are unsure of -- say a colleague will follow up
  shortly, and do not improvise the detail.
- Keep it to a few lines, and end in a way that invites them to reply.
- Answer everything they just asked in ONE message. They may have sent
  several in a row; treat them as one question.
- If your reply tells the customer you are sending the catalogue, put
  [SEND_CATALOG] on a line of its own at the very end. The file is then
  attached for real. Never promise it without the marker, and never use
  the marker if you have not said you are sending it.
BRIEF;

guard_entry();

$result = run_automation();

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, $result . "\n");
    exit(0);
}

header('Content-Type: text/plain; charset=utf-8');
echo $result, "\n";

/**
 * Only cron, or somebody holding the token.
 *
 * This is the second endpoint in the app that cannot sit behind
 * require_auth() -- cron cannot log in -- so it is held to the same rule
 * as the webhook: an unguessable token, compared with hash_equals(), and
 * a 404 rather than a 403 on a mismatch, because a 403 confirms the URL
 * is real.
 *
 * Running it from the command line needs no token at all. Somebody with
 * a shell on the server can already read config.php; asking them for a
 * secret would be theatre, and it keeps the common setup simple.
 */
function guard_entry(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $expected = defined('AUTOMATION_TOKEN') ? (string) AUTOMATION_TOKEN : '';
    $given    = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';

    // No token configured means HTTP triggering is simply not switched
    // on. Failing closed matters here: the alternative is a URL that runs
    // the business's AI budget for anyone who finds it.
    if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
        error_log('[Automation] rejected a web call from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        http_response_code(404);
        echo "Not Found\n";
        exit;
    }
}

/**
 * One tick: find what is waiting, answer it, and record that we ran.
 */
function run_automation(): string
{
    $startedAt = gmdate('c');

    try {
        $settings = getSettings();
    } catch (Throwable $e) {
        error_log('[Automation] could not read settings: ' . $e->getMessage());
        return 'settings unavailable';
    }

    if (($settings['auto_reply_enabled'] ?? '0') !== '1') {
        // Still stamped. "Automation is off" and "cron is not running at
        // all" look identical on the settings page otherwise, and those
        // need very different fixing.
        record_run($startedAt, 'off');
        return 'automatic replies are switched off';
    }

    $quiet = max(0, (int) ($settings['auto_reply_quiet_seconds'] ?? 120));
    $max   = max(0, (int) ($settings['auto_reply_max'] ?? 2));

    if ($max === 0) {
        record_run($startedAt, 'off (limit is zero)');
        return 'the per-conversation limit is zero';
    }

    try {
        $candidates = getAutoReplyCandidates($quiet, $max, AUTOMATION_BATCH, $settings['auto_reply_since'] ?? '');
    } catch (Throwable $e) {
        error_log('[Automation] could not list candidates: ' . $e->getMessage());
        record_run($startedAt, 'failed: could not reach the database');
        return 'could not list conversations';
    }

    $sent    = 0;
    $skipped = 0;

    foreach ($candidates as $candidate) {
        try {
            if (answer_conversation($candidate['session_id'], $settings)) {
                $sent++;
            } else {
                $skipped++;
            }
        } catch (Throwable $e) {
            // One bad conversation must not cost the rest of the run.
            $skipped++;
            error_log('[Automation] ' . $candidate['session_id'] . ': ' . $e->getMessage());
        }
    }

    $summary = sprintf(
        '%d replied, %d skipped, %d waiting',
        $sent,
        $skipped,
        max(0, count($candidates) - $sent - $skipped)
    );

    record_run($startedAt, $summary);
    return $summary;
}

/**
 * Drafts and sends one automatic reply.
 *
 * The payload is built by the same buildDraftPayload() the Draft button
 * uses, so what the model sees here is what an agent would have seen --
 * the thread, the customer's details, the ad they came from, the photos,
 * the voice transcripts. An automatic reply reasoning from a different
 * context than the one shown to whoever reviews it afterwards would be
 * impossible to explain.
 *
 * @param array<string, string> $settings
 * @return bool true when a message actually went out
 */
function answer_conversation(string $sessionId, array $settings): bool
{
    $customer = getCustomer($sessionId);
    if ($customer === null) {
        return false;
    }

    $to = customerAddress($customer);
    if ($to === '') {
        error_log('[Automation] ' . $sessionId . ': no WhatsApp address');
        return false;
    }

    // Re-checked here even though the candidate query already did it.
    // Minutes pass between the query and this line, and the window is the
    // one condition WhatsApp enforces on its side -- a stale answer would
    // be a rejected send rather than a wrong one, but there is no reason
    // to spend a model call finding that out.
    if (!isWithin24hWindow($customer['last_inbound_at'] ?? null)) {
        return false;
    }

    $payload = buildDraftPayload(
        $customer,
        getMessages($sessionId, 0, DRAFT_HISTORY_LIMIT),
        $settings,
        AUTOMATION_BRIEF
    );

    if (!$payload) {
        return false;
    }

    $reply = trim(WhatsApp::fromMarkdown(AI::client()->chat($payload, $settings['ai_model'])));

    // The model's request to attach the catalogue, taken off the text
    // before anything is sent. Stripped even when the catalogue cannot
    // actually be sent, so a marker never reaches a customer.
    $wantsCatalog = str_contains($reply, CATALOG_MARKER);
    if ($wantsCatalog) {
        $reply = trim(str_replace(CATALOG_MARKER, '', $reply));
    }

    if ($reply === '') {
        error_log('[Automation] ' . $sessionId . ': the model returned nothing');
        return false;
    }

    $response = WhatsApp::client()->sendText($to, $reply);

    // Stored only after WhatsApp accepted it, and marked 'auto' so the
    // thread shows which replies nobody wrote. If this insert fails the
    // message is still delivered -- logged loudly, because the next tick
    // would then count one fewer automatic reply than really went out.
    $row = insertWhatsAppMessage($sessionId, [
        'direction'     => 'out',
        'wa_status'     => 'sent',
        'wa_source'     => 'auto',
        'msg_type'      => 'text',
        'content'       => $reply,
        'wa_message_id' => WhatsApp::messageIdFrom($response),
    ]);

    if ($row === null) {
        error_log('[Automation] ' . $sessionId . ': replied but could not store the row');
    }

    if ($wantsCatalog) {
        send_catalog_after($sessionId, $to);
    }

    return true;
}

/**
 * Attaches the catalogue to the reply that just promised it.
 *
 * Stored as 'auto_doc', NOT 'auto', so it does not spend one of the
 * conversation's automatic replies: the file is part of a reply, not
 * another one, and letting a PDF use up the allowance would cut the
 * conversation short exactly when it was going well.
 *
 * Never fatal. The customer has already been told the catalogue is
 * coming; failing to attach it is worth a loud log and a human picking
 * it up, not an exception that rolls back a message already delivered.
 */
function send_catalog_after(string $sessionId, string $to): void
{
    try {
        $catalog = getCatalogFile();
        if ($catalog === null) {
            error_log('[Automation] ' . $sessionId . ': the reply promised a catalogue, but none is uploaded');
            return;
        }

        // Once per conversation. A model that says "sending the
        // catalogue" in both of its replies should not send it twice.
        if (conversation_has_auto_catalog($sessionId)) {
            return;
        }

        $sendType = media_msg_type_for_mime($catalog['mime']);
        $mediaId  = WhatsApp::client()->uploadMedia($catalog['abs'], $catalog['mime']);
        $response = WhatsApp::client()->sendMedia(
            $to,
            $sendType,
            $mediaId,
            '',
            $sendType === 'document' ? $catalog['name'] : ''
        );

        insertWhatsAppMessage($sessionId, [
            'direction'     => 'out',
            'wa_status'     => 'sent',
            'wa_source'     => 'auto_doc',
            'msg_type'      => $sendType,
            'content'       => '',
            'media_path'    => $catalog['path'],
            'media_mime'    => $catalog['mime'],
            'media_size'    => $catalog['size'],
            'media_name'    => $catalog['name'],
            'wa_message_id' => WhatsApp::messageIdFrom($response),
        ]);
    } catch (Throwable $e) {
        error_log('[Automation] ' . $sessionId . ': could not attach the catalogue: ' . $e->getMessage());
    }
}


/**
 * Records that a run happened, and what it did.
 *
 * Best-effort on purpose: failing to write the stamp must not turn a run
 * that answered customers into a run that reports failure.
 */
function record_run(string $startedAt, string $result): void
{
    try {
        setSetting('automation_last_run', $startedAt);
        setSetting('automation_last_result', $result);
    } catch (Throwable $e) {
        error_log('[Automation] could not record the run: ' . $e->getMessage());
    }
}
