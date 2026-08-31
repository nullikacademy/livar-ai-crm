<?php
/**
 * api/avatar.php
 *
 *   GET    /api/avatar.php?session_id=xxx        -> stream the photo
 *   POST   /api/avatar.php   (multipart: file, session_id)
 *   DELETE /api/avatar.php?session_id=xxx        -> remove it
 *
 * A customer's profile photo. WhatsApp does not hand out a contact's
 * own picture -- the Cloud API exposes the business profile's photo and
 * nothing about the person on the other end -- so this is a photo the
 * agent sets: a screenshot of a logo, a face from a business card, a
 * crop of something they already sent. Without one the CRM falls back to
 * initials, which is what it did before and still does.
 *
 * The path on disk is decided here and only here. `avatar_path` is
 * deliberately not in CUSTOMER_PROFILE_FIELDS, so the details form
 * cannot write it, and this endpoint never takes a path from the
 * request either -- it takes bytes, checks them, and generates the name
 * itself.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/media.php';

require_auth();

/**
 * Photos only. A profile picture that is a PDF is a mistake every time,
 * and this is a narrower list than the media store's on purpose.
 */
const AVATAR_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

/**
 * Nobody needs a 12 MB avatar, and every agent who opens the inbox pays
 * for it on every poll.
 */
const AVATAR_MAX_BYTES = 5 * 1024 * 1024;

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handleGet();
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
    error_log('[api/avatar] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (SupabaseException $e) {
    error_log('[api/avatar] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    error_log('[api/avatar] ' . $e->getMessage());
    json_error('Something went wrong with that profile photo.', 500);
}

/**
 * Streams the stored photo.
 */
function handleGet(): void
{
    $customer = requireCustomer($_GET['session_id'] ?? '');

    $path = (string) ($customer['avatar_path'] ?? '');
    $abs  = $path !== '' ? media_abs_path($path) : null;

    if ($abs === null) {
        // Either never set, or the file went missing under us. Both are
        // "there is no photo", which the browser handles by falling back
        // to initials.
        json_error('That customer has no profile photo.', 404);
    }

    media_stream($abs, avatarMime($abs));
}

/**
 * Stores an uploaded photo and points the customer at it.
 */
function handleUpload(): void
{
    $sessionId = trim((string) ($_POST['session_id'] ?? ''));
    $customer  = requireCustomer($sessionId);

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        json_error('No photo was uploaded.', 422);
    }

    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_error('That photo could not be uploaded.', 422);
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        json_error('That upload could not be read.', 422);
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        json_error('That file is empty.', 422);
    }
    if ($size > AVATAR_MAX_BYTES) {
        json_error('A profile photo has to be under ' . (AVATAR_MAX_BYTES / 1048576) . ' MB.', 422);
    }

    // The browser's Content-Type is not consulted. getimagesize() reads
    // the actual header bytes, so a .php renamed to .jpg fails here
    // rather than becoming a file in the media store.
    $mime = detectImageMime($tmp);
    if (!in_array($mime, AVATAR_MIME_TYPES, true)) {
        error_log('[api/avatar] rejected a non-image upload: ' . ($mime !== '' ? $mime : 'undetectable'));
        json_error('A profile photo has to be a JPEG, PNG, WebP or GIF image.', 422);
    }

    $bytes = file_get_contents($tmp);
    if ($bytes === false) {
        json_error('That upload could not be read.', 422);
    }

    // 'avatars' is a literal, never taken from the request.
    $saved = media_store($bytes, $mime, 'avatars');
    $row   = setCustomerAvatar($sessionId, $saved['path']);

    // Only once the new one is recorded: a failed write must not leave
    // the customer pointing at a file that is already gone.
    $previous = (string) ($customer['avatar_path'] ?? '');
    if ($previous !== '' && $previous !== $saved['path']) {
        media_delete($previous);
    }

    json_response([
        'success'  => true,
        'customer' => customerForBrowser($row ?? array_merge($customer, ['avatar_path' => $saved['path']])),
    ], 201);
}

/**
 * Clears the photo and deletes the file behind it.
 */
function handleDelete(): void
{
    $sessionId = trim((string) ($_GET['session_id'] ?? ''));
    $customer  = requireCustomer($sessionId);

    $row      = setCustomerAvatar($sessionId, null);
    $previous = (string) ($customer['avatar_path'] ?? '');
    if ($previous !== '') {
        media_delete($previous);
    }

    json_response([
        'success'  => true,
        'customer' => customerForBrowser($row ?? array_merge($customer, ['avatar_path' => ''])),
    ]);
}

/**
 * Loads the customer this request is about, or answers 404/422.
 *
 * @return array<string, mixed>
 */
function requireCustomer(string $sessionId): array
{
    $sessionId = trim($sessionId);
    if ($sessionId === '') {
        json_error('session_id is required', 422);
    }

    $customer = getCustomer($sessionId);
    if ($customer === null) {
        json_error('Customer not found', 404);
    }

    return $customer;
}

/**
 * The real image type of an uploaded file, or '' when it is not one.
 *
 * getimagesize() is used rather than finfo because it also proves the
 * file parses as an image, not merely that its first few bytes look
 * like one -- and unlike ext-fileinfo it is compiled in essentially
 * everywhere, including the shared hosts this app targets.
 */
function detectImageMime(string $path): string
{
    if (!function_exists('getimagesize')) {
        error_log('[api/avatar] getimagesize() is unavailable; cannot verify the image.');
        return '';
    }

    $info = @getimagesize($path);
    if (!is_array($info) || !isset($info['mime']) || !is_string($info['mime'])) {
        return '';
    }

    return media_normalize_mime($info['mime']);
}

/**
 * The mime to serve a stored avatar with, taken from its own extension.
 *
 * The extension came from the media store's allowlist when the file was
 * written, so this is reading back a value the server chose, not one a
 * browser supplied.
 */
function avatarMime(string $abs): string
{
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));

    return match ($ext) {
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        default => 'image/jpeg',
    };
}
