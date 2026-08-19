<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\ListServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the "nullable location_id silently excluded from
 * list results" bug (found 2026-08-17 via the retail-ERP demo fixture, live
 * PHPUnit failure after a module regeneration).
 *
 * `LocationContextService::applyLocationFiltering()`'s `whereIn(location_id,
 * $accessibleIds)` never matches a NULL location_id, per SQL semantics --
 * silently dropping any "applies everywhere" row from location-scoped list
 * queries. The trait already had an opt-out (`$locationScopeIncludesNull =
 * true`, added 2026-08-08), but no generator ever emitted it, so every
 * module with a genuinely nullable `location_id` column inherited this bug
 * with no way to fix it short of a hand-edit that a future regen would wipe.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\ListServiceGenerator::generateLocationScopeIncludesNull()
 */
class ListServiceGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-list-service-test-' . uniqid();
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

    private function generateAndRead(array $config, string $moduleName = 'ItemPrices', string $moduleGroup = 'Custom'): string
    {
        $generator = new ListServiceGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate(), 'ListServiceGenerator::generate() should report a successful write.');

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/{$moduleGroup}/{$moduleName}/Services/{$moduleName}ListService.php";
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    private function baseConfig(array $columns): array
    {
        return [
            'columns' => $columns,
            'features' => [
                'backend' => [
                    'list' => [
                        'fields' => [],
                    ],
                ],
            ],
        ];
    }

    public function test_nullable_location_id_column_declares_the_opt_out(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            ['name' => 'location_id', 'type' => 'foreignId', 'nullable' => true, 'relatedModule' => 'Locations'],
        ]));

        $this->assertStringContainsString('protected static bool $locationScopeIncludesNull = true;', $content);
    }

    public function test_non_nullable_location_id_column_does_not_declare_the_opt_out(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            ['name' => 'location_id', 'type' => 'foreignId', 'nullable' => false, 'relatedModule' => 'Locations'],
        ]));

        $this->assertStringNotContainsString('locationScopeIncludesNull', $content);
    }

    public function test_no_location_id_column_at_all_does_not_declare_the_opt_out(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            ['name' => 'name', 'type' => 'string', 'nullable' => false],
        ]));

        $this->assertStringNotContainsString('locationScopeIncludesNull', $content);
    }

    public function test_php_lints_clean_with_the_opt_out_declared(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            ['name' => 'location_id', 'type' => 'foreignId', 'nullable' => true, 'relatedModule' => 'Locations'],
        ]));

        $tmpFile = tempnam(sys_get_temp_dir(), 'gen_lint_') . '.php';
        file_put_contents($tmpFile, $content);
        exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
        unlink($tmpFile);

        $this->assertSame(0, $exitCode, 'Generated file has a PHP syntax error: ' . implode("\n", $output));
    }
}
