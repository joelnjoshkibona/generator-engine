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

    private function generate(array $inlineItemFields): string
    {
        $config = [
            'features' => [
                'frontend' => [
                    'view' => ['fields' => []],
                ],
            ],
            'inline_items' => [
                [
                    'key' => 'orderItems',
                    'label' => 'Items',
                    'primary_field' => 'item_id',
                    'fields' => $inlineItemFields,
                ],
            ],
        ];

        $generator = new ViewOverviewGenerator('Orders', 'Custom', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/FRONTEND/src/pages/modules/custom/Orders/Components/OrdersOrderItemsLineItemsView.vue';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_fk_shaped_name_field_prefers_the_resolved_objects_name(): void
    {
        $content = $this->generate([
            ['key' => 'item_id', 'type' => 'select'],
            ['key' => 'quantity', 'type' => 'number'],
            ['key' => 'unit_cost', 'type' => 'number'],
        ]);

        $this->assertStringContainsString(
            "name: String(item.item_id_object?.name ?? item.item_id ?? '—'),",
            $content
        );
        $this->assertStringNotContainsString("name: String(item.item_id ?? '—'),", $content);
    }

    public function test_plain_string_name_field_is_unaffected(): void
    {
        $content = $this->generate([
            ['key' => 'description', 'type' => 'input'],
            ['key' => 'quantity', 'type' => 'number'],
            ['key' => 'unit_price', 'type' => 'number'],
        ]);

        $this->assertStringContainsString("name: String(item.description ?? '—'),", $content);
        $this->assertStringNotContainsString('_object', $content);
    }
}
