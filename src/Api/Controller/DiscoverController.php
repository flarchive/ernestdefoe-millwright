<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use ErnestDefoe\Millwright\Discover\Cache;
use ErnestDefoe\Millwright\Discover\Compatibility;
use ErnestDefoe\Millwright\Discover\Packagist;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\Application;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Search Packagist for extensions.
 *
 * 🚨 One HTTP call, and no compatibility work. Whether each result fits this
 * Flarum is a separate question costing a request per package, and doing it here
 * would make somebody wait a dozen round trips before seeing anything — on a
 * screen they are typing into, on a host that may cut the request at thirty
 * seconds. The verdicts come from /discover/compat and the cards fill in.
 */
class DiscoverController implements RequestHandlerInterface
{
    public function __construct(
        private Paths $paths,
        private ExtensionManager $extensions,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $params = $request->getQueryParams();
        $query  = trim((string) ($params['q'] ?? ''));
        $page   = max(1, min(20, (int) ($params['page'] ?? 1)));

        /*
         * 🚨 No query means BROWSE, not "return nothing". The first version
         * refused an empty query and left the tab blank until somebody guessed a
         * search term — a catalogue behaving like a command line. Packagist
         * answers this by popularity, which is the right default ordering for
         * somebody who does not yet know what they are looking for.
         *
         * The page number is clamped: it reaches an outbound URL, and twenty
         * pages is more than anybody browses before searching instead.
         */
        $found = (new Packagist($this->cache()))->search($query, 12, $page);

        /*
         * 🚨 Marked rather than filtered out. Somebody searching for an
         * extension they already have should be told they have it — hiding it
         * looks like the search is broken, and they try again with different
         * words.
         */
        $installed = [];

        foreach ($this->extensions->getExtensions() as $extension) {
            $installed[$extension->name] = true;
        }

        foreach ($found['results'] as $i => $row) {
            $found['results'][$i]['installed'] = isset($installed[$row['name']]);
        }

        return new JsonResponse($found);
    }

    private function cache(): Cache
    {
        return new Cache($this->paths->storage . '/millwright/packagist');
    }
}
