<?php

// Adds Millwright's old paths to the exported extend.php. See export.sh.

$file = $argv[1];
$code = file_get_contents($file);
$anchor = "->post('/extension-manager/discover/compat', 'extension-manager.compat', Controller\\CompatController::class)";

if (substr_count($code, $anchor) !== 1) {
    fwrite(STDERR, "patch-extend.php: the compat route moved; update the anchor\n");
    exit(1);
}

$aliases = <<<'PHP'

        /*
         * 🚨 Millwright's paths, for the update that replaces Millwright with
         * this package. The admin tab that started it is still running
         * Millwright's script, and once the files swap that script's next
         * poll and step land here. Without them the run stalls at finalise.
         * Remove in Flarum 3.0.
         */
        ->get('/millwright/state', 'extension-manager.legacy.state', Controller\StateController::class)
        ->post('/millwright/step', 'extension-manager.legacy.step', Controller\StepController::class)
        ->post('/millwright/rollback', 'extension-manager.legacy.rollback', Controller\RollbackController::class)
PHP;

file_put_contents($file, str_replace($anchor, $anchor.$aliases, $code));
