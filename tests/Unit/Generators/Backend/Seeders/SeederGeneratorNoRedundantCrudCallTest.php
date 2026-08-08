<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Seeders;

use Blutrixx\GeneratorEngine\Generators\Backend\Seeders\SeederGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a bug found + fixed 2026-08-08 while running all 5
 * integration-test suites' fixtures simultaneously against a real consuming
 * project (SYSTEM_SHELL): `seeder.stub`'s generated `permissions()` method
 * called BOTH the unconditional `Helpers::saveModuleCRUDPermissions($module)`
 * AND looped `$this->jsonData['permissions']` — which `mergeListPermissions()`
 * already builds as a STRICT SUPERSET of the unconditional call (every CRUD
 * action `saveModuleCRUDPermissions()` always creates, plus bulkAction/
 * import/custom-action permissions it doesn't know about at all). The
 * unconditional call is also LESS correct than the JSON-derived list: it
 * creates a permission for every one of the five standard CRUD actions
 * regardless of whether that feature is actually enabled on the module,
 * while `mergeListPermissions()` only emits one when the corresponding
 * `features.backend.{action}` is truthy.
 *
 * `savePermission()` is idempotent (checks existence before insert), so this
 * redundancy was harmless under normal single-pass seeding — confirmed via
 * every one of the 17 real modules in SYSTEM_SHELL, which have carried this
 * exact pattern without incident. It only surfaced as a hard
 * UniqueConstraintViolationException in a scenario involving a phantom
 * soft-deleted permission row from an unrelated incomplete-teardown bug
 * (SYSTEM_SHELL-side, not this package) — but the redundant call was still
 * worth removing on its own merits: fewer wasted queries per seed, and no
 * longer over-provisioning permissions for disabled features.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Seeders\SeederGenerator::mergeListPermissions()
 */
class SeederGeneratorNoRedundantCrudCallTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-seeder-no-redundant-crud-' . uniqid();
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

    private function generateAndReadSeeder(string $moduleName, array $config): string
    {
        $generator = new SeederGenerator($moduleName, 'Core', $config);
        $this->assertTrue($generator->generate());

        $filePath = PathManager::getBackendModulePath('Core', $moduleName) . "/Seeders/{$moduleName}Seeder.php";
        $this->assertFileExists($filePath);

        return file_get_contents($filePath);
    }

    public function test_generated_seeder_does_not_call_the_unconditional_crud_permissions_helper(): void
    {
        $config = [
            'id_type' => 'autoincrement',
            'features' => [
                'backend' => [
                    'list'   => true,
                    'view'   => true,
                    'create' => true,
                    'edit'   => true,
                    'delete' => true,
                ],
            ],
        ];

        $content = $this->generateAndReadSeeder('Widgets', $config);

        $this->assertStringNotContainsString('saveModuleCRUDPermissions', $content);
        // The JSON-driven loop -- the sole remaining permission-seeding
        // path -- must still be present.
        $this->assertStringContainsString("foreach (\$this->jsonData['permissions'] as \$record)", $content);
        $this->assertStringContainsString('Helpers::savePermission($record);', $content);
    }

    public function test_generated_seeder_never_mentions_the_helper_regardless_of_which_features_are_enabled(): void
    {
        // A module with only 'list' enabled -- the narrowest real shape --
        // must still never reference the unconditional helper, confirming
        // this isn't gated on some feature combination happening to hide it.
        $config = [
            'id_type' => 'autoincrement',
            'features' => [
                'backend' => [
                    'list' => true,
                ],
            ],
        ];

        $content = $this->generateAndReadSeeder('MinimalWidgets', $config);

        $this->assertStringNotContainsString('saveModuleCRUDPermissions', $content);
    }
}
