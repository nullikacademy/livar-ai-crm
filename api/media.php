<?php
/**
 * api/media.php
 *
 *   GET /api/media.php?id=<n8n_chat_history.id>
 *
 * The only way media reaches a browser. Files live under storage/, which
 * Apache is told to deny outright, so this endpoint is what re-checks the
 * session, resolves the path safely and streams the bytes.
 *
 * If the row has no media_path yet -- the webhook could not defer its
 * download, or the download failed -- this fetches it from 360dialog
 * first, saves it, and updates the row. That is a fallback, not the
 * plan: Meta deletes media after roughly 30 days, so the webhook still
 * downloads eagerly.
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

try {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        json_error('id is required', 422);
    }

    $row = getMessageRow($id);
    if ($row === null) {
        json_error('Not found', 404);
    }

    $path = isset($row['media_path']) ? (string) $row['media_path'] : '';
    $abs  = $path !== '' ? media_abs_path($path) : null;

    // Nothing on disk: try to pull it from WhatsApp now.
    if ($abs === null) {
        $abs = fetch_media_now($row);
    }

    if ($abs === null) {
        json_error('That file is no longer available.', 404);
    }

    // media_stream() lives in config/media.php: api/avatar.php serves
    // bytes out of the same store, and the headers that make that safe
    // are written once.
    media_stream($abs, (string) ($row['media_mime'] ?? ''), (string) ($row['media_name'] ?? ''));
} catch (WhatsAppException $e) {
    error_log('[api/media] ' . $e->getMessage());
    json_error('That file could not be loaded from WhatsApp.', 502);
} catch (SupabaseException $e) {
    error_log('[api/media] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    error_log('[api/media] ' . $e->getMessage());
    json_error('Something went wrong while loading that file.', 500);
}

/**
 * Lazy path: downloads the row's media from 360dialog and records it.
 *
 * @param array<string, mixed> $row
 * @return string|null absolute path, or null when there is nothing to get
 */
function fetch_media_now(array $row): ?string
{
    $mediaId = isset($row['wa_media_id']) ? (string) $row['wa_media_id'] : '';

    // Outbound rows and anything from before this column existed have no
    // id to fetch with, and Meta expires media after roughly 30 days
    // anyway -- so this can legitimately come up empty.
    if ($mediaId === '') {
        return null;
    }

    $file  = WhatsApp::client()->fetchMedia($mediaId);
    $saved = media_store($file['bytes'], $file['mime']);
    setMessageMedia((int) $row['id'], $saved['path'], $saved['mime'], $saved['size']);

    return $saved['abs'];
}

