<?php

namespace ErnestDefoe\Millwright\Work;

/**
 * Whether anything newer exists, asked cheaply.
 *
 * 🚨 This is deliberately NOT the same question as "can I have it".
 *
 * Extension Manager conflates them, and that is why its answers are so often
 * useless: a newer version existing tells you nothing about whether it will
 * install alongside everything else you have. Only a resolve knows that, and a
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
    public function __construct(
        private string $cachePath,
        private int $freshFor = 21600,        // six hours
    ) {
    }

    /**
     * 🚨 Only things the site actually chose.
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
     * @param list<array{name:string,version:string,type?:string}> $packages
     * @return array<string,string>
     */
    public function interesting(array $packages): array
    {
        $out = [];

        foreach ($packages as $package) {
            $name = (string) ($package['name'] ?? '');
            $type = (string) ($package['type'] ?? '');

            if ($name === '') {
                continue;
            }

            if ($type === 'flarum-extension' || $name === 'flarum/core') {
                $out[$name] = (string) ($package['version'] ?? '');
            }
        }

        return $out;
    }

    /**
     * @param array<string,string> $installed package => installed version
     * @param callable(string):?array $fetch  overridable so this is testable
     *                                        without a network
     * @return array<string,mixed>
     */
    public function refresh(array $installed, ?callable $fetch = null): array
    {
        $fetch ??= fn (string $name) => $this->fromPackagist($name);

        $found = [];
        $unknown = [];

        foreach ($installed as $name => $version) {
            $versions = $fetch($name);

            if ($versions === null) {
                /*
                 * 🚨 Named, not silently dropped. A private or path-installed
                 * package is not on Packagist, and "we could not check this one"
                 * is a true and useful thing to say — whereas leaving it out
                 * implies it is up to date, which is a guess presented as a fact.
                 */
                $unknown[] = $name;
                continue;
            }

            $newest = $this->newestComparable($versions, $version);

            if ($newest !== null && $this->isNewer($newest, $version)) {
                $found[$name] = ['from' => $version, 'to' => $newest];
            }
        }

        $result = [
            'checkedAt' => time(),
            'updates'   => $found,
            'uncheckable' => $unknown,
        ];

        @file_put_contents($this->cachePath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $result;
    }

    /** @return array<string,mixed> */
    public function cached(): array
    {
        if (! is_file($this->cachePath)) {
            return ['checkedAt' => null, 'updates' => [], 'uncheckable' => []];
        }

        return (array) json_decode((string) file_get_contents($this->cachePath), true);
    }

    public function isStale(): bool
    {
        $at = $this->cached()['checkedAt'] ?? null;

        return $at === null || (time() - (int) $at) > $this->freshFor;
    }

    /**
     * The newest version worth comparing against what is installed.
     *
     * 🚨 A site on a dev branch is not offered a tagged release, and a site on a
     * stable tag is not offered a dev branch. Mixing them produces "update
     * available: dev-main" on a forum deliberately pinned to 2.0.0, which is
     * noise that trains people to ignore the badge.
     *
     * @param list<string> $versions
     */
    private function newestComparable(array $versions, string $installed): ?string
    {
        $installedIsDev = str_starts_with($installed, 'dev-') || str_contains($installed, '-dev');

        $candidates = array_values(array_filter($versions, function (string $v) use ($installedIsDev) {
            $isDev = str_starts_with($v, 'dev-') || str_contains($v, '-dev');

            return $isDev === $installedIsDev;
        }));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (string $a, string $b) => version_compare(
            $this->normalise($a),
            $this->normalise($b)
        ));

        return end($candidates) ?: null;
    }

    private function isNewer(string $candidate, string $installed): bool
    {
        // Two dev branches of the same name are never "newer" than each other by
        // version string — whether a branch has moved is a question for the
        // resolve, not for this.
        if (str_starts_with($candidate, 'dev-')) {
            return false;
        }

        return version_compare($this->normalise($candidate), $this->normalise($installed)) > 0;
    }

    private function normalise(string $version): string
    {
        return ltrim($version, 'v');
    }

    /** @return list<string>|null */
    private function fromPackagist(string $name): ?array
    {
        $url = 'https://repo.packagist.org/p2/' . $name . '.json';

        $context = stream_context_create(['http' => [
            'timeout' => 15,
            'header'  => "User-Agent: Millwright\r\n",
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

        return array_values(array_filter(array_map(
            fn ($v) => isset($v['version']) ? (string) $v['version'] : null,
            $versions
        )));
    }
}
