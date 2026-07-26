<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Factories;

use Blutrixx\GeneratorEngine\Generators\Backend\Factories\FactoryGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for FactoryGenerator — the new generator that closes a confirmed
 * bug: PhpUnitTestGenerator's generated CRUD tests run under RefreshDatabase,
 * so a required cross-module foreign key has no existing parent row to
 * reference at fixture time. The fix requires every generated module to ship
 * a `{Module}Factory.php`, co-located in the module directory (matching the
 * hand-built SYSTEM_SHELL convention — StatusesFactory, LocationsFactory,
 * MobileReleasesFactory, ...), so a dependent module's test can CREATE its
 * own parent row via `RelatedModel::factory()->create()->id` instead of
 * looking one up against an empty table.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Factories\FactoryGenerator
 */
class FactoryGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-factory-test-' . uniqid();
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

    private function assertValidPhpSyntax(string $file): void
    {
        $output = [];
        $exitCode = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, "Generated file has a PHP syntax error:\n" . implode("\n", $output));
    }

    public function test_generate_writes_factory_with_varied_column_types(): void
    {
        $config = [
            'table_name' => 'item_categories',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'id', 'type' => 'bigInteger'],
                ['name' => 'uuid', 'type' => 'string'],
                ['name' => 'name', 'type' => 'string', 'unique' => true, 'nullable' => false],
                ['name' => 'description', 'type' => 'text', 'nullable' => true],
                ['name' => 'is_active', 'type' => 'boolean', 'nullable' => false, 'default' => true],
                ['name' => 'sort_order', 'type' => 'integer', 'nullable' => false],
                ['name' => 'price', 'type' => 'decimal', 'nullable' => true],
                ['name' => 'effective_date', 'type' => 'date', 'nullable' => true],
                ['name' => 'created_at', 'type' => 'timestamp'],
                ['name' => 'updated_at', 'type' => 'timestamp'],
                ['name' => 'deleted_at', 'type' => 'timestamp', 'nullable' => true],
                ['name' => 'created_by_id', 'type' => 'foreignId'],
                ['name' => 'updated_by_id', 'type' => 'foreignId', 'nullable' => true],
            ],
        ];

        $generator = new FactoryGenerator('ItemCategories', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'ItemCategories') . '/ItemCategoriesFactory.php';
        $this->assertFileExists($path);
        $this->assertValidPhpSyntax($path);

        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('namespace App\Project\Modules\Core\ItemCategories;', $content);
        $this->assertStringContainsString('use Illuminate\Database\Eloquent\Factories\Factory;', $content);
        $this->assertStringContainsString('class ItemCategoriesFactory extends Factory', $content);
        $this->assertStringContainsString('protected $model = ItemCategoriesModel::class;', $content);

        // Autoincrement id is never emitted.
        $this->assertStringNotContainsString("'id' =>", $content);

        // uuid gets an explicit value (DB default isn't read back by create()).
        $this->assertStringContainsString("'uuid' => (string) Str::uuid(),", $content);

        // Unique string column.
        $this->assertStringContainsString("'name' =>", $content);
        $this->assertStringContainsString('uniqid()', $content);

        // text/boolean/integer/decimal/date per-type literals.
        $this->assertStringContainsString("'description' => fake()->paragraph(),", $content);
        $this->assertStringContainsString("'is_active' => true,", $content);
        $this->assertStringContainsString("'sort_order' => fake()->numberBetween(1, 1000),", $content);
        $this->assertStringContainsString("'price' => fake()->randomFloat(2, 1, 1000),", $content);
        $this->assertStringContainsString("'effective_date' => now()->toDateString(),", $content);

        // Timestamps/soft-deletes are auto-managed, never emitted individually.
        $this->assertStringNotContainsString("'created_at' =>", $content);
        $this->assertStringNotContainsString("'updated_at' =>", $content);
        $this->assertStringNotContainsString("'deleted_at' =>", $content);

        // created_by_id gets the standard literal 1; updated_by_id is omitted
        // entirely, matching every hand-built factory (StatusesFactory,
        // LocationTypesFactory, ...) which never sets it.
        $this->assertStringContainsString("'created_by_id' => 1,", $content);
        $this->assertStringNotContainsString("'updated_by_id' =>", $content);
    }

    public function test_non_autoincrement_id_gets_an_explicit_uuid_value(): void
    {
        $config = [
            'table_name' => 'user_invitations',
            'id_type' => 'uuid',
            'columns' => [
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'token_hash', 'type' => 'string', 'unique' => true],
            ],
            'has_uuid' => false,
            'has_creator_updater' => false,
        ];

        $generator = new FactoryGenerator('UserInvitations', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'UserInvitations') . '/UserInvitationsFactory.php';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("'id' => (string) Str::uuid(),", $content);
        // has_uuid=false and has_creator_updater=false: neither 'uuid' nor
        // 'created_by_id' should be emitted.
        $this->assertStringNotContainsString("'uuid' =>", $content);
        $this->assertStringNotContainsString("'created_by_id' =>", $content);
    }

    /**
     * Real generated module.json configs never carry the literal string
     * 'autoincrement' as `id_type` — IntrospectionToConfig::build() only
     * ever emits 'uuid' or 'bigint' (see its docblock). This is the exact
     * regression case: a `bigint` (auto-incrementing) primary key must
     * still omit `id` from definition() entirely, letting MySQL assign it.
     * Before the fix, buildIdLine() blacklisted only the literal
     * 'autoincrement', so a real 'bigint' config fell through to the uuid
     * branch and MySQL rejected the insert with "Data truncated for column
     * 'id'".
     */
    public function test_bigint_id_type_omits_id_entirely(): void
    {
        $config = [
            'table_name' => 'item_categories',
            'id_type' => 'bigint',
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
            ],
        ];

        $generator = new FactoryGenerator('ItemCategories', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'ItemCategories') . '/ItemCategoriesFactory.php';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString("'id' =>", $content);
    }

    /**
     * A `uuid` id_type must still emit an explicit id value, same as the
     * existing 'uuid' id_type coverage above — asserted again here
     * alongside the bigint case for a direct before/after contrast.
     */
    public function test_uuid_id_type_emits_explicit_id(): void
    {
        $config = [
            'table_name' => 'user_invitations',
            'id_type' => 'uuid',
            'columns' => [
                ['name' => 'token_hash', 'type' => 'string', 'unique' => true],
            ],
            'has_uuid' => false,
            'has_creator_updater' => false,
        ];

        $generator = new FactoryGenerator('UserInvitations', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'UserInvitations') . '/UserInvitationsFactory.php';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("'id' => (string) Str::uuid(),", $content);
    }

    /**
     * The exact confirmed regression: a module.json-shaped config carries
     * BOTH a top-level `id` key holding the MODULE's own UUID identifier
     * (metadata about the module, unrelated to the table's primary-key
     * column — see IntrospectionToConfig::build(), which sets
     * `'id' => $this->uuid5($moduleName)`) AND `id_type: 'bigint'` for the
     * actual (auto-incrementing) primary-key column type. The factory must
     * key off `id_type` only and must NOT be confused by the unrelated
     * UUID-shaped `id` metadata into treating the primary key as a uuid.
     */
    public function test_module_metadata_uuid_id_key_does_not_leak_into_definition_when_id_type_is_bigint(): void
    {
        $config = [
            'id' => '6669c946-c5a4-58cc-adb6-3b40b8634626', // module metadata UUID, NOT the PK type
            'table_name' => 'item_categories',
            'id_type' => 'bigint',
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
            ],
        ];

        $generator = new FactoryGenerator('ItemCategories', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'ItemCategories') . '/ItemCategoriesFactory.php';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString("'id' =>", $content);
    }

    /**
     * A self-referential hierarchy column (parent_id pointing back at this
     * module's own table) must resolve to null — never a hardcoded id — for
     * the same reason PhpUnitTestGenerator's identical case does: on the very
     * first insert into an empty table there is categorically no row yet for
     * a self-reference to point at.
     */
    public function test_self_referential_foreign_key_resolves_to_null(): void
    {
        $config = [
            'table_name' => 'categories',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'parent_id', 'type' => 'foreignId', 'nullable' => true, 'relatedModule' => 'Categories'],
            ],
        ];

        $generator = new FactoryGenerator('Categories', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Categories') . '/CategoriesFactory.php';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("'parent_id' => null,", $content);
    }

    /**
     * A required cross-module FK whose related module IS resolvable via the
     * module registry must recursively reference that module's own factory
     * — RelatedModel::factory() — exactly like MobileReleasesFactory's
     * hand-built 'apk_media_id' => MediaModel::factory().
     */
    public function test_required_cross_module_foreign_key_uses_related_modules_factory_when_resolvable(): void
    {
        PathManager::setModuleRegistry([
            [
                'name' => 'ItemTypes',
                'module_type' => 'Core',
                'table_name' => 'item_types',
            ],
        ]);

        $config = [
            'table_name' => 'items',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'item_type_id', 'type' => 'foreignId', 'nullable' => false, 'relatedModule' => 'ItemTypes'],
            ],
        ];

        $generator = new FactoryGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Items') . '/ItemsFactory.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString(
            "'item_type_id' => \\App\\Project\\Modules\\Core\\ItemTypes\\ItemTypesModel::factory(),",
            $content
        );
    }

    /**
     * A required cross-module FK whose related module is NOT resolvable via
     * the registry has no factory FQCN this generator can be sure exists, so
     * it must degrade to the literal `1` last resort rather than emit a
     * reference to a nonexistent class.
     */
    public function test_required_cross_module_foreign_key_falls_back_to_one_when_unresolvable(): void
    {
        $config = [
            'table_name' => 'items',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'item_type_id', 'type' => 'foreignId', 'nullable' => false, 'relatedModule' => 'ItemTypes'],
            ],
        ];

        $generator = new FactoryGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Items') . '/ItemsFactory.php';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("'item_type_id' => 1,", $content);
    }

    /**
     * A nullable cross-module FK resolves to null even when the related
     * module IS registered — simpler and always valid, and it avoids
     * creating an extra row nothing actually requires.
     */
    public function test_nullable_cross_module_foreign_key_resolves_to_null_even_when_registered(): void
    {
        PathManager::setModuleRegistry([
            [
                'name' => 'Media',
                'module_type' => 'Core',
                'table_name' => 'media',
            ],
        ]);

        $config = [
            'table_name' => 'mobile_releases',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'version', 'type' => 'string'],
                ['name' => 'ota_media_id', 'type' => 'foreignId', 'nullable' => true, 'relatedModule' => 'Media'],
            ],
        ];

        $generator = new FactoryGenerator('MobileReleases', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'MobileReleases') . '/MobileReleasesFactory.php';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("'ota_media_id' => null,", $content);
    }

    /**
     * The real key populated by IntrospectionToConfig::build() (and
     * documented on schema/module-config.schema.json's `enum_values`
     * property) is `enum_values`, not `options`/`values`. When it's
     * populated, the factory must emit `fake()->randomElement([...])` over
     * the ACTUAL allowed values, not a generic fake string that could
     * violate the DB enum constraint.
     */
    public function test_enum_column_with_enum_values_emits_random_element_over_real_values(): void
    {
        $config = [
            'table_name' => 'items',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'status', 'type' => 'enum', 'enum_values' => ['active', 'inactive', 'draft']],
            ],
        ];

        $generator = new FactoryGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Items') . '/ItemsFactory.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString(
            "'status' => fake()->randomElement(['active', 'inactive', 'draft']),",
            $content
        );
    }

    /**
     * An enum column with no enum_values populated (absent key or empty
     * array) must degrade gracefully to the pre-existing generic literal
     * fallback rather than error.
     */
    public function test_enum_column_without_enum_values_falls_back_to_generic_literal(): void
    {
        $config = [
            'table_name' => 'items',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'status', 'type' => 'enum'],
                ['name' => 'kind', 'type' => 'enum', 'enum_values' => []],
            ],
        ];

        $generator = new FactoryGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Items') . '/ItemsFactory.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("'status' => 'Status',", $content);
        $this->assertStringContainsString("'kind' => 'Kind',", $content);
        $this->assertStringNotContainsString('randomElement', $content);
    }

    /**
     * Enum values containing single quotes, double quotes, and backslashes
     * must round-trip through the generated factory as valid, correctly
     * escaped PHP — the exact case addslashes() would have corrupted (it
     * also escapes `"` to `\"`, which a single-quoted PHP string literal
     * does not recognize as an escape, leaving a stray backslash in the
     * resulting runtime string).
     */
    public function test_enum_value_with_quotes_and_backslashes_is_escaped_correctly(): void
    {
        $tricky = 'it\'s a "test"\\value';

        $config = [
            'table_name' => 'items',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'status', 'type' => 'enum', 'enum_values' => [$tricky, 'simple']],
            ],
        ];

        $generator = new FactoryGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Items') . '/ItemsFactory.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        // The exact PHP literal var_export() would emit for $tricky.
        $expectedLiteral = var_export($tricky, true);
        $this->assertStringContainsString(
            "'status' => fake()->randomElement([{$expectedLiteral}, 'simple']),",
            $content
        );

        // Round-trip: eval the emitted array literal and confirm it decodes
        // back to the exact original string, byte for byte.
        $arrayLiteral = "[{$expectedLiteral}, 'simple']";
        $decoded = eval("return {$arrayLiteral};");
        $this->assertSame([$tricky, 'simple'], $decoded);
    }
}
