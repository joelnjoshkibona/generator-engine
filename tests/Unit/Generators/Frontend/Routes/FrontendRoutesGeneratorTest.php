<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Routes;

use Blutrixx\GeneratorEngine\Generators\Frontend\Routes\FrontendRoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for FrontendRoutesGenerator.
 *
 * Bug: route `meta.title` (rendered as the browser tab title by router.ts)
 * interpolated the raw PascalCase module name verbatim — e.g. a module named
 * "ItemCategories" produced `title: 'ItemCategories'` (list route) and
 * `title: 'Delete ItemCategories'` / `'ItemCategories Details'` /
 * `'ItemCategories History'` instead of readable spaced text. Confirmed
 * against real generated output: FRONTEND/src/pages/modules/system/masters/
 * ItemCategories/routes.ts (untouched since generation) still shows exactly
 * this raw-concatenation pattern.
 *
 * Fix (see FrontendRoutesGenerator::generate()): titles now run through
 * BaseGenerator::humanize() (Str::headline()). The real, hand-completed
 * Roles/Users/LocationTypes/Countries/.../routes.ts files establish the
 * singular/plural convention: the list route title stays plural
 * ("Roles"), while Delete/Details/History titles use the singular form
 * ("Delete Role", "Role Details", "Role History") — confirmed consistent
 * across every hand-completed module checked (Roles, Users, LocationTypes,
 * Countries, Permissions, Locations, Wards, Media, Broadcasts).
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Routes\FrontendRoutesGenerator::generate()
 */
class FrontendRoutesGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-routes-test-' . uniqid();
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

    public function test_generate_humanizes_route_titles_with_correct_singular_plural(): void
    {
        $config = [
            'features' => [
                'frontend' => [
                    'list'   => true,
                    'create' => true,
                    'edit'   => true,
                    'delete' => true,
                    'view'   => true,
                ],
            ],
        ];

        $generator = new FrontendRoutesGenerator('ItemCategories', 'System', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $routesPath = $this->tmpRoot . '/FRONTEND/src/pages/modules/system/ItemCategories/routes.ts';
        $this->assertFileExists($routesPath);
        $content = file_get_contents($routesPath);

        // List route: plural, spaced (matches the real Roles/routes.ts: 'Roles').
        $this->assertStringContainsString("title: 'Item Categories'", $content);
        // Delete/Details/History: singular, spaced (matches the real
        // Roles/routes.ts convention: 'Delete Role', 'Role Details', 'Role History').
        $this->assertStringContainsString("title: 'Delete Item Category'", $content);
        $this->assertStringContainsString("title: 'Item Category Details'", $content);
        $this->assertStringContainsString("title: 'Item Category History'", $content);

        // No route *title* (human-facing text) may contain the raw unspaced
        // name. Route `name:`/`permission:` fields are Vue Router/ACL
        // identifiers, not display text, and correctly keep the raw
        // PascalCase form (e.g. `name: 'ItemCategories'`, matching the real
        // Roles/routes.ts convention `name: 'Roles'`) — this test does not
        // touch those.
        preg_match_all("/title: '([^']*)'/", $content, $matches);
        $this->assertNotEmpty($matches[1], 'Expected at least one route meta.title in the generated file.');
        foreach ($matches[1] as $title) {
            $this->assertStringNotContainsString('ItemCategories', $title, "Route title '{$title}' still contains the raw unspaced module name.");
        }
    }

    public function test_generate_humanizes_multiword_module_name_end_to_end(): void
    {
        $config = [
            'features' => [
                'frontend' => [
                    'list'   => true,
                    'delete' => true,
                    'view'   => true,
                ],
            ],
        ];

        $generator = new FrontendRoutesGenerator('ZzzGeneratorVerifyTest', 'System', $config);
        $generator->setForce(true);
        $generator->generate();

        $routesPath = $this->tmpRoot . '/FRONTEND/src/pages/modules/system/ZzzGeneratorVerifyTest/routes.ts';
        $content = file_get_contents($routesPath);

        // Only the `title:` meta values are human-facing text; component
        // import paths and the exported const name are code identifiers and
        // are expected to keep the raw PascalCase name untouched.
        preg_match_all("/title: '([^']*)'/", $content, $matches);
        $this->assertNotEmpty($matches[1], 'Expected at least one route meta.title in the generated file.');
        foreach ($matches[1] as $title) {
            $this->assertStringNotContainsString('ZzzGeneratorVerifyTest', $title, "Route title '{$title}' still contains the raw unspaced module name.");
        }

        $this->assertStringContainsString("title: 'Zzz Generator Verify Test'", $content);
        $this->assertStringContainsString("title: 'Delete Zzz Generator Verify Test'", $content);
        $this->assertStringContainsString("title: 'Zzz Generator Verify Test Details'", $content);
    }

    // ─── ModuleConfig export (2026-07-24) ───────────────────────────────────
    //
    // generateModuleConfigExport() registers the module with
    // useEntityNavigation() so RelatedRecordLink (the new isFk cell renderer
    // in BaseComponentGenerator) can open this module's own record details
    // whenever a FK column -- in this module's own list, or another module's
    // -- links back to it. Only emitted when 'view' is enabled: detailsView
    // imports {ModuleName}ViewModal.vue, which only exists for view-enabled
    // modules.
    //
    // @see \Blutrixx\GeneratorEngine\Generators\Frontend\Routes\FrontendRoutesGenerator::generateModuleConfigExport()

    /** @return array<string, mixed> */
    private function locationsConfig(): array
    {
        $path = dirname(__DIR__, 4) . '/Fixtures/LocationsModule.json';
        $this->assertFileExists($path, "Expected fixture not found: {$path}");

        $config = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($config, 'LocationsModule.json did not decode to an array.');

        return $config;
    }

    public function test_generate_emits_module_config_export_when_view_feature_enabled(): void
    {
        // Locations/Locations/module.json is SYSTEM_SHELL's real module config
        // for the reference module this feature's docblock explicitly mirrors
        // ("Mirrors the hand-added block in the reference Locations/Locations/
        // routes.ts"). features.frontend.view is present, so the export must
        // be emitted.
        $config = $this->locationsConfig();

        $generator = new FrontendRoutesGenerator('Locations', 'Core', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $routesPath = $this->tmpRoot . '/FRONTEND/src/pages/modules/core/Locations/routes.ts';
        $this->assertFileExists($routesPath);
        $content = (string) file_get_contents($routesPath);

        $this->assertStringContainsString('export const LocationsModuleConfig: EntityModuleConfig = {', $content);
        $this->assertStringContainsString("mode: 'modal',", $content);
        $this->assertStringContainsString("route: '/locations',", $content);
        $this->assertStringContainsString("detailsView: () => import('./Components/LocationsViewModal.vue'),", $content);
        $this->assertStringContainsString("modalSize: 'lg'", $content);

        // The type-only import the export's annotation depends on must also be present.
        $this->assertStringContainsString('import type {EntityModuleConfig} from "@/composables/useEntityNavigation";', $content);
    }

    public function test_generate_omits_module_config_export_when_view_feature_disabled(): void
    {
        $config = $this->locationsConfig();
        unset($config['features']['frontend']['view']);

        $generator = new FrontendRoutesGenerator('Locations', 'Core', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $routesPath = $this->tmpRoot . '/FRONTEND/src/pages/modules/core/Locations/routes.ts';
        $content = (string) file_get_contents($routesPath);

        $this->assertStringNotContainsString('export const LocationsModuleConfig', $content);
        $this->assertStringNotContainsString(
            "detailsView: () => import('./Components/LocationsViewModal.vue')",
            $content
        );
        $this->assertStringNotContainsString("modalSize: 'lg'", $content);
    }
}
