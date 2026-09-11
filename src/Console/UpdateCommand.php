<?php

namespace ErnestDefoe\Millwright\Console;

use ErnestDefoe\Millwright\Host\Capability;
use ErnestDefoe\Millwright\Plan\Repin;
use ErnestDefoe\Millwright\Run\Run;
use ErnestDefoe\Millwright\Run\RunStore;
use ErnestDefoe\Millwright\Run\StepRunner;
use ErnestDefoe\Millwright\Work\UpdateCheck;
use ErnestDefoe\Millwright\Work\WorkDir;
use Flarum\Console\AbstractCommand;
use Flarum\Foundation\Paths;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php flarum millwright:update vendor/name` — the update the admin screen
 * does, driven from a terminal.
 *
 * 🚨 It is the SAME run. Not a second implementation that happens to call
 * Composer: it writes the same work directory, advances the same phases through
 * the same StepRunner, journals the same way and rolls back the same way. A
 * console command that reimplemented any of that would be a second set of bugs
 * and, worse, a second answer to "what actually happened", which is the one
 * thing this extension exists to keep singular.
 *
 * The difference is only who turns the handle. The screen polls `step` because
 * a browser request may be cut off at thirty seconds; here nothing is going to
 * cut us off, so this loops until the run finishes and prints the log as it
 * goes.
 *
 * 🚨 It deliberately does NOT nudge a queue worker. On a forum with Horizon,
 * handing the run to a worker as well would mean two drivers on one run — the
 * lock in StepRunner makes that safe rather than corrupting, but it also means
 * this process would sit watching someone else work and could not report a
 * failure as its own exit code. Driving it here keeps the exit code truthful,
 * which is the entire reason to want this in a script.
 *
 * Updates only. Installing something new and removing something installed are
 * both available on the Millwright screen, and removal in particular has guards
 * — is it a direct requirement, is it still enabled — that belong with the
 * screen that can explain them.
 */
class UpdateCommand extends AbstractCommand
{
    /**
     * Log lines already printed, by index.
     *
     * 🚨 By index and by value, not by counting. A waiting step REPLACES the
     * last line rather than appending (Run::waiting), so a counter would either
     * miss it or repeat everything after it.
     *
     * @var array<int,string>
     */
    private array $printed = [];

    public function __construct(
        private Paths $paths,
        private RunStore $runs,
        private StepRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('millwright:update')
            ->setDescription('Update installed packages, as one checkpointed run that rolls back if it fails.')
            ->addArgument(
                'packages',
                InputArgument::IS_ARRAY,
                'Packages to update, as vendor/name. Leave empty and pass --all for everything the last check found.'
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Update every package the last check found a newer version for.')
            ->addOption(
                'repin',
                null,
                InputOption::VALUE_NONE,
                'Allow raising a requirement this site pins to an exact version. Without it, a pinned package is refused rather than silently skipped.'
            )
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Drive the run already in progress instead of starting a new one.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Stop waiting after this many seconds.', '3600');
    }

    protected function fire(): int
    {
        if ($this->input->getOption('resume')) {
            return $this->resume();
        }

        $named = array_values(array_filter(array_map('strval', (array) $this->input->getArgument('packages'))));
        $all   = (bool) $this->input->getOption('all');

        if ($all && $named !== []) {
            $this->error('Name the packages, or pass --all. Both together is ambiguous, so nothing was started.');

            return 1;
        }

        if ($all) {
            $named = array_keys((array) ($this->check()->cached()['updates'] ?? []));

            if ($named === []) {
                /*
                 * Exit 0: nothing to do is not a failure, and a script that
                 * runs this nightly should not page anyone over it.
                 */
                $this->info('Everything the last check knows about is already up to date.');

                if ($this->check()->isStale()) {
                    $this->info('That check is no longer fresh, though — run millwright:check --force first.');
                }

                return 0;
            }
        }

        if ($named === []) {
            $this->error('Name at least one package as vendor/name, or pass --all.');

            return 1;
        }

        foreach ($named as $package) {
            // The only value here that reaches a command line.
            if (! preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $package)) {
                $this->error("That is not a package name: $package");

                return 1;
            }
        }

        if (($refusal = $this->notInstalled($named)) !== null) {
            /*
             * 🚨 Refused rather than attempted, because `composer update` on a
             * package that is not installed does nothing at all and reports
             * success. A typo would otherwise print a clean run and leave the
             * site exactly as it was.
             */
            $this->error($refusal);

            return 1;
        }

        if ((new Capability($this->paths->base))->resolveTier() === Capability::NONE) {
            $this->error('This host does not have enough memory for Composer to work out what an update involves. '
                . 'Nothing was started. Ask your host to raise memory_limit to 256 MB and try again.');

            return 1;
        }

        $existing = $this->runs->latest();

        if ($existing !== null && ! $existing->isFinished()) {
            $age = time() - $existing->movedAt;

            $this->error("An update is already in progress: {$existing->id}, at {$existing->phase}"
                . ($age > 120 ? ', and nothing has moved for ' . round($age / 60) . ' minutes' : '') . '.');
            $this->error('Drive it with --resume, or abandon it on the Millwright screen. Nothing was started.');

            return 1;
        }

        $pins  = (new Repin($this->paths->base . '/composer.json', $this->storagePath('updates.json')))->pins($named);
        $repin = [];

        if ($pins !== []) {
            if (! $this->input->getOption('repin')) {
                /*
                 * 🚨 The failure this command exists to end. Without the flag
                 * we could still start a run, and Composer would answer
                 * "Nothing to modify in lock file", exit 0, and the run would
                 * go green having moved nothing. Refusing, by name, with the
                 * flag that fixes it, is the only honest answer.
                 */
                $this->error(count($pins) . ' package(s) are pinned to an exact version, so an update cannot move them:');

                foreach ($pins as $package => $pin) {
                    $this->error("  $package is pinned at {$pin['from']} and would have to be raised to {$pin['to']}");
                }

                $this->error('Nothing was started. Pass --repin to raise those requirements to the version the check found.');

                return 1;
            }

            foreach ($pins as $package => $pin) {
                $repin[$package] = $pin['to'];
                $this->info("Raising $package from {$pin['from']} to {$pin['to']}.");
            }
        }

        $id = 'r' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));

        (new WorkDir($this->paths->storage, $id))->create()->remember($named, 'update', $repin);

        $this->info('Updating ' . implode(', ', $named));

        return $this->pump($this->runner->begin($id));
    }

    /**
     * Take over a run somebody else started.
     *
     * 🚨 Worth having on its own: a run left behind by a browser tab that was
     * closed, or by a worker that died, is exactly the state that used to need
     * someone to know what a work directory was.
     */
    private function resume(): int
    {
        $run = $this->runs->latest();

        if ($run === null) {
            $this->error('There is no run to resume.');

            return 1;
        }

        if ($run->isFinished()) {
            $this->info("The last run ({$run->id}) already finished: {$run->state}.");

            return $run->state === Run::DONE ? 0 : 1;
        }

        $this->info("Resuming {$run->id}, at {$run->phase}.");

        return $this->pump($run);
    }

    /**
     * Turn the handle until the run stops, printing what it says as it says it.
     */
    private function pump(Run $run): int
    {
        $deadline = time() + max(60, (int) $this->input->getOption('timeout'));

        while (true) {
            $this->emit($run->log);

            if ($run->isFinished()) {
                break;
            }

            if (time() > $deadline) {
                /*
                 * 🚨 The run is NOT abandoned or rolled back here. It is on
                 * disk, mid-phase, and resumable — deciding to undo somebody's
                 * half-finished update because a timeout elapsed is not this
                 * command's call to make.
                 */
                $this->error("Gave up waiting after {$this->input->getOption('timeout')}s. "
                    . "The run is not lost: {$run->id} is at {$run->phase} and `millwright:update --resume` will carry on.");

                return 1;
            }

            $run = $this->runner->step($run->id);

            if ($this->runner->wasBusy() || $this->runner->wasWaiting()) {
                // Another driver has it, or the work is done but not live yet.
                $this->pause();
            }
        }

        if ($run->state === Run::DONE) {
            $this->info('Done.');

            return 0;
        }

        if ($run->state === Run::ROLLBACK) {
            $this->error("Rolled back at {$run->errorStep}: {$run->error}");
            $this->error('The site is as it was before this started.');

            return 1;
        }

        $this->error("Failed at {$run->errorStep}: {$run->error}");
        $this->error("Nothing was rolled back automatically. Roll {$run->id} back from the Millwright screen, "
            . 'or fix the cause and run this again.');

        return 1;
    }

    /**
     * Print log lines that are new, or that have changed in place.
     *
     * @param list<string> $log
     */
    private function emit(array $log): void
    {
        foreach ($log as $i => $line) {
            if (($this->printed[$i] ?? null) === $line) {
                continue;
            }

            $this->printed[$i] = $line;
            $this->info('  ' . $line);
        }
    }

    /** Which of these the site does not actually have, if any. */
    private function notInstalled(array $packages): ?string
    {
        $lock = @file_get_contents($this->paths->base . '/composer.lock');

        if ($lock === false) {
            // Nothing to check against; the plan phase will say so properly.
            return null;
        }

        $data = (array) json_decode($lock, true);
        $have = [];

        foreach (array_merge((array) ($data['packages'] ?? []), (array) ($data['packages-dev'] ?? [])) as $package) {
            if (isset($package['name'])) {
                $have[(string) $package['name']] = true;
            }
        }

        $missing = array_values(array_filter($packages, fn (string $p): bool => ! isset($have[$p])));

        if ($missing === []) {
            return null;
        }

        return implode(', ', $missing) . ' is not installed, so there is nothing to update. '
            . 'Install it from the Millwright screen first. Nothing was started.';
    }

    private function check(): UpdateCheck
    {
        return new UpdateCheck($this->storagePath('updates.json'));
    }

    private function storagePath(string $file): string
    {
        return $this->paths->storage . '/millwright/' . $file;
    }

    /**
     * Overridable so the tests can drive a run without waiting on a clock.
     */
    protected function pause(): void
    {
        usleep(500_000);
    }
}
