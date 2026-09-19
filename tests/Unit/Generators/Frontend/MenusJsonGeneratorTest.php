<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend;

use Blutrixx\GeneratorEngine\Generators\Frontend\MenusJsonGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for MenusJsonGenerator's current contract: it writes ONE small,
 * self-contained JSON file describing this module's own menu entry, at
 * Seeders/MenuSeederData.json (backend-side), keyed by `module_route` — not
 * a shared frontend menus.json tree it hand-merges into.
 *
 * Superseded design (removed): menus.json lived at FRONTEND/src/menus.json
 * as a hand-merged section/item tree, with matching-by-url identity logic to
 * dedupe across regenerations. Menu structure now lives in a real `menus`
 * database table (a separate module, out of this generator's scope); this
 * generator's only job is to describe what a fresh `make:module` run thinks
 * this module's entry should look like, for a downstream seeder/sync step
 * to upsert by `module_route` — a stable key regardless of where an admin
 * later relocates the row via the real management UI.
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
        PathManager::resetModuleSubGroup();
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

    private function menuSeederDataPath(string $moduleGroup, string $moduleName): string
    {
        return PathManager::getBackendModulePath($moduleGroup, $moduleName) . '/Seeders/MenuSeederData.json';
    }

    private function readMenuData(string $moduleGroup, string $moduleName): array
    {
        $path = $this->menuSeederDataPath($moduleGroup, $moduleName);
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), true);
    }

    public function test_generate_writes_humanized_menu_title_for_default_simple_item(): void
    {
        $generator = new MenusJsonGenerator('ItemCategories', 'System', []);
        $this->assertTrue($generator->generate());

        $data = $this->readMenuData('System', 'ItemCategories');

        $this->assertSame('item-categories', $data['module_route']);
        $this->assertSame('Item Categories', $data['title']);
        $this->assertSame('/item-categories/list', $data['url']);
        $this->assertSame('ItemCategories.list', $data['permission']);
        $this->assertSame('main', $data['section']);
        $this->assertSame([], $data['children']);
    }

    public function test_generate_humanizes_multiword_pascalcase_module_name(): void
    {
        $generator = new MenusJsonGenerator('ZzzGeneratorVerifyTest', 'System', []);
        $generator->generate();

        $data = $this->readMenuData('System', 'ZzzGeneratorVerifyTest');

        $this->assertSame('Zzz Generator Verify Test', $data['title']);
    }

    public function test_nested_menu_item_uses_plural_all_and_singular_create_as_children(): void
    {
        $config = [
            'menu_config' => [
                'enabled' => true,
                'nested'  => true,
            ],
        ];

        $generator = new MenusJsonGenerator('ItemCategories', 'System', $config);
        $generator->generate();

        $data = $this->readMenuData('System', 'ItemCategories');

        $this->assertSame('Item Categories', $data['title']);
        $this->assertSame('All Item Categories', $data['children'][0]['title']);
        $this->assertSame('Create Item Category', $data['children'][1]['title']);
    }

    /**
     * Re-running (e.g. --force) must overwrite the same file in place, not
     * append/duplicate anything — trivially true now there's no tree-merge
     * step, but asserted directly since it's the whole point of the file's
     * existence.
     */
    public function test_regenerating_the_same_module_overwrites_in_place(): void
    {
        $generator = new MenusJsonGenerator('ItemCategories', 'System', ['icon' => 'Rocket']);
        $generator->generate();

        $regenerator = new MenusJsonGenerator('ItemCategories', 'System', ['icon' => 'Package']);
        $regenerator->generate();

        $data = $this->readMenuData('System', 'ItemCategories');
        $this->assertSame('Package', $data['icon'], 'A second run must overwrite the file, not leave the old content or append a duplicate.');
    }

    public function test_disabled_module_does_not_write_a_file(): void
    {
        $config = ['menu_config' => ['enabled' => false]];

        $generator = new MenusJsonGenerator('ItemCategories', 'System', $config);
        $this->assertTrue($generator->generate());

        $this->assertFileDoesNotExist($this->menuSeederDataPath('System', 'ItemCategories'));
    }

    /**
     * A module previously enabled (file exists), then disabled and
     * regenerated, must have its stale file removed — mirrors the old
     * design's removeFromMenus() intent, as a file-presence check instead of
     * a tree-prune.
     */
    public function test_disabling_a_previously_enabled_module_deletes_its_existing_file(): void
    {
        (new MenusJsonGenerator('ItemCategories', 'System', []))->generate();
        $this->assertFileExists($this->menuSeederDataPath('System', 'ItemCategories'));

        $disabled = new MenusJsonGenerator('ItemCategories', 'System', ['menu_config' => ['enabled' => false]]);
        $this->assertTrue($disabled->generate());

        $this->assertFileDoesNotExist($this->menuSeederDataPath('System', 'ItemCategories'));
    }

    /**
     * module_route is derived from the module's OWN kebab-case name, not
     * from any custom title the config supplies — this is the actual
     * identity key a downstream DB-sync step upserts by, so it must stay
     * stable regardless of what title/url a blueprint's menu_config
     * overrides to.
     */
    public function test_module_route_is_independent_of_a_custom_config_title(): void
    {
        $config = [
            'menu_config' => [
                'enabled' => true,
                'section' => 'custom',
                'items' => [
                    ['title' => 'Totally Custom Label'],
                ],
            ],
        ];

        (new MenusJsonGenerator('ItemCategories', 'Custom', $config))->generate();

        $data = $this->readMenuData('Custom', 'ItemCategories');
        $this->assertSame('item-categories', $data['module_route']);
        $this->assertSame('Totally Custom Label', $data['title']);
        $this->assertSame('custom', $data['section']);
    }

    /**
     * Two different modules that happen to render the same display title
     * get two separate files (one per module's own backend path) —
     * confirms identity is per-module-path, never merged on title.
     */
    public function test_two_distinct_modules_sharing_a_title_get_separate_files(): void
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

        $sales = $this->readMenuData('Custom', 'SalesReports');
        $stock = $this->readMenuData('Custom', 'StockReports');

        $this->assertSame('sales-reports', $sales['module_route']);
        $this->assertSame('/sales-reports/list', $sales['url']);
        $this->assertSame('stock-reports', $stock['module_route']);
        $this->assertSame('/stock-reports/list', $stock['url']);
    }

    // -- Icon resolution ---------------------------------------------------

    /**
     * Default single-item emission path (no menu_config at all): an explicit
     * top-level config icon must win over the heuristic/fallback.
     */
    public function test_explicit_icon_wins_for_the_default_emission_path(): void
    {
        $generator = new MenusJsonGenerator('ItemCategories', 'System', ['icon' => 'Rocket']);
        $generator->generate();

        $data = $this->readMenuData('System', 'ItemCategories');
        $this->assertSame('Rocket', $data['icon']);
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

        $data = $this->readMenuData('System', 'ItemCategories');
        $this->assertSame('Rocket', $data['icon']);
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

        $data = $this->readMenuData('System', 'ItemCategories');
        $this->assertSame('Rocket', $data['icon']);
    }

    /**
     * menu_config.icon on the plain createSimpleMenuItem() path (menu_config
     * present but without an 'items' key) must win.
     */
    public function test_explicit_icon_wins_for_the_simple_menu_item_path(): void
    {
        $config = ['menu_config' => ['enabled' => true, 'icon' => 'Rocket']];

        (new MenusJsonGenerator('ItemCategories', 'System', $config))->generate();

        $data = $this->readMenuData('System', 'ItemCategories');
        $this->assertSame('Rocket', $data['icon']);
    }

    /**
     * menu_config.icon on the nested (group) path must apply to the parent
     * AND both "All X"/"Create X" children.
     */
    public function test_explicit_icon_wins_for_the_nested_menu_item_path(): void
    {
        $config = ['menu_config' => ['enabled' => true, 'nested' => true, 'icon' => 'Rocket']];

        (new MenusJsonGenerator('ItemCategories', 'System', $config))->generate();

        $data = $this->readMenuData('System', 'ItemCategories');

        $this->assertSame('Rocket', $data['icon']);
        $this->assertSame('Rocket', $data['children'][0]['icon']);
        $this->assertSame('Rocket', $data['children'][1]['icon']);
    }

    /**
     * The fallback heuristic must return a verified Lucide icon name for
     * representative module names instead of collapsing everything to
     * 'File'.
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

        $data = $this->readMenuData('System', $moduleName);
        $this->assertSame($expectedIcon, $data['icon']);
    }

    /**
     * A module name matching none of the curated stems must still fall back
     * to 'File' as the last resort.
     */
    public function test_unmatched_module_name_falls_back_to_file_icon(): void
    {
        (new MenusJsonGenerator('ZzzGeneratorVerifyTest', 'System', []))->generate();

        $data = $this->readMenuData('System', 'ZzzGeneratorVerifyTest');
        $this->assertSame('File', $data['icon']);
    }
}
