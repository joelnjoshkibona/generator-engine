<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\ViewServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a real, previously-untested defect found 2026-07-30
 * while live-verifying a throwaway module in SYSTEM_SHELL: the generated
 * ViewService called `{Model}::withTrashed()->where(...)` UNCONDITIONALLY,
 * regardless of whether the module actually has soft deletes. Every real
 * Core module in SYSTEM_SHELL happens to have `has_soft_deletes: true`, so
 * this was never exercised — a module without it threw a
 * BadMethodCallException the moment ViewService ran, since Eloquent's
 * __callStatic() has no `withTrashed()` to forward to without the
 * SoftDeletes trait.
 *
 * Fix: `[[withTrashedCall]]` is now resolved from
 * ModuleConfigContract::hasSoftDeletes() — the single sanctioned place to
 * read this fact — to either `'withTrashed()->'` or `''`.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\ViewServiceGenerator
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::hasSoftDeletes()
 */
class ViewServiceGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-view-service-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        $this->removeDirectory($this->tmpRoot);

        parent::tearDown();
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

    private function generateAndRead(array $config, string $moduleName = 'Widgets', string $moduleGroup = 'Core'): string
    {
        $generator = new ViewServiceGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate(), 'ViewServiceGenerator::generate() should report a successful write.');

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/{$moduleGroup}/{$moduleName}/Services/{$moduleName}ViewService.php";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_calls_with_trashed_when_module_has_soft_deletes(): void
    {
        $content = $this->generateAndRead([
            'has_soft_deletes' => true,
            'features' => ['backend' => ['view' => ['enabled' => true]]],
        ]);

        $this->assertStringContainsString('WidgetsModel::withTrashed()->where(', $content);
    }

    public function test_omits_with_trashed_when_module_has_no_soft_deletes(): void
    {
        $content = $this->generateAndRead([
            'has_soft_deletes' => false,
            'features' => ['backend' => ['view' => ['enabled' => true]]],
        ]);

        $this->assertStringContainsString('WidgetsModel::where(', $content);
        $this->assertStringNotContainsString('withTrashed', $content);
    }

    public function test_omits_with_trashed_when_has_soft_deletes_key_is_entirely_absent(): void
    {
        // ModuleConfigContract::hasSoftDeletes() defaults to false when the
        // key is missing — matches every other generator's own default.
        $content = $this->generateAndRead([
            'features' => ['backend' => ['view' => ['enabled' => true]]],
        ]);

        $this->assertStringContainsString('WidgetsModel::where(', $content);
        $this->assertStringNotContainsString('withTrashed', $content);
    }
}
