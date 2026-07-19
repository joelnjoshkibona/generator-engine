<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Migrations;

use Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for MigrationGenerator — defect class 4 (make:module
 * writing a SECOND, duplicate "create" migration for a table that already
 * has one).
 *
 * Bug: generate()'s output filename embeds the CURRENT timestamp
 * (date('Y_m_d_His')), which differs on every invocation, so
 * BaseGenerator::writeFile()'s file_exists() check — which compares that
 * exact, always-fresh filename — could never detect that a "create" migration
 * for this table already existed. Regenerating a module whose table was
 * already migrated (by hand or by a previous run) therefore produced a
 * SECOND create-migration file, with a slightly different (and sometimes
 * wrong) schema, sitting right next to the real one. Confirmed for
 * LedgerTransactions and member_phones.
 *
 * Fix: generate() now checks for an existing "*_create_{table}_table.php"
 * file in the module's Migrations directory — by TABLE NAME, not exact
 * filename — before writing, and skips (returns false) if one is already
 * present.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator::generate()
 */
class MigrationGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-migration-test-' . uniqid();
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

    private function baseConfig(): array
    {
        return [
            'table_name' => 'ledger_transactions',
            'id_type'    => 'autoincrement',
            'columns'    => [
                ['name' => 'id', 'type' => 'id'],
                ['name' => 'amount', 'type' => 'decimal'],
            ],
        ];
    }

    private function migrationsDir(): string
    {
        return $this->tmpRoot . '/BACKEND/app/Project/Modules/Sacco/LedgerTransactions/Migrations';
    }

    /** @return string[] */
    private function createMigrationFiles(): array
    {
        return glob($this->migrationsDir() . '/*_create_ledger_transactions_table.php') ?: [];
    }

    public function test_a_single_generate_call_writes_exactly_one_create_migration(): void
    {
        $generator = new MigrationGenerator('LedgerTransactions', 'Sacco', $this->baseConfig());
        $this->assertTrue($generator->generate());

        $this->assertCount(1, $this->createMigrationFiles());
    }

    public function test_regenerating_the_same_module_does_not_write_a_second_create_migration(): void
    {
        $first = new MigrationGenerator('LedgerTransactions', 'Sacco', $this->baseConfig());
        $this->assertTrue($first->generate());
        $existing = $this->createMigrationFiles();
        $this->assertCount(1, $existing);

        // Backdate the first file's timestamp prefix so a second generate()
        // call is GUARANTEED to want a different date('Y_m_d_His') filename
        // — otherwise, if both calls happened to land within the same
        // wall-clock second, the OLD exact-filename file_exists() check
        // would coincidentally also have blocked the second write, masking
        // whether this test is actually exercising the table-name-based
        // guard rather than a timing accident.
        $backdated = dirname($existing[0]) . '/2000_01_01_000000_create_ledger_transactions_table.php';
        rename($existing[0], $backdated);

        // Simulate a later `make:module` re-run for the same table — a
        // brand-new generator instance producing a fresh, later timestamp.
        $second = new MigrationGenerator('LedgerTransactions', 'Sacco', $this->baseConfig());
        $wrote = $second->generate();

        $this->assertFalse($wrote, 'A second create-migration for an already-migrated table must not be written.');
        $this->assertCount(1, $this->createMigrationFiles(), 'Exactly one create migration must exist for the table after a re-run.');
    }

    public function test_pre_existing_hand_written_migration_blocks_generation(): void
    {
        // Mirrors the real-world scenario: a human already hand-wrote the
        // create migration (with an OLDER timestamp prefix and a different,
        // correct schema) before the generator ever ran for this module.
        $dir = $this->migrationsDir();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/2020_01_01_000000_create_ledger_transactions_table.php', "<?php\n// hand-written\n");

        $generator = new MigrationGenerator('LedgerTransactions', 'Sacco', $this->baseConfig());
        $wrote = $generator->generate();

        $this->assertFalse($wrote);
        $this->assertCount(1, $this->createMigrationFiles(), 'The generator must not add a duplicate alongside the pre-existing hand-written migration.');
    }

    public function test_different_tables_are_unaffected_by_each_others_guard(): void
    {
        $first = new MigrationGenerator('LedgerTransactions', 'Sacco', $this->baseConfig());
        $this->assertTrue($first->generate());

        $otherConfig = array_merge($this->baseConfig(), ['table_name' => 'member_phones']);
        $second = new MigrationGenerator('MemberPhones', 'Sacco', $otherConfig);
        $this->assertTrue($second->generate(), 'A different table must still get its own create migration.');

        $memberPhonesDir = $this->tmpRoot . '/BACKEND/app/Project/Modules/Sacco/MemberPhones/Migrations';
        $this->assertCount(1, glob($memberPhonesDir . '/*_create_member_phones_table.php') ?: []);
    }
}
