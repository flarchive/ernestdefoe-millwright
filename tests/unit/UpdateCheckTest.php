<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Work\UpdateCheck;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 The rule: this produces a HINT, never a promise.
 *
 * "3.6.0 exists" and "you can have 3.6.0" are different questions, and only a
 * resolve answers the second. Conflating them is why the current tooling's
 * update badges are so often wrong — and a badge people learn to distrust is
 * worse than no badge.
 */
class UpdateCheckTest extends TestCase
{
    private string $cache;

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/mw-upd-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->cache);
    }

    private function check(): UpdateCheck
    {
        return new UpdateCheck($this->cache);
    }

    private function feed(array $map): callable
    {
        return fn (string $name) => $map[$name] ?? null;
    }

    public function test_it_finds_a_newer_stable_release(): void
    {
        $result = $this->check()->refresh(
            ['fof/pwa' => '2.0.0-beta.3'],
            $this->feed(['fof/pwa' => ['2.0.0-beta.3', '2.0.0-beta.4']])
        );

        $this->assertSame(['from' => '2.0.0-beta.3', 'to' => '2.0.0-beta.4'], $result['updates']['fof/pwa']);
    }

    public function test_an_up_to_date_package_is_not_reported(): void
    {
        $result = $this->check()->refresh(
            ['a/b' => '2.0.0'],
            $this->feed(['a/b' => ['1.0.0', '2.0.0']])
        );

        $this->assertSame([], $result['updates']);
    }

    public function test_a_v_prefix_does_not_invent_an_update(): void
    {
        // "v2.0.0" and "2.0.0" are the same release. Reporting one as newer than
        // the other is the classic way a badge becomes permanent noise.
        $result = $this->check()->refresh(
            ['a/b' => '2.0.0'],
            $this->feed(['a/b' => ['v2.0.0']])
        );

        $this->assertSame([], $result['updates']);
    }

    public function test_a_site_on_a_dev_branch_is_not_offered_a_tagged_release(): void
    {
        /*
         * 🚨 And vice versa. Mixing them produces "update available: dev-main"
         * on a forum deliberately pinned to a stable tag, which trains people to
         * ignore the badge entirely.
         */
        $result = $this->check()->refresh(
            ['a/b' => 'dev-main'],
            $this->feed(['a/b' => ['dev-main', '1.0.0', '2.0.0']])
        );

        $this->assertSame([], $result['updates']);
    }

    public function test_a_site_on_a_stable_tag_is_not_offered_a_dev_branch(): void
    {
        $result = $this->check()->refresh(
            ['a/b' => '1.0.0'],
            $this->feed(['a/b' => ['dev-main', '1.0.0']])
        );

        $this->assertSame([], $result['updates']);
    }

    public function test_a_package_it_cannot_check_is_named_rather_than_assumed_current(): void
    {
        // 🚨 Leaving a private package out would imply it is up to date, which is
        // a guess presented as a fact.
        $result = $this->check()->refresh(
            ['public/one' => '1.0.0', 'private/two' => '1.0.0'],
            $this->feed(['public/one' => ['1.0.0']])
        );

        $this->assertSame(['private/two'], $result['uncheckable']);
    }

    public function test_the_answer_is_cached_and_ages(): void
    {
        $check = new UpdateCheck($this->cache, freshFor: 3600);

        $this->assertTrue($check->isStale(), 'never checked is stale');

        $check->refresh(['a/b' => '1.0.0'], $this->feed(['a/b' => ['2.0.0']]));

        $this->assertFalse($check->isStale());
        $this->assertSame(['from' => '1.0.0', 'to' => '2.0.0'], $check->cached()['updates']['a/b']);
    }

    public function test_only_extensions_and_flarum_itself_are_worth_checking(): void
    {
        /*
         * 🚨 Run against a real forum this returned 59 "updates", nearly all
         * transitive — illuminate/collections 13.30.0 → 13.30.1, guzzle 7 → 8.
         * Nobody updates those individually; they arrive with the extension that
         * needs them, and several are pinned by flarum/core so the newer version
         * cannot be installed at all. A badge showing 59 when 4 things matter is
         * a badge people stop reading.
         */
        $interesting = $this->check()->interesting([
            ['name' => 'ernestdefoe/page-builder', 'version' => '3.5.0', 'type' => 'flarum-extension'],
            ['name' => 'flarum/core', 'version' => '2.0.0-rc.8', 'type' => 'library'],
            ['name' => 'illuminate/collections', 'version' => '13.30.0', 'type' => 'library'],
            ['name' => 'guzzlehttp/guzzle', 'version' => '7.15.5', 'type' => 'library'],
        ]);

        $this->assertSame(
            ['ernestdefoe/page-builder', 'flarum/core'],
            array_keys($interesting)
        );
    }

    public function test_a_never_checked_cache_reads_as_empty_rather_than_erroring(): void
    {
        $cached = $this->check()->cached();

        $this->assertNull($cached['checkedAt']);
        $this->assertSame([], $cached['updates']);
    }
}
