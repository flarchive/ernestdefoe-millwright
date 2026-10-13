<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use ErnestDefoe\Millwright\Config\AuthTokens;
use ErnestDefoe\Millwright\Config\JsonFile;
use ErnestDefoe\Millwright\Work\ReleaseNotes;
use ErnestDefoe\Millwright\Work\UpdateCheck;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * "Learn more" on a card: the releases between what is installed and what is
 * on offer. Takes a package name only; where to look comes from the saved check.
 */
class ReleaseNotesController implements RequestHandlerInterface
{
    public function __construct(private Paths $paths)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $package = (string) ($request->getQueryParams()['package'] ?? '');
        $updates = (new UpdateCheck($this->paths->storage.'/millwright/updates.json'))
            ->current($this->paths->base.'/composer.lock')['updates'] ?? [];

        $notes = isset($updates[$package])
            ? (new ReleaseNotes(
                $this->paths->storage.'/millwright/release-notes',
                new AuthTokens(new JsonFile($this->paths->base.'/auth.json')),
            ))->between($updates[$package])
            : null;

        if ($notes === null) {
            return new JsonResponse(['error' => 'No release notes are known for that package.'], 404);
        }

        return new JsonResponse(['package' => $package, 'from' => $updates[$package]['from'], 'to' => $updates[$package]['to']] + $notes);
    }
}
