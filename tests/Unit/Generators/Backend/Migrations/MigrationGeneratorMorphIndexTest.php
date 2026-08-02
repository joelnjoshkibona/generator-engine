<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Migrations;

use Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a bug found + fixed 2026-08-02 while live-verifying
 * the new morphs-suite fixture (tests/Fixtures/integration-schemas/morphs-suite/):
 * generateSchema() correctly collapses a morph pair's two columns
 * (`{prefix}_type` + `{prefix}_id`) into a single `$table->morphs($name)`
 * call, which itself creates a composite DB index over those two columns —
 * but generateIndexes()'s "don't re-emit an index the schema builder already
 * created for you" reconciliation (the same mechanism that already covers
 * uuid/created_by_id/updated_by_id/deleted_at) never covered morph pairs.
 * So a real config regenerated from a real, already-migrated `payments`
 * table (payable_type/payable_id, with its own real composite index —
 * exactly what introspection finds on any live morphs table) produced BOTH
 * `$table->morphs('payable')` AND a redundant, separately-named
 * `$table->index(['payable_type', 'payable_id'], 'idx_payments_payable')`
 * for the identical two columns. Harmless in MySQL (duplicate indexes are
 * legal, just wasteful) but real generated-output noise on every regenerate
 * of any morphs-bearing table.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator::generateIndexes()
 */
class MigrationGeneratorMorphIndexTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-migration-morph-index-test-' . uniqid();
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

    /**
     * Shaped exactly like a real regenerate-from-introspection round trip:
     * config['morphs'] describing the pair, PLUS config['indexes'] carrying
     * the real composite DB index introspection would find on an
     * already-migrated table with that pair -- the two must coexist for
     * this bug to reproduce (a fresh, never-migrated table's config would
     * only have config['morphs'], no matching config['indexes'] entry yet).
     */
    private function paymentsConfig(): array
    {
        return [
            'table_name' => 'payments',
            'id_type'    => 'autoincrement',
            'columns'    => [
                ['name' => 'id', 'type' => 'id'],
                ['name' => 'amount', 'type' => 'decimal', 'precision' => 14, 'scale' => 2],
                ['name' => 'payable_type', 'type' => 'string'],
                ['name' => 'payable_id', 'type' => 'bigInteger'],
            ],
            'morphs' => [
                ['name' => 'payable', 'type_column' => 'payable_type', 'id_column' => 'payable_id', 'targets' => []],
            ],
            'indexes' => [
                ['columns' => ['payable_type', 'payable_id'], 'unique' => false, 'name' => 'idx_payments_payable'],
                ['columns' => ['amount'], 'unique' => false, 'name' => 'idx_payments_amount'],
            ],
        ];
    }

    public function test_morphs_call_replaces_the_pair_columns_and_does_not_duplicate_its_own_index(): void
    {
        $generator = new MigrationGenerator('Payments', 'Custom', $this->paymentsConfig());
        $this->assertTrue($generator->generate());

        $files = glob($this->tmpRoot . '/BACKEND/app/Project/Modules/Custom/Payments/Migrations/*_create_payments_table.php');
        $this->assertCount(1, $files);
        $content = file_get_contents($files[0]);

        $this->assertStringContainsString("\$table->morphs('payable');", $content);
        $this->assertStringNotContainsString('payable_type', str_replace("morphs('payable')", '', $content));
        $this->assertStringNotContainsString('payable_id', str_replace("morphs('payable')", '', $content));

        // The morph pair's own composite index must not be re-emitted --
        // morphs() already creates it. A genuinely distinct index (amount)
        // must still come through untouched.
        $this->assertStringNotContainsString('idx_payments_payable', $content);
        $this->assertStringContainsString("\$table->index(['amount'], 'idx_payments_amount');", $content);
    }
}
