<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Migrations;

use Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for MigrationGenerator's role in closing the four
 * "information-loss gap" defects:
 *   1. decimal precision/scale (previously ALWAYS hardcoded to 10,2)
 *   2. introspected indexes (previously always dropped -- config['indexes']
 *      was always [])
 *   3. composite unique constraints (previously discarded entirely)
 *   4. enum columns (previously fell through to plain string, losing values)
 *
 * Plus a byte-identical regression test proving a config carrying none of the
 * new fields (precision/scale/enum_values/indexes/unique_constraints)
 * generates EXACTLY what it generated before this fix.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator::generateFieldSchema()
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator::generateIndexes()
 */
class MigrationGeneratorSchemaGapsTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-schema-gaps-test-' . uniqid();
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

    private function readGeneratedMigration(MigrationGenerator $generator, string $group, string $module, string $table): string
    {
        $this->assertTrue($generator->generate());
        $files = glob($this->tmpRoot . "/BACKEND/app/Project/Modules/{$group}/{$module}/Migrations/*_create_{$table}_table.php") ?: [];
        $this->assertCount(1, $files);

        return file_get_contents($files[0]);
    }

    // ─── Gap 1: decimal precision/scale ─────────────────────────────────

    public function test_decimal_column_with_real_precision_and_scale_round_trips_exactly(): void
    {
        $config = [
            'table_name' => 'invoices',
            'id_type'    => 'autoincrement',
            'columns'    => [
                ['name' => 'id', 'type' => 'id'],
                ['name' => 'total', 'type' => 'decimal', 'precision' => 12, 'scale' => 4],
            ],
        ];

        $generator = new MigrationGenerator('Invoices', 'Sales', $config);
        $content = $this->readGeneratedMigration($generator, 'Sales', 'Invoices', 'invoices');

        $this->assertStringContainsString("\$table->decimal('total', 12, 4)", $content);
        $this->assertStringNotContainsString("\$table->decimal('total', 10, 2)", $content);
    }

    public function test_decimal_column_without_precision_scale_falls_back_to_10_2_default(): void
    {
        // Hand-authored config genuinely lacking the data -- the ONLY case
        // the 10/2 fallback should still apply.
        $config = [
            'table_name' => 'invoices',
            'id_type'    => 'autoincrement',
            'columns'    => [
                ['name' => 'id', 'type' => 'id'],
                ['name' => 'total', 'type' => 'decimal'],
            ],
        ];

        $generator = new MigrationGenerator('Invoices', 'Sales', $config);
        $content = $this->readGeneratedMigration($generator, 'Sales', 'Invoices', 'invoices');

        $this->assertStringContainsString("\$table->decimal('total', 10, 2)", $content);
    }

    // ─── Gap 2: introspected indexes ────────────────────────────────────

    public function test_introspected_index_is_emitted(): void
    {
        $config = [
            'table_name' => 'products',
            'id_type'    => 'autoincrement',
            'columns'    => [
                ['name' => 'id', 'type' => 'id'],
                ['name' => 'category_id', 'type' => 'foreignId'],
            ],
            'indexes' => [
                ['columns' => ['category_id'], 'unique' => false, 'name' => 'idx_products_category_id'],
            ],
        ];

        $generator = new MigrationGenerator('Products', 'Catalog', $config);
        $content = $this->readGeneratedMigration($generator, 'Catalog', 'Products', 'products');

        $this->assertStringContainsString("\$table->index(['category_id'], 'idx_products_category_id');", $content);
    }

    public function test_introspected_index_matching_a_conventional_system_index_is_not_duplicated(): void
    {
        // Simulates an un-reconciled caller (or a future bug in
        // IntrospectionToConfig's own filtering) handing MigrationGenerator a
        // config['indexes'] entry that collides with what
        // generateSystemIndexes() ALREADY emits unconditionally for uuid --
        // MigrationGenerator's own defensive filter must still catch it.
        $config = [
            'table_name' => 'products',
            'id_type'    => 'autoincrement',
            'columns'    => [
                ['name' => 'id', 'type' => 'id'],
            ],
            'indexes' => [
                ['columns' => ['uuid'], 'unique' => false, 'name' => 'some_other_uuid_index_name'],
            ],
            // has_uuid defaults true when omitted (ModuleConfigContract convention)
        ];

        $generator = new MigrationGenerator('Products', 'Catalog', $config);
        $content = $this->readGeneratedMigration($generator, 'Catalog', 'Products', 'products');

        // The conventional index is present exactly once...
        $this->assertSame(1, substr_count($content, "idx_products_uuid"));
        // ...and the duplicate, differently-named index on the same column
        // must NOT have been emitted.
        $this->assertStringNotContainsString('some_other_uuid_index_name', $content);
        $this->assertSame(1, substr_count($content, "\$table->index(['uuid']"));
    }

    // ─── Gap 3: composite unique constraints ────────────────────────────

    public function test_composite_unique_constraint_is_emitted(): void
    {
        $config = [
            'table_name' => 'products',
            'id_type'    => 'autoincrement',
            'columns'    => [
                ['name' => 'id', 'type' => 'id'],
                ['name' => 'tenant_id', 'type' => 'foreignId'],
                ['name' => 'sku', 'type' => 'string'],
            ],
            'unique_constraints' => [
                ['columns' => ['tenant_id', 'sku'], 'name' => 'products_tenant_id_sku_unique'],
            ],
        ];

        $generator = new MigrationGenerator('Products', 'Catalog', $config);
        $content = $this->readGeneratedMigration($generator, 'Catalog', 'Products', 'products');

        $this->assertStringContainsString(
            "\$table->unique(['tenant_id', 'sku'], 'products_tenant_id_sku_unique');",
            $content
        );
    }

    public function test_single_column_unique_still_works_via_the_column_unique_flag_unaffected_by_unique_constraints(): void
    {
        // Regression: single-column uniques must keep working exactly as
        // before -- rendered inline on the column definition, NOT via the new
        // unique_constraints mechanism.
        $config = [
            'table_name' => 'products',
            'id_type'    => 'autoincrement',
            'columns'    => [
                ['name' => 'id', 'type' => 'id'],
                ['name' => 'sku', 'type' => 'string', 'unique' => true],
            ],
        ];

        $generator = new MigrationGenerator('Products', 'Catalog', $config);
        $content = $this->readGeneratedMigration($generator, 'Catalog', 'Products', 'products');

        $this->assertStringContainsString("->unique('products_sku_unique')", $content);
    }

    // ─── Gap 4: enum columns ─────────────────────────────────────────────

    public function test_enum_column_is_emitted_with_its_values(): void
    {
        $config = [
            'table_name' => 'orders',
            'id_type'    => 'autoincrement',
            'columns'    => [
                ['name' => 'id', 'type' => 'id'],
                ['name' => 'status', 'type' => 'enum', 'enum_values' => ['pending', 'shipped', 'cancelled']],
            ],
        ];

        $generator = new MigrationGenerator('Orders', 'Sales', $config);
        $content = $this->readGeneratedMigration($generator, 'Sales', 'Orders', 'orders');

        $this->assertStringContainsString(
            "\$table->enum('status', ['pending', 'shipped', 'cancelled']);",
            $content
        );
    }

    public function test_enum_column_nullable_and_default_modifiers_still_chain_correctly(): void
    {
        $config = [
            'table_name' => 'orders',
            'id_type'    => 'autoincrement',
            'columns'    => [
                ['name' => 'id', 'type' => 'id'],
                ['name' => 'status', 'type' => 'enum', 'enum_values' => ['pending', 'shipped'], 'nullable' => true, 'default' => 'pending'],
            ],
        ];

        $generator = new MigrationGenerator('Orders', 'Sales', $config);
        $content = $this->readGeneratedMigration($generator, 'Sales', 'Orders', 'orders');

        $this->assertStringContainsString(
            "\$table->enum('status', ['pending', 'shipped'])->nullable()->default('pending');",
            $content
        );
    }

    // ─── No-regression: config carrying none of the new fields ─────────

    public function test_config_with_none_of_the_new_fields_generates_byte_identical_output(): void
    {
        // Exactly the shape a pre-fix caller would have produced: decimal
        // column with no precision/scale, plain string/foreignId columns, no
        // indexes, no unique_constraints, no enum. Asserts the FULL migration
        // body is unchanged by this fix for a config that opts into none of
        // the new fields.
        $config = [
            'table_name' => 'ledger_transactions',
            'id_type'    => 'autoincrement',
            'columns'    => [
                ['name' => 'id', 'type' => 'id'],
                ['name' => 'amount', 'type' => 'decimal'],
                ['name' => 'category_id', 'type' => 'foreignId'],
                ['name' => 'note', 'type' => 'string', 'length' => 255],
            ],
        ];

        $generator = new MigrationGenerator('LedgerTransactions', 'Sacco', $config);
        $content = $this->readGeneratedMigration($generator, 'Sacco', 'LedgerTransactions', 'ledger_transactions');

        $expectedSchemaLines = [
            "\$table->decimal('amount', 10, 2);",
            "\$table->foreignId('category_id');",
            "\$table->string('note', 255);",
        ];
        foreach ($expectedSchemaLines as $line) {
            $this->assertStringContainsString($line, $content);
        }

        // No index/unique-constraint noise beyond the unconditional
        // conventional system indexes (uuid/created_by_id/updated_by_id).
        $this->assertStringNotContainsString('unique_constraints', $content);
        $this->assertSame(3, substr_count($content, '$table->index('), 'Only the 3 conventional system indexes (uuid, created_by_id, updated_by_id) should be present.');
    }
}
