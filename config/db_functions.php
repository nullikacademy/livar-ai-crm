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
 */
const CUSTOMER_PROFILE_FIELDS = [
    'first_name', 'last_name', 'username', 'phone',
    'country', 'email', 'city', 'address', 'tax_id', 'details',
];

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
 * Returns full chat history for a session, oldest first, with each
 * message's JSON payload parsed into `type` and `content`.
 */
function getMessages(string $sessionId): array
{
    $sb     = Supabase::client();
    $result = $sb->get('n8n_chat_history', [
        'session_id' => 'eq.' . $sessionId,
        'select'     => 'id,session_id,message',
        'order'      => 'id.asc',
    ]);

    $messages = [];
    foreach ($result['rows'] as $row) {
        $decoded = is_string($row['message']) ? json_decode($row['message'], true) : $row['message'];
        if (!is_array($decoded) || !isset($decoded['type'])) {
            continue; // Ignore malformed/unknown rows rather than break the chat.
        }
        $messages[] = [
            'id'      => $row['id'],
            'type'    => $decoded['type'],
            'content' => $decoded['content'] ?? '',
        ];
    }

    return $messages;
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
