<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Plan\Change;
use ErnestDefoe\Millwright\Work\ComposerStepsFactory;
use ErnestDefoe\Millwright\Work\Permissions;
use ErnestDefoe\Millwright\Run\Run;
use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use RuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 A root-owned package directory or cache file must stop an update BEFORE it
 * starts. Found mid-run instead, each left a real forum half-updated or
 * 500ing on 2026-10-02. chmod stands in for root ownership: the check is
 * is_writable either way.
 */
class PermissionsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write anything, so nothing is ever blocked');
        }

        $this->dir = sys_get_temp_dir() . '/mw-perm-' . bin2hex(random_bytes(4));
        foreach (['vendor/composer', 'vendor/acme/widget', 'vendor/acme/other', 'storage/cache/77/e1'] as $d) {
            mkdir($this->dir . '/' . $d, 0775, true);
        }
        touch($this->dir . '/composer.json');
        touch($this->dir . '/composer.lock');
        touch($this->dir . '/storage/cache/77/e1/entry');
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+w ' . escapeshellarg($this->dir) . '; rm -rf ' . escapeshellarg($this->dir));
    }

    private function permissions(): Permissions
    {
        return new Permissions($this->dir, $this->dir . '/vendor', $this->dir . '/storage');
    }

    public function test_a_writable_site_is_not_blocked(): void
    {
        $this->assertSame([], $this->permissions()->blocked([new Change(Change::REPLACE, 'acme/widget', '1.0.0', '2.0.0')]));
    }

    public function test_a_package_directory_that_cannot_be_moved_blocks_the_run(): void
    {
        chmod($this->dir . '/vendor/acme/widget', 0555);

        $blocked = $this->permissions()->blocked([new Change(Change::REPLACE, 'acme/widget', '1.0.0', '2.0.0')]);

        $this->assertSame([$this->dir . '/vendor/acme/widget'], $blocked);
    }

    public function test_only_packages_in_the_plan_are_checked(): void
    {
        chmod($this->dir . '/vendor/acme/other', 0555);

        $this->assertSame([], $this->permissions()->blocked([new Change(Change::REPLACE, 'acme/widget', '1.0.0', '2.0.0')]));
    }

    public function test_an_unwritable_cache_file_blocks_the_run(): void
    {
        chmod($this->dir . '/storage/cache/77/e1/entry', 0444);

        $blocked = $this->permissions()->blocked([]);

        $this->assertSame([$this->dir . '/storage/cache/77/e1/entry'], $blocked);
        $this->assertStringContainsString('chown -R', $this->permissions()->explain($blocked));
        $this->assertStringContainsString('Nothing was changed', $this->permissions()->explain($blocked));
    }

    /**
     * 🚨 The wiring, not just the rule: built the way a real run builds it,
     * planning includes the check and the check refuses and puts the
     * composer files back.
     */
    public function test_a_real_run_refuses_at_planning_and_restores_the_composer_files(): void
    {
        chmod($this->dir . '/vendor/acme/widget', 0555);
        file_put_contents($this->dir . '/composer.json', 'NEW-JSON');
        file_put_contents($this->dir . '/composer.lock', 'NEW-LOCK');

        $runDir = $this->dir . '/storage/millwright/runs/r1';
        mkdir($runDir, 0775, true);
        file_put_contents($runDir . '/composer.json.before', 'OLD-JSON');
        file_put_contents($runDir . '/composer.lock.before', 'OLD-LOCK');
        file_put_contents($runDir . '/plan.json', json_encode(['changes' => [
            ['op' => 'replace', 'package' => 'acme/widget', 'from' => '1.0.0', 'to' => '2.0.0'],
        ]]));

        $paths = new Paths([
            'base' => $this->dir, 'public' => $this->dir . '/public',
            'storage' => $this->dir . '/storage', 'vendor' => $this->dir . '/vendor',
        ]);
        $steps = (new ComposerStepsFactory($paths, new Config(['url' => 'https://example.test'])))->for('r1');
        $run = Run::start('r1', time());

        $this->assertContains('check file permissions', $steps->itemsFor('plan', $run));

        try {
            $steps->doItem('plan', 'check file permissions', $run);
            $this->fail('a run that cannot move a package was allowed to start');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($this->dir . '/vendor/acme/widget', $e->getMessage());
        }

        $this->assertSame('OLD-JSON', file_get_contents($this->dir . '/composer.json'));
        $this->assertSame('OLD-LOCK', file_get_contents($this->dir . '/composer.lock'));
    }
}
