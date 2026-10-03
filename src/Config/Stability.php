<?php

namespace ErnestDefoe\Millwright\Config;

use RuntimeException;

/**
 * How finished a release has to be before Composer will install it.
 *
 * 🚨 On a Flarum 2 forum this is not optional. Every 2.0 release so far is a
 * release candidate, so a site with the default `stable` cannot install a single
 * one of them — and the error it gets says the package "could not be found in
 * any version", which sounds like the package is missing rather than like a
 * setting is wrong. The site this was built for runs `beta`.
 */
class Stability
{
    /** Composer's own order, loosest last. */
    public const LEVELS = ['stable', 'RC', 'beta', 'alpha', 'dev'];

    public function __construct(private JsonFile $file)
    {
    }

    /** @return array{minimumStability:string, preferStable:bool} */
    public function current(): array
    {
        $data = $this->file->read();

        return [
            'minimumStability' => (string) ($data['minimum-stability'] ?? 'stable'),
            // Composer's own default is false, but every Flarum install ships it
            // as true, and reporting the effective value beats reporting the spec.
            'preferStable'     => (bool) ($data['prefer-stable'] ?? false),
        ];
    }

    public function set(string $level, bool $preferStable): void
    {
        if (! in_array($level, self::LEVELS, true)) {
            throw new RuntimeException('Minimum stability must be one of: ' . implode(', ', self::LEVELS) . '.');
        }

        $data = $this->file->read();
        $data['minimum-stability'] = $level;
        $data['prefer-stable'] = $preferStable;

        $this->file->write($data);
    }

    /**
     * What changing this actually means, in words.
     *
     * 🚨 Said before the change, not after. "minimum-stability: dev" is a setting;
     * "Composer may install unreleased code from a branch" is the consequence,
     * and the consequence is the part somebody is deciding about.
     */
    public function consequence(string $level): string
    {
        return match ($level) {
            'stable' => 'Only finished releases. On Flarum 2 this currently rules out Flarum itself, which is still a release candidate.',
            'RC'     => 'Release candidates as well as finished releases. This is the minimum that can install Flarum 2 today.',
            'beta'   => 'Betas too. Most Flarum 2 extensions are published as betas or release candidates, so this is the usual choice.',
            'alpha'  => 'Alphas as well — early code that is expected to change.',
            'dev'    => 'Unreleased code straight from a branch, which can change under you without a version number changing.',
            default  => '',
        };
    }
}
