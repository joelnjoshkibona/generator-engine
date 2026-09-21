<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Config;

use Blutrixx\GeneratorEngine\Generators\Backend\Config\ModuleConfigGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * `delegations` (and `actions`, `constants`, `json_rules`) are keyed maps, and the docs show them as `{}`. The
 * generator wrote an empty one as `[]`, so every fresh module.json began with `"delegations": []` and anyone
 * following the empty scaffold as a template wrote a flat array once they added a delegation, got integer keys
 * where delegation names belong, and had to be told it must become an object.
 */
class ModuleConfigGeneratorMapShapeTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-map-shape-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    /** @return array{0: string, 1: array<string, mixed>} the raw module.json text and its decoded form */
    private function written(array $config): array
    {
        (new ModuleConfigGenerator('Widgets', 'Core', array_replace([
            'module_name' => 'Widgets', 'module_type' => 'Core', 'table_name' => 'widgets', 'id_type' => 'bigint',
            'columns' => [], 'processors' => [], 'seeder' => [],
        ], $config)))->setForce(true)->generate();

        $raw = (string) file_get_contents(PathManager::getBackendModulePath('Core', 'Widgets') . '/module.json');

        return [$raw, json_decode($raw, true)];
    }

    public function test_an_empty_keyed_map_is_written_as_an_object(): void
    {
        [$raw] = $this->written(['delegations' => [], 'actions' => [], 'constants' => [], 'json_rules' => []]);

        foreach (['delegations', 'actions', 'constants', 'json_rules'] as $key) {
            $this->assertMatchesRegularExpression('/"' . $key . '": \{\}/', $raw, "{$key} is an empty map, so {}");
        }
    }

    public function test_a_list_key_stays_a_list(): void
    {
        [$raw] = $this->written(['delegations' => []]);

        $this->assertMatchesRegularExpression('/"processors": \[\]/', $raw);
        $this->assertMatchesRegularExpression('/"columns": \[\]/', $raw);
    }

    public function test_a_populated_map_keeps_its_keys_and_reads_back_the_same(): void
    {
        [, $decoded] = $this->written([
            'delegations' => ['invoice' => ['name' => 'Invoice', 'uiType' => 'tab']],
            'constants' => ['PENDING' => 'pending'],
        ]);

        $this->assertSame(['invoice'], array_keys($decoded['delegations']));
        $this->assertSame('tab', $decoded['delegations']['invoice']['uiType']);
        $this->assertSame(['PENDING' => 'pending'], $decoded['constants']);
    }

    public function test_the_empty_map_reads_back_as_the_empty_array_every_reader_already_expects(): void
    {
        [, $decoded] = $this->written(['delegations' => []]);

        $this->assertSame([], $decoded['delegations']);
    }

    public function test_a_key_the_config_does_not_have_is_not_invented(): void
    {
        [, $decoded] = $this->written([]);

        $this->assertArrayNotHasKey('json_rules', $decoded);
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
}
