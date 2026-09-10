<?php

namespace ErnestDefoe\Millwright\Host;

/**
 * Whether the code this update writes will actually be the code that runs.
 *
 * 🚨 An update can succeed completely and change nothing anybody can see. PHP
 * caches compiled scripts, and on a host tuned with
 * `opcache.validate_timestamps=0` it never looks at the files again — so new
 * files land, every phase reports success, and the site serves the old version
 * until somebody restarts PHP. Silent, total, and indistinguishable from the
 * update not having run.
 *
 * 🚨 It is also the reason this extension replaces directories in place rather
 * than flipping a symlink between prepared slots, which was the original plan.
 * A symlink flip is atomic on disk and invisible to PHP: with
 * `opcache.revalidate_path=0` — the default, measured as false on the machine
 * this was written on — opcache resolves a symlink ONCE and caches the result,
 * and realpath_cache_ttl (120s by default) holds the old target on top of that.
 * Slots would trade a microsecond where a package is missing for minutes of
 * quietly serving the old code, which is a far worse failure because nothing
 * looks wrong.
 *
 * Replacing a directory keeps the path constant, so the ordinary timestamp
 * check picks the change up within revalidate_freq seconds. The boring approach
 * is the correct one here.
 */
class Opcache
{
    /** No compiled-code cache in the way at all. */
    public const FINE = 'fine';

    /**
     * Compiled code is cached, so an update is not live until it is cleared.
     *
     * 🚨 This covers BOTH configurations, and the second one caught me out on a
     * real site. `validate_timestamps=0` never re-reads files — obvious. But
     * `validate_timestamps=1` with `revalidate_freq=60` — the default in
     * Flarum's own Docker image — does not re-read them for up to a MINUTE
     * either, which is long enough for the site to serve a fatal error for
     * classes that exist on disk.
     *
     * Installing this very extension on a production forum did exactly that:
     * Composer regenerated the autoloader, the extension was enabled a second
     * later, and every page returned 500 with "class not found" until the
     * revalidate window elapsed. Treating a nonzero freq as "fine" would leave
     * that gap open for every update Millwright performs.
     */
    public const STALE_RISK = 'stale-risk';

    /**
     * @return array{state:string, enabled:bool, validates:bool, freq:int, canReset:bool}
     */
    public function situation(): array
    {
        if (! function_exists('opcache_get_configuration')) {
            return ['state' => self::FINE, 'enabled' => false, 'validates' => true, 'freq' => 0, 'canReset' => false];
        }

        $config = @opcache_get_configuration();

        return $this->situationFrom(is_array($config) ? (array) ($config['directives'] ?? []) : []);
    }

    /**
     * The same judgement, from directives handed in.
     *
     * Separated so the rule can be tested against the configurations that
     * actually exist in the wild — including the one that caused a production
     * site to serve fatal errors — rather than only against whatever this
     * machine happens to be set to.
     *
     * @param array<string,mixed> $directives
     * @return array{state:string, enabled:bool, validates:bool, freq:int, canReset:bool}
     */
    public function situationFrom(array $directives): array
    {

        /*
         * 🚨 `opcache.enable` is per-SAPI, and this must be answered for the SAPI
         * that will SERVE the pages — not for whichever process happens to ask.
         * A CLI worker reports opcache disabled (enable_cli is off by default)
         * while the web pool has it very much enabled, so a check that trusts
         * the asking process would report "fine" from the one place that cannot
         * see the problem.
         */
        $enabled = (bool) ($directives['opcache.enable'] ?? false);
        $validates = (bool) ($directives['opcache.validate_timestamps'] ?? true);

        return [
            /*
             * 🚨 Any enabled opcache needs clearing, not just one with
             * validate_timestamps off. See the constant above: a 60-second
             * revalidate window is a 60-second window of serving code that no
             * longer matches the files, and the cost of clearing is a recompile
             * that was going to happen anyway.
             */
            'state'     => $enabled ? self::STALE_RISK : self::FINE,
            'enabled'   => $enabled,
            'validates' => $validates,
            'freq'      => (int) ($directives['opcache.revalidate_freq'] ?? 0),
            'canReset'  => function_exists('opcache_reset'),
        ];
    }

    /**
     * Throw away the compiled code so the new files are read.
     *
     * 🚨 Only meaningful in the process pool that serves pages. Calling this
     * from a CLI queue worker resets that worker's own cache, which nobody is
     * using, and leaves the web pool exactly as stale as before — so this
     * reports whether it did anything real rather than returning success for
     * having called a function.
     *
     * @return array{done:bool, why:string}
     */
    public function clear(): array
    {
        $situation = $this->situation();

        if ($situation['state'] === self::FINE) {
            return ['done' => true, 'why' => 'No compiled-code cache is in the way here.'];
        }

        if (PHP_SAPI === 'cli') {
            /*
             * The queue-worker case. Said plainly rather than papered over: the
             * files are correct and the site is serving stale compiled copies,
             * which is exactly the sort of thing that gets diagnosed three days
             * later as "the update didn't work".
             */
            return [
                'done' => false,
                'why'  => $situation['validates']
                    ? 'This step ran from the command line, which cannot clear the web server\'s compiled-code cache. '
                        . 'The files are updated and PHP will pick them up within '
                        . max(1, $situation['freq']) . ' second(s) on its own.'
                    : 'This step ran from the command line, which cannot clear the web server\'s compiled-code cache. '
                        . 'The files are updated, but PHP on this host is set never to re-read them '
                        . '(opcache.validate_timestamps is off), so restart PHP-FPM to make the update take effect.',
            ];
        }

        if (! $situation['canReset'] || ! @opcache_reset()) {
            return [
                'done' => false,
                'why'  => $situation['validates']
                    ? 'The files are updated. PHP\'s compiled-code cache could not be cleared from here, so the '
                        . 'change becomes live within ' . max(1, $situation['freq']) . ' second(s).'
                    : 'The files are updated, but PHP\'s compiled-code cache could not be cleared from here and '
                        . 'this host is set never to re-read files. Restart PHP-FPM to make the update take effect.',
            ];
        }

        return ['done' => true, 'why' => 'Cleared PHP\'s compiled-code cache so the new files are used.'];
    }
}
