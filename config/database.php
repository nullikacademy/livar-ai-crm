<?php
/**
 * config/database.php
 *
 * Talks to Supabase over its REST API (PostgREST) instead of a native
 * Postgres connection. This only needs the `curl` extension, which is
 * enabled on effectively every cPanel/shared-hosting PHP build -- unlike
 * pdo_pgsql, which many budget hosts don't compile in at all and won't
 * let you enable yourself.
 *
 * Credentials needed (Supabase dashboard -> Project Settings -> API):
 *   SUPABASE_URL          e.g. https://your-project-ref.supabase.co
 *   SUPABASE_SERVICE_KEY  the "service_role" secret key (NOT the public
 *                          anon key -- service_role is required to bypass
 *                          Row Level Security so the CRM can read/write
 *                          every customer's data from the server side).
 *
 * Set these in config/config.php -- the single config file for this app.
 */

declare(strict_types=1);

// All settings come from the single config file. Nothing is read from
// environment variables, .env files, or SetEnv directives -- which is
// what makes this work identically on cPanel and everywhere else.
require_once __DIR__ . '/load_config.php';

/**
 * Thrown when a Supabase REST call fails. Carries the HTTP status so
 * callers can translate it into a sensible API response.
 */
final class SupabaseException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 500)
    {
        parent::__construct($message);
    }
}

/**
 * Minimal Supabase REST (PostgREST) client. Only depends on ext-curl.
 */
final class Supabase
{
    private static ?self $instance = null;

    private string $baseUrl;
    private string $serviceKey;

    /** Reused across calls so they share one connection. See handle(). */
    private ?CurlHandle $curl = null;

    private function __construct()
    {
        $url = SUPABASE_URL;
        $key = SUPABASE_SERVICE_KEY;

        // Catch the "downloaded it but never filled in the key" case with
        // a message that says exactly what to do, rather than a confusing
        // 401 from Supabase later on.
        if ($url === '' || $key === '' || str_starts_with($key, 'REPLACE_WITH')) {
            error_log('[Supabase] config/config.php has not been filled in yet.');
            $this->fail('Not configured yet: set SUPABASE_URL and SUPABASE_SERVICE_KEY in config/config.php.', 500);
        }

        $this->baseUrl    = rtrim($url, '/') . '/rest/v1';
        $this->serviceKey = $key;
    }

    public static function client(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * GET a table/view with PostgREST query params, e.g.:
     *   $sb->get('livar_customer', ['session_id' => 'eq.abc', 'select' => '*'])
     *
     * @param array<string, string> $query
     * @return array{rows: array<int, array<string, mixed>>, total: ?int}
     */
    public function get(string $path, array $query = [], ?string $rangeHeader = null): array
    {
        $extraHeaders = [];
        if ($rangeHeader !== null) {
            $extraHeaders[] = 'Range-Unit: items';
            $extraHeaders[] = 'Range: ' . $rangeHeader;
            $extraHeaders[] = 'Prefer: count=exact';
        }

        [$body, $headers] = $this->request('GET', $path, $query, null, $extraHeaders);

        $total = null;
        foreach ($headers as $h) {
            // Content-Range: 0-29/57
            if (stripos($h, 'content-range:') === 0 && str_contains($h, '/')) {
                $total = (int) substr($h, strrpos($h, '/') + 1);
            }
        }

        return ['rows' => is_array($body) ? $body : [], 'total' => $total];
    }

    /**
     * POST (insert). Returns the inserted row(s).
     *
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    public function post(string $path, array $data): array
    {
        [$body] = $this->request('POST', $path, [], $data, ['Prefer: return=representation']);
        return is_array($body) ? $body : [];
    }

    /**
     * POST with ON CONFLICT (upsert), resolving on $onConflict.
     *
     * Needed for the race-free get-or-create the inbound webhook does on
     * `wa_id`: two simultaneous deliveries from a brand-new number would
     * otherwise both pass a check-then-insert and the loser would surface
     * as a 409, which request() flattens into a generic 400 -- impossible
     * to tell apart from a validation error.
     *
     * $onConflict must name a column covered by a NON-partial unique
     * index; Postgres cannot infer a partial one (see sql/schema.sql).
     *
     * With $ignoreDuplicates the conflicting row is left untouched and
     * the response is an empty array, which is how insertWhatsAppMessage()
     * detects a webhook retry. Otherwise the row is merged (updated) with
     * the payload and returned.
     *
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    public function upsert(string $path, array $data, string $onConflict, bool $ignoreDuplicates = false): array
    {
        $resolution = $ignoreDuplicates ? 'ignore-duplicates' : 'merge-duplicates';

        [$body] = $this->request(
            'POST',
            $path,
            ['on_conflict' => $onConflict],
            $data,
            ['Prefer: resolution=' . $resolution . ',return=representation']
        );

        return is_array($body) ? $body : [];
    }

    /**
     * PATCH (update), filtered by PostgREST query params.
     *
     * @param array<string, string> $query
     * @param array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    public function patch(string $path, array $query, array $data): array
    {
        [$body] = $this->request('PATCH', $path, $query, $data, ['Prefer: return=representation']);
        return is_array($body) ? $body : [];
    }

    /**
     * DELETE, filtered by PostgREST query params.
     *
     * The one caller is the address-book mirror dropping a contact the
     * business deleted from their phone. Nothing in this app deletes a
     * customer or a message: a conversation that happened is a fact, and
     * the CRM has no undo.
     *
     * A filter is required, not optional -- PostgREST reads a DELETE with
     * no query as "every row in the table", and there is no version of
     * that which is ever what someone meant.
     *
     * @param array<string, string> $query
     * @return array<int, array<string, mixed>>
     */
    public function delete(string $path, array $query): array
    {
        if (!$query) {
            throw new SupabaseException('Refusing to delete from ' . $path . ' with no filter.', 400);
        }

        [$body] = $this->request('DELETE', $path, $query, null, ['Prefer: return=representation']);
        return is_array($body) ? $body : [];
    }

    /**
     * Calls a Postgres function exposed via PostgREST (POST /rpc/<fn>).
     *
     * $query takes the same params a table read does -- notably `select`,
     * which PostgREST validates against the function's return type even
     * when it returns no rows. That is the only way to check a function's
     * shape on an empty table.
     *
     * @param array<string, mixed> $args
     * @param array<string, string> $query
     * @return array<int, array<string, mixed>>
     */
    public function rpc(string $functionName, array $args = [], array $query = []): array
    {
        [$body] = $this->request('POST', 'rpc/' . $functionName, $query, $args);
        return is_array($body) ? $body : [];
    }

    /**
     * The one curl handle this client reuses for every call.
     *
     * Each call used to curl_init() a handle of its own, which meant a
     * fresh TCP connection and a fresh TLS handshake every time -- tens
     * of milliseconds before a byte of the actual query went out, paid
     * again on every call. Most endpoints here make more than one: a
     * message poll asks for the messages and then the reactions, and the
     * inbound webhook makes a handful before it can acknowledge.
     *
     * Holding the handle lets curl keep the connection open and reuse
     * it, so only the first call in a request pays for the handshake.
     *
     * PHP is shared-nothing, so the handle dies with the request and the
     * next one starts cold. That is the ceiling on this: it saves
     * handshakes WITHIN a request, never across them. An endpoint that
     * asks Supabase exactly one thing gains nothing, and is not meant to.
     *
     * curl_reset() wipes every option between calls, so nothing carries
     * over -- a POST body leaking into the next GET being the one that
     * would actually hurt. The connection cache is what survives, which
     * is the whole point. It also means request() must not be re-entrant;
     * nothing here calls it from inside itself.
     */
    private function handle(): CurlHandle
    {
        if ($this->curl === null) {
            $ch = curl_init();
            if ($ch === false) {
                error_log('[Supabase] curl_init() failed.');
                $this->fail('Could not reach the database. Please try again shortly.', 502);
            }
            $this->curl = $ch;
        } else {
            curl_reset($this->curl);
        }

        return $this->curl;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed>|null $data
     * @param array<int, string> $extraHeaders
     * @return array{0: mixed, 1: array<int, string>}
     */
    private function request(string $method, string $path, array $query, ?array $data, array $extraHeaders = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = array_merge([
            'apikey: ' . $this->serviceKey,
            'Authorization: Bearer ' . $this->serviceKey,
            'Content-Type: application/json',
        ], $extraHeaders);

        $ch = $this->handle();
        curl_setopt_array($ch, [
            // Set here rather than passed to curl_init(): the handle
            // outlives this call and serves the next URL too.
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TCP_KEEPALIVE  => 1,
        ]);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        $raw       = curl_exec($ch);
        $errno     = curl_errno($ch);
        $error     = curl_error($ch);
        $status    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerLen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        // Deliberately not closed: the next call reuses this handle, and
        // the connection cache it carries is the point of the exercise.
        // PHP frees it with this object at the end of the request.

        if ($errno !== 0) {
            error_log("[Supabase] curl error ({$errno}): {$error}");
            $this->fail('Could not reach the database. Please try again shortly.', 502);
        }

        $rawHeaders  = substr((string) $raw, 0, $headerLen);
        $rawBody     = substr((string) $raw, $headerLen);
        $headerLines = array_filter(array_map('trim', explode("\n", $rawHeaders)));

        if ($status >= 400) {
            error_log("[Supabase] {$method} {$path} -> HTTP {$status}: {$rawBody}");
            $this->fail('The database rejected the request. Please try again.', $status >= 500 ? 502 : 400);
        }

        $decoded = $rawBody !== '' ? json_decode($rawBody, true) : [];
        return [$decoded, $headerLines];
    }

    /**
     * Logs and throws; api/*.php entry points catch Throwable and turn
     * this into a clean JSON error response, so we never leak internals.
     */
    private function fail(string $message, int $httpStatus): never
    {
        throw new SupabaseException($message, $httpStatus);
    }
}
