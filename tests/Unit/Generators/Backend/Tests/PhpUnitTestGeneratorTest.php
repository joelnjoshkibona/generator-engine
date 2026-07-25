<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Tests;

use Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Generator-unit coverage for PhpUnitTestGenerator (v2.10.11), run against a
 * scratch PathManager project root (mirrors MenusJsonGeneratorTest's harness:
 * PathManager::setProjectRoot() in setUp, reset+recursive cleanup in
 * tearDown) using the REAL persisted module.json for SYSTEM_SHELL's
 * LocationTypes module — copied verbatim into
 * tests/Fixtures/LocationTypesModule.json (see that file) rather than a
 * hand-rolled config array, so this exercises the exact config shape
 * IntrospectionToConfig/the builder UI actually produce.
 *
 * LocationTypes has every backend CRUD feature enabled (list/create/view/
 * edit/delete), so test_generate_writes_full_crud_test_for_a_module_with_every_feature_enabled()
 * expects every conditional method PhpUnitTestGenerator can emit, with the
 * right HTTP status per method. test_generate_omits_delete_method_when_delete_feature_is_disabled()
 * flips features.backend.delete (and its frontend counterpart, inert here
 * but toggled for parity with PlaywrightTestGeneratorTest's equivalent case)
 * off and confirms ONLY the delete-specific method disappears — the
 * unconditional DeleteCheck/filter coverage, and every other CRUD method,
 * must remain.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator
 */
class PhpUnitTestGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-phpunit-testgen-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        PathManager::resetModuleSubGroup();
        $this->removeDirectory($this->tmpRoot);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @return array<string, mixed> */
    private function locationTypesConfig(): array
    {
        $path = dirname(__DIR__, 4) . '/Fixtures/LocationTypesModule.json';
        $this->assertFileExists($path, "Expected fixture not found: {$path}");

        $config = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($config, 'LocationTypesModule.json did not decode to an array.');

        return $config;
    }

    private function generatedFilePath(): string
    {
        return PathManager::getBackendModulePath('Core', 'LocationTypes') . '/Tests/LocationTypesCrudTest.php';
    }

    private function assertValidPhpSyntax(string $file): void
    {
        $output = [];
        $exitCode = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, "Generated file has a PHP syntax error:\n" . implode("\n", $output));
    }

    /**
     * Assert a substring appears within the body of a specific generated
     * method (rather than merely anywhere in the file) — locates the method
     * by its declaration, then searches only up to the next
     * "    public function " (or end of file).
     */
    private function assertMethodBodyContains(string $content, string $methodName, string $needle): void
    {
        $start = strpos($content, "function {$methodName}(");
        $this->assertNotFalse($start, "Could not locate function {$methodName}( in generated content.");

        $nextMethodPos = strpos($content, "\n    public function ", $start + 1);
        $body = $nextMethodPos === false ? substr($content, $start) : substr($content, $start, $nextMethodPos - $start);

        $this->assertStringContainsString($needle, $body, "Expected the body of {$methodName}() to contain \"{$needle}\".");
    }

    public function test_generate_writes_full_crud_test_for_a_module_with_every_feature_enabled(): void
    {
        $config = $this->locationTypesConfig();

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = $this->generatedFilePath();
        $this->assertFileExists($path);
        $this->assertValidPhpSyntax($path);

        $content = (string) file_get_contents($path);

        // Namespace / imports / class shape — module-local, not the old central tests/Feature.
        $this->assertStringContainsString('namespace App\Project\Modules\Core\LocationTypes\Tests;', $content);
        $this->assertStringContainsString('use App\Project\Modules\Core\LocationTypes\LocationTypesModel;', $content);
        $this->assertStringContainsString('use App\Project\Modules\Core\Users\Users\UsersModel;', $content);
        $this->assertStringContainsString('class LocationTypesCrudTest extends TestCase', $content);
        $this->assertStringContainsString('protected function createLocationTypeFixture(', $content);

        // Fixture helper must end with ->fresh() — the uuid column's DB-level
        // default (see create_location_types_table's `$table->uuid()->default(...)`)
        // is never read back onto the in-memory model Model::create() returns
        // otherwise, leaving $fixture->uuid null and every uuid-path test
        // (view/edit/delete/delete-check) 404ing on a malformed request URL.
        // Confirmed live via a real make:module smoke test before this was added.
        $this->assertMethodBodyContains($content, 'createLocationTypeFixture', '->fresh()');

        // One method per enabled backend feature, plus the two pipeline-unconditional ones.
        $expectedMethods = [
            'test_can_list_location_types',
            'test_can_create_location_type',
            'test_can_view_location_type',
            'test_can_edit_location_type',
            'test_delete_check_reports_no_blocking_relationships',
            'test_can_delete_location_type',
            'test_can_filter_location_types_list_by_name',
            'test_create_location_type_validation_fails_with_missing_required_field',
        ];
        foreach ($expectedMethods as $method) {
            $this->assertStringContainsString(
                "function {$method}(",
                $content,
                "Expected generated test to contain method {$method}()."
            );
        }

        // HTTP status codes: create=201, validation-failure=422, everything else=200.
        $this->assertMethodBodyContains($content, 'test_can_list_location_types', 'assertStatus(200)');
        $this->assertMethodBodyContains($content, 'test_can_create_location_type', 'assertStatus(201)');
        $this->assertMethodBodyContains($content, 'test_can_view_location_type', 'assertStatus(200)');
        $this->assertMethodBodyContains($content, 'test_can_edit_location_type', 'assertStatus(200)');
        $this->assertMethodBodyContains($content, 'test_delete_check_reports_no_blocking_relationships', 'assertStatus(200)');
        $this->assertMethodBodyContains($content, 'test_can_delete_location_type', 'assertStatus(200)');
        $this->assertMethodBodyContains($content, 'test_can_filter_location_types_list_by_name', 'assertStatus(200)');
        $this->assertMethodBodyContains(
            $content,
            'test_create_location_type_validation_fails_with_missing_required_field',
            'assertStatus(422)'
        );
        $this->assertMethodBodyContains(
            $content,
            'test_create_location_type_validation_fails_with_missing_required_field',
            "assertJsonValidationErrors(['name'])"
        );

        // Routes derived from the module's real table/route base.
        $this->assertStringContainsString('/api/location-types/list', $content);
        $this->assertStringContainsString('/api/location-types/create', $content);
        $this->assertStringContainsString('/api/location-types/{$fixture->uuid}/view', $content);
        $this->assertStringContainsString('/api/location-types/{$fixture->uuid}/edit', $content);
        $this->assertStringContainsString('/api/location-types/{$fixture->uuid}/delete/check', $content);
        $this->assertStringContainsString('/api/location-types/{$fixture->uuid}/delete', $content);
        $this->assertStringContainsString("assertDatabaseHas('location_types'", $content);
        $this->assertStringContainsString("assertSoftDeleted('location_types'", $content);
    }

    public function test_generate_omits_delete_method_when_delete_feature_is_disabled(): void
    {
        $config = $this->locationTypesConfig();
        $config['features']['backend']['delete'] = false;
        $config['features']['frontend']['delete'] = false; // inert for this generator; toggled for parity

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath());

        $this->assertStringNotContainsString(
            'function test_can_delete_location_type(',
            $content,
            'Disabling features.backend.delete should omit the delete test method entirely.'
        );
        $this->assertStringNotContainsString('assertSoftDeleted(', $content);
        $this->assertStringNotContainsString('/delete"', $content);

        // Pipeline-unconditional coverage must remain even with delete disabled.
        $this->assertStringContainsString('function test_delete_check_reports_no_blocking_relationships(', $content);
        $this->assertStringContainsString('/delete/check', $content);
        $this->assertStringContainsString('function test_can_filter_location_types_list_by_name(', $content);

        // Every other feature-gated method stays untouched.
        $this->assertStringContainsString('function test_can_list_location_types(', $content);
        $this->assertStringContainsString('function test_can_create_location_type(', $content);
        $this->assertStringContainsString('function test_can_view_location_type(', $content);
        $this->assertStringContainsString('function test_can_edit_location_type(', $content);
    }

    /**
     * Regression test for a real generated-and-run failure: a module with a
     * self-referential hierarchy FK (module's own `create_item_categories_table`
     * style `parent_id` column, validated by IntrospectionToConfig as
     * `nullable|integer|exists:item_categories,id`) previously got the
     * generic integer-literal treatment in buildFieldValueLiteral() — always
     * hardcoding `1`. Run for real against a freshly migrated + seeded
     * database (php artisan test), this failed test_can_create_item_category
     * and test_can_edit_item_category with a 422 `validation.exists` error,
     * since no row with id 1 can possibly exist in item_categories on its
     * very first insert. Confirmed live before this fix; the config below
     * (table_name "categories", field "parent_id" with
     * "nullable|integer|exists:categories,id") reproduces the same shape
     * with a hand-rolled config, independent of any module.json fixture.
     */
    public function test_self_referential_nullable_foreign_key_uses_null_not_a_hardcoded_id(): void
    {
        $config = [
            'table_name' => 'categories',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'parent_id', 'rules' => 'nullable|integer|exists:categories,id'],
                        ],
                    ],
                    'view' => true,
                    'edit' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'parent_id', 'rules' => 'nullable|integer|exists:categories,id'],
                        ],
                    ],
                    'delete' => true,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Categories', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Categories') . '/Tests/CategoriesCrudTest.php';
        $this->assertFileExists($path);
        $this->assertValidPhpSyntax($path);

        $content = (string) file_get_contents($path);

        // The fixture helper, the create-payload, and the edit-payload must
        // all use null for the self-referential column — never a hardcoded
        // integer, which would trip `exists:categories,id` on a fresh table.
        $this->assertMethodBodyContains($content, 'createCategoryFixture', "'parent_id' => null,");
        $this->assertMethodBodyContains($content, 'test_can_create_category', "'parent_id' => null,");
        $this->assertMethodBodyContains($content, 'test_can_edit_category', "'parent_id' => null,");
    }

    /**
     * A required (non-nullable) foreign key to a *different* module's table
     * (e.g. Items.item_type_id -> item_types) whose related module is NOT
     * registered in PathManager's module registry (the generator's only
     * source of cross-module identity — see
     * PathManager::findModuleByTable()) has no basis for resolving a real
     * parent row, so it must degrade gracefully to the pre-existing generic
     * integer literal `1` — never emit a reference to a model FQCN it isn't
     * actually sure exists. No PathManager::setModuleRegistry() call is made
     * in this test, so the registry is empty and resolution is expected to
     * fail.
     */
    public function test_required_cross_table_foreign_key_falls_back_to_a_literal_one_when_related_module_is_unknown(): void
    {
        $config = [
            'table_name' => 'items',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'item_type_id', 'rules' => 'required|integer|exists:item_types,id'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Items') . '/Tests/ItemsCrudTest.php';
        $content = (string) file_get_contents($path);

        $this->assertMethodBodyContains($content, 'test_can_create_item', "'item_type_id' => 1,");
    }

    /**
     * The fix for the cross-module gap documented above: when the FK's
     * foreign table IS resolvable via PathManager's module registry (the
     * same mechanism DelegationServiceGenerator already uses to reference
     * another module's model FQCN), the generated test must CREATE a real
     * parent row at test-run time via the related module's own factory —
     * `ItemTypesModel::factory()->create()->id` — instead of looking one up
     * with `query()->value('id') ?? 1` (a previous fix attempt that doesn't
     * work: generated tests run under RefreshDatabase, so `item_types` is
     * completely empty at fixture time, the lookup always returns null, and
     * the `?? 1` fallback references a row that doesn't exist either).
     */
    public function test_required_cross_table_foreign_key_resolves_a_real_row_when_related_module_is_registered(): void
    {
        PathManager::setModuleRegistry([
            [
                'name'        => 'ItemTypes',
                'module_type' => 'Core',
                'table_name'  => 'item_types',
            ],
        ]);

        try {
            $config = [
                'table_name' => 'items',
                'features' => [
                    'backend' => [
                        'list' => true,
                        'create' => [
                            'fields' => [
                                ['field' => 'name', 'rules' => 'required|string|max:255'],
                                ['field' => 'item_type_id', 'rules' => 'required|integer|exists:item_types,id'],
                            ],
                        ],
                        'view' => false,
                        'edit' => false,
                        'delete' => false,
                    ],
                    'frontend' => [],
                ],
            ];

            $generator = new PhpUnitTestGenerator('Items', 'Core', $config);
            $this->assertTrue($generator->generate());

            $path = PathManager::getBackendModulePath('Core', 'Items') . '/Tests/ItemsCrudTest.php';
            $this->assertValidPhpSyntax($path);
            $content = (string) file_get_contents($path);

            $this->assertMethodBodyContains(
                $content,
                'test_can_create_item',
                "'item_type_id' => \\App\\Project\\Modules\\Core\\ItemTypes\\ItemTypesModel::factory()->create()->id,"
            );
        } finally {
            // Reset the registry so this test's state can't bleed into any
            // other test in the suite.
            PathManager::setModuleRegistry([]);
        }
    }

    /**
     * Self-referential and nullable cross-module FKs must still take the
     * `null` path even now that a registry entry for the related table
     * exists — the registry-resolution branch added for the required
     * cross-module case must never run before, or instead of, the existing
     * self-referential/nullable checks.
     */
    public function test_self_referential_and_nullable_fk_handling_is_unchanged_when_a_registry_is_present(): void
    {
        PathManager::setModuleRegistry([
            [
                'name'        => 'Categories',
                'module_type' => 'Core',
                'table_name'  => 'categories',
            ],
        ]);

        try {
            $config = [
                'table_name' => 'categories',
                'features' => [
                    'backend' => [
                        'list' => true,
                        'create' => [
                            'fields' => [
                                ['field' => 'name', 'rules' => 'required|string|max:255'],
                                ['field' => 'parent_id', 'rules' => 'nullable|integer|exists:categories,id'],
                            ],
                        ],
                        'view' => false,
                        'edit' => false,
                        'delete' => false,
                    ],
                    'frontend' => [],
                ],
            ];

            $generator = new PhpUnitTestGenerator('Categories', 'Core', $config);
            $this->assertTrue($generator->generate());

            $path = PathManager::getBackendModulePath('Core', 'Categories') . '/Tests/CategoriesCrudTest.php';
            $content = (string) file_get_contents($path);

            $this->assertMethodBodyContains($content, 'test_can_create_category', "'parent_id' => null,");
        } finally {
            PathManager::setModuleRegistry([]);
        }
    }
}
