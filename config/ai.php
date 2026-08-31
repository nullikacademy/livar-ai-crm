<?php
/**
 * config/ai.php
 *
 * Talks to OpenAI directly. This is the work n8n used to do: the CRM now
 * owns the prompt, the model choice and the call, so drafting is one
 * fewer service that can be down and one fewer place the prompt can
 * drift out of sync.
 *
 * Same shape as the other clients here -- a small curl wrapper, no SDK,
 * no Composer. Set OPENAI_API_KEY in config/config.php.
 *
 * The system prompt and model are NOT constants: they live in the
 * livar_settings table so they can be edited from the settings page
 * without touching a file on the server. See config/db_functions.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/load_config.php';

/**
 * Thrown when an OpenAI call fails. Carries the HTTP status, matching
 * the SupabaseException / WhatsAppException pattern.
 */
final class AIException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 500)
    {
        parent::__construct($message);
    }
}

/**
 * Minimal OpenAI client. Only depends on ext-curl.
 */
final class AI
{
    private static ?self $instance = null;

    private string $baseUrl;
    private string $apiKey;

    private function __construct()
    {
        $key = defined('OPENAI_API_KEY') ? (string) OPENAI_API_KEY : '';
        $url = defined('OPENAI_BASE_URL') ? (string) OPENAI_BASE_URL : 'https://api.openai.com/v1';

        if ($key === '' || str_starts_with($key, 'REPLACE_WITH')) {
            error_log('[AI] OPENAI_API_KEY has not been set in config/config.php.');
            throw new AIException('The AI is not configured yet: set OPENAI_API_KEY in config/config.php.', 500);
        }

        $this->baseUrl = rtrim($url, '/');
        $this->apiKey  = $key;
    }

    public static function client(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * True when a key is present, without constructing the client. Used
     * by callers that must degrade quietly rather than throw -- the
     * inbound webhook still has to store the message if captioning is
     * not available.
     */
    public static function isConfigured(): bool
    {
        return defined('OPENAI_API_KEY')
            && OPENAI_API_KEY !== ''
            && !str_starts_with((string) OPENAI_API_KEY, 'REPLACE_WITH');
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Runs a chat completion and returns the assistant's text.
     *
     * $messages is the OpenAI messages array, so a `content` may be a
     * plain string or an array of parts -- which is how images travel.
     *
     * @param array<int, array<string, mixed>> $messages
     */
    public function chat(array $messages, string $model, float $temperature = 0.4, int $maxTokens = 900): string
    {
        $response = $this->post('/chat/completions', [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
        ], 90);

        $text = $response['choices'][0]['message']['content'] ?? '';

        if (!is_string($text) || trim($text) === '') {
            error_log('[AI] completion came back empty: ' . json_encode($response));
            throw new AIException('The AI returned an empty reply.', 502);
        }

        return trim($text);
    }

    /**
     * One short sentence describing an image on disk.
     *
     * Used for the sidebar preview and for older photos that fall outside
     * the window where the real image is attached to a draft. Kept
     * deliberately terse: this is a label, not an analysis.
     */
    public function describeImage(string $absPath, string $mime, string $model): string
    {
        $part = self::imagePart($absPath, $mime);
        if ($part === null) {
            throw new AIException('That image could not be read for describing.', 422);
        }

        return $this->chat([
            [
                'role'    => 'system',
                'content' => 'You label photos sent to a packaging company\'s sales inbox. '
                           . 'Reply with ONE short sentence naming what is shown and any text, '
                           . 'branding, size or quantity visible. No preamble, no speculation.',
            ],
            [
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Describe this photo.'],
                    $part,
                ],
            ],
        ], $model, 0.2, 120);
    }

    /**
     * Lists the model ids available to this key, so the settings page can
     * offer real choices instead of a hardcoded list that goes stale.
     *
     * @return array<int, string>
     */
    public function listModels(): array
    {
        $response = $this->get('/models');
        $ids      = [];

        foreach (($response['data'] ?? []) as $model) {
            $id = $model['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        sort($ids);
        return $ids;
    }

    /**
     * Builds an image content part from a file on disk.
     *
     * Sent as a base64 data URL rather than a link: the files live behind
     * this app's login, so OpenAI could not fetch a URL for them even if
     * we gave it one.
     *
     * @return array<string, mixed>|null
     */
    public static function imagePart(string $absPath, string $mime, string $detail = 'auto'): ?array
    {
        if (!is_file($absPath) || !is_readable($absPath)) {
            return null;
        }

        $bytes = @file_get_contents($absPath);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $mime = strtolower(trim($mime)) ?: 'image/jpeg';

        return [
            'type'      => 'image_url',
            'image_url' => [
                'url'    => 'data:' . $mime . ';base64,' . base64_encode($bytes),
                'detail' => $detail,
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Transport
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload, int $timeout): array
    {
        return $this->request('POST', $path, $payload, $timeout);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        return $this->request('GET', $path, null, 20);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload, int $timeout): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            error_log("[AI] curl error ({$errno}): {$error}");
            throw new AIException('Could not reach the AI service. Please try again.', 502);
        }

        if ($status >= 400) {
            // The body can contain the prompt, so it goes to the log only.
            error_log("[AI] {$method} {$path} -> HTTP {$status}: " . mb_substr((string) $raw, 0, 600));
            throw new AIException(self::errorMessage((string) $raw, $status), $status);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new AIException('The AI service returned something unreadable.', 502);
        }

        return $decoded;
    }

    /**
     * Turns OpenAI's error body into something an agent can act on.
     *
     * The provider's own wording is surfaced here for the same reason
     * api/send.php does it: "the AI failed" does not tell anyone whether
     * to top up a balance, fix a model name, or just retry.
     */
    private static function errorMessage(string $body, int $status): string
    {
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? '') : '';

        if (!is_string($message) || $message === '') {
            $message = match (true) {
                $status === 401 => 'OpenAI rejected the API key.',
                $status === 429 => 'OpenAI is rate limiting, or the account is out of credit.',
                $status >= 500  => 'OpenAI is having trouble. Try again shortly.',
                default         => 'OpenAI rejected the request.',
            };
        }

        return mb_substr($message, 0, 300);
    }
}
