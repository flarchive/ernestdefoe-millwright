<?php

namespace ErnestDefoe\Millwright\Work;

use Flarum\Database\Migrator;
use Flarum\Extension\ExtensionManager;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use Throwable;

/**
 * Which database migrations an update ran, so undoing it can reverse them.
 *
 * 🚨 Undo used to put the old CODE back over the new SCHEMA. Moving files is
 * all a rename can do, and a migration the update ran stays run: the older
 * version then boots against columns and tables it has never heard of, or
 * misses ones it needs. ClaudiusH asked the obvious question on 2026-10-07 —
 * "this should only be offered if the update did not include a migration,
 * right?" — and the honest answer was that the confirm box merely warned.
 *
 * So the update writes down what it ran, and undo reverses exactly those,
 * newest first, through each migration's own `down`. It runs BEFORE the files
 * move back, because the down steps live in the NEW version's files. If any
 * of them has no down step, nothing is reversed and undo refuses: old code on
 * a newer database is the outcome this exists to prevent.
 *
 * Recorded in two halves so a run killed mid-step still knows: the "before"
 * list is saved once, before migrate runs, and the difference is taken after.
 * A retried step finds the saved "before" and records the same difference.
 */
final class MigrationLedger
{
    public function __construct(private string $workDir)
    {
    }

    /** Before `flarum migrate`: what has already run. Saved once, so a retry keeps the original. */
    public function before(ConnectionInterface $db): void
    {
        $path = $this->workDir . '/migrations.before.json';

        if (! is_file($path)) {
            file_put_contents($path, json_encode($this->current($db)));
        }
    }

    /** After it: what this update added, in the order it ran. */
    public function after(ConnectionInterface $db): array
    {
        $before = (array) json_decode((string) @file_get_contents($this->workDir . '/migrations.before.json'), true);
        $seen = array_flip(array_map(fn ($m) => $m['extension'] . '|' . $m['migration'], $before));
        $added = array_values(array_filter(
            $this->current($db),
            fn ($m) => ! isset($seen[$m['extension'] . '|' . $m['migration']])
        ));
        // Flarum runs them in filename order, which starts with a timestamp.
        usort($added, fn ($a, $b) => strcmp($a['migration'], $b['migration']));

        file_put_contents($this->workDir . '/migrations.json', json_encode($added, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $added;
    }

    /** @return list<array{migration:string, extension:?string}> what this update ran */
    public function ran(): array
    {
        return array_values((array) json_decode((string) @file_get_contents($this->workDir . '/migrations.json'), true));
    }

    /**
     * Reverse what this update ran, newest first. Nothing is reversed unless
     * every one of them can be.
     *
     * @return list<string> one line per migration reversed
     */
    public function undo(ConnectionInterface $db, Migrator $migrator, ExtensionManager $extensions, string $vendorPath): array
    {
        $steps = [];

        foreach (array_reverse($this->ran()) as $m) {
            $dir = $m['extension'] === null
                ? $vendorPath . '/flarum/core/migrations'
                : ($extensions->getExtension($m['extension'])?->getPath() . '/migrations');
            $migration = $migrator->resolve($dir, $m['migration']);

            if (! isset($migration['down']) || ! is_callable($migration['down'])) {
                throw new RuntimeException(
                    'Nothing was undone: this update changed the database with ' . $m['migration']
                    . ($m['extension'] ? ' (' . $m['extension'] . ')' : '') . ', which has no way to reverse itself. '
                    . 'Putting the old version back would leave it running on a database it was not written for.'
                );
            }

            $steps[] = [$m, $migration['down']];
        }

        $done = [];

        foreach ($steps as [$m, $down]) {
            try {
                call_user_func($down, $db->getSchemaBuilder());
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Undoing stopped while reversing ' . $m['migration'] . ': ' . $e->getMessage()
                    . ($done ? ' Already reversed: ' . implode(', ', $done) . '.' : ' Nothing had been reversed yet.')
                );
            }

            $migrator->getRepository()->delete($m['migration'], $m['extension']);
            $done[] = $m['migration'];
        }

        // Undone once; a second undo of the same run has nothing left to reverse.
        @unlink($this->workDir . '/migrations.json');

        return array_map(fn ($name) => "reversed database change $name", $done);
    }

    /** @return list<array{migration:string, extension:?string}> */
    private function current(ConnectionInterface $db): array
    {
        return $db->table('migrations')->get(['migration', 'extension'])
            ->map(fn ($row) => ['migration' => (string) $row->migration, 'extension' => $row->extension !== null ? (string) $row->extension : null])
            ->values()->all();
    }
}
