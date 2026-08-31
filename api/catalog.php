<?php
/**
 * api/catalog.php
 *
 *   GET    /api/catalog.php           -> what is currently uploaded
 *   GET    /api/catalog.php?file=1    -> stream it, to check it is right
 *   POST   /api/catalog.php  (multipart: file)
 *   DELETE /api/catalog.php
 *
 * The product catalog, uploaded once on the settings page and then one
 * click away in every conversation. Sending it used to mean finding the
 * PDF on your own machine and attaching it again for each customer,
 * which is both slower and a good way to send last quarter's prices.
 *
 * Why this is not part of api/settings.php: the catalog is stored as
 * three rows in livar_settings, and one of them is a path inside
 * storage/. An endpoint that let a JSON body write `catalog_path` would
 * be letting the browser name a file on disk for api/send.php to open.
 * So the settings endpoint refuses to write these keys (see
 * SETTING_AGENT_EDITABLE) and this one takes bytes instead of a path.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/media.php';
require_once __DIR__ . '/../config/whatsapp.php';

require_auth();

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            isset($_GET['file']) ? handleDownload() : handleStatus();
            break;
        case 'POST':
            handleUpload();
            break;
        case 'DELETE':
            handleDelete();
            break;
        default:
            json_error('Method not allowed', 405);
    }
} catch (MediaStoreException $e) {
    // Passed through, unlike the generic rule: a directory the server
    // cannot write to is the one storage failure whoever is looking at
    // this can go and fix.
    error_log('[api/catalog] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (SupabaseException $e) {
    error_log('[api/catalog] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    error_log('[api/catalog] ' . $e->getMessage());
    json_error('Something went wrong with the catalog file.', 500);
}

/**
 * What the settings page shows beside the upload button.
 */
function handleStatus(): void
{
    json_response(['success' => true, 'catalog' => catalogSummary()]);
}

/**
 * Streams the stored catalog so it can be checked before it is sent to
 * a customer.
 */
function handleDownload(): void
{
    $catalog = getCatalogFile();
    if ($catalog === null) {
        json_error('No catalog has been uploaded yet.', 404);
    }

    media_stream($catalog['abs'], $catalog['mime'], $catalog['name']);
}

/**
 * Replaces the catalog with a newly uploaded file.
 */
function handleUpload(): void
{
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        json_error('No file was uploaded.', 422);
    }

    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_error('That file could not be uploaded.', 422);
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        json_error('That upload could not be read.', 422);
    }

    $size = (int) ($file['size'] ?? 0);
    $max  = WhatsApp::maxMediaBytes();
    if ($size <= 0) {
        json_error('That file is empty.', 422);
    }
    // Checked against WhatsApp's limit rather than the server's, because
    // a catalog that cannot be delivered is worse than one that was
    // never accepted: the failure would land on an agent mid-conversation
    // instead of on whoever uploaded it.
    if ($size > $max) {
        json_error(
            'That file is ' . round($size / 1048576, 1) . ' MB; WhatsApp will not carry more than '
            . round($max / 1048576, 1) . ' MB.',
            422
        );
    }

    $mime = detectCatalogMime($tmp);
    if (media_ext_for_mime($mime) === null) {
        error_log('[api/catalog] rejected a disallowed mime: ' . ($mime !== '' ? $mime : 'undetectable'));
        json_error('WhatsApp does not accept that kind of file' . ($mime !== '' ? ' (' . $mime . ')' : '') . '.', 422);
    }

    $bytes = file_get_contents($tmp);
    if ($bytes === false) {
        json_error('That upload could not be read.', 422);
    }

    // Read before the settings are overwritten, so the file it replaces
    // can be cleaned up afterwards.
    $previous = trim(getSettings()['catalog_path'] ?? '');

    // 'catalog' is a literal, never taken from the request.
    $saved = media_store($bytes, $mime, 'catalog');
    $name  = catalogDisplayName((string) ($file['name'] ?? ''), $saved['mime']);

    setSetting('catalog_path', $saved['path']);
    setSetting('catalog_name', $name);
    setSetting('catalog_mime', $saved['mime']);

    retirePrevious($previous, $saved['path']);

    json_response([
        'success' => true,
        'catalog' => [
            'name'      => $name,
            'mime'      => $saved['mime'],
            'size'      => $saved['size'],
            'msg_type'  => media_msg_type_for_mime($saved['mime']),
            'available' => true,
        ],
    ], 201);
}

/**
 * Removes the catalog. Sending it then reports that there is none,
 * rather than failing at WhatsApp.
 */
function handleDelete(): void
{
    $previous = trim(getSettings()['catalog_path'] ?? '');

    setSetting('catalog_path', '');
    setSetting('catalog_name', '');
    setSetting('catalog_mime', '');

    retirePrevious($previous, '');

    json_response(['success' => true, 'catalog' => ['available' => false]]);
}

/**
 * Deletes the catalog file that has just been replaced or cleared.
 *
 * Unless a message still points at it. The catalog is sent by reference
 * -- api/send.php records the catalog's own path on the row rather than
 * copying the file per send -- so deleting it on replace would reach
 * back into conversations that already happened and turn a document
 * somebody sent last month into "no longer available". An orphaned file
 * costs a few megabytes; that costs an audit trail.
 */
function retirePrevious(string $previous, string $replacement): void
{
    if ($previous === '' || $previous === $replacement) {
        return;
    }

    if (mediaPathInUse($previous)) {
        error_log('[Supabase] keeping the old catalog file: a sent message still points at ' . $previous);
        return;
    }

    media_delete($previous);
}

/**
 * The current catalog, or a shape that says there isn't one.
 *
 * @return array<string, mixed>
 */
function catalogSummary(): array
{
    $catalog = getCatalogFile();
    if ($catalog === null) {
        return ['available' => false];
    }

    return [
        'available' => true,
        'name'      => $catalog['name'],
        'mime'      => $catalog['mime'],
        'size'      => $catalog['size'],
        'msg_type'  => media_msg_type_for_mime($catalog['mime']),
    ];
}

/**
 * Reads the real mime out of the file's bytes.
 */
function detectCatalogMime(string $path): string
{
    // All three are checked, not just finfo_open: disable_functions
    // works per function, so guarding one while calling the others turns
    // a hardened host into a fatal error.
    if (function_exists('finfo_open') && function_exists('finfo_file') && function_exists('finfo_close')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($detected) && $detected !== '') {
                return media_normalize_mime($detected);
            }
        }
    }

    error_log('[api/catalog] ext-fileinfo is not available; cannot verify the file type.');
    return '';
}

/**
 * The filename the customer will see in WhatsApp.
 *
 * Display-only, exactly like the one api/upload.php keeps beside a
 * staged attachment -- it never becomes part of a path.
 */
function catalogDisplayName(string $name, string $mime): string
{
    $name = basename($name);
    $name = str_replace(["\r", "\n", '"', '\\'], '', $name);
    $name = trim($name);

    if ($name === '') {
        $ext  = media_ext_for_mime($mime);
        $name = 'catalog' . ($ext !== null ? '.' . $ext : '');
    }

    return mb_substr($name, 0, 120);
}
