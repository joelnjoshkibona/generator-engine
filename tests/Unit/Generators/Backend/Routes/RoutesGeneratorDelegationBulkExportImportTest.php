<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Routes;

use Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for delegation-scoped export/bulk-action/import route
 * registration — nested under operations.list.backend.{export,bulk_actions,
 * import}, not top-level operation keys. Sibling to (and deliberately
 * separate from, per that file's own single-purpose convention)
 * RoutesGeneratorDelegationHttpVerbTest.php.
 *
 * Permission formula: reuses the RELATED module's own permission (e.g.
 * "StockMovements.list" for export, "StockMovements.bulkAction",
 * "StockMovements.import"), never a delegation-specific one — see
 * DelegationConfigNormalizer::resolveOperationPermission().
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator::generateDelegationRoutes()
 */
class RoutesGeneratorDelegationBulkExportImportTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-routes-delegation-bei-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        PathManager::resetModuleSubGroup();
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

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

    private function generateRoutes(array $config): string
    {
        $generator = new RoutesGenerator('Warehouses', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Custom/Warehouses/Routes/api.php';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function test_delegation_export_route_registered_as_get_with_related_modules_own_list_permission_when_export_enabled(): void
    {
        $routes = $this->generateRoutes($this->delegationConfig(['export' => true]));

        $this->assertStringContainsString(
            "->get('/warehouses/{uuid}/stock-movements/list/export'",
            $routes
        );
        $this->assertStringContainsString(
            "permission:StockMovements.list'",
            $routes,
            'export must reuse the related module\'s own .list permission, not a delegation-specific one'
        );
    }

    public function test_delegation_export_route_absent_when_export_not_configured(): void
    {
        $routes = $this->generateRoutes($this->delegationConfig([]));

        $this->assertStringNotContainsString('list/export', $routes);
    }

    public function test_delegation_bulk_action_route_registered_as_post_with_related_modules_own_bulk_action_permission(): void
    {
        $routes = $this->generateRoutes($this->delegationConfig(['bulk_actions' => [['key' => 'archive']]]));

        $this->assertStringContainsString(
            "->post('/warehouses/{uuid}/stock-movements/bulk-action'",
            $routes
        );
        $this->assertStringContainsString("permission:StockMovements.bulkAction'", $routes);
    }

    public function test_delegation_bulk_action_route_absent_when_bulk_actions_not_configured(): void
    {
        $routes = $this->generateRoutes($this->delegationConfig([]));

        $this->assertStringNotContainsString('bulk-action', $routes);
    }

    public function test_delegation_import_template_and_upload_routes_registered_with_correct_verbs_and_permission(): void
    {
        $routes = $this->generateRoutes($this->delegationConfig(['import' => true]));

        $this->assertStringContainsString(
            "->get('/warehouses/{uuid}/stock-movements/import/template'",
            $routes
        );
        $this->assertStringContainsString(
            "->post('/warehouses/{uuid}/stock-movements/import'",
            $routes
        );
        $this->assertStringContainsString("permission:StockMovements.import'", $routes);
    }

    public function test_delegation_import_routes_absent_when_import_not_configured(): void
    {
        $routes = $this->generateRoutes($this->delegationConfig([]));

        $this->assertStringNotContainsString('/import', $routes);
    }

    public function test_delegation_export_bulk_action_import_routes_all_absent_when_list_operation_itself_is_disabled(): void
    {
        $config = $this->delegationConfig(['export' => true, 'bulk_actions' => [['key' => 'archive']], 'import' => true]);
        $config['delegations']['stockMovements']['operations']['list']['enabled'] = false;

        $routes = $this->generateRoutes($config);

        $this->assertStringNotContainsString('list/export', $routes);
        $this->assertStringNotContainsString('bulk-action', $routes);
        $this->assertStringNotContainsString('/import', $routes);
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
