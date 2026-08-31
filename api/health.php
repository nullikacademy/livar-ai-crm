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
require_once __DIR__ . '/../config/ai.php';

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
    'openai'    => 'OpenAI connection',
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
    if ($check === 'ai_live') {
        json_response(['success' => true, 'result' => ['key' => 'ai_live', 'label' => 'Live draft test'] + checkAiLive()]);
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
        'openai'   => checkOpenAi(),
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
        'OPENAI_API_KEY'         => 'OpenAI API key',
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
 * Is OpenAI reachable, is the key good, and does the chosen model exist?
 *
 * Listing models is the right probe: it is cheap, it proves the key, and
 * it is the only way to catch the failure that would otherwise surface
 * as a confusing 404 at draft time -- a model id that this account
 * cannot use.
 */
function checkOpenAi(): array
{
    if (!AI::isConfigured()) {
        return [
            'status'  => 'fail',
            'summary' => 'No API key configured',
            'detail'  => ['OPENAI_API_KEY is unset or still a placeholder, so the Draft button cannot work.'],
            'hint'    => 'Set OPENAI_API_KEY in config/config.php.',
        ];
    }

    $model  = getSetting('ai_model');
    $detail = ['Model in use: ' . ($model !== '' ? $model : '(none set)')];

    $started = microtime(true);

    try {
        $models = AI::client()->listModels();
    } catch (AIException $e) {
        return [
            'status'  => 'fail',
            'summary' => $e->httpStatus === 401 ? 'API key rejected' : 'Could not reach OpenAI',
            'detail'  => array_merge($detail, [$e->getMessage()]),
            'hint'    => $e->httpStatus === 401
                ? 'Recopy OPENAI_API_KEY from platform.openai.com.'
                : 'Check outbound HTTPS from this server, and the account\'s billing status.',
        ];
    }

    $ms       = (int) round((microtime(true) - $started) * 1000);
    $detail[] = count($models) . ' models available to this key · listed in ' . $ms . ' ms';

    if ($model === '') {
        return [
            'status'  => 'fail',
            'summary' => 'No model chosen',
            'detail'  => $detail,
            'hint'    => 'Pick one under "AI replies" above.',
        ];
    }

    if (!in_array($model, $models, true)) {
        return [
            'status'  => 'warn',
            'summary' => 'Chosen model is not in this account\'s list',
            'detail'  => array_merge($detail, [
                "\"{$model}\" did not come back from /v1/models.",
                'Some accounts can still use a model that is not listed, so this may be fine.',
            ]),
            'hint'    => 'If drafting fails with a 404, this is why — pick a listed model.',
        ];
    }

    // A prompt long enough to matter is worth flagging: it is sent on
    // every single draft, so it is the biggest recurring token cost.
    $promptChars = mb_strlen(getSetting('ai_system_prompt'));
    $detail[]    = "System prompt: {$promptChars} characters, sent with every draft";

    return [
        'status'  => 'ok',
        'summary' => "Connected · {$model}",
        'detail'  => $detail,
        'hint'    => $promptChars > 6000
            ? 'That prompt is long. It is billed on every draft — consider trimming it.'
            : '',
    ];
}

/**
 * Actually asks OpenAI for a draft, using the real prompt and model.
 *
 * Costs a model call, so it is only ever reached by an explicit click.
 * Sends a throwaway conversation and writes nothing.
 */
function checkAiLive(): array
{
    if (!AI::isConfigured()) {
        return [
            'status'  => 'fail',
            'summary' => 'No API key configured',
            'detail'  => ['Set OPENAI_API_KEY first.'],
            'hint'    => '',
        ];
    }

    $settings = getSettings();
    $started  = microtime(true);

    try {
        $draft = AI::client()->chat([
            ['role' => 'system', 'content' => $settings['ai_system_prompt']],
            ['role' => 'user',   'content' => 'Hello, do you sell 500 ml plastic cans?'],
        ], $settings['ai_model']);
    } catch (AIException $e) {
        return [
            'status'  => 'fail',
            'summary' => 'OpenAI could not produce a draft',
            'detail'  => [$e->getMessage()],
            'hint'    => $e->httpStatus === 404
                ? 'That usually means the model id is wrong for this account.'
                : 'The full response is in the PHP error log on the [AI] line.',
        ];
    }

    $secs = round(microtime(true) - $started, 1);

    return [
        'status'  => 'ok',
        'summary' => "Draft returned in {$secs}s using {$settings['ai_model']}",
        'detail'  => ['Replied: “' . mb_substr($draft, 0, 220) . (mb_strlen($draft) > 220 ? '…' : '') . '”'],
        'hint'    => $secs > 20 ? 'That is slow enough that agents will notice. Consider a faster model.' : '',
    ];
}

/**
 * Can media actually be written, and how much is piling up?
 */
function checkStorage(): array
{
    $root = media_root();

    // PHP caches the result of is_writable() and friends, and under
    // php-fpm that cache outlives the request (realpath_cache_ttl, 120s
    // by default). Without this, fixing the permissions and pressing
    // Re-check keeps reporting the old failure for two minutes, which
    // makes it look like the fix did not work.
    clearstatcache(true, $root);

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
