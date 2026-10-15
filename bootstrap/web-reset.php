<?php

/*
 * 🚨 Honours Opcache::requestWebReset() before ANY extension boots.
 *
 * Composer runs this while vendor/autoload.php loads. The service provider was
 * too late: Flarum registers every extension's providers before booting any,
 * so a stale compiled extend.php naming a class its new files deleted killed
 * each request before the reset that would fix it could run (fbsfb.com,
 * 2026-10-15). One stat per web request; storage at Flarum's default place
 * beside vendor/, and the service provider covers a site that moved it.
 */
if (PHP_SAPI !== 'cli') {
    // The newest loader is this site's: keyed by its vendor dir, symlinks intact.
    define('MILLWRIGHT_EARLY_STORAGE', dirname((string) array_key_first(Composer\Autoload\ClassLoader::getRegisteredLoaders())).'/storage');

    if (is_file(MILLWRIGHT_EARLY_STORAGE.'/millwright/opcache-reset')) {
        ErnestDefoe\Millwright\Host\Opcache::honourWebReset(MILLWRIGHT_EARLY_STORAGE);
    }
}
