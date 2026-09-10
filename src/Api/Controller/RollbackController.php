<?php

namespace ErnestDefoe\Millwright\Api\Controller;

use ErnestDefoe\Millwright\Apply\Journal;
use ErnestDefoe\Millwright\Apply\Restore;
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

        /*
         * 🚨 The same Restore the run uses when it undoes itself. Two copies of
         * "put it back" — one for the button, one for the automatic path — is
         * exactly the pair that drifts, and the half that drifts is Composer's
         * record, which fails silently and months later.
         */
        $restore = new Restore(
            $this->paths->vendor,
            $this->paths->base,
            $workDir->root(),
            $workDir->trash(),
            $journal,
            $this->composer()
        );

        $result = $restore->run();
        $undone = $result['undone'];
        $note = $result['note'];

        $this->runs->save($run->rolledBack(time(), $undone));

        return new JsonResponse([
            'undone' => $undone,
            'run'    => $this->runs->latest()?->toArray(),
            'next'   => $note,
        ]);
    }
}
