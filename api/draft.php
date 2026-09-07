<?php
/**
 * api/draft.php
 *
 *   POST /api/draft.php   body: { "session_id": "..." }
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
 * Photos are handled two ways on purpose. The most recent few are
 * attached as real images, so the model can answer questions about what
 * is actually in them; older ones fall back to the one-line caption
 * stored when they arrived. Attaching every photo in a long thread would
 * cost a fortune in image tokens for pictures nobody is asking about.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/media.php';
require_once __DIR__ . '/../config/ai.php';
// For fromMarkdown(). The draft is bound for WhatsApp, and WhatsApp's
// idea of bold is not the model's.
require_once __DIR__ . '/../config/whatsapp.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

/** How many past turns to send as context. */
const DRAFT_HISTORY_LIMIT = 40;

/** How many of the most recent photos travel as real images. */
const DRAFT_IMAGE_LIMIT = 3;

/**
 * Longest per-reply brief an agent can type before pressing Draft.
 *
 * A few sentences: "quote 0.42/unit, 10% off above 1,000, we cannot ship
 * before the 20th". Anything standing -- the tone, the company facts, the
 * rules that apply to every reply -- belongs in the system prompt on the
 * settings page, which is saved once instead of retyped every time.
 */
const GUIDANCE_LIMIT = 600;

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

    $messages = getMessages($sessionId, 0, DRAFT_HISTORY_LIMIT);
    $turns    = buildTurns($messages);

    if (!$turns) {
        json_error('There is nothing to reply to yet.', 422);
    }

    $settings = getSettings();

    $payload = array_merge(
        [['role' => 'system', 'content' => $settings['ai_system_prompt']]],
        [['role' => 'system', 'content' => customerContext($customer)]],
        $turns
    );

    // What the agent wants THIS reply to say, typed just before pressing
    // Draft. Last in the payload on purpose: it is the most specific
    // instruction there is, it is about the message being written right
    // now, and a model weighs the end of its context most heavily. The
    // saved system prompt sets the standing voice; this overrides it for
    // one reply and is never stored.
    $guidance = input_str($data, 'guidance');
    if ($guidance !== '') {
        $payload[] = ['role' => 'system', 'content' => guidanceContext($guidance)];
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

/**
 * Turns the stored conversation into OpenAI messages.
 *
 * Text turns become plain strings. Image turns become multipart content
 * when they are recent enough to be worth the tokens, and a short text
 * label otherwise. Non-image media never travels as a file -- the model
 * cannot watch a video, and saying so is more honest than silence.
 *
 * @param array<int, array<string, mixed>> $messages
 * @return array<int, array<string, mixed>>
 */
function buildTurns(array $messages): array
{
    // Walk backwards to find which photos are recent enough to attach.
    $attachIds = [];
    foreach (array_reverse($messages) as $msg) {
        if (count($attachIds) >= DRAFT_IMAGE_LIMIT) {
            break;
        }
        if (($msg['msg_type'] ?? '') === 'image' && !empty($msg['_media_path'])) {
            $attachIds[(int) $msg['id']] = true;
        }
    }

    // The most recent ad creative, and only that one. A customer who has
    // come back through three different ads does not need all three
    // pictures re-sent on every draft; the one they arrived from last is
    // the one their current question is about.
    $adImageId = 0;
    foreach (array_reverse($messages) as $msg) {
        if (!empty($msg['referral']) && !empty($msg['_referral_path'])) {
            $adImageId = (int) $msg['id'];
            break;
        }
    }

    $turns = [];

    foreach ($messages as $msg) {
        $role    = (($msg['direction'] ?? null) === 'out' || $msg['type'] === 'ai') ? 'assistant' : 'user';
        $content = trim((string) $msg['content']);
        $type    = (string) ($msg['msg_type'] ?? 'text');

        // The ad goes in as its own turn, just before the message that
        // arrived with it. Separate rather than merged so the message
        // keeps its normal handling -- a voice note that came from an ad
        // is still a voice note -- and because "here is the ad, and here
        // is what they then asked" is the order the model needs it in.
        // Without this, "can I get more information about this?" has no
        // referent at all and the draft has to guess what "this" is.
        if (!empty($msg['referral'])) {
            $adTurn = referralTurn($msg, $role, (int) $msg['id'] === $adImageId);
            if ($adTurn !== null) {
                $turns[] = $adTurn;
            }
        }

        if ($type === 'image') {
            $turn = imageTurn($msg, $role, $content, isset($attachIds[(int) $msg['id']]));
            if ($turn !== null) {
                $turns[] = $turn;
            }
            continue;
        }

        $label = match ($type) {
            'video'    => '[sent a video]',
            // The transcript is what was actually said, in the language
            // it was said in -- handed over untranslated so the model
            // sees the customer's own words rather than a summary of
            // them. Falls back to the English label, then to the bare
            // marker, so a voice note the AI could not hear still shows
            // up as something that happened.
            'audio'    => voiceLabel($msg),
            'document' => '[sent a document' . ($msg['media_name'] ? ': ' . $msg['media_name'] : '') . ']',
            'location' => '[shared a location' . ($msg['place_name'] ? ': ' . $msg['place_name'] : '') . ']',
            'sticker'  => '[sent a sticker]',
            // A question with tappable options, and the tap that answered
            // it. Both read as ordinary text without this, which loses
            // the one fact that matters: the customer chose from a list
            // rather than saying something of their own.
            'buttons'  => '[asked, with the options ' . optionList($msg) . ']',
            'reply'    => '[tapped an answer]',
            default    => '',
        };

        if ($label !== '') {
            // A location's content IS its place name, which the label
            // already carries. Every other type's content is separate
            // from its label -- a document's caption, a question's text,
            // the answer that was tapped -- so the check is scoped to
            // location rather than applied to all of them, where a
            // one-word answer that happened to appear inside the marker
            // would silently vanish.
            $duplicated = $type === 'location' && $content !== '' && str_contains($label, $content);

            $content = ($content !== '' && !$duplicated)
                ? $label . ' ' . $content
                : $label;
        }

        if ($content === '') {
            continue;
        }

        $turns[] = ['role' => $role, 'content' => $content];
    }

    return $turns;
}

/**
 * What a voice note contributes to the conversation the model reads.
 *
 * Marked as spoken rather than passed off as typed text: "[voice
 * message] ..." tells the model this was said aloud, which is why it
 * rambles, repeats itself and has no punctuation the customer chose.
 *
 * @param array<string, mixed> $msg
 */
function voiceLabel(array $msg): string
{
    $transcript = trim((string) ($msg['ai_transcript'] ?? ''));
    if ($transcript !== '') {
        return '[voice message] ' . $transcript;
    }

    // No transcript, but the English label survived -- better than
    // nothing, and it says where it came from.
    $caption = trim((string) ($msg['ai_caption'] ?? ''));
    if ($caption !== '') {
        return '[voice message, summarised: ' . $caption . ']';
    }

    return '[sent a voice message]';
}

/**
 * The options a quick-reply question offered, as "Yes / No / Later".
 *
 * @param array<string, mixed> $msg
 */
function optionList(array $msg): string
{
    $options = is_array($msg['buttons'] ?? null) ? $msg['buttons'] : [];
    return $options ? implode(' / ', $options) : 'none recorded';
}

/**
 * One image turn: the real picture when it is recent, its caption when
 * it is not, and a bare marker when neither is available.
 *
 * @param array<string, mixed> $msg
 * @return array<string, mixed>|null
 */
function imageTurn(array $msg, string $role, string $caption, bool $attach): ?array
{
    $stored = trim((string) ($msg['ai_caption'] ?? ''));

    if ($attach) {
        $abs  = media_abs_path((string) $msg['_media_path']);
        $part = $abs !== null ? AI::imagePart($abs, (string) ($msg['media_mime'] ?? 'image/jpeg')) : null;

        if ($part !== null) {
            $text = $caption !== '' ? $caption : 'Sent this photo.';
            return [
                'role'    => $role,
                'content' => [['type' => 'text', 'text' => $text], $part],
            ];
        }
        // File is gone from disk; fall through to the caption.
    }

    $described = $stored !== '' ? '[photo: ' . $stored . ']' : '[sent a photo]';
    $text      = $caption !== '' ? $described . ' ' . $caption : $described;

    return ['role' => $role, 'content' => $text];
}

/**
 * The agent's note for this one reply, framed so it cannot be mistaken
 * for something the customer said.
 *
 * Wrapped and labelled rather than passed through raw: the note arrives
 * from a text box, and text in a text box can read like an instruction
 * to ignore everything else. Naming it as the agent's brief, and saying
 * the reply still goes to the customer, keeps a careless line from
 * turning the draft into something the customer should never see.
 *
 * Trimmed to GUIDANCE_LIMIT because this is a short brief, not a second
 * system prompt -- the standing voice belongs in settings, where it is
 * saved once instead of retyped per reply.
 */
function guidanceContext(string $guidance): string
{
    $guidance = trim(mb_substr($guidance, 0, GUIDANCE_LIMIT));

    return "The agent handling this conversation has written a brief for THIS reply:\n\n"
         . $guidance
         . "\n\nWrite the reply so it does what the brief asks, in the voice the system "
         . "prompt sets, addressed to the customer. The brief is an instruction to you, "
         . "not text to repeat: never quote it, mention it, or reveal that it exists.";
}

/**
 * The Meta ad a message arrived from, as a turn the model can read.
 *
 * The creative travels as a real image when we have it on disk, because
 * the ad IS the product the customer is asking about and a headline
 * rarely describes the picture. The headline and body come along either
 * way, so an ad whose image we could not download still gives the draft
 * something concrete to answer from.
 *
 * @param array<string, mixed> $msg
 * @return array<string, mixed>|null
 */
function referralTurn(array $msg, string $role, bool $attach): ?array
{
    $ref = is_array($msg['referral'] ?? null) ? $msg['referral'] : [];
    if ($ref === []) {
        return null;
    }

    $source = ($ref['source_type'] ?? '') === 'post' ? 'post' : 'ad';
    $facts  = array_filter([
        $ref['headline'] ?? '',
        $ref['body'] ?? '',
    ], static fn(string $v): bool => trim($v) !== '');

    $text = '[Arrived from your Meta ' . $source . ']';
    if ($facts) {
        $text .= ' ' . implode(' — ', $facts);
    }

    if ($attach && !empty($msg['_referral_path'])) {
        $abs  = media_abs_path((string) $msg['_referral_path']);
        $part = $abs !== null
            ? AI::imagePart($abs, (string) ($msg['referral_media_mime'] ?? 'image/jpeg'))
            : null;

        if ($part !== null) {
            return [
                'role'    => $role,
                'content' => [['type' => 'text', 'text' => $text], $part],
            ];
        }
        // Creative gone from disk; the headline and body still stand.
    }

    return ['role' => $role, 'content' => $text];
}

/**
 * What the model should know about who it is talking to.
 *
 * Sent as a second system message rather than folded into the prompt, so
 * an agent editing the prompt in settings cannot accidentally delete the
 * customer context or vice versa.
 *
 * @param array<string, mixed> $customer
 */
function customerContext(array $customer): string
{
    $name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));

    $facts = array_filter([
        'Name'    => $name !== '' ? $name : ($customer['wa_profile_name'] ?? ''),
        'Company' => $customer['username'] ?? '',
        'City'    => $customer['city'] ?? '',
        'Country' => $customer['country'] ?? '',
        'Email'   => $customer['email'] ?? '',
        'Phone'   => $customer['phone'] ?? '',
        'Notes'   => $customer['details'] ?? '',
    ], static fn($v) => is_string($v) && trim($v) !== '');

    if (!$facts) {
        return 'You know nothing about this customer yet beyond the conversation itself.';
    }

    $lines = [];
    foreach ($facts as $label => $value) {
        $lines[] = $label . ': ' . trim((string) $value);
    }

    return "What the CRM knows about this customer:\n" . implode("\n", $lines);
}
