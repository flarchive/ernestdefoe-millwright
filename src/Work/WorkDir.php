<?php

namespace ErnestDefoe\Millwright\Work;

use RuntimeException;

/**
 * The scratch space belonging to one run: its plan, its staged downloads, and
 * its journal.
 *
 * 🚨 Everything a run needs lives together and survives the process. That is
 * what makes a run resumable by a driver that knows nothing except its id — the
 * admin page, a cron tick, or a worker can each pick up a run none of them
 * started, because the whole of it is on disk in one place.
 *
 * Under storage/, which is already writable and already outside the web root.
 */
class WorkDir
{
    public function __construct(private string $storagePath, private string $runId)
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $runId)) {
            throw new RuntimeException("Refusing to build a work directory for run id: $runId");
        }
    }

    public function root(): string
    {
        return $this->storagePath . '/millwright/runs/' . $this->runId;
    }

    public function staging(): string
    {
        return $this->root() . '/staging';
    }

    public function journalPath(): string
    {
        return $this->root() . '/journal.jsonl';
    }

    public function trash(): string
    {
        /*
         * 🚨 Trash is per-INSTALL, not per-run. A rollback has to be possible
         * long after the run that made it is over and its scratch space is
         * gone — that is the whole difference between "reversible" and
         * "reversible for the next few minutes".
         */
        return $this->storagePath . '/millwright/trash';
    }

    public function create(): self
    {
        foreach ([$this->root(), $this->staging(), $this->trash()] as $dir) {
            if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                throw new RuntimeException("Could not create $dir");
            }
        }

        return $this;
    }

    /**
     * @param list<string> $packages
     * @param string $mode 'update' for packages already installed, 'install'
     *        for ones being added, 'remove' to take one out. The difference
     *        decides which Composer command the plan phase runs, and it has to
     *        survive the request that chose it.
     */
    public function remember(array $packages, string $mode = 'update'): void
    {
        file_put_contents($this->root() . '/requested.json', json_encode([
            'mode'     => in_array($mode, ['install', 'remove'], true) ? $mode : 'update',
            'packages' => array_values($packages),
        ]));
    }

    /**
     * What the user actually asked to change.
     *
     * 🚨 Recorded rather than passed in, because a run outlives the request that
     * started it. A worker picking this up an hour later has only the id, and
     * the difference between "you asked for this" and "this came along with it"
     * has to survive that.
     *
     * @return list<string>
     */
    public function requested(): array
    {
        return array_values((array) ($this->manifest()['packages'] ?? []));
    }

    /**
     * 'install' or 'update'.
     *
     * 🚨 Read from disk rather than passed along, for the same reason as the
     * package list: a run outlives the request that started it, and a worker
     * picking it up an hour later has only the id. Running `update` on a package
     * that is not installed does nothing at all and reports success, so getting
     * this wrong is silent.
     */
    public function mode(): string
    {
        $mode = (string) ($this->manifest()['mode'] ?? 'update');

        return in_array($mode, ['install', 'remove'], true) ? $mode : 'update';
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        $path = $this->root() . '/requested.json';

        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }
}
