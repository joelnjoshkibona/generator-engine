<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\MobileApp\Pages;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Pages\ViewOverviewGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for two real defects found live 2026-08-23 on
 * Expenses' Details/Overview page (v3.4.16):
 *
 * Bug 1 (present since the package's first commit, e5f7105, 2026-05-09):
 * generateOverviewSections() only ever checked `viewConfig['sections']`
 * (an older, alternate custom-sections shape), never `viewConfig['fields']`
 * -- what the View-builder wizard actually writes. Every module configured
 * via the standard wizard silently had its Overview page's field
 * list/order/selection ignored, always falling back to a raw `columns`
 * iteration with generic auto-labels instead.
 *
 * Bug 2: even once fields are read, a raw FK id column
 * (`location_id`) rendered its raw numeric value instead of the related
 * record's name, and a status FK carried no color -- the same root cause
 * BaseComponentGenerator::resolveColumnRelationship() fixes on web, reused
 * here via generateOverviewRow().
 *
 * @see \Blutrixx\GeneratorEngine\Generators\MobileApp\Pages\ViewOverviewGenerator::generateOverviewSections()
 * @see \Blutrixx\GeneratorEngine\Generators\MobileApp\Pages\ViewOverviewGenerator::generateOverviewRow()
 */
class ViewOverviewGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-mobile-view-overview-test-' . uniqid();
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

    private function generate(array $config): string
    {
        $generator = new ViewOverviewGenerator('Expenses', 'System', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = PathManager::getMobileAppModulePath('System', 'Expenses') . '/ExpensesDetailsOverviewPage.vue';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_view_fields_config_is_read_and_governs_field_selection_and_order(): void
    {
        $config = [
            'columns' => [
                ['name' => 'expense_number', 'type' => 'string'],
                ['name' => 'notes', 'type' => 'string'],
            ],
            'features' => ['frontend' => ['view' => [
                // 'notes' deliberately omitted, 'expense_number' first --
                // must be respected, not silently replaced by a raw
                // columns-order iteration of every column.
                'fields' => [
                    ['data' => 'expense_number', 'title' => 'Expense #'],
                ],
            ]]],
        ];

        $content = $this->generate($config);

        $this->assertStringContainsString('Expense #', $content);
        $this->assertStringContainsString('data?.expense_number', $content);
        $this->assertStringNotContainsString('Notes', $content);
        $this->assertStringNotContainsString('data?.notes', $content);
    }

    public function test_raw_fk_id_field_resolves_to_the_related_records_name(): void
    {
        $config = [
            'columns' => [
                ['name' => 'location_id', 'type' => 'foreignId', 'relatedModule' => 'Locations'],
            ],
            'features' => ['frontend' => ['view' => [
                'fields' => [
                    ['data' => 'location_id', 'title' => 'Location'],
                ],
            ]]],
        ];

        $content = $this->generate($config);

        $this->assertStringContainsString('{{ data?.location?.name || \'N/A\' }}', $content);
        $this->assertStringNotContainsString('data?.location_id', $content);
    }

    public function test_status_fk_field_gets_a_color_dot(): void
    {
        $config = [
            'columns' => [
                ['name' => 'status_id', 'type' => 'foreignId', 'relatedModule' => 'Statuses'],
            ],
            'features' => ['frontend' => ['view' => [
                'fields' => [
                    ['data' => 'status_id', 'title' => 'Status'],
                ],
            ]]],
        ];

        $content = $this->generate($config);

        $this->assertStringContainsString('{{ data?.status?.name || \'N/A\' }}', $content);
        $this->assertStringContainsString('data?.status?.color', $content);
    }

    public function test_older_sections_shape_still_takes_priority_over_fields(): void
    {
        $config = [
            'columns' => [],
            'features' => ['frontend' => ['view' => [
                'fields' => [
                    ['data' => 'expense_number', 'title' => 'Should be ignored'],
                ],
                'sections' => [
                    ['fields' => [
                        ['field' => 'notes', 'label' => 'Custom Section Field'],
                    ]],
                ],
            ]]],
        ];

        $content = $this->generate($config);

        $this->assertStringContainsString('Custom Section Field', $content);
        $this->assertStringNotContainsString('Should be ignored', $content);
    }

    public function test_no_view_field_config_falls_back_to_raw_columns_and_still_resolves_fk(): void
    {
        $config = [
            'columns' => [
                ['name' => 'expense_number', 'type' => 'string'],
                ['name' => 'location_id', 'type' => 'foreignId', 'relatedModule' => 'Locations'],
            ],
            // generate()'s own `empty($frontendConfig)` bail-out requires
            // the 'view' key to be genuinely non-empty -- idParam here is
            // incidental, standing in for "some other real config exists,
            // just not fields/sections".
            'features' => ['frontend' => ['view' => ['idParam' => 'uuid']]],
        ];

        $content = $this->generate($config);

        $this->assertStringContainsString('data?.expense_number', $content);
        $this->assertStringContainsString('{{ data?.location?.name || \'N/A\' }}', $content);
    }
}
