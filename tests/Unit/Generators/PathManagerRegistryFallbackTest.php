<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators;

use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a bug found + fixed 2026-08-08 while live-verifying
 * the orders-suite integration fixture (tests/Fixtures/integration-schemas/orders-suite/):
 * scaffolding a child module (OrderItems, FK to Orders) before its FK-target
 * parent (Orders, a Custom-type module) has ever been scaffolded made
 * ModelGenerator emit `\App\Project\Modules\Core\Orders\OrdersModel::class`
 * in the generated belongsTo() relation — silently wrong, since Orders is
 * actually Custom-type.
 *
 * Root cause: resolveBackendModuleNamespaceOrNull() only ever checked (1)
 * the in-memory array-based module registry and (2) default_modules.json
 * (SYSTEM_SHELL-shipped modules only) before falling through to a bare
 * "Core" guess. guessedModuleExists() (ModelGenerator.php, used only for
 * FK names *guessed* from a column name with no real FK constraint)
 * already had a fuller resolution chain that also checks the generated
 * project's own persisted registry_core.json/registry.json files and the
 * actual on-disk module directory structure — but a module name resolved
 * from a REAL FK constraint (the higher-confidence, common case) never got
 * that same treatment.
 *
 * This does NOT fix the exact ordering case live-reproduced above — at the
 * moment OrderItems is scaffolded, Orders genuinely does not exist in any
 * registry file yet, so there is nothing for step 3 to find (that specific
 * case self-corrects only by regenerating OrderItems with --force after
 * Orders exists — see resolveBackendModuleNamespace()'s improved warning
 * message). What this fixes is the more general case: the target module
 * WAS already scaffolded (in a prior, separate invocation), but the
 * in-memory array registry — built fresh per CLI invocation from a
 * filesystem snapshot — happened to miss it while the persisted registry
 * file already had the right answer.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\PathManager::resolveBackendModuleNamespaceOrNull()
 */
class PathManagerRegistryFallbackTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-pathmanager-registry-fallback-' . uniqid();
        mkdir($this->tmpRoot . '/BACKEND/app/Project/_Src', 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
        PathManager::setModuleRegistry([]);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        PathManager::setModuleRegistry([]);
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

    private function writeRegistry(string $file, array $entries): void
    {
        file_put_contents(
            $this->tmpRoot . '/BACKEND/app/Project/_Src/' . $file,
            json_encode($entries)
        );
    }

    public function test_resolves_from_registry_json_when_array_registry_is_empty(): void
    {
        $this->writeRegistry('registry.json', [
            'Orders' => [
                'namespace'   => 'App\\Project\\Modules\\Custom\\Orders',
                'path'        => 'app/Project/Modules/Custom/Orders',
                'type'        => 'Custom',
                'description' => 'Orders module',
            ],
        ]);

        $namespace = PathManager::resolveBackendModuleNamespaceOrNull('Orders');

        $this->assertSame('App\\Project\\Modules\\Custom\\Orders', $namespace);
    }

    public function test_resolves_from_registry_core_json_too(): void
    {
        $this->writeRegistry('registry_core.json', [
            'Roles' => [
                'namespace'   => 'App\\Project\\Modules\\Core\\Access\\Roles',
                'path'        => 'app/Project/Modules/Core/Access/Roles',
                'type'        => 'Core',
                'description' => 'Roles module',
            ],
        ]);

        $namespace = PathManager::resolveBackendModuleNamespaceOrNull('Roles');

        $this->assertSame('App\\Project\\Modules\\Core\\Access\\Roles', $namespace);
    }

    public function test_resolveBackendModuleNamespace_no_longer_falls_back_to_core_when_registry_json_has_the_answer(): void
    {
        $this->writeRegistry('registry.json', [
            'Orders' => [
                'namespace'   => 'App\\Project\\Modules\\Custom\\Orders',
                'path'        => 'app/Project/Modules/Custom/Orders',
                'type'        => 'Custom',
                'description' => 'Orders module',
            ],
        ]);

        $namespace = PathManager::resolveBackendModuleNamespace('Orders');

        $this->assertSame('App\\Project\\Modules\\Custom\\Orders', $namespace);
        $this->assertStringNotContainsString('Core', $namespace);
    }

    public function test_returns_null_when_module_is_genuinely_unresolvable_anywhere(): void
    {
        // No registry.json/registry_core.json written at all — mirrors the
        // exact live-reproduced case: a child module's FK-target parent
        // hasn't been scaffolded anywhere yet.
        $namespace = PathManager::resolveBackendModuleNamespaceOrNull('Orders');

        $this->assertNull($namespace);
    }

    public function test_array_registry_still_wins_over_registry_json_when_both_present(): void
    {
        $this->writeRegistry('registry.json', [
            'Orders' => [
                'namespace' => 'App\\Project\\Modules\\Custom\\Orders',
                'type'      => 'Custom',
            ],
        ]);
        PathManager::setModuleRegistry([
            ['name' => 'Orders', 'module_type' => 'System', 'group_name' => null],
        ]);

        $namespace = PathManager::resolveBackendModuleNamespaceOrNull('Orders');

        $this->assertSame('App\\Project\\Modules\\System\\Orders', $namespace);
    }
}
