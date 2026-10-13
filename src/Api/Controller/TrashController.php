<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use ErnestDefoe\Millwright\Prune\Pruner;
use ErnestDefoe\Millwright\Prune\Retention;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * The rollback copies: how much room they take, what a prune would free, and
 * the button that does it.
 *
 * 🚨 GET is a dry run and nothing else. It is what the screen's confirm quotes,
 * so the number somebody agrees to is the number the same rule just computed —
 * and the POST recomputes it rather than trusting a list the browser sends
 * back. The browser asks WHETHER to prune, never WHAT.
 *
 * Not part of /millwright/state on purpose: that is polled every couple of
 * seconds during an update, and sizing the trash walks every file in it.
 */
class TrashController implements RequestHandlerInterface
{
    public function __construct(private Paths $paths)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $dir = $this->paths->storage.'/millwright';
        $retention = new Retention($dir);
        $pruner = new Pruner($dir, $retention);
        $pruned = null;

        try {
            if ($request->getMethod() === 'POST') {
                $body = (array) $request->getParsedBody();

                match ((string) Arr::get($body, 'action', '')) {
                    'prune' => $pruned = $pruner->prune('admin'),
                    'settings' => $retention->save(Arr::get($body, 'keepDays'), Arr::get($body, 'keepRuns')),
                    default => throw new \InvalidArgumentException('Unknown action.'),
                };
            }

            $plan = $pruner->plan(true);
        } catch (Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        $keptTrash = array_values(array_filter($plan['keep'], fn ($r) => $r['kind'] === 'trash'));

        return new JsonResponse([
            'settings' => $plan['settings'],
            'trashBytes' => $plan['trashBytes'],
            'trashCount' => count($keptTrash) + count(array_filter($plan['remove'], fn ($r) => $r['kind'] === 'trash')),
            'keptCount' => count($keptTrash),
            'removeCount' => count($plan['remove']),
            'removeBytes' => $plan['removeBytes'],
            'remove' => array_map(fn ($r) => [
                'kind' => $r['kind'], 'name' => $r['name'], 'bytes' => $r['bytes'], 'why' => $r['why'],
            ], $plan['remove']),
            'lastPrune' => $retention->lastPrune(),
            'pruned' => $pruned,
        ]);
    }
}
