<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators;

use Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Seeders\SeederGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\ListServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\Actions\ActionComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\CreateFormGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\Delegations\DelegationTabComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\DeleteFormGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\EditFormGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\ViewModalGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\FrontendLocaleGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ListPageGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ViewOverviewGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Routes\FrontendRoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Roughly 20 delegation/action defects (fixed across v2.16.0-v2.19.0) all
 * shared one shape: each generated file was internally consistent and
 * self-consistently WRONG. Every per-generator unit test passed throughout,
 * because each one asserts its own file in isolation while the defect lived
 * in the RELATIONSHIP between two files — a tab id in StudlyCase vs a route
 * in kebab-case, a route guard demanding one permission string while the
 * seeder created a different one, a component importing a path no generator
 * emits, a `t('items.col_label')` call with no matching locale key.
 *
 * `ViewSurfaceParityTest` and `ActionGenerationTest` each caught ONE such
 * relationship. This test generates a single realistic fixture — a module
 * nested under System/Custom, an FK-select field, a delegation with every
 * CRUD operation enabled, both a modal and a page action, an inline_items
 * child, a morph-select field, a file-input field, and
 * bulk_actions/export/import — and runs four MECHANICAL checks over the
 * whole generated tree at once:
 *
 *   1. Every backend endpoint literal referenced in generated Vue resolves to
 *      a `Route::` path actually registered in a generated api.php.
 *   2. Every `permission:X` (routes) / `hasPermission('X')` (Vue) reference
 *      exists in a generated seeder's permissions JSON.
 *   3. Every relative or `@/pages/modules/...` import in a generated file
 *      resolves to a file this run actually wrote.
 *   4. Every `t('module.key')` call (excluding the hand-maintained `common.*`
 *      namespace) exists in a generated locale file.
 *
 * Generated INSIDE this package's own PHPUnit run, against package source —
 * never against a vendor/ copy. See memory note
 * project-generator-cross-file-contracts / project-generator-dist-not-path-repo.
 */
class CrossFileContractTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-cross-file-contract-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetModuleSubGroup();
        PathManager::resetProjectRoot();
        PathManager::setModuleRegistry([]);
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    // ── Fixture ──────────────────────────────────────────────────────────

    private function itemsConfig(): array
    {
        $fkField = [
            'field' => 'category_id', 'label' => 'Category',
            'field_type' => 'api-select', 'type' => 'text',
            'api_url' => '/select/item-categories', 'option_label' => 'name', 'option_value' => 'id',
            'required' => true,
        ];
        $nameField = ['field' => 'name', 'label' => 'Name', 'field_type' => 'input', 'type' => 'text', 'required' => true];
        $photoField = ['field' => 'photo', 'label' => 'Photo', 'field_type' => 'file-input', 'type' => 'text', 'required' => false];
        $extrasField = [
            'field' => 'extras', 'label' => 'Extras', 'field_type' => 'inline-items', 'type' => 'text', 'required' => false,
            'primaryField' => 'label', 'addButtonText' => 'Add Extra', 'emptyMessage' => 'No extras.',
            'fields' => [['key' => 'label', 'label' => 'Label', 'type' => 'text']],
        ];
        $payableField = [
            'field' => 'payable_type', 'label' => 'Payable', 'field_type' => 'morph-select',
            'id_column' => 'payable_id',
            'targets' => [
                ['alias' => 'itemPrices', 'module' => 'ItemPrices', 'label' => 'Item Prices', 'option_label' => 'price'],
            ],
        ];

        return [
            'module_name' => 'Items',
            'module_type' => 'System',
            'table_name'  => 'items',
            'id_type'     => 'bigint',
            'columns'     => [],
            'file_columns' => ['photo'],
            'inline_items' => [
                [
                    'key' => 'itemTags', 'label' => 'Item Tags',
                    'child_module' => 'ItemTags', 'child_group' => 'Custom',
                    'parent_fk' => 'item_id', 'primary_field' => 'tag',
                    'fields' => [
                        ['key' => 'tag', 'label' => 'Tag', 'type' => 'text', 'required' => true],
                    ],
                ],
                // Every knob the wrapper bakes in, so Check 5 sees the call site of a configured wrapper:
                // a table variant with totals feeding a parent field, and the can_*/message overrides.
                [
                    'key' => 'itemLines', 'label' => 'Item Lines',
                    'child_module' => 'ItemLines', 'child_group' => 'Custom',
                    'parent_fk' => 'item_id', 'primary_field' => 'description',
                    'variant' => 'table', 'modal_size' => 'lg', 'modal_columns' => 2,
                    'add_button_text' => 'Add Line', 'add_modal_title' => 'Add Line', 'edit_modal_title' => 'Edit Line',
                    'view_modal_title' => 'Line Detail', 'empty_message' => 'No lines yet.', 'delete_message' => 'Remove this line?',
                    'can_delete' => false,
                    'totals' => [['field' => 'amount', 'label' => 'Total', 'sync_to' => 'grand_total']],
                    'fields' => [
                        ['key' => 'description', 'label' => 'Description', 'type' => 'text', 'required' => true],
                        ['key' => 'amount', 'label' => 'Amount', 'type' => 'number'],
                    ],
                ],
            ],
            'features' => [
                // SeederGenerator gates permission auto-derivation on
                // !empty($backendFeatures[$feature]) — an empty array is
                // falsy, so these must be non-empty even though
                // RoutesGenerator's own gate is a looser isset() check.
                'backend' => [
                    'list' => ['enabled' => true, 'bulk_actions' => [['key' => 'archive', 'label' => 'Archive']], 'export' => true, 'import' => true],
                    'create' => ['enabled' => true], 'view' => ['enabled' => true],
                    'edit' => ['enabled' => true], 'delete' => ['enabled' => true],
                ],
                'frontend' => [
                    'list' => [
                        'enabled' => true,
                        'primaryField' => 'name',
                        'fields' => [
                            ['key' => 'name', 'sortable' => true],
                            ['key' => 'category_id', 'data' => 'category?.name', 'sortable' => false],
                        ],
                    ],
                    'create' => ['enabled' => true, 'fields' => [$nameField, $fkField, $photoField, $payableField, $extrasField]],
                    'edit'   => ['enabled' => true, 'fields' => [$nameField, $fkField, $photoField, $payableField, $extrasField]],
                    'view'   => ['enabled' => true, 'titleData' => 'name'],
                    'delete' => ['enabled' => true],
                ],
            ],
            'delegations' => [
                'itemPrices' => [
                    'name' => 'ItemPrices', 'label' => 'Item Prices', 'uiType' => 'tab',
                    'relatedModule' => ['name' => 'ItemPrices', 'group' => 'Custom'],
                    'filterKey' => 'item_id', 'parentKey' => 'uuid', 'parentIdField' => 'id',
                    'operations' => [
                        'list' => ['enabled' => true, 'frontend' => ['fields' => [
                            ['key' => 'price', 'label' => 'Price'],
                        ]]],
                        'create' => ['enabled' => true, 'frontend' => ['fields' => [
                            ['field' => 'price', 'label' => 'Price', 'field_type' => 'number-input', 'type' => 'number', 'required' => true],
                        ]]],
                        'edit' => ['enabled' => true, 'frontend' => ['fields' => [
                            ['field' => 'price', 'label' => 'Price', 'field_type' => 'number-input', 'type' => 'number', 'required' => true],
                        ]]],
                        'view'   => ['enabled' => true],
                        'delete' => ['enabled' => true],
                    ],
                ],
            ],
            'actions' => [
                'approve' => [
                    'name' => 'approve', 'label' => 'Approve', 'hasUI' => true, 'uiType' => 'modal', 'urlParams' => ['uuid'],
                    'operations' => ['create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/items/{uuid}/approve']]],
                ],
                'export' => [
                    'name' => 'export', 'label' => 'Export', 'hasUI' => true, 'uiType' => 'page', 'urlParams' => ['uuid'],
                    'operations' => ['create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/items/{uuid}/export']]],
                ],
            ],
        ];
    }

    private function itemPricesConfig(): array
    {
        return [
            'module_name' => 'ItemPrices',
            'module_type' => 'System',
            'table_name'  => 'item_prices',
            'id_type'     => 'bigint',
            'columns'     => [],
            'features' => [
                // Full CRUD, matching the Items->ItemPrices delegation's own
                // operations (2026-08-05: the delegation now delegates
                // execution to these same native services, so ItemPrices
                // needs to actually have them). 'list' included: the
                // delegation permission reversal now resolves the
                // delegation's list operation to ItemPrices' OWN
                // "ItemPrices.list" permission (not a delegation-specific
                // one) — that permission only exists if ItemPrices' own
                // backend config enables list, exactly like every other op.
                'backend'  => [
                    'list'   => ['enabled' => true],
                    'delete' => ['enabled' => true],
                    'view'   => ['enabled' => true],
                    'edit'   => ['enabled' => true],
                    'create' => ['enabled' => true],
                ],
                'frontend' => [
                    'list'   => ['fields' => [['key' => 'price', 'label' => 'Price']]],
                    'delete' => ['enabled' => true],
                    // The delegation tab now embeds the related module's own
                    // native Create/Edit/View components directly (2026-08-05
                    // unification) — must exist for the fixture to be
                    // internally consistent.
                    'view'   => ['enabled' => true, 'titleData' => 'price'],
                    'edit'   => ['enabled' => true, 'fields' => [
                        ['field' => 'price', 'label' => 'Price', 'field_type' => 'number-input', 'type' => 'number', 'required' => true],
                    ]],
                    'create' => ['enabled' => true, 'fields' => [
                        ['field' => 'price', 'label' => 'Price', 'field_type' => 'number-input', 'type' => 'number', 'required' => true],
                    ]],
                ],
            ],
        ];
    }

    /**
     * Generates the whole fixture tree once: the Items module (nested under
     * System/Custom, an FK-select field, a full-CRUD delegation to
     * ItemPrices, one modal + one page action) plus ItemPrices' own
     * independently-scaffolded full-CRUD suite (routes/seeder/locale/forms) —
     * the realistic shape of "delegate to an already-scaffolded module".
     * ItemPrices needs the full suite, not just delete, because the
     * delegation now delegates execution to these same native services
     * (2026-08-05 redesign) rather than reimplementing CRUD itself.
     */
    private function generateFixture(bool $frontendEnabled = true): void
    {
        PathManager::setModuleSubGroup('Custom');
        PathManager::setModuleRegistry([
            ['name' => 'ItemPrices', 'module_type' => 'System', 'group_name' => 'Custom', 'table_name' => 'item_prices'],
        ]);

        $items = $this->itemsConfig();
        $itemPrices = $this->itemPricesConfig();

        // features.frontend.enabled=false: both modules' frontends are hand-written (see Check 6).
        if (!$frontendEnabled) {
            $items['features']['frontend']['enabled'] = false;
            $itemPrices['features']['frontend']['enabled'] = false;
        }

        (new RoutesGenerator('Items', 'System', $items))->generate();
        (new SeederGenerator('Items', 'System', $items))->generate();
        (new FrontendRoutesGenerator('Items', 'System', $items))->generate();
        (new FrontendLocaleGenerator('Items', 'System', $items))->generate();
        (new ListPageGenerator('Items', 'System', $items))->generate();
        (new CreateFormGenerator('Items', 'System', $items))->generate();
        (new EditFormGenerator('Items', 'System', $items))->generate();
        (new DeleteFormGenerator('Items', 'System', $items))->generate();
        (new ViewModalGenerator('Items', 'System', $items))->generate();
        (new ViewOverviewGenerator('Items', 'System', $items))->generate();

        (new ActionComponentGenerator('Items', 'System', $items))->generateAction('approve', $items['actions']['approve']);
        (new ActionComponentGenerator('Items', 'System', $items))->generateAction('export', $items['actions']['export']);

        (new DelegationTabComponentGenerator('Items', 'System', $items))->generateDelegation('itemPrices', $items['delegations']['itemPrices']);

        // items-crud.e2e.js, _fixtures.js, items-item-prices.e2e.js (tab
        // delegation) and items-approve.e2e.js/items-export.e2e.js (modal +
        // page action) — exercises the split spec files' own `./_fixtures.js`
        // relative import for Check 3 below.
        (new PlaywrightTestGenerator('Items', 'System', $items))->generate();

        (new RoutesGenerator('ItemPrices', 'System', $itemPrices))->generate();
        (new SeederGenerator('ItemPrices', 'System', $itemPrices))->generate();
        (new ListServiceGenerator('ItemPrices', 'System', $itemPrices))->generate();
        (new ListPageGenerator('ItemPrices', 'System', $itemPrices))->generate();
        (new FrontendLocaleGenerator('ItemPrices', 'System', $itemPrices))->generate();
        // The Items->ItemPrices delegation tab embeds ItemPrices' own native
        // Create/Edit/Delete/View components directly (2026-08-05 redesign —
        // delegation-specific forms removed entirely, CrudListPanel reuses
        // whatever the related module's own standalone page would use) — all
        // four must exist for Check 3 (import resolution) below to pass,
        // along with the DetailsOverviewPage the ViewModal's own template
        // imports.
        (new CreateFormGenerator('ItemPrices', 'System', $itemPrices))->generate();
        (new EditFormGenerator('ItemPrices', 'System', $itemPrices))->generate();
        (new DeleteFormGenerator('ItemPrices', 'System', $itemPrices))->generate();
        (new ViewModalGenerator('ItemPrices', 'System', $itemPrices))->generate();
        (new ViewOverviewGenerator('ItemPrices', 'System', $itemPrices))->generate();
    }

    // ── Check 1: endpoint literal (Vue) -> Route:: path (api.php) ─────────

    public function test_every_backend_endpoint_literal_in_generated_vue_resolves_to_a_registered_route(): void
    {
        $this->generateFixture();

        $routePatterns = [];
        foreach ($this->allFiles('api.php') as $content) {
            // Every generated route is a chained call —
            // Route::middleware([...])->get('/path', ...) — so the path is
            // the first string argument after the HTTP-verb method, not
            // directly after `Route::`.
            preg_match_all('/->(?:get|post|put|patch|delete)\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m);
            foreach ($m[1] as $path) {
                $routePatterns[] = $this->normalizeSegments($path);
            }
        }
        $this->assertNotEmpty($routePatterns, 'no routes were generated to check endpoints against');

        foreach ($this->allFiles('.vue') as $path => $content) {
            foreach ($this->extractEndpointLiterals($content) as $endpoint) {
                if (str_starts_with($endpoint, '/select/')) {
                    continue; // shared framework select-data endpoint, not module-generated
                }
                $segments = $this->normalizeSegments($endpoint);
                $matched = false;
                foreach ($routePatterns as $routeSegments) {
                    if ($this->segmentsMatch($segments, $routeSegments)) {
                        $matched = true;
                        break;
                    }
                }
                $this->assertTrue($matched, "endpoint '{$endpoint}' in " . basename($path) . ' has no matching registered route');
            }
        }
    }

    // ── Check 2: permission:X / hasPermission('X') -> seeded permission ───

    public function test_every_permission_referenced_in_routes_and_vue_is_seeded(): void
    {
        $this->generateFixture();

        $required = [];
        foreach ($this->allFiles('api.php') as $content) {
            preg_match_all('/permission:([\w.]+)/', $content, $m);
            $required = array_merge($required, $m[1]);
        }
        foreach ($this->allFiles('.vue') as $content) {
            preg_match_all('/hasPermission\(\s*[\'"]([\w.]+)[\'"]\s*\)/', $content, $m);
            $required = array_merge($required, $m[1]);
        }
        $required = array_values(array_unique($required));
        $this->assertNotEmpty($required, 'no permissions were referenced to check against');

        $seeded = [];
        foreach ($this->allFiles('.json') as $content) {
            $decoded = json_decode($content, true);
            if (is_array($decoded) && is_array($decoded['permissions'] ?? null)) {
                foreach ($decoded['permissions'] as $perm) {
                    if (isset($perm['name'])) {
                        $seeded[] = $perm['name'];
                    }
                }
            }
        }
        // seeder.stub calls Helpers::saveModuleCRUDPermissions($module)
        // unconditionally for every module's base list/view/create/edit/
        // delete/bulkAction set (v2.53.0 -- see
        // SeederGeneratorNoRedundantCrudCallTest) -- that set is NOT
        // JSON-derived at all anymore, so it must be counted as seeded here
        // too, or this check would report every module's own base
        // permissions as "referenced but never seeded".
        foreach ($this->allFiles('Seeder.php') as $content) {
            if (preg_match("/saveModuleCRUDPermissions\('(\w+)'\)/", $content, $m)) {
                foreach (['list', 'view', 'create', 'edit', 'delete', 'bulkAction'] as $action) {
                    $seeded[] = "{$m[1]}.{$action}";
                }
            }
        }
        $seeded = array_values(array_unique($seeded));

        foreach ($required as $permission) {
            $this->assertContains($permission, $seeded, "permission '{$permission}' is referenced but never seeded");
        }
    }

    // ── Check 3: import path -> a file this run actually wrote ────────────

    /**
     * Also covers `.e2e.js` -> `./_fixtures.js`: every per-delegation/
     * per-action spec PlaywrightTestGenerator emits imports its module's
     * `_fixtures.js` by a plain relative path (see PlaywrightTestGenerator's
     * class docblock and renderSplitSpec()) — a typo in that path, or a
     * fixtures file that never got written for a module that has
     * delegations/actions, would otherwise only surface at real `npx
     * playwright test` runtime, not at generator-test time.
     */
    public function test_every_import_in_generated_files_resolves_to_a_file_this_run_wrote(): void
    {
        $this->generateFixture();

        $written = array_merge($this->allFiles('.vue'), $this->allFiles('.ts'), $this->allFiles('.js'));

        foreach (array_merge($this->allFiles('.vue'), $this->allFiles('.e2e.js')) as $path => $content) {
            foreach ($this->extractImportPaths($content) as $importPath) {
                $resolved = $this->resolveImport($importPath, dirname($path));
                if ($resolved === null) {
                    continue; // external package or hand-maintained SYSTEM_SHELL framework file
                }
                $this->assertArrayHasKey(
                    $resolved,
                    $written,
                    "import '{$importPath}' in " . basename($path) . " does not resolve to any file this run generated (resolved to {$resolved})"
                );
            }
        }
    }

    // ── Check 5: attributes a form passes to an InlineItems wrapper -> what that wrapper declares ──────

    /**
     * A generated `{Module}{Key}InlineItems.vue` has several root nodes and declares only a v-model and
     * whatever its `defineEmits` lists, so any other attribute on the tag that mounts it cannot fall
     * through and Vue logs "Extraneous non-props attributes" on every page that renders it. Each file is
     * internally fine -- the wrapper renders correctly, the form renders correctly -- and the e2e suite
     * passes with the warning in its console; only comparing the two files finds it. Covers both ways a
     * form mounts one: a top-level `inline_items` block and a `field_type: 'inline-items'` field.
     */
    public function test_every_attribute_a_form_passes_to_an_inline_items_wrapper_is_declared_by_that_wrapper(): void
    {
        $this->generateFixture();

        $vue = $this->allFiles('.vue');

        $wrappers = [];
        foreach ($vue as $path => $content) {
            if (str_ends_with($path, 'InlineItems.vue')) {
                $wrappers[basename($path, '.vue')] = $content;
            }
        }
        $this->assertNotEmpty($wrappers, 'the fixture generated no InlineItems wrapper, so nothing below is checked');

        $tagsChecked = 0;
        $sawTotalsChange = false;
        foreach ($vue as $path => $content) {
            if (str_ends_with($path, 'InlineItems.vue')) {
                continue;
            }
            // Attribute values are quoted and may themselves contain `>` (`@totals-change="(t) => {...}"`),
            // so match name/value pairs rather than scanning for the first `>`.
            preg_match_all('/<(\w+InlineItems)((?:\s+[:@#\w.\-]+(?:="[^"]*")?)*)\s*\/?>/s', $content, $tags, PREG_SET_ORDER);
            foreach ($tags as $tag) {
                [, $component, $attrText] = $tag;
                $this->assertArrayHasKey($component, $wrappers, basename($path) . " mounts <{$component}> but no such wrapper was generated");
                $wrapper = $wrappers[$component];

                $emits = [];
                if (preg_match('/defineEmits<\{([^}]*)\}>/', $wrapper, $em)) {
                    preg_match_all("/'([\\w-]+)'\\s*:/", $em[1], $names);
                    $emits = $names[1];
                }
                $hasModel = str_contains($wrapper, 'defineModel');

                preg_match_all('/([:@#\w.\-]+)(?:="[^"]*")?/', $attrText, $attrs);
                foreach ($attrs[1] as $attr) {
                    $declared = match (true) {
                        $attr === 'v-model' => $hasModel,
                        $attr === 'key', $attr === 'ref' => true,
                        str_starts_with($attr, '@') => in_array(substr($attr, 1), $emits, true),
                        default => false,
                    };
                    $this->assertTrue(
                        $declared,
                        basename($path) . " passes '{$attr}' to <{$component}>, which does not declare it (declares: "
                            . implode(', ', array_merge($hasModel ? ['v-model'] : [], array_map(fn ($e) => "@{$e}", $emits))) . ')'
                    );
                    $sawTotalsChange = $sawTotalsChange || $attr === '@totals-change';
                }
                $tagsChecked++;
            }
        }

        $this->assertGreaterThanOrEqual(3, $tagsChecked, 'expected Create+Edit tags for the two inline_items blocks and the inline-items field');
        $this->assertTrue($sawTotalsChange, 'the totals/sync_to wiring (the one attribute a wrapper does declare) was never exercised');
    }

    // ── Check 6: a module that opted out of the frontend -> no frontend file from ANY generator ──────────

    /**
     * `features.frontend.enabled: false` means the module's frontend is hand-written, and the generator writes the
     * same file names. FrontendPipeline honours the flag, but several generators are also driven one at a time
     * (make:action builds an ActionComponentGenerator and a PlaywrightTestGenerator itself, make:delegation a
     * DelegationTabComponentGenerator) and on a scratch module those created and overwrote pages and specs of an
     * opted-out module -- with --force and without it. This runs the whole fixture's generators against opted-out
     * modules and asserts the frontend tree stays empty while the backend is still generated, so a generator added
     * later that writes past BaseGenerator's guard fails here.
     */
    public function test_a_module_that_opts_out_of_the_frontend_gets_no_frontend_file_from_any_generator(): void
    {
        $this->generateFixture(frontendEnabled: false);

        $frontend = [];
        $backend = [];
        foreach (array_keys($this->allFiles('')) as $path) {
            if (str_contains($path, '/FRONTEND/')) {
                $frontend[] = substr($path, strlen($this->tmpRoot));
            } elseif (str_contains($path, '/BACKEND/')) {
                $backend[] = $path;
            }
        }

        $this->assertSame([], $frontend, 'these frontend files were written for a module whose frontend is opted out');
        // Opting out of the frontend must not switch the backend off.
        foreach (['Items/Routes/api.php', 'Items/Seeders/ItemsSeeder.php', 'ItemPrices/Services/ItemPricesListService.php'] as $expected) {
            $this->assertNotEmpty(
                array_filter($backend, static fn (string $path): bool => str_ends_with($path, '/' . $expected)),
                "{$expected} must still be generated for a module whose frontend is opted out"
            );
        }
    }

    // ── Check 4: t('module.key') -> emitted locale file ────────────────────

    public function test_every_translation_key_used_in_generated_files_exists_in_the_locale_file(): void
    {
        $this->generateFixture();

        $keysByNamespace = [];
        foreach ($this->allFiles('en.json') as $content) {
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $namespace => $keys) {
                if (is_array($keys)) {
                    $keysByNamespace[$namespace] = array_merge($keysByNamespace[$namespace] ?? [], array_keys($keys));
                }
            }
        }
        $this->assertNotEmpty($keysByNamespace, 'no locale files were generated to check against');

        // Only the module-route namespaces THIS fixture generates are in
        // scope. Every other namespace (`common`, `delete`, `entity`, ...) is
        // shared, hand-maintained SYSTEM_SHELL copy — same category as
        // `common.*` — not something any generator emits, so it's out of
        // scope for a check whose job is catching a generated file that
        // references the WRONG module's own namespace (e.g. `items.col_x` on
        // an ItemPrices column), not auditing the shared locale files.
        $moduleNamespaces = ['items', 'item-prices'];

        foreach ($this->allFiles('.vue') as $path => $content) {
            preg_match_all('/(?<!\w)\$?t\(\s*[\'"]([\w-]+)\.([\w]+)[\'"]/', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                [, $namespace, $key] = $match;
                if (!in_array($namespace, $moduleNamespaces, true)) {
                    continue;
                }
                $this->assertArrayHasKey($namespace, $keysByNamespace, "no locale namespace '{$namespace}' was generated (used in " . basename($path) . ')');
                $this->assertContains($key, $keysByNamespace[$namespace] ?? [], "t('{$namespace}.{$key}') used in " . basename($path) . ' has no matching locale entry');
            }
        }
    }

    // ── Shared helpers ──────────────────────────────────────────────────────

    /** @return array<string,string> absolute path => contents, for every file whose name ends with $suffix */
    private function allFiles(string $suffix): array
    {
        $files = [];
        if (!is_dir($this->tmpRoot)) {
            return $files;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmpRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }
        return $files;
    }

    /**
     * @return string[] every quoted string starting with '/' found on a line
     *     that actually makes or names a backend request (`send*Request(`,
     *     an `endpoint`-named computed/prop). A blanket scan over every
     *     quoted `/...` string also catches frontend PAGE navigation
     *     (`router.push('/items/list')`, `:to="..."`, `cancelLink`) — a
     *     different routing table (Vue Router) that happens to reuse the
     *     same path strings by convention, not a backend endpoint at all.
     */
    private function extractEndpointLiterals(string $content): array
    {
        $found = [];
        foreach (explode("\n", $content) as $line) {
            if (!str_contains($line, 'Request(') && stripos($line, 'endpoint') === false) {
                continue;
            }
            preg_match_all('/["\'`](\/[a-zA-Z0-9_\-\/${}.:]+)["\'`]/', $line, $m);
            $found = array_merge($found, $m[1]);
        }
        return array_values(array_unique($found));
    }

    /** @return string[] every import specifier, from static `import ... from '...'` and lazy `() => import('...')` */
    private function extractImportPaths(string $content): array
    {
        preg_match_all('/import\s+[^;]*?\sfrom\s+[\'"]([^\'"]+)[\'"]/', $content, $m1);
        preg_match_all('/\(\)\s*=>\s*import\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $m2);
        return array_values(array_unique(array_merge($m1[1], $m2[1])));
    }

    /**
     * Resolve an import specifier to an absolute path IF it points at
     * something this run is responsible for generating: a relative import,
     * or an `@/pages/modules/...` import (the alias generated component
     * cross-references use). Every other `@/...` import and every bare
     * package import (vue, vue-i18n, lucide-vue-next, @/components/ui/...,
     * @/composables/..., ...) is hand-maintained SYSTEM_SHELL framework code
     * this run never touches, so it's out of scope — return null.
     */
    private function resolveImport(string $importPath, string $importingDir): ?string
    {
        if (str_starts_with($importPath, './') || str_starts_with($importPath, '../')) {
            $target = $importingDir . '/' . $importPath;
        } elseif (str_starts_with($importPath, '@/pages/modules/')) {
            $target = $this->tmpRoot . '/FRONTEND/src/' . substr($importPath, 2);
        } else {
            return null;
        }

        $parts = [];
        foreach (explode('/', $target) as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        return '/' . implode('/', $parts);
    }

    /** Laravel `{param}`, JS `${expr}`, and `:param` segments all become a wildcard so only structure is compared, not parameter names. */
    private function normalizeSegments(string $path): array
    {
        $normalized = preg_replace('/\$\{[^}]+\}|\{[^}]+\}|:[a-zA-Z_][a-zA-Z0-9_]*/', '*', $path);
        return array_values(array_filter(explode('/', $normalized), static fn (string $s): bool => $s !== ''));
    }

    private function segmentsMatch(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $segment) {
            if ($segment === '*' || $b[$i] === '*') {
                continue;
            }
            if ($segment !== $b[$i]) {
                return false;
            }
        }
        return true;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($dir);
    }
}
