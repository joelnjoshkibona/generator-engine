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

        // buildChildNamespace() now resolves the child module's namespace via
        // PathManager::resolveBackendModuleNamespace() (registry lookup)
        // instead of hand-assembling from child_group/child_group_name --
        // fixed 2026-08-15 after the retail-ERP demo fixture proved the
        // hand-assembled version silently drops a real module's module_type
        // segment (see BaseServiceGenerator::buildChildNamespace()'s own
        // docblock). A real generation run populates this registry as each
        // module is created; simulate that here so OrderItems resolves the
        // same way a real consumer's already-generated sibling module would.
        PathManager::setModuleRegistry([
            ['name' => 'OrderItems', 'module_type' => 'Custom'],
        ]);

        $fixtureDir = __DIR__ . '/../../Fixtures/integration-schemas/orders-suite';
        $this->allColumns = require $fixtureDir . '/columns.php';
        $this->inlineItemsConfig = require $fixtureDir . '/inline_items_config.php';
    }

    protected function tearDown(): void
    {
        PathManager::setModuleRegistry([]);
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

    public function test_inline_rows_are_validated_and_reduced_to_their_declared_keys_before_anything_is_created(): void
    {
        $generator = new CreateServiceGenerator('Orders', 'Custom', $this->ordersConfig());
        $this->assertTrue($generator->generate());
        $source = file_get_contents(PathManager::getBackendModulePath('Custom', 'Orders') . '/Services/OrdersCreateService.php');

        // Rows used to reach `Model::create(array_merge($row, [...]))` unvalidated: with `guarded = []` a
        // client could set any column of the child, and a missing required field was a 500.
        $this->assertStringContainsString('validator($inlineData, [', $source);
        $this->assertStringContainsString("'order_items' => ['array']", $source);
        $this->assertStringContainsString("'order_items.*.uuid' => ['nullable', 'string']", $source);
        $this->assertStringContainsString("\\Illuminate\\Support\\Arr::only(\$row, ['uuid'", $source);

        // ...and it happens BEFORE the parent is validated or created.
        $this->assertLessThan(strpos($source, 'self::validateData($data)'), strpos($source, 'validator($inlineData, ['));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function inlineFieldRules(): array
    {
        return [
            'required number' => [['key' => 'qty', 'type' => 'number', 'required' => true], "['required', 'numeric']"],
            'optional text' => [['key' => 'note', 'type' => 'input'], "['nullable', 'string']"],
            'select (string or id: no type rule)' => [['key' => 'kind', 'type' => 'select', 'required' => true], "['required']"],
            'api-select is an id' => [['key' => 'type_id', 'type' => 'api-select'], "['nullable', 'integer']"],
            'checkbox' => [['key' => 'flag', 'type' => 'checkbox'], "['nullable', 'boolean']"],
            'date' => [['key' => 'on', 'type' => 'date', 'required' => true], "['required', 'date']"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inlineFieldRules')]
    public function test_an_inline_fields_rules_follow_its_declaration(array $field, string $expected): void
    {
        $generator = new CreateServiceGenerator('Orders', 'Custom', $this->ordersConfig());
        $method = new \ReflectionMethod($generator, 'inlineFieldRuleLiteral');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($generator, $field));
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
        $this->assertStringContainsString('OrderItemsModel::create(', $source);
        // A uuid names a row of THIS parent only -- never `updateOrCreate(['uuid' => ...])`, which adopted
        // (overwrote and re-parented) another parent's child row.
        $this->assertStringNotContainsString('updateOrCreate', $source);
        $this->assertMatchesRegularExpression(
            "/OrderItemsModel::where\\('uuid', \\\$_uuid\\)->where\\('[a-z_]+', \\\$model->id\\)->first\\(\\)/",
            $source
        );
        $this->assertStringContainsString('$_child->update(', $source);
        $this->assertStringContainsString("'currency' => \$model->currency", $source);

        // child_has_creator_updater: true -- the create branch (no uuid
        // yet) must set created_by_id; the update branch (existing
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

        // Real, concrete field markup -- not a JSON-config-driven generic
        // component (id="..." on a real field component per configured key,
        // not a `key: '...'` JS object literal).
        $this->assertStringContainsString('id="product_name"', $wrapperSource);
        $this->assertStringContainsString('id="quantity"', $wrapperSource);
        $this->assertStringContainsString('id="unit_price"', $wrapperSource);
        $this->assertStringContainsString('id="line_total"', $wrapperSource);
        $this->assertStringContainsString('This file is generated once and never touched again', $wrapperSource);
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

        // product_name: type => 'text' in config must resolve to the 'input'
        // widget -- a real <InputField id="product_name">, not <TextField>
        // or any other stand-in for the raw semantic 'text' value.
        $this->assertMatchesRegularExpression('/<InputField\s+id="product_name"/s', $wrapperSource);

        // quantity/unit_price/line_total: type => 'number' must resolve to
        // the 'number-input' widget -- a real <NumberInputField>.
        foreach (['quantity', 'unit_price', 'line_total'] as $key) {
            $this->assertMatchesRegularExpression("/<NumberInputField\\s+id=\"{$key}\"/s", $wrapperSource);
        }
    }

    /**
     * Found by the super-suite fixture's production build: an inline-items select carrying
     * `splash_key` (and no literal options) fell through to generateField()'s default
     * `:options="splash.<plural key>"`, naming a `splash` object the wrapper never receives --
     * a vue-tsc error, and a crash the moment the Add/Edit dialog renders. The old shared
     * component resolved it at runtime as `field.apiUrl || '/select/' + pascalCase(splashKey)`;
     * the v3.5.18 concrete-markup rewrite dropped that rule. It is now applied at generation time.
     */
    private function wrapperSourceWithExtraFields(array $extraFields): string
    {
        $config = $this->ordersConfig();
        $config['inline_items'][0]['fields'] = array_merge($config['inline_items'][0]['fields'], $extraFields);

        $generator = new CreateFormGenerator('Orders', 'Custom', $config);
        $this->assertTrue($generator->generate());

        return (string) file_get_contents(
            PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersOrderItemsInlineItems.vue'
        );
    }

    public function test_no_modal_field_is_left_inside_a_bare_template_element(): void
    {
        $source = $this->wrapperSourceWithExtraFields([
            ['key' => 'label_text', 'label' => 'Label', 'type' => 'input', 'required' => true],
            ['key' => 'memo', 'label' => 'Memo', 'type' => 'textarea'],
            ['key' => 'flag', 'label' => 'Flag', 'type' => 'checkbox'],
        ]);

        // Dropping only the `v-if` of a `<template v-if="!props.hiddens...">` wrapper left `<template>` with
        // no directive. Vue renders that as a real, display:none <template> element: the field was in the
        // DOM and never visible -- every text input in an inline/picker modal, `description` included.
        $this->assertDoesNotMatchRegularExpression('/<template>\s*<(InputField|TextAreaField|CheckboxField|Select2Field|ApiSelect2Field)\b/', $source);
        // The fields themselves are still emitted, and no hidden-guard is left over.
        $this->assertMatchesRegularExpression('/<InputField\s+id="label_text"/s', $source);
        $this->assertMatchesRegularExpression('/<TextAreaField\s+id="memo"/s', $source);
        $this->assertStringNotContainsString('props.hiddens', $source);
    }

    public function test_every_component_the_modal_uses_is_imported_and_nothing_else(): void
    {
        $source = $this->wrapperSourceWithExtraFields([
            ['key' => 'qty', 'label' => 'Qty', 'type' => 'number'],
            ['key' => 'due', 'label' => 'Due', 'type' => 'date'],
            ['key' => 'note', 'label' => 'Note', 'type' => 'textarea'],
            ['key' => 'flag', 'label' => 'Flag', 'type' => 'checkbox'],
        ]);

        // Every <XxxField> in the template has an import (an unresolved component renders NOTHING -- the
        // field simply is not there)...
        preg_match_all('/<([A-Z][A-Za-z0-9]*Field)\b/', $source, $used);
        $this->assertNotEmpty($used[1]);
        foreach (array_unique($used[1]) as $component) {
            $this->assertStringContainsString("import {$component} from '@/components/form-fields/{$component}.vue'", $source, "{$component} is rendered but not imported");
        }

        // ...`number` is the case that used to miss (only `number-input` was known)...
        $this->assertStringContainsString("import NumberInputField from '@/components/form-fields/NumberInputField.vue'", $source);
        // ...and a widget whose stub renders InputField no longer imports a component it never uses.
        $this->assertStringNotContainsString("form-fields/DateField.vue", $source);
    }

    public function test_a_splash_key_select_becomes_an_api_picker_on_the_generic_select_endpoint(): void
    {
        $source = $this->wrapperSourceWithExtraFields([
            ['key' => 'status_key', 'label' => 'Status', 'type' => 'select', 'required' => true, 'splash_key' => 'line_statuses'],
        ]);

        $this->assertMatchesRegularExpression('/<ApiSelect2Field\s+id="status_key"/s', $source);
        $this->assertStringContainsString('/select/LineStatuses', $source);
        // The regression itself: no bare `splash` reference anywhere in the wrapper.
        $this->assertDoesNotMatchRegularExpression('/\bsplash\./', $source);
    }

    public function test_an_explicit_api_url_wins_over_the_splash_key_derivation(): void
    {
        $source = $this->wrapperSourceWithExtraFields([
            ['key' => 'status_key', 'label' => 'Status', 'type' => 'select', 'splash_key' => 'line_statuses', 'api_url' => '/select/CustomStatuses'],
        ]);

        $this->assertStringContainsString('/select/CustomStatuses', $source);
        $this->assertStringNotContainsString('/select/LineStatuses', $source);
    }

    public function test_a_select_with_literal_options_stays_a_plain_select_even_with_a_splash_key(): void
    {
        $source = $this->wrapperSourceWithExtraFields([
            [
                'key' => 'line_kind', 'label' => 'Kind', 'type' => 'select', 'splash_key' => 'line_kinds',
                'options' => [['id' => 'GOODS', 'name' => 'Goods'], ['id' => 'SERVICE', 'name' => 'Service']],
            ],
        ]);

        $this->assertDoesNotMatchRegularExpression('/<ApiSelect2Field\s+id="line_kind"/s', $source);
        $this->assertStringNotContainsString('/select/LineKinds', $source);
        $this->assertDoesNotMatchRegularExpression('/\bsplash\./', $source);
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
