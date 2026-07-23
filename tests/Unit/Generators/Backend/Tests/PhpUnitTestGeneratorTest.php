<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Tests;

use Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Generator-unit coverage for PhpUnitTestGenerator (v2.10.11), run against a
 * scratch PathManager project root (mirrors MenusJsonGeneratorTest's harness:
 * PathManager::setProjectRoot() in setUp, reset+recursive cleanup in
 * tearDown) using the REAL persisted module.json for SYSTEM_SHELL's
 * LocationTypes module — copied verbatim into
 * tests/Fixtures/LocationTypesModule.json (see that file) rather than a
 * hand-rolled config array, so this exercises the exact config shape
 * IntrospectionToConfig/the builder UI actually produce.
 *
 * LocationTypes has every backend CRUD feature enabled (list/create/view/
 * edit/delete), so test_generate_writes_full_crud_test_for_a_module_with_every_feature_enabled()
 * expects every conditional method PhpUnitTestGenerator can emit, with the
 * right HTTP status per method. test_generate_omits_delete_method_when_delete_feature_is_disabled()
 * flips features.backend.delete (and its frontend counterpart, inert here
 * but toggled for parity with PlaywrightTestGeneratorTest's equivalent case)
 * off and confirms ONLY the delete-specific method disappears — the
 * unconditional DeleteCheck/filter coverage, and every other CRUD method,
 * must remain.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator
 */
class PhpUnitTestGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-phpunit-testgen-' . uniqid();
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

    /** @return array<string, mixed> */
    private function locationTypesConfig(): array
    {
        $path = dirname(__DIR__, 4) . '/Fixtures/LocationTypesModule.json';
        $this->assertFileExists($path, "Expected fixture not found: {$path}");

        $config = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($config, 'LocationTypesModule.json did not decode to an array.');

        return $config;
    }

    private function generatedFilePath(): string
    {
        return PathManager::getBackendTestsPath() . '/LocationTypesCrudTest.php';
    }

    private function assertValidPhpSyntax(string $file): void
    {
        $output = [];
        $exitCode = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, "Generated file has a PHP syntax error:\n" . implode("\n", $output));
    }

    /**
     * Assert a substring appears within the body of a specific generated
     * method (rather than merely anywhere in the file) — locates the method
     * by its declaration, then searches only up to the next
     * "    public function " (or end of file).
     */
    private function assertMethodBodyContains(string $content, string $methodName, string $needle): void
    {
        $start = strpos($content, "function {$methodName}(");
        $this->assertNotFalse($start, "Could not locate function {$methodName}( in generated content.");

        $nextMethodPos = strpos($content, "\n    public function ", $start + 1);
        $body = $nextMethodPos === false ? substr($content, $start) : substr($content, $start, $nextMethodPos - $start);

        $this->assertStringContainsString($needle, $body, "Expected the body of {$methodName}() to contain \"{$needle}\".");
    }

    public function test_generate_writes_full_crud_test_for_a_module_with_every_feature_enabled(): void
    {
        $config = $this->locationTypesConfig();

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $path = $this->generatedFilePath();
        $this->assertFileExists($path);
        $this->assertValidPhpSyntax($path);

        $content = (string) file_get_contents($path);

        // Namespace / imports / class shape
        $this->assertStringContainsString('namespace Tests\Feature;', $content);
        $this->assertStringContainsString('use App\Project\Modules\Core\LocationTypes\LocationTypesModel;', $content);
        $this->assertStringContainsString('use App\Project\Modules\Core\Users\Users\UsersModel;', $content);
        $this->assertStringContainsString('class LocationTypesCrudTest extends TestCase', $content);
        $this->assertStringContainsString('protected function createLocationTypeFixture(', $content);

        // One method per enabled backend feature, plus the two pipeline-unconditional ones.
        $expectedMethods = [
            'test_can_list_location_types',
            'test_can_create_location_type',
            'test_can_view_location_type',
            'test_can_edit_location_type',
            'test_delete_check_reports_no_blocking_relationships',
            'test_can_delete_location_type',
            'test_can_filter_location_types_list_by_name',
            'test_create_location_type_validation_fails_with_missing_required_field',
        ];
        foreach ($expectedMethods as $method) {
            $this->assertStringContainsString(
                "function {$method}(",
                $content,
                "Expected generated test to contain method {$method}()."
            );
        }

        // HTTP status codes: create=201, validation-failure=422, everything else=200.
        $this->assertMethodBodyContains($content, 'test_can_list_location_types', 'assertStatus(200)');
        $this->assertMethodBodyContains($content, 'test_can_create_location_type', 'assertStatus(201)');
        $this->assertMethodBodyContains($content, 'test_can_view_location_type', 'assertStatus(200)');
        $this->assertMethodBodyContains($content, 'test_can_edit_location_type', 'assertStatus(200)');
        $this->assertMethodBodyContains($content, 'test_delete_check_reports_no_blocking_relationships', 'assertStatus(200)');
        $this->assertMethodBodyContains($content, 'test_can_delete_location_type', 'assertStatus(200)');
        $this->assertMethodBodyContains($content, 'test_can_filter_location_types_list_by_name', 'assertStatus(200)');
        $this->assertMethodBodyContains(
            $content,
            'test_create_location_type_validation_fails_with_missing_required_field',
            'assertStatus(422)'
        );
        $this->assertMethodBodyContains(
            $content,
            'test_create_location_type_validation_fails_with_missing_required_field',
            "assertJsonValidationErrors(['name'])"
        );

        // Routes derived from the module's real table/route base.
        $this->assertStringContainsString('/api/location-types/list', $content);
        $this->assertStringContainsString('/api/location-types/create', $content);
        $this->assertStringContainsString('/api/location-types/{$fixture->uuid}/view', $content);
        $this->assertStringContainsString('/api/location-types/{$fixture->uuid}/edit', $content);
        $this->assertStringContainsString('/api/location-types/{$fixture->uuid}/delete/check', $content);
        $this->assertStringContainsString('/api/location-types/{$fixture->uuid}/delete', $content);
        $this->assertStringContainsString("assertDatabaseHas('location_types'", $content);
        $this->assertStringContainsString("assertSoftDeleted('location_types'", $content);
    }

    public function test_generate_omits_delete_method_when_delete_feature_is_disabled(): void
    {
        $config = $this->locationTypesConfig();
        $config['features']['backend']['delete'] = false;
        $config['features']['frontend']['delete'] = false; // inert for this generator; toggled for parity

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->generatedFilePath());

        $this->assertStringNotContainsString(
            'function test_can_delete_location_type(',
            $content,
            'Disabling features.backend.delete should omit the delete test method entirely.'
        );
        $this->assertStringNotContainsString('assertSoftDeleted(', $content);
        $this->assertStringNotContainsString('/delete"', $content);

        // Pipeline-unconditional coverage must remain even with delete disabled.
        $this->assertStringContainsString('function test_delete_check_reports_no_blocking_relationships(', $content);
        $this->assertStringContainsString('/delete/check', $content);
        $this->assertStringContainsString('function test_can_filter_location_types_list_by_name(', $content);

        // Every other feature-gated method stays untouched.
        $this->assertStringContainsString('function test_can_list_location_types(', $content);
        $this->assertStringContainsString('function test_can_create_location_type(', $content);
        $this->assertStringContainsString('function test_can_view_location_type(', $content);
        $this->assertStringContainsString('function test_can_edit_location_type(', $content);
    }
}
