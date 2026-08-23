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

/**
 * The editable customer profile fields.
 *
 * createCustomer()/updateCustomer() only write keys listed here, so a
 * column missing from this list is silently unwritable. `last_inbound_at`
 * is deliberately absent: it is owned by the inbound webhook (see
 * touchLastInbound()) and must never be settable from the details form,
 * since it is what gates outbound sending.
 */
const CUSTOMER_PROFILE_FIELDS = [
    'first_name', 'last_name', 'username', 'phone',
    'country', 'email', 'city', 'address', 'tax_id', 'details',
    'wa_id', 'wa_profile_name',
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
 * Creates a new customer with a freshly generated session_id.
 *
 * @param array<string, mixed> $data
 */
function createCustomer(array $data): array
{
    $sb        = Supabase::client();
    $sessionId = $data['session_id'] ?? generate_session_id();

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
                      . 'media_path,media_mime,media_size,media_name,'
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

            'latitude'      => isset($row['latitude']) ? (float) $row['latitude'] : null,
            'longitude'     => isset($row['longitude']) ? (float) $row['longitude'] : null,
            'place_name'    => $row['place_name'] ?? null,
            'place_address' => $row['place_address'] ?? null,
        ];
    }

    return $messages;
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
    ];
    // Only send a name we actually have -- merge-duplicates would
    // otherwise blank out a name the racing insert just stored.
    if ($profileName !== '') {
        $payload['wa_profile_name'] = $profileName;
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
