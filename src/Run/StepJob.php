<?php

namespace ErnestDefoe\Millwright\Run;

use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Turns the handle from a queue worker, where a forum has one.
 *
 * 🚨 An OPTION, never the path. Extension Manager's central failure is that the
 * queue is the only way work happens, so when a job dies the update dies with
 * it, the task row is left saying `running`, and every later update is silently
 * refused. Here the queue is one of three interchangeable drivers — the admin
 * page polling and a cron tick are the others — and the run is complete state on
 * disk that any of them can pick up. Kill this job and an admin refreshing the
 * page finishes the update.
 *
 * 🚨 It re-dispatches itself rather than looping to the end. A worker timeout is
 * as real a limit as a web request's, and the whole design is built on never
 * needing a long one. Each job does a few seconds of work and hands on.
 */
class StepJob extends AbstractJob
{
    /**
     * Comfortably inside any sane worker timeout — Horizon's default is 60
     * seconds and Laravel's is 60 too. Nothing here needs to be near it.
     */
    private const BUDGET_SECONDS = 20;

    /**
     * 🚨 One try, on purpose.
     *
     * Retrying is what turns one dead job into "has been attempted too many
     * times" and a wedged queue. There is nothing to retry anyway: the run's
     * state is on disk, so the correct recovery is for any driver to call step()
     * again — which the admin page does every couple of seconds regardless.
     */
    public $tries = 1;

    public function __construct(private string $runId)
    {
        parent::__construct();
    }

    public function handle(StepRunner $runner, RunStore $store, Dispatcher $bus): void
    {
        $until = time() + self::BUDGET_SECONDS;

        do {
            $run = $runner->step($this->runId);

            if ($run->isFinished()) {
                return;
            }

            /*
             * Another driver has it — the admin page is probably polling. Stop
             * rather than spin: it is making progress, and two drivers taking
             * turns on one lock is wasted work on a host that is already busy.
             */
            if ($runner->wasBusy()) {
                return;
            }
        } while (time() < $until);

        // Out of budget with work left. Hand on to a fresh job rather than
        // running past a worker timeout and being killed mid-item.
        $bus->dispatch(new self($this->runId));
    }

    /**
     * 🚨 A failed job must not leave the run looking alive.
     *
     * This is the exact hole in the tooling being replaced: the job dies, the
     * task row still says `running`, and the dispatcher then refuses every later
     * update because something looks busy. Recording the failure means the admin
     * screen can show it and offer a rollback.
     */
    public function failed(\Throwable $e): void
    {
        $store = resolve(RunStore::class);
        $run = $store->load($this->runId);

        if ($run !== null && ! $run->isFinished()) {
            $store->save($run->failed(
                'The background worker running this update stopped: ' . $e->getMessage(),
                'queue worker',
                time()
            ));
        }
    }
}
