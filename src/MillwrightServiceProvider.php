<?php

namespace ErnestDefoe\Millwright;

use ErnestDefoe\Millwright\Run\Drivers;
use ErnestDefoe\Millwright\Run\RunStore;
use ErnestDefoe\Millwright\Run\StepRunner;
use ErnestDefoe\Millwright\Run\StepsFactory;
use ErnestDefoe\Millwright\Work\ComposerStepsFactory;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Foundation\Paths;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;

class MillwrightServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(RunStore::class, function ($container) {
            /*
             * Under storage/, which is already writable, already excluded from
             * the web root, and already backed up with the rest of the site. A
             * run's state is not secret but it is not public either.
             */
            return new RunStore($container->make(Paths::class)->storage . '/millwright/runs');
        });

        $this->container->singleton(Drivers::class, function ($container) {
            return new Drivers(
                $container->make(QueueFactory::class),
                $container->make(Dispatcher::class)
            );
        });

        /*
         * 🚨 Bound as a FACTORY, never as the work for "the current run".
         *
         * The obvious binding — ask the store for the latest run and build the
         * work around it — is correct under PHP-FPM and wrong in a queue worker,
         * which is a long-lived process where a singleton outlives the job that
         * created it. That worker would carry the finished run's staging
         * directory and journal into the next run, and a journal that disagrees
         * with what happened is a rollback that restores the wrong files.
         *
         * Handing the id in at step() time removes the question entirely.
         */
        $this->container->singleton(StepsFactory::class, function ($container) {
            return new ComposerStepsFactory($container->make(Paths::class));
        });

        /*
         * 🚨 The lock directory is what stops two drivers doing the same item.
         * On a forum with a queue, the admin page polling and a worker WILL run
         * at once — without this they both read the same index and a package is
         * applied twice.
         */
        $this->container->singleton(StepRunner::class, function ($container) {
            $storage = $container->make(Paths::class)->storage;

            return new StepRunner(
                $container->make(RunStore::class),
                $container->make(StepsFactory::class),
                fn () => time(),
                $storage . '/millwright/locks'
            );
        });
    }
}
