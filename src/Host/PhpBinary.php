<?php

namespace ErnestDefoe\Millwright\Host;

/**
 * Where the command-line PHP is.
 *
 * 🚨 PHP_BINARY is the wrong answer under FPM, and FPM is most hosts. Under
 * php-fpm it is the FPM binary — handing it a script does not run the script,
 * and the failure is the confusing kind: proc_open succeeds, a process starts,
 * and it exits having done nothing recognisable.
 *
 * 🚨 Every candidate is PROVED rather than trusted, by running it and asking
 * which SAPI it is. Guessing from the filename is not good enough: the first
 * version of this trusted PHP_BINDIR, and on the machine it was written on
 * PHP_BINDIR is `/bin` — which contains no PHP at all. It failed safely and
 * with a clear message, which is the only reason it was cheap to find.
 *
 * 🚨 The most reliable candidate is the FPM binary's OWN DIRECTORY with `-fpm`
 * stripped: `php85-fpm` sits beside `php85`, same build, same version, same
 * extensions. That layout is shared by Herd, cPanel, Plesk and most distro
 * packages, and it is checked before anything on a fixed path so a forum gets
 * the PHP it is actually running rather than whatever the host defaults to.
 */
class PhpBinary
{
    private ?string $resolved = null;
    private bool $searched = false;

    public function __construct(private ?string $override = null)
    {
    }

    public function path(): ?string
    {
        if ($this->override !== null) {
            return $this->override;
        }

        if ($this->searched) {
            return $this->resolved;
        }

        $this->searched = true;

        // Under the CLI, PHP_BINARY is certainly a PHP that runs scripts.
        if (PHP_SAPI === 'cli' && is_file(PHP_BINARY)) {
            return $this->resolved = PHP_BINARY;
        }

        foreach ($this->candidates() as $candidate) {
            if ($this->isCli($candidate)) {
                return $this->resolved = $candidate;
            }
        }

        return $this->resolved = null;
    }

    /**
     * 🚨 Proof, not a guess: run it and ask. `php85-fpm -v` prints a version
     * quite happily and is still useless for running a script, so a version
     * check would pass on exactly the binary being avoided. Only the SAPI
     * distinguishes them.
     */
    private function isCli(string $candidate): bool
    {
        if (! is_file($candidate) || ! is_executable($candidate)) {
            return false;
        }

        if (! function_exists('proc_open')) {
            return false;
        }

        $process = @proc_open(
            [$candidate, '-r', 'echo PHP_SAPI;'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($process)) {
            return false;
        }

        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return trim($out) === 'cli';
    }

    /**
     * In order of how likely each is to be the same PHP this request runs under.
     *
     * @return list<string>
     */
    private function candidates(): array
    {
        $dir  = dirname(PHP_BINARY);
        $me   = basename(PHP_BINARY);
        $ver  = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
        $dot  = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        $candidates = [];

        // php85-fpm → php85, right beside it. The same build, so the same
        // extensions and the same php.ini as the request asking.
        if (str_ends_with($me, '-fpm')) {
            $candidates[] = $dir . '/' . substr($me, 0, -4);
        }

        foreach ([$dir, PHP_BINDIR, '/usr/local/bin', '/usr/bin'] as $where) {
            $candidates[] = $where . '/php' . $ver;
            $candidates[] = $where . '/php' . $dot;
            $candidates[] = $where . '/php';
        }

        // cPanel keeps each version in its own tree, and its `php` is a selector
        // that may point at a different version than this forum runs on.
        $candidates[] = '/opt/cpanel/ea-php' . $ver . '/root/usr/bin/php';
        $candidates[] = '/opt/plesk/php/' . $dot . '/bin/php';

        return array_values(array_unique($candidates));
    }
}
