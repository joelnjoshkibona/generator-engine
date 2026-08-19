<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Migrations;

use Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationUpdateGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for MigrationUpdateGenerator — two real bugs found
 * live 2026-08-18 turning PurchaseOrders.po_number/status_id nullable via
 * an UPDATE (ALTER) migration:
 *
 * Bug A (empty-string default sentinel): `''` is this codebase's own "no
 * default configured" sentinel throughout the pipeline (ColumnTypeMapper,
 * Pass1ConfigAssembler's `'default' => $c['default'] ?? ''`).
 * MigrationGenerator (the CREATE-table path) already correctly excludes it
 * (`$default !== null && $default !== ''`) — buildColumnSchema() here only
 * checked `!== null`, so a numeric/foreignId column transitioning nullable
 * with the sentinel default emitted a literal `->default('')`, which MySQL
 * rejects on a non-string column (SQLSTATE[42000] 1067).
 *
 * Bug B (duplicate unique index on ->change()): a chained ->unique() on
 * ->change() compiles as a SEPARATE `ADD UNIQUE` statement regardless of
 * whether the column is already unique in the DB. Re-chaining ->unique()
 * for a column that was unique in BOTH the old and new schema (only some
 * unrelated property, e.g. nullable, actually changed) duplicated an
 * already-existing index name and failed with MySQL 1061.
 *
 * @see MigrationUpdateGenerator::buildColumnSchema()
 */
class MigrationUpdateGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-migration-update-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);

        // generate() logs via the Log facade -- illuminate/container isn't a
        // direct dependency of this package, so a minimal ArrayAccess stub
        // (all Facade::resolveFacadeInstance() actually needs) stands in for
        // a real app container, resolving 'log' to a no-op logger.
        Facade::setFacadeApplication(new class implements \ArrayAccess {
            private object $logger;
            public function __construct() {
                $this->logger = new class {
                    public function __call($name, $args) { return null; }
                };
            }
            public function offsetExists($offset): bool { return $offset === 'log'; }
            public function offsetGet($offset): mixed { return $this->logger; }
            public function offsetSet($offset, $value): void {}
            public function offsetUnset($offset): void {}
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
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

    private function migrationsDir(): string
    {
        return $this->tmpRoot . '/BACKEND/app/Project/Modules/System/PurchaseOrders/Migrations';
    }

    private function latestMigrationContents(): string
    {
        $files = glob($this->migrationsDir() . '/*_update_purchase_orders_table_*.php') ?: [];
        $this->assertNotEmpty($files, 'Expected an update migration file to have been written.');
        return file_get_contents(end($files));
    }

    public function test_column_becoming_nullable_with_empty_string_default_sentinel_does_not_emit_default(): void
    {
        $generator = new MigrationUpdateGenerator('PurchaseOrders', 'System', [
            'table_name' => 'purchase_orders',
            'previous_columns' => [
                ['name' => 'status_id', 'type' => 'foreignId', 'nullable' => false, 'default' => '', 'unique' => false],
            ],
            'new_columns' => [
                ['name' => 'status_id', 'type' => 'foreignId', 'nullable' => true, 'default' => '', 'unique' => false],
            ],
        ]);

        $this->assertTrue($generator->generate());

        $contents = $this->latestMigrationContents();
        $this->assertStringNotContainsString(
            "->default('')",
            $contents,
            'The "no default configured" sentinel must never be emitted as a literal ->default(\'\') call.'
        );
        $this->assertStringContainsString("\$table->foreignId('status_id')->nullable()", $contents);
    }

    public function test_real_default_value_is_still_emitted(): void
    {
        $generator = new MigrationUpdateGenerator('PurchaseOrders', 'System', [
            'table_name' => 'purchase_orders',
            'previous_columns' => [
                ['name' => 'priority', 'type' => 'string', 'nullable' => false, 'default' => '', 'unique' => false],
            ],
            'new_columns' => [
                ['name' => 'priority', 'type' => 'string', 'nullable' => false, 'default' => 'normal', 'unique' => false],
            ],
        ]);

        $this->assertTrue($generator->generate());

        $this->assertStringContainsString(
            "->default('normal')",
            $this->latestMigrationContents(),
            'A genuinely configured default value must still be emitted.'
        );
    }

    public function test_column_already_unique_does_not_get_a_duplicate_unique_chained_on_change(): void
    {
        // po_number was already unique in BOTH the old and new schema — only
        // `nullable` actually changed. buildColumnSchema() must not re-chain
        // ->unique() here; the constraint already exists in the database.
        $generator = new MigrationUpdateGenerator('PurchaseOrders', 'System', [
            'table_name' => 'purchase_orders',
            'previous_columns' => [
                ['name' => 'po_number', 'type' => 'string', 'nullable' => false, 'default' => '', 'unique' => true],
            ],
            'new_columns' => [
                ['name' => 'po_number', 'type' => 'string', 'nullable' => true, 'default' => '', 'unique' => true],
            ],
        ]);

        $this->assertTrue($generator->generate());

        $contents = $this->latestMigrationContents();
        $this->assertStringNotContainsString(
            '->unique()',
            $contents,
            'A column that was already unique before this change must not re-chain ->unique() on ->change() — Laravel compiles it as a separate ADD UNIQUE statement and duplicates the existing index.'
        );
        $this->assertStringContainsString("\$table->string('po_number')->nullable()", $contents);
    }

    public function test_column_newly_becoming_unique_still_emits_unique(): void
    {
        $generator = new MigrationUpdateGenerator('PurchaseOrders', 'System', [
            'table_name' => 'purchase_orders',
            'previous_columns' => [
                ['name' => 'reference_code', 'type' => 'string', 'nullable' => false, 'default' => '', 'unique' => false],
            ],
            'new_columns' => [
                ['name' => 'reference_code', 'type' => 'string', 'nullable' => false, 'default' => '', 'unique' => true],
            ],
        ]);

        $this->assertTrue($generator->generate());

        $this->assertStringContainsString(
            '->unique()',
            $this->latestMigrationContents(),
            'A column genuinely becoming unique for the first time must still get ->unique() chained.'
        );
    }
}
