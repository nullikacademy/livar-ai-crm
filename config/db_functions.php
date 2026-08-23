<?php
/**
 * Central Supabase data-access layer for customers and WhatsApp messages.
 */

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/database.php';

/** Fields agents may edit from the customer form. last_inbound_at is webhook-owned. */
const CUSTOMER_PROFILE_FIELDS = [
    'first_name', 'last_name', 'username', 'phone',
    'country', 'email', 'city', 'address', 'tax_id', 'details',
    'wa_id', 'wa_profile_name',
];

/** Operational message columns accepted by insertWhatsAppMessage(). */
const WHATSAPP_MESSAGE_FIELDS = [
    'created_at', 'direction', 'wa_message_id', 'wa_status', 'wa_error',
    'msg_type', 'wa_media_id', 'media_path', 'media_mime', 'media_size',
    'media_name', 'latitude', 'longitude', 'place_name', 'place_address',
];

/** Keep WhatsApp/PostgREST identifiers digits-only. */
function normalizeWaId(string $waId): string
{
    return preg_replace('/\D+/', '', $waId) ?? '';
}

/** Quotes an arbitrary PostgREST equality value containing provider punctuation. */
function postgrestQuotedEq(string $value): string
{
    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    return 'eq."' . $escaped . '"';
}

/**
 * Returns one customer page with latest-message previews.
 *
 * @return array{rows: array<int, array<string, mixed>>, hasMore: bool}
 */
function getCustomers(int $limit = CUSTOMERS_PAGE_SIZE, int $offset = 0, string $search = ''): array
{
    $rows = Supabase::client()->rpc('get_customers_with_preview', [
        'p_search' => $search,
        'p_limit'  => $limit + 1,
        'p_offset' => $offset,
    ]);

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }

    $rows = array_map(static function (array $row): array {
        unset($row['total_count'], $row['last_activity_id']);
        return $row;
    }, $rows);

    return ['rows' => $rows, 'hasMore' => $hasMore];
}

/** Fetches one customer by conversation session ID. */
function getCustomer(string $sessionId): ?array
{
    $result = Supabase::client()->get('livar_customer', [
        'session_id' => 'eq.' . $sessionId,
        'select'     => '*',
        'limit'      => '1',
    ]);

    return $result['rows'][0] ?? null;
}

/** Fetches one customer by digits-only WhatsApp ID. */
function getCustomerByWaId(string $waId): ?array
{
    $waId = normalizeWaId($waId);
    if ($waId === '') {
        return null;
    }

    $result = Supabase::client()->get('livar_customer', [
        'wa_id'  => 'eq.' . $waId,
        'select' => '*',
        'limit'  => '1',
    ]);

    return $result['rows'][0] ?? null;
}

/**
 * Creates a customer with a generated session ID unless one is supplied.
 *
 * @param array<string, mixed> $data
 */
function createCustomer(array $data): array
{
    $sessionId = is_string($data['session_id'] ?? null) && $data['session_id'] !== ''
        ? $data['session_id']
        : generate_session_id();

    $payload = ['session_id' => $sessionId];
    foreach (CUSTOMER_PROFILE_FIELDS as $field) {
        $value = $data[$field] ?? null;
        if ($field === 'wa_id' && is_string($value)) {
            $value = normalizeWaId($value);
        }
        $payload[$field] = ($value === '' ? null : $value);
    }

    $rows = Supabase::client()->post('livar_customer', $payload);
    return $rows[0] ?? $payload;
}

/** Partially updates editable profile fields for a customer. */
function updateCustomer(string $sessionId, array $data): ?array
{
    $payload = [];
    foreach (CUSTOMER_PROFILE_FIELDS as $field) {
        if (!array_key_exists($field, $data)) {
            continue;
        }

        $value = $data[$field];
        if ($field === 'wa_id' && is_string($value)) {
            $value = normalizeWaId($value);
        }
        $payload[$field] = ($value === '' ? null : $value);
    }

    if (!$payload) {
        return getCustomer($sessionId);
    }

    $rows = Supabase::client()->patch(
        'livar_customer',
        ['session_id' => 'eq.' . $sessionId],
        $payload
    );

    return $rows[0] ?? null;
}

/**
 * Race-free inbound customer creation. Concurrent webhook deliveries for the
 * same number converge on the wa_id unique index and deterministic session ID.
 */
function getOrCreateCustomerByWaId(string $waId, string $profileName = ''): array
{
    $waId = normalizeWaId($waId);
    if ($waId === '') {
        throw new InvalidArgumentException('A valid WhatsApp ID is required.');
    }

    $payload = [
        'wa_id'      => $waId,
        'session_id' => 'wa_' . $waId,
        'phone'      => $waId,
    ];
    $profileName = trim($profileName);
    if ($profileName !== '') {
        $payload['wa_profile_name'] = $profileName;
    }

    $rows = Supabase::client()->upsert('livar_customer', $payload, 'wa_id');
    return $rows[0] ?? getCustomerByWaId($waId) ?? $payload;
}

/** Marks the current instant as the customer's latest inbound activity. */
function touchLastInbound(string $sessionId): void
{
    Supabase::client()->patch(
        'livar_customer',
        ['session_id' => 'eq.' . $sessionId],
        ['last_inbound_at' => gmdate('Y-m-d\TH:i:s\Z')]
    );
}

/**
 * Inserts one canonical LangChain-shaped WhatsApp message.
 *
 * A duplicate wa_message_id returns null and is a successful webhook retry.
 *
 * @param array<string, mixed> $fields
 */
function insertWhatsAppMessage(string $sessionId, array $fields): ?array
{
    $direction = ($fields['direction'] ?? 'in') === 'out' ? 'out' : 'in';
    $content = is_string($fields['content'] ?? null) ? $fields['content'] : '';
    $msgType = is_string($fields['msg_type'] ?? null) && $fields['msg_type'] !== ''
        ? $fields['msg_type']
        : 'text';

    $payload = [
        'session_id' => $sessionId,
        'message'    => [
            'type'    => $direction === 'out' ? 'ai' : 'human',
            'content' => $content,
        ],
        'direction'  => $direction,
        'msg_type'   => $msgType,
    ];

    foreach (WHATSAPP_MESSAGE_FIELDS as $field) {
        if (array_key_exists($field, $fields)) {
            $payload[$field] = $fields[$field] === '' ? null : $fields[$field];
        }
    }

    try {
        $rows = Supabase::client()->post('n8n_chat_history', $payload);
        return $rows[0] ?? $payload;
    } catch (SupabaseException $e) {
        if ($e->httpStatus === 409 && !empty($payload['wa_message_id'])) {
            return null;
        }
        throw $e;
    }
}

/** Applies a 360dialog delivery/read/failure status to its outbound message. */
function updateMessageStatus(string $waMessageId, string $status, string $error = ''): void
{
    if ($waMessageId === '') {
        return;
    }

    Supabase::client()->patch(
        'n8n_chat_history',
        ['wa_message_id' => postgrestQuotedEq($waMessageId)],
        [
            'wa_status' => $status,
            'wa_error'  => $error === '' ? null : $error,
        ]
    );
}

/** Saves an eagerly or lazily downloaded media file against a message row. */
function setMessageMedia(int $id, string $path, string $mime, int $size): void
{
    Supabase::client()->patch(
        'n8n_chat_history',
        ['id' => 'eq.' . $id],
        [
            'media_path' => $path,
            'media_mime' => $mime,
            'media_size' => $size,
        ]
    );
}

/** Returns one raw chat row for authenticated media streaming. */
function getMessageById(int $id): ?array
{
    $result = Supabase::client()->get('n8n_chat_history', [
        'id'     => 'eq.' . $id,
        'select' => 'id,session_id,message,created_at,direction,wa_message_id,wa_status,wa_error,msg_type,wa_media_id,media_path,media_mime,media_size,media_name,latitude,longitude,place_name,place_address',
        'limit'  => '1',
    ]);

    return $result['rows'][0] ?? null;
}

/**
 * Normalizes a raw database row for the browser. Returns null for malformed
 * legacy rows that do not contain LangChain's message.type.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>|null
 */
function formatChatMessage(array $row): ?array
{
    $decoded = is_string($row['message'] ?? null)
        ? json_decode($row['message'], true)
        : ($row['message'] ?? null);
    if (!is_array($decoded) || !isset($decoded['type'])) {
        return null;
    }

    $content = $decoded['content'] ?? '';
    if (!is_string($content)) {
        $content = is_scalar($content)
            ? (string) $content
            : (json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    $id = (int) ($row['id'] ?? 0);
    $msgType = is_string($row['msg_type'] ?? null) && $row['msg_type'] !== ''
        ? $row['msg_type']
        : 'text';
    $hasMedia = in_array($msgType, ['image', 'video', 'audio', 'document', 'sticker'], true);

    return [
        'id'            => $id,
        'type'          => (string) $decoded['type'],
        'content'       => $content,
        'created_at'    => $row['created_at'] ?? null,
        'direction'     => $row['direction'] ?? null,
        'msg_type'      => $msgType,
        'media_url'     => $hasMedia && $id > 0 ? 'api/media.php?id=' . $id : null,
        'media_mime'    => $row['media_mime'] ?? null,
        'media_size'    => isset($row['media_size']) ? (int) $row['media_size'] : null,
        'media_name'    => $row['media_name'] ?? null,
        'latitude'      => isset($row['latitude']) ? (float) $row['latitude'] : null,
        'longitude'     => isset($row['longitude']) ? (float) $row['longitude'] : null,
        'place_name'    => $row['place_name'] ?? null,
        'place_address' => $row['place_address'] ?? null,
        'wa_status'     => $row['wa_status'] ?? null,
        'wa_error'      => $row['wa_error'] ?? null,
    ];
}

/**
 * Returns a bounded message page, oldest first. sinceId enables incremental
 * polling without rebuilding the existing conversation.
 *
 * @return array<int, array<string, mixed>>
 */
function getMessages(string $sessionId, int $sinceId = 0, int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $initialPage = $sinceId <= 0;
    $query = [
        'session_id' => 'eq.' . $sessionId,
        'select'     => 'id,session_id,message,created_at,direction,wa_message_id,wa_status,wa_error,msg_type,wa_media_id,media_path,media_mime,media_size,media_name,latitude,longitude,place_name,place_address',
        // Initial loads need the newest bounded window; incremental polls
        // need rows newer than sinceId. Both are normalized oldest-first.
        'order'      => $initialPage ? 'id.desc' : 'id.asc',
        'limit'      => (string) $limit,
    ];
    if ($sinceId > 0) {
        $query['id'] = 'gt.' . $sinceId;
    }

    $result = Supabase::client()->get('n8n_chat_history', $query);
    $rows = $initialPage ? array_reverse($result['rows']) : $result['rows'];
    $messages = [];
    foreach ($rows as $row) {
        $message = formatChatMessage($row);
        if ($message !== null) {
            $messages[] = $message;
        }
    }

    return $messages;
}

/**
 * Returns recent status snapshots so incremental polling also sees updates to
 * already-rendered outbound rows.
 *
 * @return array<int, array{id:int, wa_status:mixed, wa_error:mixed}>
 */
function getMessageStatuses(string $sessionId, int $limit = 200): array
{
    $result = Supabase::client()->get('n8n_chat_history', [
        'session_id' => 'eq.' . $sessionId,
        'direction'  => 'eq.out',
        'select'     => 'id,wa_status,wa_error',
        'order'      => 'id.desc',
        'limit'      => (string) max(1, min(500, $limit)),
    ]);

    return array_reverse(array_map(static fn (array $row): array => [
        'id'        => (int) ($row['id'] ?? 0),
        'wa_status' => $row['wa_status'] ?? null,
        'wa_error'  => $row['wa_error'] ?? null,
    ], $result['rows']));
}

/** True only while the free-form WhatsApp reply window remains open. */
function isWithin24hWindow(?string $lastInboundAt): bool
{
    if ($lastInboundAt === null || trim($lastInboundAt) === '') {
        return false;
    }

    try {
        $inbound = new DateTimeImmutable($lastInboundAt);
    } catch (Throwable) {
        return false;
    }

    $age = time() - $inbound->getTimestamp();
    return $age >= -300 && $age < 86400;
}
