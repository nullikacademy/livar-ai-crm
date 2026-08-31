<?php
/**
 * config/db_functions.php
 *
 * Reusable data access layer, now backed by the Supabase REST API
 * (see config/database.php) instead of a native PDO connection. Every
 * route in /api includes this file instead of talking to Supabase(Client)
 * directly, so query/filter logic stays in one place.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/countries.php';
// getCatalogFile() has to resolve a stored path safely, and the rules
// for that are written once, in media.php.
require_once __DIR__ . '/media.php';

/**
 * The editable customer profile fields.
 *
 * createCustomer()/updateCustomer() only write keys listed here, so a
 * column missing from this list is silently unwritable. `last_inbound_at`
 * is deliberately absent: it is owned by the inbound webhook (see
 * touchLastInbound()) and must never be settable from the details form,
 * since it is what gates outbound sending. So is `avatar_path`, which is
 * a location on disk -- api/avatar.php writes it after storing a file it
 * validated itself, and nothing a browser sends ever becomes a path.
 */
const CUSTOMER_PROFILE_FIELDS = [
    'first_name', 'last_name', 'username', 'phone',
    'country', 'email', 'city', 'address', 'tax_id', 'details',
    'wa_id', 'wa_profile_name', 'label',
];

/**
 * The labels a customer can carry, and how each is written on screen.
 *
 * A closed set rather than free text: the whole point is to be able to
 * tell at a glance which conversations are first contacts, and that
 * stops working the moment "New"/"new "/"NEW customer" are three
 * different labels. A customer created by the inbound webhook starts as
 * 'new'; an agent moves them to 'old' (or clears it) from the details
 * panel.
 */
const CUSTOMER_LABELS = [
    'new' => 'New customer',
    'old' => 'Old customer',
];

/**
 * Message types the CRM stores media on disk for.
 */
const MEDIA_MSG_TYPES = ['image', 'video', 'audio', 'document', 'sticker'];

/**
 * Reduces a WhatsApp id / phone number to digits.
 *
 * PostgREST parses `,` `.` `:` `(` `)` inside a filter value as filter
 * syntax, and http_build_query only URL-encodes -- it does not escape
 * them. A `+34 600 111 222` reaching an `eq.` filter unescaped would be
 * misparsed, so every wa_id is normalised to bare digits on the way in
 * and on the way out. 360dialog reports wa_id in exactly this form.
 */
function normalizeWaId(string $waId): string
{
    return preg_replace('/\D+/', '', $waId) ?? '';
}

/**
 * The deterministic session_id for a WhatsApp conversation.
 *
 * Deriving it from the number rather than generating a random one is what
 * lets two concurrent webhook deliveries for the same new number produce
 * the same row instead of two.
 */
function waSessionId(string $waId): string
{
    return 'wa_' . normalizeWaId($waId);
}

/**
 * Returns a page of customers with a last-message preview, ordered by
 * most recent activity first. Backed by the get_customers_with_preview()
 * Postgres function (see sql/schema.sql) so the join + search + paging
 * all happen in one round trip instead of N+1 REST calls.
 *
 * @return array{rows: array<int, array<string, mixed>>, hasMore: bool}
 */
function getCustomers(int $limit = CUSTOMERS_PAGE_SIZE, int $offset = 0, string $search = ''): array
{
    $sb = Supabase::client();

    // Ask for one extra row so we know whether another page exists.
    $rows = $sb->rpc('get_customers_with_preview', [
        'p_search' => $search,
        'p_limit'  => $limit + 1,
        'p_offset' => $offset,
    ]);

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }

    // Normalize field names to match what the frontend expects
    // (created_at, last_message, last_message_type, ...).
    $rows = array_map(static function (array $row): array {
        unset($row['total_count'], $row['last_activity_id']);
        return $row;
    }, $rows);

    return ['rows' => $rows, 'hasMore' => $hasMore];
}

/**
 * Fetches a single customer by session_id.
 */
function getCustomer(string $sessionId): ?array
{
    $sb     = Supabase::client();
    $result = $sb->get('livar_customer', [
        'session_id' => 'eq.' . $sessionId,
        'select'     => '*',
        'limit'      => '1',
    ]);

    return $result['rows'][0] ?? null;
}

/**
 * Reduces a label to one of CUSTOMER_LABELS, or null.
 *
 * Anything unrecognised becomes null rather than an error: a label is a
 * convenience, and refusing to save a customer's address because their
 * label was odd would be the wrong trade.
 */
function normalizeCustomerLabel(mixed $label): ?string
{
    if (!is_string($label)) {
        return null;
    }

    $label = strtolower(trim($label));
    return isset(CUSTOMER_LABELS[$label]) ? $label : null;
}

/**
 * Shapes a customer row for the browser.
 *
 * Two things happen here, and both are the reason this exists rather
 * than handing the row straight out:
 *
 *  - `avatar_path` is a location inside storage/ and never leaves the
 *    server. It becomes an api/avatar.php URL instead, carrying a short
 *    fingerprint of the path so replacing a photo busts the cache.
 *  - The country is DERIVED from the number on every read rather than
 *    stored, so a customer whose row predates this still gets a flag,
 *    and correcting a phone number corrects the flag with it.
 *
 * @param array<string, mixed> $customer
 * @return array<string, mixed>
 */
function customerForBrowser(array $customer): array
{
    $avatarPath = (string) ($customer['avatar_path'] ?? '');
    unset($customer['avatar_path']);

    $customer['avatar_url'] = $avatarPath !== ''
        ? 'api/avatar.php?session_id=' . rawurlencode((string) ($customer['session_id'] ?? ''))
          . '&v=' . substr(md5($avatarPath), 0, 8)
        : null;

    $country = country_for_customer($customer);
    $customer['country_code'] = $country['code'];
    $customer['country_flag'] = $country['flag'];
    $customer['country_name'] = $country['name'];

    return $customer;
}

/**
 * Creates a new customer with a freshly generated session_id.
 *
 * @param array<string, mixed> $data
 */
function createCustomer(array $data): array
{
    $sb        = Supabase::client();
    $sessionId = $data['session_id'] ?? generate_session_id();

    // '' rather than null so the loop below stores it as null anyway; a
    // label the browser made up must never reach the column.
    if (array_key_exists('label', $data)) {
        $data['label'] = normalizeCustomerLabel($data['label']) ?? '';
    }

    $payload = ['session_id' => $sessionId];
    foreach (CUSTOMER_PROFILE_FIELDS as $field) {
        $value = $data[$field] ?? null;
        $payload[$field] = ($value === '' ? null : $value);
    }

    $rows = $sb->post('livar_customer', $payload);
    return $rows[0] ?? $payload;
}

/**
 * Updates the editable profile fields of an existing customer.
 * Only keys present in $data are written (partial updates supported).
 */
function updateCustomer(string $sessionId, array $data): ?array
{
    $sb      = Supabase::client();
    $payload = [];

    if (array_key_exists('label', $data)) {
        $data['label'] = normalizeCustomerLabel($data['label']) ?? '';
    }

    foreach (CUSTOMER_PROFILE_FIELDS as $field) {
        if (array_key_exists($field, $data)) {
            $payload[$field] = ($data[$field] === '' ? null : $data[$field]);
        }
    }

    if (!$payload) {
        return getCustomer($sessionId);
    }

    $rows = $sb->patch('livar_customer', ['session_id' => 'eq.' . $sessionId], $payload);
    return $rows[0] ?? null;
}

/**
 * Returns chat history for a session, oldest first.
 *
 * $sinceId powers incremental polling: pass the highest id already on
 * screen and only newer rows come back. $limit bounds the response --
 * before WhatsApp, this fetched an entire conversation on every call,
 * which with media rows and an 8s poll is far too much.
 *
 * The two cases order differently on purpose. An initial load (sinceId 0)
 * wants the most RECENT window of a long thread, so it reads descending
 * and flips; a poll wants everything after a known point with no holes in
 * it, so it reads ascending and simply catches up on the next tick if a
 * burst exceeded the limit.
 */
function getMessages(string $sessionId, int $sinceId = 0, int $limit = 200): array
{
    $sb    = Supabase::client();
    $limit = max(1, min(500, $limit));

    $query = [
        'session_id' => 'eq.' . $sessionId,
        'select'     => 'id,session_id,message,created_at,direction,wa_status,msg_type,'
                      . 'media_path,media_mime,media_size,media_name,ai_caption,'
                      . 'wa_buttons,wa_template,wa_source,'
                      . 'latitude,longitude,place_name,place_address',
        'limit'      => (string) $limit,
    ];

    if ($sinceId > 0) {
        $query['id']    = 'gt.' . $sinceId;
        $query['order'] = 'id.asc';
        $reverse        = false;
    } else {
        $query['order'] = 'id.desc';
        $reverse        = true;
    }

    $result = $sb->get('n8n_chat_history', $query);
    $rows   = $result['rows'];
    if ($reverse) {
        $rows = array_reverse($rows);
    }

    $messages = [];
    foreach ($rows as $row) {
        $decoded = is_string($row['message']) ? json_decode($row['message'], true) : $row['message'];
        if (!is_array($decoded) || !isset($decoded['type'])) {
            continue; // Ignore malformed/unknown rows rather than break the chat.
        }

        $msgType = $row['msg_type'] ?? null;
        $id      = (int) $row['id'];

        $messages[] = [
            'id'      => $id,
            'type'    => $decoded['type'],
            'content' => $decoded['content'] ?? '',

            // Everything below is null on legacy rows written before the
            // WhatsApp columns existed; the frontend falls back to `type`.
            'created_at'    => $row['created_at'] ?? null,
            'direction'     => $row['direction'] ?? null,
            'wa_status'     => $row['wa_status'] ?? null,
            'msg_type'      => $msgType,

            // Never the path on disk. api/media.php re-checks auth, and
            // lazily downloads from 360dialog if media_path is still null
            // (which happens when the webhook could not defer its work).
            'media_url'     => in_array($msgType, MEDIA_MSG_TYPES, true) ? 'api/media.php?id=' . $id : null,
            'media_mime'    => $row['media_mime'] ?? null,
            'media_size'    => isset($row['media_size']) ? (int) $row['media_size'] : null,
            'media_name'    => $row['media_name'] ?? null,
            'ai_caption'    => $row['ai_caption'] ?? null,

            // The option labels of a quick-reply question, so the thread
            // shows what the customer was actually offered rather than
            // just the question text.
            'buttons'       => decodeButtonLabels($row['wa_buttons'] ?? null),
            'wa_template'   => $row['wa_template'] ?? null,

            // 'app' for a reply typed on the phone and mirrored back by
            // the coexistence echo webhook; null for one this CRM sent.
            'wa_source'     => $row['wa_source'] ?? null,

            // Server-side only: the draft builder needs the file on disk
            // to attach a real image. api/messages.php strips this before
            // it reaches the browser -- a disk path must never leave here.
            '_media_path'   => $row['media_path'] ?? null,

            'latitude'      => isset($row['latitude']) ? (float) $row['latitude'] : null,
            'longitude'     => isset($row['longitude']) ? (float) $row['longitude'] : null,
            'place_name'    => $row['place_name'] ?? null,
            'place_address' => $row['place_address'] ?? null,
        ];
    }

    return $messages;
}

/**
 * Reads the stored quick-reply labels back into a list of strings.
 *
 * Stored as JSON in one column rather than a side table: they are three
 * short strings that are only ever read together with their own row,
 * and a join for that would be all cost and no benefit. A row written
 * before the column existed, or one holding something unreadable, comes
 * back as an empty list -- the question still renders, just without its
 * options.
 *
 * @return array<int, string>
 */
function decodeButtonLabels(mixed $stored): array
{
    if (is_array($stored)) {
        $decoded = $stored;
    } elseif (is_string($stored) && $stored !== '') {
        $decoded = json_decode($stored, true);
    } else {
        return [];
    }

    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_map(
        static fn(mixed $label): string => mb_substr((string) $label, 0, 40),
        array_filter($decoded, static fn(mixed $label): bool => is_string($label) && trim($label) !== '')
    ));
}

/**
 * Fetches a single chat row by primary key, unparsed. Used by
 * api/media.php, which needs media_path/media_mime rather than the
 * frontend-shaped payload getMessages() builds.
 */
function getMessageRow(int $id): ?array
{
    $sb     = Supabase::client();
    $result = $sb->get('n8n_chat_history', [
        'id'     => 'eq.' . $id,
        'select' => '*',
        'limit'  => '1',
    ]);

    return $result['rows'][0] ?? null;
}

/**
 * Inserts a row shaped like n8n's LangChain message history format.
 */
function insertChatMessage(string $sessionId, string $type, string $content): array
{
    $sb   = Supabase::client();
    $rows = $sb->post('n8n_chat_history', [
        'session_id' => $sessionId,
        'message'    => ['type' => $type, 'content' => $content],
    ]);

    return $rows[0] ?? ['session_id' => $sessionId, 'message' => ['type' => $type, 'content' => $content]];
}

/**
 * Inserts a customer ("human") message into the chat history.
 */
function insertHumanMessage(string $sessionId, string $content): array
{
    return insertChatMessage($sessionId, 'human', $content);
}

/**
 * Inserts an AI-generated reply into the chat history.
 */
function insertAIMessage(string $sessionId, string $content): array
{
    return insertChatMessage($sessionId, 'ai', $content);
}

// ---------------------------------------------------------------------
// WhatsApp
// ---------------------------------------------------------------------

/**
 * Looks a customer up by their WhatsApp number.
 */
function getCustomerByWaId(string $waId): ?array
{
    $waId = normalizeWaId($waId);
    if ($waId === '') {
        return null;
    }

    $sb     = Supabase::client();
    $result = $sb->get('livar_customer', [
        'wa_id'  => 'eq.' . $waId,
        'select' => '*',
        'limit'  => '1',
    ]);

    return $result['rows'][0] ?? null;
}

/**
 * Returns the customer for a WhatsApp number, creating one on first
 * contact with whatever profile name WhatsApp reported.
 *
 * The read comes first so a number an agent already linked to a
 * hand-created customer keeps that customer (and its own session_id)
 * untouched. Only a genuinely unknown number reaches the upsert, which
 * resolves on the unique wa_id index -- so two webhook deliveries racing
 * on a first message converge on one row instead of one of them failing
 * with a 409.
 */
function getOrCreateCustomerByWaId(string $waId, string $profileName = ''): array
{
    $waId = normalizeWaId($waId);
    if ($waId === '') {
        throw new SupabaseException('A WhatsApp message arrived without a usable sender id.', 422);
    }

    $existing = getCustomerByWaId($waId);
    if ($existing !== null) {
        return $existing;
    }

    $payload = [
        'session_id' => waSessionId($waId),
        'wa_id'      => $waId,
        'phone'      => '+' . $waId,
        // Nobody has spoken to this number before, which is the one
        // moment the CRM can say that for certain. An agent can move
        // them to 'old' later; guessing it back afterwards is impossible.
        'label'      => 'new',
    ];
    // Only send a name we actually have -- merge-duplicates would
    // otherwise blank out a name the racing insert just stored.
    if ($profileName !== '') {
        $payload['wa_profile_name'] = $profileName;
    }
    // The country is in the number, so there is no reason to make an
    // agent type it. Only ever written here, on the insert: a later
    // correction by hand must not be overwritten by the prefix table.
    $country = country_name_for_phone($waId);
    if ($country !== null) {
        $payload['country'] = $country;
    }

    $sb   = Supabase::client();
    $rows = $sb->upsert('livar_customer', $payload, 'wa_id');

    // A merge upsert always returns the row, but re-read defensively
    // rather than hand a half-built payload to the rest of the webhook.
    return $rows[0] ?? getCustomerByWaId($waId) ?? $payload;
}

/**
 * Stamps the customer's last inbound message time. This single column is
 * what the 24h free-form reply window is computed from, both for the
 * header indicator and for the server-side check in api/send.php.
 */
function touchLastInbound(string $sessionId, ?string $at = null): void
{
    $sb = Supabase::client();
    $sb->patch(
        'livar_customer',
        ['session_id' => 'eq.' . $sessionId],
        ['last_inbound_at' => $at ?? gmdate('c')]
    );
}

/**
 * Inserts a WhatsApp message row.
 *
 * Returns null when a row with the same wa_message_id already exists.
 * That is 360dialog re-delivering a batch, not an error -- the webhook
 * treats null as "already handled, skip".
 *
 * `message` keeps the canonical LangChain { type, content } shape so
 * everything that already reads this table keeps working; direction and
 * the media fields are real columns beside it.
 *
 * @param array<string, mixed> $fields
 */
function insertWhatsAppMessage(string $sessionId, array $fields): ?array
{
    $direction = ($fields['direction'] ?? 'in') === 'out' ? 'out' : 'in';
    $content   = (string) ($fields['content'] ?? '');

    $payload = [
        'session_id' => $sessionId,
        // 'human' = from the customer, 'ai' = from us. Same convention
        // the pre-WhatsApp rows use, so alignment still works for both.
        'message'    => ['type' => $direction === 'in' ? 'human' : 'ai', 'content' => $content],
        'direction'  => $direction,
        'msg_type'   => $fields['msg_type'] ?? 'text',
    ];

    foreach ([
        'wa_message_id', 'wa_status', 'wa_error', 'wa_media_id',
        'wa_buttons', 'wa_template', 'wa_source',
        'media_path', 'media_mime', 'media_size', 'media_name',
        'latitude', 'longitude', 'place_name', 'place_address',
    ] as $optional) {
        if (isset($fields[$optional]) && $fields[$optional] !== '') {
            $payload[$optional] = $fields[$optional];
        }
    }

    if (isset($fields['created_at'])) {
        $payload['created_at'] = $fields['created_at'];
    }

    $sb = Supabase::client();

    if (!isset($payload['wa_message_id'])) {
        $rows = $sb->post('n8n_chat_history', $payload);
        return $rows[0] ?? null;
    }

    // ignore-duplicates makes the unique index a no-op instead of a 409,
    // so a retry returns an empty representation rather than throwing.
    $rows = $sb->upsert('n8n_chat_history', $payload, 'wa_message_id', true);
    return $rows[0] ?? null;
}

/**
 * How far along a delivery status is. Used to stop an out-of-order
 * webhook ('delivered' arriving after 'read') from walking the status
 * backwards. A failure always wins.
 */
const WA_STATUS_RANK = ['sent' => 1, 'delivered' => 2, 'read' => 3];

/**
 * Applies a statuses[] update from the webhook to the row it belongs to.
 */
function updateMessageStatus(string $waMessageId, string $status, string $error = ''): void
{
    if ($waMessageId === '' || $status === '') {
        return;
    }

    $payload = ['wa_status' => $status];
    if ($error !== '') {
        $payload['wa_error'] = $error;
    }

    $query = ['wa_message_id' => 'eq.' . $waMessageId];

    // Only guard the ordered statuses; 'failed' must always be able to
    // overwrite whatever is there.
    $rank = WA_STATUS_RANK[$status] ?? null;
    if ($rank !== null) {
        $lower = [];
        foreach (WA_STATUS_RANK as $name => $r) {
            if ($r < $rank) {
                $lower[] = $name;
            }
        }
        // "currently unset, or currently at a lower rank than this one".
        $query['or'] = $lower
            ? '(wa_status.is.null,wa_status.in.(' . implode(',', $lower) . '))'
            : '(wa_status.is.null)';
    }

    $sb = Supabase::client();
    $sb->patch('n8n_chat_history', $query, $payload);
}

/**
 * Rewrites the text of a message that was edited after it was sent.
 *
 * Reached only from the coexistence echo webhook: WhatsApp lets a
 * business edit a message from the app for a few minutes after sending
 * it, and a thread showing the version the customer no longer sees is
 * worse than one showing nothing.
 *
 * Read-then-write rather than a single patch, because `message` is a
 * jsonb object and PostgREST would replace the whole thing -- losing the
 * LangChain `type` beside the content that everything reading this table
 * still depends on.
 */
function setMessageContent(string $waMessageId, string $content): void
{
    if ($waMessageId === '' || $content === '') {
        return;
    }

    $sb     = Supabase::client();
    $result = $sb->get('n8n_chat_history', [
        'wa_message_id' => 'eq.' . $waMessageId,
        'select'        => 'id,message',
        'limit'         => '1',
    ]);

    $row = $result['rows'][0] ?? null;
    if ($row === null) {
        error_log('[WhatsApp] an edit arrived for a message that is not in the thread: ' . $waMessageId);
        return;
    }

    $message = is_string($row['message']) ? json_decode($row['message'], true) : $row['message'];
    if (!is_array($message)) {
        return;
    }

    $message['content'] = $content;
    $sb->patch('n8n_chat_history', ['id' => 'eq.' . (int) $row['id']], ['message' => $message]);
}

/**
 * Records a media file that has been downloaded to disk.
 */
function setMessageMedia(int $id, string $path, string $mime, int $size): void
{
    $sb = Supabase::client();
    $sb->patch('n8n_chat_history', ['id' => 'eq.' . $id], [
        'media_path' => $path,
        'media_mime' => $mime,
        'media_size' => $size,
    ]);
}

// ---------------------------------------------------------------------
// Editable settings (livar_settings)
// ---------------------------------------------------------------------

/**
 * Defaults for anything the settings page can edit.
 *
 * These are the values a fresh install runs on, so the CRM works before
 * anyone opens the settings page. A row in livar_settings overrides one.
 */
const SETTING_DEFAULTS = [
    'ai_model'         => 'gpt-4o-mini',
    'ai_system_prompt' => <<<'PROMPT'
You are a sales and support agent for LiVAR Packaging Solutions, replying to
customers on WhatsApp.

Write the reply itself and nothing else — no preamble, no "here is a draft",
no sign-off block. What you write goes straight into an agent's composer for
them to edit and send.

- Match the customer's language.
- Be brief and concrete. WhatsApp is not email.
- Give prices, sizes and lead times only when they appear in the conversation
  or the customer's notes. Never invent a figure.
- If something needs a person — a custom quote, a complaint, a payment issue —
  say you are checking with the team rather than guessing.
PROMPT,

    // The catalog an agent can send in one click. Written by
    // api/catalog.php, which stores the file itself; never by
    // api/settings.php -- see SETTING_AGENT_EDITABLE below.
    'catalog_path' => '',
    'catalog_name' => '',
    'catalog_mime' => '',
];

/**
 * The settings api/settings.php will WRITE.
 *
 * SETTING_DEFAULTS is still the whole set of keys that exist, and
 * nothing outside it can be read or written -- but a key being editable
 * from the settings form is a narrower thing. `catalog_path` is a
 * location inside storage/, and an endpoint that took one from a JSON
 * body would be handing the browser a way to name a file on disk. The
 * catalog is uploaded through api/catalog.php instead, which stores the
 * bytes and derives the path itself.
 */
const SETTING_AGENT_EDITABLE = ['ai_model', 'ai_system_prompt'];

/**
 * Reads every editable setting, with defaults filled in.
 *
 * One request per page load rather than one per key: there are only a
 * handful, and the settings page wants them all anyway.
 *
 * @return array<string, string>
 */
function getSettings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $values = SETTING_DEFAULTS;

    try {
        $result = Supabase::client()->get('livar_settings', ['select' => 'key,value']);
        foreach ($result['rows'] as $row) {
            $key = (string) ($row['key'] ?? '');
            // A stored empty string means "reset to default", not "blank".
            if ($key !== '' && isset($values[$key]) && trim((string) $row['value']) !== '') {
                $values[$key] = (string) $row['value'];
            }
        }
    } catch (SupabaseException $e) {
        // Missing table on an un-migrated database, or Supabase down. The
        // defaults still let drafting work, so this must not be fatal.
        error_log('[Supabase] could not read livar_settings, using defaults: ' . $e->getMessage());
    }

    return $cache = $values;
}

/**
 * Reads one setting.
 */
function getSetting(string $key): string
{
    return getSettings()[$key] ?? '';
}

/**
 * Writes one setting. An empty value deletes the override, so the
 * default in SETTING_DEFAULTS applies again.
 */
function setSetting(string $key, string $value): void
{
    if (!array_key_exists($key, SETTING_DEFAULTS)) {
        throw new InvalidArgumentException('Unknown setting: ' . $key);
    }

    Supabase::client()->upsert('livar_settings', [
        'key'        => $key,
        'value'      => trim($value),
        'updated_at' => gmdate('c'),
    ], 'key');
}

/**
 * Stores the vision model's one-line description of a photo.
 */
function setMessageCaption(int $id, string $caption): void
{
    Supabase::client()->patch('n8n_chat_history', ['id' => 'eq.' . $id], ['ai_caption' => $caption]);
}

/**
 * Points a customer at their profile photo on disk, or clears it.
 *
 * Deliberately not part of updateCustomer(): `avatar_path` is not in
 * CUSTOMER_PROFILE_FIELDS, so the details form cannot reach it. Only
 * api/avatar.php calls this, with a path it generated itself after
 * validating the bytes.
 */
function setCustomerAvatar(string $sessionId, ?string $path): ?array
{
    $rows = Supabase::client()->patch(
        'livar_customer',
        ['session_id' => 'eq.' . $sessionId],
        ['avatar_path' => $path]
    );

    return $rows[0] ?? null;
}

/**
 * Whether any chat row still points at a file in the media store.
 *
 * The catalog is sent by reference -- a row records the path of the file
 * that went out rather than a copy of it -- so replacing the catalog
 * must not delete a file that a message in somebody's thread is still
 * showing. Every other file in the store belongs to exactly one row, or
 * to no row at all, so this is only asked about the catalog.
 */
function mediaPathInUse(string $path): bool
{
    if ($path === '') {
        return false;
    }

    try {
        $result = Supabase::client()->get('n8n_chat_history', [
            'media_path' => 'eq.' . $path,
            'select'     => 'id',
            'limit'      => '1',
        ]);
    } catch (SupabaseException $e) {
        // Could not tell. Keep the file: an orphan costs disk, a wrongly
        // deleted one costs an attachment out of a customer's history.
        error_log('[Supabase] could not check whether a media file is still referenced: ' . $e->getMessage());
        return true;
    }

    return $result['rows'] !== [];
}

/**
 * The catalog file staged on the settings page, if there is one.
 *
 * Returns null when none has been uploaded, or when the file it points
 * at has since disappeared from disk -- a stale setting must read as
 * "no catalog", not as a send that fails at the customer's end.
 *
 * @return array{abs: string, path: string, name: string, mime: string, size: int}|null
 */
function getCatalogFile(): ?array
{
    $settings = getSettings();
    $path     = trim($settings['catalog_path'] ?? '');

    if ($path === '') {
        return null;
    }

    $abs = media_abs_path($path);
    if ($abs === null) {
        error_log('[Supabase] catalog_path points at a file that is not on disk: ' . $path);
        return null;
    }

    return [
        'abs'  => $abs,
        'path' => $path,
        'name' => ($settings['catalog_name'] ?? '') !== '' ? $settings['catalog_name'] : basename($abs),
        'mime' => ($settings['catalog_mime'] ?? '') !== '' ? $settings['catalog_mime'] : 'application/pdf',
        'size' => (int) filesize($abs),
    ];
}

/**
 * Whether free-form replies are still allowed.
 *
 * WhatsApp only permits them for WHATSAPP_WINDOW_HOURS after the
 * customer's last inbound message; past that a business must send an
 * approved template, which this CRM does not do -- so it blocks instead.
 */
function isWithin24hWindow(?string $lastInboundAt): bool
{
    $remaining = windowSecondsRemaining($lastInboundAt);
    return $remaining > 0;
}

/**
 * Seconds left in the free-form reply window; 0 when closed or unknown.
 */
function windowSecondsRemaining(?string $lastInboundAt): int
{
    if ($lastInboundAt === null || $lastInboundAt === '') {
        return 0;
    }

    $ts = strtotime($lastInboundAt);
    if ($ts === false) {
        return 0;
    }

    $hours   = defined('WHATSAPP_WINDOW_HOURS') ? (int) WHATSAPP_WINDOW_HOURS : 24;
    $expires = $ts + ($hours * 3600);

    return max(0, $expires - time());
}
