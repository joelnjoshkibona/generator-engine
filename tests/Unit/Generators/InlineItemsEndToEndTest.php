<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators;

use Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\CreateServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\DeleteServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\EditServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\ViewServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\CreateFormGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\EditFormGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end coverage for `inline_items` (the parent-child "Order Items"
 * feature) against the orders-suite fixture -- the real generator classes,
 * a real temp filesystem, no mocking. Every other inline_items-adjacent
 * test in this package exercises internal building blocks
 * (BaseComponentGeneratorTest's writeInlineItemsWrapperComponent()/
 * generateInlineItemsBlock() tests); this is the first test that runs the
 * FULL pipeline -- frontend forms AND backend services together -- against
 * a config shaped exactly like a real consumer would build one.
 *
 * Building this fixture (see tests/Fixtures/integration-schemas/orders-suite/)
 * found and fixed six real, previously-latent bugs (plus a resolved design
 * decision), none caught by any existing unit test because nothing had ever
 * run inline_items through real generation -- or against a real database --
 * before:
 *
 * 1. README.md's own `inline_items` shape example was wrong -- documented
 *    as `['line_items' => [ [...] ]]` (a group-name key wrapping a list),
 *    but every real consumer does `foreach ($config['inline_items'] ?? []
 *    as $item)` expecting a flat list. Fixed in README.md; this fixture's
 *    inline_items_config.php uses the shape the code actually expects.
 * 2. BaseServiceGenerator::buildChildNamespace() forced any child_group
 *    other than exactly 'Core' or 'System' to nest under
 *    `App\Project\Modules\System\{group}\...` -- but the README's own
 *    documented example uses `child_group => 'Custom'`, and every other
 *    generator's own getNamespace() puts a Custom-grouped module directly
 *    at `App\Project\Modules\Custom\{Module}`, no System nesting. Following
 *    the README literally produced a namespace reference to a class that
 *    does not exist. Fixed to match getNamespace()'s own convention.
 * 3. ModuleScaffolder::mergePersistedFields() (SYSTEM_SHELL-side) never
 *    carried `inline_items` forward across a --force regenerate -- since
 *    it's hand-authored config, not DB-introspected, a --force run (e.g.
 *    to pick up a newly added column) would silently drop the entire
 *    inline_items block. See SYSTEM_SHELL/BACKEND's own commit for that
 *    fix; not reproducible from this package's tests alone since
 *    ModuleScaffolder lives in the consumer, not here.
 * 4. CreateFormGenerator/EditFormGenerator's inline_items block still
 *    imported the shared InlineItemsComponent directly -- stale since the
 *    wrapper-component mechanism (v2.24.0) was introduced, never caught
 *    because no test had run the full generate() pipeline for this path.
 * 5. writeInlineItemsWrapperComponent() used writeFile(), whose
 *    skip-if-exists is gated on `!$this->force` -- a real `--force`
 *    regenerate (the normal case for an unrelated schema change) silently
 *    clobbered a hand-edited wrapper back to its template, defeating the
 *    entire point of the feature. Confirmed via a live SYSTEM_SHELL scratch
 *    module. Fixed with BaseGenerator::writeFileOnce(), a genuinely
 *    unconditional skip-if-exists primitive.
 * 6. CreateServiceGenerator/EditServiceGenerator's save/sync never set
 *    created_by_id/updated_by_id on child rows -- fatal-errored against
 *    any child module using the project's standard creator/updater
 *    convention (confirmed via a live OrdersCreateService::execute() call
 *    against a real database). Fixed with an opt-in
 *    child_has_creator_updater flag (schema-blind by design).
 * 7. Delete cascade (resolved design decision, not a latent bug):
 *    DeleteServiceGenerator now cascade-deletes every inline_items child
 *    when the parent is deleted, unconditionally -- see
 *    generateInlineItemsCascadeDelete()'s own docblock for why cascade
 *    (not block) is the correct default here, and why
 *    DeleteCheckServiceGenerator needed no inline_items-specific change
 *    (its generic FK-graph dependent check already covers a typical
 *    parent_fk column).
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\BaseServiceGenerator::buildChildNamespace()
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\DeleteServiceGenerator::generateInlineItemsCascadeDelete()
 */
class InlineItemsEndToEndTest extends TestCase
{
    private string $tmpRoot;
    private array $allColumns;
    private array $inlineItemsConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-inline-items-e2e-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);

        $fixtureDir = __DIR__ . '/../../Fixtures/integration-schemas/orders-suite';
        $this->allColumns = require $fixtureDir . '/columns.php';
        $this->inlineItemsConfig = require $fixtureDir . '/inline_items_config.php';
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

    /** @return array<string, mixed> */
    private function orderItemsMeta(): array
    {
        return [
            'module_name' => 'OrderItems',
            'module_type' => 'Custom',
            'table_name'  => 'order_items',
        ];
    }

    /** @return array<string, mixed> */
    private function orderItemsConfig(): array
    {
        $config = (new IntrospectionToConfig())->build($this->allColumns['order_items'], $this->orderItemsMeta());
        $config['connection'] = '';

        return $config;
    }

    /** @return array<string, mixed> */
    private function ordersConfig(): array
    {
        $config = (new IntrospectionToConfig())->build($this->allColumns['orders'], [
            'module_name' => 'Orders',
            'module_type' => 'Custom',
            'table_name'  => 'orders',
        ]);
        // 'connection' must be explicit -- ModelGenerator falls back to
        // config('generator.default_connection') when absent, which needs a
        // booted Laravel app this plain PHPUnit run doesn't have. Same
        // pattern ModelGeneratorTest's own fixtures use.
        $config['connection'] = '';

        return array_merge($config, $this->inlineItemsConfig);
    }

    // ─── The bug: real child-module namespace vs buildChildNamespace()'s guess ──

    public function test_real_orderitems_module_namespace_matches_getnamespace_convention(): void
    {
        // Actually generate OrderItems' model, exactly as `make:module
        // Custom/OrderItems` would (no sub-group -- matches
        // inline_items_config.php's child_group => 'Custom' with no
        // child_subgroup, since buildChildNamespace() takes no subgroup
        // parameter at all).
        $orderItemsConfig = $this->orderItemsConfig();

        $modelGenerator = new ModelGenerator('OrderItems', 'Custom', $orderItemsConfig);
        $this->assertTrue($modelGenerator->generate());

        $modelPath = PathManager::getBackendModulePath('Custom', 'OrderItems') . '/OrderItemsModel.php';
        $this->assertFileExists($modelPath);

        $modelSource = file_get_contents($modelPath);
        $this->assertStringContainsString('namespace App\Project\Modules\Custom\OrderItems;', $modelSource);
    }

    public function test_orders_create_service_references_the_real_orderitems_namespace(): void
    {
        $ordersConfig = $this->ordersConfig();

        $generator = new CreateServiceGenerator('Orders', 'Custom', $ordersConfig);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Custom', 'Orders') . '/Services/OrdersCreateService.php';
        $this->assertFileExists($path);
        $source = file_get_contents($path);

        // Must reference the SAME namespace the real OrderItems module
        // actually generates into (App\Project\Modules\Custom\OrderItems --
        // see the previous test) -- not a guessed
        // App\Project\Modules\System\Custom\OrderItems that doesn't exist.
        $this->assertStringContainsString('\App\Project\Modules\Custom\OrderItems\OrderItemsModel::create(', $source);
        $this->assertStringNotContainsString('System\Custom\OrderItems', $source);

        // Extract block: pulls order_items out of $data before validation.
        $this->assertStringContainsString("\$inlineData['order_items'] = \$data['order_items'] ?? [];", $source);
        $this->assertStringContainsString("unset(\$data['order_items']);", $source);

        // Save block: parent_fk + inject_from_parent both present.
        $this->assertStringContainsString("'order_id' => \$model->id", $source);
        $this->assertStringContainsString("'currency' => \$model->currency", $source);
        $this->assertStringContainsString("foreach (\$inlineData['order_items'] ?? [] as \$inlineItem)", $source);

        // child_has_creator_updater: true -- created_by_id must be set on
        // every child row this creates, or it fatal-errors against a real
        // DB (order_items.created_by_id is NOT NULL, the project's
        // standard convention). See buildInlineInjectArray()'s docblock.
        $this->assertStringContainsString("'created_by_id' => Auth::id()", $source);
    }

    public function test_orders_edit_service_syncs_order_items_by_uuid_with_real_namespace(): void
    {
        $ordersConfig = $this->ordersConfig();

        $generator = new EditServiceGenerator('Orders', 'Custom', $ordersConfig);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Custom', 'Orders') . '/Services/OrdersEditService.php';
        $this->assertFileExists($path);
        $source = file_get_contents($path);

        $this->assertStringContainsString('\App\Project\Modules\Custom\OrderItems\OrderItemsModel::', $source);
        $this->assertStringNotContainsString('System\Custom\OrderItems', $source);

        // Sync semantics: delete rows dropped from the payload, update
        // existing rows by uuid, insert rows with no uuid.
        $this->assertStringContainsString("whereNotIn('uuid', \$_existingUuids)->delete()", $source);
        $this->assertStringContainsString('OrderItemsModel::updateOrCreate(', $source);
        $this->assertStringContainsString('OrderItemsModel::create(', $source);
        $this->assertStringContainsString("'currency' => \$model->currency", $source);

        // child_has_creator_updater: true -- the create branch (no uuid
        // yet) must set created_by_id; the updateOrCreate branch (existing
        // uuid) must set updated_by_id, NOT created_by_id (that would
        // silently overwrite the original creator on every edit).
        $this->assertStringContainsString("'created_by_id' => Auth::id()", $source);
        $this->assertStringContainsString("'updated_by_id' => Auth::id()", $source);
    }

    public function test_orders_view_service_loads_order_items_with_real_namespace(): void
    {
        $ordersConfig = $this->ordersConfig();

        $generator = new ViewServiceGenerator('Orders', 'Custom', $ordersConfig);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Custom', 'Orders') . '/Services/OrdersViewService.php';
        $this->assertFileExists($path);
        $source = file_get_contents($path);

        $this->assertStringContainsString('\App\Project\Modules\Custom\OrderItems\OrderItemsModel::where(', $source);
        $this->assertStringNotContainsString('System\Custom\OrderItems', $source);
        $this->assertStringContainsString("\$data['order_items'] = ", $source);
        $this->assertStringContainsString("where('order_id', \$model->id)->get()->toArray()", $source);
    }

    public function test_orders_delete_service_cascade_deletes_order_items_with_real_namespace(): void
    {
        $ordersConfig = $this->ordersConfig();

        $generator = new DeleteServiceGenerator('Orders', 'Custom', $ordersConfig);
        $this->assertTrue($generator->generate());

        $path = PathManager::getBackendModulePath('Custom', 'Orders') . '/Services/OrdersDeleteService.php';
        $this->assertFileExists($path);
        $source = file_get_contents($path);

        // Cascade delete must reference the real OrderItems namespace, run
        // AFTER the parent's own $model->delete(), and key off the correct
        // parent_fk -- same namespace-correctness bar as save/sync/load.
        $this->assertStringContainsString('\App\Project\Modules\Custom\OrderItems\OrderItemsModel::where(', $source);
        $this->assertStringNotContainsString('System\Custom\OrderItems', $source);
        $this->assertStringContainsString("where('order_id', \$model->id)->delete()", $source);

        $deletePos = strpos($source, '$model->delete();');
        $cascadePos = strpos($source, "OrderItemsModel::where('order_id'");
        $this->assertNotFalse($deletePos);
        $this->assertNotFalse($cascadePos);
        $this->assertGreaterThan($deletePos, $cascadePos, 'Cascade delete must run after the parent is deleted.');
    }

    // ─── Frontend: wrapper component instead of <InlineItemsComponent> directly ──

    public function test_orders_create_form_uses_wrapper_component_not_inlineitemscomponent_directly(): void
    {
        $ordersConfig = $this->ordersConfig();

        $generator = new CreateFormGenerator('Orders', 'Custom', $ordersConfig);
        $this->assertTrue($generator->generate());

        $createFormPath = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $this->assertFileExists($createFormPath);
        $createFormSource = file_get_contents($createFormPath);

        $this->assertStringContainsString('<OrdersOrderItemsInlineItems', $createFormSource);
        $this->assertStringNotContainsString('<InlineItemsComponent', $createFormSource);
        $this->assertStringContainsString("import OrdersOrderItemsInlineItems from './OrdersOrderItemsInlineItems.vue';", $createFormSource);

        $wrapperPath = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersOrderItemsInlineItems.vue';
        $this->assertFileExists($wrapperPath);
        $wrapperSource = file_get_contents($wrapperPath);

        $this->assertStringContainsString("key: 'product_name'", $wrapperSource);
        $this->assertStringContainsString("key: 'quantity'", $wrapperSource);
        $this->assertStringContainsString("key: 'unit_price'", $wrapperSource);
        $this->assertStringContainsString("key: 'line_total'", $wrapperSource);
        $this->assertStringContainsString('TODO', $wrapperSource);
        $this->assertStringContainsString('defineModel<any[]>', $wrapperSource);
    }

    /**
     * Bug (found + fixed 2026-08-09, while capturing documentation
     * screenshots of this exact fixture): buildInlineItemFieldsJs() passed
     * `inline_items_config.php`'s field `type` ('text'/'number' -- the same
     * semantic values this fixture and the docs page both use) straight
     * through as the emitted `type:` prop. `InlineItemsFieldRenderer.vue`
     * only recognizes WIDGET values there ('input'/'number-input'/etc, see
     * IntrospectionToConfig::buildMorphFrontendFields() for the identical
     * `type`+`field_type` split used everywhere else in this generator) --
     * 'text'/'number' match none of its cases, so the Add/Edit modal
     * silently rendered zero visible fields. Confirmed live: opening the
     * real "Add Item" modal for a module generated from this fixture,
     * `getByLabel('Product')` never found anything.
     */
    public function test_orders_order_items_wrapper_maps_semantic_type_to_the_real_widget_type(): void
    {
        $ordersConfig = $this->ordersConfig();

        $generator = new CreateFormGenerator('Orders', 'Custom', $ordersConfig);
        $this->assertTrue($generator->generate());

        $wrapperPath = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersOrderItemsInlineItems.vue';
        $wrapperSource = file_get_contents($wrapperPath);

        // product_name: type => 'text' in config must become the 'input' widget.
        $this->assertMatchesRegularExpression("/key: 'product_name'.*?type: 'input'/s", $wrapperSource);
        $this->assertDoesNotMatchRegularExpression("/key: 'product_name'.*?type: 'text'/s", $wrapperSource);

        // quantity/unit_price/line_total: type => 'number' must become 'number-input'.
        foreach (['quantity', 'unit_price', 'line_total'] as $key) {
            $this->assertMatchesRegularExpression("/key: '{$key}'.*?type: 'number-input'/s", $wrapperSource);
        }
        $this->assertStringNotContainsString("type: 'number'", $wrapperSource);
    }

    public function test_orders_edit_form_reuses_the_same_wrapper_component_written_once(): void
    {
        $ordersConfig = $this->ordersConfig();

        $createGenerator = new CreateFormGenerator('Orders', 'Custom', $ordersConfig);
        $this->assertTrue($createGenerator->generate());

        $wrapperPath = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersOrderItemsInlineItems.vue';
        $this->assertFileExists($wrapperPath);

        // Simulate a developer having hand-filled the TODO hooks after Create ran.
        file_put_contents($wrapperPath, "<!-- HAND-EDITED: dynamicDisabled wired up for real -->\n");

        $editGenerator = new EditFormGenerator('Orders', 'Custom', $ordersConfig);
        $this->assertTrue($editGenerator->generate());

        $editFormPath = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersEditForm.vue';
        $this->assertFileExists($editFormPath);
        $editFormSource = file_get_contents($editFormPath);
        $this->assertStringContainsString('<OrdersOrderItemsInlineItems', $editFormSource);

        // EditFormGenerator running AFTER CreateFormGenerator must not have
        // touched the already-hand-edited wrapper -- skip-if-exists, same
        // file, same component regardless of which form generator asks for it.
        $this->assertSame(
            "<!-- HAND-EDITED: dynamicDisabled wired up for real -->\n",
            file_get_contents($wrapperPath)
        );
    }

    /**
     * Regression test for a real bug found + fixed 2026-08-02: the wrapper
     * used to be written via writeFile(), whose skip-if-exists is gated on
     * `!$this->force` -- so the PRECEDING test (which never calls
     * setForce()) passed even while --force silently clobbered a
     * hand-edited wrapper in production. This test exercises exactly the
     * code path that test missed: setForce(true) on the SECOND generate()
     * call, matching a real `make:module Custom/Orders --force` run.
     */
    public function test_orders_edit_form_with_force_does_not_clobber_hand_edited_wrapper(): void
    {
        $ordersConfig = $this->ordersConfig();

        $createGenerator = (new CreateFormGenerator('Orders', 'Custom', $ordersConfig))->setForce(true);
        $this->assertTrue($createGenerator->generate());

        $wrapperPath = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersOrderItemsInlineItems.vue';
        $this->assertFileExists($wrapperPath);

        file_put_contents($wrapperPath, "<!-- HAND-EDITED: dynamicDisabled wired up for real -->\n");

        // The critical difference from the test above: force=true, matching
        // a real `--force` regenerate run for an unrelated schema change.
        $editGenerator = (new EditFormGenerator('Orders', 'Custom', $ordersConfig))->setForce(true);
        $this->assertTrue($editGenerator->generate());

        $this->assertSame(
            "<!-- HAND-EDITED: dynamicDisabled wired up for real -->\n",
            file_get_contents($wrapperPath),
            'writeInlineItemsWrapperComponent() must survive --force -- it is write-once by design.'
        );
    }

    // ─── Full pipeline together, once, for a final sanity check ─────────────

    public function test_full_pipeline_runs_clean_for_both_modules_together(): void
    {
        $orderItemsConfig = $this->orderItemsConfig();
        $ordersConfig = $this->ordersConfig();

        // Dependency order: child before parent (see README.md).
        $this->assertTrue((new ModelGenerator('OrderItems', 'Custom', $orderItemsConfig))->generate());
        $this->assertTrue((new CreateServiceGenerator('OrderItems', 'Custom', $orderItemsConfig))->generate());

        $this->assertTrue((new ModelGenerator('Orders', 'Custom', $ordersConfig))->generate());
        $this->assertTrue((new CreateFormGenerator('Orders', 'Custom', $ordersConfig))->generate());
        $this->assertTrue((new EditFormGenerator('Orders', 'Custom', $ordersConfig))->generate());
        $this->assertTrue((new CreateServiceGenerator('Orders', 'Custom', $ordersConfig))->generate());
        $this->assertTrue((new EditServiceGenerator('Orders', 'Custom', $ordersConfig))->generate());
        $this->assertTrue((new ViewServiceGenerator('Orders', 'Custom', $ordersConfig))->generate());

        $ordersServicesDir = PathManager::getBackendModulePath('Custom', 'Orders') . '/Services';
        foreach (['OrdersCreateService.php', 'OrdersEditService.php', 'OrdersViewService.php'] as $file) {
            $path = "{$ordersServicesDir}/{$file}";
            $this->assertFileExists($path);
            // Every generated service file must be lexically valid PHP --
            // a real, if weak, syntax check on the whole pipeline's output.
            $tokens = @token_get_all(file_get_contents($path));
            $this->assertNotFalse($tokens, "{$file} failed to tokenize as PHP");
        }
    }
}
