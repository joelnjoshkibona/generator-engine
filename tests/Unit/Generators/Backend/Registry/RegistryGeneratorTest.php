<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Registry;

use Blutrixx\GeneratorEngine\Generators\Backend\Registry\RegistryGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for RegistryGenerator's group-gated registry write.
 *
 * Bug: `updateCoreRegistry()`/`updateSystemRegistry()` each began with a
 * guard that returned `true` ("skip") for any module group other than
 * exactly 'Core' or exactly 'System'. Any other group - notably 'Custom',
 * the group the package's own integration-schema fixtures instruct users
 * to generate into - matched neither guard, so BOTH methods skipped and
 * the module was never written to any registry file at all, even though
 * `generate()` still returned `true`. Confirmed end-to-end against the
 * real consuming app: an unregistered module has no routes and no
 * migrations loaded by ApplicationServiceProvider::registerModules(),
 * which iterates Registry::getRegistry().
 *
 * Fix: `updateRegistryForGroup()`/`removeFromRegistryForGroup()` now write
 * to registry_core.json for 'Core' and to the shared registry.json tier
 * for every other group (System, Custom, or anything else a project
 * invents), matching the consuming app's Registry.php tiering.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Registry\RegistryGenerator
 */
class RegistryGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-registry-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
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

    private function registryPath(string $filename): string
    {
        return PathManager::getBackendRegistryPath() . '/' . $filename;
    }

    private function readRegistry(string $filename): array
    {
        $path = $this->registryPath($filename);
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), true);
    }

    public function test_core_module_is_written_to_registry_core_json(): void
    {
        $generator = new RegistryGenerator('Users', 'Core', []);

        $this->assertTrue($generator->generate());

        $registry = $this->readRegistry('registry_core.json');
        $this->assertArrayHasKey('Users', $registry);
        $this->assertSame('Core', $registry['Users']['type']);
        $this->assertStringContainsString('/Core/', $registry['Users']['path']);
        $this->assertFileDoesNotExist($this->registryPath('registry.json'));
    }

    public function test_system_module_is_written_to_registry_json(): void
    {
        $generator = new RegistryGenerator('Notifications', 'System', []);

        $this->assertTrue($generator->generate());

        $registry = $this->readRegistry('registry.json');
        $this->assertArrayHasKey('Notifications', $registry);
        $this->assertSame('System', $registry['Notifications']['type']);
        $this->assertStringContainsString('/System/', $registry['Notifications']['path']);
        $this->assertFileDoesNotExist($this->registryPath('registry_core.json'));
    }

    public function test_custom_module_is_written_to_registry_json_with_custom_type(): void
    {
        $generator = new RegistryGenerator('Items', 'Custom', []);

        $this->assertTrue($generator->generate());

        $registry = $this->readRegistry('registry.json');
        $this->assertArrayHasKey('Items', $registry);
        $this->assertSame('Custom', $registry['Items']['type']);
        $this->assertStringContainsString('/Custom/', $registry['Items']['path']);
        $this->assertArrayHasKey('namespace', $registry['Items']);
        $this->assertArrayHasKey('description', $registry['Items']);
        $this->assertFileDoesNotExist($this->registryPath('registry_core.json'));
    }

    public function test_nested_sub_grouped_custom_module_gets_correct_path(): void
    {
        PathManager::setModuleSubGroup('Inventory');

        $generator = new RegistryGenerator('Items', 'Custom', []);
        $this->assertTrue($generator->generate());

        $registry = $this->readRegistry('registry.json');
        $this->assertSame(
            'app/Project/Modules/Custom/Inventory/Items',
            $registry['Items']['path']
        );
        $this->assertSame('App\\Project\\Modules\\Custom\\Inventory\\Items', $registry['Items']['namespace']);
    }

    public function test_custom_module_can_be_removed_from_registry(): void
    {
        $generator = new RegistryGenerator('Items', 'Custom', []);
        $this->assertTrue($generator->generate());

        $registry = $this->readRegistry('registry.json');
        $this->assertArrayHasKey('Items', $registry);

        $this->assertTrue($generator->removeFromRegistry());

        $registry = $this->readRegistry('registry.json');
        $this->assertArrayNotHasKey('Items', $registry);
    }

    public function test_registry_entry_keeps_exact_shape(): void
    {
        $generator = new RegistryGenerator('Items', 'Custom', ['module' => ['description' => 'Item catalogue']]);
        $this->assertTrue($generator->generate());

        $registry = $this->readRegistry('registry.json');
        $this->assertSame(
            ['namespace', 'path', 'type', 'description'],
            array_keys($registry['Items'])
        );
        $this->assertSame('Item catalogue', $registry['Items']['description']);
    }
}
