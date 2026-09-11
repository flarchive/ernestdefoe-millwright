<?php

namespace ErnestDefoe\Millwright\Apply;

/**
 * Removing a directory, done once.
 *
 * 🚨 One copy on purpose. The applier and the rollback each had their own, and
 * they drifted: the symlink hole was fixed in one and left open in the other, so
 * a rollback could still reach through a link into a working checkout and empty
 * it. Two implementations of a dangerous operation is two chances to fix it
 * incompletely.
 */
class Tree
{
    /**
     * 🚨 Never walks through a symlink.
     *
     * RecursiveDirectoryIterator follows them by default, and is_dir() is true
     * for a link pointing at a directory — so the obvious version of this
     * descends through the link and unlinks the files on the far side. Composer
     * installs a path repository AS A SYMLINK, so the far side is a checkout
     * somebody is editing.
     *
     * A link is one thing to remove, never a door.
     */
    public static function delete(string $dir): void
    {
        if (is_link($dir)) {
            @unlink($dir);

            return;
        }

        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $path = $item->getPathname();

            if (is_link($path)) {
                @unlink($path);

                continue;
            }

            $item->isDir() ? @rmdir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
