<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Models;

use Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the `$hidden` declaration ModelGenerator emits for secret-like
 * columns.
 *
 * Why it exists: NJIWA's Webhooks Model has no `$hidden` at all, so its
 * signing `secret` is serialized into every list row and is even offered as
 * a sortable column. `$hidden` is the one Eloquent mechanism that closes
 * every serialization path at once — list, view, export, create, edit — so
 * this is emitted from the same `ModuleConfigContract::sensitiveColumns()`
 * rule the list/filter/sort generators and introspection all consult.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::sensitiveColumns()
 */
class ModelGeneratorHiddenTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-model-hidden-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::setModuleRegistry([]);
        PathManager::resetModuleSubGroup();
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

    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'connection' => '',
            'id_type'    => 'integer',
            'columns'    => [
                ['name' => 'title', 'type' => 'string'],
            ],
        ], $overrides);
    }

    private function generateAndRead(array $config, string $moduleName = 'TestSales', string $moduleGroup = 'System'): string
    {
        $generator = new ModelGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate(), 'ModelGenerator::generate() should report a successful write.');

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/{$moduleGroup}/{$moduleName}/{$moduleName}Model.php";
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function test_a_sensitive_column_is_hidden(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'webhook_secret', 'type' => 'string'],
            ],
        ]));

        $this->assertStringContainsString('protected $hidden = [', $content);
        $this->assertStringContainsString("        'webhook_secret',", $content);

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/TestSales/TestSalesModel.php';
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    public function test_no_sensitive_columns_emits_no_hidden_block(): void
    {
        $content = $this->generateAndRead($this->baseConfig());

        $this->assertStringNotContainsString('$hidden', $content);
    }

    public function test_exclude_override_keeps_a_heuristic_match_out_of_hidden(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'webhook_secret', 'type' => 'string'],
            ],
            'sensitive_columns' => ['exclude' => ['webhook_secret']],
        ]));

        $this->assertStringNotContainsString('$hidden', $content);
    }
}
