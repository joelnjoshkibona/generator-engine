<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Controller;

use Blutrixx\GeneratorEngine\Generators\Backend\Controller\ControllerGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * The controller's standard service imports follow the same feature resolution as its methods and routes.
 *
 * They came from a fixed list, so a module whose `delete` (or `edit`, ...) had been removed from
 * features.backend on purpose still got `use ...OrdersDeleteService;` back on every scoped regen: the route and
 * the method stayed gone, but an import for a class that no longer exists on disk returned. Harmless at
 * runtime, but dead code creeping back into a file that had been cleaned up. Confirmed twice.
 */
class ControllerServiceImportsFollowFeaturesTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-controller-imports-' . uniqid();
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

    /** @param array<string, mixed> $backend features.backend */
    private function controller(array $backend, array $extra = []): string
    {
        return (new ControllerGenerator('Orders', 'Core', array_replace([
            'table_name' => 'orders',
            'columns'    => [['name' => 'reference', 'type' => 'string']],
            'features'   => ['backend' => $backend, 'frontend' => []],
        ], $extra)))->buildContent();
    }

    private function imports(string $content): array
    {
        preg_match_all('/^use [^;]+\\\\Services\\\\Orders(\w+)Service;$/m', $content, $m);

        return $m[1];
    }

    public function test_a_module_with_every_feature_imports_every_service_in_the_order_it_always_did(): void
    {
        $all = ['list' => true, 'create' => true, 'view' => true, 'edit' => true, 'delete' => true];

        $this->assertSame(
            ['List', 'Create', 'View', 'Edit', 'Delete', 'DeleteCheck', 'ActivityList'],
            $this->imports($this->controller($all))
        );
    }

    public function test_a_removed_delete_feature_takes_its_two_imports_with_it(): void
    {
        $content = $this->controller(['list' => true, 'create' => true, 'view' => true, 'edit' => true]);

        $this->assertSame(['List', 'Create', 'View', 'Edit', 'ActivityList'], $this->imports($content));
        $this->assertStringNotContainsString('OrdersDeleteService', $content);
        $this->assertStringNotContainsString('OrdersDeleteCheckService', $content);
    }

    public function test_a_removed_edit_feature_takes_its_import_with_it(): void
    {
        $content = $this->controller(['list' => true, 'create' => true, 'view' => true, 'delete' => true]);

        $this->assertNotContains('Edit', $this->imports($content));
        $this->assertStringNotContainsString('OrdersEditService', $content);
    }

    public function test_the_activity_list_service_is_always_imported_because_the_trait_needs_it(): void
    {
        $this->assertSame(['List', 'ActivityList'], $this->imports($this->controller(['list' => true])));
    }

    public function test_splash_services_need_both_the_constants_and_the_feature_key(): void
    {
        $base = ['list' => true, 'create' => true, 'edit' => true];

        $this->assertNotContains('CreateSplash', $this->imports($this->controller($base + ['createSplash' => true])), 'no constants declared');
        $this->assertNotContains('CreateSplash', $this->imports($this->controller($base, ['constants' => ['NEW' => 'new']])), 'constants but no feature key');
        $this->assertContains('CreateSplash', $this->imports($this->controller($base + ['createSplash' => true], ['constants' => ['NEW' => 'new']])));
    }

    public function test_the_imports_and_the_methods_never_disagree(): void
    {
        $content = $this->controller(['list' => true, 'view' => true]);

        foreach (['Create', 'Edit', 'Delete', 'DeleteCheck'] as $service) {
            $this->assertStringNotContainsString("Orders{$service}Service", $content, "{$service}: no method, no import");
        }
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
}
