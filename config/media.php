<?php
/**
 * config/media.php
 *
 * Everything to do with media files on local disk: where they live, what
 * types are allowed, and how a stored path is turned back into a real
 * one safely.
 *
 * This is its own file rather than part of whatsapp.php or
 * db_functions.php because three endpoints need it -- the inbound
 * webhook writes files, api/media.php reads them, api/upload.php writes
 * outbound ones -- and the path-safety rules must be written once, not
 * three times.
 *
 * Layout:
 *
 *   storage/
 *     .htaccess              Require all denied
 *     media/YYYY/MM/<32-hex>.<ext>     inbound, downloaded from WhatsApp
 *     media/outbox/<32-hex>.<ext>      outbound, staged before sending
 *
 * Nothing under storage/ is ever served directly. api/media.php streams
 * files after re-checking auth.
 */

declare(strict_types=1);

require_once __DIR__ . '/load_config.php';

/**
 * Mime types the CRM will store, and the extension each gets.
 *
 * The extension is always taken from THIS table, never from a filename
 * in the payload, so a sender cannot choose what a file is called on
 * disk. Together with the random basename that makes traversal and
 * ".php" tricks impossible by construction rather than by filtering.
 */
const MEDIA_MIME_EXT = [
    // Images
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/gif'       => 'gif',
    // Video
    'video/mp4'       => 'mp4',
    'video/3gpp'      => '3gp',
    'video/quicktime' => 'mov',
    // Audio / voice notes
    'audio/aac'       => 'aac',
    'audio/mp4'       => 'm4a',
    'audio/mpeg'      => 'mp3',
    'audio/amr'       => 'amr',
    'audio/ogg'       => 'ogg',
    'audio/opus'      => 'opus',
    'audio/wav'       => 'wav',
    'audio/x-wav'     => 'wav',
    // Documents
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'application/zip' => 'zip',
    'text/plain'      => 'txt',
    'text/csv'        => 'csv',
];

/**
 * Absolute path of the media root, created on first use.
 */
function media_root(): string
{
    $root = dirname(__DIR__) . '/storage/media';
    if (!is_dir($root)) {
        @mkdir($root, 0755, true);
    }
    return $root;
}

/**
 * Strips any parameters off a mime type: "audio/ogg; codecs=opus" is how
 * WhatsApp reports a voice note, and that is not a key in the table.
 */
function media_normalize_mime(string $mime): string
{
    $mime = strtolower(trim($mime));
    if (str_contains($mime, ';')) {
        $mime = trim(strstr($mime, ';', true) ?: $mime);
    }
    return $mime;
}

/**
 * The extension for a mime type, or null when it isn't allowed.
 */
function media_ext_for_mime(string $mime): ?string
{
    return MEDIA_MIME_EXT[media_normalize_mime($mime)] ?? null;
}

/**
 * Which kind of chat bubble a mime type produces.
 */
function media_msg_type_for_mime(string $mime): string
{
    $mime = media_normalize_mime($mime);
    if (str_starts_with($mime, 'image/')) {
        return 'image';
    }
    if (str_starts_with($mime, 'video/')) {
        return 'video';
    }
    if (str_starts_with($mime, 'audio/')) {
        return 'audio';
    }
    return 'document';
}

/**
 * Writes bytes into the media store and returns the path to record.
 *
 * The basename is 16 random bytes of hex -- unguessable, and never
 * derived from anything a sender controls. $subdir is either a YYYY/MM
 * bucket (inbound) or 'outbox' (staged outbound); it is generated here,
 * never taken from a request.
 *
 * @return array{path: string, abs: string, size: int, mime: string}
 */
function media_store(string $bytes, string $mime, string $subdir = ''): array
{
    $ext = media_ext_for_mime($mime);
    if ($ext === null) {
        throw new RuntimeException('Unsupported media type: ' . media_normalize_mime($mime));
    }

    $subdir = $subdir !== '' ? $subdir : gmdate('Y/m');
    $dir    = media_root() . '/' . $subdir;

    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the media directory: ' . $subdir);
    }

    $relative = $subdir . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    $abs      = media_root() . '/' . $relative;

    if (file_put_contents($abs, $bytes) === false) {
        throw new RuntimeException('Could not write the media file to disk.');
    }
    @chmod($abs, 0644);

    return [
        'path' => $relative,
        'abs'  => $abs,
        'size' => strlen($bytes),
        'mime' => media_normalize_mime($mime),
    ];
}

/**
 * Turns a stored relative path back into an absolute one, or null.
 *
 * Resolved with realpath() and then asserted to sit inside the media
 * root, so even a path that somehow got a "../" into the database cannot
 * read outside storage/media.
 */
function media_abs_path(string $relative): ?string
{
    if ($relative === '') {
        return null;
    }

    $root = realpath(media_root());
    $abs  = realpath(media_root() . '/' . $relative);

    if ($root === false || $abs === false) {
        return null;
    }
    if (!str_starts_with($abs, $root . DIRECTORY_SEPARATOR)) {
        error_log('[WhatsApp] refused a media path outside the media root: ' . $relative);
        return null;
    }
    if (!is_file($abs)) {
        return null;
    }

    return $abs;
}

/**
 * Streams a stored file to the browser and stops.
 *
 * Lives here rather than in api/media.php because two endpoints serve
 * bytes out of storage/ -- chat media and customer profile photos -- and
 * the headers that make that safe must not be written twice and drift.
 * nosniff matters more here than anywhere else in the app: these bytes
 * came from a stranger's phone, and without it a browser could decide a
 * "photo" is really HTML and run it on our origin.
 *
 * The caller has already re-checked the session and resolved the path
 * through media_abs_path().
 */
function media_stream(string $abs, string $mime, string $name = ''): never
{
    $mime = media_normalize_mime($mime);
    if ($mime === '' || media_ext_for_mime($mime) === null) {
        $mime = 'application/octet-stream';
    }

    $size = (int) filesize($abs);
    $etag = '"' . md5($abs . '|' . $size . '|' . (string) filemtime($abs)) . '"';

    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        header('ETag: ' . $etag);
        exit;
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; sandbox');
    header('ETag: ' . $etag);
    // The bytes behind a given URL never change -- a replaced avatar
    // gets a new ?v= -- so this can cache hard. It is private: the
    // response is only valid for this signed-in agent.
    header('Cache-Control: private, max-age=31536000, immutable');
    header('Content-Disposition: inline' . (($name !== '') ? '; filename="' . media_safe_filename($name) . '"' : ''));

    // Stream rather than file_get_contents: a 16 MB video should not
    // have to fit in PHP's memory limit.
    $fh = fopen($abs, 'rb');
    if ($fh === false) {
        // Not json_error(): media.php is the storage layer and does not
        // own the response format. The route above catches this.
        throw new RuntimeException('That file could not be opened for reading.');
    }
    fpassthru($fh);
    fclose($fh);
    exit;
}

/**
 * Makes a sender-supplied filename safe to put in a header.
 *
 * Only used for the download name -- it never touches the path on disk,
 * which is always server-generated hex.
 */
function media_safe_filename(string $name): string
{
    $name = str_replace(["\r", "\n", '"', '\\'], '', $name);
    $name = preg_replace('#[/\\\\]#', '_', $name) ?? '';
    $name = trim($name);
    return $name === '' ? 'file' : mb_substr($name, 0, 120);
}

/**
 * Deletes a file from the media store, if it is really in there.
 *
 * Used when a profile photo or the catalog is replaced: the old file has
 * no row pointing at it any more and would otherwise sit against the
 * hosting quota forever. Resolved through media_abs_path() first, so a
 * path that somehow escaped the root cannot be unlinked.
 */
function media_delete(string $relative): void
{
    $abs = media_abs_path($relative);
    if ($abs !== null) {
        @unlink($abs);
    }
}

/**
 * Total bytes currently held in the media store.
 *
 * Media accumulates against the hosting quota and there is no pruning
 * job, so the webhook logs this periodically to make growth visible
 * before it becomes a support call.
 */
function media_total_bytes(): int
{
    $root = realpath(media_root());
    if ($root === false) {
        return 0;
    }

    $total = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $total += $file->getSize();
        }
    }

    return $total;
}
