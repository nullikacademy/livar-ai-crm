<?php
/**
 * 360dialog Cloud API client plus private on-disk media helpers.
 */

declare(strict_types=1);

require_once __DIR__ . '/load_config.php';

final class WhatsAppException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 500)
    {
        parent::__construct($message);
    }
}

final class WhatsApp
{
    private const BASE_URL = 'https://waba-v2.360dialog.io';

    private static ?self $instance = null;
    private string $apiKey;

    private function __construct()
    {
        $key = defined('D360_API_KEY') ? D360_API_KEY : '';
        if ($key === '' || str_starts_with($key, 'REPLACE_WITH')) {
            throw new WhatsAppException('WhatsApp is not configured yet.', 500);
        }
        $this->apiKey = $key;
    }

    public static function client(): self
    {
        return self::$instance ??= new self();
    }

    /** @return array<string, mixed> */
    public function sendText(string $to, string $body): array
    {
        return $this->jsonRequest('POST', '/messages', [
            'messaging_product' => 'whatsapp',
            'to'                => $this->recipient($to),
            'type'              => 'text',
            'text'              => [
                'body'        => $body,
                'preview_url' => true,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public function sendMedia(
        string $to,
        string $type,
        string $mediaId,
        string $caption = '',
        string $filename = ''
    ): array {
        if (!in_array($type, ['image', 'video', 'audio', 'document'], true)) {
            throw new InvalidArgumentException('Unsupported outbound media type.');
        }

        $media = ['id' => $mediaId];
        if ($caption !== '' && $type !== 'audio') {
            $media['caption'] = $caption;
        }
        if ($filename !== '' && $type === 'document') {
            $media['filename'] = $filename;
        }

        return $this->jsonRequest('POST', '/messages', [
            'messaging_product' => 'whatsapp',
            'to'                => $this->recipient($to),
            'type'              => $type,
            $type               => $media,
        ]);
    }

    /** @return array<string, mixed> */
    public function sendLocation(
        string $to,
        float $lat,
        float $lng,
        string $name = '',
        string $address = ''
    ): array {
        $location = ['latitude' => $lat, 'longitude' => $lng];
        if ($name !== '') {
            $location['name'] = $name;
        }
        if ($address !== '') {
            $location['address'] = $address;
        }

        return $this->jsonRequest('POST', '/messages', [
            'messaging_product' => 'whatsapp',
            'to'                => $this->recipient($to),
            'type'              => 'location',
            'location'          => $location,
        ]);
    }

    /** Uploads a local file and returns the provider media ID. */
    public function uploadMedia(string $path, string $mime): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new WhatsAppException('The selected attachment is no longer available.', 422);
        }

        $response = $this->request('POST', '/media', [
            'messaging_product' => 'whatsapp',
            'file'              => new CURLFile($path, $mime, basename($path)),
        ], false);

        $mediaId = $response['id'] ?? null;
        if (!is_string($mediaId) || $mediaId === '') {
            error_log('[WhatsApp] Upload response did not contain a media id.');
            throw new WhatsAppException('WhatsApp did not accept the attachment.', 502);
        }

        return $mediaId;
    }

    /**
     * Resolves a media ID and downloads the bytes through 360dialog.
     *
     * @return array{bytes:string, mime:string, size:int}
     */
    public function fetchMedia(string $mediaId): array
    {
        $metadata = $this->jsonRequest('GET', '/' . rawurlencode($mediaId));
        $sourceUrl = $metadata['url'] ?? null;
        if (!is_string($sourceUrl) || $sourceUrl === '') {
            throw new WhatsAppException('WhatsApp media metadata was incomplete.', 502);
        }

        $declaredSize = isset($metadata['file_size']) ? (int) $metadata['file_size'] : 0;
        if ($declaredSize > whatsappMaxMediaBytes()) {
            throw new WhatsAppException('This WhatsApp attachment exceeds the media size limit.', 413);
        }

        $parts = parse_url($sourceUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($host, ['lookaside.fbsbx.com', 'waba-v2.360dialog.io'], true)) {
            error_log('[WhatsApp] Refused unexpected media host: ' . $host);
            throw new WhatsAppException('WhatsApp returned an invalid media URL.', 502);
        }

        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $downloadUrl = self::BASE_URL . $path . $query;

        [$bytes, $reportedMime] = $this->downloadBytes($downloadUrl);
        $mime = is_string($metadata['mime_type'] ?? null) && $metadata['mime_type'] !== ''
            ? $metadata['mime_type']
            : $reportedMime;

        return ['bytes' => $bytes, 'mime' => normalizeMediaMime($mime), 'size' => strlen($bytes)];
    }

    /** @return array<string, mixed> */
    private function jsonRequest(string $method, string $path, ?array $payload = null): array
    {
        return $this->request($method, $path, $payload, true);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload, bool $json): array
    {
        $headers = ['D360-API-KEY: ' . $this->apiKey];
        $postFields = null;
        if ($payload !== null) {
            if ($json) {
                $headers[] = 'Content-Type: application/json';
                $postFields = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $postFields = $payload;
            }
        }

        $ch = curl_init(self::BASE_URL . '/' . ltrim($path, '/'));
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($postFields !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            error_log("[WhatsApp] curl error ({$errno}): {$error}");
            throw new WhatsAppException('Could not reach WhatsApp. Please try again.', 502);
        }

        $decoded = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300) {
            error_log("[WhatsApp] {$method} {$path} -> HTTP {$status}: " . (string) $raw);
            throw new WhatsAppException(
                $this->providerMessage(is_array($decoded) ? $decoded : [], $status),
                $status > 0 ? $status : 502
            );
        }

        if (!is_array($decoded)) {
            error_log("[WhatsApp] {$method} {$path} returned invalid JSON: " . (string) $raw);
            throw new WhatsAppException('WhatsApp returned an unexpected response.', 502);
        }

        return $decoded;
    }

    /** @return array{0:string, 1:string} */
    private function downloadBytes(string $url): array
    {
        $bytes = '';
        $tooLarge = false;
        $max = whatsappMaxMediaBytes();

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['D360-API-KEY: ' . $this->apiKey],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_WRITEFUNCTION  => static function ($handle, string $chunk) use (&$bytes, &$tooLarge, $max): int {
                if (strlen($bytes) + strlen($chunk) > $max) {
                    $tooLarge = true;
                    return 0;
                }
                $bytes .= $chunk;
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($tooLarge) {
            throw new WhatsAppException('This WhatsApp attachment exceeds the media size limit.', 413);
        }
        if ($errno !== 0) {
            error_log("[WhatsApp] media curl error ({$errno}): {$error}");
            throw new WhatsAppException('Could not download the WhatsApp attachment.', 502);
        }
        if ($status < 200 || $status >= 300) {
            error_log("[WhatsApp] media download -> HTTP {$status}: " . substr($bytes, 0, 2000));
            throw new WhatsAppException('WhatsApp could not provide this attachment.', 502);
        }

        return [$bytes, normalizeMediaMime($mime)];
    }

    private function recipient(string $to): string
    {
        $digits = preg_replace('/\D+/', '', $to) ?? '';
        if ($digits === '') {
            throw new InvalidArgumentException('A valid WhatsApp recipient is required.');
        }
        return $digits;
    }

    /** @param array<string, mixed> $response */
    private function providerMessage(array $response, int $status): string
    {
        $message = null;
        if (is_array($response['error'] ?? null)) {
            $message = $response['error']['message'] ?? null;
        }
        if (!is_string($message) && is_array($response['errors'][0] ?? null)) {
            $message = $response['errors'][0]['message'] ?? null;
        }
        if (!is_string($message) && is_string($response['message'] ?? null)) {
            $message = $response['message'];
        }
        return is_string($message) && trim($message) !== ''
            ? trim($message)
            : "WhatsApp rejected the request (HTTP {$status}).";
    }
}

/** Configured maximum media bytes with a safe default for older config files. */
function whatsappMaxMediaBytes(): int
{
    $configured = defined('WHATSAPP_MAX_MEDIA_BYTES') ? (int) WHATSAPP_MAX_MEDIA_BYTES : 16 * 1024 * 1024;
    return max(1024, $configured);
}

/** Removes a Content-Type parameter and normalizes case. */
function normalizeMediaMime(string $mime): string
{
    return strtolower(trim(explode(';', $mime, 2)[0]));
}

/** MIME-to-extension allowlist used for every server-generated filename. */
function mediaMimeExtension(string $mime): ?string
{
    static $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/3gpp' => '3gp',
        'audio/aac' => 'aac',
        'audio/amr' => 'amr',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/ogg' => 'ogg',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/zip' => 'zip',
        'application/octet-stream' => 'bin',
    ];

    return $extensions[normalizeMediaMime($mime)] ?? null;
}

/** Returns image, video, or document for an allowed outbound MIME. */
function outboundWhatsAppTypeForMime(string $mime): ?string
{
    $mime = normalizeMediaMime($mime);
    if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
        return 'image';
    }
    if (in_array($mime, ['video/mp4', 'video/3gpp'], true)) {
        return 'video';
    }

    $documents = [
        'text/plain',
        'text/csv',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/zip',
    ];
    return in_array($mime, $documents, true) ? 'document' : null;
}

/** Ensures and returns the absolute private media root. */
function mediaRoot(): string
{
    $root = dirname(__DIR__) . '/storage/media';
    if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
        throw new WhatsAppException('The media storage directory is unavailable.', 500);
    }

    $real = realpath($root);
    if ($real === false) {
        throw new WhatsAppException('The media storage directory is unavailable.', 500);
    }
    return $real;
}

/**
 * Stores downloaded bytes below media/YYYY/MM and returns a relative path.
 *
 * @param array{bytes:string, mime:string, size:int} $media
 * @return array{path:string, mime:string, size:int}
 */
function storeWhatsAppMedia(array $media): array
{
    $mime = normalizeMediaMime($media['mime']);
    $extension = mediaMimeExtension($mime);
    $size = strlen($media['bytes']);
    if ($extension === null) {
        throw new WhatsAppException('This WhatsApp media type is not supported.', 415);
    }
    if ($size > whatsappMaxMediaBytes()) {
        throw new WhatsAppException('This WhatsApp attachment exceeds the media size limit.', 413);
    }

    $relativeDir = gmdate('Y/m');
    $directory = mediaRoot() . '/' . $relativeDir;
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new WhatsAppException('The media storage directory is unavailable.', 500);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $target = $directory . '/' . $filename;
    if (file_put_contents($target, $media['bytes'], LOCK_EX) !== $size) {
        throw new WhatsAppException('The WhatsApp attachment could not be saved.', 500);
    }
    chmod($target, 0640);

    return ['path' => $relativeDir . '/' . $filename, 'mime' => $mime, 'size' => $size];
}

/**
 * Moves a validated PHP upload into the private outbox and writes its opaque
 * metadata sidecar.
 *
 * @return array{ref:string, path:string, absolute_path:string, mime:string, size:int, name:string, type:string}
 */
function storeOutboxUpload(string $temporaryPath, string $mime, int $size, string $originalName): array
{
    $mime = normalizeMediaMime($mime);
    $extension = mediaMimeExtension($mime);
    $type = outboundWhatsAppTypeForMime($mime);
    if ($extension === null || $type === null) {
        throw new WhatsAppException('Choose a supported image, video, or document.', 415);
    }
    if ($size <= 0 || $size > whatsappMaxMediaBytes()) {
        throw new WhatsAppException('The attachment exceeds the configured media size limit.', 413);
    }

    $directory = mediaRoot() . '/outbox';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new WhatsAppException('The media outbox is unavailable.', 500);
    }

    $ref = bin2hex(random_bytes(16));
    $relativePath = 'outbox/' . $ref . '.' . $extension;
    $target = mediaRoot() . '/' . $relativePath;
    $moved = is_uploaded_file($temporaryPath)
        ? move_uploaded_file($temporaryPath, $target)
        : rename($temporaryPath, $target);
    if (!$moved) {
        throw new WhatsAppException('The attachment could not be saved.', 500);
    }
    chmod($target, 0640);

    $safeName = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', basename($originalName)));
    $safeName = $safeName === '' ? 'Attachment.' . $extension : substr($safeName, 0, 180);
    $metadata = [
        'ref'   => $ref,
        'path'  => $relativePath,
        'mime'  => $mime,
        'size'  => $size,
        'name'  => $safeName,
        'type'  => $type,
    ];
    $metadataPath = $directory . '/' . $ref . '.json';
    $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($metadataPath, $encoded, LOCK_EX) === false) {
        @unlink($target);
        throw new WhatsAppException('The attachment metadata could not be saved.', 500);
    }
    chmod($metadataPath, 0640);

    return $metadata + ['absolute_path' => $target];
}

/** Resolves an opaque outbox reference through its server-owned sidecar. */
function resolveOutboxUpload(string $ref): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $ref)) {
        return null;
    }

    $metadataPath = mediaRoot() . '/outbox/' . $ref . '.json';
    $raw = is_file($metadataPath) ? file_get_contents($metadataPath) : false;
    $metadata = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($metadata) || ($metadata['ref'] ?? '') !== $ref) {
        return null;
    }

    $absolute = resolveMediaPath(is_string($metadata['path'] ?? null) ? $metadata['path'] : '');
    if ($absolute === null) {
        return null;
    }

    return array_merge($metadata, ['absolute_path' => $absolute]);
}

/** Resolves a stored relative path and asserts it remains below mediaRoot(). */
function resolveMediaPath(string $relativePath): ?string
{
    if ($relativePath === '' || str_contains($relativePath, "\0")) {
        return null;
    }

    $root = mediaRoot();
    $resolved = realpath($root . '/' . ltrim($relativePath, '/'));
    if ($resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return is_file($resolved) ? $resolved : null;
}

/** Calculates current media disk usage for operational logs. */
function mediaDiskUsageBytes(): int
{
    $total = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(mediaRoot(), FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $total += $file->getSize();
        }
    }
    return $total;
}
