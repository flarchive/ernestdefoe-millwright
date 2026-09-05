<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Run\Run;
use ErnestDefoe\Millwright\Run\RunStore;
use ErnestDefoe\Millwright\Run\StepRunner;
use ErnestDefoe\Millwright\Run\Steps;
use PHPUnit\Framework\TestCase;

/**
 * The driver's promise: an update completes however many times it is
 * interrupted, and it never lies about what it is doing.
 */
class StepRunnerTest extends TestCase
{
    private string $dir;
    private int $clock = 1000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mw-run-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_an_update_completes_one_step_at_a_time(): void
    {
        [$runner, $steps] = $this->make(['plan' => ['resolve'], 'fetch' => ['a', 'b'], 'apply' => ['a', 'b'], 'finalise' => ['autoload']]);
        $runner->begin('r1');

        $guard = 0;
        do {
            $run = $runner->step('r1');
        } while (! $run->isFinished() && ++$guard < 100);

        $this->assertSame(Run::DONE, $run->state);
        $this->assertSame(
            ['plan:resolve', 'fetch:a', 'fetch:b', 'apply:a', 'apply:b', 'finalise:autoload'],
            $steps->done,
            'every item ran exactly once, in order'
        );
    }

    public function test_no_single_step_does_more_than_one_item(): void
    {
        // 🚨 The whole reason this exists. If a step ever did two things, a host
        // with a 30 second ceiling would eventually be cut off mid-item again.
        [$runner, $steps] = $this->make(['plan' => [], 'fetch' => ['a', 'b', 'c'], 'apply' => [], 'finalise' => []]);
        $runner->begin('r1');

        $counts = [];
        for ($i = 0; $i < 12; $i++) {
            $before = count($steps->done);
            $run = $runner->step('r1');
            $counts[] = count($steps->done) - $before;

            if ($run->isFinished()) {
                break;
            }
        }

        $this->assertLessThanOrEqual(1, max($counts), 'a step did more than one item of work');
    }

    public function test_a_run_resumes_from_a_completely_fresh_process_state(): void
    {
        // Standing in for the tab being closed and reopened, or a cron tick
        // arriving in a new process: nothing is held in memory between calls.
        [$_, $steps] = $this->make(['plan' => [], 'fetch' => ['a', 'b', 'c'], 'apply' => [], 'finalise' => []]);
        $this->newRunner($steps)->begin('r1');

        $guard = 0;
        do {
            // A brand new runner and store every single time.
            $run = $this->newRunner($steps)->step('r1');
        } while (! $run->isFinished() && ++$guard < 100);

        $this->assertSame(Run::DONE, $run->state);
        $this->assertSame(['fetch:a', 'fetch:b', 'fetch:c'], $steps->done);
    }

    public function test_a_failure_records_which_step_failed(): void
    {
        // 🚨 The anti-example is "has been attempted too many times": true,
        // useless, and three layers from the cause.
        [$runner, $steps] = $this->make(['plan' => [], 'fetch' => ['a', 'boom', 'c'], 'apply' => [], 'finalise' => []]);
        $steps->explodeOn = 'boom';
        $runner->begin('r1');

        $guard = 0;
        do {
            $run = $runner->step('r1');
        } while (! $run->isFinished() && ++$guard < 50);

        $this->assertSame(Run::FAILED, $run->state);
        $this->assertSame('fetch → boom', $run->errorStep);
        $this->assertStringContainsString('could not fetch boom', (string) $run->error);
    }

    public function test_stepping_a_finished_run_returns_it_unchanged_rather_than_doing_nothing_quietly(): void
    {
        [$runner, $steps] = $this->make(['plan' => [], 'fetch' => ['a'], 'apply' => [], 'finalise' => []]);
        $runner->begin('r1');

        $guard = 0;
        do {
            $run = $runner->step('r1');
        } while (! $run->isFinished() && ++$guard < 50);

        $before = count($steps->done);
        $again = $runner->step('r1');

        $this->assertSame(Run::DONE, $again->state);
        $this->assertCount($before, $steps->done, 'a finished run must not be re-run');
    }

    public function test_progress_is_reportable_at_every_point(): void
    {
        // What the admin screen binds to. A bar that cannot say where it is, is
        // the spinner problem wearing a different hat.
        [$runner, $steps] = $this->make(['plan' => [], 'fetch' => ['a', 'b', 'c', 'd'], 'apply' => [], 'finalise' => []]);
        $runner->begin('r1');

        $seen = [];
        $guard = 0;
        do {
            $run = $runner->step('r1');

            if ($run->phase === 'fetch' && $run->total() > 0 && ! $run->isFinished()) {
                $seen[] = round($run->fraction(), 2);
                $this->assertNotNull($run->current() ?? ($run->index >= $run->total() ? 'end' : null));
            }
        } while (! $run->isFinished() && ++$guard < 50);

        $this->assertNotEmpty($seen);
        $this->assertSame($seen, array_values(array_unique($seen)), 'progress went backwards or stalled');
    }

    public function test_a_stale_run_is_reported_not_silently_skipped(): void
    {
        // 🚨 Extension Manager refuses to queue while a task looks busy and says
        // nothing, so one dead task blocks everything forever. Here staleness is
        // a fact the caller can act on, never a reason to do nothing.
        [$runner, $steps] = $this->make(['plan' => [], 'fetch' => ['a', 'b'], 'apply' => [], 'finalise' => []]);
        $runner->begin('r1');
        $runner->step('r1');
        $run = $runner->step('r1');

        $this->assertFalse($run->isStale($this->clock));

        $this->clock += 600;

        $this->assertTrue($run->isStale($this->clock), 'a run nobody has advanced for ten minutes is stale');

        // And it can still be advanced — staleness never blocks.
        $next = $runner->step('r1');
        $this->assertGreaterThan($run->index, $next->index);
    }

    public function test_the_state_file_is_never_seen_half_written(): void
    {
        [$runner, $steps] = $this->make(['plan' => [], 'fetch' => ['a', 'b', 'c'], 'apply' => [], 'finalise' => []]);
        $runner->begin('r1');

        $store = new RunStore($this->dir);

        for ($i = 0; $i < 8; $i++) {
            $runner->step('r1');
            // A reader arriving between writes must always get a whole run.
            $this->assertInstanceOf(Run::class, $store->load('r1'));
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{0: StepRunner, 1: object} */
    private function make(array $plan): array
    {
        $steps = $this->fakeSteps($plan);

        return [$this->newRunner($steps), $steps];
    }

    private function newRunner(object $steps): StepRunner
    {
        return new StepRunner(new RunStore($this->dir), $steps, fn () => $this->clock);
    }

    private function fakeSteps(array $plan): object
    {
        return new class($plan) implements Steps {
            public array $done = [];
            public ?string $explodeOn = null;

            public function __construct(private array $plan)
            {
            }

            public function itemsFor(string $phase, Run $run): array
            {
                return $this->plan[$phase] ?? [];
            }

            public function doItem(string $phase, string $item, Run $run): ?string
            {
                if ($item === $this->explodeOn) {
                    throw new \RuntimeException("could not fetch $item");
                }

                $this->done[] = "$phase:$item";

                return "$phase $item";
            }
        };
    }
}
