<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Host\Opcache;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 The rule these protect: an update that cannot be seen has not happened.
 *
 * Everything else in this extension is about whether an update can RUN. This is
 * about whether anybody can tell that it did. On a host tuned with
 * `opcache.validate_timestamps=0`, new files land, every phase reports success,
 * and the site serves the old compiled code until PHP is restarted — silent,
 * total, and indistinguishable from the update never having run.
 *
 * The same fact is why this extension replaces directories in place rather than
 * flipping a symlink between prepared slots: `opcache.revalidate_path` is false
 * by default, so opcache resolves a symlink once and caches it. Slots would
 * trade a microsecond of a missing package for minutes of serving stale code.
 */
class OpcacheTest extends TestCase
{
    public function test_a_host_that_checks_timestamps_needs_nothing_doing(): void
    {
        $o = new class extends Opcache {
            public function situation(): array
            {
                return ['state' => Opcache::FINE, 'enabled' => true, 'validates' => true, 'freq' => 2, 'canReset' => true];
            }
        };

        $result = $o->clear();

        $this->assertTrue($result['done']);
        $this->assertStringContainsString('timestamps', $result['why']);
    }

    public function test_a_host_with_no_opcache_at_all_needs_nothing_doing(): void
    {
        $o = new class extends Opcache {
            public function situation(): array
            {
                return ['state' => Opcache::FINE, 'enabled' => false, 'validates' => true, 'freq' => 0, 'canReset' => false];
            }
        };

        $this->assertTrue($o->clear()['done']);
    }

    public function test_a_worker_says_plainly_that_it_cannot_fix_this(): void
    {
        /*
         * 🚨 The queue-worker case, and the reason it is called out rather than
         * quietly returning success: opcache_reset() in a CLI worker resets that
         * worker's own cache, which nobody is using, and leaves the web pool
         * exactly as stale as before. Reporting success for having called a
         * function is how this gets diagnosed three days later as "the update
         * didn't work".
         */
        $o = new class extends Opcache {
            public function situation(): array
            {
                return ['state' => Opcache::STALE_RISK, 'enabled' => true, 'validates' => false, 'freq' => 0, 'canReset' => true];
            }
        };

        $result = $o->clear();

        // This suite runs under the CLI SAPI, which is the situation being tested.
        $this->assertFalse($result['done']);
        $this->assertStringContainsString('restart PHP-FPM', $result['why']);
        $this->assertStringContainsString('files are updated', $result['why'], 'and it says what IS true');
    }

    public function test_the_real_situation_is_answered_without_throwing(): void
    {
        // Whatever this machine is configured to do, the shape must hold — this
        // is consulted on a page load and on every finalise.
        $s = (new Opcache())->situation();

        $this->assertContains($s['state'], [Opcache::FINE, Opcache::STALE_RISK]);
        $this->assertIsBool($s['enabled']);
        $this->assertIsBool($s['validates']);
        $this->assertIsInt($s['freq']);
    }

    public function test_clearing_never_throws(): void
    {
        // 🚨 It runs as the last step of a finished update. By then the files,
        // the lock and Composer's record all agree; a cache that could not be
        // cleared is something to say, never a reason to fail work that was
        // correct and offer to undo it.
        $result = (new Opcache())->clear();

        $this->assertIsBool($result['done']);
        $this->assertNotSame('', $result['why']);
    }
}
