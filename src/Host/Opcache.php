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
    /** Nothing to do: either no opcache, or it checks timestamps itself. */
    public const FINE = 'fine';

    /** Compiled code is cached and never revalidated. Something must clear it. */
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
        $directives = is_array($config) ? ($config['directives'] ?? []) : [];

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
            'state'     => (! $enabled || $validates) ? self::FINE : self::STALE_RISK,
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
            return [
                'done' => true,
                'why'  => $situation['enabled']
                    ? 'PHP checks file timestamps here, so the new files are picked up on their own.'
                    : 'No compiled-code cache is in the way here.',
            ];
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
                'why'  => 'This step ran from the command line, which cannot clear the web server\'s compiled-code cache. '
                    . 'The files are updated, but PHP on this host is set never to re-read them '
                    . '(opcache.validate_timestamps is off), so restart PHP-FPM to make the update take effect.',
            ];
        }

        if (! $situation['canReset'] || ! @opcache_reset()) {
            return [
                'done' => false,
                'why'  => 'The files are updated, but PHP\'s compiled-code cache could not be cleared from here and '
                    . 'this host is set never to re-read files. Restart PHP-FPM to make the update take effect.',
            ];
        }

        return ['done' => true, 'why' => 'Cleared PHP\'s compiled-code cache so the new files are used.'];
    }
}
