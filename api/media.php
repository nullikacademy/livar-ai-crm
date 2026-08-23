<?php
/** Authenticated, traversal-safe media streaming and lazy 360dialog fetch. */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_auth();
require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/whatsapp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

try {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        json_error('A valid media id is required', 422);
    }

    $row = getMessageById($id);
    if ($row === null) {
        json_error('Media not found', 404);
    }

    $relativePath = is_string($row['media_path'] ?? null) ? $row['media_path'] : '';
    $absolutePath = resolveMediaPath($relativePath);
    if ($absolutePath === null) {
        $providerMediaId = is_string($row['wa_media_id'] ?? null) ? $row['wa_media_id'] : '';
        if ($providerMediaId === '') {
            json_error('Media not found', 404);
        }

        $stored = storeWhatsAppMedia(WhatsApp::client()->fetchMedia($providerMediaId));
        setMessageMedia($id, $stored['path'], $stored['mime'], $stored['size']);
        $relativePath = $stored['path'];
        $absolutePath = resolveMediaPath($relativePath);
        $row['media_mime'] = $stored['mime'];
        $row['media_size'] = $stored['size'];
    }

    if ($absolutePath === null) {
        json_error('Media not found', 404);
    }

    $mime = normalizeMediaMime((string) ($row['media_mime'] ?? 'application/octet-stream'));
    if ($mime === '') {
        $mime = 'application/octet-stream';
    }
    $name = is_string($row['media_name'] ?? null) && $row['media_name'] !== ''
        ? basename($row['media_name'])
        : basename($absolutePath);
    $name = preg_replace('/[\x00-\x1F\x7F"\\\\]+/', '', $name) ?: 'attachment';

    header('Content-Type: ' . $mime);
    header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=31536000, immutable');
    streamMediaFile($absolutePath);
} catch (SupabaseException $e) {
    error_log('[api/media] ' . $e->getMessage());
    json_error('The media record could not be loaded.', 502);
} catch (WhatsAppException $e) {
    error_log('[api/media] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus >= 500 ? 502 : $e->httpStatus);
} catch (Throwable $e) {
    error_log('[api/media] ' . $e->getMessage());
    json_error('The attachment could not be loaded.', 500);
}

/** Streams one optional byte range so native audio/video controls can seek. */
function streamMediaFile(string $absolutePath): never
{
    $size = filesize($absolutePath);
    if ($size === false || $size <= 0) {
        throw new RuntimeException('Could not determine media size.');
    }

    $start = 0;
    $end = max(0, $size - 1);
    $partial = false;
    $range = is_string($_SERVER['HTTP_RANGE'] ?? null) ? trim($_SERVER['HTTP_RANGE']) : '';
    if ($range !== '') {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches)) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }

        if ($matches[1] === '' && $matches[2] !== '') {
            $suffix = min((int) $matches[2], $size);
            $start = max(0, $size - $suffix);
        } else {
            $start = (int) $matches[1];
            if ($matches[2] !== '') {
                $end = min((int) $matches[2], $size - 1);
            }
        }

        if ($start < 0 || $start >= $size || $end < $start) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        $partial = true;
    }

    $length = $end - $start + 1;
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $length);
    if ($partial) {
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }

    $handle = fopen($absolutePath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Could not open media file.');
    }
    if ($start > 0) {
        fseek($handle, $start);
    }

    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(8192, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($handle);
    exit;
}
