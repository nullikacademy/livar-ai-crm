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
