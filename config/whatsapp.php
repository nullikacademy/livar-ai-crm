<?php
/**
 * config/whatsapp.php
 *
 * Talks to WhatsApp through 360dialog's Cloud API proxy. Mirrors the
 * style of config/database.php -- a small curl client, no SDK, no
 * Composer -- but stays a separate file because db_functions.php is the
 * Supabase layer and should not grow a second provider inside it.
 *
 * 360dialog proxies Meta's Cloud API, so every payload below is Cloud
 * API shaped; the only differences are the base URL and that auth is a
 * D360-API-KEY header instead of a bearer token.
 *
 * Set D360_API_KEY (and optionally D360_BASE_URL) in config/config.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/load_config.php';

/**
 * Thrown when a 360dialog call fails. Carries the HTTP status so callers
 * can translate it, matching the SupabaseException pattern.
 */
final class WhatsAppException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 500)
    {
        parent::__construct($message);
    }
}

/**
 * Minimal 360dialog / WhatsApp Cloud API client. Only depends on ext-curl.
 */
final class WhatsApp
{
    private static ?self $instance = null;

    private string $baseUrl;
    private string $apiKey;

    private function __construct()
    {
        $key = defined('D360_API_KEY') ? (string) D360_API_KEY : '';
        $url = defined('D360_BASE_URL') ? (string) D360_BASE_URL : 'https://waba-v2.360dialog.io';

        if ($key === '' || str_starts_with($key, 'REPLACE_WITH')) {
            error_log('[WhatsApp] D360_API_KEY has not been set in config/config.php.');
            throw new WhatsAppException('WhatsApp is not configured yet: set D360_API_KEY in config/config.php.', 500);
        }

        $this->baseUrl = rtrim($url, '/');
        $this->apiKey  = $key;
    }

    public static function client(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Largest media file we will move in either direction.
     */
    public static function maxMediaBytes(): int
    {
        return defined('WHATSAPP_MAX_MEDIA_BYTES') ? (int) WHATSAPP_MAX_MEDIA_BYTES : 16 * 1024 * 1024;
    }

    // -----------------------------------------------------------------
    // Sending
    // -----------------------------------------------------------------

    /**
     * Sends a plain text message.
     *
     * preview_url lets WhatsApp render a link card for URLs in the body,
     * which is what an agent pasting a product link expects to happen.
     *
     * @return array<string, mixed> the provider's response
     */
    public function sendText(string $to, string $body): array
    {
        return $this->send([
            'to'   => $to,
            'type' => 'text',
            'text' => ['body' => $body, 'preview_url' => true],
        ]);
    }

    /**
     * Sends an already-uploaded media file by its media id.
     *
     * $type is image, video, audio or document. Only document carries a
     * filename, and audio carries no caption -- WhatsApp rejects both.
     *
     * @return array<string, mixed>
     */
    public function sendMedia(string $to, string $type, string $mediaId, string $caption = '', string $filename = ''): array
    {
        $allowed = ['image', 'video', 'audio', 'document'];
        if (!in_array($type, $allowed, true)) {
            throw new WhatsAppException('Unsupported media type: ' . $type, 422);
        }

        $media = ['id' => $mediaId];
        if ($caption !== '' && $type !== 'audio') {
            $media['caption'] = $caption;
        }
        if ($filename !== '' && $type === 'document') {
            $media['filename'] = $filename;
        }

        return $this->send([
            'to'   => $to,
            'type' => $type,
            $type  => $media,
        ]);
    }

    /**
     * Sends a pin on the map.
     *
     * @return array<string, mixed>
     */
    public function sendLocation(string $to, float $lat, float $lng, string $name = '', string $address = ''): array
    {
        $location = ['latitude' => $lat, 'longitude' => $lng];
        if ($name !== '') {
            $location['name'] = $name;
        }
        if ($address !== '') {
            $location['address'] = $address;
        }

        return $this->send([
            'to'       => $to,
            'type'     => 'location',
            'location' => $location,
        ]);
    }

    /**
     * Puts an emoji on a message the customer sent, or takes it off.
     *
     * `message_id` is the message being reacted to. An empty emoji is how
     * WhatsApp spells removal -- not an error, and not something to guard
     * against, since taking a reaction back is a thing people do.
     *
     * @return array<string, mixed>
     */
    public function sendReaction(string $to, string $messageId, string $emoji): array
    {
        if ($messageId === '') {
            throw new WhatsAppException('There is no message to react to.', 422);
        }

        return $this->send([
            'to'       => $to,
            'type'     => 'reaction',
            'reaction' => [
                'message_id' => $messageId,
                // One emoji, or empty to remove. Anything longer is not a
                // reaction, and Meta rejects it.
                'emoji'      => $emoji === '' ? '' : mb_substr($emoji, 0, 8),
            ],
        ]);
    }

    /**
     * Sends an approved message template.
     *
     * This is the ONLY thing WhatsApp will deliver once the 24-hour
     * free-form window has closed, which is the whole reason it exists
     * here: without it a conversation that goes quiet overnight can
     * never be restarted from the CRM.
     *
     * $bodyParams fills the template's {{1}}, {{2}}, ... placeholders in
     * order. A template with none takes an empty array and sends no
     * components at all -- Meta rejects an empty parameters list.
     *
     * @param array<int, string> $bodyParams
     * @return array<string, mixed>
     */
    public function sendTemplate(string $to, string $name, string $language, array $bodyParams = []): array
    {
        if ($name === '' || $language === '') {
            throw new WhatsAppException('A template needs a name and a language.', 422);
        }

        $template = [
            'name'     => $name,
            'language' => ['code' => $language],
        ];

        if ($bodyParams) {
            $template['components'] = [[
                'type'       => 'body',
                'parameters' => array_map(
                    static fn(string $value): array => ['type' => 'text', 'text' => $value],
                    array_values($bodyParams)
                ),
            ]];
        }

        return $this->send([
            'to'       => $to,
            'type'     => 'template',
            'template' => $template,
        ]);
    }

    /**
     * Sends a question with tappable reply buttons.
     *
     * The customer answers by tapping, and the tap comes back on the
     * webhook as an `interactive` message carrying the button's id and
     * title -- so this is how the CRM asks a question it can actually
     * read the answer to, instead of hoping for a parseable sentence.
     *
     * WhatsApp's own limits are enforced here rather than trusted to the
     * provider: at most three buttons, 20 characters each, and no
     * duplicate ids.
     *
     * @param array<int, string> $buttons the option labels, in order
     * @return array<string, mixed>
     */
    public function sendButtons(string $to, string $body, array $buttons, string $footer = ''): array
    {
        $buttons = array_values(array_filter(array_map('trim', $buttons), static fn(string $b): bool => $b !== ''));

        if ($body === '') {
            throw new WhatsAppException('A question needs something to ask.', 422);
        }
        if (count($buttons) < 1 || count($buttons) > self::MAX_REPLY_BUTTONS) {
            throw new WhatsAppException(
                'A question needs between 1 and ' . self::MAX_REPLY_BUTTONS . ' answer buttons.',
                422
            );
        }

        $replies = [];
        foreach ($buttons as $index => $label) {
            if (mb_strlen($label) > self::MAX_BUTTON_LABEL) {
                throw new WhatsAppException(
                    'WhatsApp allows ' . self::MAX_BUTTON_LABEL . ' characters on a button; "'
                    . $label . '" is longer.',
                    422
                );
            }
            $replies[] = [
                'type'  => 'reply',
                // The id is ours, not the label: two buttons could
                // legitimately read the same in different languages, and
                // WhatsApp requires the ids to be distinct.
                'reply' => ['id' => 'opt_' . $index, 'title' => $label],
            ];
        }

        $interactive = [
            'type'   => 'button',
            'body'   => ['text' => mb_substr($body, 0, 1024)],
            'action' => ['buttons' => $replies],
        ];
        if ($footer !== '') {
            $interactive['footer'] = ['text' => mb_substr($footer, 0, 60)];
        }

        return $this->send([
            'to'          => $to,
            'type'        => 'interactive',
            'interactive' => $interactive,
        ]);
    }

    /** WhatsApp's ceiling on reply buttons in one interactive message. */
    public const MAX_REPLY_BUTTONS = 3;

    /** WhatsApp's ceiling on a reply button's label. */
    public const MAX_BUTTON_LABEL = 20;

    /**
     * Lists the message templates registered on this WhatsApp number.
     *
     * Only APPROVED ones are any use -- sending a rejected or pending
     * template just fails at Meta -- but everything is returned with its
     * status so the settings/templates UI can say why a template it can
     * see is not offered.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTemplates(): array
    {
        [$body, $status, ] = $this->rawGetQuiet($this->baseUrl . '/v1/configs/templates');

        if ($status >= 400 || $status === 0) {
            error_log("[WhatsApp] GET /v1/configs/templates -> HTTP {$status}: " . mb_substr($body, 0, 400));
            throw new WhatsAppException(
                self::providerMessage($body, 'WhatsApp would not list your message templates.'),
                $status >= 400 ? $status : 502
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new WhatsAppException('WhatsApp returned an unreadable template list.', 502);
        }

        // 360dialog answers with waba_templates; Meta's own Cloud API
        // uses data. Accept either rather than depend on which proxy
        // version an account is on.
        $rows = $decoded['waba_templates'] ?? $decoded['data'] ?? $decoded;
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * Pulls the provider's message id out of a send response, which is
     * what gets stored so statuses[] webhooks can find the row again.
     *
     * @param array<string, mixed> $response
     */
    public static function messageIdFrom(array $response): string
    {
        $id = $response['messages'][0]['id'] ?? '';
        return is_string($id) ? $id : '';
    }

    // -----------------------------------------------------------------
    // Media
    // -----------------------------------------------------------------

    /**
     * Uploads a local file and returns the media id to send it by.
     */
    public function uploadMedia(string $path, string $mime): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new WhatsAppException('That file is no longer available to send.', 422);
        }

        $size = (int) filesize($path);
        if ($size > self::maxMediaBytes()) {
            throw new WhatsAppException('That file is larger than WhatsApp allows.', 422);
        }

        $post = [
            'messaging_product' => 'whatsapp',
            'file'              => new CURLFile($path, $mime, basename($path)),
        ];

        // Multipart, so this one call cannot go through request().
        $ch = curl_init($this->baseUrl . '/media');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_HTTPHEADER     => ['D360-API-KEY: ' . $this->apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            error_log("[WhatsApp] media upload curl error ({$errno}): {$error}");
            throw new WhatsAppException('Could not reach WhatsApp to upload the file.', 502);
        }
        if ($status >= 400) {
            error_log("[WhatsApp] POST /media -> HTTP {$status}: {$raw}");
            throw new WhatsAppException(self::providerMessage((string) $raw, 'WhatsApp rejected the file.'), $status);
        }

        $decoded = json_decode((string) $raw, true);
        $id      = is_array($decoded) ? ($decoded['id'] ?? '') : '';

        if (!is_string($id) || $id === '') {
            error_log("[WhatsApp] POST /media returned no id: {$raw}");
            throw new WhatsAppException('WhatsApp did not return an id for the uploaded file.', 502);
        }

        return $id;
    }

    /**
     * Downloads an inbound media file.
     *
     * This is two hops, not one. `GET /<media-id>` returns JSON with a
     * `url` pointing at lookaside.fbsbx.com, which will not accept the
     * 360dialog key; the host has to be swapped back to the 360dialog
     * one and the key re-sent to actually get the bytes.
     *
     * @return array{bytes: string, mime: string, size: int}
     */
    public function fetchMedia(string $mediaId): array
    {
        if ($mediaId === '') {
            throw new WhatsAppException('No media id to download.', 422);
        }

        [$meta, $status] = $this->rawGet($this->baseUrl . '/' . rawurlencode($mediaId));
        if ($status >= 400) {
            error_log("[WhatsApp] GET /{$mediaId} -> HTTP {$status}: {$meta}");
            throw new WhatsAppException('WhatsApp would not describe that media file.', $status);
        }

        $decoded = json_decode($meta, true);
        $url     = is_array($decoded) ? ($decoded['url'] ?? '') : '';
        $mime    = is_array($decoded) ? (string) ($decoded['mime_type'] ?? '') : '';
        $size    = is_array($decoded) ? (int) ($decoded['file_size'] ?? 0) : 0;

        if (!is_string($url) || $url === '') {
            error_log("[WhatsApp] media metadata had no url: {$meta}");
            throw new WhatsAppException('WhatsApp returned no download link for that media file.', 502);
        }

        if ($size > 0 && $size > self::maxMediaBytes()) {
            throw new WhatsAppException('That media file is larger than WHATSAPP_MAX_MEDIA_BYTES.', 413);
        }

        [$bytes, $binStatus, $binMime] = $this->rawGet(self::rewriteMediaHost($url), true);
        if ($binStatus >= 400) {
            error_log("[WhatsApp] media download -> HTTP {$binStatus}");
            throw new WhatsAppException('Could not download that media file from WhatsApp.', $binStatus);
        }

        $length = strlen($bytes);
        if ($length === 0) {
            throw new WhatsAppException('WhatsApp returned an empty media file.', 502);
        }
        if ($length > self::maxMediaBytes()) {
            throw new WhatsAppException('That media file is larger than WHATSAPP_MAX_MEDIA_BYTES.', 413);
        }

        return [
            'bytes' => $bytes,
            'mime'  => $mime !== '' ? $mime : ($binMime !== '' ? $binMime : 'application/octet-stream'),
            'size'  => $length,
        ];
    }

    /**
     * Points a lookaside.fbsbx.com download link back at 360dialog.
     *
     * Meta hands out the link, but only 360dialog will authenticate us
     * for it -- fetching lookaside directly with the D360 key gets a 401.
     */
    public static function rewriteMediaHost(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return $url;
        }

        $base = parse_url(defined('D360_BASE_URL') ? (string) D360_BASE_URL : 'https://waba-v2.360dialog.io');
        $host = $base['host'] ?? 'waba-v2.360dialog.io';
        // Carry the port over too. The real base URL has none, but
        // dropping it silently sends the download to port 80.
        if (isset($base['port'])) {
            $host .= ':' . $base['port'];
        }

        $rebuilt = ($base['scheme'] ?? 'https') . '://' . $host . ($parts['path'] ?? '');
        if (isset($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }

        return $rebuilt;
    }

    // -----------------------------------------------------------------
    // Health probes
    //
    // These are the only calls that REPORT a failure instead of throwing
    // one: the settings page needs to show what happened, including the
    // status code, rather than get an exception it has to translate back.
    // -----------------------------------------------------------------

    /**
     * Checks whether the API key is accepted, without sending anything.
     *
     * There is no ping endpoint, so this asks for a media id that cannot
     * exist. A key that works gets 404 (or 400) from Meta with an error
     * body; a key that doesn't gets 401/403 before the id is ever looked
     * up. That difference is the whole test.
     *
     * @return array{status: int, body: string, error: string}
     */
    public function probeAuth(): array
    {
        [$body, $status, ] = $this->rawGetQuiet($this->baseUrl . '/0');
        return ['status' => $status, 'body' => mb_substr($body, 0, 400), 'error' => ''];
    }

    /**
     * Reads back the webhook URL currently registered with 360dialog, so
     * the settings page can tell you whether it matches the one this
     * install actually answers on -- a mismatch is the single most common
     * reason inbound messages silently never arrive.
     *
     * @return array{status: int, url: string, body: string}
     */
    public function webhookConfig(): array
    {
        [$body, $status, ] = $this->rawGetQuiet($this->baseUrl . '/v1/configs/webhook');

        $url     = '';
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $candidate = $decoded['url'] ?? $decoded['webhook']['url'] ?? '';
            if (is_string($candidate)) {
                $url = $candidate;
            }
        }

        return ['status' => $status, 'url' => $url, 'body' => mb_substr($body, 0, 400)];
    }

    /**
     * Like rawGet(), but returns transport failures as status 0 instead
     * of throwing. Only the probes above use it.
     *
     * @return array{0: string, 1: int, 2: string}
     */
    private function rawGetQuiet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['D360-API-KEY: ' . $this->apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            error_log("[WhatsApp] probe curl error ({$errno}): {$error}");
            return ['', 0, $error];
        }

        return [(string) $raw, $status, ''];
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * POST /messages with the shared envelope fields filled in.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function send(array $payload): array
    {
        // Callers pass the customer's address as `to` without caring what
        // kind it is. A phone number stays in `to`; a business-scoped user
        // id -- the only address a WhatsApp username adopter has -- moves
        // to `recipient`. Done here so all six send methods and anything
        // added later get it for free.
        if (isset($payload['to'])) {
            $addressed = self::recipient((string) $payload['to']);
            unset($payload['to']);
            $payload = array_merge($addressed, $payload);
        }

        $payload = array_merge(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual'], $payload);

        $ch = curl_init($this->baseUrl . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'D360-API-KEY: ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            error_log("[WhatsApp] send curl error ({$errno}): {$error}");
            throw new WhatsAppException('Could not reach WhatsApp. Please try again.', 502);
        }

        if ($status >= 400) {
            error_log("[WhatsApp] POST /messages -> HTTP {$status}: {$raw}");
            // Unlike the Supabase layer, the provider's own wording is
            // surfaced: "message failed" with no reason is unusable to an
            // agent who needs to know whether to retry or to call.
            throw new WhatsAppException(self::providerMessage((string) $raw, 'WhatsApp rejected the message.'), $status);
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * GET with the API key attached.
     *
     * @return array{0: string, 1: int, 2: string} body, status, content-type
     */
    private function rawGet(string $url, bool $binary = false): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['D360-API-KEY: ' . $this->apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $binary ? 60 : 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($errno !== 0) {
            error_log("[WhatsApp] GET curl error ({$errno}): {$error}");
            throw new WhatsAppException('Could not reach WhatsApp.', 502);
        }

        // "image/jpeg; charset=binary" -> "image/jpeg"
        if (str_contains($mime, ';')) {
            $mime = trim(strstr($mime, ';', true) ?: $mime);
        }

        return [(string) $raw, $status, $mime];
    }

    /**
     * Extracts Meta's own error text from a response body, falling back
     * to $default when the shape is unfamiliar.
     */
    private static function providerMessage(string $body, string $default): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $default;
        }

        $error = $decoded['error'] ?? null;
        if (is_array($error)) {
            $parts = array_filter([
                is_string($error['message'] ?? null) ? $error['message'] : null,
                is_string($error['error_data']['details'] ?? null) ? $error['error_data']['details'] : null,
            ]);
            if ($parts) {
                return implode(' — ', $parts);
            }
        }

        if (is_string($decoded['message'] ?? null)) {
            return $decoded['message'];
        }

        return $default;
    }

    /**
     * Rewrites Markdown emphasis as WhatsApp's own.
     *
     * WhatsApp is not Markdown and predates it in this space: bold is
     * ONE asterisk, `*like this*`, and strikethrough is one tilde. A
     * model writing `**bold**` is writing correct Markdown and wrong
     * WhatsApp -- what the customer sees is a bold word with a stray
     * asterisk stuck to each end, because WhatsApp consumes the inner
     * pair and renders the outer one literally.
     *
     * Applied to drafts rather than to everything an agent types: a
     * person writing `**` in the composer meant to, and rewriting it
     * under them would be the CRM arguing with its own user. See
     * api/draft.php.
     *
     * Italic is left alone -- `_italic_` means the same thing in both --
     * and so are fenced code blocks, which WhatsApp also spells ```.
     */
    public static function fromMarkdown(string $text): string
    {
        $rules = [
            // Bold. Both Markdown spellings collapse to WhatsApp's one
            // asterisk. The lookarounds stop `** stray` or a lone `**`
            // from matching, and require the run to start and end on a
            // non-space, which is what Markdown itself demands.
            '/(?<!\*)\*\*(?!\s)(.+?)(?<!\s)\*\*(?!\*)/su' => '*$1*',
            '/(?<!_)__(?!\s)(.+?)(?<!\s)__(?!_)/su'       => '*$1*',

            // Strikethrough: two tildes in Markdown, one in WhatsApp.
            '/(?<!~)~~(?!\s)(.+?)(?<!\s)~~(?!~)/su'       => '~$1~',

            // A heading has no equivalent at all, so it becomes the
            // nearest thing WhatsApp has. Left as `### Prices` it would
            // reach the customer with the hashes still on it.
            '/^[ \t]*#{1,6}[ \t]+(.+?)[ \t]*$/mu'         => '*$1*',

            // Markdown bullets render as literal `-` or `*` on WhatsApp.
            // A real bullet is both shorter and what the model meant.
            '/^[ \t]*[-*+][ \t]+/mu'                      => '• ',
        ];

        foreach ($rules as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        // `[our catalogue](https://…)` shows the customer neither a link
        // nor the words, just the punctuation around both.
        $text = preg_replace_callback(
            '/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/u',
            static fn(array $m): string => $m[1] === $m[2] ? $m[2] : $m[1] . ' ' . $m[2],
            $text
        ) ?? $text;

        return $text;
    }

    /**
     * Addresses a message to a phone number OR a business-scoped user id.
     *
     * A customer who reached us through a WhatsApp username never gave us
     * a number, so there is nothing to put in `to`. Meta addresses them by
     * their BSUID in `recipient` instead. The two are mutually exclusive
     * in practice, and `to` wins when both are somehow set, so this
     * returns exactly one of them.
     *
     * Telling them apart on shape rather than on a flag keeps every
     * caller's signature unchanged: a phone number is digits once its
     * punctuation is stripped, and a BSUID never is.
     *
     * @return array{to: string}|array{recipient: string}
     */
    private static function recipient(string $to): array
    {
        $to = trim($to);
        if ($to === '') {
            throw new WhatsAppException('That customer has no usable WhatsApp address.', 422);
        }

        // Deliberately not normalizeWaId(): that strips letters, which
        // would turn a BSUID into a run of digits and send the message to
        // whatever phone number those digits happen to spell.
        $alnum = preg_replace('/[^A-Za-z0-9]+/', '', $to) ?? '';
        if ($alnum !== '' && ctype_digit($alnum)) {
            return ['to' => $alnum];
        }

        // Same allowlist as normalizeWaUserId() in db_functions.php, kept
        // here rather than shared so this client stays free of the data
        // layer. Anything outside it cannot be a Meta identifier.
        $userId = substr(preg_replace('/[^A-Za-z0-9._:=+\/~-]+/', '', $to) ?? '', 0, 128);
        if ($userId === '') {
            throw new WhatsAppException('That customer has no usable WhatsApp address.', 422);
        }

        return ['recipient' => $userId];
    }
}
