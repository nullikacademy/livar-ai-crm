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
 * Reduces a business-scoped user id (BSUID) to a storable, filterable id.
 *
 * A BSUID is how WhatsApp names a sender who has not given us their phone
 * number -- an opaque per-business string such as 'BR.1A2B3C4D...'. It is
 * NOT a number, so normalizeWaId() would shred it into a meaningless run
 * of digits that could even collide with a real number. Hence its own
 * normaliser, and its own column.
 *
 * The charset is an allowlist rather than an escape: `.` `:` `+` are all
 * PostgREST filter syntax, and anything outside what Meta actually uses
 * is far more likely to be an injection attempt than a real id.
 */
function normalizeWaUserId(string $userId): string
{
    $clean = preg_replace('/[^A-Za-z0-9._:=+\/~-]+/', '', $userId) ?? '';
    return substr($clean, 0, 128);
}

/**
 * Wraps a filter value in double quotes for PostgREST.
 *
 * normalizeWaId() sidesteps this by reducing to digits, but a BSUID keeps
 * its dots, and an unquoted `eq.BR.1A2B` is read as filter syntax. The
 * allowlist above already excludes `"` and `\`, so there is nothing left
 * inside the quotes that needs escaping -- but escape anyway rather than
 * rely on a caller having normalised first.
 */
function eqFilter(string $value): string
{
    return 'eq."' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
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
 * The deterministic session_id for a customer known only by a BSUID.
 *
 * Hashed rather than embedded because session_id travels in PostgREST
 * filters and in api/avatar.php's query string, and a raw BSUID carries
 * the dots that made eqFilter() necessary. Deterministic for the same
 * reason waSessionId() is: two concurrent first deliveries have to land
 * on one row, not two.
 *
 * A customer's session_id is assigned once and never recomputed, so a
 * number appearing later (see linkCustomerIdentity()) does not move an
 * existing thread to a wa_ id -- that would strand its whole history.
 */
function waUserSessionId(string $userId): string
{
    return 'wau_' . substr(sha1(normalizeWaUserId($userId)), 0, 24);
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
        'select'     => 'id,session_id,message,created_at,direction,wa_status,msg_type,wa_message_id,'
                      . 'media_path,media_mime,media_size,media_name,ai_caption,ai_transcript,'
                      . 'wa_buttons,wa_template,wa_source,wa_reaction,wa_reaction_out,'
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
            // Meta's own id for this message. Not a secret, and the
            // browser needs it to say which message it is reacting to.
            'wa_message_id' => $row['wa_message_id'] ?? null,
            'msg_type'      => $msgType,

            // Never the path on disk. api/media.php re-checks auth, and
            // lazily downloads from 360dialog if media_path is still null
            // (which happens when the webhook could not defer its work).
            'media_url'     => in_array($msgType, MEDIA_MSG_TYPES, true) ? 'api/media.php?id=' . $id : null,
            'media_mime'    => $row['media_mime'] ?? null,
            'media_size'    => isset($row['media_size']) ? (int) $row['media_size'] : null,
            'media_name'    => $row['media_name'] ?? null,
            'ai_caption'    => $row['ai_caption'] ?? null,
            'ai_transcript' => $row['ai_transcript'] ?? null,

            // The option labels of a quick-reply question, so the thread
            // shows what the customer was actually offered rather than
            // just the question text.
            'buttons'       => decodeButtonLabels($row['wa_buttons'] ?? null),
            'wa_template'   => $row['wa_template'] ?? null,

            // 'app' for a reply typed on the phone and mirrored back by
            // the coexistence echo webhook; null for one this CRM sent.
            'wa_source'     => $row['wa_source'] ?? null,

            // The emoji on this message: theirs, and ours.
            'reaction'      => $row['wa_reaction'] ?? null,
            'reaction_out'  => $row['wa_reaction_out'] ?? null,

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
 * The address to send this customer a WhatsApp message at.
 *
 * Their number when we have one, and their business-scoped user id when
 * we do not -- which is the case for anyone who reached us through a
 * WhatsApp username. Returns '' when the customer is not reachable on
 * WhatsApp at all (a row an agent created by hand, say).
 *
 * The number is preferred because Meta prefers it: `to` wins over
 * `recipient` when a payload somehow carries both.
 *
 * @param array<string, mixed> $customer
 */
function customerAddress(array $customer): string
{
    $waId = normalizeWaId((string) ($customer['wa_id'] ?? ''));
    if ($waId !== '') {
        return $waId;
    }

    return normalizeWaUserId((string) ($customer['wa_user_id'] ?? ''));
}

/**
 * Looks a customer up by their business-scoped user id.
 */
function getCustomerByWaUserId(string $userId): ?array
{
    $userId = normalizeWaUserId($userId);
    if ($userId === '') {
        return null;
    }

    $sb     = Supabase::client();
    $result = $sb->get('livar_customer', [
        'wa_user_id' => eqFilter($userId),
        'select'     => '*',
        'limit'      => '1',
    ]);

    return $result['rows'][0] ?? null;
}

/**
 * Fills in an identity a customer row is missing, and nothing else.
 *
 * The two identities appear at different times. A username adopter is
 * BSUID-only until they message us from a number we can see, or the
 * business saves them; someone we have known by number for months starts
 * carrying a BSUID the moment Meta enables it. Either way one row already
 * exists and simply lacks the other half.
 *
 * The profile name is filled in here too, and for the same reason. A
 * customer can be created by something that carries no name at all -- an
 * echo of a message the business sent from their phone creates the row
 * with nothing but an identity on it -- and the name is only ever offered
 * on the NEXT inbound message. Applying it only at creation left those
 * customers reading "Unnamed customer" forever.
 *
 * Only ever writes a column that is currently empty. A number or a name
 * already on the row was either learned earlier or typed by an agent, and
 * a webhook is not the authority on it.
 *
 * @param array<string, mixed>  $customer
 * @param array<string, string> $identity
 * @return array<string, mixed> the customer, updated if anything changed
 */
function linkCustomerIdentity(array $customer, array $identity): array
{
    $patch = [];
    foreach (['wa_id', 'wa_user_id', 'wa_username', 'wa_profile_name'] as $field) {
        $value = trim($identity[$field] ?? '');
        if ($value !== '' && ($customer[$field] ?? null) === null) {
            $patch[$field] = $value;
        }
    }

    // A number arriving for the first time also settles the country and
    // the dialable phone, both of which were unknowable until now.
    if (isset($patch['wa_id'])) {
        if (($customer['phone'] ?? null) === null) {
            $patch['phone'] = '+' . $patch['wa_id'];
        }
        if (($customer['country'] ?? null) === null) {
            $country = country_name_for_phone($patch['wa_id']);
            if ($country !== null) {
                $patch['country'] = $country;
            }
        }
    }

    if ($patch === []) {
        return $customer;
    }

    try {
        $rows = Supabase::client()->patch(
            'livar_customer',
            ['session_id' => 'eq.' . (string) ($customer['session_id'] ?? '')],
            $patch
        );
    } catch (SupabaseException $e) {
        // A unique-index clash means the other identity is already on a
        // DIFFERENT row -- the same person reached us twice before Meta
        // sent both halves together. Merging two histories is not
        // something a webhook should decide, so leave both rows alone and
        // say so; the message itself still files against the row we have.
        error_log('[WhatsApp] could not link identity for ' . ($customer['session_id'] ?? '?')
                  . ': ' . $e->getMessage());
        return $customer;
    }

    return $rows[0] ?? array_merge($customer, $patch);
}

/**
 * Returns the customer for whatever identity a webhook gave us, creating
 * one on first contact.
 *
 * WhatsApp names an inbound sender one of two ways, and increasingly both:
 * a phone number, and/or a business-scoped user id. A person who uses a
 * WhatsApp username has no number in the payload at all, so a phone-only
 * lookup would drop their messages on the floor -- which is exactly what
 * this app used to do.
 *
 * The BSUID is preferred as the key when present: it is stable for this
 * business, and it is the only identity a username adopter has. The phone
 * number remains the key for everyone already in the database, so no
 * existing conversation moves.
 *
 * @param array<string, string> $identity wa_id / wa_user_id / wa_username
 * @return array<string, mixed>
 */
function getOrCreateCustomerByIdentity(array $identity, string $profileName = ''): array
{
    $waId     = normalizeWaId($identity['wa_id'] ?? '');
    $userId   = normalizeWaUserId($identity['wa_user_id'] ?? '');
    $username = trim($identity['wa_username'] ?? '');
    $identity = [
        'wa_id'           => $waId,
        'wa_user_id'      => $userId,
        'wa_username'     => $username,
        // Carried alongside the identities so an existing nameless row can
        // learn it too, not just a row being created here.
        'wa_profile_name' => trim($profileName),
    ];

    if ($waId === '' && $userId === '') {
        throw new SupabaseException('A WhatsApp message arrived without a usable sender id.', 422);
    }

    // Look under both identities before creating anything. Checking only
    // the one we would key on would create a second row for a person the
    // CRM already knows the moment Meta starts sending the other half.
    $existing = $userId !== '' ? getCustomerByWaUserId($userId) : null;
    if ($existing === null && $waId !== '') {
        $existing = getCustomerByWaId($waId);
    }
    if ($existing !== null) {
        return linkCustomerIdentity($existing, $identity);
    }

    $keyedOnUserId = $userId !== '';

    $payload = [
        'session_id' => $keyedOnUserId ? waUserSessionId($userId) : waSessionId($waId),
        // Nobody has spoken to this contact before, which is the one
        // moment the CRM can say that for certain. An agent can move
        // them to 'old' later; guessing it back afterwards is impossible.
        'label'      => 'new',
    ];
    if ($waId !== '') {
        $payload['wa_id'] = $waId;
        $payload['phone'] = '+' . $waId;
    }
    if ($userId !== '') {
        $payload['wa_user_id'] = $userId;
    }
    if ($username !== '') {
        $payload['wa_username'] = $username;
    }
    // Only send a name we actually have -- merge-duplicates would
    // otherwise blank out a name the racing insert just stored.
    if ($profileName !== '') {
        $payload['wa_profile_name'] = $profileName;
    }
    // The business may already have this number saved on their phone
    // without ever having been messaged by it. That name is better than
    // the one the customer picked for themselves, so it is collected at
    // the moment the conversation starts -- which is the first point the
    // contact stops being an address-book entry and becomes a customer.
    // A BSUID-only sender is not in the address book by definition.
    if ($waId !== '') {
        $contactName = getWaContactName($waId);
        if ($contactName !== null) {
            $payload['wa_contact_name'] = $contactName;
        }
        // The country is in the number, so there is no reason to make an
        // agent type it. Only ever written here, on the insert: a later
        // correction by hand must not be overwritten by the prefix table.
        $country = country_name_for_phone($waId);
        if ($country !== null) {
            $payload['country'] = $country;
        }
    }

    $sb   = Supabase::client();
    $rows = $sb->upsert('livar_customer', $payload, $keyedOnUserId ? 'wa_user_id' : 'wa_id');

    // A merge upsert always returns the row, but re-read defensively
    // rather than hand a half-built payload to the rest of the webhook.
    if (isset($rows[0])) {
        return $rows[0];
    }
    return ($keyedOnUserId ? getCustomerByWaUserId($userId) : getCustomerByWaId($waId)) ?? $payload;
}

/**
 * Mirrors the WhatsApp Business app's address book.
 *
 * From the smb_app_state_sync coexistence webhook. Onboarding replays
 * the WHOLE address book, so this writes in ONE bulk upsert rather than
 * a request per contact -- the webhook has to answer before 360dialog
 * gives up, and several hundred round trips would not fit.
 *
 * Contacts land in their own table, not in livar_customer. Most numbers
 * in a phone have never messaged the business, and turning each into a
 * customer would bury the real conversations. The name is collected when
 * that number first writes in -- see getOrCreateCustomerByIdentity().
 *
 * Any customer that already exists is updated immediately, though, which
 * is what makes the sync visible on a directory that is already full.
 *
 * @param array<int, array{wa_id: string, full_name: string, first_name: string}> $contacts
 * @return array{stored: int, matched: int}
 */
function syncWaContacts(array $contacts): array
{
    $rows = [];
    foreach ($contacts as $contact) {
        $waId = normalizeWaId((string) ($contact['wa_id'] ?? ''));
        $full = trim((string) ($contact['full_name'] ?? ''));
        $first = trim((string) ($contact['first_name'] ?? ''));

        // A contact with no name is the phone telling us about a number
        // it has nothing to add about.
        if ($waId === '' || ($full === '' && $first === '')) {
            continue;
        }

        // Keyed by wa_id so a repeated number within one batch collapses
        // instead of making the upsert fail on a duplicate key.
        $rows[$waId] = [
            'wa_id'      => $waId,
            'full_name'  => $full !== '' ? mb_substr($full, 0, 200) : $first,
            'first_name' => $first !== '' ? mb_substr($first, 0, 200) : null,
            'updated_at' => gmdate('c'),
        ];
    }

    if (!$rows) {
        return ['stored' => 0, 'matched' => 0];
    }

    $sb = Supabase::client();
    $sb->upsert('livar_wa_contact', array_values($rows), 'wa_id');

    // Light up the customers that already exist. One PATCH per name
    // rather than one per contact: only numbers already in the directory
    // are touched, which on a real inbox is a small fraction of a phone.
    $matched = 0;
    foreach ($rows as $waId => $row) {
        try {
            $updated = $sb->patch(
                'livar_customer',
                ['wa_id' => 'eq.' . $waId],
                ['wa_contact_name' => $row['full_name']]
            );
            $matched += count($updated);
        } catch (SupabaseException $e) {
            error_log('[WhatsApp] could not apply a synced contact name to ' . $waId . ': ' . $e->getMessage());
        }
    }

    return ['stored' => count($rows), 'matched' => $matched];
}

/**
 * Forgets a contact the business deleted from their phone.
 *
 * Only the mirrored name goes: the customer, their conversation and
 * anything an agent typed all stay. Deleting a phone contact says
 * nothing about whether the CRM should still know who they are.
 */
function forgetWaContact(string $waId): void
{
    $waId = normalizeWaId($waId);
    if ($waId === '') {
        return;
    }

    $sb = Supabase::client();
    $sb->delete('livar_wa_contact', ['wa_id' => 'eq.' . $waId]);
    $sb->patch('livar_customer', ['wa_id' => 'eq.' . $waId], ['wa_contact_name' => null]);
}

/**
 * The name the business has saved for a number, if any.
 */
function getWaContactName(string $waId): ?string
{
    $waId = normalizeWaId($waId);
    if ($waId === '') {
        return null;
    }

    try {
        $result = Supabase::client()->get('livar_wa_contact', [
            'wa_id'  => 'eq.' . $waId,
            'select' => 'full_name',
            'limit'  => '1',
        ]);
    } catch (SupabaseException $e) {
        // An un-migrated database has no such table. A missing nicety
        // must never cost us the inbound message that asked for it.
        error_log('[WhatsApp] could not read the synced contact name: ' . $e->getMessage());
        return null;
    }

    $name = trim((string) ($result['rows'][0]['full_name'] ?? ''));
    return $name !== '' ? $name : null;
}

/**
 * Marks a conversation as read, up to now.
 *
 * Its own function rather than a field on the details form, for the same
 * reason last_inbound_at is: it is not a profile fact an agent types, it
 * is a record of something that happened. api/read.php is the only
 * caller, and it stamps the server's clock rather than accepting a time
 * from the browser -- a client whose clock is fast would otherwise be
 * able to mark messages read before they arrived.
 */
function markConversationRead(string $sessionId): void
{
    if ($sessionId === '') {
        return;
    }

    Supabase::client()->patch(
        'livar_customer',
        ['session_id' => 'eq.' . $sessionId],
        ['last_read_at' => gmdate('c')]
    );
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
 * Every reaction currently on a conversation.
 *
 * Its own query because reactions break the incremental poll: they
 * change a row that already exists, and getMessages(sinceId) only ever
 * returns rows NEWER than what is on screen. A customer reacting to
 * something from an hour ago would otherwise not show up until the
 * conversation was reopened.
 *
 * Cheap by construction -- it asks only for rows that actually carry a
 * reaction, which in a normal thread is none or a handful.
 *
 * @return array<int, array{in: ?string, out: ?string}> keyed by message id
 */
function getReactions(string $sessionId): array
{
    $result = Supabase::client()->get('n8n_chat_history', [
        'session_id' => 'eq.' . $sessionId,
        'or'         => '(wa_reaction.not.is.null,wa_reaction_out.not.is.null)',
        'select'     => 'id,wa_reaction,wa_reaction_out',
        'limit'      => '200',
    ]);

    $reactions = [];
    foreach ($result['rows'] as $row) {
        $reactions[(int) $row['id']] = [
            'in'  => $row['wa_reaction'] ?? null,
            'out' => $row['wa_reaction_out'] ?? null,
        ];
    }

    return $reactions;
}

/**
 * Puts a reaction on a message, or takes one off.
 *
 * A reaction is not a message. WhatsApp sends it as one, but what it
 * means is "this emoji is now on that other message", so it is stored on
 * the row it refers to rather than inserted as a bubble of its own --
 * otherwise a thread fills up with 👍 entries that reply to nothing and
 * the sidebar preview becomes an emoji.
 *
 * An empty $emoji is a removal: WhatsApp sends the same shape with no
 * emoji when somebody takes their reaction back.
 *
 * $direction is 'in' for the customer's reaction and 'out' for ours,
 * which are different columns because both can exist on one message.
 *
 * Returns false when the message being reacted to is not in the CRM --
 * a reaction to something older than this install, which is nothing to
 * act on but worth knowing about.
 */
function setMessageReaction(string $waMessageId, string $emoji, string $direction = 'in'): bool
{
    if ($waMessageId === '') {
        return false;
    }

    $column = $direction === 'out' ? 'wa_reaction_out' : 'wa_reaction';
    // Emoji only, and one of them: the column is a reaction, not a text
    // field, and WhatsApp allows exactly one per person per message.
    $value  = $emoji === '' ? null : mb_substr($emoji, 0, 8);

    $rows = Supabase::client()->patch(
        'n8n_chat_history',
        ['wa_message_id' => 'eq.' . $waMessageId],
        [$column => $value]
    );

    return $rows !== [];
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
- WhatsApp is not Markdown. Bold is *one* asterisk, italic is _one_
  underscore. Never write **double** asterisks, headings or [links](url).
- Give prices, sizes and lead times only when they appear in the conversation
  or the customer's notes. Never invent a figure.
- If something needs a person — a custom quote, a complaint, a payment issue —
  say you are checking with the team rather than guessing.
PROMPT,

    // Speech-to-text for voice notes. Its own setting because the chat
    // model cannot transcribe audio, and a constant here would go stale
    // exactly like a hardcoded chat model would.
    'ai_transcribe_model' => 'whisper-1',

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
const SETTING_AGENT_EDITABLE = ['ai_model', 'ai_system_prompt', 'ai_transcribe_model'];

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
 * Stores what a voice note said, in the language it was spoken.
 *
 * Beside setMessageCaption() rather than folded into it: the caption is
 * the one-line English label the UI shows, this is the full text the
 * draft reasons from, and a row can legitimately have one without the
 * other.
 */
function setMessageTranscript(int $id, string $transcript): void
{
    Supabase::client()->patch('n8n_chat_history', ['id' => 'eq.' . $id], ['ai_transcript' => $transcript]);
}

/**
 * Voice notes on disk that have never been transcribed.
 *
 * Powers the settings-page backfill for messages that arrived before
 * transcription existed. Rows whose audio was never downloaded are
 * skipped -- there is nothing to send.
 *
 * @return array<int, array<string, mixed>>
 */
function getUntranscribedVoiceNotes(int $limit = 10): array
{
    $result = Supabase::client()->get('n8n_chat_history', [
        'msg_type'      => 'eq.audio',
        'ai_transcript' => 'is.null',
        'media_path'    => 'not.is.null',
        'select'        => 'id,media_path,media_mime',
        'order'         => 'id.desc',
        'limit'         => (string) max(1, min(50, $limit)),
    ]);

    return $result['rows'];
}

/**
 * How many voice notes are still waiting, so the settings page can say
 * whether pressing the button again would do anything.
 */
function countUntranscribedVoiceNotes(): int
{
    $result = Supabase::client()->get('n8n_chat_history', [
        'msg_type'      => 'eq.audio',
        'ai_transcript' => 'is.null',
        'media_path'    => 'not.is.null',
        'select'        => 'id',
        'limit'         => '500',
    ]);

    return count($result['rows']);
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
