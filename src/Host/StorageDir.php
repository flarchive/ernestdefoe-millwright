<?php

namespace ErnestDefoe\Millwright\Host;

/**
 * Where Millwright keeps its own files, under Flarum's storage directory: runs,
 * rollback copies, the update check, caches and locks.
 *
 * One place, so the directory can move without a hunt for every caller.
 */
final class StorageDir
{
    public static function of(string $storage): string
    {
        return $storage.'/millwright';
    }
}
