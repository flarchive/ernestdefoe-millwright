<?php

namespace ErnestDefoe\Millwright\Config;

use RuntimeException;

/**
 * Where Composer is allowed to look for packages.
 *
 * 🚨 This is the setting that makes a forum's own extensions installable at all.
 * On the site this was built for, eleven of the twelve entries are private
 * GitHub repositories and the twelfth is a paid marketplace — remove them and
 * nothing that forum runs can be resolved. It is not an advanced nicety, it is
 * the reason `composer require` finds anything.
 */
class Repositories
{
    /** The kinds worth offering. Anything else is a footgun in a web form. */
    public const TYPES = ['vcs', 'composer', 'path'];

    public function __construct(private JsonFile $file)
    {
    }

    /**
     * @return list<array{name:string,type:string,url:string,canonical:bool}>
     */
    public function all(): array
    {
        $out = [];

        foreach ((array) ($this->file->read()['repositories'] ?? []) as $key => $repo) {
            if (! is_array($repo)) {
                continue;
            }

            $out[] = [
                // Composer accepts repositories as a list or as a keyed map, and
                // the key is the name when it is a map.
                'name'      => (string) ($repo['name'] ?? (is_string($key) ? $key : ($repo['url'] ?? ''))),
                'type'      => (string) ($repo['type'] ?? '?'),
                'url'       => (string) ($repo['url'] ?? ''),
                'canonical' => (bool) ($repo['canonical'] ?? true),
            ];
        }

        return $out;
    }

    /**
     * @throws RuntimeException when the entry would not be usable
     */
    public function add(string $type, string $url, ?string $name = null): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new RuntimeException("Millwright does not add '$type' repositories. Choose vcs, composer or path.");
        }

        $url = trim($url);

        if ($url === '') {
            throw new RuntimeException('A repository needs a URL.');
        }

        /*
         * 🚨 A `path` repository points at a directory, and everything else must
         * be a URL Composer can actually fetch. Checking them the same way would
         * either reject valid local paths or accept nonsense as a URL — and a
         * bad entry here breaks every later resolve, not just this one.
         */
        if ($type === 'path') {
            if (! str_starts_with($url, '/') && ! str_starts_with($url, './') && ! str_starts_with($url, '../')) {
                throw new RuntimeException('A path repository needs a filesystem path, not a URL.');
            }
        } elseif (! preg_match('#^(https?://|git@|ssh://|git://)#i', $url)) {
            throw new RuntimeException('That does not look like a repository URL. Use https://, git@ or ssh://.');
        }

        $data = $this->file->read();
        $repos = (array) ($data['repositories'] ?? []);

        foreach ($repos as $existing) {
            if (is_array($existing) && rtrim((string) ($existing['url'] ?? ''), '/') === rtrim($url, '/')) {
                throw new RuntimeException('That repository is already configured.');
            }
        }

        $entry = ['type' => $type, 'url' => $url];

        if ($name !== null && trim($name) !== '') {
            $entry['name'] = trim($name);
        }

        // Appended, keeping the existing shape: Composer resolves repositories in
        // order, so a new entry going last cannot change how anything already
        // installed is found.
        $repos[] = $entry;
        $data['repositories'] = array_values($repos);

        $this->file->write($data);
    }

    /**
     * Remove by URL, which is the only field guaranteed to be there.
     */
    public function remove(string $url): void
    {
        $data = $this->file->read();
        $repos = (array) ($data['repositories'] ?? []);
        $keep = [];
        $found = false;

        foreach ($repos as $repo) {
            if (is_array($repo) && rtrim((string) ($repo['url'] ?? ''), '/') === rtrim(trim($url), '/')) {
                $found = true;
                continue;
            }

            $keep[] = $repo;
        }

        if (! $found) {
            throw new RuntimeException('That repository is not in composer.json.');
        }

        $data['repositories'] = array_values($keep);
        $this->file->write($data);
    }
}
