<?php

namespace ErnestDefoe\Millwright\Work;

use ErnestDefoe\Millwright\Config\AuthTokens;
use ErnestDefoe\Millwright\Config\JsonFile;
use ErnestDefoe\Millwright\Config\Repositories;
use ErnestDefoe\Millwright\Config\StabilityRule;
use ErnestDefoe\Millwright\Discover\Compatibility;
use Flarum\Locale\TranslatorInterface;
use GuzzleHttp\Client;

/**
 * Whether anything newer exists, asked cheaply.
 *
 * This is deliberately NOT the same question as "can I have it".
 *
 * Conflating them makes the answer useless: a newer version existing tells you
 * nothing about whether it will install alongside everything else you have. Only a resolve knows that, and a
 * resolve is 16 seconds and 165 MB — far too expensive to run on a page load, or
 * on a schedule, or once per extension.
 *
 * So there are two answers, and they are kept apart on purpose:
 *
 *   this class — "3.6.0 exists and you are on 3.5.0". One cheap HTTP call per
 *                package, cacheable, safe to run nightly. It is a HINT.
 *   the plan   — "here is exactly what changes, what comes with it, and what
 *                blocks it". Run when somebody actually presses Update.
 *
 * Presenting the hint as a promise would be the lie. The badge says a newer
 * version exists; pressing Update is what finds out whether you can have it.
 */
class UpdateCheck
{
    /**
     * Where each version's release notes live, as read from Packagist while
     * checking: package => version => URL. Filled by fromPackagist(), so a
     * check costs no request beyond the one it already makes.
     *
     * @var array<string,array<string,string>>
     */
    protected array $releaseNotes = [];

    /** @var array<string,array<string,string>> package => version => its flarum/core constraint */
    protected array $coreRequires = [];

    /**
     * Saved with each check. A check saved before its updates were held to the
     * installed core counts as stale, so the scheduler replaces it at once.
     */
    public const RULES = 2;

    /** The forum's stability rules; null until forRoot(), and then nothing is filtered. */
    protected ?StabilityRule $stability = null;

    public function __construct(
        protected string $cachePath,
        protected int $freshFor = 21600,        // six hours
    ) {
    }

    /**
     * Offer only what an install could have: a version its stability rules
     * allow (minimum-stability, and a package's own `@beta` flag), and whose
     * flarum/core requirement the installed core meets. Install resolves with
     * exactly these rules, so without them the check offered a version that
     * needs flarum/core ^2.1 to a 2.0 forum on stable, which install had just
     * correctly declined (Claudius, 2026-11-23).
     */
    public function forRoot(StabilityRule $stability): static
    {
        $this->stability = $stability;

        return $this;
    }

    /**
     * The packages a composer.json requires itself: names with a vendor, so
     * not php, ext-* or lib-*.
     *
     * @return list<string>
     */
    public static function directRequires(string $composerJson): array
    {
        $data = is_file($composerJson) ? json_decode((string) file_get_contents($composerJson), true) : null;
        $names = array_keys((array) (is_array($data) ? ($data['require'] ?? []) : []));

        return array_values(array_filter($names, fn ($name) => is_string($name) && str_contains($name, '/')));
    }

    /**
     * Only things the site actually chose.
     *
     * Running this against a real forum returned 59 "updates", of which nearly
     * all were transitive dependencies — illuminate/collections 13.30.0 →
     * 13.30.1, guzzle 7 → 8, doctrine 3 → 4. Nobody updates those individually;
     * they arrive with the extension that needs them, and several are pinned by
     * flarum/core so the newer version is not installable at all.
     *
     * Reporting them would be worse than reporting nothing: a badge showing 59
     * when 4 things matter is a badge people stop reading. So the check is
     * limited to Flarum extensions and Flarum itself — the things somebody
     * deliberately installed and might deliberately update.
     *
     * And to anything the forum's own composer.json requires. fof/redis is
     * a Composer library, not an extension, so it has no settings page and
     * was never reported: its stable release went unseen (Ernest,
     * 2026-10-10). Required directly means somebody chose it; pulled in by
     * another package means nobody did, and those stay quiet.
     *
     * @param list<array{name:string,version:string,type?:string}> $packages
     * @param list<string> $direct packages the root composer.json requires
     * @return array<string,string>
     */
    public function interesting(array $packages, array $direct = []): array
    {
        $direct = array_flip($direct);

        $out = [];

        foreach ($packages as $package) {
            $name = (string) ($package['name'] ?? '');
            $type = (string) ($package['type'] ?? '');

            if ($name === '') {
                continue;
            }

            if ($type === 'flarum-extension' || $name === 'flarum/core' || isset($direct[$name])) {
                $out[$name] = (string) ($package['version'] ?? '');
            }
        }

        return $out;
    }

    /**
     * @param array<string,string> $installed package => installed version
     * @param callable(string):?array $fetch  overridable so this is testable
     *                                        without a network
     * @param callable(string):array<string,string>|null $notesFor release links
     *        for packages Packagist does not know, version => URL
     * @return array<string,mixed>
     */
    public function refresh(array $installed, ?callable $fetch = null, ?callable $notesFor = null): array
    {
        $fetch ??= fn (string $name) => $this->fromPackagist($name);

        $found = [];
        $unknown = [];
        $tracking = [];

        foreach ($installed as $name => $version) {
            /*
             * A package tracking a branch is not "uncheckable" — it is not a
             * version question at all.
             *
             * `dev-main` has no version to be newer than. Whether the branch has
             * moved is something only a resolve can answer, and this command
             * deliberately does not resolve. Lumping these in with packages we
             * genuinely could not reach told an admin that four extensions
             * "could not be checked", which is two wrong things at once: they
             * were reachable, and nothing was wrong.
             *
             * It also saves the request, which is the point of a nightly check
             * staying cheap.
             */
            if (self::tracksABranch($version)) {
                $tracking[] = $name;
                continue;
            }

            $versions = $fetch($name);

            if ($versions === null) {
                /*
                 * Named, not silently dropped. A protected or path-installed
                 * package is not on Packagist, and "we could not check this one"
                 * is a true and useful thing to say — whereas leaving it out
                 * implies it is up to date, which is a guess presented as a fact.
                 */
                $unknown[] = $name;
                continue;
            }

            $newest = $this->newestComparable(
                array_values(array_filter($versions, fn (string $v) => $this->installable($name, $v, $installed['flarum/core'] ?? null))),
                $version
            );

            if ($newest !== null && $this->isNewer($newest, $version)) {
                $found[$name] = ['from' => $version, 'to' => $newest];

                // Kept so a saved check can be held against the core installed when it is read.
                if (isset($this->coreRequires[$name][$newest])) {
                    $found[$name]['core'] = $this->coreRequires[$name][$newest];
                }

                // "What does this update give me?" (ClaudiusH, 2026-10-09). A
                // link to the release, never a guess: absent when the package
                // is not on GitHub.
                $notes = $this->releaseNotes[$name][$newest]
                    ?? ($notesFor ? ($notesFor($name)[$newest] ?? null) : null);

                if ($notes !== null) {
                    $found[$name]['notes'] = $notes;
                }
            }
        }

        $result = [
            'checkedAt' => time(),
            'rules' => self::RULES,
            'updates' => $found,
            'uncheckable' => $unknown,
            'tracking' => $tracking,
        ];

        /*
         * The directory is made here, not assumed. Nothing else creates
         * storage/millwright until the first update run, so on a fresh install
         * every check — the nightly one, the console one and "Check now" — wrote
         * into a directory that did not exist, the @ swallowed it, and the
         * screen said "Not checked yet" with no Update button anywhere, over a
         * check that had just reported eleven newer versions.
         */
        $dir = dirname($this->cachePath);

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($this->cachePath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $result;
    }

    /**
     * Packagist first, then the site's own protected repositories: the check the
     * console command and "Check now" both run.
     *
     * One place, because they drifted. "Check now" asked Packagist alone,
     * so pressing it dropped every protected extension's update that the nightly
     * check had found, until the next night put them back.
     *
     * @param array<string,string> $installed
     * @return array<string,mixed>
     */
    public function refreshWith(array $installed, PrivateIndex $protected): array
    {
        return $this->refresh(
            $installed,
            function (string $name) use ($protected) {
                $versions = $this->fromPackagist($name);

                if ($versions !== null) {
                    return $versions;
                }

                $versions = $protected->versionsFor($name);
                $this->coreRequires[$name] = $protected->coreRequirementsFor($name);

                return $versions;
            },
            fn (string $name) => $protected->releaseNotesFor($name),
        );
    }

    /**
     * The whole check for a forum: what its lock says is installed, filtered
     * as {@see interesting()}, asked of Packagist and then its protected
     * repositories. Null when composer.lock cannot be read.
     *
     * One place for "Check now", millwright:check and the re-check
     * millwright:update makes before giving up on a pin.
     *
     * @return array<string,mixed>|null
     */
    public function refreshSite(TranslatorInterface $translator, string $base): ?array
    {
        $lock = (array) json_decode((string) @file_get_contents($base.'/composer.lock'), true);
        $packages = array_merge(array_values((array) ($lock['packages'] ?? [])), array_values((array) ($lock['packages-dev'] ?? [])));

        if ($packages === []) {
            return null;
        }

        return $this->forRoot(StabilityRule::fromComposerJson($base.'/composer.json'))->refreshWith($this->interesting($packages, self::directRequires($base.'/composer.json')), new PrivateIndex(
            new Repositories($translator, new JsonFile($translator, $base.'/composer.json')),
            new AuthTokens($translator, new JsonFile($translator, $base.'/auth.json')),
            new Client(),
        ));
    }

    /** @return array<string,mixed> */
    public function cached(): array
    {
        if (! is_file($this->cachePath)) {
            return ['checkedAt' => null, 'updates' => [], 'uncheckable' => [], 'tracking' => []];
        }

        return (array) json_decode((string) file_get_contents($this->cachePath), true);
    }

    /**
     * The cached check, minus what has been installed since.
     *
     * The check is saved when it runs and an update does not re-run it, so
     * straight after updating Mobile Tab to 2.0.1 its card still offered
     * "2.0.0 → 2.0.1" (ClaudiusH, 2026-10-07), and Millwright offered itself the
     * version it was already running. Each entry is measured against what
     * composer.lock says now: dropped once reached, and its "from" kept true
     * when only part of the way. Dropped too when the forum's stability rules
     * or its installed core no longer allow the target.
     *
     * @return array<string,mixed>
     */
    public function current(string $lockPath): array
    {
        $cached = $this->cached();
        $lock = (array) json_decode((string) @file_get_contents($lockPath), true);
        $now = $this->interesting(
            array_merge((array) ($lock['packages'] ?? []), (array) ($lock['packages-dev'] ?? [])),
            self::directRequires(dirname($lockPath).'/composer.json')
        );

        $composerJson = dirname($lockPath).'/composer.json';
        $rule = is_file($composerJson) ? StabilityRule::fromComposerJson($composerJson) : null;
        $updates = [];

        foreach ((array) ($cached['updates'] ?? []) as $name => $update) {
            $installed = $now[$name] ?? null;

            // Saved before the rules held it, or under another core: Update could not deliver it.
            if (($rule !== null && ! $rule->allows($name, (string) ($update['to'] ?? '')))
                || ! self::coreRuns($now['flarum/core'] ?? null, is_string($update['core'] ?? null) ? $update['core'] : null)) {
                continue;
            }

            if ($installed === null || self::tracksABranch($installed)) {
                $updates[$name] = $update;
            } elseif ($this->isNewer((string) ($update['to'] ?? ''), $installed)) {
                $updates[$name] = ['from' => $installed] + $update;
            }
        }

        return ['updates' => $updates] + $cached;
    }

    public function isStale(): bool
    {
        $cached = $this->cached();
        $at = $cached['checkedAt'] ?? null;

        return $at === null || (int) ($cached['rules'] ?? 1) < self::RULES || (time() - (int) $at) > $this->freshFor;
    }

    /**
     * The newest version worth comparing against what is installed.
     *
     * A site on a dev branch is not offered a tagged release, and a site on a
     * stable tag is not offered a dev branch. Mixing them produces "update
     * available: dev-main" on a forum deliberately pinned to 2.0.0, which is
     * noise that trains people to ignore the badge.
     *
     * @param list<string> $versions
     */
    /** A branch install: `dev-main`, `1.x-dev`. */
    public static function tracksABranch(string $version): bool
    {
        return str_starts_with($version, 'dev-') || str_contains($version, '-dev');
    }

    protected function newestComparable(array $versions, string $installed): ?string
    {
        $installedIsDev = self::tracksABranch($installed);

        $candidates = array_values(array_filter($versions, function (string $v) use ($installedIsDev) {
            return self::tracksABranch($v) === $installedIsDev;
        }));

        if ($candidates === []) {
            return null;
        }

        // Under prefer-stable, Composer picks the most stable release there is.
        if ($this->stability?->prefersStable()) {
            $best = min(array_map(StabilityRule::rank(...), $candidates));
            $candidates = array_values(array_filter($candidates, fn (string $v) => StabilityRule::rank($v) === $best));
        }

        usort($candidates, fn (string $a, string $b) => version_compare(
            $this->normalise($a),
            $this->normalise($b)
        ));

        return end($candidates) ?: null;
    }

    /**
     * Whether install could pick this version, by the rules forRoot() read.
     * A version whose core requirement is unknown (a GitHub tag) is judged on
     * stability alone.
     */
    protected function installable(string $name, string $version, ?string $core): bool
    {
        if ($this->stability === null) {
            return true;
        }

        if (! $this->stability->allows($name, $version)) {
            return false;
        }

        return self::coreRuns($core, $this->coreRequires[$name][$version] ?? null);
    }

    /**
     * No constraint (a GitHub tag carries none) counts as yes, and so does a
     * core on a named branch, which has no version to compare. A numbered
     * branch such as 2.x-dev is compared, as Composer does, and a constraint
     * Composer cannot read is a no, as it is for install.
     */
    public static function coreRuns(?string $installedCore, ?string $constraint): bool
    {
        if ($constraint === null || $installedCore === null || $installedCore === '' || str_starts_with($installedCore, 'dev-')) {
            return true;
        }

        return (new Compatibility(ltrim($installedCore, 'v')))->admitsCore($constraint);
    }

    protected function isNewer(string $candidate, string $installed): bool
    {
        // Two dev branches of the same name are never "newer" than each other by
        // version string — whether a branch has moved is a question for the
        // resolve, not for this.
        if (str_starts_with($candidate, 'dev-')) {
            return false;
        }

        return version_compare($this->normalise($candidate), $this->normalise($installed)) > 0;
    }

    protected function normalise(string $version): string
    {
        return ltrim($version, 'v');
    }

    /** @return list<string>|null */
    /**
     * Public so a caller can compose it — Packagist first, then whatever
     * protected repositories the site has — without reimplementing it.
     */
    public function fromPackagist(string $name): ?array
    {
        $url = 'https://repo.packagist.org/p2/'.$name.'.json';

        $context = stream_context_create(['http' => [
            'timeout' => 15,
            'header' => "User-Agent: Millwright\r\n",
        ]]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return null;
        }

        $data = json_decode($body, true);
        $versions = $data['packages'][$name] ?? null;

        if (! is_array($versions)) {
            return null;
        }

        return $this->remember($name, $versions);
    }

    /**
     * A package's Packagist entries read and kept: its release links and each
     * version's core requirement, for the check that follows.
     *
     * @param list<array<string,mixed>> $entries
     * @return list<string> the versions
     */
    public function remember(string $name, array $entries): array
    {
        [$list, $notes, $core] = self::readP2($entries);
        $this->releaseNotes[$name] = $notes;
        $this->coreRequires[$name] = $core;

        return $list;
    }

    /**
     * The versions in a Packagist p2 response, and each one's GitHub release.
     *
     * p2 is MINIFIED: each entry repeats only what changed since the one
     * before it, and `__unset` removes a key. `source` is usually given once, on
     * the first entry, so reading each entry on its own found a repository for
     * the newest version and nothing for the rest. Expanded here as Composer's
     * MetadataMinifier does.
     *
     * The tag is the version as written (`v1.2.0` or `1.2.0`), which is the
     * name Packagist read from the repository.
     *
     * @param list<array<string,mixed>> $entries
     * @return array{0: list<string>, 1: array<string,string>, 2: array<string,string>} versions, release
     *         links, and each version's flarum/core requirement
     */
    public static function readP2(array $entries): array
    {
        $versions = [];
        $notes = [];
        $core = [];
        $current = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach ($entry as $key => $value) {
                if ($value === '__unset') {
                    unset($current[$key]);
                } else {
                    $current[$key] = $value;
                }
            }

            if (! isset($current['version'])) {
                continue;
            }

            $version = (string) $current['version'];
            $versions[] = $version;

            if (is_string($needs = $current['require']['flarum/core'] ?? null)) {
                $core[$version] = $needs === 'self.version' ? $version : $needs;
            }

            $url = self::releaseUrl((string) ($current['source']['url'] ?? ''), $version);

            if ($url !== null) {
                $notes[$version] = $url;
            }
        }

        return [$versions, $notes, $core];
    }

    /**
     * A tagged version's release page on GitHub, or null for anything else: a
     * branch has no release, and other hosts lay their pages out differently.
     */
    public static function releaseUrl(string $sourceUrl, string $version): ?string
    {
        if (self::tracksABranch($version)
            || ! preg_match('#^(?:https?://|git@)github\.com[/:]([\w.-]+)/([\w.-]+?)(?:\.git)?/?$#', $sourceUrl, $m)) {
            return null;
        }

        return 'https://github.com/'.$m[1].'/'.$m[2].'/releases/tag/'.rawurlencode($version);
    }
}
