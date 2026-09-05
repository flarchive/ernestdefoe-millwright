<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use ErnestDefoe\Millwright\Apply\Journal;
use ErnestDefoe\Millwright\Apply\Rollback;
use ErnestDefoe\Millwright\Run\RunStore;
use ErnestDefoe\Millwright\Work\ComposerRunner;
use ErnestDefoe\Millwright\Work\WorkDir;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Put everything back the way it was.
 *
 * 🚨 Works on a finished run AND on one that died halfway. That is the whole
 * claim: the journal records what was about to happen before it happened, so an
 * interrupted update is as recoverable as a completed one. There is no state
 * this refuses to unwind.
 */
class RollbackController implements RequestHandlerInterface
{
    public function __construct(
        private RunStore $runs,
        private Paths $paths,
    ) {
    }

    private function composer(): ComposerRunner
    {
        return new ComposerRunner(
            $this->paths->base,
            null,
            $this->paths->storage . '/.composer'
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $run = $this->runs->latest();

        if ($run === null) {
            return new JsonResponse(['error' => 'There is nothing to roll back.'], 404);
        }

        $workDir = new WorkDir($this->paths->storage, $run->id);
        $journal = new Journal($workDir->journalPath());

        /*
         * 🚨 An empty journal does NOT mean nothing changed.
         *
         * The plan phase runs Composer with --no-install, and Composer rewrites
         * composer.json and composer.lock the moment it succeeds — before a
         * single file has been moved, so before the journal has anything in it.
         * A run that then fails leaves the manifests describing a site that does
         * not exist, and the version of this that refused on an empty journal
         * said "that update never got as far as changing anything", which was
         * simply false. Found by a `composer remove` that updated both files and
         * then exited non-zero.
         *
         * So the question is not "is there a journal" but "is there anything
         * saved to put back".
         */
        $savedLock = $workDir->root() . '/composer.lock.before';

        if (! $journal->exists() && ! is_file($savedLock)) {
            return new JsonResponse([
                'error' => 'That update never got as far as changing anything, so there is nothing to undo.',
            ], 422);
        }

        $undone = (new Rollback(
            $this->paths->vendor,
            $workDir->trash(),
            $journal,
            $this->paths->base,
            $workDir->root()
        ))->run();

        $this->runs->save($run->rolledBack(time(), $undone));

        /*
         * 🚨 Composer's record is reconciled HERE rather than left as advice.
         *
         * Moving the files back leaves vendor/composer/installed.json still
         * claiming a package whose directory is gone. Nothing breaks
         * immediately — which is the problem, because the site looks fine while
         * Flarum lists a phantom extension pointing at nothing, and enabling it
         * is a fatal error at some later date nobody will connect to this.
         *
         * The earlier version told the admin to run `composer dump-autoload`,
         * which does not fix it: dump-autoload regenerates the autoloader FROM
         * that record, so it faithfully rebuilds the wrong thing. `install`
         * reconciles the record with the lock this rollback has just restored.
         *
         * A subprocess, so no Flarum is booted in the request that moved its
         * files. If it cannot run, the site is still correct on disk and the
         * one command that finishes the job is handed back.
         */
        $note = null;

        if ($undone !== []) {
            try {
                $result = $this->composer()->run(['install', '--no-scripts']);

                if ($result['code'] === 0) {
                    $undone[] = "Composer's record put back";
                } else {
                    $note = 'The files are back, but Composer could not update its own record. '
                        . 'Run `composer install` to finish putting things back.';
                }
            } catch (\Throwable $e) {
                $note = 'The files are back, but Composer could not be run here (' . $e->getMessage() . '). '
                    . 'Run `composer install` to finish putting things back.';
            }
        }

        return new JsonResponse([
            'undone' => $undone,
            'run'    => $this->runs->latest()?->toArray(),
            'next'   => $note,
        ]);
    }
}
