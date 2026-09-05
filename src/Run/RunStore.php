<?php

namespace ErnestDefoe\Millwright\Run;

use RuntimeException;

/**
 * Where a run's state lives between requests.
 *
 * 🚨 Written atomically — a temp file plus a rename — because this is read by
 * one request while another is writing it. Every poll of the admin screen reads
 * this file, and every step writes it; a torn read would show a run that never
 * existed, and on a busy forum that would be common rather than rare.
 *
 * A file rather than a database row, deliberately. The whole design goal is that
 * progress survives a killed request on a host that gives no guarantees, and a
 * file with an fsync is the least machinery between an intention and the disk.
 * It also means the driver can be exercised with no Flarum and no database,
 * which is why phases 2 and 3 have real tests at all.
 */
class RunStore
{
    public function __construct(private string $dir)
    {
    }

    public function save(Run $run): void
    {
        $this->ensureDir();

        $path = $this->path($run->id);
        $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        $json = json_encode($run->toArray(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $handle = fopen($temp, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Cannot write the run state at $temp");
        }

        fwrite($handle, $json);
        fflush($handle);
        fsync($handle);
        fclose($handle);

        // 🚨 rename() is atomic, so a concurrent reader sees either the whole
        // previous state or the whole new one, and never a half-written file.
        if (! @rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException("Cannot move the run state into place at $path");
        }
    }

    public function load(string $id): ?Run
    {
        $path = $this->path($id);

        if (! is_file($path)) {
            return null;
        }

        $row = json_decode((string) file_get_contents($path), true);

        return is_array($row) ? Run::fromArray($row) : null;
    }

    /** The most recently started run, which is what the admin screen opens on. */
    public function latest(): ?Run
    {
        $runs = $this->all();

        return $runs === [] ? null : $runs[0];
    }

    /** @return list<Run> newest first */
    public function all(): array
    {
        if (! is_dir($this->dir)) {
            return [];
        }

        $runs = [];

        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $row = json_decode((string) file_get_contents($file), true);

            if (is_array($row) && isset($row['id'])) {
                $runs[] = Run::fromArray($row);
            }
        }

        usort($runs, fn (Run $a, Run $b) => $b->startedAt <=> $a->startedAt);

        return $runs;
    }

    public function forget(string $id): void
    {
        @unlink($this->path($id));
    }

    private function path(string $id): string
    {
        // 🚨 Ids are generated, never taken from a request — but this is the
        // second place a name reaches the filesystem, so it is checked here too
        // rather than trusted because of where it came from.
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            throw new RuntimeException("Refusing to build a path from run id: $id");
        }

        return rtrim($this->dir, '/') . '/' . $id . '.json';
    }

    private function ensureDir(): void
    {
        if (! is_dir($this->dir) && ! mkdir($this->dir, 0775, true) && ! is_dir($this->dir)) {
            throw new RuntimeException("Could not create the run directory at {$this->dir}");
        }
    }
}
