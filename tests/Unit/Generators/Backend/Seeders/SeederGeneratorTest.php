<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Seeders;

use Blutrixx\GeneratorEngine\Generators\Backend\Seeders\SeederGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Regression coverage for SeederGenerator's auto-derived permission
 * title/description text.
 *
 * Bug: `mergeListPermissions()` interpolated the raw PascalCase module name
 * verbatim into permission `title`/`description` — e.g. a freshly
 * scaffolded "ItemCategories" module seeded a permission
 * `"title": "List ItemCategories"` instead of "List Item Categories".
 *
 * Fix (see mergeListPermissions()): `title`/`description` now run through
 * BaseGenerator::humanize(), keeping the plural form already established by
 * real permission data (Users: "Bulk Actions on Users"). The permission
 * `name`/`module` fields are left untouched — they are identifiers (matched
 * against route meta.permission, DB rows, and the Roles > Permissions tab's
 * grouping key), not display text, so humanizing them is a separate,
 * out-of-scope concern.
 *
 * v2.53.0 note: the base list/view/create/edit/delete/bulkAction set moved
 * to the unconditional `Helpers::saveModuleCRUDPermissions()` call (see
 * SeederGeneratorNoRedundantCrudCallTest) and is no longer JSON-derived at
 * all — that Helper's own title/description wording is a separate,
 * SYSTEM_SHELL-side concern, not humanized by this class. The tests below
 * now exercise humanization via `deleteCheck`/custom `actions`, the
 * mechanisms mergeListPermissions() still owns.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Seeders\SeederGenerator
 */
class SeederGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-seeder-test-' . uniqid();
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

    /** @return array<int, array<string, mixed>> */
    private function permissionsOf(SeederGenerator $generator): array
    {
        $prop = new ReflectionProperty($generator, 'permissions');
        $prop->setAccessible(true);

        return $prop->getValue($generator);
    }

    public function test_auto_derived_delete_check_permission_has_humanized_plural_title_and_description(): void
    {
        $config = [
            'id_type' => 'autoincrement',
            'features' => [
                'backend' => [
                    'list'        => true,
                    'deleteCheck' => true,
                ],
            ],
        ];

        $generator = new SeederGenerator('ItemCategories', 'System', $config);
        $permissions = $this->permissionsOf($generator);
        $byName = [];
        foreach ($permissions as $perm) {
            $byName[$perm['name']] = $perm;
        }

        $this->assertSame('Delete Check Item Categories', $byName['ItemCategories.deleteCheck']['title']);
        $this->assertSame('Permission to deleteCheck Item Categories', $byName['ItemCategories.deleteCheck']['description']);

        // The `name`/`module` fields are identifiers, not display text, and
        // must stay raw (unspaced) PascalCase — they are matched elsewhere
        // (route meta.permission, DB rows, Roles > Permissions tab grouping).
        $this->assertSame('ItemCategories.deleteCheck', $byName['ItemCategories.deleteCheck']['name']);
        $this->assertSame('ItemCategories', $byName['ItemCategories.deleteCheck']['module']);

        // No title/description may contain the raw unspaced module name.
        foreach ($permissions as $perm) {
            $this->assertStringNotContainsString('ItemCategories', $perm['title'], "Permission title '{$perm['title']}' still contains the raw unspaced module name.");
            $this->assertStringNotContainsString('ItemCategories', $perm['description'], "Permission description '{$perm['description']}' still contains the raw unspaced module name.");
        }
    }

    public function test_multiword_pascalcase_module_name_is_humanized_in_permission_text(): void
    {
        $config = [
            'id_type' => 'autoincrement',
            'features' => [
                'backend' => ['list' => true, 'deleteCheck' => true],
            ],
        ];

        $generator = new SeederGenerator('ZzzGeneratorVerifyTest', 'System', $config);
        $permissions = $this->permissionsOf($generator);

        $deleteCheckPerm = null;
        foreach ($permissions as $perm) {
            if ($perm['name'] === 'ZzzGeneratorVerifyTest.deleteCheck') {
                $deleteCheckPerm = $perm;
            }
        }

        $this->assertNotNull($deleteCheckPerm);
        $this->assertSame('Delete Check Zzz Generator Verify Test', $deleteCheckPerm['title']);
        $this->assertSame('Permission to deleteCheck Zzz Generator Verify Test', $deleteCheckPerm['description']);
    }

    /**
     * Delegations resolve every operation's permission to the RELATED
     * module's own permission (DelegationConfigNormalizer::
     * resolveOperationPermission()) — never a delegation-specific
     * "{Module}.{Delegation}.{op}" one. A parent module with a delegations
     * entry must therefore seed no such permission at all; the related
     * module's own SeederGenerator run (against its own config) is the sole
     * source, whenever it has the corresponding feature enabled.
     */
    public function test_delegations_seed_no_delegation_specific_permission(): void
    {
        $config = [
            'id_type' => 'autoincrement',
            'features' => [
                'backend' => ['list' => true, 'view' => true],
            ],
            'delegations' => [
                'stockMovements' => [
                    'name' => 'StockMovements',
                    'relatedModule' => ['name' => 'StockMovements', 'group' => 'Custom'],
                    'operations' => [
                        'list' => ['enabled' => true, 'backend' => ['bulk_actions' => [['key' => 'archive']], 'import' => true]],
                        'create' => ['enabled' => true],
                        'edit' => ['enabled' => true],
                        'view' => ['enabled' => true],
                        'delete' => ['enabled' => true],
                    ],
                ],
            ],
        ];

        $generator = new SeederGenerator('Warehouses', 'Custom', $config);
        $permissions = $this->permissionsOf($generator);

        foreach ($permissions as $perm) {
            $this->assertStringNotContainsString(
                'StockMovements',
                $perm['name'],
                "Permission '{$perm['name']}' is delegation-specific — it should not exist; StockMovements' own SeederGenerator run seeds StockMovements.* instead."
            );
        }

        // Warehouses' own standalone list/view permissions are covered by
        // Helpers::saveModuleCRUDPermissions() now (see
        // SeederGeneratorNoRedundantCrudCallTest), not JSON-derived — this
        // config's 'list'/'view' features produce no JSON permission entry
        // at all, only confirming no delegation-specific leak above.
        $names = array_column($permissions, 'name');
        $this->assertNotContains('Warehouses.list', $names);
        $this->assertNotContains('Warehouses.view', $names);
    }
}
