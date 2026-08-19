<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ListPageGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Generator-unit coverage for ListPageGenerator, run against a scratch
 * PathManager project root (same harness as PhpUnitTestGeneratorTest/
 * CustomFeatureTabComponentGeneratorTest).
 *
 * Focuses on what this session added: `bulk_actions`/`export`/`import` live
 * at `features.backend.list` — a SEPARATE config location from
 * `features.frontend.list` (columns/fields) — so ListPageGenerator has to
 * read both blocks and wire `:enable-export`/`:enable-bulk-actions`/
 * `:enable-import`/`:bulk-actions` onto `<CrudListPanel>` from the backend
 * block while columns keep coming from the frontend block. No dedicated
 * test existed for this generator before (it was previously only exercised
 * indirectly through CrossFileContractTest's fixture).
 */
class ListPageGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-list-page-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetModuleSubGroup();
        PathManager::resetProjectRoot();
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

    private function generatedContent(string $moduleName = 'Widgets'): string
    {
        $path = PathManager::getFrontendModulePath('Core', $moduleName) . "/{$moduleName}ListPage.vue";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function baseConfig(array $backendListOverrides = []): array
    {
        return [
            'module_name' => 'Widgets',
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => array_merge(['enabled' => true], $backendListOverrides),
                ],
                'frontend' => [
                    'list' => [
                        'enabled' => true,
                        'fields' => [
                            ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_generate_returns_false_and_writes_nothing_when_frontend_list_is_not_configured(): void
    {
        $generator = new ListPageGenerator('Widgets', 'Core', [
            'table_name' => 'widgets',
            'features' => ['backend' => ['list' => true], 'frontend' => []],
        ]);

        $this->assertFalse($generator->generate());
        $this->assertFileDoesNotExist(PathManager::getFrontendModulePath('Core', 'Widgets') . '/WidgetsListPage.vue');
    }

    public function test_generate_writes_the_list_page_with_columns_from_frontend_fields(): void
    {
        $generator = new ListPageGenerator('Widgets', 'Core', $this->baseConfig());
        $this->assertTrue($generator->generate());

        $content = $this->generatedContent();
        $this->assertStringContainsString("name: 'WidgetsListPage'", $content);
        $this->assertStringContainsString('key: "name"', $content);
    }

    /**
     * A field with no `defaultVisible` key at all must never emit the
     * property — omission means "visible" (ReportColumn.defaultVisible's own
     * contract), and every already-generated file's output must stay
     * byte-for-byte unchanged now that the generator knows about the key.
     */
    public function test_field_with_no_defaultvisible_key_omits_it_from_the_emitted_column(): void
    {
        $generator = new ListPageGenerator('Widgets', 'Core', $this->baseConfig());
        $this->assertTrue($generator->generate());

        $content = $this->generatedContent();
        $this->assertStringNotContainsString('defaultVisible', $content);
    }

    /**
     * `defaultVisible: false` in a field's config (features.frontend.list.
     * fields[]) wires the generator up to ReportTable.vue's existing (already
     * shipped project-wide) column-visibility "View" toggle — the column
     * starts hidden until a user opts back in, without any new frontend
     * component work.
     */
    public function test_field_with_defaultvisible_false_emits_it_on_the_generated_column(): void
    {
        $config = $this->baseConfig();
        $config['features']['frontend']['list']['fields'][] = [
            'key' => 'internal_notes', 'label' => 'Internal Notes', 'type' => 'text', 'defaultVisible' => false,
        ];

        $generator = new ListPageGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContent();
        $this->assertStringContainsString('key: "internal_notes"', $content);
        $this->assertStringContainsString('defaultVisible: false', $content);
        // The other field (no defaultVisible key) must still omit it.
        $this->assertMatchesRegularExpression('/key: "name".*?\}/s', $content);
        $this->assertDoesNotMatchRegularExpression('/key: "name"[^}]*defaultVisible/s', $content);
    }

    /**
     * The primary/pinned column is always excluded from ReportTable.vue's
     * configurableColumns (fixed columns are never hideable), so
     * `defaultVisible: false` on it would be a silent no-op. Confirms it's
     * never emitted there even if a config sets it, avoiding generated code
     * that implies a toggle that can never actually take effect.
     */
    public function test_defaultvisible_false_on_the_primary_field_is_not_emitted(): void
    {
        $config = $this->baseConfig();
        $config['features']['frontend']['list']['fields'][0]['defaultVisible'] = false;

        $generator = new ListPageGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContent();
        $this->assertStringContainsString('key: "name"', $content);
        $this->assertStringNotContainsString('defaultVisible', $content);
    }

    public function test_export_bulk_actions_and_import_all_default_to_disabled(): void
    {
        $generator = new ListPageGenerator('Widgets', 'Core', $this->baseConfig());
        $generator->generate();
        $content = $this->generatedContent();

        $this->assertStringContainsString(':enable-export="false"', $content);
        $this->assertStringContainsString(':enable-bulk-actions="false"', $content);
        $this->assertStringContainsString(':enable-import="false"', $content);
        $this->assertStringContainsString('const bulkActions = computed<BulkAction[]>(() => [])', $content);
    }

    public function test_export_enabled_sets_enable_export_true_and_needs_no_dedicated_permission_prop(): void
    {
        $generator = new ListPageGenerator('Widgets', 'Core', $this->baseConfig(['export' => true]));
        $generator->generate();
        $content = $this->generatedContent();

        $this->assertStringContainsString(':enable-export="true"', $content);
        // Export reuses the list's own permission — no :export-permission prop exists at all.
        $this->assertStringNotContainsString('export-permission', $content);
    }

    public function test_import_enabled_sets_enable_import_true_and_the_dedicated_import_permission(): void
    {
        $generator = new ListPageGenerator('Widgets', 'Core', $this->baseConfig(['import' => true]));
        $generator->generate();
        $content = $this->generatedContent();

        $this->assertStringContainsString(':enable-import="true"', $content);
        $this->assertStringContainsString(":import-permission=\"'Widgets.import'\"", $content);
    }

    public function test_bulk_actions_enabled_sets_enable_bulk_actions_true_and_emits_the_literal_array(): void
    {
        $generator = new ListPageGenerator('Widgets', 'Core', $this->baseConfig([
            'bulk_actions' => [
                ['key' => 'archive', 'label' => 'Archive'],
            ],
        ]));
        $generator->generate();
        $content = $this->generatedContent();

        $this->assertStringContainsString(':enable-bulk-actions="true"', $content);
        $this->assertStringContainsString(":bulk-action-permission=\"'Widgets.bulkAction'\"", $content);
        $this->assertStringContainsString("key: 'archive'", $content);
        $this->assertStringContainsString("label: 'Archive'", $content);
    }

    public function test_bulk_actions_literal_drops_an_entry_with_no_key_via_the_shared_normalizer(): void
    {
        $generator = new ListPageGenerator('Widgets', 'Core', $this->baseConfig([
            'bulk_actions' => [
                ['label' => 'No key here'],
                ['key' => 'archive', 'label' => 'Archive'],
            ],
        ]));
        $generator->generate();
        $content = $this->generatedContent();

        $this->assertStringNotContainsString('No key here', $content);
        $this->assertStringContainsString("key: 'archive'", $content);
    }

    /**
     * `features.backend.list.bulk_actions`/`.export`/`.import` are a
     * SEPARATE config location from `features.frontend.list` — an empty or
     * absent `features.backend.list` block must not crash generation just
     * because the frontend block is what actually gates whether the page
     * gets written at all.
     */
    public function test_missing_backend_list_block_does_not_crash_and_defaults_every_flag_off(): void
    {
        $generator = new ListPageGenerator('Widgets', 'Core', [
            'table_name' => 'widgets',
            'features' => [
                'frontend' => ['list' => ['enabled' => true, 'fields' => []]],
            ],
        ]);

        $this->assertTrue($generator->generate());
        $content = $this->generatedContent();

        $this->assertStringContainsString(':enable-export="false"', $content);
        $this->assertStringContainsString(':enable-bulk-actions="false"', $content);
        $this->assertStringContainsString(':enable-import="false"', $content);
    }

    /**
     * Bug found live while porting UserLocations (a pure junction/assignment
     * table with no natural name/title column of its own):
     * IntrospectionToConfig::detectPrimaryFieldFromColumns() falls back to
     * the first column when a table has no non-FK string column — which, for
     * a table like this, is an FK (e.g. user_id). generateColumnsFromListFields()
     * pins the primary field's ReportColumn via `fixed: true` but never gives
     * it a `data` path, and generateCustomCellRenderersFromListFields()
     * unconditionally skipped generating ANY cell renderer for the primary
     * field at all — so with no #cell-{key} slot and no data path,
     * ReportTable.vue's default `row[col.key]` fallback rendered the raw
     * numeric FK id instead of the related record's name. Every other FK
     * column (non-primary) always got a proper RelatedRecordLink renderer;
     * only the primary one, uniquely, didn't.
     */
    public function test_fk_typed_primary_field_gets_a_related_record_link_cell_renderer(): void
    {
        $generator = new ListPageGenerator('UserLocations', 'Core', [
            'module_name' => 'UserLocations',
            'table_name' => 'user_locations',
            'features' => [
                'backend' => ['list' => ['enabled' => true]],
                'frontend' => [
                    'list' => [
                        'enabled' => true,
                        // No explicit primaryField — user_id (the first field)
                        // is the fallback primary, exactly as
                        // detectPrimaryFieldFromColumns() would derive it.
                        'fields' => [
                            [
                                'key' => 'user_id', 'label' => 'User', 'type' => 'text',
                                'data' => 'user?.name', 'isFk' => true,
                                'relatedModule' => 'Users', 'displayField' => 'name',
                            ],
                            ['key' => 'is_primary', 'label' => 'Is Primary', 'type' => 'boolean'],
                        ],
                    ],
                ],
            ],
        ]);
        $this->assertTrue($generator->generate());
        $content = $this->generatedContent('UserLocations');

        $this->assertStringContainsString('<template #cell-user_id="{ row }">', $content);
        $this->assertStringContainsString('<RelatedRecordLink module="Users" :uuid="row.user?.uuid">', $content);
        $this->assertStringContainsString('row.user?.name', $content);
    }

    /**
     * The fix above must not change output for the overwhelmingly common
     * case: a plain scalar (non-FK, non-badge, non-boolean) primary field
     * still gets no cell renderer at all — ReportTable.vue's default
     * `row[col.key]` fallback already renders it correctly, and emitting a
     * redundant renderer would just be noise.
     */
    public function test_plain_scalar_primary_field_still_gets_no_cell_renderer(): void
    {
        $generator = new ListPageGenerator('Widgets', 'Core', $this->baseConfig());
        $generator->generate();
        $content = $this->generatedContent();

        $this->assertStringNotContainsString('#cell-name', $content);
    }

    /**
     * `baseConfig()` on its own never sets `features.frontend.{create,edit,
     * delete,view}` — add all four for tests that need a realistic,
     * fully-CRUD module.
     */
    private function fullCrudConfig(): array
    {
        $config = $this->baseConfig();
        $config['features']['frontend']['create'] = ['enabled' => true];
        $config['features']['frontend']['edit']   = ['enabled' => true];
        $config['features']['frontend']['delete'] = ['enabled' => true];
        $config['features']['frontend']['view']   = ['enabled' => true];

        return $config;
    }

    public function test_full_crud_config_emits_all_four_operation_imports_and_props(): void
    {
        $generator = new ListPageGenerator('Widgets', 'Core', $this->fullCrudConfig());
        $this->assertTrue($generator->generate());
        $content = $this->generatedContent();

        $this->assertStringContainsString("import WidgetsCreateForm from './Components/WidgetsCreateForm.vue'", $content);
        $this->assertStringContainsString("import WidgetsEditForm from './Components/WidgetsEditForm.vue'", $content);
        $this->assertStringContainsString("import WidgetsDeleteForm from './Components/WidgetsDeleteForm.vue'", $content);
        $this->assertStringContainsString("import WidgetsViewModal from './Components/WidgetsViewModal.vue'", $content);
        $this->assertStringContainsString(':create-component="WidgetsCreateForm"', $content);
        $this->assertStringContainsString(':edit-component="WidgetsEditForm"', $content);
        $this->assertStringContainsString(':delete-component="WidgetsDeleteForm"', $content);
        $this->assertStringContainsString(':view-component="WidgetsViewModal"', $content);
    }

    /**
     * The bug this whole block exists to fix: an append-only-ledger-style
     * module that omits `features.frontend.delete` must not get a
     * `{Module}DeleteForm` import at all — DeletePageGenerator/
     * DeleteFormGenerator never write that file for such a module, so an
     * unconditional import would break the Vite build the moment anyone
     * actually relies on the omission.
     */
    public function test_omitting_delete_drops_the_deleteform_import_and_prop_entirely(): void
    {
        $config = $this->fullCrudConfig();
        unset($config['features']['frontend']['delete']);

        $generator = new ListPageGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());
        $content = $this->generatedContent();

        $this->assertStringNotContainsString('DeleteForm', $content);
        $this->assertStringNotContainsString('delete-component', $content);
        // The other three operations are unaffected.
        $this->assertStringContainsString(':create-component="WidgetsCreateForm"', $content);
        $this->assertStringContainsString(':edit-component="WidgetsEditForm"', $content);
        $this->assertStringContainsString(':view-component="WidgetsViewModal"', $content);
    }

    /**
     * A pure list+view-less minimal module (e.g. read-only reference data
     * exposed only via the list itself) emits none of the four operation
     * imports/props — `baseConfig()` alone already represents this case.
     */
    public function test_list_only_module_emits_no_crud_operation_props_or_imports(): void
    {
        $generator = new ListPageGenerator('Widgets', 'Core', $this->baseConfig());
        $this->assertTrue($generator->generate());
        $content = $this->generatedContent();

        $this->assertStringNotContainsString('CreateForm', $content);
        $this->assertStringNotContainsString('EditForm', $content);
        $this->assertStringNotContainsString('DeleteForm', $content);
        $this->assertStringNotContainsString('ViewModal', $content);
    }

    public function test_no_leftover_placeholder_tokens_in_generated_output(): void
    {
        $generator = new ListPageGenerator('Widgets', 'Core', $this->baseConfig([
            'export' => true,
            'import' => true,
            'bulk_actions' => [['key' => 'archive', 'label' => 'Archive']],
        ]));
        $generator->generate();
        $content = $this->generatedContent();

        $this->assertDoesNotMatchRegularExpression('/\[\[\w+\]\]/', $content);
    }
}
