<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Seeders;

use Blutrixx\GeneratorEngine\Generators\Backend\Seeders\SeederGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Deliberately reversed 2026-08-10 (was "no redundant CRUD call" until then).
 *
 * The 2026-08-08 fix this class used to guard (removing `seeder.stub`'s
 * `Helpers::saveModuleCRUDPermissions($module)` call in favor of relying
 * entirely on `mergeListPermissions()`'s JSON-driven, feature-aware list)
 * was real and well-reasoned — it fixed an actual `UniqueConstraintViolationException`
 * and was strictly more correct (only emits `{module}.delete` when `delete`
 * is genuinely enabled on that module, instead of unconditionally for every
 * module regardless of which CRUD features it actually has).
 *
 * Reversed anyway: every already-generated module in the primary consuming
 * project (SYSTEM_SHELL) had quietly drifted BACK to calling both — 17+
 * modules independently carrying the "redundant" shape this fix was meant
 * to prevent, discovered live while investigating an unrelated missing-seed-data
 * report. Rather than treat that as 17 individual regressions to silently
 * re-fix, the call was restored as the deliberate, permanent design: a
 * single obvious place (`Helpers::saveModuleCRUDPermissions()`) that always
 * creates the base `list/view/create/edit/delete/bulkAction` set for any
 * module name, with `{Module}SeederData.json`'s `permissions` array holding
 * ONLY genuine extras (import, custom actions, deleteCheck, anything the
 * Helper doesn't cover).
 *
 * Known, accepted tradeoff (the reason this needed a deliberate decision,
 * not just a revert): the Helper is feature-blind. A module with `delete`
 * disabled still gets a `{module}.delete` permission row it has no route or
 * service for. This is intentionally NOT fixed here — every already-generated
 * module needs a manual audit-and-cleanup pass to prune any bogus
 * over-provisioned permission rows this reintroduces, tracked separately
 * from this change.
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

    private function generateAndReadSeederData(string $moduleName, array $config): array
    {
        $generator = new SeederGenerator($moduleName, 'Core', $config);
        $this->assertTrue($generator->generate());

        $filePath = PathManager::getBackendModulePath('Core', $moduleName) . "/Seeders/{$moduleName}SeederData.json";
        $this->assertFileExists($filePath);

        return json_decode(file_get_contents($filePath), true);
    }

    public function test_generated_seeder_always_calls_the_unconditional_crud_permissions_helper(): void
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

        $this->assertStringContainsString("Helpers::saveModuleCRUDPermissions('Widgets');", $content);
        // The JSON-driven loop still exists too -- it's the only source for
        // genuine extras (import, custom actions, deleteCheck).
        $this->assertStringContainsString("foreach (\$this->jsonData['permissions'] as \$record)", $content);
        $this->assertStringContainsString('Helpers::savePermission($record);', $content);
    }

    public function test_generated_seeder_calls_the_helper_regardless_of_which_features_are_enabled(): void
    {
        // A module with only 'list' enabled -- the narrowest real shape --
        // must still call the Helper (it's unconditional by design now).
        $config = [
            'id_type' => 'autoincrement',
            'features' => [
                'backend' => [
                    'list' => true,
                ],
            ],
        ];

        $content = $this->generateAndReadSeeder('MinimalWidgets', $config);

        $this->assertStringContainsString('saveModuleCRUDPermissions', $content);
    }

    public function test_generated_permissions_json_no_longer_contains_the_base_crud_or_bulk_action_set(): void
    {
        // The base 5 + bulkAction are the Helper's job now -- the JSON list
        // must not duplicate them, only genuine extras (deleteCheck here).
        $config = [
            'id_type' => 'autoincrement',
            'features' => [
                'backend' => [
                    'list'        => true,
                    'view'        => true,
                    'create'      => true,
                    'edit'        => true,
                    'delete'      => true,
                    'deleteCheck' => true,
                ],
            ],
        ];

        $jsonData = $this->generateAndReadSeederData('Gadgets', $config);
        $names = array_column($jsonData['permissions'], 'name');

        foreach (['Gadgets.list', 'Gadgets.view', 'Gadgets.create', 'Gadgets.edit', 'Gadgets.delete', 'Gadgets.bulkAction'] as $covered) {
            $this->assertNotContains($covered, $names, "{$covered} is covered by the Helper now, must not be duplicated in the JSON list");
        }
        $this->assertContains('Gadgets.deleteCheck', $names, 'deleteCheck is NOT covered by the Helper, must still be JSON-derived');
    }

    public function test_generated_permissions_json_still_carries_hand_authored_extras_and_import(): void
    {
        $config = [
            'id_type' => 'autoincrement',
            'seeder' => [
                'permissions' => [
                    ['name' => 'Widgets.export', 'module' => 'Widgets', 'title' => 'Export Widgets', 'description' => 'Permission to export Widgets'],
                ],
            ],
            'features' => [
                'backend' => [
                    'list' => ['import' => true],
                ],
            ],
        ];

        $jsonData = $this->generateAndReadSeederData('Widgets', $config);
        $names = array_column($jsonData['permissions'], 'name');

        $this->assertContains('Widgets.export', $names);
        $this->assertContains('Widgets.import', $names);
        $this->assertNotContains('Widgets.list', $names);
        $this->assertNotContains('Widgets.bulkAction', $names);
    }
}
