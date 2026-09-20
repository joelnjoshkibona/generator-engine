<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * A module with `features.frontend.enabled: false` has a hand-written frontend, and the generator writes the same
 * file names. FrontendPipeline refuses to run for it, but make:action and make:delegation build one generator
 * themselves and never pass through the pipeline: on a scratch module both created and overwrote pages and specs
 * of an opted-out module, with and without --force (make:action's and make:delegation's e2e spec is always
 * regenerated). So the rule lives where every write ends up -- BaseGenerator's helpers -- and holds for any
 * command, present or future.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\BaseGenerator::isBlockedFrontendPath()
 */
class BaseGeneratorFrontendOptOutTest extends TestCase
{
    private string $tmpRoot;

    /** @var string[] */
    private array $issues = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-frontend-optout-' . uniqid();
        mkdir($this->tmpRoot . '/FRONTEND/src/pages/modules/core/Users', 0755, true);
        mkdir($this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Users', 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
        $this->issues = [];
        PathManager::setIssueHandler(function (string $message, string $level): void {
            $this->issues[] = $message;
        });
    }

    protected function tearDown(): void
    {
        PathManager::setIssueHandler(null);
        PathManager::resetProjectRoot();
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    private function generator(array $config, string $module = 'Users'): object
    {
        return new class($module, 'Core', $config) extends BaseGenerator {
            public function generate(): bool
            {
                return true;
            }

            public function write(string $path, string $content): bool { return $this->writeFile($path, $content); }
            public function writeOnce(string $path, string $content): bool { return $this->writeFileOnce($path, $content); }
            public function writeAlways(string $path, string $content): bool { return $this->writeFileAlways($path, $content); }
            public function put(string $path, string $content): bool { return $this->putFile($path, $content); }
            public function remove(string $path): bool { return $this->removeFile($path); }
            public function blocked(string $path): bool { return $this->isBlockedFrontendPath($path); }
        };
    }

    private function optedOut(): array
    {
        return ['features' => ['frontend' => ['enabled' => false]]];
    }

    private function frontend(string $relative): string
    {
        return $this->tmpRoot . '/FRONTEND/src/pages/modules/core/Users/' . $relative;
    }

    private function handWritten(string $relative): string
    {
        $path = $this->frontend($relative);
        file_put_contents($path, "HAND-WRITTEN\n");

        return $path;
    }

    public function test_every_write_helper_leaves_an_opted_out_modules_hand_written_file_alone(): void
    {
        $g = $this->generator($this->optedOut());
        $g->setForce(true); // --force is the case that used to overwrite

        foreach (['write', 'writeOnce', 'writeAlways', 'put'] as $method) {
            $path = $this->handWritten("Users-{$method}.vue");
            $this->assertFalse($g->$method($path, "GENERATED\n"), "{$method}() must report it wrote nothing");
            $this->assertSame("HAND-WRITTEN\n", file_get_contents($path), "{$method}() replaced a hand-written file");
        }
    }

    public function test_an_opted_out_module_gets_no_new_frontend_file_and_no_new_folder(): void
    {
        $g = $this->generator($this->optedOut());
        $g->setForce(true);

        foreach (['write', 'writeOnce', 'writeAlways', 'put'] as $method) {
            $path = $this->tmpRoot . "/FRONTEND/src/pages/modules/core/Users/new-{$method}/Brand.vue";
            $this->assertFalse($g->$method($path, "GENERATED\n"));
            $this->assertFileDoesNotExist($path);
            $this->assertDirectoryDoesNotExist(dirname($path), "{$method}() created a folder inside the frontend");
        }
    }

    public function test_removing_a_frontend_file_is_refused_for_an_opted_out_module(): void
    {
        $g = $this->generator($this->optedOut());
        $path = $this->handWritten('users-crud.e2e.js');

        $this->assertFalse($g->remove($path));
        $this->assertFileExists($path);
    }

    public function test_the_backend_of_an_opted_out_module_is_still_generated(): void
    {
        $g = $this->generator($this->optedOut());
        $g->setForce(true);
        $service = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Users/Services/UsersListService.php';

        $this->assertTrue($g->writeAlways($service, "<?php\n"));
        $this->assertFileExists($service);

        $this->assertTrue($g->remove($service));
        $this->assertFileDoesNotExist($service);
    }

    public function test_a_module_that_has_not_opted_out_still_writes_its_frontend(): void
    {
        foreach ([
            'flag true' => ['features' => ['frontend' => ['enabled' => true]]],
            'flag unset' => ['features' => ['frontend' => []]],
            'no features block' => [],
        ] as $label => $config) {
            $g = $this->generator($config);
            $g->setForce(true);
            $path = $this->frontend("ok-{$label}.vue");

            $this->assertTrue($g->write($path, "GENERATED\n"), $label);
            $this->assertSame("GENERATED\n", file_get_contents($path), $label);
            $this->assertTrue($g->remove($path), $label);
        }
    }

    public function test_the_frontend_root_is_matched_on_a_path_boundary_and_through_dot_segments(): void
    {
        $g = $this->generator($this->optedOut());
        $root = $this->tmpRoot;

        $this->assertTrue($g->blocked("$root/FRONTEND/src/x.vue"));
        $this->assertTrue($g->blocked("$root/FRONTEND/src/../src/./x.vue"), 'dot segments must not slip past the check');
        $this->assertTrue($g->blocked("$root//FRONTEND//src/x.vue"));

        // A sibling that merely starts with the same letters is not the frontend.
        $this->assertFalse($g->blocked("$root/FRONTEND_BACKUP/x.vue"));
        $this->assertFalse($g->blocked("$root/FRONTENDX/x.vue"));
        $this->assertFalse($g->blocked("$root/BACKEND/FRONTEND/x.php"));
        $this->assertFalse($g->blocked("$root/BACKEND/../FRONTEND-notes.md"));
    }

    public function test_without_a_project_root_there_is_no_frontend_to_protect(): void
    {
        $g = $this->generator($this->optedOut());
        PathManager::resetProjectRoot();

        $this->assertFalse($g->blocked('/anywhere/FRONTEND/src/x.vue'));
    }

    public function test_the_skip_is_reported_once_per_module_not_once_per_file(): void
    {
        $g = $this->generator($this->optedOut(), 'ReportedOnce');

        for ($i = 0; $i < 5; $i++) {
            $g->write($this->frontend("f{$i}.vue"), 'x');
        }

        $this->assertCount(1, $this->issues, 'a module writes dozens of files; the notice must not repeat');
        $this->assertStringContainsString('features.frontend.enabled is false', $this->issues[0]);
        $this->assertStringContainsString('ReportedOnce', $this->issues[0]);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
