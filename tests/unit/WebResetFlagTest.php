<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Host\Opcache;
use PHPUnit\Framework\TestCase;

/** A CLI update asks the web server to drop its compiled code; one web request takes it up. */
class WebResetFlagTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mw-reset-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function test_a_request_is_pending_until_a_web_request_takes_it(): void
    {
        $this->assertFalse(Opcache::webResetPending($this->dir));

        Opcache::requestWebReset($this->dir);
        $this->assertTrue(Opcache::webResetPending($this->dir), 'the storage directory is made if missing');

        Opcache::honourWebReset($this->dir);
        $this->assertFalse(Opcache::webResetPending($this->dir));
        $this->assertSame([], glob($this->dir.'/millwright/opcache-reset*'), 'nothing is left behind');
    }

    public function test_no_flag_is_a_single_stat_and_nothing_else(): void
    {
        Opcache::honourWebReset($this->dir);

        $this->assertDirectoryDoesNotExist($this->dir.'/millwright');
    }
}
