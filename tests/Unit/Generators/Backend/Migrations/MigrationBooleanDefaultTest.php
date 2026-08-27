<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Migrations;

use Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * A boolean column's DEFAULT must survive regeneration.
 *
 * It did not. The emitter matched only the literal string `"true"` and sent
 * everything else to `false` — but MySQL has no BOOLEAN type, so
 * `$table->boolean()` compiles to TINYINT(1) and information_schema reports its
 * default as the string `"1"` or `"0"`, never `"true"`. Every real introspected
 * boolean default therefore came back inverted.
 *
 * This was not theoretical. `mobile_releases.is_active` has `DEFAULT 1` in the
 * database and `->default(true)` in its committed migration; its module.json
 * correctly records `"1"`; and regenerating from that config emitted
 * `->default(false)`. A `--force` regenerate silently flipped a production
 * default from on to off, and nothing anywhere reported it.
 */
class MigrationBooleanDefaultTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-bool-default-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    /**
     * The exact shapes MySQL hands back, plus the ones a hand-written config uses.
     */
    public function test_boolean_defaults_are_not_inverted(): void
    {
        $cases = [
            // [stored default, expected emitted default]
            ['1',      'true'],   // what information_schema returns for DEFAULT 1
            ["'1'",    'true'],   // ...sometimes quoted
            ['0',      'false'],
            ["'0'",    'false'],
            ['true',   'true'],   // the only form that used to work
            ['false',  'false'],
            ['TRUE',   'true'],   // case is not significant
            ['yes',    'true'],
            ['on',     'true'],
            [true,     'true'],   // already a real bool
            [false,    'false'],
        ];

        foreach ($cases as [$stored, $expected]) {
            $migration = $this->generate($stored);

            $this->assertStringContainsString(
                "\$table->boolean('is_active')->default({$expected})",
                $migration,
                sprintf('default %s should emit ->default(%s)', var_export($stored, true), $expected)
            );
        }
    }

    public function test_a_boolean_with_no_default_gets_none(): void
    {
        $migration = $this->generate(null);

        $this->assertStringContainsString("\$table->boolean('is_active')", $migration);
        $this->assertStringNotContainsString("boolean('is_active')->default", $migration);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function generate(mixed $default): string
    {
        $this->freshRoot();

        (new MigrationGenerator('Widgets', 'Core', [
            'module_name' => 'Widgets',
            'module_type' => 'Core',
            'table_name'  => 'widgets',
            'id_type'     => 'bigint',
            'columns'     => [
                [
                    'name'     => 'is_active',
                    'type'     => 'boolean',
                    'nullable' => false,
                    'default'  => $default,
                ],
            ],
        ]))->setForce(true)->generate();

        return $this->migrationContents();
    }

    private function freshRoot(): void
    {
        $this->removeDirectory($this->tmpRoot);
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    private function migrationContents(): string
    {
        $dir = PathManager::getBackendModulePath('Core', 'Widgets') . '/Migrations';
        $files = glob($dir . '/*.php') ?: [];

        $this->assertNotEmpty($files, 'no migration was generated');

        return (string) file_get_contents($files[0]);
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
