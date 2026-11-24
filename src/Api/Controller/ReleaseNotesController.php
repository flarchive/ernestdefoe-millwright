<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use ErnestDefoe\Millwright\Config\AuthTokens;
use ErnestDefoe\Millwright\Config\JsonFile;
use ErnestDefoe\Millwright\Host\StorageDir;
use ErnestDefoe\Millwright\Work\ReleaseNotes;
use ErnestDefoe\Millwright\Work\UpdateCheck;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use ErnestDefoe\Millwright\Exception\RefusedException;
use Flarum\Locale\TranslatorInterface;
use GuzzleHttp\Client;
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
    public function __construct(
        protected TranslatorInterface $translator,
        protected Paths $paths,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $package = (string) ($request->getQueryParams()['package'] ?? '');
        $updates = (new UpdateCheck(StorageDir::of($this->paths->storage).'/updates.json'))
            ->current($this->paths->base.'/composer.lock')['updates'] ?? [];

        $notes = isset($updates[$package])
            ? (new ReleaseNotes(
                StorageDir::of($this->paths->storage).'/release-notes',
                new AuthTokens($this->translator, new JsonFile($this->translator, $this->paths->base.'/auth.json')),
                new Client(),
            ))->between($updates[$package])
            : null;

        if ($notes === null) {
            throw new RefusedException($this->translator->trans('ernestdefoe-millwright.api.release_notes.none'), 'no_release_notes', 404);
        }

        return new JsonResponse(['package' => $package, 'from' => $updates[$package]['from'], 'to' => $updates[$package]['to']] + $notes);
    }
}
