<?php
/**
 * api/templates.php
 *
 *   GET /api/templates.php -> the message templates on this number
 *
 * WhatsApp only delivers a free-form reply inside the 24-hour window
 * that the customer's own last message opens. Past that, the only thing
 * Meta will carry is a template that was approved in advance -- so this
 * endpoint is what turns "the window has closed, nothing you can do"
 * into a list an agent can actually pick from.
 *
 * It reports every template the number has, not just the usable ones. A
 * template sitting in review is exactly the thing someone will go
 * looking for, and "it isn't in the list" is a much worse answer than
 * "it is still pending approval".
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/whatsapp.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

try {
    $templates = array_map('normalizeTemplate', WhatsApp::client()->listTemplates());

    // Approved first, then by name, so the ones that can actually be
    // sent are at the top of the picker.
    usort($templates, static function (array $a, array $b): int {
        if ($a['sendable'] !== $b['sendable']) {
            return $a['sendable'] ? -1 : 1;
        }
        return strcasecmp($a['name'], $b['name']);
    });

    json_response([
        'success'   => true,
        'templates' => $templates,
        'sendable'  => count(array_filter($templates, static fn(array $t): bool => $t['sendable'])),
    ]);
} catch (WhatsAppException $e) {
    // The provider's wording again: "could not list templates" does not
    // say whether the key is wrong or the plan simply has no templates.
    error_log('[api/templates] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus >= 400 && $e->httpStatus < 600 ? $e->httpStatus : 502);
} catch (Throwable $e) {
    error_log('[api/templates] ' . $e->getMessage());
    json_error('Something went wrong while listing your message templates.', 500);
}

/**
 * Flattens one provider template into what the picker needs.
 *
 * `sendable` is the interesting field. A template is only offered when
 * it is approved AND everything it needs can be filled in from this
 * page -- which means body placeholders and nothing else. A template
 * whose header or buttons take their own parameters is listed with the
 * reason it is greyed out, rather than offered and then rejected by
 * Meta after the agent has typed the values in.
 *
 * @param array<string, mixed> $template
 * @return array<string, mixed>
 */
function normalizeTemplate(array $template): array
{
    $status = strtolower((string) ($template['status'] ?? ''));

    $header = '';
    $body   = '';
    $footer = '';
    $needsUnsupportedParams = false;

    foreach (($template['components'] ?? []) as $component) {
        if (!is_array($component)) {
            continue;
        }

        $type = strtoupper((string) ($component['type'] ?? ''));
        $text = (string) ($component['text'] ?? '');

        switch ($type) {
            case 'HEADER':
                $header = $text;
                // A media header needs an uploaded file, and a text one
                // with {{1}} needs a value we have nowhere to put.
                if (strtoupper((string) ($component['format'] ?? 'TEXT')) !== 'TEXT'
                    || placeholderCount($text) > 0) {
                    $needsUnsupportedParams = true;
                }
                break;
            case 'BODY':
                $body = $text;
                break;
            case 'FOOTER':
                $footer = $text;
                break;
            case 'BUTTONS':
                foreach (($component['buttons'] ?? []) as $button) {
                    // A URL button with a {{1}} in it is a per-send
                    // parameter, same problem as the header.
                    if (is_array($button) && placeholderCount((string) ($button['url'] ?? '')) > 0) {
                        $needsUnsupportedParams = true;
                    }
                }
                break;
        }
    }

    $placeholders = placeholderCount($body);

    $reason = '';
    if ($status !== 'approved') {
        $reason = $status !== '' ? 'Not approved yet (' . $status . ')' : 'No approval status reported';
    } elseif ($needsUnsupportedParams) {
        $reason = 'Needs a header or button value this page cannot fill in';
    }

    return [
        'name'         => (string) ($template['name'] ?? ''),
        'language'     => (string) ($template['language'] ?? ''),
        'category'     => (string) ($template['category'] ?? ''),
        'status'       => $status,
        'header'       => $header,
        'body'         => $body,
        'footer'       => $footer,
        'placeholders' => $placeholders,
        'sendable'     => $reason === '',
        'reason'       => $reason,
    ];
}

/**
 * How many {{n}} placeholders a template component has.
 *
 * Counts DISTINCT indexes, not occurrences: a template that says
 * "Hi {{1}}, your {{2}} ships {{2}}" takes two values, not three.
 */
function placeholderCount(string $text): int
{
    if (!preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $matches)) {
        return 0;
    }

    return count(array_unique($matches[1]));
}
