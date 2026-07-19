<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend;

use Blutrixx\GeneratorEngine\Generators\Frontend\MenusJsonGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for MenusJsonGenerator.
 *
 * Bug: menu item `title` interpolated the raw PascalCase module name
 * verbatim — e.g. a freshly scaffolded "ItemCategories" module produced a
 * sidebar menu entry `"title": "ItemCategories"` instead of "Item
 * Categories". Confirmed in the real, untouched FRONTEND/src/menus.json
 * (System > ItemCategories entry).
 *
 * Fix (see createSimpleMenuItem()/createNestedMenuItem()/the default
 * menu_config fallback): titles now run through BaseGenerator::humanize().
 * The nested-menu "All X"/"Create X" sub-items follow the same
 * plural-list/singular-action convention established by
 * FrontendLocaleGenerator and confirmed by the real Roles/Users routes
 * ("Create Role", "Create User").
 *
 * The removeModuleFromMenus()/countModuleMenus()/moduleExistsInMenus()
 * lookups previously matched menu items by the raw moduleName; they now
 * match against the same humanized title that gets written, so
 * regenerating (or --force re-running) a module still replaces its single
 * existing menu entry instead of duplicating it.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\MenusJsonGenerator
 */
class MenusJsonGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-menus-test-' . uniqid();
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

    private function menusJsonPath(): string
    {
        return $this->tmpRoot . '/FRONTEND/src/menus.json';
    }

    public function test_generate_writes_humanized_menu_title_for_default_simple_item(): void
    {
        $generator = new MenusJsonGenerator('ItemCategories', 'System', []);
        $this->assertTrue($generator->generate());

        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $item = $menus[0]['items'][0];

        $this->assertSame('Item Categories', $item['title']);
        $this->assertSame('/item-categories/list', $item['url']);
        $this->assertSame('ItemCategories.list', $item['permission']);
    }

    public function test_generate_humanizes_multiword_pascalcase_module_name(): void
    {
        $generator = new MenusJsonGenerator('ZzzGeneratorVerifyTest', 'System', []);
        $generator->generate();

        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $item = $menus[0]['items'][0];

        $this->assertSame('Zzz Generator Verify Test', $item['title']);
    }

    public function test_nested_menu_item_uses_plural_all_and_singular_create(): void
    {
        $config = [
            'menu_config' => [
                'enabled' => true,
                'nested'  => true,
            ],
        ];

        $generator = new MenusJsonGenerator('ItemCategories', 'System', $config);
        $generator->generate();

        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $item = $menus[0]['items'][0];

        $this->assertSame('Item Categories', $item['title']);
        $this->assertSame('All Item Categories', $item['items'][0]['title']);
        $this->assertSame('Create Item Category', $item['items'][1]['title']);
    }

    /**
     * Regenerating the same module (e.g. `--force`) must replace its one
     * existing menu entry, not duplicate it — this was previously guarded by
     * matching item['title'] against the raw moduleName, which would have
     * silently broken (and started duplicating entries) once titles became
     * humanized text instead of the raw name.
     */
    public function test_regenerating_the_same_module_does_not_duplicate_its_menu_entry(): void
    {
        $generator = new MenusJsonGenerator('ItemCategories', 'System', []);
        $generator->generate();

        $regenerator = new MenusJsonGenerator('ItemCategories', 'System', []);
        $regenerator->generate();

        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $this->assertCount(1, $menus[0]['items'], 'Regenerating the module must not duplicate its menu entry.');
        $this->assertSame('Item Categories', $menus[0]['items'][0]['title']);
    }

    public function test_remove_from_menus_removes_the_humanized_entry(): void
    {
        $generator = new MenusJsonGenerator('ItemCategories', 'System', []);
        $generator->generate();

        $this->assertTrue($generator->moduleExistsInMenus());

        $generator->removeFromMenus();

        $this->assertFalse($generator->moduleExistsInMenus());
        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $this->assertCount(0, $menus[0]['items']);
    }
}
