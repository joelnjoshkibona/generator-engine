<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Routes;

use Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a 2026-08-05 live-verification finding: a
 * hand-authored delegation entry in module.json (the only way this config
 * is ever written — delegations are never introspected) has no
 * `endpoint.method` key, exactly like every example in delegations-suite/
 * README.md. RoutesGenerator's constructor used to read $config['delegations']
 * straight off the raw config, bypassing DelegationConfigNormalizer::normalize()
 * entirely (only the incremental make:delegation path normalized first) — so
 * every edit/delete delegation route was silently registered as POST, one
 * HTTP verb away from what the native EditForm/DeleteForm this whole design
 * reuses actually sends (PUT/DELETE). Caught via a real make:module --force
 * run against a scratch module in SYSTEM_SHELL, not by any existing test —
 * every prior test constructed RoutesGenerator with a config built by hand
 * in PHP, which is easy to (accidentally) pre-normalize.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator::generateDelegationRoutes()
 * @see \Blutrixx\GeneratorEngine\Helpers\DelegationConfigNormalizer::getOperationDefaults()
 */
class RoutesGeneratorDelegationHttpVerbTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-routes-delegation-verb-' . uniqid();
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

    /** No 'endpoint' key on any operation — exactly what a hand-authored module.json delegation looks like. */
    private function rawDelegationConfig(): array
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
                    'label' => 'Stock Movements',
                    'uiType' => 'tab',
                    'relatedModule' => ['name' => 'StockMovements', 'group' => 'Custom'],
                    'filterKey' => 'warehouse_id',
                    'parentKey' => 'uuid',
                    'parentIdField' => 'id',
                    'operations' => [
                        'list' => ['enabled' => true],
                        'create' => ['enabled' => true],
                        'edit' => ['enabled' => true],
                        'view' => ['enabled' => true],
                        'delete' => ['enabled' => true],
                    ],
                ],
            ],
        ];
    }

    public function test_bulk_generate_registers_edit_and_delete_with_the_verbs_native_forms_actually_send(): void
    {
        $config = $this->rawDelegationConfig();
        $generator = new RoutesGenerator('Warehouses', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Custom/Warehouses/Routes/api.php';
        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString(
            "->put('/warehouses/{uuid}/stock-movements/{itemUuid}/edit'",
            $content,
            'edit must be PUT — native EditForm always sends PUT'
        );
        $this->assertStringContainsString(
            "->delete('/warehouses/{uuid}/stock-movements/{itemUuid}/delete'",
            $content,
            'delete must be DELETE — native DeleteForm always sends DELETE'
        );
        $this->assertStringContainsString(
            "->post('/warehouses/{uuid}/stock-movements/create'",
            $content
        );
        $this->assertStringContainsString(
            "->get('/warehouses/{uuid}/stock-movements/list'",
            $content
        );
        $this->assertStringContainsString(
            "->get('/warehouses/{uuid}/stock-movements/{itemUuid}/view'",
            $content
        );

        // The bug's exact symptom: edit/delete silently registered as POST.
        $this->assertStringNotContainsString(
            "->post('/warehouses/{uuid}/stock-movements/{itemUuid}/edit'",
            $content
        );
        $this->assertStringNotContainsString(
            "->post('/warehouses/{uuid}/stock-movements/{itemUuid}/delete'",
            $content
        );
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
