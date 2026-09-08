<?php

namespace ErnestDefoe\Millwright\Console;

use ErnestDefoe\Millwright\Work\UpdateCheck;
use Flarum\Console\AbstractCommand;
use Flarum\Foundation\Paths;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php flarum millwright:check` — is anything newer available?
 *
 * 🚨 One cheap HTTP call per installed package, and no Composer. That is what
 * makes it safe to run nightly on somebody else's shared hosting: a resolve
 * would be 16 seconds and 165 MB, which is not something to do on a schedule on
 * a host that may only have 256 MB to begin with.
 *
 * The answer is a hint, and the admin screen says so. Whether an update can
 * actually be installed is a question for the plan, when somebody asks for it.
 */
class CheckCommand extends AbstractCommand
{
    public function __construct(private Paths $paths)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('millwright:check')
            ->setDescription('Check whether newer versions of installed packages exist.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Check even if the last result is still fresh.');
    }

    protected function fire(): int
    {
        $check = new UpdateCheck($this->paths->storage . '/millwright/updates.json');

        if (! $this->input->getOption('force') && ! $check->isStale()) {
            $this->info('The last check is still fresh. Use --force to check anyway.');

            return 0;
        }

        $packages = $this->lockPackages();

        if ($packages === []) {
            $this->error('Could not read composer.lock, so there is nothing to check.');

            return 1;
        }

        // 🚨 Extensions and Flarum only — see UpdateCheck::interesting().
        $installed = $check->interesting($packages);

        $result = $check->refresh($installed);
        $count  = count($result['updates']);

        $this->info($count === 0
            ? 'Everything that can be checked is up to date.'
            : "$count package(s) have a newer version available.");

        foreach ($result['updates'] as $name => $move) {
            $this->info("  $name  {$move['from']} → {$move['to']}");
        }

        if ($result['uncheckable'] !== []) {
            // Said out loud rather than omitted: silence here would read as
            // "these are fine", which is not something this can know.
            $this->info(count($result['uncheckable']) . ' package(s) are not on Packagist and could not be checked.');
        }

        return 0;
    }

    /** @return list<array<string,mixed>> */
    private function lockPackages(): array
    {
        $lock = @file_get_contents($this->paths->base . '/composer.lock');

        if ($lock === false) {
            return [];
        }

        $data = (array) json_decode($lock, true);

        return array_merge(
            array_values((array) ($data['packages'] ?? [])),
            array_values((array) ($data['packages-dev'] ?? []))
        );
    }
}
