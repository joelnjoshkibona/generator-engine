<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Tests;

use Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A generated create service does plain mass-assignment; the Eloquent cast
 * IS the write-path fix (see ModelGeneratorHashedEncryptedCastsTest). What a
 * generated PHPUnit suite must never do is assert the stored value equals
 * the submitted plaintext — the whole point of `hashed`/`encrypted` is that
 * it doesn't. This is the DB-level replacement: fetch the persisted row and
 * prove the cast actually ran, via `Hash::check()`/decrypt-equality.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator::buildCreateTestMethod()
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator::firstDbAssertableField()
 */
class PhpUnitTestGeneratorHashedEncryptedTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-phpunit-testgen-hashed-' . uniqid();
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

    private function callFirstDbAssertableField(array $config, array $fields): ?array
    {
        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $method = new ReflectionMethod(PhpUnitTestGenerator::class, 'firstDbAssertableField');
        $method->setAccessible(true);

        return $method->invoke($generator, $fields);
    }

    public function test_firstDbAssertableField_never_picks_a_hashed_or_encrypted_field_over_a_unique_plain_one(): void
    {
        $result = $this->callFirstDbAssertableField([], [
            ['field' => 'webhook_secret', 'rules' => 'required|string|unique:widgets,webhook_secret|max:255'],
            ['field' => 'name', 'rules' => 'required|string|max:255'],
        ]);

        $this->assertSame('name', $result['field'] ?? null);
    }

    public function test_firstDbAssertableField_returns_null_when_every_field_is_sensitive(): void
    {
        $result = $this->callFirstDbAssertableField([], [
            ['field' => 'password', 'rules' => 'required|string|max:255'],
        ]);

        $this->assertNull($result);
    }

    /** @return array<string, mixed> */
    private function locationTypesConfigWithColumn(string $field): array
    {
        $path = dirname(__DIR__, 4) . '/Fixtures/LocationTypesModule.json';
        $this->assertFileExists($path, "Expected fixture not found: {$path}");

        $config = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($config, 'LocationTypesModule.json did not decode to an array.');

        array_unshift($config['columns'], ['name' => $field, 'type' => 'string']);
        $entry = ['field' => $field, 'rules' => 'required|string|max:255', 'messages' => []];
        array_unshift($config['features']['backend']['create']['fields'], $entry);
        array_unshift($config['features']['backend']['edit']['fields'], $entry);

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

    public function test_a_hashed_create_field_asserts_hash_check_not_a_round_trip(): void
    {
        $config = $this->locationTypesConfigWithColumn('pin');
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');
        $body = $this->extractMethodBody($content, 'test_can_create_location_type');

        $this->assertStringContainsString('Hash::check(', $body);
        $this->assertStringNotContainsString("assertJsonPath('data.pin'", $body);

        $files = $this->generatedTestFiles('Core', 'LocationTypes');
        foreach ($files as $file) {
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));
        }
    }

    public function test_an_encrypted_create_field_asserts_decrypted_equality_not_a_raw_db_check(): void
    {
        $config = $this->locationTypesConfigWithColumn('api_secret');
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');
        $body = $this->extractMethodBody($content, 'test_can_create_location_type');

        $this->assertStringContainsString("assertSame(\$payload['api_secret'],", $body);
        $this->assertStringNotContainsString("assertDatabaseHas('location_types', ['api_secret'", $body);
    }
}
