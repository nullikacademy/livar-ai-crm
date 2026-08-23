<?php
/** Authenticated private outbox upload. */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_auth();
require_once __DIR__ . '/../config/whatsapp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

try {
    $upload = $_FILES['file'] ?? null;
    if (!is_array($upload) || !isset($upload['tmp_name'], $upload['error'])) {
        json_error('Choose a file to upload', 422);
    }
    if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
        $message = in_array((int) $upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'The attachment exceeds the server upload limit.'
            : 'The attachment upload did not complete.';
        json_error($message, 422);
    }

    $temporaryPath = (string) $upload['tmp_name'];
    $size = filesize($temporaryPath);
    if ($size === false || $size <= 0 || $size > whatsappMaxMediaBytes()) {
        json_error('The attachment exceeds the configured media size limit.', 413);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        throw new RuntimeException('The server MIME detector is unavailable.');
    }
    $mime = normalizeMediaMime((string) finfo_file($finfo, $temporaryPath));
    finfo_close($finfo);
    if (outboundWhatsAppTypeForMime($mime) === null) {
        json_error('Choose a supported image, video, or document.', 415);
    }

    $stored = storeOutboxUpload(
        $temporaryPath,
        $mime,
        (int) $size,
        is_string($upload['name'] ?? null) ? $upload['name'] : 'Attachment'
    );

    json_response([
        'success'   => true,
        'media_ref' => $stored['ref'],
        'type'      => $stored['type'],
        'mime'      => $stored['mime'],
        'size'      => $stored['size'],
        'name'      => $stored['name'],
    ], 201);
} catch (WhatsAppException $e) {
    error_log('[api/upload] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus >= 500 ? 500 : $e->httpStatus);
} catch (Throwable $e) {
    error_log('[api/upload] ' . $e->getMessage());
    json_error('The attachment could not be uploaded.', 500);
}
