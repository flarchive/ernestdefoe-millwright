<?php

namespace ErnestDefoe\Millwright\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Formatter\Formatter;

/**
 * Put Flarum's post formatter back into a state that can render.
 *
 * 🚨 This exists because `flarum cache:clear` reliably breaks post rendering on
 * any forum whose cache driver is not the file store — which is every forum
 * running fof/redis.
 *
 * The formatter is two halves that must agree: a SERIALIZED renderer object
 * cached under `flarum.formatter`, and the generated
 * `storage/formatter/Renderer_<hash>.php` that the object is an instance of.
 * `cache:clear` unlinks that file, and flushes the APPLICATION cache — but core
 * gives the formatter its OWN FileStore, so the entry it needs to remove is in a
 * different store and survives. The entry then names a class whose file is gone,
 * every unserialize yields `__PHP_Incomplete_Class`, and every post render 500s.
 * `rememberForever` means nothing invalidates it on its own.
 *
 * It is not a race. It happens every single time.
 *
 * 🚨 The symptom hides: a discussion LIST renders no post bodies, so `/` and
 * `/all` stay 200 while every discussion, private message and queued mail dies.
 * A site can sit like that for hours looking fine.
 *
 * `Formatter::flush()` forgets the entry through the formatter's own cache — the
 * store that actually holds it — and `warm()` then rebuilds both halves
 * together. Running it after cache:clear closes the hole that command opens.
 */
class RepairFormatterCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this
            ->setName('millwright:repair-formatter')
            ->setDescription('Rebuild the post formatter so its cached renderer and generated class agree.');
    }

    protected function fire(): int
    {
        $formatter = resolve(Formatter::class);

        $formatter->flush();
        $formatter->warm();

        $this->info('Formatter rebuilt.');

        return 0;
    }
}
