<?php

namespace ErnestDefoe\Millwright\Config;

use RuntimeException;

/**
 * Reading and writing the two files Composer will not let you get wrong twice.
 *
 * 🚨 composer.json and auth.json are the files that decide whether Composer can
 * run at all. A malformed composer.json does not degrade a site, it stops every
 * future install and update dead — including the one that would put it back. So
 * every write here is: validate, back up, write to a temporary file, fsync, and
 * rename into place.
 *
 * The rename is what makes it safe. A half-written composer.json is
 * unrecoverable from the admin screen that wrote it; rename(2) is atomic, so a
 * reader sees either the old file or the new one and never a partial one.
 */
class JsonFile
{
    public function __construct(private string $path)
    {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** @return array<string,mixed> */
    public function read(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $raw = @file_get_contents($this->path);

        if ($raw === false) {
            throw new RuntimeException("Could not read {$this->path}.");
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            /*
             * 🚨 Refuse rather than treat it as empty. Returning [] here would
             * mean the next write replaces a file we could not understand with
             * one built from nothing — turning a file somebody could still fix
             * by hand into one whose contents are gone.
             */
            throw new RuntimeException(
                basename($this->path) . ' is not valid JSON, so Millwright will not overwrite it. '
                . 'Fix it by hand first — ' . (json_last_error_msg() ?: 'unknown error') . '.'
            );
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     * @param int $mode permissions for a file we are creating; 0600 for secrets
     */
    public function write(array $data, int $mode = 0664): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('Could not encode ' . basename($this->path) . ': ' . json_last_error_msg());
        }

        // 🚨 Prove it parses before it goes anywhere near the real path.
        if (json_decode($json, true) === null) {
            throw new RuntimeException('Refusing to write ' . basename($this->path) . ': the result does not parse.');
        }

        $this->backup();

        $tmp = $this->path . '.millwright-' . bin2hex(random_bytes(4));
        $handle = @fopen($tmp, 'w');

        if ($handle === false) {
            throw new RuntimeException("Could not write next to {$this->path}. Check the directory is writable.");
        }

        try {
            fwrite($handle, $json . "\n");
            fflush($handle);
            // The file must be on disk before the rename, or a crash between the
            // two leaves an empty file where a valid one used to be.
            @fsync($handle);
        } finally {
            fclose($handle);
        }

        @chmod($tmp, $mode);

        if (! @rename($tmp, $this->path)) {
            @unlink($tmp);

            throw new RuntimeException("Could not replace {$this->path}.");
        }
    }

    /**
     * Keep the previous contents, timestamped.
     *
     * 🚨 Beside the file rather than in a temp directory, so somebody debugging
     * a broken site at 2am finds them without being told where to look.
     */
    private function backup(): void
    {
        if (! $this->exists()) {
            return;
        }

        $to = $this->path . '.millwright-backup-' . date('Ymd-His');

        if (! is_file($to)) {
            @copy($this->path, $to);
        }
    }
}
