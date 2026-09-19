<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\DeleteServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Plan 039's existence-leak fix, DeleteServiceGenerator's side: by the time
 * `!$model` is reached, the uuid's own `exists:` rule already confirmed a row
 * with that uuid is somewhere in the table -- so for a location-bearing
 * model, "not found" here can only mean "exists, scoped out", and must 422
 * (matching "doesn't exist") rather than leak that distinction via 404.
 *
 * Not tests/Unit/Generators/Backend/Services/DeleteCheckServiceGeneratorTest.php
 * -- that file tests DeleteCheckServiceGenerator/deleteCheck/service.stub, a
 * different generator this plan does not touch (deleteCheck has no `exists:`
 * rule and needs no abort-code change).
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\DeleteServiceGenerator
 */
class DeleteServiceGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-delete-service-test-' . uniqid();
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
        $generator = new DeleteServiceGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate(), 'DeleteServiceGenerator::generate() should report a successful write.');

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/{$moduleGroup}/{$moduleName}/Services/{$moduleName}DeleteService.php";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_not_found_aborts_422_for_a_location_bearing_model_and_404_otherwise(): void
    {
        $content = $this->generateAndRead([
            'features' => ['backend' => ['delete' => ['enabled' => true]]],
        ]);

        $this->assertStringContainsString(
            "method_exists(WidgetsModel::class, 'isLocationBearing') && WidgetsModel::isLocationBearing() ? 422 : 404",
            $content
        );
        $this->assertStringContainsString("abort(\$notFoundCode, 'Record not found')", $content);
    }

    public function test_php_lints_clean(): void
    {
        $content = $this->generateAndRead([
            'features' => ['backend' => ['delete' => ['enabled' => true]]],
        ]);

        $tmpFile = $this->tmpRoot . '/lint_check.php';
        file_put_contents($tmpFile, $content);

        exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, 'Generated file must be syntactically valid PHP: ' . implode("\n", $output));
    }
}
