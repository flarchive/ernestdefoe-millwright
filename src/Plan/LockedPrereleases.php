<?php

namespace ErnestDefoe\Millwright\Plan;

use Composer\Semver\VersionParser;
use ErnestDefoe\Millwright\Config\StabilityRule;

/**
 * Installed packages less finished than the forum's minimum-stability now
 * allows: a beta installed before the forum was set to Stable.
 *
 * Composer refuses every partial update while one is locked ("fixed to
 * 2.0.0-beta.1 (lock file version) by a partial update but that version is
 * rejected by your minimum-stability"), so updating or installing anything
 * fails (Claudius, 2026-11-24). Many Flarum 2 extensions only have betas, so
 * moving them to a stable release is often impossible. Composer's own answer
 * is a stability flag on that one requirement (`^2.0@beta`), which keeps
 * Stable for everything else.
 */
class LockedPrereleases
{
    /**
     * @param array<string,mixed> $composerJson
     * @param array<string,mixed> $lock
     * @return array<string,string> package => the stability its locked version needs
     */
    public static function find(array $composerJson, array $lock): array
    {
        $rule = StabilityRule::fromArray($composerJson);
        $out = [];

        foreach ([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])] as $package) {
            $name = is_array($package) ? ($package['name'] ?? null) : null;
            $version = is_array($package) ? ($package['version'] ?? null) : null;

            if (is_string($name) && is_string($version) && ! $rule->allows($name, $version)) {
                $out[$name] = VersionParser::parseStability($version);
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * The ones a flag can fix: those composer.json requires itself. A
     * dependency has no requirement of the forum's own to carry a flag.
     *
     * @param array<string,string> $found from find()
     * @param array<string,mixed> $composerJson
     * @return array<string,string>
     */
    public static function flaggable(array $found, array $composerJson): array
    {
        $require = (array) ($composerJson['require'] ?? []);

        return array_filter($found, fn (string $package) => is_string($require[$package] ?? null), ARRAY_FILTER_USE_KEY);
    }

    /** `^2.0` → `^2.0@beta`. A constraint that already carries a flag is left alone. */
    public static function withFlag(string $constraint, string $stability): string
    {
        return str_contains($constraint, '@') ? $constraint : $constraint.'@'.$stability;
    }
}
