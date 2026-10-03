<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Console\UpdateCommand;
use ErnestDefoe\Millwright\Run\Run;
use ErnestDefoe\Millwright\Run\RunStore;
use ErnestDefoe\Millwright\Run\StepRunner;
use ErnestDefoe\Millwright\Run\Steps;
use ErnestDefoe\Millwright\Work\WorkDir;
use Flarum\Foundation\Paths;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `millwright:update` — the admin screen's update, driven from a terminal.
 *
 * 🚨 Most of what is tested here is what the command REFUSES to do, because
 * every refusal replaces a way of quietly doing nothing:
 *
 *   - a pinned requirement, where Composer answers "nothing to modify" and
 *     exits 0
 *   - a package that is not installed, where `composer update` succeeds and
 *     changes nothing
 *   - a run already in progress, which two drivers would then race
 *
 * Each of those has actually happened. The ones that did it silently are the
 * reason this command exists at all.
 */
class UpdateCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mw-update-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/millwright', 0775, true);

        // Capability refuses to plan under 160 MB, which a bare CLI can be.
        ini_set('memory_limit', '512M');
    }

    protected function tearDown(): void
    {
        $this->delete($this->dir);
    }

    // ── what it refuses ──────────────────────────────────────────────────────

    /**
     * 🚨 `composer update` on a package that is not installed does nothing at
     * all and reports success, so a typo would otherwise print a clean run and
     * leave the site exactly as it was.
     */
    public function test_it_refuses_a_package_the_site_does_not_have(): void
    {
        $this->site(require: ['a/thing' => '1.0.0'], installed: ['a/thing' => '1.0.0']);

        $tester = $this->runCommand(['packages' => ['b/absent']]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('b/absent is not installed', $tester->getDisplay());
        $this->assertNull($this->store()->latest(), 'Nothing may be started.');
    }

    public function test_it_refuses_a_name_that_is_not_a_package(): void
    {
        $this->site(require: [], installed: []);

        $tester = $this->runCommand(['packages' => ['; rm -rf /']]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not a package name', $tester->getDisplay());
    }

    /**
     * 🚨 And it says how to take it over, because a run left behind by a closed
     * browser tab is exactly the state that used to need someone who knew what
     * a work directory was.
     */
    public function test_it_refuses_when_a_run_is_in_progress_and_says_how_to_drive_it(): void
    {
        $this->site(require: ['a/thing' => '1.0.0'], installed: ['a/thing' => '1.0.0']);
        $this->store()->save(Run::start('r-already-going', time()));

        $tester = $this->runCommand(['packages' => ['a/thing']]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('r-already-going', $tester->getDisplay());
        $this->assertStringContainsString('--resume', $tester->getDisplay());
    }

    /**
     * 🚨 The failure this command exists to end.
     *
     * Against `"a/thing": "1.0.0"`, `composer update a/thing` answers "Nothing
     * to modify in lock file" and exits 0. Starting a run anyway would go green
     * having moved nothing, which is worse than refusing.
     */
    public function test_a_pinned_package_is_refused_by_name_rather_than_silently_doing_nothing(): void
    {
        $this->site(
            require: ['a/thing' => '1.0.0'],
            installed: ['a/thing' => '1.0.0'],
            updates: ['a/thing' => ['from' => '1.0.0', 'to' => '1.1.0']]
        );

        $tester = $this->runCommand(['packages' => ['a/thing']]);
        $display = $tester->getDisplay();

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('pinned at 1.0.0', $display);
        $this->assertStringContainsString('raised to 1.1.0', $display);
        $this->assertStringContainsString('--repin', $display);
        $this->assertNull($this->store()->latest(), 'Nothing may be started.');
    }

    // ── what it does ─────────────────────────────────────────────────────────

    /**
     * 🚨 The permission is written down with the run, not kept in memory: a run
     * outlives the process that started it, and the version raised to must be
     * the one the check found, not whatever a later check says.
     */
    public function test_repin_raises_it_and_records_the_permission_with_the_run(): void
    {
        $this->site(
            require: ['a/thing' => '1.0.0'],
            installed: ['a/thing' => '1.0.0'],
            updates: ['a/thing' => ['from' => '1.0.0', 'to' => '1.1.0']]
        );

        $tester = $this->runCommand(['packages' => ['a/thing'], '--repin' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Raising a/thing from 1.0.0 to 1.1.0', $tester->getDisplay());

        $id = $this->store()->latest()?->id ?? '';
        $this->assertSame(['a/thing' => '1.1.0'], (new WorkDir($this->dir, $id))->repin());
        $this->assertSame(['a/thing'], (new WorkDir($this->dir, $id))->requested());
    }

    public function test_it_drives_the_run_to_done_and_prints_what_happened(): void
    {
        $this->site(require: ['a/thing' => '^1.0'], installed: ['a/thing' => '1.0.0']);

        $tester = $this->runCommand(['packages' => ['a/thing']], [
            'plan' => ['work out the change'],
            'fetch' => ['download a/thing'],
            'apply' => ['move a/thing'],
            'finalise' => ['caches'],
        ]);

        $display = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(Run::DONE, $this->store()->latest()?->state);
        $this->assertStringContainsString('apply move a/thing', $display, 'The log is the point of running it here.');
        $this->assertStringContainsString('Done.', $display);
    }

    /**
     * 🚨 A line is printed once. A waiting step replaces the last line of the
     * log rather than appending, so anything counting would repeat the tail of
     * the run on every poll.
     */
    public function test_it_prints_each_line_once(): void
    {
        $this->site(require: ['a/thing' => '^1.0'], installed: ['a/thing' => '1.0.0']);

        $display = $this->runCommand(['packages' => ['a/thing']], ['apply' => ['one', 'two']])->getDisplay();

        $this->assertSame(1, substr_count($display, 'apply one'));
        $this->assertSame(1, substr_count($display, 'apply two'));
    }

    public function test_resume_takes_over_a_run_it_did_not_start(): void
    {
        $this->site(require: [], installed: []);
        $this->store()->save(Run::start('r-somebody-elses', time()));

        $tester = $this->runCommand(['--resume' => true], ['apply' => ['finish what was started']]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Resuming r-somebody-elses', $tester->getDisplay());
        $this->assertSame(Run::DONE, $this->store()->load('r-somebody-elses')?->state);
    }

    public function test_resume_with_nothing_to_resume_says_so(): void
    {
        $this->site(require: [], installed: []);

        $tester = $this->runCommand(['--resume' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('no run to resume', $tester->getDisplay());
    }

    /** A failure is the exit code, not a line somebody has to read. */
    public function test_a_failed_run_exits_non_zero_and_names_the_step(): void
    {
        $this->site(require: ['a/thing' => '^1.0'], installed: ['a/thing' => '1.0.0']);

        $tester = $this->runCommand(
            ['packages' => ['a/thing']],
            ['apply' => ['move a/thing']],
            failOn: 'apply:move a/thing'
        );

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Failed at apply → move a/thing', $tester->getDisplay());
        $this->assertStringContainsString('the disk filled up', $tester->getDisplay());
    }

    /**
     * Nothing to do is not a failure. This is the shape somebody puts in a
     * nightly script, and it must not page them for being up to date.
     */
    public function test_all_with_nothing_newer_exits_zero(): void
    {
        $this->site(require: ['a/thing' => '^1.0'], installed: ['a/thing' => '1.0.0']);

        $tester = $this->runCommand(['--all' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('already up to date', $tester->getDisplay());
    }

    public function test_naming_packages_and_asking_for_all_is_refused(): void
    {
        $this->site(require: ['a/thing' => '^1.0'], installed: ['a/thing' => '1.0.0']);

        $tester = $this->runCommand(['packages' => ['a/thing'], '--all' => true]);

        $this->assertSame(1, $tester->getStatusCode());
    }

    /**
     * 🚨 It drives the run itself and never hands it to a worker.
     *
     * Handing it over would make the exit code a lie: the command would finish
     * successfully while the update it started went on to fail somewhere else,
     * which is precisely what makes this useless in a script.
     */
    public function test_it_never_hands_the_run_to_a_queue_worker(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Console/UpdateCommand.php');

        $this->assertStringNotContainsString('nudge(', $source);
        $this->assertStringNotContainsString('Drivers', $source);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,string> $require    what composer.json pins or ranges
     * @param array<string,string> $installed  what composer.lock says is there
     * @param array<string,array{from:string,to:string}> $updates what the check found
     */
    private function site(array $require, array $installed, array $updates = []): void
    {
        file_put_contents($this->dir . '/composer.json', json_encode(['require' => $require]));

        file_put_contents($this->dir . '/composer.lock', json_encode([
            'packages' => array_map(
                fn (string $name, string $version): array => ['name' => $name, 'version' => $version],
                array_keys($installed),
                array_values($installed)
            ),
        ]));

        file_put_contents($this->dir . '/millwright/updates.json', json_encode([
            'checkedAt' => time(), 'updates' => $updates, 'uncheckable' => [], 'tracking' => [],
        ]));
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,list<string>> $plan what each phase consists of
     */
    private function runCommand(array $input, array $plan = [], ?string $failOn = null): CommandTester
    {
        $store = $this->store();

        $command = new UpdateCommand(
            new Paths(['base' => $this->dir, 'public' => $this->dir, 'storage' => $this->dir, 'vendor' => $this->dir]),
            $store,
            new StepRunner($store, $this->steps($plan, $failOn), fn (): int => time())
        );

        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    private function store(): RunStore
    {
        return new RunStore($this->dir . '/millwright/runs');
    }

    /** @param array<string,list<string>> $plan */
    private function steps(array $plan, ?string $failOn): Steps
    {
        return new class($plan, $failOn) implements Steps {
            /** @param array<string,list<string>> $plan */
            public function __construct(private array $plan, private ?string $failOn)
            {
            }

            public function itemsFor(string $phase, Run $run): array
            {
                return $this->plan[$phase] ?? [];
            }

            public function doItem(string $phase, string $item, Run $run): ?string
            {
                if ($this->failOn === "$phase:$item") {
                    throw new \RuntimeException('the disk filled up');
                }

                return "$phase $item";
            }
        };
    }

    private function delete(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->delete($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }
}
