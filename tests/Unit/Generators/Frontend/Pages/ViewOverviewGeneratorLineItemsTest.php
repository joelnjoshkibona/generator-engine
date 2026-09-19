<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ViewOverviewGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a real defect found live 2026-08-18 on
 * PurchaseOrders' Details Overview page: writeLineItemsViewComponent()'s
 * name-field heuristic only recognizes literal name-shaped aliases
 * (name/product_name/item_name/description/title). PurchaseOrderItems has
 * none of those -- only item_id/quantity/unit_cost -- so it fell back to the
 * FIRST configured field, which is very often the row's own primary
 * select/api-select FK reference (item_id here), not a real display string.
 * The generated component rendered `item.item_id` directly, showing the raw
 * numeric id ("1", "2") instead of the related Item's name.
 *
 * Fix: when the resolved name field is itself select/api-select-typed,
 * prefer its `{field}_object.name` (populated server-side by
 * ViewServiceGenerator::generateInlineItemsLoad(), see that class's own
 * regression tests) over the raw id, falling back to the raw value only if
 * the object is ever absent.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::writeLineItemsViewComponent()
 */
class ViewOverviewGeneratorLineItemsTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-view-overview-line-items-test-' . uniqid();
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

    private function generate(array $inlineItemFields, array $itemExtra = []): string
    {
        $config = [
            'features' => [
                'frontend' => [
                    'view' => ['fields' => []],
                ],
            ],
            'inline_items' => [
                array_merge([
                    'key' => 'orderItems',
                    'label' => 'Items',
                    'primary_field' => 'item_id',
                    'fields' => $inlineItemFields,
                ], $itemExtra),
            ],
        ];

        $generator = new ViewOverviewGenerator('Orders', 'Custom', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/FRONTEND/src/pages/modules/custom/Orders/Components/OrdersOrderItemsLineItemsView.vue';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_fk_shaped_select_prefers_the_resolved_objects_name(): void
    {
        $content = $this->generate([
            ['key' => 'item_id', 'type' => 'select'],
            ['key' => 'quantity', 'type' => 'number'],
            ['key' => 'unit_cost', 'type' => 'number'],
        ]);

        $this->assertStringContainsString("{{ item.item_id_object?.name ?? item.item_id ?? '—' }}", $content);
        // Never the bare id on its own -- that is the "1", "2" the original defect rendered.
        $this->assertStringNotContainsString("{{ item.item_id ?? '—' }}", $content);
    }

    public function test_plain_string_field_is_unaffected(): void
    {
        $content = $this->generate([
            ['key' => 'description', 'type' => 'input'],
            ['key' => 'quantity', 'type' => 'number'],
            ['key' => 'unit_price', 'type' => 'number'],
        ]);

        $this->assertStringContainsString("{{ item.description ?? '—' }}", $content);
        $this->assertStringNotContainsString('_object', $content);
    }

    /**
     * The view used to render through a shared `@/components/LineItemsList.vue`, which the current
     * frontend base doesn't ship: an unresolvable import found by the super-suite fixture's
     * production build. It is concrete markup now, with no runtime dependency at all.
     */
    public function test_it_has_no_dependency_on_the_shared_line_items_list_component(): void
    {
        $content = $this->generate([['key' => 'description', 'type' => 'input']]);

        $this->assertStringNotContainsString('LineItemsList', $content);
        $this->assertStringNotContainsString("from '@/components", $content);
    }

    public function test_columns_are_headed_by_the_field_labels_and_numbers_align_right(): void
    {
        $content = $this->generate([
            ['key' => 'description', 'label' => 'What', 'type' => 'input'],
            ['key' => 'quantity', 'label' => 'Qty', 'type' => 'number'],
        ]);

        $this->assertStringContainsString('<th class="px-2 py-2 font-medium">What</th>', $content);
        $this->assertStringContainsString('<th class="px-2 py-2 font-medium text-right">Qty</th>', $content);
    }

    public function test_a_field_hidden_from_the_table_gets_no_column(): void
    {
        $content = $this->generate([
            ['key' => 'description', 'type' => 'input'],
            ['key' => 'internal_note', 'type' => 'input', 'show_in_table' => false],
        ]);

        $this->assertStringContainsString('item.description', $content);
        // The "Available keys" comment still lists every API key, hidden or not -- it's the column
        // (header and cell) that must be absent.
        $this->assertStringNotContainsString('item.internal_note', $content);
        $this->assertStringNotContainsString('Internal Note', $content);
    }

    public function test_a_literal_options_select_shows_the_option_label_not_the_stored_key(): void
    {
        $content = $this->generate([
            ['key' => 'line_kind', 'type' => 'select', 'options' => [['id' => 'GOODS', 'name' => 'Goods']]],
        ]);

        $this->assertStringContainsString('.find((o: any) => o.id === item.line_kind)?.name', $content);
        // A literal-options select is not a relation: no server-resolved object to read.
        $this->assertStringNotContainsString('line_kind_object', $content);
    }

    public function test_number_decimals_and_checkbox_values_are_formatted(): void
    {
        $content = $this->generate([
            ['key' => 'unit_price', 'type' => 'number', 'decimals' => 2],
            ['key' => 'taxable', 'type' => 'boolean'],
        ]);

        $this->assertStringContainsString('Number(item.unit_price).toFixed(2)', $content);
        $this->assertStringContainsString("item.taxable ? 'Yes' : 'No'", $content);
    }

    public function test_configured_totals_become_a_footer_row_of_column_sums(): void
    {
        $content = $this->generate(
            [
                ['key' => 'description', 'type' => 'input'],
                ['key' => 'line_total', 'type' => 'number', 'decimals' => 2],
            ],
            ['totals' => [['field' => 'line_total', 'label' => 'Grand total']]]
        );

        $this->assertStringContainsString('<tfoot>', $content);
        $this->assertStringContainsString('Grand total', $content);
        $this->assertStringContainsString('items.reduce((sum: number, i: any) => sum + Number(i.line_total ?? 0), 0).toFixed(2)', $content);
    }

    public function test_no_footer_without_totals(): void
    {
        $content = $this->generate([['key' => 'description', 'type' => 'input']]);

        $this->assertStringNotContainsString('<tfoot>', $content);
    }

    public function test_an_empty_relation_renders_a_message_instead_of_an_empty_table(): void
    {
        $content = $this->generate([['key' => 'description', 'type' => 'input']]);

        $this->assertStringContainsString('<p v-if="!items?.length"', $content);
    }
}
