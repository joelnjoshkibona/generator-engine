<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\MobileApp\Backend\Registry;

use Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Registry\MobileRegistryGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the mobile registry write.
 *
 * This class had NO test at all, which is how a real defect shipped: when the
 * JSON writers were switched to `encodeJsonPreservingIndent()`, this call site
 * received `$path` — a variable that does not exist in `generate()`, whose
 * local is `$registryPath`. Every generation of a module then reported
 * `Failed: [MobileRegistry] Undefined variable $path`, and the mobile registry
 * was never written.
 *
 * The package's 431 unit tests passed both before and after that regression,
 * because none of them executed this method. It surfaced only by running the
 * real `make:module` against the consuming app.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\Registry\MobileRegistryGenerator
 */
class MobileRegistryGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-mobile-registry-' . uniqid();
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

    private function registryPath(): string
    {
        return PathManager::getMobileAppBasePath() . '/app/Modules/registry.json';
    }

    public function test_generate_writes_the_module_into_the_mobile_registry(): void
    {
        $generator = new MobileRegistryGenerator('Invoices', 'System', []);

        $this->assertTrue($generator->generate(), 'generate() reported failure.');
        $this->assertFileExists($this->registryPath());

        $data = json_decode(file_get_contents($this->registryPath()), true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('Invoices', $data);
        $this->assertSame('App\\Modules\\System\\Invoices', $data['Invoices']['namespace']);
        $this->assertSame('app/Modules/System/Invoices', $data['Invoices']['path']);
        $this->assertSame('System', $data['Invoices']['group']);
    }

    public function test_generate_merges_into_an_existing_registry_without_dropping_entries(): void
    {
        $path = $this->registryPath();
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode([
            'Existing' => ['namespace' => 'App\\Modules\\Core\\Existing', 'path' => 'app/Modules/Core/Existing', 'group' => 'Core'],
        ], JSON_PRETTY_PRINT));

        $this->assertTrue((new MobileRegistryGenerator('Invoices', 'System', []))->generate());

        $data = json_decode(file_get_contents($path), true);
        $this->assertArrayHasKey('Existing', $data, 'An existing entry was dropped.');
        $this->assertArrayHasKey('Invoices', $data);
    }

    public function test_generate_preserves_the_existing_indentation_width(): void
    {
        $path = $this->registryPath();
        mkdir(dirname($path), 0755, true);
        // Two-space indented, as menus.json is in the real app.
        file_put_contents($path, "{\n  \"Existing\": {\n    \"group\": \"Core\"\n  }\n}\n");

        $this->assertTrue((new MobileRegistryGenerator('Invoices', 'System', []))->generate());

        $lines = explode("\n", file_get_contents($path));
        $firstIndented = null;
        foreach ($lines as $line) {
            if (preg_match('/^( +)\S/', $line, $m)) {
                $firstIndented = strlen($m[1]);
                break;
            }
        }

        $this->assertSame(2, $firstIndented, 'Rewriting the registry changed its indentation width.');
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
