<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Seeders;

use Blutrixx\GeneratorEngine\Generators\Backend\Seeders\SeederGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a real, confirmed data-loss bug found live while
 * porting SYSTEM_SHELL's Countries module (2026-08-07) — the identical
 * failure mode had already, independently, bitten Users' 2 bootstrap-account
 * seed rows earlier in the same modernization pass.
 *
 * Bug: `SeederGenerator::generateJsonData()` sourced its `data` array
 * exclusively from `$config['seeder']['data']` (module.json's declared seed
 * rows) and unconditionally overwrote `{Module}SeederData.json` with the
 * result. Real seed data is routinely hand-added directly to the already-
 * generated SeederData.json file and never round-tripped back into
 * module.json — Countries' full 252-row ISO-3166 reference list being the
 * confirmed real-world case. Any subsequent `--force` regenerate (module.json
 * still declaring an empty `seeder.data`) silently wrote `"data": []` over
 * that file, discarding all 252 rows with no warning and no error — the
 * regenerated seeder ran successfully, it just seeded nothing.
 *
 * Fix: `resolveSeedData()` now preserves an existing, non-empty on-disk
 * `data` array when config declares none, while still letting non-empty
 * config data win (matching every other generator output in this class —
 * explicit config is always authoritative when present).
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Seeders\SeederGenerator
 */
class SeederGeneratorPreservesExistingDataTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-seeder-preserve-test-' . uniqid();
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

    private function seederDataPath(string $moduleGroup, string $moduleName): string
    {
        return PathManager::getBackendModulePath($moduleGroup, $moduleName)
            . "/Seeders/{$moduleName}SeederData.json";
    }

    private function writeExistingSeederData(string $moduleGroup, string $moduleName, array $data): void
    {
        $path = $this->seederDataPath($moduleGroup, $moduleName);
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode(['data' => $data, 'permissions' => []], JSON_PRETTY_PRINT));
    }

    public function test_force_regenerate_with_empty_config_seed_data_preserves_existing_nonempty_file_data(): void
    {
        $existingRows = [
            ['id' => 1, 'iso' => 'AF', 'name' => 'AFGHANISTAN', 'created_by_id' => 1],
            ['id' => 2, 'iso' => 'AL', 'name' => 'ALBANIA', 'created_by_id' => 1],
        ];
        $this->writeExistingSeederData('Core', 'Countries', $existingRows);

        // module.json declares no seed data — the real, confirmed shape for
        // a module whose seed rows were hand-added directly to the JSON file.
        $config = [
            'id_type' => 'bigint',
            'seeder' => ['data' => [], 'permissions' => []],
            'features' => ['backend' => ['list' => true, 'view' => true]],
        ];

        $generator = new SeederGenerator('Countries', 'Core', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $written = json_decode(file_get_contents($this->seederDataPath('Core', 'Countries')), true);

        $this->assertSame($existingRows, $written['data'], 'Existing non-empty seed data must survive a --force regenerate when config declares none.');
    }

    public function test_nonempty_config_seed_data_still_wins_over_existing_file_data(): void
    {
        $this->writeExistingSeederData('Core', 'Countries', [
            ['id' => 1, 'iso' => 'AF', 'name' => 'AFGHANISTAN', 'created_by_id' => 1],
        ]);

        $config = [
            'id_type' => 'bigint',
            'seeder' => [
                'data' => [
                    ['iso' => 'ZQ', 'name' => 'NEWLAND'],
                ],
                'permissions' => [],
            ],
            'features' => ['backend' => ['list' => true, 'view' => true]],
        ];

        $generator = new SeederGenerator('Countries', 'Core', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $written = json_decode(file_get_contents($this->seederDataPath('Core', 'Countries')), true);

        $this->assertCount(1, $written['data']);
        $this->assertSame('ZQ', $written['data'][0]['iso']);
        $this->assertSame('NEWLAND', $written['data'][0]['name']);
        $this->assertSame(1, $written['data'][0]['id'], 'Config-declared rows still go through processSeedData()\'s index-based id assignment.');
    }

    public function test_brand_new_module_with_no_existing_file_and_no_config_data_writes_empty_array(): void
    {
        $config = [
            'id_type' => 'bigint',
            'seeder' => ['data' => [], 'permissions' => []],
            'features' => ['backend' => ['list' => true, 'view' => true]],
        ];

        $generator = new SeederGenerator('BrandNewModule', 'Core', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $written = json_decode(file_get_contents($this->seederDataPath('Core', 'BrandNewModule')), true);

        $this->assertSame([], $written['data']);
    }

    public function test_existing_file_with_empty_data_array_is_not_treated_as_preservable(): void
    {
        $this->writeExistingSeederData('Core', 'Countries', []);

        $config = [
            'id_type' => 'bigint',
            'seeder' => ['data' => [], 'permissions' => []],
            'features' => ['backend' => ['list' => true, 'view' => true]],
        ];

        $generator = new SeederGenerator('Countries', 'Core', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $written = json_decode(file_get_contents($this->seederDataPath('Core', 'Countries')), true);

        $this->assertSame([], $written['data']);
    }
}
