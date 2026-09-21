<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Migrations;

use Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * A nullable column with no default must not get `->default('NULL')`.
 *
 * MariaDB reports such a column's default as the four-character string NULL. It reached the migration as
 * `$table->datetime('paid_at')->nullable()->default('NULL')`, which MySQL's strict mode rejects
 * (1067 Invalid default value) the first time the migration runs on a database the table is not already in:
 * a fresh test database, CI, a new contributor's first migrate. Confirmed on five module scaffolds.
 */
class MigrationNullDefaultTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-null-default-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    public function test_a_nullable_column_whose_default_is_the_string_null_gets_no_default(): void
    {
        foreach (['datetime' => 'paid_at', 'string' => 'note', 'integer' => 'quantity', 'text' => 'remarks'] as $type => $name) {
            $migration = $this->generate($name, $type, 'NULL');

            $this->assertStringContainsString("'{$name}')->nullable()", $migration, "{$type}: the column is still nullable");
            $this->assertStringNotContainsString("default('NULL')", $migration, "{$type}: no default of the word NULL");
            $this->assertStringNotContainsString('default(NULL)', $migration);
        }
    }

    public function test_the_case_of_the_word_null_does_not_matter(): void
    {
        $this->assertStringNotContainsString("default('null')", $this->generate('paid_at', 'datetime', 'null'));
    }

    public function test_a_quoted_string_default_is_emitted_without_the_quotes_of_the_database(): void
    {
        $migration = $this->generate('status', 'string', "'UNPAID'", false);

        $this->assertStringContainsString("default('UNPAID')", $migration);
        $this->assertStringNotContainsString("default('\\'UNPAID\\'')", $migration);
        $this->assertStringNotContainsString("default(''UNPAID'')", $migration);
    }

    public function test_a_real_default_is_still_emitted(): void
    {
        $this->assertStringContainsString("default('UNPAID')", $this->generate('status', 'string', 'UNPAID', false));
        $this->assertStringContainsString("default('0')", $this->generate('quantity', 'integer', '0', false), 'a numeric default keeps the form it has always had');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function generate(string $column, string $type, mixed $default, bool $nullable = true): string
    {
        $this->removeDirectory($this->tmpRoot);
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);

        (new MigrationGenerator('Widgets', 'Core', [
            'module_name' => 'Widgets',
            'module_type' => 'Core',
            'table_name'  => 'widgets',
            'id_type'     => 'bigint',
            'columns'     => [['name' => $column, 'type' => $type, 'nullable' => $nullable, 'default' => $default]],
        ]))->setForce(true)->generate();

        $files = glob(PathManager::getBackendModulePath('Core', 'Widgets') . '/Migrations/*.php') ?: [];
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
