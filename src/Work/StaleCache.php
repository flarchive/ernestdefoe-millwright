<?php

namespace ErnestDefoe\Millwright\Work;

/**
 * A cache somebody else owns, moved out of the way instead of refused.
 *
 * 🚨 Found on a Flarum-in-a-box container, 2026-10-07: its entrypoint runs
 * `php flarum` as root, so storage/cache and storage/formatter fill with
 * root-owned entries. Permissions refused every update over them, and "Put
 * everything back" failed at `cache:clear` for the same reason, on a host
 * where the operator may not have a shell to chown from.
 *
 * Neither directory holds anything worth keeping. When storage/ itself is
 * writable, renaming one aside is one call (a rename within the same parent
 * needs write permission on the parent only) and Flarum rebuilds what it
 * needs into a fresh one. Both halves of the formatter, the cached renderer
 * and the Renderer_<hash>.php it names, live in storage/formatter, so they go
 * together and never disagree. The old directory is left for root to delete:
 * this process cannot.
 *
 * When the rename is not possible (storage/ not writable, a mount point),
 * nothing moves and Permissions refuses exactly as before.
 */
final class StaleCache
{
    private const DIRS = ['cache', 'formatter'];

    /** @return string[] the directories moved aside */
    public static function setAside(string $storagePath): array
    {
        if ($storagePath === '' || ! is_writable($storagePath)) {
            return [];
        }

        $moved = [];

        foreach (self::DIRS as $name) {
            $dir = $storagePath . '/' . $name;

            if (! is_dir($dir) || ! self::hasUnwritable($dir)) {
                continue;
            }

            $aside = $dir . '.root-owned-' . date('YmdHis');

            if (@rename($dir, $aside)) {
                @mkdir($dir, 0775);
                $moved[] = $aside;
            }
        }

        return $moved;
    }

    /** One line for a step result, or '' when nothing moved. */
    public static function describe(array $moved): string
    {
        return $moved === [] ? '' : 'Set aside a cache owned by another user, which Flarum rebuilds; delete it as root when convenient: rm -rf '
            . implode(' ', array_map('escapeshellarg', $moved));
    }

    private static function hasUnwritable(string $root): bool
    {
        $found = [];
        (new Permissions('', '', ''))->walkInto($root, $found);

        return $found !== [];
    }
}
