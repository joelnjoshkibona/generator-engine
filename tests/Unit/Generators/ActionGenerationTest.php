<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators;

use Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Seeders\SeederGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\ViewModalGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for `config['actions']` — the sibling of `delegations`.
 *
 * Two bugs made every default-configured action useless, and neither had any
 * test:
 *
 *  1. The route guard defaulted to `{Module}.{StudlyName}.{op}` while the
 *     seeder created `{Module}.{name}.execute`. Different case AND a different
 *     final segment, so the permission the route demanded was never created by
 *     the seeder that is supposed to create it — a permanent 403 with no error
 *     anywhere in the generation output.
 *
 *  2. Nothing in the frontend read `actions` at all. The backend emitted a
 *     service, controller method and route, and the UI to reach them had to be
 *     hand-written — which is exactly what UsersViewModal.vue is.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\ViewModalGenerator
 */
class ActionGenerationTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-actions-' . uniqid();
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

    private function config(array $actions): array
    {
        return [
            'module_name' => 'Products',
            'module_type' => 'System',
            'table_name'  => 'products',
            'id_type'     => 'bigint',
            'columns'     => [],
            'actions'     => $actions,
        ];
    }

    private function approveAction(array $overrides = []): array
    {
        return array_merge([
            'name'       => 'approve',
            'label'      => 'Approve',
            'hasUI'      => true,
            'uiType'     => 'modal',
            'urlParams'  => ['uuid'],
            'operations' => [
                'create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/products/{uuid}/approve']],
            ],
        ], $overrides);
    }

    /**
     * The regression that matters most: the string the route demands and the
     * string the seeder creates must be byte-identical, or the action is
     * unreachable no matter what role the user has.
     */
    public function test_route_guard_and_seeded_permission_are_the_same_string(): void
    {
        $config = $this->config(['approve' => $this->approveAction()]);

        (new RoutesGenerator('Products', 'System', $config))->generate();
        (new SeederGenerator('Products', 'System', $config))->generate();

        $routes = $this->findFileContaining('api.php');
        $seeder = $this->findFileContaining('Permissions.json') ?? $this->findFileContaining('.json');

        $this->assertNotNull($routes, 'routes file was not generated');
        $this->assertNotNull($seeder, 'seeder permission file was not generated');

        $this->assertStringContainsString(
            'permission:Products.approve',
            $routes,
            'Route guard should demand the canonical {Module}.{actionName} permission.'
        );
        $this->assertStringContainsString(
            '"Products.approve"',
            $seeder,
            'Seeder must create exactly the permission the route demands.'
        );
        $this->assertStringNotContainsString('Products.approve.execute', $seeder, 'The ".execute" suffix never matched the route guard.');
        $this->assertStringNotContainsString('Products.Approve', $routes, 'StudlyCase spelling never matched the seeded permission.');
    }

    public function test_main_placement_renders_a_footer_button_and_more_placement_does_not(): void
    {
        $config = $this->config([
            'approve' => $this->approveAction(['placement' => 'main', 'icon' => 'CheckIcon']),
        ]);

        (new ViewModalGenerator('Products', 'System', $config))->generate();
        $modal = $this->findFileContaining('ViewModal.vue');

        $this->assertNotNull($modal);
        $this->assertStringContainsString("hasPermission('Products.approve')", $modal);
        $this->assertStringContainsString('products-action-approve-', $modal, 'main action needs a stable testid');
        $this->assertStringContainsString("icons['CheckIcon']", $modal);
        // A main-placement action must NOT also appear as a dropdown item.
        $this->assertStringNotContainsString('DropdownMenuItem', substr($modal, 0, (int) strpos($modal, 'DropdownMenuTrigger')));
    }

    public function test_more_placement_renders_a_dropdown_item_and_widens_the_menu_condition(): void
    {
        $config = $this->config([
            'approve' => $this->approveAction(['placement' => 'more', 'destructive' => true]),
        ]);

        (new ViewModalGenerator('Products', 'System', $config))->generate();
        $modal = $this->findFileContaining('ViewModal.vue');

        $this->assertNotNull($modal);
        // Without widening, the menu is gated on delete alone and a user who
        // cannot delete would never see the action.
        $this->assertStringContainsString(
            "hasPermission('Products.delete') || hasPermission('Products.approve')",
            $modal,
            'The More actions menu must show when any more-placement action is permitted.'
        );
        $this->assertStringContainsString('text-destructive', $modal);
    }

    public function test_placement_defaults_to_more_so_a_new_action_never_crowds_the_primary_row(): void
    {
        $action = $this->approveAction();
        unset($action['placement']);

        (new ViewModalGenerator('Products', 'System', $this->config(['approve' => $action])))->generate();
        $modal = $this->findFileContaining('ViewModal.vue');

        $this->assertNotNull($modal);
        $this->assertStringContainsString('DropdownMenuItem', $modal);
        $this->assertStringContainsString("hasPermission('Products.delete') || hasPermission('Products.approve')", $modal);
    }

    public function test_a_module_without_actions_emits_no_action_markup_and_no_stray_placeholders(): void
    {
        (new ViewModalGenerator('Products', 'System', $this->config([])))->generate();
        $modal = $this->findFileContaining('ViewModal.vue');

        $this->assertNotNull($modal);
        $this->assertStringNotContainsString('[[action', $modal, 'placeholders must always be replaced');
        $this->assertStringNotContainsString('products-action-', $modal);
        // The menu keeps its original delete-only condition.
        $this->assertStringContainsString("<DropdownMenu v-if=\"hasPermission('Products.delete')\">", $modal);
    }

    /** Actions without hasUI are backend-only and must not reach the modal. */
    public function test_actions_without_ui_are_not_rendered(): void
    {
        $config = $this->config(['approve' => $this->approveAction(['hasUI' => false])]);

        (new ViewModalGenerator('Products', 'System', $config))->generate();
        $modal = $this->findFileContaining('ViewModal.vue');

        $this->assertNotNull($modal);
        $this->assertStringNotContainsString('products-action-approve-', $modal);
    }

    /**
     * A page action must produce a reachable route — and a syntactically valid
     * routes file. Emitting the block with a LEADING comma produced `},,`,
     * which is an array elision: the routes array gained an `undefined` entry
     * and vue-router died on startup with "Cannot read properties of undefined
     * (reading 'path')", taking down every page including /login. The generated
     * output looked fine in a diff; only the running app showed it.
     */
    public function test_page_action_route_is_emitted_without_creating_an_array_hole(): void
    {
        $config = $this->config([
            'approve' => $this->approveAction(['uiType' => 'page']),
        ]);

        (new \Blutrixx\GeneratorEngine\Generators\Frontend\Routes\FrontendRoutesGenerator('Products', 'System', $config))->generate();
        $routes = $this->findFileContaining('routes.ts');

        $this->assertNotNull($routes, 'routes.ts was not generated');
        $this->assertStringContainsString("path: '/products/:uuid/approve'", $routes);
        $this->assertStringContainsString("permission: 'Products.approve'", $routes);
        $this->assertStringNotContainsString(',,', $routes, 'a double comma creates an undefined route entry');
        $this->assertStringNotContainsString('[,', $routes);
    }

    /**
     * The Form is the hand-edited file, so it must survive a uiType flip —
     * otherwise switching modal <-> page silently orphans the fields someone
     * wrote and hands back a blank stub.
     */
    public function test_the_form_is_generated_for_both_ui_types_and_only_page_adds_a_page_shell(): void
    {
        foreach (['modal', 'page'] as $uiType) {
            $this->removeDirectory($this->tmpRoot);
            mkdir($this->tmpRoot, 0755, true);

            $config = $this->config(['approve' => $this->approveAction(['uiType' => $uiType])]);
            (new \Blutrixx\GeneratorEngine\Generators\Frontend\Components\Actions\ActionComponentGenerator('Products', 'System', $config))
                ->generateAction('approve', $config['actions']['approve']);

            $this->assertNotNull($this->findFileContaining('ProductsApproveForm.vue'), "Form must be generated for uiType={$uiType}");

            $page = $this->findFileContaining('ProductsApprovePage.vue');
            $uiType === 'page'
                ? $this->assertNotNull($page, 'page actions need a page shell')
                : $this->assertNull($page, 'modal actions must not emit a page shell');
        }
    }

    private function findFileContaining(string $needleInName): ?string
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
