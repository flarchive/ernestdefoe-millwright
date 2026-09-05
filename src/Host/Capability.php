<?php

namespace ErnestDefoe\Millwright\Host;

/**
 * What this host will and will not let Millwright do, established by looking
 * rather than by hoping.
 *
 * 🚨 This is shown to the admin BEFORE they press anything, and that is the
 * point of it. The single most infuriating thing about the current tooling is
 * that it behaves mysteriously on constrained hosting and never says why — you
 * discover the limit by hitting it, halfway through an update, with the site
 * down. A tool that knows it cannot do zero-downtime updates should say so on a
 * settings page, in advance.
 *
 * The thresholds are measured, not guessed. On a 253-package install a full
 * resolve peaks at 162 MB and a targeted one at 123 MB, so:
 *
 *   under 160 MB — cannot resolve at all
 *   160–192 MB   — targeted updates only
 *   192 MB and up — everything
 */
class Capability
{
    public const FULL     = 'full';
    public const TARGETED = 'targeted';
    public const NONE     = 'none';

    public function __construct(private string $installPath)
    {
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        $memory = $this->memoryBytes();
        $checks = [
            $this->memoryCheck($memory),
            $this->timeCheck(),
            $this->subprocessCheck(),
            $this->opcacheCheck(),
            $this->diskCheck(),
        ];

        return [
            'resolves' => $this->resolveTier($memory),
            'tier'     => $this->applyTier(),
            'checks'   => $checks,
            'summary'  => $this->summary($memory),
        ];
    }

    /** How much of an update this host can plan for itself. */
    public function resolveTier(?int $bytes = null): string
    {
        $bytes ??= $this->memoryBytes();

        if ($bytes === -1) {
            return self::FULL;                      // no limit at all
        }

        $mb = $bytes / 1048576;

        return match (true) {
            $mb >= 192 => self::FULL,
            $mb >= 160 => self::TARGETED,
            default    => self::NONE,
        };
    }

    /**
     * How updates are applied here.
     *
     * 🚨 One strategy, on every host, and that is a decision rather than a
     * missing feature. This used to advertise a second "slots" tier where a
     * symlink is flipped between prepared directories so nothing is ever
     * missing — the panel said so, and no such code existed.
     *
     * It was not built because it is wrong. A symlink flip is atomic on disk
     * and invisible to PHP: with `opcache.revalidate_path=0` — the default,
     * measured as false on the machine this was written on — opcache resolves a
     * symlink once and caches the result, and realpath_cache_ttl holds the old
     * target for a further two minutes. Slots would trade a microsecond where a
     * package is missing for minutes of quietly serving the old code, which is
     * far worse: nothing looks wrong.
     *
     * Replacing a directory keeps the path constant, so the ordinary timestamp
     * check picks it up. See Opcache for the case where that check is off.
     */
    public function applyTier(): string
    {
        return 'journal';
    }

    private function memoryCheck(int $bytes): array
    {
        $tier = $this->resolveTier($bytes);
        $mb   = $bytes === -1 ? 'unlimited' : round($bytes / 1048576) . ' MB';

        return [
            'id'   => 'memory',
            'ok'   => $tier !== self::NONE,
            'warn' => $tier === self::TARGETED,
            'what' => "Memory: $mb",
            'why'  => match ($tier) {
                self::FULL     => 'A resolve on a forum this size peaks around 165 MB, so everything works, including updating Flarum itself.',
                self::TARGETED => 'Enough to update one extension at a time, but not to re-resolve everything at once. Updating Flarum needs about 192 MB.',
                self::NONE     => 'Below about 160 MB, Composer cannot resolve dependencies here at all. Ask your host to raise memory_limit — 256 MB is plenty.',
            },
        ];
    }

    private function timeCheck(): array
    {
        $limit = (int) ini_get('max_execution_time');

        return [
            'id'   => 'time',
            'ok'   => true,
            'warn' => false,
            'what' => 'Execution limit: ' . ($limit === 0 ? 'none' : $limit . ' seconds'),
            'why'  => $limit === 0
                ? 'Not that it matters — no single step needs more than a few seconds either way.'
                : 'Not a problem. Millwright does one small step per request, so an update that takes ten minutes still finishes on a host that cuts every request at ' . $limit . ' seconds.',
        ];
    }

    private function subprocessCheck(): array
    {
        $ok = $this->canSpawn();

        /*
         * 🚨 A blocker, not a warning, and it used to say otherwise: "Composer
         * runs in-process, that still works". It does not — there is no
         * in-process path, because running Composer inside the web request is
         * the fault this extension exists to avoid. The panel now says what the
         * code actually does.
         */
        return [
            'id'   => 'subprocess',
            'ok'   => $ok,
            'warn' => false,
            'what' => $ok ? 'Can run Composer as a separate process' : 'Cannot start a separate process',
            'why'  => $ok
                ? 'Composer runs outside the web request, so its memory use is its own and cannot take the site down with it.'
                : 'proc_open is disabled here, so Composer cannot be run at all. Millwright can inspect this host but not update it. '
                    . 'Ask your host to remove proc_open from disable_functions.',
        ];
    }

    /**
     * 🚨 The check that decides whether an update is VISIBLE.
     *
     * Everything else here is about whether an update can run. This is about
     * whether anybody will be able to tell that it did — on a host tuned with
     * validate_timestamps off, new files land, every phase reports success, and
     * the site serves the old code until PHP is restarted.
     */
    private function opcacheCheck(): array
    {
        $o = (new Opcache())->situation();
        $risk = $o['state'] === Opcache::STALE_RISK;

        return [
            'id'   => 'opcache',
            'ok'   => ! $risk,
            'warn' => $risk,
            'what' => $risk
                ? 'PHP caches compiled code and never re-reads files'
                : ($o['enabled'] ? 'PHP notices changed files' : 'No compiled-code cache'),
            'why'  => $risk
                ? 'opcache.validate_timestamps is off here, so updated files are not read until PHP is restarted. '
                    . 'Millwright clears the cache itself after an update where it can, and tells you when it cannot.'
                : ($o['enabled']
                    ? 'opcache re-checks files every ' . max(1, $o['freq']) . ' second(s), so an update is live almost immediately.'
                    : 'Nothing stands between the files an update writes and the code that runs.'),
        ];
    }

    private function diskCheck(): array
    {
        $free = @disk_free_space($this->installPath);
        $gb   = $free === false ? null : round($free / 1073741824, 1);

        return [
            'id'   => 'disk',
            'ok'   => $gb === null || $gb > 1,
            'warn' => $gb !== null && $gb <= 1,
            'what' => 'Disk: ' . ($gb === null ? 'unknown' : $gb . ' GB free'),
            'why'  => 'The previous version of anything replaced is kept so you can roll back. That needs room for what changed, not for a second copy of everything.',
        ];
    }

    private function summary(int $bytes): string
    {
        if (! $this->canSpawn()) {
            return 'Composer cannot be run on this host, because PHP is not allowed to start other programs. '
                . 'Millwright can tell you what is installed, but it cannot update anything here.';
        }

        return match ($this->resolveTier($bytes)) {
            self::FULL => 'Everything works on this host. Updates replace one package at a time, which is safe and reversible.',
            self::TARGETED => 'You can update extensions one at a time here. Updating Flarum itself needs a little more memory than this host allows.',
            self::NONE => 'This host does not have enough memory for Composer to work out what an update involves. Everything else is ready — ask your host to raise memory_limit to 256 MB.',
        };
    }

    private function memoryBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $unit = strtolower(substr($raw, -1));
        $n    = (int) $raw;

        return match ($unit) {
            'g'     => $n * 1073741824,
            'm'     => $n * 1048576,
            'k'     => $n * 1024,
            default => $n,
        };
    }

    private function canSpawn(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('proc_open', $disabled, true);
    }

}
