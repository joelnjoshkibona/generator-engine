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
}
