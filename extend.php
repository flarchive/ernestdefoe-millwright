<?php

use ErnestDefoe\Millwright\Api\Controller;
use ErnestDefoe\Millwright\Console\CheckCommand;
use ErnestDefoe\Millwright\Console\RepairFormatterCommand;
use ErnestDefoe\Millwright\MillwrightServiceProvider;
use Flarum\Extend;

return [
    (new Extend\ServiceProvider())
        ->register(MillwrightServiceProvider::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    (new Extend\Console())
        ->command(CheckCommand::class)
        /*
         * 🚨 Registered as a command so it runs in its OWN process, after the
         * files have moved. Repairing the formatter means building it, and
         * building it inside the request that just replaced Flarum's files
         * loads a half-old class map.
         */
        ->command(RepairFormatterCommand::class)
        /*
         * 🚨 Daily, and cheap enough to mean it. This is one HTTP call per
         * installed package with no Composer involved — a resolve on a schedule
         * would be 165 MB on somebody else's shared host, every night, for a
         * question they may not have asked.
         */
        ->schedule(CheckCommand::class, fn ($event) => $event->daily()),

    new Extend\Locales(__DIR__ . '/resources/locale'),

    /*
     * 🚨 Two endpoints, and the split matters.
     *
     * `state` is a read: the admin screen polls it, and it must stay cheap
     * enough to poll every second or two without being noticed.
     *
     * `step` does exactly one unit of work and returns. That is the whole
     * design — progress is a function of how many times this is called, so a
     * host that cuts every request at thirty seconds can still finish an update
     * that takes ten minutes. Nothing here ever loops.
     */
    (new Extend\Routes('api'))
        ->get('/millwright/state', 'millwright.state', Controller\StateController::class)
        ->post('/millwright/check', 'millwright.check', Controller\CheckController::class)
        ->post('/millwright/update', 'millwright.update', Controller\StartController::class)
        ->post('/millwright/step', 'millwright.step', Controller\StepController::class)
        ->post('/millwright/rollback', 'millwright.rollback', Controller\RollbackController::class)
        /*
         * 🚨 Discovery is two endpoints, and the split is deliberate. `discover`
         * is one call to Packagist's search. `compat` is one call PER PACKAGE to
         * work out whether each result fits the Flarum actually installed —
         * doing both in one request would mean a dozen round trips before
         * anything appeared on a screen somebody is typing into.
         */
        ->get('/millwright/core', 'millwright.core', Controller\CoreController::class)
        ->get('/millwright/discover', 'millwright.discover', Controller\DiscoverController::class)
        ->post('/millwright/discover/compat', 'millwright.compat', Controller\CompatController::class),
];
