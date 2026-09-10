<?php

namespace ErnestDefoe\Millwright\Host;

/**
 * Does the site still answer?
 *
 * 🚨 Over HTTP, as a visitor, not by booting Flarum in this process.
 *
 * The failure this exists to catch is a site that will not BOOT — an extension
 * whose extend.php throws, a class the autoloader cannot find, a migration that
 * left the database disagreeing with the code. A check that runs inside an
 * already-booted process cannot see any of that: this process booted before the
 * change, and asking it whether booting works is asking the one witness who
 * cannot know.
 *
 * So: a real request, over the network, to the site's own address, answered by
 * a worker that booted after the change. That is the only thing that proves it.
 */
class SiteHealth
{
    /** Long enough for a cold boot on a small host, short enough not to hang a run. */
    private const TIMEOUT = 15;

    public function __construct(private string $url)
    {
    }

    /**
     * @return array{ok:bool, status:int|null, why:string}
     */
    public function check(int $attempts = 3): array
    {
        $last = ['ok' => false, 'status' => null, 'why' => 'The site was never reached.'];

        for ($i = 0; $i < max(1, $attempts); $i++) {
            /*
             * 🚨 A fresh query string every attempt. Between this extension, a
             * CDN and Flarum's own response cache there are several places a
             * 200 from before the update could be served from, and a cached
             * success is the one answer that would make this check worse than
             * not having it.
             */
            $last = $this->once($this->url . (str_contains($this->url, '?') ? '&' : '?')
                . 'millwright-health=' . bin2hex(random_bytes(6)));

            if ($last['ok']) {
                return $last;
            }

            /*
             * A short pause, because php-fpm workers do not all pick up new
             * code in the same instant and one unlucky request is not an
             * outage. Three tries over a few seconds is: they cannot all be
             * unlucky.
             */
            if ($i < $attempts - 1) {
                sleep(2);
            }
        }

        return $last;
    }

    /**
     * @return array{ok:bool, status:int|null, why:string}
     */
    private function once(string $url): array
    {
        if (! function_exists('curl_init')) {
            /*
             * 🚨 Reported as UNUSABLE, never as healthy. A check that cannot run
             * must not be allowed to look like a pass — the caller compares this
             * answer before and after the update, and "cannot check" is a
             * perfectly good answer as long as it is the same one both times.
             */
            return ['ok' => false, 'status' => null, 'why' => 'This host has no way to make an HTTP request.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => false,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            // A redirect is a working site, and following one only adds ways to fail.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'Millwright health check',
            CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache', 'Pragma: no-cache'],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        /*
         * 🚨 No curl_close(). It has done nothing since PHP 8.0 and is
         * deprecated in 8.5 — and this runs on every update, so keeping it
         * would write a deprecation notice into the very log the next failure
         * is read from.
         */

        if ($body === false || $status === 0) {
            return ['ok' => false, 'status' => null, 'why' => 'The site did not answer at all (' . ($error ?: 'no response') . ').'];
        }

        /*
         * 🚨 Only 5xx is a broken site. A 403 or a 404 is a site making a
         * decision, which needs a working boot to make — and a private forum
         * answering 403 to a signed-out request is not an outage, it is that
         * forum working exactly as configured.
         */
        if ($status >= 500) {
            return ['ok' => false, 'status' => $status, 'why' => 'The site answered ' . $status . '.'];
        }

        return ['ok' => true, 'status' => $status, 'why' => 'The site answered ' . $status . '.'];
    }
}
