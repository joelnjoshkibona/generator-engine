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

    // ─── Defect A regression: max-length-aware string literals ─────────────

    /**
     * The confirmed bug: THC_V2's OrdersFactory.php emitted
     * `fake()->words(2, true)` unconditionally for `orders.payment_type
     * varchar(20)` — two random words routinely exceed 20 chars, so every
     * `OrdersModel::factory()->create()` (including as a cross-module FK
     * parent for other modules' fixtures) 500'd with SQLSTATE 22001 "Data
     * too long for column". A column carrying a real `length` must now emit
     * a literal `Str::limit()`-bounded to that length.
     */
    public function test_string_column_with_length_emits_length_bounded_literal(): void
    {
        $config = [
            'table_name' => 'orders',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'payment_type', 'type' => 'string', 'length' => '20', 'nullable' => false],
            ],
        ];

        $generator = new FactoryGenerator('Orders', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Orders') . '/OrdersFactory.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString(
            "'payment_type' => Str::limit(fake()->words(2, true), 20, ''),",
            $content
        );
    }

    /**
     * A column with NO length constraint (the `length` key entirely absent,
     * exactly as every existing test above already models) must produce
     * BYTE-FOR-BYTE identical output to before this fix — the length-aware
     * branch must never engage when there is nothing to bound against.
     */
    public function test_string_column_without_length_key_is_byte_for_byte_unchanged(): void
    {
        $config = [
            'table_name' => 'items',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'nickname', 'type' => 'string', 'nullable' => true],
            ],
        ];

        $generator = new FactoryGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Items') . '/ItemsFactory.php';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("'nickname' => fake()->words(2, true),", $content);
    }

    /**
     * The same "no constraint" behaviour for an explicitly-empty `length`
     * value — IntrospectionToConfig::buildColumn() always casts `length` to
     * a STRING and defaults it to `''` (never null) for a column with no
     * length constraint: `(string) ($col['length'] ?? '')`. `''` must
     * normalize to "no constraint", not to a length of zero.
     */
    public function test_string_column_with_empty_string_length_is_byte_for_byte_unchanged(): void
    {
        $config = [
            'table_name' => 'items',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'nickname', 'type' => 'string', 'length' => '', 'nullable' => true],
            ],
        ];

        $generator = new FactoryGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Items') . '/ItemsFactory.php';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("'nickname' => fake()->words(2, true),", $content);
    }

    /**
     * A UNIQUE string column with a real length must still produce a value
     * that both fits the column AND stays unique across repeated
     * ->create() calls in the same test — mirrors
     * PhpUnitTestGenerator::buildMaxAwareUniqueStringLiteral()'s exact
     * approach (truncate the cosmetic label, keep the full fast-varying
     * uniqid() token) for the "fits with room to spare" case.
     */
    public function test_unique_string_column_with_generous_length_keeps_label_and_uniqid(): void
    {
        $config = [
            'table_name' => 'trading_partners',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'code', 'type' => 'string', 'length' => '100', 'unique' => true, 'nullable' => false],
            ],
        ];

        $generator = new FactoryGenerator('TradingPartners', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'TradingPartners') . '/TradingPartnersFactory.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("'code' => 'Test Code ' . uniqid(),", $content);
    }

    /**
     * A UNIQUE string column whose length is too short even for the label
     * (varchar(10)) must truncate the label but keep the full 13-char
     * uniqid() token, since that token is what actually guarantees
     * uniqueness between two calls a few microseconds apart.
     */
    public function test_unique_string_column_with_short_length_truncates_label_only(): void
    {
        $config = [
            'table_name' => 'trading_partners',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'code', 'type' => 'string', 'length' => '10', 'unique' => true, 'nullable' => false],
            ],
        ];

        $generator = new FactoryGenerator('TradingPartners', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'TradingPartners') . '/TradingPartnersFactory.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        // 10 chars: no room for "Test Code " (10 chars) + a 13-char uniqid(),
        // so the label truncates to 0 chars ($max - $uidLength is negative,
        // clamped by the < $uidLength branch), leaving only the uniqid() tail.
        $this->assertStringContainsString("'code' => substr(uniqid(), -10),", $content);
    }

    /**
     * varchar(6) and varchar(7) — the shortest real columns the schema
     * actually has (per the task brief) — must still produce syntactically
     * valid, unique, length-safe output.
     */
    public function test_unique_string_column_with_very_short_lengths_still_fits(): void
    {
        $config = [
            'table_name' => 'partner_transaction_types',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'color', 'type' => 'string', 'length' => '7', 'unique' => true, 'nullable' => false],
                ['name' => 'code', 'type' => 'string', 'length' => '6', 'unique' => true, 'nullable' => false],
            ],
        ];

        $generator = new FactoryGenerator('PartnerTransactionTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'PartnerTransactionTypes') . '/PartnerTransactionTypesFactory.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("'color' => substr(uniqid(), -7),", $content);
        $this->assertStringContainsString("'code' => substr(uniqid(), -6),", $content);
    }

    /**
     * A non-unique string column with a very short length still needs a
     * plausible-but-bounded value: Str::limit() truncates whatever
     * fake()->words(2, true) produces down to the column's real length,
     * regardless of how short that length is.
     */
    public function test_non_unique_string_column_with_very_short_length_still_bounds_output(): void
    {
        $config = [
            'table_name' => 'partner_transaction_types',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'code', 'type' => 'string', 'length' => '6', 'nullable' => false],
            ],
        ];

        $generator = new FactoryGenerator('PartnerTransactionTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'PartnerTransactionTypes') . '/PartnerTransactionTypesFactory.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("'code' => Str::limit(fake()->words(2, true), 6, ''),", $content);
    }

    /**
     * Regression coverage for a defect found live 2026-08 on a rental-CRM domain: a `status`
     * column backed by module `constants` (DRAFT/ACTIVE/ENDED/TERMINATED) is a plain varchar to
     * the DB, so it got `Str::limit(fake()->words(2, true), 255, '')` and every factory row was
     * born as e.g. "incidunt sit" — a value no guard, action or state machine accepts. Every
     * fixture started in an impossible state and each project patched its factories by hand.
     *
     * `constants` cannot fix this alone (flat name => value map, no column association — one
     * module had 8 constants across `status` and `deposit_status`); the column's own schema
     * default can, and generalizes to projects that use no constants at all.
     *
     * @see \Blutrixx\GeneratorEngine\Generators\Backend\Factories\FactoryGenerator::buildScalarValue()
     */
    public function test_string_column_with_a_schema_default_uses_it_instead_of_random_words(): void
    {
        $config = [
            'table_name' => 'contracts',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'status', 'type' => 'string', 'length' => '255', 'default' => 'DRAFT'],
                ['name' => 'deposit_status', 'type' => 'string', 'length' => '255', 'default' => 'NONE'],
            ],
        ];

        $generator = new FactoryGenerator('Contracts', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Contracts') . '/ContractsFactory.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("'status' => 'DRAFT',", $content);
        $this->assertStringContainsString("'deposit_status' => 'NONE',", $content);
        $this->assertStringNotContainsString('fake()->words', $content);
    }

    public function test_string_column_without_a_default_keeps_the_generic_literal(): void
    {
        $config = [
            'table_name' => 'items',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'nickname', 'type' => 'string', 'length' => '255'],
                ['name' => 'blank_default', 'type' => 'string', 'length' => '255', 'default' => ''],
            ],
        ];

        $generator = new FactoryGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(PathManager::getBackendModulePath('Core', 'Items') . '/ItemsFactory.php');

        $this->assertStringContainsString("'nickname' => Str::limit(fake()->words(2, true), 255, ''),", $content);
        $this->assertStringContainsString("'blank_default' => Str::limit(fake()->words(2, true), 255, ''),", $content);
    }

    /**
     * A UNIQUE column must never take its schema default: every generated row would carry the
     * same value and collide on the second insert.
     */
    public function test_unique_string_column_ignores_its_default(): void
    {
        $config = [
            'table_name' => 'items',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'code', 'type' => 'string', 'length' => '32', 'unique' => true, 'default' => 'TMP'],
            ],
        ];

        $generator = new FactoryGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(PathManager::getBackendModulePath('Core', 'Items') . '/ItemsFactory.php');

        $this->assertStringNotContainsString("'code' => 'TMP',", $content);
        $this->assertStringContainsString('uniqid()', $content);
    }

    /**
     * `CURRENT_TIMESTAMP` and friends are DB functions the column computes at insert time, not
     * literal values — emitting them as a quoted string would write the text into the column.
     */
    public function test_function_shaped_defaults_are_not_treated_as_literals(): void
    {
        $config = [
            'table_name' => 'items',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'stamped_at_text', 'type' => 'string', 'length' => '64', 'default' => 'CURRENT_TIMESTAMP'],
            ],
        ];

        $generator = new FactoryGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(PathManager::getBackendModulePath('Core', 'Items') . '/ItemsFactory.php');

        $this->assertStringNotContainsString("'CURRENT_TIMESTAMP'", $content);
        $this->assertStringContainsString('fake()->words', $content);
    }

    /**
     * A default longer than the column it belongs to is a broken schema; emitting it would
     * reintroduce exactly the SQLSTATE 22001 this generator's length handling exists to prevent.
     */
    public function test_default_longer_than_the_column_is_ignored(): void
    {
        $config = [
            'table_name' => 'items',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'label', 'type' => 'string', 'length' => '5', 'default' => 'far too long for five'],
            ],
        ];

        $generator = new FactoryGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(PathManager::getBackendModulePath('Core', 'Items') . '/ItemsFactory.php');

        $this->assertStringNotContainsString('far too long', $content);
        $this->assertStringContainsString("Str::limit(fake()->words(2, true), 5, '')", $content);
    }
}
