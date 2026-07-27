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
        $body = $this->extractMethodBody($content, $methodName);

        $this->assertStringContainsString($needle, $body, "Expected the body of {$methodName}() to contain \"{$needle}\".");
    }

    /**
     * Negative counterpart to assertMethodBodyContains() — scoped to a single
     * method's body rather than the whole file, since some of the fixture
     * literals asserted against here (e.g. a file column's
     * `RelatedModel::factory()->create()->id`) are deliberately correct
     * INSIDE the fixture helper (a direct Model::create() call, bypassing
     * HTTP validation) while being wrong inside an HTTP request payload
     * method — a whole-file assertStringNotContainsString would produce a
     * false failure by also matching the fixture helper.
     */
    private function assertMethodBodyNotContains(string $content, string $methodName, string $needle): void
    {
        $body = $this->extractMethodBody($content, $methodName);

        $this->assertStringNotContainsString($needle, $body, "Expected the body of {$methodName}() NOT to contain \"{$needle}\".");
    }

    /**
     * Locates a method by its declaration and returns everything up to the
     * next "    public function " (or end of file).
     */
    private function extractMethodBody(string $content, string $methodName): string
    {
        $start = strpos($content, "function {$methodName}(");
        $this->assertNotFalse($start, "Could not locate function {$methodName}( in generated content.");

        $nextMethodPos = strpos($content, "\n    public function ", $start + 1);

        return $nextMethodPos === false ? substr($content, $start) : substr($content, $start, $nextMethodPos - $start);
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
            // Bulk-action coverage: gated on list alone (route.stub registers
            // POST /bulk-action unconditionally whenever list is enabled),
            // NOT on bulk_actions being configured — LocationTypes has none
            // configured, so only the config-independent validation/403
            // tests are expected here, not the ids-mode happy path.
            'test_bulk_action_validation_fails_with_missing_ids',
            'test_cannot_bulk_action_without_permission',
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

        // Bulk-action route (route.stub's second line, unconditional
        // whenever list is enabled): validation and permission coverage use
        // the real route path; LocationTypes has no bulk_actions configured
        // so no concrete action key is asserted here.
        $this->assertStringContainsString('/api/location-types/bulk-action', $content);
        $this->assertMethodBodyContains($content, 'test_bulk_action_validation_fails_with_missing_ids', 'assertStatus(422)');
        $this->assertMethodBodyContains($content, 'test_cannot_bulk_action_without_permission', 'assertStatus(403)');
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

    /**
     * Regression test for a real generated-and-run failure: since v2.10.17
     * the backend generators support file_columns-marked upload columns
     * (e.g. 'image_media_id'), whose generated service validates them with a
     * 'file' rule and converts the uploaded file into a Media row at
     * runtime. PhpUnitTestGenerator had no knowledge of file_columns at all,
     * so it fell through to the ordinary integer/FK literal `1` for these
     * columns — confirmed live: 2 of 40 generated tests failed with
     * `validation.file` against a real database. The generated create-test
     * payload must instead submit a fake UploadedFile.
     */
    public function test_file_column_uses_a_fake_upload_literal_in_the_create_payload(): void
    {
        $config = [
            'table_name' => 'item_images',
            'file_columns' => ['image_media_id'],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'ItemImages') . '/Tests/ItemImagesCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertMethodBodyContains(
            $content,
            'test_can_create_item_image',
            "'image_media_id' => \\Illuminate\\Http\\UploadedFile::fake()->image('test.jpg'),"
        );
    }

    /**
     * The file_columns check must win over the FK/integer inference even
     * though the column is FK-SHAPED — an unsignedBigInteger with an
     * `exists:media,id` rule, exactly like any other required cross-module
     * FK. Without the file-column check running first, this column would
     * otherwise be claimed by the same `exists:` branch that resolves a real
     * parent row via a factory (or falls back to the literal `1`) — neither
     * of which is valid for an HTTP request payload that must submit an
     * actual file for the 'file' validation rule to pass.
     */
    public function test_file_column_check_wins_over_fk_shaped_exists_rule(): void
    {
        PathManager::setModuleRegistry([
            [
                'name'        => 'Media',
                'module_type' => 'Core',
                'table_name'  => 'media',
            ],
        ]);

        try {
            $config = [
                'table_name' => 'item_images',
                'file_columns' => ['image_media_id'],
                'features' => [
                    'backend' => [
                        'list' => true,
                        'create' => [
                            'fields' => [
                                ['field' => 'name', 'rules' => 'required|string|max:255'],
                                ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                            ],
                        ],
                        'view' => false,
                        'edit' => false,
                        'delete' => false,
                    ],
                    'frontend' => [],
                ],
            ];

            $generator = new PhpUnitTestGenerator('ItemImages', 'Core', $config);
            $this->assertTrue($generator->generate());

            $path = PathManager::getBackendModulePath('Core', 'ItemImages') . '/Tests/ItemImagesCrudTest.php';
            $content = (string) file_get_contents($path);

            $this->assertMethodBodyContains(
                $content,
                'test_can_create_item_image',
                "\\Illuminate\\Http\\UploadedFile::fake()"
            );
            // Scoped to the create-test method body specifically: the
            // fixture helper (createItemImageFixture(), used by direct
            // Model::create() calls, not HTTP requests) legitimately DOES
            // emit the factory-create literal for this same column — that's
            // the ordinary, correct cross-module-FK behavior for a column
            // that bypasses HTTP validation entirely.
            $this->assertMethodBodyNotContains(
                $content,
                'test_can_create_item_image',
                "\\App\\Project\\Modules\\Core\\Media\\MediaModel::factory()->create()->id"
            );
            $this->assertMethodBodyNotContains($content, 'test_can_create_item_image', "'image_media_id' => 1,");
        } finally {
            PathManager::setModuleRegistry([]);
        }
    }

    /**
     * The broken exact-match response assertion this bug would otherwise
     * trade for `validation.file`: the backend replaces the uploaded file
     * with the newly created media row's integer id at runtime (see
     * BaseServiceGenerator::generateFileColumnUploads()), so
     * `->assertJsonPath('data.image_media_id', $payload['image_media_id'])`
     * can never hold for a file column — $payload['image_media_id'] is an
     * UploadedFile instance, never an id. The generated test must omit that
     * exact-match assertion for file columns and assert the conversion
     * happened (an integer id came back) instead.
     */
    public function test_file_column_response_assertion_is_adjusted_not_left_broken(): void
    {
        $config = [
            'table_name' => 'item_images',
            'file_columns' => ['image_media_id'],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'ItemImages') . '/Tests/ItemImagesCrudTest.php';
        $content = (string) file_get_contents($path);

        $this->assertMethodBodyContains(
            $content,
            'test_can_create_item_image',
            "\$this->assertIsInt(\$response->json('data.image_media_id'));"
        );
        $this->assertStringNotContainsString(
            "->assertJsonPath('data.image_media_id', \$payload['image_media_id'])",
            $content
        );
    }

    /**
     * Non-file columns must be completely unaffected by the file_columns
     * check — an ordinary integer/FK column with no file_columns entry at
     * all still gets the pre-existing literal `1` treatment (or the
     * cross-module factory-create literal, covered by the tests above this
     * one), never a fake-upload literal.
     */
    public function test_non_file_columns_are_unaffected_by_the_file_column_check(): void
    {
        $config = [
            'table_name' => 'items',
            'file_columns' => [],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'quantity', 'rules' => 'required|integer'],
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

        $this->assertMethodBodyContains($content, 'test_can_create_item', "'quantity' => 1,");
        $this->assertStringNotContainsString('UploadedFile::fake()', $content);
    }

    /**
     * Regression test for the remaining half of the file_columns bug,
     * confirmed by a live run: postJson()/putJson() JSON-encode the request
     * body, which destroys the UploadedFile instance entirely — and, being
     * array-valued, drags every SIBLING field down with it, since an
     * UploadedFile can't survive json_encode(). The live failure showed a
     * request with content-type: application/json, the upload gone, and a
     * completely unrelated field (item_id) failing validation.required as
     * collateral damage. A module carrying a file_columns entry must instead
     * issue a real multipart request via post() for its create test.
     */
    /**
     * Regression: a multipart request issued via post() sends no
     * `Accept: application/json` header, unlike postJson(). Laravel then
     * treats a validation failure as a browser request and REDIRECTS (302)
     * instead of returning a JSON 422 — so every generated validation test
     * on a file-carrying module failed with "Expected 422 but received 302"
     * while validation had in fact fired correctly. Found by running the
     * generated suite against a real app; the package's own unit tests were
     * green throughout. Every multipart call site must declare JSON
     * acceptance explicitly.
     */
    public function test_multipart_requests_declare_json_acceptance(): void
    {
        $generator = new \ReflectionClass(\Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator::class);
        $source = file_get_contents($generator->getFileName());

        // Locate every generated `$this->post(` call site emitted by this generator.
        preg_match_all('/\\$this->post\\((.*?)\\)"/', $source, $matches);

        $this->assertNotEmpty($matches[0], 'Expected the generator to emit multipart post() call sites.');

        foreach ($matches[0] as $callSite) {
            $this->assertStringContainsString(
                "'Accept' => 'application/json'",
                $callSite,
                "Multipart call site is missing the Accept header and will 302 on validation failure: {$callSite}"
            );
        }
    }

    public function test_file_carrying_module_create_test_uses_post_not_post_json(): void
    {
        $config = [
            'table_name' => 'item_images',
            'file_columns' => ['image_media_id'],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                            ['field' => 'is_primary', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'ItemImages') . '/Tests/ItemImagesCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertMethodBodyContains($content, 'test_can_create_item_image', "\$this->post('/api/item-images/create', \$payload, ['Accept' => 'application/json'])");
        $this->assertMethodBodyNotContains($content, 'test_can_create_item_image', 'postJson(');
    }

    /**
     * Edit-side counterpart. The generated route for edit is registered as
     * PUT (see the module's own generated Routes/api.php), but PHP never
     * populates $_FILES for a PUT request regardless of framework — a real
     * multipart body can only be sent via POST. This mirrors
     * BaseComponentGenerator::generateSubmitCall()'s frontend fix (v2.10.17)
     * exactly: POST the multipart body with a `_method: 'PUT'` override
     * field, which Laravel's method-override spoofing (active for every
     * request, including in-process test requests, via
     * Request::enableHttpMethodParameterOverride()) resolves back to the
     * registered PUT route — the same mechanism Blade's @method('PUT')
     * directive relies on for native HTML file-upload forms.
     */
    public function test_file_carrying_module_edit_test_routes_to_put_via_method_override(): void
    {
        $config = [
            'table_name' => 'item_images',
            'file_columns' => ['image_media_id'],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                            ['field' => 'is_primary', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                            ['field' => 'is_primary', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'ItemImages') . '/Tests/ItemImagesCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertMethodBodyContains(
            $content,
            'test_can_edit_item_image',
            "\$this->post(\"/api/item-images/{\$fixture->uuid}/edit\", \$payload + ['_method' => 'PUT'], ['Accept' => 'application/json'])"
        );
        $this->assertMethodBodyNotContains($content, 'test_can_edit_item_image', 'putJson(');
    }

    /**
     * The boolean/string requirement mirrors the frontend's own
     * generateSubmitCall() multipart fix (v2.10.17): "Booleans must be sent
     * as 1/0 in FormData" (see SYSTEM_SHELL's own module-pattern docs). A
     * real multipart request carries every field as a string, so a raw PHP
     * `true` literal in the payload array is never what the underlying HTTP
     * layer actually transmits. Only the multipart (file-carrying) path
     * needs this — the plain postJson()/putJson() path already worked
     * correctly with a native boolean and must stay untouched (see the
     * non-file-column test below), and the fixture helper's direct
     * Model::create() call must still receive a real boolean regardless.
     */
    public function test_file_carrying_module_converts_booleans_to_strings_in_the_multipart_payload(): void
    {
        $config = [
            'table_name' => 'item_images',
            'file_columns' => ['image_media_id'],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                            ['field' => 'is_primary', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'ItemImages') . '/Tests/ItemImagesCrudTest.php';
        $content = (string) file_get_contents($path);

        $this->assertMethodBodyContains($content, 'test_can_create_item_image', "'is_primary' => '1',");
        $this->assertMethodBodyNotContains($content, 'test_can_create_item_image', "'is_primary' => true,");

        // The fixture helper bypasses HTTP entirely (direct Model::create()),
        // so it must still receive a real PHP boolean, not the multipart
        // string conversion.
        $this->assertMethodBodyContains($content, 'createItemImageFixture', "'is_primary' => true,");
    }

    /**
     * Byte-for-byte regression guard: a module with NO file_columns entry at
     * all must keep emitting postJson()/putJson() exactly as before this fix
     * — the multipart/post()/_method-override machinery must be fully inert
     * for it, and its boolean literal must stay a native `true`, not the
     * multipart-only '1' string conversion.
     */
    public function test_module_without_file_column_still_uses_post_json_and_put_json(): void
    {
        $config = [
            'table_name' => 'items',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'is_active', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'is_active', 'rules' => 'required|boolean'],
                        ],
                    ],
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

        $this->assertMethodBodyContains($content, 'test_can_create_item', "\$this->postJson('/api/items/create', \$payload)");
        $this->assertMethodBodyContains($content, 'test_can_edit_item', '$this->putJson("/api/items/{$fixture->uuid}/edit", $payload)');
        $this->assertMethodBodyContains($content, 'test_can_create_item', "'is_active' => true,");
        $this->assertStringNotContainsString('_method', $content);
        $this->assertStringNotContainsString("\$this->post(", $content);
    }

    // ─── Mandatory byte-for-byte regression (no enabled backend feature) ──

    /**
     * The strictest form of the "must not change output for a module that
     * doesn't opt into any of the new coverage" guarantee: a module with
     * ZERO enabled backend CRUD features generates ONLY the three
     * pipeline-unconditional methods (fixture helper + deleteCheck +
     * activity + filter), because every gap 1-10 method AND every route-family
     * 2-7 method (export/import-template/bulk/actions/delegations/splash) is
     * gated on at least one CRUD feature or its own dedicated config flag
     * being enabled/present — none of which this config sets. Compared with
     * assertSame() against the exact literal output (not merely
     * assertStringNotContainsString for each new method individually), so
     * any accidental unconditional addition to generate() — not just the
     * ones anticipated here — trips this test.
     *
     * The activity-history method IS present in the expected literal below
     * (unlike every gap/route-family method) because RoutesGenerator emits
     * that route completely unconditionally for every module regardless of
     * config — see buildActivityTestMethod()'s docblock — exactly like the
     * pre-existing deleteCheck/filter coverage above it. Route families
     * 2-7 (export, import, bulk actions, custom actions, delegations,
     * splash) all remain absent here, proving each one's own emission gate
     * (mirroring RoutesGenerator's exact condition) is truly conditional.
     */
    public function test_generate_is_byte_for_byte_unchanged_for_a_module_with_no_enabled_backend_features(): void
    {
        $config = [
            'table_name' => 'widgets',
            'has_soft_deletes' => false,
            'has_creator_updater' => false,
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
            'columns' => [],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $expected = <<<PHP
<?php

namespace App\Project\Modules\Core\Widgets\Tests;

use App\Project\Modules\Core\Widgets\WidgetsModel;
use App\Project\Modules\Core\Users\Users\UsersModel;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Generated feature coverage for the Widgets module.
 *
 * Authenticates as the seeded "developer" user and exercises the module's
 * enabled HTTP surface end to end. Fixtures are built via direct
 * WidgetsModel::create() calls (no Eloquent factory is assumed to
 * exist for generated modules) through the fixture helper below.
 */
class WidgetsCrudTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(UsersModel::find(UsersModel::DEVELOPER));
    }

    protected function createWidgetFixture(array \$overrides = []): WidgetsModel
    {
        return WidgetsModel::create(array_merge([
            'created_by_id' => UsersModel::DEVELOPER,
        ], \$overrides))->fresh();
    }

    public function test_delete_check_reports_no_blocking_relationships(): void
    {
        \$fixture = \$this->createWidgetFixture();

        \$response = \$this->getJson("/api/widgets/{\$fixture->uuid}/delete/check");

        \$response->assertStatus(200)
            ->assertJsonPath('data.can_delete', true);
    }

    public function test_can_view_widget_activity_history(): void
    {
        \$fixture = \$this->createWidgetFixture();

        \$response = \$this->getJson("/api/widgets/{\$fixture->uuid}/activity");

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);
    }

    public function test_can_filter_widgets_list_by_id(): void
    {
        \$fixture = \$this->createWidgetFixture();

        \$response = \$this->getJson('/api/widgets/list?' . http_build_query([
            'filters' => [
                'id' => ['operator' => 'eq', 'value' => \$fixture->id],
            ],
        ]));

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);

        \$values = collect(\$response->json('data.data'))->pluck('id');
        \$this->assertTrue(\$values->contains(\$fixture->id));
    }
}

PHP;

        $this->assertSame($expected, $content);
    }

    /**
     * Broader companion to the strict zero-feature regression above: a
     * module with every CRUD feature enabled (so gap 1's authorization
     * coverage, and gap 3b's overlength-on-`max:` coverage, both correctly
     * fire — neither is gated on the six schema-shape flags below) but NONE
     * of no soft deletes / no unique column / no FK column / no file column
     * / no enum column / no audit columns must still omit every gap 2-10
     * method that IS gated on one of those flags. This is the test that
     * proves those six gates are truly conditional, independent of gap 1's
     * necessarily broader "any CRUD feature enabled" scope (see this
     * class's own top-of-file note and the task summary for why a single
     * literal byte-identical assertion can't cover both at once).
     */
    public function test_generate_omits_every_schema_shape_gated_method_for_a_module_with_none_of_those_shapes(): void
    {
        $config = [
            'table_name' => 'widgets',
            'has_soft_deletes' => false,
            'has_creator_updater' => false,
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                        ],
                    ],
                    'view' => true,
                    'edit' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                        ],
                    ],
                    'delete' => true,
                ],
                'frontend' => [],
            ],
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        // Gap 1 (feature-gated, not schema-gated) correctly fires.
        $this->assertStringContainsString('function test_cannot_list_widgets_without_permission(', $content);
        $this->assertStringContainsString('function test_cannot_create_widget_without_permission(', $content);
        $this->assertStringContainsString('function test_cannot_view_widget_without_permission(', $content);
        $this->assertStringContainsString('function test_cannot_edit_widget_without_permission(', $content);
        $this->assertStringContainsString('function test_cannot_delete_widget_without_permission(', $content);

        // Gap 3b (every string field carries an implicit max:255 unless
        // explicitly nullable, so this is realistically almost always
        // present too — it is gated on the field shape, not the six
        // module-level flags this test is about) correctly fires.
        $this->assertStringContainsString('function test_create_widget_validation_fails_with_overlength_name(', $content);

        // Every gap gated on one of the six excluded schema shapes must be
        // absent: no unique column (gap 3a), no FK column (gap 3c), no
        // enum column (gap 10), no decimal/scale column (gap 9), no soft
        // deletes (gap 5), no file column (gap 6), no audit columns (gap 4).
        $this->assertStringNotContainsString('validation_fails_with_duplicate_', $content);
        $this->assertStringNotContainsString('validation_fails_with_nonexistent_', $content);
        $this->assertStringNotContainsString('_enum_value(', $content);
        $this->assertStringNotContainsString('decimal_precision_intact', $content);
        $this->assertStringNotContainsString('is_excluded_from_widgets_list', $content);
        $this->assertStringNotContainsString('without_reuploading_', $content);
        // Scoped to the create/edit method bodies — the fixture helper's own
        // 'created_by_id' => UsersModel::DEVELOPER line is a separate,
        // pre-existing, unconditional field this gap must not be judged
        // against.
        $this->assertMethodBodyNotContains($content, 'test_can_create_widget', 'data.created_by_id');
        $this->assertMethodBodyNotContains($content, 'test_can_edit_widget', 'data.updated_by_id');
    }

    // ─── Gap 1: authorization coverage ─────────────────────────────────────

    public function test_generate_emits_one_forbidden_test_per_enabled_crud_feature_sharing_one_helper(): void
    {
        $config = $this->locationTypesConfig();

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath());

        $this->assertStringContainsString('private function actingAsUserWithoutPermission(): UsersModel', $content);
        $this->assertStringContainsString(
            "\\App\\Project\\Modules\\Core\\Access\\Roles\\RolesModel::factory()->create(['permissions' => []]);",
            $content
        );
        $this->assertStringContainsString(
            "\\App\\Project\\Modules\\Core\\Users\\UserLocations\\UserLocationsModel::create([",
            $content
        );

        foreach (['list', 'create', 'view', 'edit', 'delete'] as $feature) {
            $method = "test_cannot_{$feature}_" . ($feature === 'list' ? 'location_types' : 'location_type') . '_without_permission';
            $this->assertStringContainsString("function {$method}(", $content, "Expected forbidden coverage for '{$feature}'.");
            $this->assertMethodBodyContains($content, $method, '$this->actingAsUserWithoutPermission();');
            $this->assertMethodBodyContains($content, $method, 'assertStatus(403);');
        }
    }

    public function test_generate_omits_forbidden_test_for_a_feature_that_is_disabled(): void
    {
        $config = $this->locationTypesConfig();
        $config['features']['backend']['delete'] = false;

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath());

        $this->assertStringNotContainsString('function test_cannot_delete_location_type_without_permission(', $content);
        // The other four features stay covered.
        $this->assertStringContainsString('function test_cannot_list_location_types_without_permission(', $content);
        $this->assertStringContainsString('function test_cannot_create_location_type_without_permission(', $content);
        $this->assertStringContainsString('function test_cannot_view_location_type_without_permission(', $content);
        $this->assertStringContainsString('function test_cannot_edit_location_type_without_permission(', $content);
    }

    // ─── Gap 3: extra validation coverage ──────────────────────────────────

    public function test_generate_emits_duplicate_and_overlength_validation_tests_for_a_unique_max_constrained_field(): void
    {
        $config = $this->locationTypesConfig();

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath());

        $this->assertMethodBodyContains($content, 'test_create_location_type_validation_fails_with_duplicate_name', "\$payload['name'] = \$fixture->name;");
        $this->assertMethodBodyContains($content, 'test_create_location_type_validation_fails_with_duplicate_name', 'assertStatus(422)');

        $this->assertMethodBodyContains($content, 'test_create_location_type_validation_fails_with_overlength_name', "str_repeat('a', 256)");
        $this->assertMethodBodyContains($content, 'test_create_location_type_validation_fails_with_overlength_name', 'assertStatus(422)');
    }

    public function test_generate_omits_duplicate_and_overlength_tests_when_no_field_carries_those_rules(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'quantity', 'rules' => 'required|integer'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCrudTest.php';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString('validation_fails_with_duplicate_', $content);
        $this->assertStringNotContainsString('validation_fails_with_overlength_', $content);
    }

    public function test_generate_emits_nonexistent_fk_validation_test_for_a_required_cross_module_fk(): void
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
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertMethodBodyContains(
            $content,
            'test_create_item_validation_fails_with_nonexistent_item_type_id',
            "\$payload['item_type_id'] = 999999999;"
        );
        $this->assertMethodBodyContains(
            $content,
            'test_create_item_validation_fails_with_nonexistent_item_type_id',
            'assertStatus(422)'
        );
    }

    public function test_generate_omits_nonexistent_fk_validation_test_for_a_nullable_self_referential_fk(): void
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

        $this->assertStringNotContainsString('validation_fails_with_nonexistent_', $content);
    }

    // ─── Gap 4: audit-column coverage ──────────────────────────────────────

    public function test_generate_asserts_audit_columns_when_module_has_creator_updater(): void
    {
        $config = $this->locationTypesConfig();
        // LocationTypesModule.json carries no explicit has_creator_updater
        // key, so ModuleConfigContract::hasCreatorUpdater() already defaults
        // it to true — asserted explicitly here so this test documents that
        // assumption rather than relying on it silently.
        $this->assertArrayNotHasKey('has_creator_updater', $config);

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath());

        $this->assertMethodBodyContains($content, 'test_can_create_location_type', "->assertJsonPath('data.created_by_id', (int) UsersModel::DEVELOPER)");
        $this->assertMethodBodyContains($content, 'test_can_edit_location_type', "->assertJsonPath('data.updated_by_id', (int) UsersModel::DEVELOPER)");
    }

    public function test_generate_omits_audit_column_assertions_when_module_has_no_creator_updater(): void
    {
        $config = $this->locationTypesConfig();
        $config['has_creator_updater'] = false;

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath());

        // Scoped to the two methods' bodies specifically — the fixture
        // helper's own 'created_by_id' => UsersModel::DEVELOPER line is a
        // separate, pre-existing, UNCONDITIONAL field (not this gap's
        // concern) that a whole-file assertStringNotContainsString would
        // wrongly trip on.
        $this->assertMethodBodyNotContains($content, 'test_can_create_location_type', 'data.created_by_id');
        $this->assertMethodBodyNotContains($content, 'test_can_edit_location_type', 'data.updated_by_id');
    }

    // ─── Gap 5: soft-deleted row excluded from list ────────────────────────

    public function test_generate_emits_soft_delete_list_exclusion_test_when_soft_deletes_delete_and_list_are_all_enabled(): void
    {
        $config = $this->locationTypesConfig();
        $config['has_soft_deletes'] = true;

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath());

        $this->assertStringContainsString('function test_soft_deleted_location_type_is_excluded_from_location_types_list(', $content);
        $this->assertMethodBodyContains(
            $content,
            'test_soft_deleted_location_type_is_excluded_from_location_types_list',
            'assertFalse($uuids->contains($fixture->uuid));'
        );
    }

    public function test_generate_omits_soft_delete_list_exclusion_test_when_module_has_no_soft_deletes(): void
    {
        // locationTypesConfig() has no has_soft_deletes key at all, and
        // ModuleConfigContract::hasSoftDeletes() defaults an absent key to
        // false, so the un-mutated fixture already covers this case.
        $config = $this->locationTypesConfig();

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath());

        $this->assertStringNotContainsString('is_excluded_from_location_types_list', $content);
    }

    // ─── Gap 6: file-edit-without-reupload coverage ────────────────────────

    public function test_generate_emits_file_edit_without_reupload_test_when_edit_has_a_file_and_a_non_file_field(): void
    {
        $config = [
            'table_name' => 'item_images',
            'file_columns' => ['image_media_id'],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                            ['field' => 'is_primary', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'image_media_id', 'rules' => 'nullable|integer|exists:media,id'],
                            ['field' => 'is_primary', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'ItemImages') . '/Tests/ItemImagesCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('function test_can_edit_item_image_without_reuploading_image_media_id(', $content);
        $this->assertMethodBodyContains($content, 'test_can_edit_item_image_without_reuploading_image_media_id', '$originalFileValue = $fixture->image_media_id;');
        $this->assertMethodBodyContains($content, 'test_can_edit_item_image_without_reuploading_image_media_id', "\$this->putJson(\"/api/item-images/{\$fixture->uuid}/edit\", \$payload)");
        $this->assertMethodBodyNotContains($content, 'test_can_edit_item_image_without_reuploading_image_media_id', "'image_media_id' =>");
    }

    public function test_generate_omits_file_edit_without_reupload_test_when_module_has_no_file_column(): void
    {
        $config = [
            'table_name' => 'items',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                        ],
                    ],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Items') . '/Tests/ItemsCrudTest.php';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString('without_reuploading_', $content);
    }

    // ─── Gap 9: decimal precision/scale round-trip coverage ───────────────

    public function test_generate_emits_decimal_precision_test_when_a_create_field_column_carries_scale(): void
    {
        $config = [
            'table_name' => 'item_prices',
            'columns' => [
                ['name' => 'amount', 'type' => 'decimal', 'precision' => 10, 'scale' => 2],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'amount', 'rules' => 'required|numeric'],
                        ],
                    ],
                    'view' => true,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('ItemPrices', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'ItemPrices') . '/Tests/ItemPricesCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('function test_can_create_and_view_item_price_with_decimal_precision_intact(', $content);
        $this->assertMethodBodyContains($content, 'test_can_create_and_view_item_price_with_decimal_precision_intact', "\$payload['amount'] = '1.55';");
        $this->assertMethodBodyContains($content, 'test_can_create_and_view_item_price_with_decimal_precision_intact', 'assertEqualsWithDelta(1.55,');
    }

    public function test_generate_omits_decimal_precision_test_when_no_column_carries_scale(): void
    {
        $config = [
            'table_name' => 'items',
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
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

        $this->assertStringNotContainsString('decimal_precision_intact', $content);
    }

    // ─── Gap 10: enum validation coverage ──────────────────────────────────

    public function test_generate_emits_accept_and_reject_enum_tests_when_a_create_field_column_carries_enum_values(): void
    {
        $config = [
            'table_name' => 'orders',
            'columns' => [
                ['name' => 'status', 'type' => 'enum', 'enum_values' => ['pending', 'active', 'cancelled']],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'status', 'rules' => 'required|string'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Orders', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Orders') . '/Tests/OrdersCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('function test_create_order_accepts_a_valid_status_enum_value(', $content);
        $this->assertMethodBodyContains($content, 'test_create_order_accepts_a_valid_status_enum_value', "\$payload['status'] = 'pending';");
        $this->assertMethodBodyContains($content, 'test_create_order_accepts_a_valid_status_enum_value', 'assertStatus(201);');

        $this->assertStringContainsString('function test_create_order_rejects_an_invalid_status_enum_value(', $content);
        $this->assertMethodBodyContains($content, 'test_create_order_rejects_an_invalid_status_enum_value', 'assertStatus(422)');
    }

    public function test_generate_omits_enum_tests_when_no_column_carries_enum_values(): void
    {
        $config = [
            'table_name' => 'orders',
            'columns' => [
                ['name' => 'status', 'type' => 'string'],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'status', 'rules' => 'required|string|max:255'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Orders', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Orders') . '/Tests/OrdersCrudTest.php';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString('_enum_value(', $content);
    }

    // ─── Route family 1: activity-history coverage ─────────────────────────

    public function test_generate_emits_activity_history_test_unconditionally(): void
    {
        // Zero enabled backend CRUD features — activity coverage must still
        // fire, since RoutesGenerator emits that route unconditionally.
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('function test_can_view_widget_activity_history(', $content);
        $this->assertMethodBodyContains($content, 'test_can_view_widget_activity_history', '/api/widgets/{$fixture->uuid}/activity');
        $this->assertMethodBodyContains($content, 'test_can_view_widget_activity_history', 'assertStatus(200)');
    }

    // ─── Route family 2: export coverage ────────────────────────────────────

    public function test_generate_emits_export_test_when_list_export_is_enabled(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => ['export' => true],
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('function test_can_export_widgets_list(', $content);
        // Mirrors RoutesGenerator's own manually-concatenated trailing "s".
        $this->assertMethodBodyContains($content, 'test_can_export_widgets_list', "/api/widgets" . "s/list/export");
        $this->assertMethodBodyContains($content, 'test_can_export_widgets_list', 'assertStatus(200)');
    }

    public function test_generate_omits_export_test_when_list_export_is_not_enabled(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCrudTest.php';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString('function test_can_export_widgets_list(', $content);
        $this->assertStringNotContainsString('list/export', $content);
    }

    // ─── Route family 3: import-template coverage ──────────────────────────

    public function test_generate_emits_import_template_test_when_list_import_is_enabled(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => ['import' => true],
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('function test_can_download_widgets_import_template(', $content);
        $this->assertMethodBodyContains($content, 'test_can_download_widgets_import_template', "/api/widgets" . "s/import/template");
        $this->assertMethodBodyContains($content, 'test_can_download_widgets_import_template', 'assertStatus(200)');

        // The actual file-upload POST /widgets/import route is deliberately
        // left untested — no test method name referencing a plain "import"
        // (as opposed to "import_template") should be emitted.
        $this->assertStringNotContainsString('function test_can_import_widgets(', $content);
    }

    public function test_generate_omits_import_template_test_when_list_import_is_not_enabled(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCrudTest.php';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString('function test_can_download_widgets_import_template(', $content);
        $this->assertStringNotContainsString('import/template', $content);
    }

    // ─── Route family 7: createSplash/editSplash coverage ─────────────────

    public function test_generate_emits_splash_tests_when_constants_are_declared_and_splash_features_enabled(): void
    {
        $config = [
            'table_name' => 'widgets',
            'constants' => ['SOME_CONSTANT' => 1],
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                    'createSplash' => ['splashData' => []],
                    'editSplash' => ['splashData' => []],
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('function test_can_view_widget_create_splash(', $content);
        $this->assertMethodBodyContains($content, 'test_can_view_widget_create_splash', '/api/widgets/create/splash');
        $this->assertMethodBodyContains($content, 'test_can_view_widget_create_splash', 'assertStatus(200)');

        $this->assertStringContainsString('function test_can_view_widget_edit_splash(', $content);
        $this->assertMethodBodyContains($content, 'test_can_view_widget_edit_splash', '/api/widgets/edit/splash');
        $this->assertMethodBodyContains($content, 'test_can_view_widget_edit_splash', 'assertStatus(200)');
    }

    public function test_generate_omits_splash_tests_when_constants_are_declared_but_splash_features_are_not_enabled(): void
    {
        // constants present, but neither createSplash nor editSplash key is
        // set in features.backend — RoutesGenerator requires BOTH the
        // constants gate AND the feature's own key to be present.
        $config = [
            'table_name' => 'widgets',
            'constants' => ['SOME_CONSTANT' => 1],
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCrudTest.php';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString('create_splash', $content);
        $this->assertStringNotContainsString('edit_splash', $content);
        $this->assertStringNotContainsString('/splash', $content);
    }

    public function test_generate_omits_splash_tests_when_splash_features_enabled_but_no_constants_declared(): void
    {
        // features.backend.createSplash/editSplash both set, but no
        // 'constants' key at all — RoutesGenerator's $hasSplash gate is
        // false, so no splash route (and therefore no splash test) exists.
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                    'createSplash' => ['splashData' => []],
                    'editSplash' => ['splashData' => []],
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCrudTest.php';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString('create_splash', $content);
        $this->assertStringNotContainsString('edit_splash', $content);
        $this->assertStringNotContainsString('/splash', $content);
    }

    // ─── Route families 5-6: actions[] contract + delegation coverage ─────

    /**
     * Custom actions[] emit an explicit "Add your custom logic here" TODO
     * service body (Features/action/service.stub) that unconditionally
     * returns a 200 regardless of $data/urlParams — no real behavioral
     * assertion is possible, but the permission gate (403 without it) and
     * "never a hard 5xx" contract ARE guaranteed by the framework/routing
     * layer today, independent of that placeholder body (see
     * buildActionContractTestMethods()'s docblock). Delegation `list` needs
     * no related-module field-shape knowledge at all (see
     * buildDelegationTestMethodsFor()'s docblock), so it's always emitted
     * when enabled. Confirmed here with a config that declares both
     * alongside a bulk_actions entry (to prove bulk-action coverage and
     * these newer families coexist correctly for the same module).
     */
    public function test_generate_emits_action_contract_and_delegation_list_tests(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => [
                        'bulk_actions' => [
                            ['key' => 'activate', 'status_target' => 'ACTIVE'],
                        ],
                    ],
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
            'actions' => [
                'quickView' => [
                    'name' => 'quickView',
                    'operations' => ['view' => ['enabled' => true]],
                ],
            ],
            'delegations' => [
                'items' => [
                    'name' => 'items',
                    'operations' => ['list' => ['enabled' => true]],
                ],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        // actions[] contract coverage: permission-gated + non-5xx, no
        // behavioral assertion about the placeholder body.
        $this->assertStringContainsString('function test_can_invoke_the_quick_view_action_with_permission(', $content);
        $this->assertStringContainsString('function test_cannot_invoke_the_quick_view_action_without_permission(', $content);
        $this->assertMethodBodyContains($content, 'test_can_invoke_the_quick_view_action_with_permission', "/api/widgets/quick-view/view");
        $this->assertMethodBodyContains($content, 'test_cannot_invoke_the_quick_view_action_without_permission', 'assertStatus(403)');

        // Delegation list coverage.
        $this->assertStringContainsString('function test_can_list_items_delegation(', $content);
        $this->assertMethodBodyContains($content, 'test_can_list_items_delegation', '/items/list');

        // Bulk-action coverage IS present: this config's only bulk_actions
        // entry carries a status_target, so firstGenericBulkActionKey()
        // finds nothing safe to invoke — the ids-mode/filter-mode happy-path
        // tests are correctly absent — but the config-independent
        // validation/403 tests are still emitted, since they're gated on
        // $hasList alone.
        $this->assertStringContainsString('/api/widgets/bulk-action', $content);
        $this->assertStringContainsString('function test_bulk_action_validation_fails_with_missing_ids(', $content);
        $this->assertStringContainsString('function test_cannot_bulk_action_without_permission(', $content);
        $this->assertStringNotContainsString('function test_bulk_action_processes_a_valid_uuid_list_in_ids_mode(', $content);
        $this->assertStringNotContainsString('function test_bulk_action_processes_all_records_in_filter_mode_with_an_empty_filter(', $content);
    }

    /**
     * Negative counterpart: an actions[] entry with a custom endpoint path
     * override, or any urlParams, must NOT get a contract test — this
     * generator has no way to independently verify a hand-rolled path is
     * correct (see buildActionContractTestMethods()'s docblock).
     */
    public function test_generate_omits_action_contract_test_when_route_shape_is_customized(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
            'actions' => [
                'archiveByYear' => [
                    'name' => 'archiveByYear',
                    'urlParams' => ['year'],
                    'operations' => ['view' => ['enabled' => true]],
                ],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCrudTest.php';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString('archive_by_year', $content);
        $this->assertStringNotContainsString('ArchiveByYear', $content);
    }

    /**
     * Delegation create/edit/view/delete coverage additionally requires the
     * related module to resolve to a real model FQCN (via PathManager's
     * registry) — here it can't (no such module was ever scaffolded in this
     * test's registry), so only the field-shape-independent `list` test is
     * emitted.
     */
    public function test_generate_omits_delegation_view_edit_delete_tests_when_related_module_does_not_resolve(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
            'delegations' => [
                'items' => [
                    'name' => 'items',
                    'relatedModule' => 'NoSuchModule',
                    'operations' => [
                        'list' => ['enabled' => true],
                        'view' => ['enabled' => true],
                        'delete' => ['enabled' => true],
                    ],
                ],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCrudTest.php';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('function test_can_list_items_delegation(', $content);
        $this->assertStringNotContainsString('function test_can_view_items_delegation_item(', $content);
        $this->assertStringNotContainsString('function test_can_delete_items_delegation_item(', $content);
    }

    /**
     * The positive counterpart above: a bulk_actions entry WITHOUT a
     * status_target is the one shape BulkActionServiceGenerator emits with
     * no dependency on a model constant existing (see
     * firstGenericBulkActionKey()'s docblock), so the ids-mode happy-path
     * test IS emitted here, using that entry's own key.
     */
    public function test_generate_emits_bulk_action_ids_mode_happy_path_when_a_generic_action_is_configured(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => [
                        'bulk_actions' => [
                            ['key' => 'archive'],
                        ],
                    ],
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('function test_bulk_action_processes_a_valid_uuid_list_in_ids_mode(', $content);
        $this->assertMethodBodyContains($content, 'test_bulk_action_processes_a_valid_uuid_list_in_ids_mode', "'action' => 'archive',");
        $this->assertMethodBodyContains($content, 'test_bulk_action_processes_a_valid_uuid_list_in_ids_mode', "'/api/widgets/bulk-action'");
        $this->assertMethodBodyContains($content, 'test_bulk_action_processes_a_valid_uuid_list_in_ids_mode', 'assertStatus(200)');
    }

    /**
     * Mirrors the two tests above but from the "no list feature at all"
     * side: bulk-action coverage must vanish entirely (not just the
     * happy-path half) when list itself is disabled, since route.stub's
     * bulk-action route line only exists inside the list feature's own
     * conditional inclusion.
     */
    public function test_generate_omits_all_bulk_action_tests_when_list_feature_is_disabled(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCrudTest.php';
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString('bulk-action', $content);
        $this->assertStringNotContainsString('bulk_action', $content);
    }

    // ─── Bug fix: enum columns get a real value everywhere, not just the ────
    // ─── enum-specific accept/reject tests ───────────────────────────────────

    /**
     * The GENERIC field-value literal builder (buildFieldValueLiteral(),
     * used both for the HTTP payload and for the direct-Model::create()
     * fixture helper) must emit a real enum_values member, not the generic
     * 'Test Field ' . uniqid() string. A live 5-module generation run
     * confirmed this broke create/edit/view/list/delete_check/activity/
     * delete/filter tests (a Rule::in([...]) 422s the bogus HTTP payload
     * value) AND the fixture helper (Model::create() bypasses validation
     * entirely, sending the bogus string straight to MySQL and truncating
     * against the column's real ENUM(...) definition).
     */
    public function test_generic_literal_builder_uses_a_real_enum_value_in_both_payload_and_fixture(): void
    {
        $config = [
            'table_name' => 'products',
            'columns' => [
                ['name' => 'price_tier', 'type' => 'enum', 'enum_values' => ['standard', 'premium', 'wholesale']],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'price_tier', 'rules' => 'required|string'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Products', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Products') . '/Tests/ProductsCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        // Direct-create() fixture path.
        $this->assertMethodBodyContains($content, 'createProductFixture', "'price_tier' => 'standard',");

        // HTTP payload path (the same field appears in the create test's
        // own $payload build, BEFORE the enum-specific accept/reject tests
        // override it).
        $this->assertMethodBodyContains($content, 'test_can_create_product', "'price_tier' => 'standard',");

        // Never the generic literal for this field.
        $this->assertStringNotContainsString('Test PriceTier', $content);
    }

    /**
     * An enum value containing a quote must not corrupt the generated PHP
     * literal — mirrors FactoryGenerator's own var_export()-based escaping
     * for the identical problem (see FactoryGenerator::buildValueExpression()'s
     * 'enum' case).
     */
    public function test_generic_literal_builder_escapes_an_enum_value_containing_a_quote(): void
    {
        $config = [
            'table_name' => 'products',
            'columns' => [
                ['name' => 'price_tier', 'type' => 'enum', 'enum_values' => ["standard's", 'premium']],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'price_tier', 'rules' => 'required|string'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Products', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Products') . '/Tests/ProductsCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertMethodBodyContains($content, 'createProductFixture', "'price_tier' => 'standard\\'s',");
    }

    /**
     * Reusing the generic literal helper for the enum column's default
     * payload build must not disturb the two enum-specific tests already
     * emitted by buildEnumValidationTestMethods(): "accepts a valid value"
     * still submits a genuinely valid member, and "rejects an invalid
     * value" still submits a deliberately bogus one — both tests OVERRIDE
     * $payload['field'] after the shared buildPayloadLines() call, so
     * neither can be silently defeated by the generic literal now also
     * happening to be a valid enum member.
     */
    public function test_enum_specific_accept_and_reject_tests_remain_correct_after_reusing_the_generic_literal(): void
    {
        $config = [
            'table_name' => 'orders',
            'columns' => [
                ['name' => 'status', 'type' => 'enum', 'enum_values' => ['pending', 'active', 'cancelled']],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'status', 'rules' => 'required|string'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Orders', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'Orders') . '/Tests/OrdersCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        // Accept test: still overrides to a real member and expects 201.
        $this->assertMethodBodyContains($content, 'test_create_order_accepts_a_valid_status_enum_value', "\$payload['status'] = 'pending';");
        $this->assertMethodBodyContains($content, 'test_create_order_accepts_a_valid_status_enum_value', 'assertStatus(201);');

        // Reject test: still overrides to a deliberately bogus value and
        // expects a 422 validation error — NOT accidentally left as the
        // (now-valid) generic literal.
        $this->assertMethodBodyContains($content, 'test_create_order_rejects_an_invalid_status_enum_value', "\$payload['status'] = 'not-a-real-value-' . uniqid();");
        $this->assertMethodBodyContains($content, 'test_create_order_rejects_an_invalid_status_enum_value', 'assertStatus(422)');
        $this->assertMethodBodyContains($content, 'test_create_order_rejects_an_invalid_status_enum_value', "assertJsonValidationErrors(['status'])");
    }

    // ─── Bug fix: multipart boolean response assertions ─────────────────────

    /**
     * On the multipart path, a boolean column's response assertion must
     * compare against a real PHP boolean, never `$payload['field']` — the
     * payload itself correctly holds the STRING '1' (multipart form data
     * carries everything as a string), but the model casts the column to a
     * real boolean, so the JSON response holds `true`, and
     * `assertJsonPath()`'s strict comparison fails `true !== '1'`.
     * Confirmed live: broke test_can_create_item_image,
     * test_can_edit_item_image, and two validation tests in a real
     * multipart module. The payload literal itself must NOT change.
     */
    public function test_multipart_module_boolean_response_assertion_uses_a_real_boolean_not_the_payload(): void
    {
        $config = [
            'table_name' => 'item_images',
            'file_columns' => ['image_media_id'],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                            ['field' => 'is_primary', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'image_media_id', 'rules' => 'nullable|integer|exists:media,id'],
                            ['field' => 'is_primary', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'ItemImages') . '/Tests/ItemImagesCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        // The payload literal itself is unchanged: still the multipart string.
        $this->assertMethodBodyContains($content, 'test_can_create_item_image', "'is_primary' => '1',");
        $this->assertMethodBodyContains($content, 'test_can_edit_item_image', "'is_primary' => '1',");

        // The response assertion compares against a real boolean, not the payload.
        $this->assertMethodBodyContains($content, 'test_can_create_item_image', "->assertJsonPath('data.is_primary', true)");
        $this->assertMethodBodyContains($content, 'test_can_edit_item_image', "->assertJsonPath('data.is_primary', true)");
        $this->assertMethodBodyNotContains($content, 'test_can_create_item_image', "->assertJsonPath('data.is_primary', \$payload['is_primary'])");
        $this->assertMethodBodyNotContains($content, 'test_can_edit_item_image', "->assertJsonPath('data.is_primary', \$payload['is_primary'])");
    }

    /**
     * Non-multipart counterpart: a module with no file_columns entry must
     * be COMPLETELY unaffected by the multipart-boolean fix — its payload
     * already holds a real PHP boolean, and the pre-existing
     * `$payload['field']` comparison already passes there.
     */
    public function test_non_multipart_module_boolean_response_assertion_is_unaffected(): void
    {
        $config = [
            'table_name' => 'items',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'is_active', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'is_active', 'rules' => 'required|boolean'],
                        ],
                    ],
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

        $this->assertMethodBodyContains($content, 'test_can_create_item', "'is_active' => true,");
        $this->assertMethodBodyContains($content, 'test_can_create_item', "->assertJsonPath('data.is_active', \$payload['is_active'])");
        $this->assertMethodBodyContains($content, 'test_can_edit_item', "->assertJsonPath('data.is_active', \$payload['is_active'])");
        $this->assertStringNotContainsString("->assertJsonPath('data.is_active', true)", $content);
    }

    // ─── JSON/array column coverage ────────────────────────────────────────

    /**
     * Regression test for a real generated-and-run failure: a `json` column
     * (IntrospectionToConfig::buildBackendFields() emits a bare `array` rule
     * for every normalized_type === 'json' column — reproduced here via a
     * hand-rolled `terms_and_conditions.applies_to` shape, exactly like the
     * real project this was reported from) previously got the generic
     * string-literal fallback (`'Test AppliesTo ' . uniqid()`), which 422s
     * against the generated service's own `["nullable","array"]` validation.
     * The fixture helper, the create-payload, and the edit-payload must all
     * use a real PHP array literal instead.
     */
    public function test_json_array_column_uses_an_array_literal_not_a_string(): void
    {
        $config = [
            'table_name' => 'terms_and_conditions',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'applies_to', 'rules' => 'nullable|array'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'applies_to', 'rules' => 'nullable|array'],
                        ],
                    ],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('TermsAndConditions', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'TermsAndConditions') . '/Tests/TermsAndConditionsCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertMethodBodyContains($content, 'createTermsAndConditionFixture', "'applies_to' => ['test'],");
        $this->assertMethodBodyContains($content, 'test_can_create_terms_and_condition', "'applies_to' => ['test'],");
        $this->assertMethodBodyContains($content, 'test_can_edit_terms_and_condition', "'applies_to' => ['test'],");
        $this->assertStringNotContainsString("'Test AppliesTo ' . uniqid()", $content);
        $this->assertStringNotContainsString("'Updated AppliesTo ' . uniqid()", $content);
    }

    /**
     * assertDatabaseHas() cannot safely check a json/array column at all.
     * The obvious problem is a raw PHP array value (PDO has no array bind
     * type); the less obvious one — discovered only by actually running the
     * fix against a real MySQL 8 database as part of this task's mandatory
     * end-to-end verification — is that json_encode()-ing the value first
     * does NOT fix it either: MySQL's native JSON column type does not
     * compare equal (`=`) to a bound string parameter, even when the stored
     * bytes are byte-for-byte identical to the encoded literal (confirmed
     * live via `SELECT applies_to = '["test"]'` returning 0 against a row
     * whose `HEX(applies_to)` was exactly `5B2274657374225D`, while
     * `SELECT applies_to = CAST('["test"]' AS JSON)` on the same row
     * returned 1 — assertDatabaseHas()'s where() builds the former,
     * uncast form). The only reliable fix is to omit the json/array field's
     * key from the where-clause entirely, on both call sites: the create
     * test's single-field fallback (firstDbAssertableField() must skip past
     * `applies_to` onto `title`, since no field here carries a `unique:`
     * rule) and the edit test's multi-field assertion block (which must omit
     * `applies_to`'s own line while still asserting `title`'s).
     */
    public function test_json_array_column_is_excluded_from_assert_database_has(): void
    {
        $config = [
            'table_name' => 'terms_and_conditions',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'applies_to', 'rules' => 'nullable|array'],
                            ['field' => 'title', 'rules' => 'required|string|max:255'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'applies_to', 'rules' => 'nullable|array'],
                            ['field' => 'title', 'rules' => 'required|string|max:255'],
                        ],
                    ],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('TermsAndConditions', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'TermsAndConditions') . '/Tests/TermsAndConditionsCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        // Create test's single-field assertDatabaseHas() falls back to
        // 'title' — the first non-array field — never 'applies_to', even
        // though 'applies_to' is $fields[0].
        $this->assertMethodBodyContains(
            $content,
            'test_can_create_terms_and_condition',
            "\$this->assertDatabaseHas('terms_and_conditions', ['title' => \$payload['title']]);"
        );

        // Edit test's multi-field assertDatabaseHas() block asserts 'title'
        // but omits 'applies_to' entirely — scoped to the assertDatabaseHas()
        // call itself (via its 'uuid' => $fixture->uuid anchor line), since
        // the method body legitimately mentions 'applies_to' elsewhere (the
        // payload construction and the response's assertJsonPath()).
        $editBody = $this->extractMethodBody($content, 'test_can_edit_terms_and_condition');
        $dbAssertStart = strpos($editBody, "'uuid' => \$fixture->uuid,");
        $this->assertNotFalse($dbAssertStart, 'Could not locate the assertDatabaseHas() block.');
        $dbAssertBlock = substr($editBody, $dbAssertStart);

        $this->assertStringContainsString("'title' => \$payload['title'],", $dbAssertBlock);
        $this->assertStringNotContainsString("'applies_to'", $dbAssertBlock);

        // Never any form of the json column handed to either
        // assertDatabaseHas() call — neither the raw-array shape (a PDO
        // array-bind fatal) nor a json_encode()'d one (a silent, always-false
        // MySQL JSON-vs-string comparison).
        $this->assertStringNotContainsString("'applies_to' => \$payload['applies_to']", $content);
        $this->assertStringNotContainsString('json_encode(', $content);
    }

    /**
     * Degenerate edge case: every configured field is json/array-typed, so
     * firstDbAssertableField() has no non-array field left to fall back to
     * at all. The create test must simply omit its assertDatabaseHas() line
     * (never emit one built from an excluded field, and never fatal trying)
     * — the file-assertion/DB-assertion block collapses to nothing rather
     * than a broken assertion.
     */
    public function test_module_with_only_array_fields_omits_the_create_db_assertion(): void
    {
        $config = [
            'table_name' => 'terms_and_conditions',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'applies_to', 'rules' => 'nullable|array'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('TermsAndConditions', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'TermsAndConditions') . '/Tests/TermsAndConditionsCrudTest.php';
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertMethodBodyNotContains($content, 'test_can_create_terms_and_condition', 'assertDatabaseHas(');
    }

    /**
     * The model casts `json` => `array` (ModelGenerator::getCastType()), so
     * the create/edit response's `data.applies_to` comes back as a real PHP
     * array, and $payload['applies_to'] is already a real PHP array too (the
     * literal fix above) — assertJsonPath()'s strict (`===`) comparison holds
     * for both without any special-casing, unlike the multipart-boolean case
     * (buildResponseAssertLine()'s existing override), which needed one
     * because ITS payload literal deliberately diverges its PHP type
     * ($isMultipart forces a string '1'/'0') from the model's cast type. A
     * json/array column's payload literal never diverges like that on any
     * path, so the plain `$payload['field']` comparison
     * buildResponseAssertLine() already emits by default needs no override
     * here — this test asserts that default is exactly what comes out.
     */
    public function test_json_array_column_response_assertion_uses_the_default_payload_comparison(): void
    {
        $config = [
            'table_name' => 'terms_and_conditions',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'applies_to', 'rules' => 'nullable|array'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('TermsAndConditions', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Core', 'TermsAndConditions') . '/Tests/TermsAndConditionsCrudTest.php';
        $content = (string) file_get_contents($path);

        $this->assertMethodBodyContains(
            $content,
            'test_can_create_terms_and_condition',
            "->assertJsonPath('data.applies_to', \$payload['applies_to'])"
        );
    }

    /**
     * Mandatory regression proof (per this fix's own constraint): a module
     * with NO json/array column at all must produce byte-for-byte identical
     * output to what PhpUnitTestGenerator emitted before this fix — the new
     * `isArrayField()` branches in buildFieldValueLiteral()/
     * buildCreateTestMethod()/buildEditTestMethod() must be true no-ops for
     * every rule string that never contains the bare `array` word. Compares
     * the LocationTypes fixture's full generated output (no json columns in
     * that config) against the exact expected assertions the pre-existing
     * `test_generate_writes_full_crud_test_for_a_module_with_every_feature_enabled()`
     * test already relies on, plus an explicit sanity check that no
     * json_encode()/array-literal artifact leaked in anywhere.
     */
    public function test_module_with_no_json_column_produces_unchanged_output(): void
    {
        $config = $this->locationTypesConfig();

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = $this->generatedFilePath();
        $this->assertValidPhpSyntax($path);
        $content = (string) file_get_contents($path);

        $this->assertStringNotContainsString('json_encode(', $content);
        $this->assertStringNotContainsString("['test'],", $content);
        $this->assertStringContainsString(
            "\$this->assertDatabaseHas('location_types', ['name' => \$payload['name']]);",
            $content
        );
    }
}
