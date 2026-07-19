<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Models;

use Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for ModelGenerator — actually invokes generate() and
 * inspects the real generated *Model.php file on disk, exercising the same
 * code path used to produce the real defective models this session
 * (LedgerTransactionTypes/LedgerTransactions/MemberPhones).
 *
 * Bug 1 ($timestamps — defect class 2): created_at/updated_at are
 * deliberately excluded from $config['columns'] (see
 * SchemaIntrospector::SKIP_COLUMNS), so ModelGenerator::generateTimestamps()
 * checking "does $this->fields contain 'created_at'" was checking a list
 * that NEVER contains it — regardless of whether the real migrated table had
 * timestamps. Every generated model got `public $timestamps = false;`,
 * silently leaving created_at/updated_at NULL forever.
 *
 * Fix: default to timestamps-enabled (Laravel's own default — most tables
 * have $table->timestamps()) unless the config explicitly says otherwise via
 * 'has_timestamps' (set by IntrospectionToConfig::build() from
 * SchemaIntrospector::hasTimestamps()).
 *
 * Bug 2 (SoftDeletes — defect class 3): the backend model.stub template
 * unconditionally `use HasFactory, SoftDeletes;` regardless of whether the
 * table has a deleted_at column at all — breaking every query with "unknown
 * column deleted_at" (confirmed for LedgerTransactionsModel).
 *
 * Fix: SoftDeletes import + trait are now conditional on 'has_soft_deletes'
 * (default false — soft deletes are opt-in).
 *
 * Bug 3 (bogus relation guessing — defect class 6): a column merely NAMED
 * like a foreign key (ends in "_id") with no real relatedModule used to get
 * a relation guessed from its name with no check that the guessed module
 * actually exists (e.g. "external_trans_id" -> a nonexistent "ExternalTrans"
 * module).
 *
 * Fix: a guessed (not explicitly-FK-derived) module name must resolve
 * against the module registry/project before a relation is emitted for it.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator
 */
class ModelGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-model-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::setModuleRegistry([]);
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
     * Base config every test starts from. 'connection' MUST always be
     * present (even as '') — ModelGenerator::generateConnection() falls
     * back to the Laravel config() helper when it's absent entirely, which
     * doesn't exist in this plain-PHPUnit environment.
     */
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

    private function generateAndRead(array $config, string $moduleName = 'TestLedger', string $moduleGroup = 'Sacco'): string
    {
        $generator = new ModelGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate(), 'ModelGenerator::generate() should report a successful write.');

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/{$moduleGroup}/{$moduleName}/{$moduleName}Model.php";
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    // ─── Bug 1: $timestamps (defect class 2) ───────────────────────────────

    public function test_timestamps_are_enabled_by_default_when_config_omits_has_timestamps(): void
    {
        // Mirrors the real bug scenario: a "columns" list with no
        // created_at/updated_at entries (because those are stripped by
        // SchemaIntrospector before this config was even built) and no
        // explicit 'has_timestamps' key — must NOT disable timestamps.
        $content = $this->generateAndRead($this->baseConfig());

        $this->assertStringNotContainsString(
            'public $timestamps = false;',
            $content,
            'A model whose config carries no timestamps signal must default to Eloquent\'s own timestamps-enabled behaviour, not be silently disabled.'
        );
    }

    public function test_timestamps_disabled_only_when_explicitly_configured(): void
    {
        $content = $this->generateAndRead($this->baseConfig(['has_timestamps' => false]));

        $this->assertStringContainsString('public $timestamps = false;', $content);
    }

    public function test_timestamps_enabled_explicitly_also_omits_the_false_override(): void
    {
        $content = $this->generateAndRead($this->baseConfig(['has_timestamps' => true]));

        $this->assertStringNotContainsString('public $timestamps = false;', $content);
    }

    public function test_legacy_created_date_modified_date_columns_still_use_custom_constants(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'has_timestamps' => false, // should be overridden by the legacy-column branch
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'created_date', 'type' => 'datetime'],
                ['name' => 'modified_date', 'type' => 'datetime'],
            ],
        ]));

        $this->assertStringContainsString("const CREATED_AT = 'created_date';", $content);
        $this->assertStringContainsString("const UPDATED_AT = 'modified_date';", $content);
        $this->assertStringNotContainsString('public $timestamps = false;', $content);
    }

    // ─── Bug 2: SoftDeletes (defect class 3) ────────────────────────────────

    public function test_soft_deletes_is_omitted_by_default(): void
    {
        // Mirrors the real bug scenario for LedgerTransactionsModel: the
        // migration has no deleted_at column, and config carries no
        // 'has_soft_deletes' signal.
        $content = $this->generateAndRead($this->baseConfig());

        $this->assertStringNotContainsString('SoftDeletes', $content, 'SoftDeletes must not be referenced anywhere when the table has no deleted_at column.');
        $this->assertStringContainsString('use HasFactory;', $content);
    }

    public function test_soft_deletes_is_added_when_table_actually_has_deleted_at(): void
    {
        $content = $this->generateAndRead($this->baseConfig(['has_soft_deletes' => true]));

        $this->assertStringContainsString('use Illuminate\Database\Eloquent\SoftDeletes;', $content);
        $this->assertStringContainsString('use HasFactory, SoftDeletes;', $content);
        // Exactly one import — no leftover/duplicate.
        $this->assertSame(1, substr_count($content, 'use Illuminate\Database\Eloquent\SoftDeletes;'));
    }

    // ─── Bug 3: bogus relation guessing (defect class 6) ────────────────────

    public function test_guessed_relation_is_skipped_when_the_guessed_module_does_not_exist(): void
    {
        // "external_trans_id" is normalized_type 'foreignId' here to mirror
        // the upstream SchemaIntrospector mis-tagging this test's sibling
        // (SchemaIntrospectorNormalizeTypeTest) proves is now fixed — this
        // test asserts ModelGenerator's OWN independent safety net for the
        // case where a 'foreignId' column still slips through with no
        // resolvable relatedModule (e.g. hand-authored config, or any other
        // future path that produces the same shape).
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'external_trans_id', 'type' => 'foreignId', 'relatedModule' => ''],
            ],
        ]));

        $this->assertStringNotContainsString('externalTrans', $content, 'A relation must never be emitted for a guessed module name that does not resolve to any known module.');
        $this->assertStringNotContainsString('ExternalTrans', $content);
    }

    public function test_guessed_relation_is_emitted_when_the_guessed_module_does_resolve(): void
    {
        PathManager::setModuleRegistry([
            ['name' => 'ExternalTrans', 'module_type' => 'Sacco'],
        ]);

        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'external_trans_id', 'type' => 'foreignId', 'relatedModule' => ''],
            ],
        ]));

        $this->assertStringContainsString('public function externalTrans()', $content, 'A guessed module name must still resolve normally once it is a real, known module.');
        $this->assertStringContainsString('\\App\\Project\\Modules\\Sacco\\ExternalTrans\\ExternalTransModel::class', $content);
    }

    public function test_explicit_related_module_relation_is_never_gated_by_registry_resolution(): void
    {
        // A relatedModule that came from REAL FK metadata (not guessed from
        // the column name) must always be honoured, even with an empty
        // registry — the registry-resolution gate only applies to guessed
        // names (defect class 6), not to explicit, FK-derived ones.
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'category_id', 'type' => 'foreignId', 'relatedModule' => 'Categories'],
            ],
        ]));

        $this->assertStringContainsString('public function category()', $content);
        $this->assertStringContainsString('CategoriesModel::class', $content);
    }
}
