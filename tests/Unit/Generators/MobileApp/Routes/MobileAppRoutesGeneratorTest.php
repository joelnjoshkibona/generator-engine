<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\MobileApp\Routes;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Routes\MobileAppRoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for MobileAppRoutesGenerator, the MOBILE_APP sibling of
 * FrontendRoutesGenerator — same raw-name-into-route-title bug, same fix
 * (BaseGenerator::humanize()), same singular/plural convention: list title
 * plural, Create/Edit/Delete/Details/Overview titles singular.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\MobileApp\Routes\MobileAppRoutesGenerator::generate()
 */
class MobileAppRoutesGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-mobile-routes-test-' . uniqid();
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

        $generator = new MobileAppRoutesGenerator('ItemCategories', 'System', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $routesPath = $this->tmpRoot . '/MOBILE_APP/resources/js/src/pages/modules/system/ItemCategories/routes.ts';
        $this->assertFileExists($routesPath);
        $content = file_get_contents($routesPath);

        $this->assertStringContainsString("title: 'Item Categories'", $content);
        $this->assertStringContainsString("title: 'Create Item Category'", $content);
        $this->assertStringContainsString("title: 'Edit Item Category'", $content);
        $this->assertStringContainsString("title: 'Delete Item Category'", $content);
        $this->assertStringContainsString("title: 'Item Category Details'", $content);
        $this->assertStringContainsString("title: 'Item Category Overview'", $content);

        preg_match_all("/title: '([^']*)'/", $content, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $title) {
            $this->assertStringNotContainsString('ItemCategories', $title, "Route title '{$title}' still contains the raw unspaced module name.");
        }
    }
}
