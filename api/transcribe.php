<?php
/**
 * api/transcribe.php
 *
 *   GET  /api/transcribe.php   -> how many voice notes are still pending
 *   POST /api/transcribe.php   -> transcribe the next batch
 *
 * Backfill for voice notes that arrived before transcription existed.
 * New ones are handled by the inbound webhook as they land; this is only
 * for the ones already in the thread.
 *
 * Deliberately a button on the settings page rather than something that
 * runs on its own. Every transcription is a paid model call, and this
 * app has no job queue to rate-limit one -- so it happens when somebody
 * asks for it, in bounded batches, and says what it cost in work.
 *
 * A batch is small on purpose: shared hosting kills a long request, and
 * a partial batch is fine because the next press picks up where this one
 * stopped. Nothing is retried automatically; a voice note that cannot be
 * transcribed reports why and is skipped.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/media.php';
require_once __DIR__ . '/../config/ai.php';

require_auth();

/**
 * How many to do per press. Sized against a shared host's request
 * timeout, not against how many are waiting.
 */
const TRANSCRIBE_BATCH = 5;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response([
            'success'   => true,
            'pending'   => countUntranscribedVoiceNotes(),
            'batch'     => TRANSCRIBE_BATCH,
            'available' => AI::isConfigured(),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed', 405);
    }

    if (!AI::isConfigured()) {
        json_error('Set OPENAI_API_KEY in config/config.php before transcribing.', 422);
    }

    $rows = getUntranscribedVoiceNotes(TRANSCRIBE_BATCH);

    $done    = 0;
    $skipped = [];

    foreach ($rows as $row) {
        $id  = (int) ($row['id'] ?? 0);
        $abs = media_abs_path((string) ($row['media_path'] ?? ''));

        if ($id === 0 || $abs === null) {
            // The row points at a file that is no longer on disk. Meta
            // expires media after ~30 days, so this is expected on old
            // conversations rather than a fault.
            $skipped[] = 'Message ' . $id . ': the audio file is no longer on disk.';
            continue;
        }

        try {
            transcribeRow($id, $abs, (string) ($row['media_mime'] ?? ''));
            $done++;
        } catch (AIException $e) {
            // OpenAI's own wording: an unsupported format and a spent
            // balance need different actions, and "failed" says neither.
            $skipped[] = 'Message ' . $id . ': ' . $e->getMessage();
        }
    }

    json_response([
        'success' => true,
        'done'    => $done,
        'skipped' => $skipped,
        'pending' => countUntranscribedVoiceNotes(),
    ]);
} catch (SupabaseException $e) {
    error_log('[api/transcribe] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    error_log('[api/transcribe] ' . $e->getMessage());
    json_error('Something went wrong while transcribing.', 500);
}

/**
 * Transcribes one stored voice note and labels it in English.
 *
 * Mirrors transcribe_voice() in the webhook, and stores the same two
 * values for the same reasons: the transcript is what was said, in the
 * language it was said, and is what api/draft.php reasons from; the
 * caption is one English line for the thread.
 */
function transcribeRow(int $id, string $absPath, string $mime): void
{
    $result     = AI::client()->transcribe($absPath, $mime, getSetting('ai_transcribe_model'));
    $transcript = $result['text'];

    setMessageTranscript($id, mb_substr($transcript, 0, 8000));

    $isEnglish = str_starts_with($result['language'], 'en');
    $caption   = ($isEnglish && mb_strlen($transcript) <= 160)
        ? $transcript
        : AI::client()->summariseInEnglish($transcript, getSetting('ai_model'));

    if ($caption !== '') {
        setMessageCaption($id, mb_substr($caption, 0, 300));
    }
}
