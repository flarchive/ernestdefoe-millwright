<?php

namespace ErnestDefoe\Millwright\Config;

use RuntimeException;

/**
 * Credentials Composer uses to reach private and paid repositories.
 *
 * 🚨 WRITE-ONLY, and that is the whole design. A stored token is never returned
 * to the browser by any method on this class — `all()` reports which hosts have
 * a credential and nothing else. A settings screen that displays your GitHub
 * token back to you is worse than no screen: it turns every admin session, every
 * screenshot and every support ticket with a screenshot in it into a credential
 * leak, and the person it leaks from has no reason to think anything happened.
 *
 * Setting a value replaces it. Removing deletes it. Reading it back is not
 * offered, because nothing in this extension needs to read it — Composer reads
 * auth.json for itself.
 */
class AuthTokens
{
    /**
     * The kinds Composer understands, and what each expects.
     *
     * 🚨 `http-basic` takes a username AND password; the rest take a single
     * token. Storing a bare string where Composer expects an object makes
     * authentication fail with a message about the repository rather than about
     * the credential, which is a long way to walk for a typo.
     */
    public const KINDS = ['github-oauth', 'gitlab-token', 'bearer', 'http-basic'];

    public function __construct(private JsonFile $file)
    {
    }

    /**
     * Which hosts have a credential — never the credentials.
     *
     * @return list<array{kind:string,host:string,detail:string}>
     */
    public function all(): array
    {
        $out = [];

        foreach ($this->file->read() as $kind => $entries) {
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $host => $value) {
                $out[] = [
                    'kind' => (string) $kind,
                    'host' => (string) $host,
                    /*
                     * For http-basic the USERNAME is not the secret and is worth
                     * showing — it is how somebody tells two accounts on one host
                     * apart. The password is never included.
                     */
                    'detail' => is_array($value) && isset($value['username'])
                        ? 'username ' . (string) $value['username']
                        : 'token set',
                ];
            }
        }

        usort($out, fn ($a, $b) => [$a['kind'], $a['host']] <=> [$b['kind'], $b['host']]);

        return $out;
    }

    public function set(string $kind, string $host, string $secret, ?string $username = null): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('Unknown credential type. Use one of: ' . implode(', ', self::KINDS) . '.');
        }

        $host = strtolower(trim($host));

        // A host, not a URL: Composer matches on the hostname, and pasting a full
        // URL here silently never matches anything.
        if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) {
            throw new RuntimeException('That is not a hostname. Use github.com, not https://github.com/….');
        }

        if (trim($secret) === '') {
            throw new RuntimeException('The credential is empty.');
        }

        if ($kind === 'http-basic' && ($username === null || trim($username) === '')) {
            throw new RuntimeException('http-basic needs a username as well as a password.');
        }

        $data = $this->file->read();
        $data[$kind] ??= [];

        $data[$kind][$host] = $kind === 'http-basic'
            ? ['username' => trim((string) $username), 'password' => $secret]
            : $secret;

        // 🚨 0600. auth.json holds credentials in plain text — that is Composer's
        // format, not a choice available here — so the file being unreadable by
        // anyone but the web user is the only protection it has.
        $this->file->write($data, 0600);
    }

    public function remove(string $kind, string $host): void
    {
        $data = $this->file->read();
        $host = strtolower(trim($host));

        if (! isset($data[$kind][$host])) {
            throw new RuntimeException('There is no credential stored for that host.');
        }

        unset($data[$kind][$host]);

        if ($data[$kind] === []) {
            unset($data[$kind]);
        }

        $this->file->write($data, 0600);
    }
}
