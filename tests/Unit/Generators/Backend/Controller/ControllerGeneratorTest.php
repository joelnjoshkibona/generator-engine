<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Controller;

use Blutrixx\GeneratorEngine\Generators\Backend\Controller\ControllerGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the ControllerGenerator / RoutesGenerator
 * features.backend divergence.
 *
 * Bug: RoutesGenerator::__construct() only added a standard feature (list,
 * create, view, edit, delete) to $this->features when its own key was
 * present in $config['features']['backend'] (plus the deleteCheck-follows-
 * delete special case). ControllerGenerator::__construct() instead
 * hardcoded all six standard features as unconditionally "enabled" and
 * only consulted $backendFeatures for the createSplash/editSplash check.
 * Net effect: a module with, say, 'create' disabled in features.backend
 * still got a create{Module}() controller method with no route pointing at
 * it -- dead code, not a crash, but the same two-places-derive-the-same-
 * fact-differently shape that has produced real bugs in this codebase.
 *
 * A second, narrower instance of the same divergence: ControllerGenerator
 * gated createSplash/editSplash generation on `isset($this->features['create'])
 * /['edit']` in addition to the splash feature itself being enabled;
 * RoutesGenerator never required 'create'/'edit' to also be enabled for the
 * splash route. Fixed to match RoutesGenerator (splash's own key is the
 * only gate, alongside the existing $hasSplash/'constants' check).
 *
 * Fix: ControllerGenerator now derives which standard features are enabled
 * via a local resolveEnabledBackendFeatures() helper whose logic is kept
 * byte-for-byte identical to RoutesGenerator's inline equivalent (see the
 * docblock on that method for why it's a local copy rather than a shared
 * helper in this fix).
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Controller\ControllerGenerator::resolveEnabledBackendFeatures()
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator::__construct()
 */
class ControllerGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-controller-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
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

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @param array<string, mixed> $backendFeatures features.backend block
     * @param array<string, mixed> $extraConfig additional top-level config keys (e.g. 'constants')
     */
    private function generateController(
        array $backendFeatures,
        array $extraConfig = [],
        string $moduleName = 'TestWidget',
        string $moduleGroup = 'Core'
    ): string {
        $config = array_merge(['features' => ['backend' => $backendFeatures]], $extraConfig);

        $generator = new ControllerGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate(), 'ControllerGenerator::generate() should report a successful write.');

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/{$moduleGroup}/{$moduleName}/{$moduleName}Controller.php";
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    private function generateRoutes(
        array $backendFeatures,
        array $extraConfig = [],
        string $moduleName = 'TestWidget',
        string $moduleGroup = 'Core'
    ): string {
        $config = array_merge(['features' => ['backend' => $backendFeatures]], $extraConfig);

        $generator = new RoutesGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate(), 'RoutesGenerator::generate() should report a successful write.');

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/{$moduleGroup}/{$moduleName}/Routes/api.php";
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    // ─── Each standard feature: emitted when enabled, omitted when disabled ──

    public function test_enabled_standard_feature_gets_a_controller_method(): void
    {
        $content = $this->generateController(['create' => []]);

        $this->assertStringContainsString('public function createTestWidget(', $content);
    }

    public function test_disabled_standard_feature_gets_no_controller_method(): void
    {
        // Only 'create' is configured -- list/view/edit/delete must all be
        // absent from the generated controller.
        $content = $this->generateController(['create' => []]);

        $this->assertStringNotContainsString('public function listTestWidget(', $content);
        $this->assertStringNotContainsString('public function viewTestWidget(', $content);
        $this->assertStringNotContainsString('public function editTestWidget(', $content);
        $this->assertStringNotContainsString('public function deleteTestWidget(', $content);
    }

    public function test_all_standard_features_enabled_emits_all_methods(): void
    {
        $content = $this->generateController([
            'list' => [], 'create' => [], 'view' => [], 'edit' => [], 'delete' => [],
        ]);

        foreach (['list', 'create', 'view', 'edit', 'delete', 'deleteCheck'] as $feature) {
            $this->assertStringContainsString("public function {$feature}TestWidget(", $content, "Expected {$feature}TestWidget() to be generated.");
        }
    }

    // ─── deleteCheck follows delete ──────────────────────────────────────────

    public function test_delete_check_is_generated_when_delete_is_enabled_even_without_its_own_key(): void
    {
        $content = $this->generateController(['delete' => []]);

        $this->assertStringContainsString('public function deleteTestWidget(', $content);
        $this->assertStringContainsString('public function deleteCheckTestWidget(', $content);
    }

    public function test_delete_check_is_absent_when_delete_is_not_enabled(): void
    {
        $content = $this->generateController(['create' => []]);

        $this->assertStringNotContainsString('public function deleteCheckTestWidget(', $content);
    }

    public function test_delete_check_can_be_enabled_independently_via_its_own_key(): void
    {
        // Edge case allowed by the shared rule: deleteCheck's own key present
        // even though 'delete' itself is not configured.
        $content = $this->generateController(['deleteCheck' => []]);

        $this->assertStringContainsString('public function deleteCheckTestWidget(', $content);
        $this->assertStringNotContainsString('public function deleteTestWidget(', $content);
    }

    // ─── createSplash / editSplash: gated on constants + own key, not on create/edit ──

    public function test_create_splash_requires_both_constants_and_its_own_backend_feature_key(): void
    {
        $content = $this->generateController(
            ['create' => [], 'createSplash' => []],
            ['constants' => ['SOME_CONST' => 'value']]
        );

        $this->assertStringContainsString('public function createSplash(', $content);
    }

    public function test_create_splash_omitted_when_constants_declared_but_own_key_missing(): void
    {
        $content = $this->generateController(
            ['create' => []],
            ['constants' => ['SOME_CONST' => 'value']]
        );

        $this->assertStringNotContainsString('public function createSplash(', $content);
    }

    public function test_create_splash_omitted_when_own_key_present_but_no_constants_declared(): void
    {
        $content = $this->generateController(['create' => [], 'createSplash' => []]);

        $this->assertStringNotContainsString('public function createSplash(', $content);
    }

    public function test_create_splash_generated_even_when_create_itself_is_disabled(): void
    {
        // Matches RoutesGenerator: splash generation is gated on the splash
        // feature's own key + $hasSplash, NOT on 'create' being enabled.
        $content = $this->generateController(
            ['createSplash' => []],
            ['constants' => ['SOME_CONST' => 'value']]
        );

        $this->assertStringNotContainsString('public function createTestWidget(', $content);
        $this->assertStringContainsString('public function createSplash(', $content);
    }

    public function test_edit_splash_generated_even_when_edit_itself_is_disabled(): void
    {
        $content = $this->generateController(
            ['editSplash' => []],
            ['constants' => ['SOME_CONST' => 'value']]
        );

        $this->assertStringNotContainsString('public function editTestWidget(', $content);
        $this->assertStringContainsString('public function editSplash(', $content);
    }

    // ─── missing / empty features.backend ────────────────────────────────────

    public function test_missing_features_backend_key_yields_no_standard_feature_methods(): void
    {
        // No 'features' key at all. This intentionally matches
        // RoutesGenerator's pre-existing behaviour (empty backendFeatures =>
        // zero routes) rather than preserving the old "always all six"
        // permissive default: both real config producers -- IntrospectionToConfig::
        // buildBackendFeatures() and GeneratorCreateModuleService::
        // ensureAllFeaturesEnabled() (the two actual entry points that build
        // configs for these generators) always populate features.backend
        // with all standard feature keys before a generator ever sees the
        // config, so this branch is not reachable in practice via either
        // real production path -- but must still behave sanely (empty
        // output, not a crash) if ever hit directly, e.g. by a hand-authored
        // config or a future caller.
        $generator = new ControllerGenerator('TestWidget', 'Core', []);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/TestWidget/TestWidgetController.php';
        $content = file_get_contents($path);

        foreach (['list', 'create', 'view', 'edit', 'delete', 'deleteCheck'] as $feature) {
            $this->assertStringNotContainsString("public function {$feature}TestWidget(", $content);
        }
    }

    public function test_empty_features_backend_array_yields_no_standard_feature_methods(): void
    {
        $content = $this->generateController([]);

        foreach (['list', 'create', 'view', 'edit', 'delete', 'deleteCheck'] as $feature) {
            $this->assertStringNotContainsString("public function {$feature}TestWidget(", $content);
        }
    }

    // ─── Drift guard: controller methods and routes must always agree ───────

    /**
     * The most valuable test in this file: for the same config, the set of
     * standard-feature controller methods ControllerGenerator emits must
     * exactly match the set of standard-feature routes RoutesGenerator
     * emits for that config. Both generators are exercised for real
     * (their actual constructors/generate() methods, actual stub
     * templates, actual file output) -- nothing here re-implements or
     * mocks either generator's feature-resolution logic, so this test
     * fails if the two ever silently disagree again, regardless of
     * which one drifts.
     *
     */
    #[DataProvider('provideFeatureConfigsForDriftGuard')]
    public function test_controller_methods_and_routes_stay_in_lockstep(array $backendFeatures, array $extraConfig, string $label): void
    {
        $controllerContent = $this->generateController($backendFeatures, $extraConfig, 'DriftWidget', 'Core');
        $routesContent = $this->generateRoutes($backendFeatures, $extraConfig, 'DriftWidget', 'Core');

        $controllerFeatures = $this->extractStandardFeaturesFromController($controllerContent, 'DriftWidget');
        $routeFeatures = $this->extractStandardFeaturesFromRoutes($routesContent, 'DriftWidget');

        $this->assertSame(
            $routeFeatures,
            $controllerFeatures,
            "[{$label}] Controller methods and routes disagree on which standard features are enabled. "
            . 'Controller: [' . implode(', ', $controllerFeatures) . ']; '
            . 'Routes: [' . implode(', ', $routeFeatures) . '].'
        );
    }

    public static function provideFeatureConfigsForDriftGuard(): array
    {
        return [
            'all standard features enabled' => [
                ['list' => [], 'create' => [], 'view' => [], 'edit' => [], 'delete' => []],
                [],
                'all-enabled',
            ],
            'only create enabled' => [
                ['create' => []],
                [],
                'only-create',
            ],
            'list and view only' => [
                ['list' => [], 'view' => []],
                [],
                'list-and-view',
            ],
            'delete enabled implies deleteCheck' => [
                ['delete' => []],
                [],
                'delete-implies-deleteCheck',
            ],
            'deleteCheck enabled independently of delete' => [
                ['deleteCheck' => []],
                [],
                'deleteCheck-alone',
            ],
            'splash features with constants declared' => [
                ['create' => [], 'createSplash' => [], 'edit' => [], 'editSplash' => []],
                ['constants' => ['SOME_CONST' => 'value']],
                'splash-with-constants',
            ],
            'splash keys present but constants missing (splash must stay off both sides)' => [
                ['create' => [], 'createSplash' => [], 'edit' => [], 'editSplash' => []],
                [],
                'splash-without-constants',
            ],
            'splash feature enabled while its base create/edit is disabled' => [
                ['createSplash' => [], 'editSplash' => []],
                ['constants' => ['SOME_CONST' => 'value']],
                'splash-without-base-feature',
            ],
            'empty features.backend' => [
                [],
                [],
                'empty-backend-features',
            ],
        ];
    }

    /**
     * @return string[] enabled standard feature names found in the
     *         generated controller, sorted for a stable comparison.
     */
    private function extractStandardFeaturesFromController(string $content, string $moduleName): array
    {
        $found = [];
        foreach (['list', 'create', 'view', 'edit', 'delete', 'deleteCheck'] as $feature) {
            if (str_contains($content, "public function {$feature}{$moduleName}(")) {
                $found[] = $feature;
            }
        }
        if (str_contains($content, 'public function createSplash(')) {
            $found[] = 'createSplash';
        }
        if (str_contains($content, 'public function editSplash(')) {
            $found[] = 'editSplash';
        }
        sort($found);
        return $found;
    }

    /**
     * @return string[] enabled standard feature names found in the
     *         generated routes file, sorted for a stable comparison.
     */
    private function extractStandardFeaturesFromRoutes(string $content, string $moduleName): array
    {
        $found = [];
        foreach (['list', 'create', 'view', 'edit', 'delete', 'deleteCheck'] as $feature) {
            if (str_contains($content, "'{$feature}{$moduleName}']")) {
                $found[] = $feature;
            }
        }
        if (str_contains($content, "'createSplash']")) {
            $found[] = 'createSplash';
        }
        if (str_contains($content, "'editSplash']")) {
            $found[] = 'editSplash';
        }
        sort($found);
        return $found;
    }

    // ─── Delegation-scoped export/bulk-action/import controller methods ──────

    /**
     * @param array<string, mixed> $listBackendOverrides
     */
    private function delegationConfig(array $listBackendOverrides = []): array
    {
        return [
            'module_name' => 'Warehouses',
            'module_type' => 'Custom',
            'table_name' => 'warehouses',
            'id_type' => 'bigint',
            'columns' => [],
            'delegations' => [
                'stockMovements' => [
                    'name' => 'StockMovements',
                    'relatedModule' => ['name' => 'StockMovements', 'group' => 'Custom'],
                    'filterKey' => 'warehouse_id',
                    'parentKey' => 'uuid',
                    'parentIdField' => 'id',
                    'operations' => [
                        'list' => array_replace_recursive(
                            ['enabled' => true, 'backend' => []],
                            ['backend' => $listBackendOverrides]
                        ),
                        'create' => ['enabled' => false],
                        'edit' => ['enabled' => false],
                        'view' => ['enabled' => false],
                        'delete' => ['enabled' => false],
                    ],
                ],
            ],
        ];
    }

    private function generateDelegationController(array $config): string
    {
        $generator = new ControllerGenerator('Warehouses', 'Custom', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Custom/Warehouses/WarehousesController.php';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function test_generate_delegation_methods_emits_export_method_when_delegation_export_enabled(): void
    {
        $content = $this->generateDelegationController($this->delegationConfig(['export' => true]));

        $this->assertStringContainsString('public function exportStockMovements(Request $request, string $uuid): mixed', $content);
        $this->assertStringNotContainsString('function bulkActionStockMovements', $content);
        $this->assertStringNotContainsString('function importStockMovements', $content);
    }

    public function test_generate_delegation_methods_emits_bulk_action_method_when_delegation_bulk_actions_configured(): void
    {
        $content = $this->generateDelegationController($this->delegationConfig(['bulk_actions' => [['key' => 'archive']]]));

        $this->assertStringContainsString('public function bulkActionStockMovements(Request $request, string $uuid)', $content);
        $this->assertStringNotContainsString('function exportStockMovements', $content);
    }

    public function test_generate_delegation_methods_emits_import_and_import_template_methods_when_delegation_import_enabled(): void
    {
        $content = $this->generateDelegationController($this->delegationConfig(['import' => true]));

        $this->assertStringContainsString('public function importTemplateStockMovements(Request $request): mixed', $content);
        $this->assertStringContainsString('public function importStockMovements(Request $request, string $uuid)', $content);
    }

    public function test_generate_delegation_methods_omits_export_bulk_action_import_when_not_configured(): void
    {
        $content = $this->generateDelegationController($this->delegationConfig([]));

        $this->assertStringNotContainsString('function exportStockMovements', $content);
        $this->assertStringNotContainsString('function bulkActionStockMovements', $content);
        $this->assertStringNotContainsString('function importStockMovements', $content);
        $this->assertStringNotContainsString('function importTemplateStockMovements', $content);
    }

    public function test_generate_delegation_methods_omits_export_bulk_action_import_when_list_operation_disabled(): void
    {
        $config = $this->delegationConfig(['export' => true, 'bulk_actions' => [['key' => 'archive']], 'import' => true]);
        $config['delegations']['stockMovements']['operations']['list']['enabled'] = false;

        $content = $this->generateDelegationController($config);

        $this->assertStringNotContainsString('function exportStockMovements', $content);
        $this->assertStringNotContainsString('function bulkActionStockMovements', $content);
        $this->assertStringNotContainsString('function importStockMovements', $content);
        $this->assertStringNotContainsString('function importTemplateStockMovements', $content);
    }

    /**
     * Cross-file parity: the controller method name each new route points
     * at (RoutesGenerator's own [Controller::class, 'methodName']) must be
     * exactly what ControllerGenerator actually emits.
     */
    public function test_controller_method_names_match_routes_generator_action_references_for_export_import_bulk_action(): void
    {
        $config = $this->delegationConfig(['export' => true, 'bulk_actions' => [['key' => 'archive']], 'import' => true]);

        $controller = $this->generateDelegationController($config);

        $routesGenerator = new RoutesGenerator('Warehouses', 'Custom', $config);
        $routesGenerator->setForce(true);
        $this->assertTrue($routesGenerator->generate());
        $routesPath = $this->tmpRoot . '/BACKEND/app/Project/Modules/Custom/Warehouses/Routes/api.php';
        $routes = (string) file_get_contents($routesPath);

        foreach (['exportStockMovements', 'bulkActionStockMovements', 'importTemplateStockMovements', 'importStockMovements'] as $methodName) {
            $this->assertStringContainsString("'{$methodName}']", $routes, "routes never reference controller method '{$methodName}'");
            $this->assertStringContainsString("function {$methodName}(", $controller, "controller never defines method '{$methodName}'");
        }
    }
}
