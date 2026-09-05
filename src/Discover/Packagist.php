<?php

namespace ErnestDefoe\Millwright\Discover;

/**
 * Finding extensions, in two cheap requests rather than one slow one.
 *
 * 🚨 The split is the design. Packagist's search tells you a package exists and
 * nothing about whether it works with your Flarum; that answer lives in each
 * package's own metadata file, one HTTP call per package. Doing all of it in the
 * request that serves a search means a dozen round trips before anything appears
 * — on a host that may cut the request at thirty seconds, for a screen somebody
 * is typing into.
 *
 * So search returns immediately with what search knows, and the verdicts are
 * asked for separately and cached. The screen fills in.
 */
class Packagist
{
    /** @param ?callable(string):?string $get overridable so tests never touch a network */
    public function __construct(
        private Cache $cache,
        private $get = null,
    ) {
        $this->get ??= fn (string $url) => $this->fetch($url);
    }

    /**
     * @return array{results:list<array<string,mixed>>, total:int, error:?string}
     */
    public function search(string $query, int $perPage = 12): array
    {
        $url = 'https://packagist.org/search.json?type=flarum-extension&per_page=' . $perPage
            . '&q=' . rawurlencode($query);

        $body = ($this->get)($url);

        if ($body === null) {
            /*
             * 🚨 Reported, never turned into an empty list. "Nothing found" and
             * "could not reach Packagist" look identical on screen and mean
             * opposite things — the first ends a search, the second means try
             * again in a minute.
             */
            return ['results' => [], 'total' => 0, 'error' => 'Packagist could not be reached.'];
        }

        $data = json_decode($body, true);

        if (! is_array($data) || ! isset($data['results'])) {
            return ['results' => [], 'total' => 0, 'error' => 'Packagist returned something unexpected.'];
        }

        $results = [];

        foreach ((array) $data['results'] as $row) {
            $name = (string) ($row['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $results[] = [
                'name'        => $name,
                'description' => (string) ($row['description'] ?? ''),
                'downloads'   => (int) ($row['downloads'] ?? 0),
                'favers'      => (int) ($row['favers'] ?? 0),
                'repository'  => (string) ($row['repository'] ?? ''),
                /*
                 * 🚨 Carried through rather than dropped. Packagist marks a
                 * package abandoned when its author says so, and installing one
                 * unknowingly is exactly the kind of thing somebody would want
                 * to have been told before rather than after.
                 */
                'abandoned'   => $row['abandoned'] ?? false,
            ];
        }

        return ['results' => $results, 'total' => (int) ($data['total'] ?? count($results)), 'error' => null];
    }

    /**
     * Compatibility verdicts for a handful of packages, from cache where possible.
     *
     * @param list<string> $names
     * @return array<string,array<string,mixed>>
     */
    public function verdicts(array $names, Compatibility $compat): array
    {
        $out = [];

        /*
         * 🚨 The core version is part of the key. A verdict is not a fact about
         * a package, it is a fact about a package AND the Flarum asking — so a
         * cache keyed on the name alone would keep answering for the version the
         * site used to run for a day after an upgrade, which is exactly when
         * somebody goes looking for extensions that now work.
         */
        $scope = 'compat:' . $compat->coreVersion() . ':';

        foreach ($names as $name) {
            $cached = $this->cache->get($scope . $name);

            if ($cached !== null) {
                $out[$name] = $cached;
                continue;
            }

            $body = ($this->get)('https://repo.packagist.org/p2/' . $name . '.json');

            if ($body === null) {
                // Not cached: a package that could not be reached today may be
                // reachable in a minute, and caching "unknown" would hide it.
                $out[$name] = ['compatible' => null, 'version' => null, 'requires' => null, 'stability' => null];
                continue;
            }

            $verdict = $compat->verdict($name, (array) json_decode($body, true));
            $this->cache->put($scope . $name, $verdict);
            $out[$name] = $verdict;
        }

        return $out;
    }

    private function fetch(string $url): ?string
    {
        $context = stream_context_create(['http' => [
            'timeout' => 15,
            'header'  => "User-Agent: Millwright (Flarum extension updater)\r\n",
        ]]);

        $body = @file_get_contents($url, false, $context);

        return $body === false ? null : $body;
    }
}
