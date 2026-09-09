<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use ErnestDefoe\Millwright\Host\Capability;
use ErnestDefoe\Millwright\Run\RunStore;
use ErnestDefoe\Millwright\Work\UpdateCheck;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Everything the admin screen draws, in one read.
 *
 * 🚨 Polled while a run is going, so it stays a read — it never advances
 * anything, never repairs anything, and never has a side effect. Mixing "tell me
 * the state" with "make something happen" is how a page refresh ends up
 * starting a second update.
 */
class StateController implements RequestHandlerInterface
{
    public function __construct(
        private ExtensionManager $extensions,
        private Paths $paths,
        private RunStore $runs,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $run = $this->runs->latest();

        $check = new UpdateCheck($this->paths->storage . '/millwright/updates.json');
        $cached = $check->cached();

        return new JsonResponse([
            'host'       => (new Capability($this->paths->base))->report(),
            'installed'  => $this->installed($cached['updates'] ?? []),
            'run'        => $run?->toArray(),
            'runIsStale' => $run !== null && $run->isStale(time()),
            /*
             * 🚨 Sent with its age and its blind spots, never as a bare count.
             * "3 updates" is a claim; "3 updates, checked 2 hours ago, and 4
             * packages could not be checked at all" is the truth, and the second
             * is what lets somebody decide whether to trust it.
             */
            'updates'    => [
                'available'   => $cached['updates'] ?? [],
                'checkedAt'   => $cached['checkedAt'] ?? null,
                'stale'       => $check->isStale(),
                'uncheckable' => $cached['uncheckable'] ?? [],
            ],
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function installed(array $updates): array
    {
        $out = [];

        foreach ($this->extensions->getExtensions() as $extension) {
            $out[] = [
                'id'      => $extension->getId(),
                'name'    => $extension->getTitle(),
                'package' => $extension->name,
                'version' => $extension->getVersion(),
                'icon'    => $extension->getIcon(),
                'enabled' => $this->extensions->isEnabled($extension->getId()),
                /*
                 * 🚨 A HINT from the cheap Packagist check, not a promise. It
                 * means "a newer version exists", not "you can have it" — only
                 * the plan answers that, and the screen has to keep the two
                 * apart or the badge becomes something people learn to ignore.
                 */
                'update'  => $updates[$extension->name] ?? null,
                /*
                 * 🚨 Reported so the SCREEN can explain, rather than the apply
                 * refusing later. Composer installs a path repository as a
                 * symlink into a checkout on this machine — the way every
                 * extension developer runs their own work. Millwright will not
                 * replace one, and finding that out after planning and
                 * downloading is a worse way to learn it than not being offered
                 * the button.
                 */
                'pathInstall' => is_link($this->paths->vendor . '/' . $extension->name),
            ];
        }

        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }
}
