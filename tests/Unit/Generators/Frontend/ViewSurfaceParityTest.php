<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\ViewModalGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ViewLayoutGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Routes\FrontendRoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * A record has TWO view surfaces — the modal opened by the list's eye action,
 * and the full details page at /{module}/{uuid}/details — and they are built by
 * two different generators. Nothing kept them in agreement.
 *
 * That is how delegations came to exist on the page and not in the modal:
 * ViewLayoutGenerator built its tabs from the module's delegations via
 * BaseComponentGenerator::generateTabNavigation(), while modal.stub carried a
 * hardcoded overview+history literal. The child list was reachable by URL and
 * simply absent from the surface most people actually open. Every individual
 * generator test passed the whole time, because each asserted its own file in
 * isolation — the defect lived in the RELATIONSHIP between two files.
 *
 * These tests assert that relationship directly: the same delegation must
 * produce the same tab id on both surfaces, and that id must match the route
 * the router registers. Comparing ids (not markup) keeps this robust to the two
 * surfaces legitimately looking different — one is a dialog, one is a page.
 */
class ViewSurfaceParityTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-view-parity-' . uniqid();
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

    private function configWithDelegations(): array
    {
        return [
            'module_name' => 'Items',
            'module_type' => 'System',
            'table_name'  => 'items',
            'id_type'     => 'bigint',
            'columns'     => [],
            // FrontendRoutesGenerator derives which routes to emit from
            // features.frontend; without it the routes file is empty and the
            // parity assertion below would pass vacuously.
            'features'    => [
                'frontend' => [
                    'list'   => ['enabled' => true],
                    'create' => ['enabled' => true],
                    'edit'   => ['enabled' => true],
                    'view'   => ['enabled' => true],
                    'delete' => ['enabled' => true],
                ],
            ],
            'delegations' => [
                'itemPrices' => [
                    'name' => 'ItemPrices', 'label' => 'Item Prices', 'uiType' => 'tab',
                    'relatedModule' => ['name' => 'ItemPrices', 'group' => 'Inventory'],
                    'filterKey' => 'item_id', 'parentKey' => 'uuid', 'parentIdField' => 'id',
                    'operations' => ['list' => ['enabled' => true]],
                ],
                'itemImages' => [
                    'name' => 'ItemImages', 'label' => 'Item Images', 'uiType' => 'tab',
                    'relatedModule' => ['name' => 'ItemImages', 'group' => 'Inventory'],
                    'filterKey' => 'item_id', 'parentKey' => 'uuid', 'parentIdField' => 'id',
                    'operations' => ['list' => ['enabled' => true]],
                ],
            ],
        ];
    }

    /** @return string[] tab ids, in order, from a generated file's `id: '...'` entries */
    private function tabIdsIn(string $contents): array
    {
        preg_match_all("/\bid:\s*'([a-z0-9-]+)'/", $contents, $m);
        return array_values(array_unique($m[1]));
    }

    public function test_modal_and_details_page_expose_the_same_delegation_tabs(): void
    {
        $config = $this->configWithDelegations();

        (new ViewModalGenerator('Items', 'System', $config))->generate();
        (new ViewLayoutGenerator('Items', 'System', $config))->generate();

        $modal = $this->findFile('ItemsViewModal.vue');
        $page  = $this->findFile('ItemsDetailsLayout.vue');

        $this->assertNotNull($modal, 'view modal was not generated');
        $this->assertNotNull($page, 'details layout was not generated');

        $modalTabs = $this->tabIdsIn($modal);
        $pageTabs  = $this->tabIdsIn($page);

        foreach (['item-prices', 'item-images'] as $expected) {
            $this->assertContains($expected, $pageTabs, "details page is missing the '{$expected}' tab");
            $this->assertContains($expected, $modalTabs, "view modal is missing the '{$expected}' tab — the two view surfaces have drifted");
        }

        sort($modalTabs);
        sort($pageTabs);
        $this->assertSame($pageTabs, $modalTabs, 'view modal and details page must expose an identical set of tabs');
    }

    /**
     * A tab id that does not match its registered route is a dead link — the
     * exact failure that shipped when the nav emitted StudlyCase while the
     * route was kebab-case.
     */
    public function test_every_delegation_tab_id_matches_a_registered_route(): void
    {
        $config = $this->configWithDelegations();

        (new ViewModalGenerator('Items', 'System', $config))->generate();
        (new FrontendRoutesGenerator('Items', 'System', $config))->generate();

        $modal  = $this->findFile('ItemsViewModal.vue');
        $routes = $this->findFile('routes.ts');

        $this->assertNotNull($routes, 'routes.ts was not generated');

        foreach ($this->tabIdsIn($modal) as $tabId) {
            if (in_array($tabId, ['overview', 'history'], true)) {
                continue; // built-ins, asserted by their own generator tests
            }
            $this->assertStringContainsString(
                "path: '{$tabId}'",
                $routes,
                "tab '{$tabId}' has no matching child route, so the tab links nowhere"
            );
        }
    }

    public function test_a_module_without_delegations_keeps_both_surfaces_at_the_builtin_tabs(): void
    {
        $config = $this->configWithDelegations();
        $config['delegations'] = [];

        (new ViewModalGenerator('Items', 'System', $config))->generate();
        (new ViewLayoutGenerator('Items', 'System', $config))->generate();

        $modalTabs = $this->tabIdsIn($this->findFile('ItemsViewModal.vue'));
        $pageTabs  = $this->tabIdsIn($this->findFile('ItemsDetailsLayout.vue'));

        sort($modalTabs);
        sort($pageTabs);
        $this->assertSame(['history', 'overview'], $modalTabs);
        $this->assertSame(['history', 'overview'], $pageTabs);
    }


    /**
     * A delegation tab with no columns fetches its rows correctly and then
     * renders an empty table — 200 OK, `meta` reporting 1-1 of 1, and nothing
     * on screen. It looks like a data problem and is not one.
     *
     * The columns come from the RELATED module's module.json; the delegation
     * entry itself only carries operation flags, so reading
     * features.frontend.list.fields off the delegation always yielded [].
     */
    public function test_delegation_tab_renders_columns_from_the_related_module(): void
    {
        $related = [
            'module_name' => 'ItemImages',
            'module_type' => 'System',
            'features' => ['frontend' => ['list' => ['primaryField' => 'caption', 'fields' => [
                ['key' => 'caption', 'label' => 'Caption'],
                ['key' => 'is_primary', 'label' => 'Is Primary'],
            ]]]],
        ];

        $modulePath = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/ItemImages';
        mkdir($modulePath, 0755, true);
        file_put_contents($modulePath . '/module.json', json_encode($related));

        PathManager::setModuleRegistry([
            ['name' => 'ItemImages', 'module_type' => 'System', 'group_name' => null, 'table_name' => 'item_images'],
        ]);

        $config = $this->configWithDelegations();
        (new \Blutrixx\GeneratorEngine\Generators\Frontend\Components\Delegations\DelegationTabComponentGenerator('Items', 'System', $config))
            ->generateDelegation('itemImages', $config['delegations']['itemImages']);

        $tab = $this->findFile('ItemsItemImagesTab.vue');
        $this->assertNotNull($tab, 'delegation tab was not generated');

        preg_match('/const columns: ReportColumn\[\] = \[(.*?)\];/s', $tab, $m);
        $this->assertNotEmpty(
            trim($m[1] ?? ''),
            'delegation tab generated an EMPTY columns array — the child list would render a table with no header and no rows'
        );
        $this->assertStringContainsString('"caption"', $tab);
    }

    private function findFile(string $needleInName): ?string
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmpRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (str_contains($file->getFilename(), $needleInName)) {
                return file_get_contents($file->getPathname());
            }
        }
        return null;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($dir);
    }
}
