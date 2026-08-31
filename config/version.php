<?php
/**
 * config/version.php
 *
 * What build is this?
 *
 * APP_VERSION is bumped by hand on every change (see CLAUDE.md). It is
 * tracked in git, unlike config/config.php -- it identifies the code,
 * not the installation, so it belongs in the repository.
 *
 * Everything else is read from .git at runtime rather than written into
 * a file at deploy time. That is deliberate: a hand-maintained build
 * stamp can be stale and still look authoritative, whereas the commit
 * git actually has checked out cannot lie. It is what answers "did my
 * git pull work?" without SSHing in to look.
 *
 * Nothing here is fatal. A deployment without .git -- an uploaded zip,
 * say -- simply shows the version on its own.
 */

declare(strict_types=1);

/**
 * Bump this on every change. Patch for a fix, minor for a feature.
 */
const APP_VERSION = '1.0.0';

/**
 * Version plus, where git is available, the deployed commit.
 *
 * @return array{version: string, commit: string, branch: string, deployed_at: ?int}
 */
function app_version_info(): array
{
    $info = [
        'version'     => APP_VERSION,
        'commit'      => '',
        'branch'      => '',
        'deployed_at' => null,
    ];

    $gitDir = dirname(__DIR__) . '/.git';
    if (!is_dir($gitDir) || !is_readable($gitDir . '/HEAD')) {
        return $info;
    }

    $head = trim((string) @file_get_contents($gitDir . '/HEAD'));
    if ($head === '') {
        return $info;
    }

    // "ref: refs/heads/<branch>" normally; a bare sha when detached.
    if (str_starts_with($head, 'ref: ')) {
        $ref = substr($head, 5);
        // Strip only the refs/heads/ prefix. basename() would cut a
        // nested name like "claude/whatsapp-integration" down to its
        // last segment and report a branch that does not exist.
        $info['branch'] = preg_replace('#^refs/heads/#', '', $ref) ?? $ref;
        $info['commit'] = git_resolve_ref($gitDir, $ref);
        $refFile        = $gitDir . '/' . $ref;
        // When the ref file was last written is when this checkout
        // happened -- in other words, when the app was deployed.
        $info['deployed_at'] = is_file($refFile)
            ? (filemtime($refFile) ?: null)
            : (filemtime($gitDir . '/HEAD') ?: null);
    } else {
        $info['commit']      = $head;
        $info['branch']      = 'detached';
        $info['deployed_at'] = filemtime($gitDir . '/HEAD') ?: null;
    }

    $info['commit'] = substr($info['commit'], 0, 7);

    return $info;
}

/**
 * Reads a ref, falling back to packed-refs.
 *
 * After a fresh clone most refs live in .git/packed-refs rather than as
 * loose files, so reading only the loose path would come up empty on
 * exactly the deployments this is meant to identify.
 */
function git_resolve_ref(string $gitDir, string $ref): string
{
    $loose = $gitDir . '/' . $ref;
    if (is_file($loose)) {
        return trim((string) @file_get_contents($loose));
    }

    $packed = $gitDir . '/packed-refs';
    if (!is_file($packed)) {
        return '';
    }

    foreach (file($packed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line === '' || $line[0] === '#' || $line[0] === '^') {
            continue;
        }
        [$sha, $name] = array_pad(explode(' ', $line, 2), 2, '');
        if ($name === $ref) {
            return $sha;
        }
    }

    return '';
}

/**
 * The one-line form shown in the settings footer.
 */
function app_version_label(): string
{
    $info  = app_version_info();
    $parts = ['v' . $info['version']];

    if ($info['commit'] !== '') {
        $parts[] = $info['commit'] . ($info['branch'] !== '' ? ' (' . $info['branch'] . ')' : '');
    }
    if ($info['deployed_at'] !== null) {
        $parts[] = 'deployed ' . gmdate('j M Y H:i', $info['deployed_at']) . ' UTC';
    }

    return implode(' · ', $parts);
}
