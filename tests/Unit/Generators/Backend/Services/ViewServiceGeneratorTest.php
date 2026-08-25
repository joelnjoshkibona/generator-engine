<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\ViewServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a real, previously-untested defect found 2026-07-30
 * while live-verifying a throwaway module in SYSTEM_SHELL: the generated
 * ViewService called `{Model}::withTrashed()->where(...)` UNCONDITIONALLY,
 * regardless of whether the module actually has soft deletes. Every real
 * Core module in SYSTEM_SHELL happens to have `has_soft_deletes: true`, so
 * this was never exercised — a module without it threw a
 * BadMethodCallException the moment ViewService ran, since Eloquent's
 * __callStatic() has no `withTrashed()` to forward to without the
 * SoftDeletes trait.
 *
 * Fix: `[[withTrashedCall]]` is now resolved from
 * ModuleConfigContract::hasSoftDeletes() — the single sanctioned place to
 * read this fact — to either `'withTrashed()->'` or `''`.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\ViewServiceGenerator
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::hasSoftDeletes()
 */
class ViewServiceGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-view-service-test-' . uniqid();
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

    private function generateAndRead(array $config, string $moduleName = 'Widgets', string $moduleGroup = 'Core'): string
    {
        $generator = new ViewServiceGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate(), 'ViewServiceGenerator::generate() should report a successful write.');

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/{$moduleGroup}/{$moduleName}/Services/{$moduleName}ViewService.php";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_calls_with_trashed_when_module_has_soft_deletes(): void
    {
        $content = $this->generateAndRead([
            'has_soft_deletes' => true,
            'features' => ['backend' => ['view' => ['enabled' => true]]],
        ]);

        $this->assertStringContainsString('WidgetsModel::withTrashed()->where(', $content);
    }

    public function test_omits_with_trashed_when_module_has_no_soft_deletes(): void
    {
        $content = $this->generateAndRead([
            'has_soft_deletes' => false,
            'features' => ['backend' => ['view' => ['enabled' => true]]],
        ]);

        $this->assertStringContainsString('WidgetsModel::where(', $content);
        $this->assertStringNotContainsString('withTrashed', $content);
    }

    public function test_omits_with_trashed_when_has_soft_deletes_key_is_entirely_absent(): void
    {
        // ModuleConfigContract::hasSoftDeletes() defaults to false when the
        // key is missing — matches every other generator's own default.
        $content = $this->generateAndRead([
            'features' => ['backend' => ['view' => ['enabled' => true]]],
        ]);

        $this->assertStringContainsString('WidgetsModel::where(', $content);
        $this->assertStringNotContainsString('withTrashed', $content);
    }

    /**
     * Regression coverage for a real defect found live 2026-08-18 on
     * PurchaseOrders: an inline_items row's own select/api-select FK field
     * (e.g. PurchaseOrderItems.item_id) was never eager-loaded here, so
     * every child row's FK rendered as a raw numeric id in the View/Edit
     * modal instead of the related record's name -- the frontend only ever
     * resolves a display label from an in-memory `{field}_object` the
     * PICKER attaches at selection-time, never persisted server-side, so it
     * was always absent the moment a record was reloaded from the API.
     *
     * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\ViewServiceGenerator::generateInlineItemsLoad()
     */
    public function test_inline_item_fk_field_is_eager_loaded_and_mapped_to_field_object(): void
    {
        $content = $this->generateAndRead([
            'features' => ['backend' => ['view' => ['enabled' => true]]],
            'inline_items' => [
                [
                    'key' => 'orderItems',
                    'parent_fk' => 'order_id',
                    'child_module' => 'OrderItems',
                    'child_group' => 'Core',
                    'fields' => [
                        ['key' => 'item_id', 'type' => 'select'],
                        ['key' => 'quantity', 'type' => 'number'],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString("->with(['item'])", $content, 'The FK field\'s own relation must be eager-loaded.');
        $this->assertStringContainsString(
            "\$row['item_id_object'] = \$childRow->item ? \$childRow->item->toArray() : null;",
            $content,
            'The eager-loaded relation must be re-attached under the exact `{field}_object` key the frontend already expects.'
        );
    }

    public function test_inline_item_with_no_fk_fields_is_byte_identical_to_the_old_plain_query(): void
    {
        $content = $this->generateAndRead([
            'features' => ['backend' => ['view' => ['enabled' => true]]],
            'inline_items' => [
                [
                    'key' => 'orderItems',
                    'parent_fk' => 'order_id',
                    'child_module' => 'OrderItems',
                    'child_group' => 'Core',
                    'fields' => [
                        ['key' => 'description', 'type' => 'input'],
                        ['key' => 'quantity', 'type' => 'number'],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString(
            "\$data['orderItems'] = \\App\\Project\\Modules\\Core\\OrderItems\\OrderItemsModel::where('order_id', \$model->id)->get()->toArray();",
            $content
        );
        $this->assertStringNotContainsString('_object', $content);
        $this->assertStringNotContainsString('->with(', $content);
    }

    /**
     * A `select` is not automatically a relation. Two shapes carry their own value list and
     * have no related model at all: a literal `options` array, and a `splash_key`-resolved
     * list. Both used to be treated as FKs -- the column name was stripped of `_id`,
     * camelCased into a relation name and handed to ::with(), which 500s the View endpoint
     * with "Call to undefined relationship". Confirmed 2026-08 on an inline `template_key`
     * select, which had to be redeclared as an `input` to work around it.
     */
    public function test_inline_item_select_with_local_options_array_is_not_treated_as_an_fk(): void
    {
        $content = $this->generateAndRead([
            'features' => ['backend' => ['view' => ['enabled' => true]]],
            'inline_items' => [
                [
                    'key' => 'orderItems',
                    'parent_fk' => 'order_id',
                    'child_module' => 'OrderItems',
                    'child_group' => 'Core',
                    'fields' => [
                        ['key' => 'template_key', 'type' => 'select', 'options' => [
                            ['value' => 'A', 'label' => 'Alpha'],
                            ['value' => 'B', 'label' => 'Beta'],
                        ]],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString(
            "\$data['orderItems'] = \\App\\Project\\Modules\\Core\\OrderItems\\OrderItemsModel::where('order_id', \$model->id)->get()->toArray();",
            $content
        );
        $this->assertStringNotContainsString('->with(', $content);
        $this->assertStringNotContainsString('templateKey', $content);
    }

    public function test_inline_item_select_backed_by_a_splash_key_is_not_treated_as_an_fk(): void
    {
        $content = $this->generateAndRead([
            'features' => ['backend' => ['view' => ['enabled' => true]]],
            'inline_items' => [
                [
                    'key' => 'orderItems',
                    'parent_fk' => 'order_id',
                    'child_module' => 'OrderItems',
                    'child_group' => 'Core',
                    'fields' => [
                        ['key' => 'status', 'type' => 'select', 'splash_key' => 'statuses'],
                    ],
                ],
            ],
        ]);

        $this->assertStringNotContainsString('->with(', $content);
    }

    public function test_a_real_fk_select_is_still_eager_loaded_alongside_a_local_options_select(): void
    {
        $content = $this->generateAndRead([
            'features' => ['backend' => ['view' => ['enabled' => true]]],
            'inline_items' => [
                [
                    'key' => 'orderItems',
                    'parent_fk' => 'order_id',
                    'child_module' => 'OrderItems',
                    'child_group' => 'Core',
                    'fields' => [
                        ['key' => 'template_key', 'type' => 'select', 'options' => [['value' => 'A', 'label' => 'Alpha']]],
                        ['key' => 'item_id', 'type' => 'api-select'],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString("->with(['item'])", $content);
        $this->assertStringNotContainsString('templateKey', $content);
    }
}
