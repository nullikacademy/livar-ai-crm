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

    /**
     * Which request dialect each model turned out to want.
     *
     * See chat(): OpenAI renamed `max_tokens` to `max_completion_tokens`
     * and newer models reject the old name outright, while an
     * OpenAI-compatible endpoint behind a custom OPENAI_BASE_URL may only
     * know the old one. Rather than keep a model list that goes stale,
     * the client sends the modern spelling and remembers what the
     * provider said if it complained.
     *
     * @var array<string, array{tokens: string, temperature: bool}>
     */
    private array $dialects = [];

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
     * The token cap is sent as `max_completion_tokens`. `max_tokens` is
     * the deprecated spelling and the newer models -- the o-series, and
     * gpt-5 and later -- refuse a request that uses it, which is a hard
     * 400 rather than a warning. The same models also refuse a
     * `temperature` other than the default.
     *
     * Neither is decided from the model NAME. A hardcoded family list is
     * exactly the kind of thing that is wrong the week a new model ships,
     * and OPENAI_BASE_URL can point at an OpenAI-compatible service with
     * its own rules. Instead the modern spelling goes out first and the
     * provider's own 400 -- which names the parameter it wants -- is used
     * to correct the request and retry it. What it said is remembered for
     * the rest of the request, so a second draft does not pay for the
     * same lesson twice.
     *
     * @param array<int, array<string, mixed>> $messages
     */
    public function chat(array $messages, string $model, float $temperature = 0.4, int $maxTokens = 900): string
    {
        $dialect = $this->dialects[$model] ??= ['tokens' => 'max_completion_tokens', 'temperature' => true];

        // One attempt per thing that can be wrong, plus the real one.
        for ($attempt = 0; ; $attempt++) {
            $payload = ['model' => $model, 'messages' => $messages];
            $payload[$dialect['tokens']] = $maxTokens;
            if ($dialect['temperature']) {
                $payload['temperature'] = $temperature;
            }

            try {
                $response = $this->post('/chat/completions', $payload, 90);
                break;
            } catch (AIException $e) {
                $corrected = self::correctDialect($dialect, $e);
                if ($corrected === null || $attempt >= 2) {
                    throw $e;
                }

                error_log("[AI] {$model} rejected the request; retrying as "
                    . json_encode($corrected) . ': ' . $e->getMessage());
                $dialect = $this->dialects[$model] = $corrected;
            }
        }

        $text = $response['choices'][0]['message']['content'] ?? '';

        if (!is_string($text) || trim($text) === '') {
            error_log('[AI] completion came back empty: ' . json_encode($response));

            // A reasoning model spends the same budget on thinking as on
            // writing, so it can hit the cap before a single word of the
            // reply exists. That is a limit to raise, not a fault to
            // retry, and saying so is the difference between the two.
            if (($response['choices'][0]['finish_reason'] ?? '') === 'length') {
                throw new AIException(
                    'The model used its whole ' . $maxTokens . '-token budget before writing a reply. '
                    . 'A reasoning model needs a larger one — try a non-reasoning model for drafts.',
                    502
                );
            }

            throw new AIException('The AI returned an empty reply.', 502);
        }

        return trim($text);
    }

    /**
     * Reads a 400 and works out what to send differently next time.
     *
     * Returns null when the failure has nothing to do with the dialect --
     * a bad key, an unknown model, no credit -- so the caller rethrows
     * instead of retrying something that will fail identically.
     *
     * @param array{tokens: string, temperature: bool} $dialect
     * @return array{tokens: string, temperature: bool}|null
     */
    private static function correctDialect(array $dialect, AIException $e): ?array
    {
        if ($e->httpStatus !== 400) {
            return null;
        }

        $message = strtolower($e->getMessage());

        // "Unsupported parameter: 'max_completion_tokens' ..." from an
        // older or third-party endpoint that only knows the legacy name.
        if ($dialect['tokens'] === 'max_completion_tokens' && str_contains($message, 'max_completion_tokens')) {
            $dialect['tokens'] = 'max_tokens';
            return $dialect;
        }

        // "... 'max_tokens' is not supported with this model. Use
        // 'max_completion_tokens' instead." -- the case this whole dance
        // exists for, reachable after the fallback above guessed wrong.
        if ($dialect['tokens'] === 'max_tokens' && str_contains($message, 'max_completion_tokens')) {
            $dialect['tokens'] = 'max_completion_tokens';
            return $dialect;
        }

        // "Unsupported value: 'temperature' does not support 0.4 with
        // this model. Only the default (1) value is supported."
        if ($dialect['temperature'] && str_contains($message, 'temperature')) {
            $dialect['temperature'] = false;
            return $dialect;
        }

        return null;
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
     * File extensions the transcription endpoint accepts.
     *
     * Checked here rather than left to a 400, because the one WhatsApp
     * format that is missing -- AMR, which older Android phones still
     * record -- is a thing the CRM should report calmly rather than
     * retry. A voice note WhatsApp delivers as audio/ogg (Opus inside)
     * is the common case and is supported.
     */
    private const TRANSCRIBE_EXTENSIONS = [
        'flac', 'm4a', 'mp3', 'mp4', 'mpeg', 'mpga', 'oga', 'ogg', 'wav', 'webm',
    ];

    /**
     * Transcribes a voice note, in whatever language it was spoken.
     *
     * No `language` is sent: Whisper detects it, and a sales inbox gets
     * Arabic, Hindi, Tagalog and English in the same afternoon. The
     * detected language comes back too, which is what lets the caller
     * skip a needless translation call for a message already in English.
     *
     * Multipart, so this cannot go through request() -- same reason
     * WhatsApp::uploadMedia() has its own curl handle.
     *
     * @return array{text: string, language: string}
     */
    public function transcribe(string $absPath, string $mime, string $model): array
    {
        if (!is_file($absPath) || !is_readable($absPath)) {
            throw new AIException('That voice message is no longer on disk.', 422);
        }

        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        if (!in_array($ext, self::TRANSCRIBE_EXTENSIONS, true)) {
            throw new AIException(
                'Voice notes in .' . $ext . ' cannot be transcribed — OpenAI accepts '
                . implode(', ', self::TRANSCRIBE_EXTENSIONS) . '.',
                422
            );
        }

        $ch = curl_init($this->baseUrl . '/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => [
                'file'  => new CURLFile($absPath, $mime !== '' ? $mime : 'audio/ogg', basename($absPath)),
                'model' => $model,
                // Carries the detected language beside the text, which a
                // plain response does not.
                'response_format' => 'verbose_json',
            ],
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Audio is slower than chat, and a two-minute voice note is
            // not unusual on WhatsApp.
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            error_log("[AI] transcription curl error ({$errno}): {$error}");
            throw new AIException('Could not reach OpenAI to transcribe that voice message.', 502);
        }
        if ($status >= 400) {
            error_log("[AI] POST /audio/transcriptions -> HTTP {$status}: {$raw}");
            throw new AIException(self::errorMessage((string) $raw, $status), $status);
        }

        $decoded = json_decode((string) $raw, true);
        $text    = is_array($decoded) ? trim((string) ($decoded['text'] ?? '')) : '';

        if ($text === '') {
            // Silence, or speech the model could not make out. Not an
            // error worth retrying, but not a transcript either.
            throw new AIException('That voice message came back empty — it may be silent.', 422);
        }

        return [
            'text'     => $text,
            'language' => is_array($decoded) ? strtolower((string) ($decoded['language'] ?? '')) : '',
        ];
    }

    /**
     * One short English line saying what a voice note was about.
     *
     * Shown under the player, so an agent scanning a thread does not have
     * to press play on six messages in a language they do not read. The
     * full transcript is what the draft actually reasons from; this is
     * the label.
     */
    public function summariseInEnglish(string $transcript, string $model): string
    {
        return $this->chat([
            [
                'role'    => 'system',
                'content' => 'You summarise voice messages sent to a packaging company\'s sales '
                           . 'inbox. Reply with ONE short sentence in ENGLISH saying what the '
                           . 'caller wants, including any quantity, size or product named. '
                           . 'Translate if the message is in another language. No preamble.',
            ],
            ['role' => 'user', 'content' => $transcript],
        ], $model, 0.2, 150);
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
