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
 * Confirmed against the real, untouched
 * BACKEND/app/Project/Modules/System/Masters/ItemCategories/Seeders/
 * ItemCategoriesSeederData.json, which still shows this exact
 * raw-concatenation pattern in every auto-derived CRUD/bulkAction
 * permission.
 *
 * Fix (see mergeListPermissions()): `title`/`description` now run through
 * BaseGenerator::humanize(), keeping the plural form already established by
 * real permission data (Users: "Bulk Actions on Users"). The permission
 * `name`/`module` fields are left untouched — they are identifiers (matched
 * against route meta.permission, DB rows, and the Roles > Permissions tab's
 * grouping key), not display text, so humanizing them is a separate,
 * out-of-scope concern.
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

    public function test_auto_derived_crud_and_bulk_permissions_have_humanized_plural_title_and_description(): void
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

        $generator = new SeederGenerator('ItemCategories', 'System', $config);
        $permissions = $this->permissionsOf($generator);
        $byName = [];
        foreach ($permissions as $perm) {
            $byName[$perm['name']] = $perm;
        }

        $this->assertSame('List Item Categories', $byName['ItemCategories.list']['title']);
        $this->assertSame('Permission to list Item Categories', $byName['ItemCategories.list']['description']);
        $this->assertSame('Create Item Categories', $byName['ItemCategories.create']['title']);
        $this->assertSame('Bulk Actions on Item Categories', $byName['ItemCategories.bulkAction']['title']);
        $this->assertSame('Permission to run bulk actions on Item Categories', $byName['ItemCategories.bulkAction']['description']);

        // The `name`/`module` fields are identifiers, not display text, and
        // must stay raw (unspaced) PascalCase — they are matched elsewhere
        // (route meta.permission, DB rows, Roles > Permissions tab grouping).
        $this->assertSame('ItemCategories.list', $byName['ItemCategories.list']['name']);
        $this->assertSame('ItemCategories', $byName['ItemCategories.list']['module']);

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
                'backend' => ['list' => true],
            ],
        ];

        $generator = new SeederGenerator('ZzzGeneratorVerifyTest', 'System', $config);
        $permissions = $this->permissionsOf($generator);

        $listPerm = null;
        foreach ($permissions as $perm) {
            if ($perm['name'] === 'ZzzGeneratorVerifyTest.list') {
                $listPerm = $perm;
            }
        }

        $this->assertNotNull($listPerm);
        $this->assertSame('List Zzz Generator Verify Test', $listPerm['title']);
        $this->assertSame('Permission to list Zzz Generator Verify Test', $listPerm['description']);
    }
}
