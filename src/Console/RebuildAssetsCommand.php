<?php

namespace ErnestDefoe\Millwright\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Frontend\AssetManager;
use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Rebuild every frontend's compiled JavaScript, CSS and translations from the
 * code now installed.
 *
 * 🚨 cache:clear does not do this before Flarum 2.0.0. After 1.17.0 updated
 * itself to 1.18.0, ernestdefoe.online served the 7 October admin bundle and
 * fbsfb a translation bundle built before the update. Every step reported
 * success and the new screen never arrived: its strings were missing and its
 * buttons were not in the JavaScript at all.
 *
 * Its own process, like the formatter repair, so it compiles the NEW code.
 * Each compiler only rewrites a file whose output changed.
 */
class RebuildAssetsCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this
            ->setName('millwright:rebuild-assets')
            ->setDescription('Rebuild the compiled JavaScript, CSS and translations for every frontend.')
            /*
             * For an undo run in-process: this request booted the newer
             * version, so building now would bake it in. Flarum rebuilds a set
             * flagged dirty on the next request, which runs the restored code.
             */
            ->addOption('mark-dirty', null, InputOption::VALUE_NONE, 'Only flag the assets for rebuilding on the next request.');
    }

    protected function fire(): int
    {
        $settings = resolve(SettingsRepositoryInterface::class);
        $locales = array_keys(resolve(LocaleManager::class)->getLocales());

        foreach (resolve(AssetManager::class)->all() as $assets) {
            $dirty = 'assets_dirty.'.$assets->getName();

            if ($this->input->getOption('mark-dirty')) {
                $settings->set($dirty, 1);
                continue;
            }

            $assets->makeJs()->commit();
            $assets->makeCss()->commit();

            foreach ($locales as $locale) {
                $assets->makeLocaleJs($locale)->commit();
                $assets->makeLocaleCss($locale)->commit();
            }

            $assets->makeJsDirectory()->commit();

            $settings->delete($dirty);
        }

        $this->info($this->input->getOption('mark-dirty') ? 'Assets flagged; the next request rebuilds them.' : 'Assets rebuilt.');

        return 0;
    }
}
