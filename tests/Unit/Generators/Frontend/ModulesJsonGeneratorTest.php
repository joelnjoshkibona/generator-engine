<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend;

use Blutrixx\GeneratorEngine\Generators\Frontend\ModulesJsonGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for ModulesJsonGenerator's `type`/`group` fields.
 *
 * Added 2026-08-02 alongside SYSTEM_SHELL's e2e-test-by-group wiring:
 * modules.json only ever carried a bare `path` per module, so there was no
 * way to discover "every Core module" or "every module in the Locations
 * group" from it -- the exact thing a group/type-filtered e2e test runner
 * needs. Mirrors RegistryGenerator's own `'type' => $this->moduleGroup`
 * convention (see RegistryGeneratorTest) so frontend module discovery uses
 * the same Kernel/Core/System/Custom taxonomy the backend registry already
 * does, instead of inventing a second one.
 *
 * `group` (the module's sub-group, e.g. "Locations", or a Custom module's
 * own business-domain name like "Expenses") is only present when one was
 * actually set on the generator -- most modules have none, and an absent
 * key reads more cleanly in modules.json than `"group": null` scattered
 * across every entry.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\ModulesJsonGenerator
 */
class ModulesJsonGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-modulesjson-test-' . uniqid();
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

    private function readModulesJson(): array
    {
        $path = PathManager::getFrontendSrcPath() . '/modules.json';
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), true);
    }

    public function test_core_module_without_subgroup_gets_type_only(): void
    {
        $generator = new ModulesJsonGenerator('Media', 'Core', []);
        $this->assertTrue($generator->generate());

        $modules = $this->readModulesJson();
        $this->assertSame(
            ['path' => '/modules/core/Media', 'type' => 'Core'],
            $modules['Media']
        );
    }

    public function test_core_module_with_subgroup_gets_type_and_group(): void
    {
        PathManager::setModuleSubGroup('Locations');

        $generator = new ModulesJsonGenerator('Countries', 'Core', []);
        $this->assertTrue($generator->generate());

        $modules = $this->readModulesJson();
        $this->assertSame(
            [
                'path' => '/modules/core/Locations/Countries',
                'type' => 'Core',
                'group' => 'Locations',
            ],
            $modules['Countries']
        );
    }

    public function test_system_module_gets_system_type(): void
    {
        PathManager::setModuleSubGroup('Analytics');

        $generator = new ModulesJsonGenerator('Dashboard', 'System', []);
        $this->assertTrue($generator->generate());

        $modules = $this->readModulesJson();
        $this->assertSame('System', $modules['Dashboard']['type']);
        $this->assertSame('Analytics', $modules['Dashboard']['group']);
    }

    public function test_custom_module_with_domain_group_gets_custom_type(): void
    {
        PathManager::setModuleSubGroup('Expenses');

        $generator = new ModulesJsonGenerator('BudgetItems', 'Custom', []);
        $this->assertTrue($generator->generate());

        $modules = $this->readModulesJson();
        $this->assertSame(
            [
                'path' => '/modules/custom/Expenses/BudgetItems',
                'type' => 'Custom',
                'group' => 'Expenses',
            ],
            $modules['BudgetItems']
        );
    }

    public function test_getmoduleentry_matches_what_generate_writes(): void
    {
        PathManager::setModuleSubGroup('Notifications');
        $generator = new ModulesJsonGenerator('Broadcasts', 'Core', []);

        $entry = $generator->getModuleEntry();
        $this->assertTrue($generator->generate());

        $modules = $this->readModulesJson();
        $this->assertSame($entry, $modules['Broadcasts']);
    }

    public function test_regenerating_an_existing_module_preserves_other_entries(): void
    {
        $first = new ModulesJsonGenerator('Media', 'Core', []);
        $this->assertTrue($first->generate());

        PathManager::resetModuleSubGroup();
        $second = new ModulesJsonGenerator('Statuses', 'Core', []);
        $this->assertTrue($second->generate());

        $modules = $this->readModulesJson();
        $this->assertArrayHasKey('Media', $modules);
        $this->assertArrayHasKey('Statuses', $modules);
        $this->assertSame('Core', $modules['Media']['type']);
        $this->assertSame('Core', $modules['Statuses']['type']);
    }
}
