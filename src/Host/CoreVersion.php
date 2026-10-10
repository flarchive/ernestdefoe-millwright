<?php

namespace ErnestDefoe\Millwright\Host;

/**
 * The version Flarum's code on disk will write to the database when it
 * migrates: `Application::VERSION` of the core that was just swapped in.
 *
 * Read from the file, because the class in this process's memory is the OLD
 * core's — loaded before the swap, and PHP cannot load it twice.
 */
final class CoreVersion
{
    public static function onDisk(string $vendorPath): ?string
    {
        $source = @file_get_contents($vendorPath.'/flarum/core/src/Foundation/Application.php');

        if ($source === false || ! preg_match("/const\\s+VERSION\\s*=\\s*'([^']+)'/", $source, $m)) {
            return null;
        }

        return $m[1];
    }
}
