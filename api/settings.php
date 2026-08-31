<?php
/**
 * api/settings.php
 *
 *   GET  /api/settings.php            -> current values, defaults, model list
 *   PUT  /api/settings.php            -> save { ai_system_prompt, ai_model }
 *
 * The editable half of the settings page. Only keys declared in
 * SETTING_DEFAULTS exist at all, and of those only the ones in
 * SETTING_AGENT_EDITABLE can be read or written here -- so this endpoint
 * can never be used to poke at anything else in livar_settings.
 *
 * The catalog is the reason those are two different lists. It lives in
 * livar_settings like everything else, but one of its keys is a path
 * inside storage/, and an endpoint that wrote it from a JSON body would
 * be letting the browser choose a file for api/send.php to open. It is
 * uploaded through api/catalog.php instead, and only summarised here.
 *
 * API keys are NOT settings and are not reachable here -- they stay in
 * config/config.php, which the app never writes to.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/ai.php';

require_auth();

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handleGet();
            break;
        case 'PUT':
        case 'POST':
            handleSave();
            break;
        default:
            json_error('Method not allowed', 405);
    }
} catch (SupabaseException $e) {
    error_log('[api/settings] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    error_log('[api/settings] ' . $e->getMessage());
    json_error('Something went wrong while reading the settings.', 500);
}

function handleGet(): void
{
    json_response([
        'success'  => true,
        'settings' => editableOnly(getSettings()),
        // Sent so the page can offer "reset to default" without hardcoding
        // a copy of the prompt in JavaScript that would drift from PHP.
        'defaults' => editableOnly(SETTING_DEFAULTS),
        'models'   => availableModels(),
    ]);
}

function handleSave(): void
{
    $data  = read_json_body();
    $saved = [];

    foreach (SETTING_AGENT_EDITABLE as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }
        if (!is_string($data[$key])) {
            json_error("{$key} must be text", 422);
        }
        setSetting($key, $data[$key]);
        $saved[] = $key;
    }

    if (!$saved) {
        json_error('Nothing to save.', 422);
    }

    // Re-read rather than echo the input back, so the page shows what is
    // actually stored -- including a default restored by saving a blank.
    json_response(['success' => true, 'saved' => $saved, 'settings' => editableOnly(freshSettings())]);
}

/**
 * Narrows a settings map to the keys this endpoint owns.
 *
 * `catalog_path` is the one that matters: it is a location inside
 * storage/, and there is no reason for it to reach a browser at all.
 *
 * @param array<string, string> $settings
 * @return array<string, string>
 */
function editableOnly(array $settings): array
{
    return array_intersect_key($settings, array_flip(SETTING_AGENT_EDITABLE));
}

/**
 * getSettings() memoises for the request, which would hand back the
 * pre-save values here.
 *
 * @return array<string, string>
 */
function freshSettings(): array
{
    $values = SETTING_DEFAULTS;

    $result = Supabase::client()->get('livar_settings', ['select' => 'key,value']);
    foreach ($result['rows'] as $row) {
        $key = (string) ($row['key'] ?? '');
        if ($key !== '' && isset($values[$key]) && trim((string) $row['value']) !== '') {
            $values[$key] = (string) $row['value'];
        }
    }

    return $values;
}

/**
 * The model ids this key can actually use.
 *
 * Fetched from OpenAI rather than hardcoded: a baked-in list goes stale
 * the moment a model is retired, and would then offer choices that only
 * fail at draft time. An empty list is fine -- the field accepts free
 * text, so an unreachable API just means no autocomplete.
 *
 * @return array<int, string>
 */
function availableModels(): array
{
    if (!AI::isConfigured()) {
        return [];
    }

    try {
        $all = AI::client()->listModels();
    } catch (Throwable $e) {
        error_log('[api/settings] could not list models: ' . $e->getMessage());
        return [];
    }

    // The full list includes embeddings, audio, moderation and image
    // models that cannot answer a chat completion. Narrow it to the
    // families that can, so the picker is not 80 entries of noise.
    $chatty = array_values(array_filter($all, static function (string $id): bool {
        if (preg_match('/embedding|whisper|tts|audio|moderation|dall-e|image|realtime|transcribe|search|codex/i', $id)) {
            return false;
        }
        return (bool) preg_match('/^(gpt|o\d|chatgpt)/i', $id);
    }));

    return $chatty;
}
