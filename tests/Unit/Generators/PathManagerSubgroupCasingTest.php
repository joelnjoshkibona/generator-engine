<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators;

use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for frontend/backend module-path sub-group casing
 * agreement.
 *
 * Bug: PathManager::getFrontendModulePath() ran strtolower() over both
 * $moduleGroup AND self::$moduleSubGroup, while getBackendModulePath()
 * preserved casing for both. self::$moduleSubGroup is stored PascalCase via
 * Str::studly() (see setModuleSubGroup()), and every real nested module
 * folder on disk uses PascalCase for the sub-group segment — confirmed
 * against SYSTEM_SHELL/FRONTEND/src/pages/modules/core/{Locations,Access,
 * Users,Notifications}/... — while the top-level group segment is lowercase
 * by convention ("core", "system", "dev"). Lowercasing the sub-group too
 * meant regenerating an existing nested module wrote to a fresh, wrong,
 * lowercase duplicate folder instead of the real PascalCase one, silently
 * orphaning the existing files.
 *
 * This test asserts getFrontendModulePath() and getBackendModulePath()
 * agree on sub-group casing for the same input (both preserve it), while
 * the frontend path keeps lowercasing only the top-level group segment.
 */
class PathManagerSubgroupCasingTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-pathmanager-casing-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        PathManager::resetModuleSubGroup();
        PathManager::setModuleRegistry([]);
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

    public function test_frontend_and_backend_module_paths_preserve_the_same_subgroup_casing(): void
    {
        PathManager::setModuleSubGroup('Locations');

        $frontendPath = PathManager::getFrontendModulePath('core', 'LocationTypes');
        $backendPath = PathManager::getBackendModulePath('Core', 'LocationTypes');

        $this->assertStringContainsString('/Locations/', $frontendPath, 'Frontend path lowercased the sub-group segment.');
        $this->assertStringContainsString('/Locations/', $backendPath, 'Backend path lowercased the sub-group segment.');

        $this->assertStringNotContainsString('/locations/', $frontendPath);
    }

    public function test_frontend_module_path_still_lowercases_only_the_top_level_group_segment(): void
    {
        PathManager::setModuleSubGroup('Access');

        $frontendPath = PathManager::getFrontendModulePath('Core', 'Permissions');

        $this->assertStringContainsString('/core/Access/Permissions', $frontendPath);
        $this->assertStringNotContainsString('/Core/', $frontendPath);
    }

    public function test_frontend_module_path_with_no_subgroup_is_unaffected(): void
    {
        PathManager::resetModuleSubGroup();

        $frontendPath = PathManager::getFrontendModulePath('Core', 'Statuses');

        $this->assertStringEndsWith('/core/Statuses', $frontendPath);
    }

    public function test_subgroup_is_studly_cased_regardless_of_input_casing(): void
    {
        PathManager::setModuleSubGroup('user_locations');

        $frontendPath = PathManager::getFrontendModulePath('core', 'UserLocations');
        $backendPath = PathManager::getBackendModulePath('Core', 'UserLocations');

        $this->assertStringContainsString('/UserLocations/', $frontendPath);
        $this->assertStringContainsString('/UserLocations/', $backendPath);
    }

    /**
     * Bug (leftover from the getFrontendModulePath() fix above):
     * getMobileAppModulePath() independently ran strtolower() over
     * self::$moduleSubGroup, the exact same defect. Real MOBILE_APP module
     * folders are PascalCase for the sub-group segment too — confirmed
     * against SYSTEM_SHELL/MOBILE_APP/resources/js/src/pages/modules/core/
     * {Locations,Users,Permissions,Wards,Statuses,Countries,...} and
     * .../system/{Reports,Notifications,Dashboard} — while the top-level
     * group stays lowercase ("core", "system", "vfd"). This asserts
     * getMobileAppModulePath() now matches getFrontendModulePath()'s
     * casing behavior.
     */
    /**
     * The MOBILE tree is deliberately FLAT — {group}/{Module}, no sub-group.
     * Verified against the real app: all 18 mobile modules sit at exactly two
     * levels, and the web tree's sub-groups are collapsed there (web has
     * core/Locations/{Countries,Locations,LocationTypes,Wards}; mobile has
     * core/Countries, core/Locations, ... side by side). An earlier change
     * misread those flat paths as nested and appended the sub-group here.
     */
    public function test_mobile_app_module_path_is_flat_and_omits_the_subgroup(): void
    {
        PathManager::setModuleSubGroup('Locations');

        $mobilePath = PathManager::getMobileAppModulePath('core', 'LocationTypes');

        $this->assertStringContainsString('/core/LocationTypes', $mobilePath);
        $this->assertStringNotContainsString('/Locations/', $mobilePath, 'Mobile paths must not nest the sub-group.');
        $this->assertStringNotContainsString('/locations/', $mobilePath);
    }

    public function test_mobile_app_module_path_still_lowercases_only_the_top_level_group_segment(): void
    {
        PathManager::setModuleSubGroup('Access');

        $mobilePath = PathManager::getMobileAppModulePath('Core', 'Permissions');

        // Group lowercased, sub-group dropped entirely — matches the real
        // mobile tree, where core/Permissions is a flat module directory.
        $this->assertStringContainsString('/core/Permissions', $mobilePath);
        $this->assertStringNotContainsString('/Access/', $mobilePath);
        $this->assertStringNotContainsString('/Core/', $mobilePath);
    }

    public function test_mobile_app_module_path_with_no_subgroup_is_unaffected(): void
    {
        PathManager::resetModuleSubGroup();

        $mobilePath = PathManager::getMobileAppModulePath('Core', 'Statuses');

        $this->assertStringEndsWith('/core/Statuses', $mobilePath);
    }

    /**
     * Bug (same family): resolveFrontendImportSegment() studly-cased the
     * registry's group_name (correct) but then immediately lowercased the
     * result again, producing import paths like
     * `core/locations/LocationTypes` that don't exist on disk. Real
     * generated imports use the PascalCase sub-group segment — confirmed via
     * `import LocationsCreateForm from '@/pages/modules/core/Locations/
     * Locations/Components/LocationsCreateForm.vue'`,
     * `.../core/Access/Roles/Components/RolesCreateForm.vue`, and
     * `.../core/Users/Users/Components/UsersCreateForm.vue` in
     * SYSTEM_SHELL/FRONTEND/src. This asserts the resolved import segment
     * keeps that casing.
     */
    public function test_import_segment_preserves_subgroup_casing_from_registry(): void
    {
        PathManager::setModuleRegistry([
            [
                'name' => 'LocationTypes',
                'module_type' => 'core',
                'group_name' => 'locations',
            ],
        ]);

        $segment = PathManager::resolveFrontendImportSegment('LocationTypes');

        $this->assertSame('core/Locations/LocationTypes', $segment);
    }

    /**
     * Regression: modules.json's `path` is what router.ts uses to find a
     * module's routes.ts (`/src/pages{path}/routes.ts`), so it MUST match the
     * casing the frontend files are actually written with. ModulesJsonGenerator
     * lowercased the sub-group while PathManager::getFrontendModulePath() did
     * not, so a nested module's route lookup missed entirely — the module 404'd
     * in the browser while its menu entry still rendered. Caught only by a real
     * headed browser run; every backend test passed throughout.
     */
    public function test_modules_json_path_matches_frontend_module_path_casing(): void
    {
        PathManager::setModuleSubGroup('Custom');
        $frontendPath = PathManager::getFrontendModulePath('System', 'ItemTypes');
        PathManager::resetModuleSubGroup();

        // The on-disk path must contain the PascalCase sub-group...
        $this->assertStringContainsString('/Custom/', $frontendPath);
        // ...and must NOT contain a lowercased one.
        $this->assertStringNotContainsString('/custom/', $frontendPath);

        // ModulesJsonGenerator builds its path the same way; assert the source
        // no longer lowercases the sub-group segment.
        $src = file_get_contents(__DIR__ . '/../../../src/Generators/Frontend/ModulesJsonGenerator.php');
        $this->assertStringNotContainsString(
            "strtolower(\$this->moduleSubGroup)",
            $src,
            'ModulesJsonGenerator must not lowercase the sub-group — router.ts looks up routes.ts by this exact path.'
        );
    }
}
