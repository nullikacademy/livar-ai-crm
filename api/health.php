<?php
/**
 * api/health.php
 *
 *   GET /api/health.php               -> the list of available checks
 *   GET /api/health.php?check=<name>  -> run one check
 *
 * Backs settings.php. Each check runs in its own request so the page can
 * fire them in parallel and one unreachable service cannot hold up the
 * rest -- with three external dependencies, "the CRM is broken" is
 * usually one of them being down, and this says which.
 *
 * Every check returns the same shape:
 *
 *   { key, label, status: ok|warn|fail, summary, detail[], hint }
 *
 * `status` is deliberately three-valued. `warn` means "reachable but
 * could not be confirmed" -- an unproven check must never be painted
 * green, and is not necessarily a failure either.
 *
 * NOTHING here returns a secret. Keys and tokens are reported as
 * configured / not configured, and the webhook URL is masked.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/media.php';
require_once __DIR__ . '/../config/whatsapp.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

/**
 * The checks, in the order the page shows them.
 */
const HEALTH_CHECKS = [
    'config'    => 'Configuration',
    'supabase'  => 'Supabase connection',
    'schema'    => 'Database schema',
    'whatsapp'  => '360dialog API key',
    'webhook'   => '360dialog webhook',
    'n8n'       => 'n8n draft service',
    'storage'   => 'Media storage',
    'php'       => 'PHP environment',
];

try {
    $check = isset($_GET['check']) && is_string($_GET['check']) ? $_GET['check'] : '';

    if ($check === '') {
        $list = [];
        foreach (HEALTH_CHECKS as $key => $label) {
            $list[] = ['key' => $key, 'label' => $label];
        }
        json_response(['success' => true, 'checks' => $list]);
    }

    // Actions are real calls with a real cost, so they are never in the
    // list the page auto-runs -- only a deliberate click reaches them.
    if ($check === 'n8n_live') {
        json_response(['success' => true, 'result' => ['key' => 'n8n_live', 'label' => 'Live draft test'] + checkN8nLive()]);
    }

    if (!isset(HEALTH_CHECKS[$check])) {
        json_error('Unknown check', 404);
    }

    $result = match ($check) {
        'config'   => checkConfig(),
        'supabase' => checkSupabase(),
        'schema'   => checkSchema(),
        'whatsapp' => checkWhatsApp(),
        'webhook'  => checkWebhook(),
        'n8n'      => checkN8n(),
        'storage'  => checkStorage(),
        'php'      => checkPhp(),
    };

    json_response(['success' => true, 'result' => ['key' => $check, 'label' => HEALTH_CHECKS[$check]] + $result]);
} catch (Throwable $e) {
    error_log('[api/health] ' . $e->getMessage());
    json_error('The health check itself failed to run.', 500);
}

// ---------------------------------------------------------------------
// Checks
// ---------------------------------------------------------------------

/**
 * Are all the constants filled in? Runs entirely locally, so it is the
 * one check that still works when every network call is failing.
 *
 * @return array{status: string, summary: string, detail: array<int, string>, hint: string}
 */
function checkConfig(): array
{
    $required = [
        'SUPABASE_URL'           => 'Supabase project URL',
        'SUPABASE_SERVICE_KEY'   => 'Supabase service_role key',
        'CRM_PASSWORD_HASH'      => 'CRM password hash',
        'D360_API_KEY'           => '360dialog API key',
        'WHATSAPP_WEBHOOK_TOKEN' => 'Webhook token',
        'N8N_WEBHOOK_URL'        => 'n8n webhook URL',
    ];

    $detail  = [];
    $missing = [];

    foreach ($required as $const => $label) {
        if (!defined($const)) {
            $missing[] = $label;
            $detail[]  = "{$label}: not defined in config.php";
            continue;
        }
        $value = (string) constant($const);
        if ($value === '' || str_starts_with($value, 'REPLACE_WITH') || str_contains($value, 'your-project-ref')) {
            $missing[] = $label;
            $detail[]  = "{$label}: still the placeholder";
        } else {
            $detail[] = "{$label}: configured";
        }
    }

    // A plaintext password here would authenticate nobody and is worth
    // calling out specifically -- it is an easy mistake to make.
    if (defined('CRM_PASSWORD_HASH')) {
        $hash = (string) CRM_PASSWORD_HASH;
        if ($hash !== '' && !str_starts_with($hash, 'REPLACE_WITH') && !preg_match('/^\$2[aby]?\$|^\$argon2/', $hash)) {
            return [
                'status'  => 'fail',
                'summary' => 'CRM_PASSWORD_HASH is not a password hash',
                'detail'  => array_merge($detail, ['It looks like a plaintext password, so sign-in will always fail.']),
                'hint'    => 'Generate one with: php -r "echo password_hash(\'your-password\', PASSWORD_DEFAULT);"',
            ];
        }
    }

    if ($missing) {
        return [
            'status'  => 'fail',
            'summary' => count($missing) . ' setting(s) still unset',
            'detail'  => $detail,
            'hint'    => 'Fill these in in config/config.php — see README section 2, step 3.',
        ];
    }

    return ['status' => 'ok', 'summary' => 'All settings present', 'detail' => $detail, 'hint' => ''];
}

/**
 * Can we actually reach Supabase and is the key accepted?
 */
function checkSupabase(): array
{
    $started = microtime(true);

    try {
        Supabase::client()->get('livar_customer', ['select' => 'id', 'limit' => '1']);
    } catch (SupabaseException $e) {
        return [
            'status'  => 'fail',
            'summary' => 'Could not query Supabase',
            'detail'  => [$e->getMessage(), 'The exact status and body are in the PHP error log, on the [Supabase] line.'],
            'hint'    => $e->httpStatus >= 500
                ? 'Supabase looks unreachable. Check SUPABASE_URL and that the project is not paused.'
                : 'Usually a wrong key, or the anon key used instead of service_role.',
        ];
    }

    $ms = (int) round((microtime(true) - $started) * 1000);

    return [
        'status'  => 'ok',
        'summary' => "Connected in {$ms} ms",
        'detail'  => ['livar_customer is readable with the configured key.'],
        'hint'    => $ms > 1500 ? 'That is slow — the CRM will feel sluggish. Check the project region.' : '',
    ];
}

/**
 * Has sql/schema.sql actually been run?
 *
 * This is the check that would have explained the generic "database
 * rejected the request" error outright: PostgREST answers 400 for a
 * column that does not exist, so asking for the WhatsApp columns is a
 * direct test of whether the migration was applied.
 */
function checkSchema(): array
{
    $detail  = [];
    $missing = [];

    $probes = [
        'n8n_chat_history' => 'id,created_at,direction,wa_message_id,wa_status,msg_type,wa_media_id,'
                            . 'media_path,media_mime,media_size,media_name,latitude,longitude,place_name,place_address',
        'livar_customer'   => 'id,wa_id,wa_profile_name,last_inbound_at',
    ];

    foreach ($probes as $table => $select) {
        try {
            Supabase::client()->get($table, ['select' => $select, 'limit' => '1']);
            $detail[] = "{$table}: all WhatsApp columns present";
        } catch (SupabaseException $e) {
            // A transport failure says nothing about the schema. Telling
            // someone to re-run a migration because their database was
            // briefly unreachable would send them down the wrong path
            // entirely, so bail out rather than guess.
            if ($e->httpStatus >= 500) {
                return [
                    'status'  => 'warn',
                    'summary' => 'Could not be checked',
                    'detail'  => ['Supabase is unreachable, so the schema could not be inspected.'],
                    'hint'    => 'Fix the Supabase connection above, then re-check.',
                ];
            }
            $missing[] = $table;
            $detail[]  = "{$table}: one or more WhatsApp columns are missing";
        }
    }

    // The RPC's return shape changed, so an old copy of it is its own
    // failure mode, separate from the columns. Asking for the new columns
    // by name is what makes this work on an empty table -- inspecting a
    // returned row would silently pass when there are no rows to inspect.
    try {
        Supabase::client()->rpc(
            'get_customers_with_preview',
            ['p_search' => '', 'p_limit' => 1, 'p_offset' => 0],
            ['select' => 'session_id,wa_id,last_inbound_at,last_activity_at']
        );
        $detail[] = 'get_customers_with_preview: current version';
    } catch (SupabaseException $e) {
        if ($e->httpStatus >= 500) {
            return [
                'status'  => 'warn',
                'summary' => 'Could not be checked',
                'detail'  => ['Supabase is unreachable, so the schema could not be inspected.'],
                'hint'    => 'Fix the Supabase connection above, then re-check.',
            ];
        }
        $missing[] = 'get_customers_with_preview';
        $detail[]  = 'get_customers_with_preview: missing, or an older version without the WhatsApp columns';
    }

    if ($missing) {
        return [
            'status'  => 'fail',
            'summary' => 'Schema is out of date',
            'detail'  => $detail,
            'hint'    => 'Run sql/schema.sql in the Supabase SQL editor. It is safe to re-run.',
        ];
    }

    return ['status' => 'ok', 'summary' => 'Schema is up to date', 'detail' => $detail, 'hint' => ''];
}

/**
 * Is the 360dialog key accepted?
 */
function checkWhatsApp(): array
{
    if (!defined('D360_API_KEY') || D360_API_KEY === '' || str_starts_with((string) D360_API_KEY, 'REPLACE_WITH')) {
        return [
            'status'  => 'fail',
            'summary' => 'No API key configured',
            'detail'  => ['D360_API_KEY is still a placeholder, so nothing can be sent or received.'],
            'hint'    => 'Set D360_API_KEY in config/config.php from the 360dialog Hub.',
        ];
    }

    try {
        $probe = WhatsApp::client()->probeAuth();
    } catch (WhatsAppException $e) {
        return [
            'status'  => 'fail',
            'summary' => 'Client could not start',
            'detail'  => [$e->getMessage()],
            'hint'    => 'Check D360_API_KEY and D360_BASE_URL in config/config.php.',
        ];
    }

    $status = $probe['status'];
    $base   = defined('D360_BASE_URL') ? (string) D360_BASE_URL : 'https://waba-v2.360dialog.io';

    if ($status === 0) {
        return [
            'status'  => 'fail',
            'summary' => 'Could not reach 360dialog',
            'detail'  => ["No HTTP response from {$base}."],
            'hint'    => 'Check outbound HTTPS from this server, and that D360_BASE_URL is right.',
        ];
    }

    if ($status === 401 || $status === 403) {
        return [
            'status'  => 'fail',
            'summary' => "API key rejected (HTTP {$status})",
            'detail'  => ['360dialog answered, but refused the key.'],
            'hint'    => 'Recopy D360_API_KEY from the 360dialog Hub for this WhatsApp number.',
        ];
    }

    // The probe asks for a media id that cannot exist. Being told it does
    // not exist means the key got far enough to be trusted.
    if ($status === 404 || $status === 400) {
        return [
            'status'  => 'ok',
            'summary' => 'API key accepted',
            'detail'  => [
                "{$base} answered HTTP {$status} to a deliberately invalid media id.",
                'That is the expected reply for a working key — an unauthenticated one is refused before the lookup.',
            ],
            'hint'    => '',
        ];
    }

    return [
        'status'  => 'warn',
        'summary' => "Reachable, but unconfirmed (HTTP {$status})",
        'detail'  => ["{$base} answered HTTP {$status}, which this check does not recognise."],
        'hint'    => 'Not necessarily broken. Send a test message to confirm.',
    ];
}

/**
 * Is the webhook registered, and does it point at THIS install?
 *
 * A mismatch here is the most common reason inbound messages silently
 * never arrive, and nothing else in the app would ever surface it.
 */
function checkWebhook(): array
{
    $token = defined('WHATSAPP_WEBHOOK_TOKEN') ? (string) WHATSAPP_WEBHOOK_TOKEN : '';
    if ($token === '' || str_starts_with($token, 'REPLACE_WITH')) {
        return [
            'status'  => 'fail',
            'summary' => 'No webhook token configured',
            'detail'  => ['WHATSAPP_WEBHOOK_TOKEN is still a placeholder, so the webhook refuses every call with a 404.'],
            'hint'    => 'Generate one with: php -r "echo bin2hex(random_bytes(24));" then re-register the URL.',
        ];
    }

    $expected = expectedWebhookUrl();
    $detail   = ['This install answers on: ' . maskToken($expected, $token)];

    try {
        $config = WhatsApp::client()->webhookConfig();
    } catch (WhatsAppException $e) {
        return [
            'status'  => 'warn',
            'summary' => 'Could not read the registered URL',
            'detail'  => array_merge($detail, [$e->getMessage()]),
            'hint'    => 'Check it by hand in the 360dialog Hub.',
        ];
    }

    if ($config['status'] !== 200 || $config['url'] === '') {
        return [
            'status'  => 'warn',
            'summary' => 'Registered URL could not be read',
            'detail'  => array_merge($detail, [
                "360dialog answered HTTP {$config['status']} when asked for the webhook configuration.",
                'Not all plans expose this endpoint, so this is not proof of a problem.',
            ]),
            'hint'    => 'Confirm in the 360dialog Hub that the webhook points at the URL above, token included.',
        ];
    }

    $registered = $config['url'];
    $detail[]   = 'Registered with 360dialog: ' . maskToken($registered, $token);

    if (!str_contains($registered, 'token=')) {
        return [
            'status'  => 'fail',
            'summary' => 'Registered URL has no token',
            'detail'  => array_merge($detail, ['Without ?token= the webhook answers 404 and every inbound message is dropped.']),
            'hint'    => 'Re-register the full URL shown above, including its token.',
        ];
    }

    if (rtrim($registered, '/') !== rtrim($expected, '/')) {
        return [
            'status'  => 'warn',
            'summary' => 'Registered URL does not match this install',
            'detail'  => array_merge($detail, ['Inbound messages are going somewhere else, or to an old token.']),
            'hint'    => 'Re-register the URL shown above — unless this install is intentionally not the live one.',
        ];
    }

    return [
        'status'  => 'ok',
        'summary' => 'Registered and pointing here',
        'detail'  => $detail,
        'hint'    => '',
    ];
}

/**
 * Is n8n up?
 *
 * Deliberately a GET, not a POST. An n8n webhook registered for POST
 * answers a GET with a 404 that names the method -- which proves both
 * that the host is up and that the workflow exists, without running the
 * agent or spending a model call. The settings page offers a real draft
 * as a separate, explicit action.
 */
function checkN8n(): array
{
    $url = defined('N8N_WEBHOOK_URL') ? (string) N8N_WEBHOOK_URL : '';
    if ($url === '' || str_contains($url, 'your-n8n-host')) {
        return [
            'status'  => 'fail',
            'summary' => 'No webhook URL configured',
            'detail'  => ['N8N_WEBHOOK_URL is still a placeholder, so the Draft button cannot work.'],
            'hint'    => 'Set N8N_WEBHOOK_URL in config/config.php — see README section 5.',
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body   = (string) curl_exec($ch);
    $errno  = curl_errno($ch);
    $error  = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return [
            'status'  => 'fail',
            'summary' => 'Could not reach n8n',
            'detail'  => ['No HTTP response from the configured webhook host.', $error],
            'hint'    => 'Check that n8n is running and reachable over HTTPS from this server.',
        ];
    }

    // n8n's own wording when a POST-only webhook is fetched with GET.
    if (stripos($body, 'not registered for GET') !== false) {
        return [
            'status'  => 'ok',
            'summary' => 'Reachable, workflow is listening',
            'detail'  => ['n8n replied that this webhook takes POST, not GET — which is exactly right.'],
            'hint'    => '',
        ];
    }

    if ($status === 404) {
        return [
            'status'  => 'fail',
            'summary' => 'Host is up, but the workflow is not there',
            'detail'  => ['n8n answered 404. The workflow is probably inactive, or the UUID in the URL is wrong.'],
            'hint'    => 'Activate the workflow in n8n and check the path matches N8N_WEBHOOK_URL.',
        ];
    }

    if ($status >= 200 && $status < 500) {
        return [
            'status'  => 'ok',
            'summary' => "Reachable (HTTP {$status})",
            'detail'  => ['The host answered. Use "Run a live draft test" below to exercise the agent itself.'],
            'hint'    => '',
        ];
    }

    return [
        'status'  => 'warn',
        'summary' => "Unexpected reply (HTTP {$status})",
        'detail'  => ['The host answered, but with a server error.'],
        'hint'    => 'Check the n8n execution log.',
    ];
}

/**
 * Actually asks n8n for a draft, end to end.
 *
 * This runs the AI agent and therefore costs a model call, so it is only
 * ever reached by an explicit click -- never by the page loading. It
 * sends a throwaway conversation and writes nothing to the database.
 */
function checkN8nLive(): array
{
    $url = defined('N8N_WEBHOOK_URL') ? (string) N8N_WEBHOOK_URL : '';
    if ($url === '' || str_contains($url, 'your-n8n-host')) {
        return [
            'status'  => 'fail',
            'summary' => 'No webhook URL configured',
            'detail'  => ['Set N8N_WEBHOOK_URL first.'],
            'hint'    => '',
        ];
    }

    $payload = json_encode([
        'session_id' => 'health_check',
        'history'    => [
            ['role' => 'user', 'content' => 'Hello, do you sell 500 ml plastic cans?'],
        ],
        'customer'   => ['first_name' => 'Health', 'last_name' => 'Check', 'wa_id' => null],
    ], JSON_UNESCAPED_UNICODE);

    $started = microtime(true);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => defined('N8N_TIMEOUT_SECONDS') ? (int) N8N_TIMEOUT_SECONDS : 45,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body   = (string) curl_exec($ch);
    $errno  = curl_errno($ch);
    $error  = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $secs = round(microtime(true) - $started, 1);

    if ($errno !== 0) {
        return [
            'status'  => 'fail',
            'summary' => 'Could not reach n8n',
            'detail'  => [$error],
            'hint'    => 'Check that n8n is running and reachable over HTTPS from this server.',
        ];
    }

    if ($status < 200 || $status >= 300) {
        return [
            'status'  => 'fail',
            'summary' => "n8n returned HTTP {$status}",
            'detail'  => ['Response: ' . mb_substr($body, 0, 300)],
            'hint'    => 'Open the workflow execution log in n8n to see which node failed.',
        ];
    }

    // Same tolerant extraction api/webhook.php uses, so this test agrees
    // with what the Draft button will actually do.
    $draft   = null;
    $decoded = json_decode($body, true);
    if (is_array($decoded) && array_is_list($decoded) && is_array($decoded[0] ?? null)) {
        $decoded = $decoded[0];
    }
    if (is_string($decoded)) {
        $draft = trim($decoded);
    } elseif (is_array($decoded)) {
        foreach (['draft', 'output', 'text', 'reply', 'message', 'content'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
                $draft = trim($decoded[$key]);
                break;
            }
        }
    }

    if ($draft === null) {
        return [
            'status'  => 'fail',
            'summary' => 'n8n answered, but with no draft in it',
            'detail'  => ['Response: ' . mb_substr($body, 0, 300)],
            'hint'    => 'The Respond to Webhook node should return { "draft": "..." }. See README section 5, step 4.',
        ];
    }

    return [
        'status'  => 'ok',
        'summary' => "Draft returned in {$secs}s",
        'detail'  => ['n8n replied: “' . mb_substr($draft, 0, 220) . (mb_strlen($draft) > 220 ? '…' : '') . '”'],
        'hint'    => $secs > 30 ? 'That is close to the ' . N8N_TIMEOUT_SECONDS . 's timeout. Consider a faster model.' : '',
    ];
}

/**
 * Can media actually be written, and how much is piling up?
 */
function checkStorage(): array
{
    $root = media_root();

    if (!is_dir($root)) {
        return [
            'status'  => 'fail',
            'summary' => 'Media directory is missing',
            'detail'  => ['Expected storage/media/ to exist and could not create it.'],
            'hint'    => 'Create storage/media/ and make it writable by the web server.',
        ];
    }

    if (!is_writable($root)) {
        return [
            'status'  => 'fail',
            'summary' => 'Media directory is not writable',
            'detail'  => ['Inbound photos and documents cannot be saved.'],
            'hint'    => 'chmod 755 storage/media (and make sure the web server user owns it).',
        ];
    }

    $bytes = media_total_bytes();
    $mb    = $bytes / 1048576;
    $guard = is_file(dirname($root) . '/.htaccess');
    $size  = humanSize($bytes);

    $detail = [
        "Media store holds {$size}.",
        $guard
            ? 'storage/.htaccess is in place, so nothing here is served directly.'
            : 'storage/.htaccess is MISSING — on Apache these files may be downloadable without signing in.',
    ];

    if (!$guard) {
        return [
            'status'  => 'fail',
            'summary' => 'Media is not protected',
            'detail'  => $detail,
            'hint'    => 'Restore storage/.htaccess containing "Require all denied".',
        ];
    }

    // There is no pruning job, so growth is worth flagging before the
    // hosting quota does it for you.
    if ($mb > 1024) {
        return [
            'status'  => 'warn',
            'summary' => "Writable, {$size} stored",
            'detail'  => $detail,
            'hint'    => 'Media accumulates with no pruning job. Consider archiving old files.',
        ];
    }

    return ['status' => 'ok', 'summary' => "Writable, {$size} stored", 'detail' => $detail, 'hint' => ''];
}

/**
 * Bytes in whichever unit does not read as "0.0".
 */
function humanSize(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024) . ' KB';
    }
    if ($bytes < 1073741824) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    return round($bytes / 1073741824, 2) . ' GB';
}

/**
 * The two extensions this app genuinely needs, plus the one that decides
 * whether the webhook can defer its media downloads.
 */
function checkPhp(): array
{
    $detail = ['PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')'];
    $fail   = [];

    foreach (['curl' => 'every API call', 'fileinfo' => 'validating uploads'] as $ext => $why) {
        if (extension_loaded($ext)) {
            $detail[] = "ext-{$ext}: loaded";
        } else {
            $fail[]   = $ext;
            $detail[] = "ext-{$ext}: MISSING — needed for {$why}";
        }
    }

    if (version_compare(PHP_VERSION, '8.1', '<')) {
        $fail[]   = 'php-version';
        $detail[] = 'This app needs PHP 8.1 or newer.';
    }

    $deferred = function_exists('fastcgi_finish_request');
    $detail[] = $deferred
        ? 'fastcgi_finish_request(): available — the webhook acknowledges before downloading media.'
        : 'fastcgi_finish_request(): not available — media downloads run inline, so the webhook is slower to answer.';

    if ($fail) {
        return [
            'status'  => 'fail',
            'summary' => 'Missing requirements',
            'detail'  => $detail,
            'hint'    => 'Enable the extensions above in your hosting control panel.',
        ];
    }

    return [
        'status'  => $deferred ? 'ok' : 'warn',
        'summary' => $deferred ? 'All requirements met' : 'Met, but no deferred processing',
        'detail'  => $detail,
        'hint'    => $deferred ? '' : 'Harmless on low volume. Under php-fpm the webhook would answer faster.',
    ];
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

/**
 * The webhook URL this install actually answers on, rebuilt from the
 * current request so it reflects the real host and path.
 */
function expectedWebhookUrl(): string
{
    $https  = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    // .../api/health.php -> .../api/whatsapp_webhook.php
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/health.php'))), '/');

    $token = defined('WHATSAPP_WEBHOOK_TOKEN') ? (string) WHATSAPP_WEBHOOK_TOKEN : '';

    return $scheme . '://' . $host . $dir . '/whatsapp_webhook.php?token=' . rawurlencode($token);
}

/**
 * Replaces the token in a URL with a short fingerprint.
 *
 * The settings page is behind a login, but the token is still a
 * credential: rendering it into HTML puts it in the page source, the
 * browser cache and the back button. A fingerprint is enough to compare
 * two URLs, which is all this page needs to do.
 */
function maskToken(string $url, string $token): string
{
    if ($token === '') {
        return $url;
    }
    $fingerprint = '…' . substr(hash('sha256', $token), 0, 8);
    return str_replace([$token, rawurlencode($token)], $fingerprint, $url);
}
