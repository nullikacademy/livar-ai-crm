<?php
/**
 * api/context_blocks.php
 *
 *   GET    /api/context_blocks.php                 -> every block, in order
 *   POST   /api/context_blocks.php                 -> { title, body }
 *   PUT    /api/context_blocks.php                 -> { id, title?, body?, enabled? }
 *                                                     or { id, move: "up"|"down" }
 *   DELETE /api/context_blocks.php?id=12
 *
 * The AI's standing knowledge, in named pieces. This replaced the single
 * system-prompt box: one long field meant editing the brand paragraph
 * put the shipping paragraph at risk, and switching the product list off
 * for an afternoon meant cutting it somewhere safe first.
 *
 * Every enabled block is handed to the model on every draft, which is
 * why CONTEXT_BLOCK_MAX exists -- the cap is really on what a reply
 * costs, not on storage.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';

require_auth();

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            respondWithList();
            break;
        case 'POST':
            handleCreate();
            break;
        case 'PUT':
            handleUpdate();
            break;
        case 'DELETE':
            handleDelete();
            break;
        default:
            json_error('Method not allowed', 405);
    }
} catch (SupabaseException $e) {
    error_log('[api/context_blocks] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    error_log('[api/context_blocks] ' . $e->getMessage());
    json_error('Something went wrong with the context blocks.', 500);
}

/**
 * The whole list, every time.
 *
 * A write returns it too rather than just the row it touched: a move
 * renumbers several blocks at once, and a page rebuilding itself from
 * one returned row would show an order the database does not have.
 */
function respondWithList(int $status = 200): void
{
    $blocks = getContextBlocks(true);

    $enabled = array_filter(
        $blocks,
        static fn (array $b): bool => $b['enabled'] && trim($b['body']) !== ''
    );

    json_response([
        'success' => true,
        'blocks'  => $blocks,
        'limits'  => [
            'max'   => CONTEXT_BLOCK_MAX,
            'title' => CONTEXT_BLOCK_TITLE_LIMIT,
            'body'  => CONTEXT_BLOCK_BODY_LIMIT,
        ],
        // So the page can say plainly that the model is running on the
        // built-in prompt instead of leaving it to be noticed in a draft.
        'using_fallback' => $enabled === [],
        // What the model will actually be given, for the preview. It is
        // assembled from the same function drafting uses, so the preview
        // cannot drift from the real thing.
        'composed_length' => mb_strlen(composeSystemPrompt()),
    ], $status);
}

function handleCreate(): void
{
    $data = read_json_body();

    $title = isset($data['title']) && is_string($data['title']) ? $data['title'] : '';
    $body  = isset($data['body']) && is_string($data['body']) ? $data['body'] : '';

    if (trim($title) === '' && trim($body) === '') {
        json_error('Give the block a name or something to say.', 422);
    }

    createContextBlock($title, $body);
    respondWithList(201);
}

function handleUpdate(): void
{
    $data = read_json_body();
    $id   = isset($data['id']) ? (int) $data['id'] : 0;

    if ($id <= 0) {
        json_error('Which block?', 422);
    }

    if (isset($data['move'])) {
        $move = (string) $data['move'];
        if ($move !== 'up' && $move !== 'down') {
            json_error('A block moves up or down.', 422);
        }
        // false means it is already at that end, which is not an error
        // worth a red box -- the list simply comes back unchanged.
        moveContextBlock($id, $move);
        respondWithList();
        return;
    }

    foreach (['title', 'body'] as $key) {
        if (isset($data[$key]) && !is_string($data[$key])) {
            json_error("{$key} must be text", 422);
        }
    }

    if (updateContextBlock($id, $data) === null) {
        json_error('There was nothing to change, or that block is gone.', 404);
    }

    respondWithList();
}

function handleDelete(): void
{
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        json_error('Which block?', 422);
    }

    if (!deleteContextBlock($id)) {
        json_error('That block is already gone.', 404);
    }

    respondWithList();
}
