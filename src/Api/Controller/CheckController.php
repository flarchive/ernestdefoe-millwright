<?php

namespace ErnestDefoe\Millwright\Api\Controller;

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

        $check = new UpdateCheck($this->paths->storage . '/millwright/updates.json');

        $lock = (array) json_decode((string) @file_get_contents($this->paths->base . '/composer.lock'), true);

        $packages = array_merge(
            array_values((array) ($lock['packages'] ?? [])),
            array_values((array) ($lock['packages-dev'] ?? []))
        );

        if ($packages === []) {
            return new JsonResponse([
                'error' => 'Millwright could not read composer.lock, so it cannot tell what is installed.',
            ], 500);
        }

        $result = $check->refresh($check->interesting($packages));

        return new JsonResponse([
            'updates' => [
                'available'   => $result['updates'],
                'checkedAt'   => $result['checkedAt'],
                'stale'       => false,
                'uncheckable' => $result['uncheckable'],
            ],
        ]);
    }
}
