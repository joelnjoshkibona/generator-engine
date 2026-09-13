<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Tests;

use Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * A generated PHPUnit suite must agree with ModelGenerator's own $hidden:
 * asserting a sensitive field round-tripped through the JSON response would
 * fail on every module that has one, since $hidden means it never comes
 * back at all. Uses the same real LocationTypesModule.json fixture as
 * PhpUnitTestGeneratorTest, with one sensitive column (`api_secret`)
 * prepended to columns/create.fields/edit.fields — prepended, not
 * appended, so a naive "only checks the LAST field" implementation would
 * still fail this test.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::isSensitive()
 */
class PhpUnitTestGeneratorSensitiveColumnsTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-phpunit-testgen-sensitive-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        PathManager::resetModuleSubGroup();
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

    /** @return array<string, mixed> */
    private function locationTypesConfigWithSensitiveField(): array
    {
        $path = dirname(__DIR__, 4) . '/Fixtures/LocationTypesModule.json';
        $this->assertFileExists($path, "Expected fixture not found: {$path}");

        $config = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($config, 'LocationTypesModule.json did not decode to an array.');

        array_unshift($config['columns'], ['name' => 'api_secret', 'type' => 'string']);

        $sensitiveField = ['field' => 'api_secret', 'rules' => 'required|string|max:255', 'messages' => []];
        array_unshift($config['features']['backend']['create']['fields'], $sensitiveField);
        array_unshift($config['features']['backend']['edit']['fields'], $sensitiveField);

        return $config;
    }

    /** @return string[] */
    private function generatedTestFiles(string $group, string $module): array
    {
        $dir = PathManager::getBackendModulePath($group, $module) . '/Tests';
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.php') ?: [];
        sort($files);
        return $files;
    }

    private function generatedContentFor(string $group, string $module): string
    {
        $files = $this->generatedTestFiles($group, $module);
        $this->assertNotEmpty($files, "Expected at least one generated Tests/ file for {$module}.");

        $content = '';
        foreach ($files as $file) {
            $content .= (string) file_get_contents($file) . "\n";
        }
        return $content;
    }

    private function extractMethodBody(string $content, string $methodName): string
    {
        $start = strpos($content, "function {$methodName}(");
        $this->assertNotFalse($start, "Could not locate function {$methodName}( in generated content.");

        $nextMethodPos = strpos($content, "\n    public function ", $start + 1);
        $nextFilePos = strpos($content, "\n<?php", $start + 1);

        $end = match (true) {
            $nextMethodPos === false && $nextFilePos === false => null,
            $nextMethodPos === false => $nextFilePos,
            $nextFilePos === false => $nextMethodPos,
            default => min($nextMethodPos, $nextFilePos),
        };

        return $end === null ? substr($content, $start) : substr($content, $start, $end - $start);
    }

    public function test_create_test_asserts_the_sensitive_field_is_missing_not_round_tripped(): void
    {
        $config = $this->locationTypesConfigWithSensitiveField();
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');
        $body = $this->extractMethodBody($content, 'test_can_create_location_type');

        $this->assertStringContainsString("assertJsonMissingPath('data.api_secret')", $body);
        $this->assertStringNotContainsString("assertJsonPath('data.api_secret'", $body);
    }

    public function test_view_test_never_asserts_on_the_sensitive_field_and_still_asserts_on_name(): void
    {
        $config = $this->locationTypesConfigWithSensitiveField();
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');
        $body = $this->extractMethodBody($content, 'test_can_view_location_type');

        $this->assertStringNotContainsString('data.api_secret', $body);
        $this->assertStringContainsString("assertJsonPath('data.name'", $body);
    }

    public function test_edit_test_omits_the_sensitive_field_from_response_assertions_but_keeps_name(): void
    {
        $config = $this->locationTypesConfigWithSensitiveField();
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');
        $body = $this->extractMethodBody($content, 'test_can_edit_location_type');

        $this->assertStringNotContainsString("assertJsonPath('data.api_secret'", $body);
        $this->assertStringNotContainsString("assertJsonMissingPath('data.api_secret'", $body);
        $this->assertStringContainsString("assertJsonPath('data.name'", $body);
    }
}
