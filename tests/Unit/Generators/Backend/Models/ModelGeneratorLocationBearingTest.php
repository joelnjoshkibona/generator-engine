<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Models;

use Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the `$locationBearing` declaration ModelGenerator emits.
 *
 * Why it exists: a consuming app's location scoping has only ever reached
 * list queries (`ListServiceTrait::applyLocationFiltering()`, hand-maintained
 * in the app). Every generated view/edit/delete/deleteCheck service fetches
 * by uuid through `($query ?? Model::query())->where(['uuid' => ...])->first()`,
 * which no scope touches — so a record outside a user's locations is missing
 * from their list and still fully readable, editable and deletable by uuid.
 * Declaring the fact on the model lets the app close that in one shared
 * place instead of at every fetch site in every module.
 *
 * The declaration is derived from the `location_id` column, mirroring
 * ListServiceGenerator::generateLocationScopeIncludesNull(), and is
 * overridable in both directions for the case introspection cannot see: a
 * row that belongs to a location through a join rather than a column.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::isLocationBearing()
 */
class ModelGeneratorLocationBearingTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-location-bearing-' . uniqid();
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

    /**
     * 'connection' MUST always be present (even as '') — generateConnection()
     * falls back to the Laravel config() helper when it is absent entirely,
     * which does not exist in this plain-PHPUnit environment.
     */
    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'connection' => '',
            'id_type'    => 'integer',
            'columns'    => [
                ['name' => 'title', 'type' => 'string'],
            ],
        ], $overrides);
    }

    private function generateAndRead(array $config, string $moduleName = 'TestSales', string $moduleGroup = 'System'): string
    {
        $generator = new ModelGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate(), 'ModelGenerator::generate() should report a successful write.');

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/{$moduleGroup}/{$moduleName}/{$moduleName}Model.php";
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function test_a_module_with_a_location_id_column_declares_itself_location_bearing(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'location_id', 'type' => 'integer'],
            ],
        ]));

        $this->assertStringContainsString('protected static bool $locationBearing = true;', $content);
    }

    public function test_a_module_without_a_location_id_column_declares_nothing(): void
    {
        // The common case, and the reason the declaration is emitted rather
        // than always written as `= false`: an unrelated module regenerates
        // byte-identically.
        $content = $this->generateAndRead($this->baseConfig());

        $this->assertStringNotContainsString('locationBearing', $content);
    }

    public function test_an_explicit_true_covers_a_module_whose_location_comes_from_a_join(): void
    {
        // Users are the standing example: no `location_id` column, yet a
        // person is reachable only through their `user_locations` rows.
        $content = $this->generateAndRead($this->baseConfig([
            'location_bearing' => true,
        ]));

        $this->assertStringContainsString('protected static bool $locationBearing = true;', $content);
    }

    public function test_an_explicit_false_opts_a_module_out_despite_the_column(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'location_id', 'type' => 'integer'],
            ],
            'location_bearing' => false,
        ]));

        $this->assertStringNotContainsString('locationBearing', $content);
    }
}
