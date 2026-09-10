<?php

namespace ErnestDefoe\Millwright\Work;

use ErnestDefoe\Millwright\Host\PhpBinary;
use RuntimeException;

/**
 * Runs Composer, out of process where the host allows it.
 *
 * 🚨 A subprocess by preference, and the reason is the whole story of why the
 * current tooling fails. Extension Manager runs Composer *inside* the PHP worker
 * serving the request, which is what makes its memory cap load-bearing, what
 * makes a killed request able to take the vendor directory with it, and what
 * puts a booted Flarum's memory on the same budget as the resolve.
 *
 * Out of process, none of that is true: Composer gets its own limit, its own
 * lifetime, and its failures are exit codes rather than a dead worker.
 *
 * Where a subprocess is impossible — proc_open disabled, or no command-line PHP
 * — this refuses, and says which of those it is. That is a real limitation and
 * it is stated rather than papered over: running Composer in-process is the
 * thing that makes the current tooling fragile, so offering it as a fallback
 * would be reintroducing the fault this exists to avoid. The host panel tells
 * an admin where they stand before they press anything.
 */
class ComposerRunner
{
    private PhpBinary $php;

    public function __construct(
        private string $installPath,
        private ?string $composerBin = null,
        private ?string $composerHome = null,
        ?string $phpBin = null,
    ) {
        $this->php = new PhpBinary($phpBin);
    }

    public function canSpawn(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('proc_open', $disabled, true)
            && $this->binary() !== null
            && $this->php->path() !== null;
    }

    /**
     * @param list<string> $args
     * @return array{code:int, output:string}
     */
    public function run(array $args, int $timeout = 600): array
    {
        if (! $this->canSpawn()) {
            throw new RuntimeException($this->whyNot());
        }

        $cmd = array_merge(
            [$this->php->path(), $this->binary()],
            $args,
            ['--no-interaction', '--working-dir=' . $this->installPath]
        );

        return Process::run($cmd, $this->installPath, [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            /*
             * 🚨 -1, set explicitly. Composer raises its own limit to 1.5G on
             * startup unless this is set, which would make any measurement of
             * what a host can actually do meaningless — and the whole capability
             * panel depends on those measurements being real.
             */
            'COMPOSER_MEMORY_LIMIT' => '-1',
            'COMPOSER_HOME' => $this->composerHome ?? ($this->installPath . '/storage/.composer'),
            'HOME' => getenv('HOME') ?: $this->installPath,
        ], $timeout);
    }

    /**
     * 🚨 The BUNDLED Composer first, and a host's own only as a last resort.
     *
     * Millwright requires composer/composer, so the library is in the same
     * vendor tree as everything else and is simply always there. That is not
     * belt-and-braces: a great many shared hosts have no `composer` command at
     * all, and the ones that do are running whatever version their panel
     * shipped. Depending on the host's would make the behaviour of an update
     * differ per host in ways nobody could reproduce.
     *
     * Note this is still run as a SUBPROCESS. Bundling the library is how
     * Extension Manager gets Composer too — the difference that matters is that
     * it then calls Composer's API inside the PHP worker serving the request,
     * which is what puts a booted Flarum and a dependency resolve on one memory
     * budget. Same library, own process, own limit.
     */
    private function binary(): ?string
    {
        if ($this->composerBin !== null) {
            return $this->composerBin;
        }

        $candidates = [
            $this->installPath . '/vendor/composer/composer/bin/composer',
            $this->installPath . '/vendor/bin/composer',
            $this->installPath . '/composer.phar',
            '/usr/local/bin/composer',
            '/usr/bin/composer',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $this->composerBin = $candidate;
            }
        }

        return null;
    }

    /**
     * Which of the three preconditions is missing, in words that name the thing
     * to fix. "Composer cannot be run" sends somebody hunting; "there is no
     * command-line PHP" tells their host exactly what to install.
     */
    private function whyNot(): string
    {
        if (! function_exists('proc_open')) {
            return 'This host does not allow PHP to start other programs (proc_open is disabled), '
                . 'so Composer cannot be run in its own process.';
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (in_array('proc_open', $disabled, true)) {
            return 'This host disables proc_open, so Composer cannot be run in its own process. '
                . 'Ask your host to remove proc_open from disable_functions.';
        }

        if ($this->binary() === null) {
            return 'Composer itself could not be found. It ships with Millwright, so this usually means the '
                . 'install is incomplete — reinstalling the extension should restore it.';
        }

        return 'No command-line PHP could be found on this host, so Composer cannot be run in its own process. '
            . 'Ask your host where the PHP CLI binary lives.';
    }
}
