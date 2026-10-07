<?php

namespace ErnestDefoe\Millwright\Console;

use ErnestDefoe\Millwright\Prune\Pruner;
use ErnestDefoe\Millwright\Prune\Retention;
use Flarum\Console\AbstractCommand;
use Flarum\Foundation\Paths;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * `php flarum millwright:prune [--dry-run]` — tidy the rollback copies.
 *
 * Scheduled daily with NO arguments. 🚨 A scheduled `['--dry-run' => true]`
 * would render as `--dry-run='1'`, which a no-value option refuses — every
 * night, into /dev/null. The flag is for a person at a terminal.
 *
 * What is kept and why is the Pruner's decision, not this command's: the same
 * rule runs at the end of every update and behind the admin screen's button.
 */
class PruneCommand extends AbstractCommand
{
    public function __construct(private Paths $paths)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('millwright:prune')
            ->setDescription('Remove rollback copies and run records that no rollback can use any more.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be removed, and the space it would free, without removing anything.');
    }

    protected function fire(): int
    {
        $dir = $this->paths->storage . '/millwright';
        $pruner = new Pruner($dir, new Retention($dir));

        try {
            if ($this->input->getOption('dry-run')) {
                return $this->dryRun($pruner);
            }

            $summary = $pruner->prune('console');
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }

        foreach ($summary['names'] as $name) {
            $this->output->writeln('  removed ' . $name);
        }

        foreach ($summary['failed'] as $line) {
            $this->error('  could not remove ' . $line);
        }

        $this->info(sprintf('Removed %d, freed %s.', $summary['removed'], Pruner::human($summary['freed'])));

        return $summary['failed'] === [] ? 0 : 1;
    }

    private function dryRun(Pruner $pruner): int
    {
        $plan = $pruner->plan(true);

        $this->output->writeln(sprintf(
            'Keeping rollback copies for %d days or the last %d updates, whichever keeps more.',
            $plan['settings']['keepDays'],
            $plan['settings']['keepRuns']
        ));

        foreach ($plan['keep'] as $row) {
            $this->output->writeln(sprintf('  keep    %-5s %s — %s', $row['kind'], $row['name'], $row['why']));
        }

        foreach ($plan['remove'] as $row) {
            $this->output->writeln(sprintf(
                '  remove  %-5s %s (%s) — %s',
                $row['kind'],
                $row['name'],
                Pruner::human((int) $row['bytes']),
                $row['why']
            ));
        }

        $this->info(sprintf(
            'Would remove %d, freeing %s. The trash holds %s now. Nothing was removed (--dry-run).',
            count($plan['remove']),
            Pruner::human($plan['removeBytes']),
            Pruner::human((int) $plan['trashBytes'])
        ));

        return 0;
    }
}
