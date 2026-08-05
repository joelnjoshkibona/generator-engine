<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components\CustomFeatures;

use Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\Delegations\DelegationTabComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the 2026-08-05 redesign: delegation tabs used to
 * hand-roll their own independent create/edit/view/delete modal state
 * machine, wired to delegation-specific forms that `RelatedModuleFormGenerator`
 * wrote to the SAME file path the related module's own native
 * CreateFormGenerator/EditFormGenerator already used — a confirmed, live
 * file-collision bug (see tests/Fixtures/integration-schemas/delegations-suite/
 * README.md). Now the tab embeds the related module's own NATIVE components
 * directly via the shared `CrudListPanel` component, and there is no separate
 * delegation-specific form generator left to collide with anything.
 *
 * These tests assert the generated tab component's *source*, not runtime
 * behavior — a package-level generator test can't boot Vue. The live
 * click-through equivalent is a manual SYSTEM_SHELL scratch-module pass.
 */
class CustomFeatureTabComponentGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-tab-component-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetModuleSubGroup();
        PathManager::resetProjectRoot();
        PathManager::setModuleRegistry([]);
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    private function fullCrudConfig(): array
    {
        return [
            'module_name' => 'Statuses',
            'module_type' => 'Core',
            'table_name'  => 'statuses',
            'id_type'     => 'bigint',
            'columns'     => [],
            'features'    => ['frontend' => [
                'list' => ['enabled' => true], 'create' => ['enabled' => true],
                'edit' => ['enabled' => true], 'view' => ['enabled' => true], 'delete' => ['enabled' => true],
            ]],
            'delegations' => [
                'locations' => [
                    'name' => 'Locations', 'label' => 'Locations', 'uiType' => 'tab',
                    'relatedModule' => ['name' => 'Locations', 'group' => 'Core'],
                    'filterKey' => 'status_id', 'parentKey' => 'uuid', 'parentIdField' => 'id',
                    'operations' => [
                        'list' => ['enabled' => true, 'frontend' => ['fields' => [['key' => 'name', 'label' => 'Name']]]],
                        'create' => ['enabled' => true],
                        'edit' => ['enabled' => true],
                        'view' => ['enabled' => true],
                        'delete' => ['enabled' => true],
                    ],
                ],
            ],
        ];
    }

    private function generateTab(array $config): string
    {
        PathManager::setModuleRegistry([
            ['name' => 'Locations', 'module_type' => 'Core', 'group_name' => null, 'table_name' => 'locations'],
        ]);

        (new DelegationTabComponentGenerator('Statuses', 'Core', $config))
            ->generateDelegation('locations', $config['delegations']['locations']);

        $tab = $this->findFile('StatusesLocationsTab.vue');
        $this->assertNotNull($tab, 'delegation tab was not generated');
        return $tab;
    }

    public function test_tab_imports_crud_list_panel_not_the_legacy_dialog_or_bare_table(): void
    {
        $tab = $this->generateTab($this->fullCrudConfig());

        $this->assertStringContainsString('import { CrudListPanel } from "@/components/list-table";', $tab);
        $this->assertStringContainsString('<CrudListPanel', $tab);
        $this->assertStringNotContainsString('ListPageBareTable', $tab);
        $this->assertStringNotContainsString('<Dialog', $tab);
        $this->assertStringNotContainsString("from \"@/components/ui/dialog\"", $tab);
    }

    /**
     * Direct regression guard for the collision bug: the imported form paths
     * must be identical to what CreateFormGenerator/EditFormGenerator/
     * DeleteFormGenerator/ViewModalGenerator themselves write for the related
     * module — proving there is no separate, delegation-specific generator
     * writing something else to that same path.
     */
    public function test_imported_related_module_components_are_the_native_ones(): void
    {
        $tab = $this->generateTab($this->fullCrudConfig());

        foreach (['CreateForm', 'EditForm', 'DeleteForm', 'ViewModal'] as $suffix) {
            $this->assertStringContainsString(
                "import Locations{$suffix} from \"@/pages/modules/core/Locations/Components/Locations{$suffix}.vue\";",
                $tab,
                "expected the native Locations{$suffix}.vue import, not a delegation-specific variant"
            );
        }
        $this->assertStringNotContainsString('ViewComponent', $tab, 'ViewComponent is superseded by the native ViewModal');
    }

    public function test_mode_is_ajax_not_history(): void
    {
        $tab = $this->generateTab($this->fullCrudConfig());

        $this->assertStringContainsString('mode="ajax"', $tab);
        $this->assertStringNotContainsString('mode="history"', $tab);
    }

    /** The dual @created/@success listener was a hedge against two competing form shapes; only one form shape exists now. */
    public function test_no_defensive_success_event_listener(): void
    {
        $tab = $this->generateTab($this->fullCrudConfig());

        $this->assertStringNotContainsString('@success', $tab);
    }

    /**
     * Cross-file check, same spirit as ViewSurfaceParityTest: the tab's
     * permission strings must equal exactly what RoutesGenerator resolves for
     * the identical delegation config, or frontend gating and backend route
     * enforcement silently drift apart.
     */
    public function test_permission_strings_match_routes_generator_for_the_same_config(): void
    {
        $config = $this->fullCrudConfig();
        $tab = $this->generateTab($config);

        (new RoutesGenerator('Statuses', 'Core', $config))->generate();
        $routes = $this->findFile('api.php');
        $this->assertNotNull($routes, 'routes were not generated');

        foreach (['create', 'edit', 'view', 'delete'] as $op) {
            $expected = "Statuses.Locations.{$op}";
            $this->assertStringContainsString(
                "'{$expected}'",
                $tab,
                "tab's {$op}Permission constant does not match the expected formula"
            );
            $this->assertStringContainsString(
                "permission:{$expected}",
                $routes,
                "RoutesGenerator did not register a route guarded by {$expected} — frontend/backend permission drift"
            );
        }
    }

    /** operations.{op}.endpoint.permission overrides the default formula on both sides identically. */
    public function test_permission_override_is_honored_and_still_matches_routes_generator(): void
    {
        $config = $this->fullCrudConfig();
        $config['delegations']['locations']['operations']['delete']['endpoint'] = ['permission' => 'Custom.Override.Permission'];

        $tab = $this->generateTab($config);
        (new RoutesGenerator('Statuses', 'Core', $config))->generate();
        $routes = $this->findFile('api.php');

        $this->assertStringContainsString("'Custom.Override.Permission'", $tab);
        $this->assertStringContainsString('permission:Custom.Override.Permission', $routes);
        $this->assertStringNotContainsString("'Statuses.Locations.delete'", $tab);
    }

    /**
     * The item-scoped endpoint paths (edit/delete/deleteCheck/view) must match
     * RoutesGenerator's own path scheme exactly, with the literal string
     * '{uuid}' left for CrudListPanel to substitute at render time (it isn't
     * a JS template expression -- the child/item uuid isn't known until a row
     * is selected).
     */
    public function test_endpoint_paths_match_routes_generator_scheme_with_literal_uuid_placeholder(): void
    {
        $tab = $this->generateTab($this->fullCrudConfig());

        $this->assertStringContainsString('`/statuses/${uuid.value}/locations/list`', $tab);
        $this->assertStringContainsString('`/statuses/${uuid.value}/locations/create`', $tab);
        $this->assertStringContainsString('`/statuses/${uuid.value}/locations/{uuid}/edit`', $tab);
        $this->assertStringContainsString('`/statuses/${uuid.value}/locations/{uuid}/delete`', $tab);
        $this->assertStringContainsString('`/statuses/${uuid.value}/locations/{uuid}/delete/check`', $tab);
        $this->assertStringContainsString('`/statuses/${uuid.value}/locations/{uuid}/view`', $tab);
    }

    /** Component refs are null-gated per operation so a disabled operation is never referenced. */
    public function test_disabled_operations_pass_null_component_and_are_not_imported(): void
    {
        $config = $this->fullCrudConfig();
        $config['delegations']['locations']['operations']['delete']['enabled'] = false;

        $tab = $this->generateTab($config);

        $this->assertStringContainsString(':delete-component="false ? LocationsDeleteForm : null"', $tab);
        $this->assertStringNotContainsString('import LocationsDeleteForm', $tab);
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
