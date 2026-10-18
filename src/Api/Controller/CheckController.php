<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use ErnestDefoe\Millwright\Host\StorageDir;
use ErnestDefoe\Millwright\Lang;
use ErnestDefoe\Millwright\Work\UpdateCheck;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Check now, rather than waiting for tonight's scheduled run.
 *
 * 🚨 Cheap enough to be a button: one HTTP call per Flarum extension, no
 * Composer, no resolve. On this forum that is about ten calls, not the 130 it
 * would be without the filter in UpdateCheck::interesting().
 */
class CheckController implements RequestHandlerInterface
{
    public function __construct(private Paths $paths)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $check = new UpdateCheck(StorageDir::of($this->paths->storage).'/updates.json');

        // 🚨 The same check as the console command, private repositories
        // included: see UpdateCheck::refreshSite().
        $result = $check->refreshSite($this->paths->base);

        if ($result === null) {
            return new JsonResponse([
                'error' => Lang::trans('check.no_lock'),
            ], 500);
        }

        return new JsonResponse([
            'updates' => [
                'available' => $result['updates'],
                'checkedAt' => $result['checkedAt'],
                'stale' => false,
                'uncheckable' => $result['uncheckable'],
                'tracking' => $result['tracking'],
            ],
        ]);
    }
}
