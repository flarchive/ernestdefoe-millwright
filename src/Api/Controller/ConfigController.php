<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use ErnestDefoe\Millwright\Config\AuthTokens;
use ErnestDefoe\Millwright\Config\JsonFile;
use ErnestDefoe\Millwright\Config\Repositories;
use ErnestDefoe\Millwright\Config\Stability;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * Where Composer looks, what it will accept, and how it authenticates.
 *
 * 🚨 One controller for the three settings that decide whether Composer can find
 * anything at all. Without them Millwright can update what is already installed
 * and nothing else — which is why the extension it replaces could not be removed.
 *
 * 🚨 No stored credential is ever in a response. The auth section reports which
 * hosts have one; the values are write-only by construction. See AuthTokens.
 */
class ConfigController implements RequestHandlerInterface
{
    public function __construct(private Paths $paths)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $method = $request->getMethod();
        $body   = (array) $request->getParsedBody();
        $action = (string) Arr::get($body, 'action', '');

        try {
            if ($method === 'GET') {
                return new JsonResponse($this->state());
            }

            match ($action) {
                'add-repository'    => $this->repositories()->add(
                    (string) Arr::get($body, 'type', 'vcs'),
                    (string) Arr::get($body, 'url', ''),
                    Arr::get($body, 'name')
                ),
                'remove-repository' => $this->repositories()->remove((string) Arr::get($body, 'url', '')),
                'set-stability'     => $this->stability()->set(
                    (string) Arr::get($body, 'minimumStability', 'stable'),
                    (bool) Arr::get($body, 'preferStable', true)
                ),
                'set-auth'          => $this->auth()->set(
                    (string) Arr::get($body, 'kind', ''),
                    (string) Arr::get($body, 'host', ''),
                    (string) Arr::get($body, 'secret', ''),
                    Arr::get($body, 'username')
                ),
                'remove-auth'       => $this->auth()->remove(
                    (string) Arr::get($body, 'kind', ''),
                    (string) Arr::get($body, 'host', '')
                ),
                default             => throw new RuntimeException('Unknown action.'),
            };
        } catch (RuntimeException $e) {
            /*
             * The message, not a generic failure. Every throw in the Config
             * classes is written to be read by the person who typed the value —
             * "that is not a hostname" is actionable, "invalid input" is not.
             */
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse($this->state());
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $stability = $this->stability();
        $current = $stability->current();

        return [
            'repositories' => $this->repositories()->all(),
            'stability'    => $current + [
                'levels'      => Stability::LEVELS,
                'consequence' => $stability->consequence($current['minimumStability']),
                'explains'    => array_map(
                    fn (string $l) => ['level' => $l, 'means' => $stability->consequence($l)],
                    Stability::LEVELS
                ),
            ],
            'auth'         => [
                'stored' => $this->auth()->all(),
                'kinds'  => AuthTokens::KINDS,
                'path'   => 'auth.json',
            ],
            'types'        => Repositories::TYPES,
            /*
             * 🚨 A change here does not re-resolve anything. Composer reads these
             * on the NEXT install or update, so saying so is the difference
             * between somebody understanding why nothing happened and somebody
             * concluding the screen is broken.
             */
            'note'         => 'These take effect the next time something is installed or updated.',
        ];
    }

    private function repositories(): Repositories
    {
        return new Repositories(new JsonFile($this->paths->base . '/composer.json'));
    }

    private function stability(): Stability
    {
        return new Stability(new JsonFile($this->paths->base . '/composer.json'));
    }

    private function auth(): AuthTokens
    {
        return new AuthTokens(new JsonFile($this->paths->base . '/auth.json'));
    }
}
