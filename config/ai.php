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
        $response = $this->completion([
            'model'                 => $model,
            'messages'              => $messages,
            'temperature'           => $temperature,
            // Newer models require this name; older ones require
            // max_tokens. completion() sorts that out from the API's own
            // error rather than from a list of model names here.
            'max_completion_tokens' => $maxTokens,
        ]);

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
     * POSTs a chat completion, adapting the payload to what the chosen
     * model actually accepts.
     *
     * OpenAI's parameter surface differs per model family: newer ones
     * reject `max_tokens` and require `max_completion_tokens`, and the
     * reasoning models reject any `temperature` but the default. Since
     * the model is picked by whoever edits the settings page, this cannot
     * be decided from a hardcoded list of names -- that list would be
     * wrong the day a new model ships, which is exactly when someone
     * would be trying it.
     *
     * So the API's own error is the source of truth: it names the
     * offending parameter and often the replacement, and we retry.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function completion(array $payload): array
    {
        // One attempt per parameter that could need adapting, plus one.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                return $this->post('/chat/completions', $payload, 90);
            } catch (AIException $e) {
                if ($e->httpStatus !== 400) {
                    throw $e;
                }
                $adapted = self::adaptPayload($payload, $e->getMessage());
                if ($adapted === null) {
                    throw $e;
                }
                error_log('[AI] retrying with an adapted payload after: ' . $e->getMessage());
                $payload = $adapted;
            }
        }

        throw new AIException('Could not find a request this model accepts.', 400);
    }

    /**
     * Rewrites a payload in response to a parameter complaint, or returns
     * null when the error is not about a parameter we can drop or rename.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function adaptPayload(array $payload, string $error): ?array
    {
        // "Unsupported parameter: 'max_tokens' is not supported with this
        //  model. Use 'max_completion_tokens' instead."
        if (preg_match("/'([a-z_]+)'.*?[Uu]se '([a-z_]+)' instead/", $error, $m)) {
            [, $from, $to] = $m;
            if (array_key_exists($from, $payload) && !array_key_exists($to, $payload)) {
                $payload[$to] = $payload[$from];
                unset($payload[$from]);
                return $payload;
            }
        }

        // "Unsupported value: 'temperature' does not support 0.4 with
        //  this model." Reasoning models only accept the default, so the
        //  parameter is dropped rather than guessed at.
        if (preg_match("/[Uu]nsupported (?:value|parameter): '([a-z_]+)'/", $error, $m)) {
            $name = $m[1];
            if ($name !== 'messages' && $name !== 'model' && array_key_exists($name, $payload)) {
                unset($payload[$name]);
                return $payload;
            }
        }

        return null;
    }

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
