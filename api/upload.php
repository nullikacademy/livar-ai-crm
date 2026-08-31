<?php
/**
 * api/upload.php
 *
 *   POST /api/upload.php   (multipart, one file in `file`)
 *   ->  { success: true, media_ref: "<32-hex>.<ext>", name, mime, size, msg_type }
 *
 * Stages an outbound attachment on disk and hands back an opaque
 * reference. api/send.php resolves that reference, uploads the file to
 * WhatsApp and sends it.
 *
 * The two-step exists so the slow part (uploading to Meta) happens when
 * the agent presses Send, not while they are still typing a caption --
 * and so a file they change their mind about never reaches WhatsApp at
 * all.
 *
 * The browser's Content-Type is not trusted for anything: the mime is
 * re-detected from the file's own bytes with finfo_file(), and the name
 * on disk is server-generated hex with an extension from the allowlist.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/media.php';
require_once __DIR__ . '/../config/whatsapp.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

try {
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        json_error('No file was uploaded.', 422);
    }

    $file = $_FILES['file'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_error(uploadErrorMessage((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)), 422);
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
    if ($size > $max) {
        json_error(sprintf('That file is %s; the limit is %s.', humanBytes($size), humanBytes($max)), 422);
    }

    // The browser-supplied type is ignored. finfo reads the actual bytes,
    // so a .php renamed to .jpg is caught here rather than by WhatsApp.
    $mime = detectMime($tmp);
    if (media_ext_for_mime($mime) === null) {
        error_log('[api/upload] rejected a disallowed mime: ' . $mime);
        json_error('WhatsApp does not accept that kind of file (' . $mime . ').', 422);
    }

    $bytes = file_get_contents($tmp);
    if ($bytes === false) {
        json_error('That upload could not be read.', 422);
    }

    // 'outbox' is a literal here, never taken from the request.
    $saved = media_store($bytes, $mime, 'outbox');

    // The original filename is kept beside the file, not in its path. It
    // is display-only: shown in the chip, and used as the WhatsApp
    // document filename.
    $displayName = safeDisplayName((string) ($file['name'] ?? ''));
    @file_put_contents($saved['abs'] . '.json', json_encode([
        'name'        => $displayName,
        'mime'        => $saved['mime'],
        'size'        => $saved['size'],
        'staged_at'   => gmdate('c'),
    ]));

    json_response([
        'success'   => true,
        'media_ref' => basename($saved['path']),
        'name'      => $displayName,
        'mime'      => $saved['mime'],
        'size'      => $saved['size'],
        'msg_type'  => media_msg_type_for_mime($saved['mime']),
    ], 201);
} catch (MediaStoreException $e) {
    // Passed through, unlike the generic rule: a directory the server
    // cannot write to is the one storage failure whoever is looking at
    // this can go and fix.
    error_log('[api/upload] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (WhatsAppException $e) {
    error_log('[api/upload] ' . $e->getMessage());
    json_error($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('[api/upload] ' . $e->getMessage());
    json_error('Something went wrong while staging that file.', 500);
}

/**
 * Reads the real mime type out of the file's bytes.
 */
function detectMime(string $path): string
{
    // All three are checked, not just finfo_open: disable_functions works
    // per function, so guarding one while calling the others turns a
    // hardened host into a fatal error.
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

    // No ext-fileinfo (rare, but some minimal builds omit it). Refuse
    // rather than fall back to the browser's claim about the file.
    error_log('[api/upload] ext-fileinfo is not available; cannot verify the file type.');
    return '';
}

/**
 * Cleans up a filename for display. Never used as a path.
 */
function safeDisplayName(string $name): string
{
    $name = basename($name);
    $name = str_replace(["\r", "\n", '"', '\\'], '', $name);
    $name = trim($name);
    return $name === '' ? 'attachment' : mb_substr($name, 0, 120);
}

function humanBytes(int $bytes): string
{
    if ($bytes < 1048576) {
        return round($bytes / 1024) . ' KB';
    }
    return round($bytes / 1048576, 1) . ' MB';
}

/**
 * Turns PHP's upload error codes into something an agent can act on.
 */
function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            'That file is larger than the server accepts. Try a smaller one.',
        UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
            'The server could not save that file.',
        UPLOAD_ERR_EXTENSION => 'That upload was blocked by the server.',
        default              => 'That file could not be uploaded.',
    };
}
