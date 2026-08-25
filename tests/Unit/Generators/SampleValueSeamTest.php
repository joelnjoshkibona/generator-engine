<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators;

use Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Some constraints are unreachable from anything a generator can read.
 *
 * A field with a `processing_service` is the clearest case: its value must satisfy a NORMALIZER,
 * not a validation rule. `nullable|string|max:255` is all the rules say, while the service rejects
 * everything that is not a parseable phone number. Confirmed live on a rental-CRM domain — every
 * Tenants create 422'd with `Invalid phone number: Test Phone 68ab...`, and both generators already
 * KNEW the field had a processing_service (they weaken their assertions for exactly that reason)
 * but had no way to be told what a good value looks like. Each affected project patched the
 * generated payloads by hand, in two places, after every regenerate.
 *
 * `sample_value` closes that. `sample_value_js` / `sample_value_php` carry a raw expression for a
 * value that must vary per run — a unique-indexed column needs one.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator::sampleValueLiteral()
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator::sampleValueExpr()
 */
class SampleValueSeamTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-sample-value-' . uniqid();
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
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function generateSpec(array $backendFieldExtras, string $rules = 'required|string|max:255'): string
    {
        $config = [
            'table_name' => 'tenants',
            'id_type' => 'autoincrement',
            'columns' => [
                ['name' => 'name', 'type' => 'string', 'length' => '255'],
                ['name' => 'phone', 'type' => 'string', 'length' => '255'],
            ],
            'features' => [
                'backend' => [
                    'list' => ['enabled' => true],
                    'create' => ['enabled' => true, 'fields' => [
                        ['field' => 'name', 'rules' => 'required|string|max:255'],
                        array_merge(['field' => 'phone', 'rules' => $rules], $backendFieldExtras),
                    ]],
                    'edit' => ['enabled' => true, 'fields' => [
                        ['field' => 'name', 'rules' => 'required|string|max:255'],
                        array_merge(['field' => 'phone', 'rules' => $rules], $backendFieldExtras),
                    ]],
                    'view' => ['enabled' => true],
                ],
                'frontend' => [
                    'list' => ['enabled' => true, 'fields' => [
                        ['key' => 'name', 'title' => 'Name', 'data' => 'name'],
                        ['key' => 'phone', 'title' => 'Phone', 'data' => 'phone'],
                    ]],
                    'create' => ['enabled' => true, 'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'field_type' => 'input'],
                        ['field' => 'phone', 'label' => 'Phone', 'field_type' => 'input'],
                    ]],
                    'edit' => ['enabled' => true, 'fields' => [
                        ['field' => 'phone', 'label' => 'Phone', 'field_type' => 'input'],
                    ]],
                    'view' => ['enabled' => true],
                ],
            ],
        ];

        $generator = new PlaywrightTestGenerator('Tenants', 'Core', $config);
        $this->assertTrue($generator->generate());

        return (string) file_get_contents(
            PathManager::getFrontendModulePath('Core', 'Tenants') . '/e2e/tenants-crud.e2e.js'
        );
    }

    public function test_sample_value_js_expression_is_emitted_verbatim(): void
    {
        $content = $this->generateSpec([
            'processing_service' => 'TenantsPhoneNumberService',
            'sample_value_js' => "('+2557' + String(stamp).slice(-8))",
        ]);

        $this->assertStringContainsString("('+2557' + String(stamp).slice(-8))", $content);
        $this->assertStringNotContainsString('E2E Tenants Phone', $content);
    }

    public function test_scalar_sample_value_is_rendered_as_a_literal(): void
    {
        $content = $this->generateSpec(['sample_value' => '+255700000001']);

        $this->assertStringContainsString("'+255700000001'", $content);
        $this->assertStringNotContainsString('E2E Tenants Phone', $content);
    }

    /**
     * An explicit sample_value is the author overriding the generator, so it beats a derived `in:`
     * value too — the two disagreeing is an authoring error, not something to silently arbitrate.
     */
    public function test_sample_value_takes_precedence_over_an_in_rule(): void
    {
        $content = $this->generateSpec(
            ['sample_value' => 'OVERRIDE'],
            'required|string|in:alpha,beta'
        );

        $this->assertStringContainsString("'OVERRIDE'", $content);
        $this->assertStringNotContainsString("'alpha'", $content);
    }

    public function test_absent_sample_value_leaves_the_generic_fill_untouched(): void
    {
        $content = $this->generateSpec([]);

        $this->assertStringContainsString('E2E Tenants Phone', $content);
    }

    /** An empty or whitespace-only expression is not a value — it must not blank the field out. */
    public function test_blank_expression_is_ignored(): void
    {
        $content = $this->generateSpec(['sample_value_js' => '   ']);

        $this->assertStringContainsString('E2E Tenants Phone', $content);
    }
}
