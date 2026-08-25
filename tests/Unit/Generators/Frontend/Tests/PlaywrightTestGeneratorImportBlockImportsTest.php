<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Tests;

use Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a defect found live 2026-08-25, immediately downstream of the artifacts
 * seam (see PlaywrightTestGenerator's `#e2e-helpers/artifacts.js` import in crud.e2e.stub /
 * split.e2e.stub): removing the stub's own `import fs from 'node:fs'` / `import path from
 * 'node:path'` also removed them for buildImportBlock(), an entirely separate piece of the same
 * generated file that downloads the module's real import template and re-saves it under its
 * suggested filename (`path.join(path.dirname(...), ...)`, `fs.existsSync(...)`) — unrelated to
 * screenshots, and not visible from crud.e2e.stub's own text since it is spliced in from PHP.
 *
 * Every module with `features.backend.list.import` enabled therefore got a generated CRUD spec
 * that failed its very first import step with `ReferenceError: path is not defined` — confirmed
 * live on Units, in a run that otherwise exercised the artifacts change correctly.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator::buildImportBlock()
 */
class PlaywrightTestGeneratorImportBlockImportsTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-import-block-imports-' . uniqid();
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

    private function generateAndRead(bool $importEnabled): string
    {
        $config = [
            'table_name' => 'units',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'name', 'type' => 'string', 'length' => '255'],
            ],
            'features' => [
                'backend' => [
                    'list' => ['enabled' => true, 'import' => $importEnabled],
                    'create' => ['enabled' => true],
                    'view' => ['enabled' => true],
                ],
                'frontend' => [
                    'list' => ['enabled' => true, 'fields' => [
                        ['key' => 'name', 'title' => 'Name', 'data' => 'name'],
                    ]],
                    'create' => ['enabled' => true, 'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'field_type' => 'input'],
                    ]],
                    'view' => ['enabled' => true],
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('Units', 'Core', $config);
        $this->assertTrue($generator->generate());

        return (string) file_get_contents(
            PathManager::getFrontendModulePath('Core', 'Units') . '/e2e/units-crud.e2e.js'
        );
    }

    public function test_a_module_with_import_enabled_gets_both_fs_and_path_imported(): void
    {
        $content = $this->generateAndRead(importEnabled: true);

        $this->assertStringContainsString("import fs from 'node:fs';", $content);
        $this->assertStringContainsString("import path from 'node:path';", $content);
        // The import block genuinely uses both -- this is the failure mode being guarded, not an
        // incidental detail: without the imports above, both of these throw ReferenceError.
        $this->assertStringContainsString('path.join(path.dirname(', $content);
        $this->assertStringContainsString('fs.existsSync(', $content);
    }

    public function test_the_import_uses_come_after_the_import_statements_not_before(): void
    {
        $content = $this->generateAndRead(importEnabled: true);

        $importStatementPos = strpos($content, "import path from 'node:path';");
        $usagePos = strpos($content, 'path.join(path.dirname(');
        $this->assertNotFalse($importStatementPos);
        $this->assertNotFalse($usagePos);
        $this->assertLessThan($usagePos, $importStatementPos);
    }

    public function test_fs_and_path_are_imported_even_when_import_is_not_enabled(): void
    {
        // Deliberately unconditional: Node builtins have no install cost, and gating the import on
        // whether THIS particular feature is enabled would reintroduce a class of bug (a config
        // combination nobody happened to test) for a saving of two lines.
        $content = $this->generateAndRead(importEnabled: false);

        $this->assertStringContainsString("import fs from 'node:fs';", $content);
        $this->assertStringContainsString("import path from 'node:path';", $content);
    }
}
