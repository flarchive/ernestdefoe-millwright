<?php

namespace ErnestDefoe\Millwright\Work;

use ErnestDefoe\Millwright\Apply\Applier;
use ErnestDefoe\Millwright\Apply\Journal;
use ErnestDefoe\Millwright\Run\Steps;
use ErnestDefoe\Millwright\Run\StepsFactory;
use Flarum\Foundation\Paths;

/**
 * Assembles a run's Composer, downloader and applier around that run's own
 * scratch space.
 *
 * Everything here is derived from the id, and nothing is remembered between
 * calls: hand it a run id and it builds the whole apparatus for that run, which
 * is what lets a driver holding nothing but an id — a cron tick, a worker, an
 * admin page that has just been reopened — pick up work it did not start.
 */
class ComposerStepsFactory implements StepsFactory
{
    public function __construct(private Paths $paths)
    {
    }

    public function for(string $runId): Steps
    {
        $workDir = new WorkDir($this->paths->storage, $runId);
        $journal = new Journal($workDir->journalPath());

        return new ComposerSteps(
            $this->paths->base,
            $workDir->root(),
            new ComposerRunner($this->paths->base, null, $this->paths->storage . '/.composer'),
            new Fetcher($workDir->staging(), $this->paths->base . '/auth.json'),
            new Applier($this->paths->vendor, $workDir->staging(), $workDir->trash(), $journal),
            $journal,
            $workDir->requested(),
            $workDir->mode()
        );
    }
}
