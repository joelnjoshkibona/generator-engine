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

    // ─── Bug 5: timestamps/softDeletes/uuid/audit columns always emitted
    //     regardless of the real schema (found while porting StockTransfers —
    //     the migration-side twin of the ModelGenerator fix in v2.10.8, which
    //     covered only the Model's $timestamps property / SoftDeletes trait,
    //     never the migration that actually creates the columns) ───────────

    private function readGeneratedMigration(MigrationGenerator $generator, string $group, string $module, string $table): string
    {
        $this->assertTrue($generator->generate());
        $files = glob($this->tmpRoot . "/BACKEND/app/Project/Modules/{$group}/{$module}/Migrations/*_create_{$table}_table.php") ?: [];
        $this->assertCount(1, $files);

        return file_get_contents($files[0]);
    }

    public function test_migration_includes_timestamps_uuid_and_audit_columns_by_default_but_not_soft_deletes(): void
    {
        // Mirrors every normal module: config carries no has_*/id_type signal
        // beyond the base config — must default to this project's standard
        // convention, exactly mirroring ModelGenerator's own established
        // defaults: has_timestamps/has_uuid/has_creator_updater default to
        // `true` (the norm), has_soft_deletes defaults to `false` (opt-in) —
        // see ModelGenerator::hasTimestamps()/hasSoftDeletes() and
        // IntrospectionToConfig::build(), both already precedent for this
        // exact true/true/true/false default split.
        $generator = new MigrationGenerator('LedgerTransactions', 'Sacco', $this->baseConfig());
        $content = $this->readGeneratedMigration($generator, 'Sacco', 'LedgerTransactions', 'ledger_transactions');

        $this->assertStringContainsString('$table->timestamps();', $content);
        $this->assertStringNotContainsString('$table->softDeletes();', $content);
        $this->assertStringNotContainsString('idx_ledger_transactions_deleted_at', $content);
        $this->assertStringContainsString("\$table->uuid()->default(DB::raw(Helpers::getDefaultUuidByDriver()))->unique();", $content);
        $this->assertStringContainsString("\$table->foreignId('created_by_id');", $content);
        $this->assertStringContainsString("\$table->foreignId('updated_by_id')->nullable();", $content);
        $this->assertStringContainsString("idx_ledger_transactions_uuid", $content);
        $this->assertStringContainsString("idx_ledger_transactions_created_by_id", $content);
        $this->assertStringContainsString("idx_ledger_transactions_updated_by_id", $content);
        $this->assertStringContainsString('use App\Project\_Src\Helpers;', $content);
        $this->assertStringContainsString('use Illuminate\Support\Facades\DB;', $content);
    }

    public function test_migration_includes_soft_deletes_when_explicitly_configured(): void
    {
        $config = array_merge($this->baseConfig(), [
            'table_name' => 'soft_deletable_table',
            'has_soft_deletes' => true,
        ]);
        $generator = new MigrationGenerator('SoftDeletableTable', 'Sacco', $config);
        $content = $this->readGeneratedMigration($generator, 'Sacco', 'SoftDeletableTable', 'soft_deletable_table');

        $this->assertStringContainsString('$table->softDeletes();', $content);
        $this->assertStringContainsString('idx_soft_deletable_table_deleted_at', $content);
    }

    public function test_migration_omits_timestamps_softdeletes_uuid_and_audit_columns_when_table_has_none(): void
    {
        // Mirrors the real bug: price_lists/stock_transfers were scaffolded
        // with NO uuid, NO timestamps, NO soft-deletes and NO
        // created_by_id/updated_by_id columns in the real dev schema, but the
        // generated migration tried to create all of them anyway, requiring
        // hand-correction after every scaffold.
        $config = array_merge($this->baseConfig(), [
            'table_name' => 'stock_transfers',
            'has_timestamps' => false,
            'has_soft_deletes' => false,
            'has_uuid' => false,
            'has_creator_updater' => false,
        ]);
        $generator = new MigrationGenerator('StockTransfers', 'Inventory', $config);
        $content = $this->readGeneratedMigration($generator, 'Inventory', 'StockTransfers', 'stock_transfers');

        $this->assertStringNotContainsString('$table->timestamps();', $content);
        $this->assertStringNotContainsString('$table->softDeletes();', $content);
        $this->assertStringNotContainsString('$table->uuid()', $content);
        $this->assertStringNotContainsString('created_by_id', $content);
        $this->assertStringNotContainsString('updated_by_id', $content);
        $this->assertStringNotContainsString('idx_stock_transfers_uuid', $content);
        $this->assertStringNotContainsString('idx_stock_transfers_deleted_at', $content);
        $this->assertStringNotContainsString('use App\Project\_Src\Helpers;', $content, 'The Helpers import must not leak in when no uuid column is generated (unused-import).');
        $this->assertStringNotContainsString('use Illuminate\Support\Facades\DB;', $content);
    }

    public function test_migration_respects_independently_mixed_flags(): void
    {
        // has_soft_deletes=true but everything else false — proves the four
        // flags gate independently rather than all-or-nothing.
        $config = array_merge($this->baseConfig(), [
            'table_name' => 'partial_flags_table',
            'has_timestamps' => false,
            'has_soft_deletes' => true,
            'has_uuid' => false,
            'has_creator_updater' => false,
        ]);
        $generator = new MigrationGenerator('PartialFlagsTable', 'Sacco', $config);
        $content = $this->readGeneratedMigration($generator, 'Sacco', 'PartialFlagsTable', 'partial_flags_table');

        $this->assertStringNotContainsString('$table->timestamps();', $content);
        $this->assertStringContainsString('$table->softDeletes();', $content);
        $this->assertStringContainsString('idx_partial_flags_table_deleted_at', $content);
        $this->assertStringNotContainsString('$table->uuid()', $content);
        $this->assertStringNotContainsString('created_by_id', $content);
    }
}
