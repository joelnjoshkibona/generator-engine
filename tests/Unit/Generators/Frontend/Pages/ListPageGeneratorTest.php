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

    private function generatedContent(): string
    {
        $path = PathManager::getFrontendModulePath('Core', 'Widgets') . '/WidgetsListPage.vue';
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
