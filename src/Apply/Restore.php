<?php

namespace ErnestDefoe\Millwright\Apply;

use ErnestDefoe\Millwright\Work\ComposerRunner;
use Throwable;

/**
 * Put everything back the way it was, once, in one place.
 *
 * 🚨 Extracted because there are now TWO callers — the admin pressing the
 * button, and the run undoing itself when the site stops answering — and two
 * copies of "put it back" is the last thing this extension should have. The
 * subtle half is not moving the files: it is reconciling Composer's record
 * afterwards, and a second copy that forgot to would leave a site that looks
 * fine and carries a phantom package which is fatal to enable, months later,
 * with nothing pointing back here.
 */
class Restore
{
    public function __construct(
        private string $vendorPath,
        private string $basePath,
        private string $workDirRoot,
        private string $trashPath,
        private Journal $journal,
        private ComposerRunner $composer,
    ) {
    }

    /** Is there anything saved to put back? */
    public function possible(): bool
    {
        return $this->journal->exists() || is_file($this->workDirRoot . '/composer.lock.before');
    }

    /**
     * @return array{undone: list<string>, note: ?string}
     */
    public function run(): array
    {
        $undone = (new Rollback(
            $this->vendorPath,
            $this->trashPath,
            $this->journal,
            $this->basePath,
            $this->workDirRoot
        ))->run();

        $note = null;

        if ($undone !== []) {
            /*
             * 🚨 `install`, not `dump-autoload`. Moving the files back leaves
             * vendor/composer/installed.json still claiming a package whose
             * directory is gone, and dump-autoload regenerates the autoloader
             * FROM that record — it faithfully rebuilds the wrong thing.
             *
             * A subprocess, so no Flarum boots inside the process that has just
             * moved its files out from under it.
             */
            try {
                $result = $this->composer->run(['install', '--no-scripts']);

                if ($result['code'] === 0) {
                    $undone[] = "Composer's record put back";
                } else {
                    $note = 'The files are back, but Composer could not update its own record. '
                        . 'Run `composer install` to finish putting things back.';
                }
            } catch (Throwable $e) {
                $note = 'The files are back, but Composer could not be run here (' . $e->getMessage() . '). '
                    . 'Run `composer install` to finish putting things back.';
            }
        }

        return ['undone' => $undone, 'note' => $note];
    }
}
