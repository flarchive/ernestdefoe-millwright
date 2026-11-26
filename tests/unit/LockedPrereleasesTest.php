<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Api\Controller\StartController;
use ErnestDefoe\Millwright\Apply\Applier;
use ErnestDefoe\Millwright\Apply\Journal;
use ErnestDefoe\Millwright\Exception\RefusedException;
use ErnestDefoe\Millwright\Host\PhpBinary;
use ErnestDefoe\Millwright\Plan\LockedPrereleases;
use ErnestDefoe\Millwright\Run\Run;
use ErnestDefoe\Millwright\Tests\unit\EnglishTranslator;
use ErnestDefoe\Millwright\Work\ComposerRunner;
use ErnestDefoe\Millwright\Work\ComposerSteps;
use ErnestDefoe\Millwright\Work\Fetcher;
use ErnestDefoe\Millwright\Work\WorkDir;
use Flarum\Foundation\Paths;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Claudius, 2026-11-24: on a forum set to Stable whose lock still holds
 * betas (fof/gamification and friends, which have no stable 2.x), Composer
 * refused every partial update.
 */
class LockedPrereleasesTest extends TestCase
{
    private string $dir;

    private string|false $composerAuth = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mw-prerel-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/project', 0775, true);
        mkdir($this->dir.'/work', 0775, true);
        mkdir($this->dir.'/home', 0775, true);

        $this->composerAuth = getenv('COMPOSER_AUTH');
        putenv('COMPOSER_AUTH');
        unset($_SERVER['COMPOSER_AUTH'], $_ENV['COMPOSER_AUTH']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));

        if ($this->composerAuth !== false) {
            putenv('COMPOSER_AUTH='.$this->composerAuth);
            $_SERVER['COMPOSER_AUTH'] = $this->composerAuth;
        }
    }

    public function test_composer_refuses_the_partial_update_while_a_locked_beta_is_not_allowed(): void
    {
        $this->project();

        try {
            $this->steps([])->doItem('plan', 'work out what changes', new Run('r1'));
            $this->fail('Composer accepted a locked beta under minimum-stability stable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('rejected by your minimum-stability', $e->getMessage());
        }
    }

    public function test_with_the_admins_yes_the_beta_is_flagged_and_the_update_goes_through(): void
    {
        $this->project();

        $note = $this->steps(['acme/beta' => 'beta'])->doItem('plan', 'work out what changes', new Run('r1'));

        $this->assertStringContainsString('acme/beta required as ^2.0@beta', $note);
        $this->assertSame('^2.0@beta', $this->json('project/composer.json')['require']['acme/beta']);
        $this->assertSame('^2.0', $this->json('work/composer.json.before')['require']['acme/beta'], 'Undo restores the unflagged requirement.');

        $locked = array_column($this->json('project/composer.lock')['packages'], 'version', 'name');
        $this->assertSame('1.1.0', $locked['acme/widget']);
        $this->assertSame('2.0.0-beta.1', $locked['acme/beta'], 'The beta stays where it was.');
    }

    public function test_it_finds_only_what_the_forum_no_longer_allows(): void
    {
        $lock = ['packages' => [
            ['name' => 'a/beta', 'version' => '2.0.0-beta.5'],
            ['name' => 'a/flagged', 'version' => '2.0.0-beta.1'],
            ['name' => 'a/stable', 'version' => '2.0.2'],
            ['name' => 'a/dep', 'version' => '1.0.0-RC1'],
        ]];
        $composer = ['require' => ['a/beta' => '^2.0', 'a/flagged' => '^2.0@beta', 'a/stable' => '^2.0']];

        $found = LockedPrereleases::find($composer, $lock);

        $this->assertSame(['a/beta' => 'beta', 'a/dep' => 'RC'], $found);
        $this->assertSame(['a/beta' => 'beta'], LockedPrereleases::flaggable($found, $composer));
        $this->assertSame([], LockedPrereleases::find(['minimum-stability' => 'beta'] + $composer, $lock));
        $this->assertSame('^2.0@beta', LockedPrereleases::withFlag('^2.0', 'beta'));
        $this->assertSame('^2.0@dev', LockedPrereleases::withFlag('^2.0@dev', 'beta'));
    }

    public function test_the_screen_is_refused_by_name_until_the_admin_agrees(): void
    {
        file_put_contents($this->dir.'/project/composer.json', json_encode(['require' => ['acme/beta' => '^2.0']]));
        file_put_contents($this->dir.'/project/composer.lock', json_encode(['packages' => [['name' => 'acme/beta', 'version' => '2.0.0-beta.1']]]));

        try {
            $this->prereleasesFor([]);
            $this->fail('Started without asking.');
        } catch (RefusedException $e) {
            $this->assertSame('locked_prereleases', $e->getType());
            $this->assertStringContainsString('acme/beta (beta)', $e->getMessage());
        }

        $this->assertSame(['acme/beta' => 'beta'], $this->prereleasesFor(['allowPrereleases' => true]));
    }

    public function test_a_beta_dependency_is_refused_with_what_to_change_instead(): void
    {
        file_put_contents($this->dir.'/project/composer.json', json_encode(['require' => ['acme/ext' => '^1.0']]));
        file_put_contents($this->dir.'/project/composer.lock', json_encode(['packages' => [['name' => 'acme/lib', 'version' => '2.0.0-beta.1']]]));

        try {
            $this->prereleasesFor(['allowPrereleases' => true]);
            $this->fail('A dependency cannot carry a flag.');
        } catch (RefusedException $e) {
            $this->assertSame('locked_prereleases_dependency', $e->getType());
            $this->assertStringContainsString('minimum stability to Beta', $e->getMessage());
        }
    }

    public function test_the_yes_is_recorded_with_the_run_and_nothing_else_is(): void
    {
        $work = (new WorkDir(EnglishTranslator::make(), $this->dir, 'r1'))->create();
        $work->remember(['acme/widget'], 'update', [], ['acme/beta' => 'beta', 'acme/odd' => 'nonsense', 'acme/stable' => 'stable']);

        $this->assertSame(['acme/beta' => 'beta'], $work->allow());
    }

    /** @param array<string,mixed> $body */
    private function prereleasesFor(array $body): array
    {
        $reflection = new ReflectionClass(StartController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('translator')->setValue($controller, EnglishTranslator::make());
        $reflection->getProperty('paths')->setValue($controller, new Paths([
            'base' => $this->dir.'/project', 'public' => $this->dir, 'storage' => $this->dir, 'vendor' => $this->dir,
        ]));

        return $reflection->getMethod('prereleasesFor')->invoke($controller, $body);
    }

    /**
     * Stable forum, acme/beta installed at its only 2.x release (a beta),
     * acme/widget 1.0.0 with 1.1.0 out: Claudius's forum in miniature.
     */
    private function project(): void
    {
        $dist = $this->dir.'/empty.zip';
        $zip = new \ZipArchive();
        $zip->open($dist, \ZipArchive::CREATE);
        $zip->addFromString('README', 'fixture');
        $zip->close();

        $package = fn (string $name, string $version) => ['name' => $name, 'version' => $version, 'dist' => ['type' => 'zip', 'url' => $dist]];

        $this->writeJson('project/composer.json', [
            'require' => ['acme/beta' => '^2.0', 'acme/widget' => '^1.0'],
            'minimum-stability' => 'stable',
            'repositories' => [
                ['type' => 'package', 'package' => [
                    $package('acme/beta', '2.0.0-beta.1'), $package('acme/beta', '1.0.0'),
                    $package('acme/widget', '1.0.0'), $package('acme/widget', '1.1.0'),
                ]],
                ['packagist.org' => false],
            ],
            'config' => ['secure-http' => false],
        ]);
        $this->writeJson('project/composer.lock', ['packages' => [
            $package('acme/beta', '2.0.0-beta.1') + ['type' => 'library'],
            $package('acme/widget', '1.0.0') + ['type' => 'library'],
        ], 'packages-dev' => []]);
    }

    /** @param array<string,string> $allow */
    private function steps(array $allow): ComposerSteps
    {
        $journal = new Journal(EnglishTranslator::make(), $this->dir.'/work/journal.jsonl');
        $php = new PhpBinary(EnglishTranslator::make(), PHP_BINARY, null, '', null, PHP_BINARY, 'cli', 'proc_open');

        return new ComposerSteps(
            EnglishTranslator::make(),
            $this->dir.'/project',
            $this->dir.'/work',
            (new ComposerRunner(EnglishTranslator::make(), $this->dir.'/project', __FILE__, $this->dir.'/home'))->withPhp($php),
            new Fetcher(EnglishTranslator::make(), new \GuzzleHttp\Client(), $this->dir.'/work/staging'),
            new Applier(EnglishTranslator::make(), $this->dir.'/project/vendor', $this->dir.'/work/staging', $this->dir.'/work/trash', $journal),
            $journal,
            ['acme/widget'],
            'update',
            allow: $allow,
        );
    }

    /** @return array<string,mixed> */
    private function json(string $relative): array
    {
        return (array) json_decode((string) file_get_contents($this->dir.'/'.$relative), true);
    }

    private function writeJson(string $relative, array $data): void
    {
        file_put_contents($this->dir.'/'.$relative, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
