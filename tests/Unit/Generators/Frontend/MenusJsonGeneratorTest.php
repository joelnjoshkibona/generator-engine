<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend;

use Blutrixx\GeneratorEngine\Generators\Frontend\MenusJsonGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $item = $menus[0]['items'][0]['items'][0];

        $this->assertSame('Item Categories', $item['title']);
        $this->assertSame('/item-categories/list', $item['url']);
        $this->assertSame('ItemCategories.list', $item['permission']);
    }

    public function test_generate_humanizes_multiword_pascalcase_module_name(): void
    {
        $generator = new MenusJsonGenerator('ZzzGeneratorVerifyTest', 'System', []);
        $generator->generate();

        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $item = $menus[0]['items'][0]['items'][0];

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
        $item = $menus[0]['items'][0]['items'][0];

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
        $this->assertCount(1, $menus[0]['items'][0]['items'], 'Regenerating the module must not duplicate its menu entry.');
        $this->assertSame('Item Categories', $menus[0]['items'][0]['items'][0]['title']);
    }

    public function test_remove_from_menus_removes_the_humanized_entry(): void
    {
        $generator = new MenusJsonGenerator('ItemCategories', 'System', []);
        $generator->generate();

        $this->assertTrue($generator->moduleExistsInMenus());

        $generator->removeFromMenus();

        $this->assertFalse($generator->moduleExistsInMenus());
        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $this->assertCount(0, $menus[0]['items'][0]['items']);
    }

    /**
     * Bug 1 regression: real, untouched menus.json contained TWO copies of
     * ItemCategories/ItemTypes/ItemImages/ItemPrices after a second --force
     * run, because those entries were written with an explicit config title
     * ("ItemCategories", no space) via menu_config.items[0].title that does
     * NOT match humanize(moduleName) ("Item Categories"). The old
     * removeModuleFromMenus() only ever matched on the humanized title, so
     * it silently failed to find/replace the existing entry and a duplicate
     * was appended every run.
     *
     * The fix keys identity on the item's own route (url) first — which
     * stays stable across runs regardless of what title the config supplies
     * — so this must now dedupe correctly.
     */
    public function test_regenerating_a_module_with_a_custom_config_title_does_not_duplicate(): void
    {
        $config = [
            'menu_config' => [
                'enabled' => true,
                'section' => 'custom',
                'items' => [
                    ['title' => 'ItemCategories'], // raw title, deliberately not humanized
                ],
            ],
        ];

        (new MenusJsonGenerator('ItemCategories', 'Custom', $config))->generate();
        (new MenusJsonGenerator('ItemCategories', 'Custom', $config))->generate();

        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $customSection = array_values(array_filter($menus[0]['items'], fn($s) => $s['id'] === 'custom'))[0];

        $this->assertCount(1, $customSection['items'], 'A second run with a custom config title must update in place, not duplicate.');
        $this->assertSame('ItemCategories', $customSection['items'][0]['title']);
        $this->assertSame('/item-categories/list', $customSection['items'][0]['url']);
    }

    /**
     * The full real-world scenario: 5 modules generated twice each
     * (simulating a --force re-run of the whole blueprint) must never
     * produce more than one menu node per module.
     */
    public function test_regenerating_five_modules_twice_yields_exactly_one_entry_each(): void
    {
        $modules = ['ItemCategories', 'ItemTypes', 'ItemImages', 'ItemPrices', 'Items'];

        foreach ([1, 2] as $pass) {
            foreach ($modules as $moduleName) {
                (new MenusJsonGenerator($moduleName, 'Custom', [
                    'menu_config' => ['enabled' => true, 'section' => 'custom'],
                ]))->generate();
            }
        }

        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $customSection = array_values(array_filter($menus[0]['items'], fn($s) => $s['id'] === 'custom'))[0];

        $this->assertCount(count($modules), $customSection['items'], 'Each module must appear exactly once after two full generation passes.');
    }

    /**
     * Two different modules that happen to render the same display title
     * must remain two separate menu nodes — identity must not merge on
     * title alone (title is not module-unique; url is).
     */
    public function test_two_distinct_modules_sharing_a_title_are_not_merged(): void
    {
        $sharedTitleConfig = [
            'menu_config' => [
                'enabled' => true,
                'section' => 'custom',
                'items' => [
                    ['title' => 'Reports'],
                ],
            ],
        ];

        (new MenusJsonGenerator('SalesReports', 'Custom', $sharedTitleConfig))->generate();
        (new MenusJsonGenerator('StockReports', 'Custom', $sharedTitleConfig))->generate();

        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $customSection = array_values(array_filter($menus[0]['items'], fn($s) => $s['id'] === 'custom'))[0];

        $this->assertCount(2, $customSection['items'], 'Two distinct modules sharing a title must not be merged into one entry.');
        $this->assertSame('/sales-reports/list', $customSection['items'][0]['url']);
        $this->assertSame('/stock-reports/list', $customSection['items'][1]['url']);
    }

    /**
     * Regenerating a nested (self-contained group) module twice must not
     * duplicate its parent node either.
     */
    public function test_regenerating_a_nested_module_does_not_duplicate_its_group(): void
    {
        $config = ['menu_config' => ['enabled' => true, 'nested' => true]];

        (new MenusJsonGenerator('ItemCategories', 'System', $config))->generate();
        (new MenusJsonGenerator('ItemCategories', 'System', $config))->generate();

        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $this->assertCount(1, $menus[0]['items'][0]['items']);
        $this->assertCount(2, $menus[0]['items'][0]['items'][0]['items'], 'The nested group itself must not be duplicated either.');
    }

    // -- Bug 2: icon resolution -------------------------------------------------

    /**
     * Default single-item emission path (no menu_config at all): an explicit
     * top-level config icon must win over the heuristic/fallback.
     */
    public function test_explicit_icon_wins_for_the_default_emission_path(): void
    {
        $generator = new MenusJsonGenerator('ItemCategories', 'System', ['icon' => 'Rocket']);
        $generator->generate();

        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $this->assertSame('Rocket', $menus[0]['items'][0]['items'][0]['icon']);
    }

    /**
     * menu_config.items[0].icon (single-item, no children) must win.
     */
    public function test_explicit_icon_wins_for_config_items_single_item_path(): void
    {
        $config = [
            'menu_config' => [
                'enabled' => true,
                'items' => [
                    ['title' => 'Item Categories', 'icon' => 'Rocket'],
                ],
            ],
        ];

        (new MenusJsonGenerator('ItemCategories', 'System', $config))->generate();

        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $this->assertSame('Rocket', $menus[0]['items'][0]['items'][0]['icon']);
    }

    /**
     * menu_config.items[0].icon on a parent-with-children item must win too.
     */
    public function test_explicit_icon_wins_for_config_items_parent_with_children_path(): void
    {
        $config = [
            'menu_config' => [
                'enabled' => true,
                'items' => [
                    [
                        'title' => 'Items',
                        'icon' => 'Rocket',
                        'children' => [
                            ['title' => 'Item Categories', 'url' => '/item-categories/list'],
                        ],
                    ],
                ],
            ],
        ];

        (new MenusJsonGenerator('ItemCategories', 'System', $config))->generate();

        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $this->assertSame('Rocket', $menus[0]['items'][0]['items'][0]['icon']);
    }

    /**
     * menu_config.icon on the plain createSimpleMenuItem() path (menu_config
     * present but without an 'items' key) must win.
     */
    public function test_explicit_icon_wins_for_the_simple_menu_item_path(): void
    {
        $config = ['menu_config' => ['enabled' => true, 'icon' => 'Rocket']];

        (new MenusJsonGenerator('ItemCategories', 'System', $config))->generate();

        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $this->assertSame('Rocket', $menus[0]['items'][0]['items'][0]['icon']);
    }

    /**
     * menu_config.icon on the nested (group) path must apply to the parent
     * AND both "All X"/"Create X" subitems.
     */
    public function test_explicit_icon_wins_for_the_nested_menu_item_path(): void
    {
        $config = ['menu_config' => ['enabled' => true, 'nested' => true, 'icon' => 'Rocket']];

        (new MenusJsonGenerator('ItemCategories', 'System', $config))->generate();

        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $item = $menus[0]['items'][0]['items'][0];

        $this->assertSame('Rocket', $item['icon']);
        $this->assertSame('Rocket', $item['items'][0]['icon']);
        $this->assertSame('Rocket', $item['items'][1]['icon']);
    }

    /**
     * The fallback heuristic must return a verified Lucide icon name for
     * representative module names instead of collapsing everything to
     * 'File' (the old 12-entry exact map covered ~12 names; everything else,
     * including common real modules, fell through to 'File').
     */
    public static function iconHeuristicProvider(): array
    {
        return [
            'image module'        => ['ItemImages', 'Image'],
            'price module'        => ['ItemPrices', 'Banknote'],
            'category module'     => ['ItemCategories', 'Tag'],
            'type module'         => ['ItemTypes', 'Tag'],
            'user module'         => ['Users', 'User'],
            'location module'     => ['Locations', 'MapPin'],
            'ward module'         => ['Wards', 'MapPin'],
            'notification module' => ['Notifications', 'Bell'],
            'broadcast module'    => ['Broadcasts', 'Megaphone'],
            'role module'         => ['Roles', 'Shield'],
            'permission module'   => ['Permissions', 'Lock'],
        ];
    }

    #[DataProvider('iconHeuristicProvider')]
    public function test_icon_heuristic_matches_representative_module_names(string $moduleName, string $expectedIcon): void
    {
        (new MenusJsonGenerator($moduleName, 'System', []))->generate();

        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $this->assertSame($expectedIcon, $menus[0]['items'][0]['items'][0]['icon']);
    }

    /**
     * A module name matching none of the curated stems must still fall back
     * to 'File' as the last resort.
     */
    public function test_unmatched_module_name_falls_back_to_file_icon(): void
    {
        (new MenusJsonGenerator('ZzzGeneratorVerifyTest', 'System', []))->generate();

        $menus = json_decode(file_get_contents($this->menusJsonPath()), true);
        $this->assertSame('File', $menus[0]['items'][0]['items'][0]['icon']);
    }
}
