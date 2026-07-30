<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services\Delegation;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\Delegation\DelegationServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Nested-sub-group namespace resolution for delegation `use` imports —
 * mirrors the live bug reproduced against SYSTEM_SHELL's five Item* modules
 * (System\Custom\{Module}): ItemCategoriesItemsService, ItemsItemPricesService,
 * etc. all imported `App\Project\Modules\Core\{Module}` instead of
 * `App\Project\Modules\System\Custom\{Module}`.
 *
 * Unlike an auto-derived FK relation (topologically sorted so the target
 * module is always generated -- and registered -- first), a delegation
 * routinely points from a parent module (FK target, scaffolded first) to a
 * child module (FK source, scaffolded later) that simply isn't in the
 * registry yet when the delegation service is generated. See
 * DelegationServiceGenerator::resolveRelatedModuleGroupPath() for the fix.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\Delegation\DelegationServiceGenerator
 */
class DelegationServiceGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-delegation-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::setModuleRegistry([]);
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

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function baseConfig(): array
    {
        return [
            'connection' => '',
            'id_type'    => 'integer',
            'columns'    => [
                ['name' => 'title', 'type' => 'string'],
            ],
        ];
    }

    public function test_delegation_to_a_not_yet_registered_sibling_in_nested_subgroup_resolves_full_namespace(): void
    {
        // Mirrors ItemCategoriesItemsService: ItemCategories (System\Custom,
        // scaffolded first, as the FK target) declares a delegation listing
        // "Items" (also System\Custom) as children -- Items is scaffolded
        // *later* (it's the FK source), so it is deliberately NOT registered
        // here. Only the caller-declared sub-group ("Custom") is available.
        PathManager::setModuleSubGroup('Custom');

        $generator = new DelegationServiceGenerator(
            'ItemCategories',
            'System',
            $this->baseConfig(),
            'items',
            [
                'name'          => 'Items',
                'relatedModule' => ['name' => 'Items', 'group' => 'Custom'],
                'filterKey'     => 'item_category_id',
            ]
        );
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/Custom/ItemCategories/Services/ItemCategoriesItemsService.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);

        $this->assertStringContainsString('use App\Project\Modules\System\Custom\Items\ItemsModel;', $content);
        $this->assertStringNotContainsString('App\Project\Modules\Core\Items', $content);
    }

    public function test_delegation_to_an_already_registered_module_prefers_the_registry(): void
    {
        // When the target IS already known, the registry entry (authoritative)
        // must win over the caller-declared hint.
        PathManager::setModuleRegistry([
            ['name' => 'ItemPrices', 'module_type' => 'System', 'group_name' => 'Custom'],
        ]);
        PathManager::setModuleSubGroup('Custom');

        $generator = new DelegationServiceGenerator(
            'Items',
            'System',
            $this->baseConfig(),
            'itemPrices',
            [
                'name'          => 'ItemPrices',
                'relatedModule' => ['name' => 'ItemPrices', 'group' => 'Custom'],
                'filterKey'     => 'item_id',
            ]
        );
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/Custom/Items/Services/ItemsItemPricesService.php';
        $content = file_get_contents($path);

        $this->assertStringContainsString('use App\Project\Modules\System\Custom\ItemPrices\ItemPricesModel;', $content);
    }

    public function test_self_delegation_resolves_via_self_registration(): void
    {
        // e.g. Accounts.children -> Accounts. BaseGenerator registers the
        // current module under its own name before generate() runs, so this
        // resolves through the ordinary registry path -- no special-casing
        // needed here, but the outcome must still be the full nested namespace.
        PathManager::setModuleSubGroup('Custom');

        $generator = new DelegationServiceGenerator(
            'ItemCategories',
            'System',
            $this->baseConfig(),
            'children',
            [
                'name'          => 'Children',
                'relatedModule' => ['name' => 'ItemCategories', 'group' => 'Custom'],
                'filterKey'     => 'parent_id',
            ]
        );
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/Custom/ItemCategories/Services/ItemCategoriesChildrenService.php';
        $content = file_get_contents($path);

        $this->assertStringContainsString('App\Project\Modules\System\Custom\ItemCategories\ItemCategoriesModel', $content);
    }

    public function test_flat_core_delegation_is_unaffected(): void
    {
        // No sub-group anywhere in play -- must behave exactly as before.
        PathManager::setModuleRegistry([
            ['name' => 'Comments', 'module_type' => 'Core'],
        ]);

        $generator = new DelegationServiceGenerator(
            'Posts',
            'Core',
            $this->baseConfig(),
            'comments',
            [
                'name'          => 'Comments',
                'relatedModule' => ['name' => 'Comments', 'group' => null],
                'filterKey'     => 'post_id',
            ]
        );
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Posts/Services/PostsCommentsService.php';
        $content = file_get_contents($path);

        $this->assertStringContainsString('use App\Project\Modules\Core\Comments\CommentsModel;', $content);
    }

    public function test_unresolvable_delegation_target_fails_loudly_instead_of_defaulting_to_core(): void
    {
        // No registry entry AND no caller-declared sub-group hint at all --
        // genuinely unresolvable. A delegation is an explicit, blueprint
        // -authored declaration (not a guess), so this must throw rather
        // than silently emit `use App\Project\Modules\Core\Bogus\BogusModel;`.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Bogus/');

        $generator = new DelegationServiceGenerator(
            'Items',
            'System',
            $this->baseConfig(),
            'bogus',
            [
                'name'          => 'Bogus',
                'relatedModule' => ['name' => 'Bogus', 'group' => null],
                'filterKey'     => 'item_id',
            ]
        );
        $generator->setForce(true);
        $generator->generate();
    }

    // ─── buildEagerLoadRelationships() — creator/updater gated on
    // ModuleConfigContract::hasCreatorUpdater() (2026-07-30) ────────────────
    //
    // Bug: this class has its own private buildEagerLoadRelationships(),
    // separate from BaseServiceGenerator::generateEagerLoadRelationships()
    // (it reads from $this->delegation['operations'][$op], not
    // $this->config['features']['backend'][$feature]) -- it duplicated the
    // exact same unconditional 'creator'/'updater' bug independently. Found
    // live: a parent module with has_creator_updater: false generated a
    // delegation service whose eager-load list still threw
    // RelationNotFoundException on the related module's model.
    //
    // @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\Delegation\DelegationServiceGenerator::buildEagerLoadRelationships()

    public function test_delegation_eager_load_defaults_to_creator_and_updater(): void
    {
        PathManager::setModuleSubGroup('Custom');

        $generator = new DelegationServiceGenerator(
            'ItemCategories',
            'System',
            $this->baseConfig(),
            'items',
            [
                'name'          => 'Items',
                'relatedModule' => ['name' => 'Items', 'group' => 'Custom'],
                'filterKey'     => 'item_category_id',
            ]
        );
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/Custom/ItemCategories/Services/ItemCategoriesItemsService.php';
        $content = file_get_contents($path);

        $this->assertStringContainsString("['creator', 'updater']", $content);
    }

    public function test_delegation_eager_load_omits_creator_and_updater_when_parent_module_disables_it(): void
    {
        PathManager::setModuleSubGroup('Custom');

        $generator = new DelegationServiceGenerator(
            'ItemCategories',
            'System',
            array_merge($this->baseConfig(), ['has_creator_updater' => false]),
            'items',
            [
                'name'          => 'Items',
                'relatedModule' => ['name' => 'Items', 'group' => 'Custom'],
                'filterKey'     => 'item_category_id',
            ]
        );
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/Custom/ItemCategories/Services/ItemCategoriesItemsService.php';
        $content = file_get_contents($path);

        $this->assertStringNotContainsString('creator', $content);
        $this->assertStringNotContainsString('updater', $content);
    }
}
