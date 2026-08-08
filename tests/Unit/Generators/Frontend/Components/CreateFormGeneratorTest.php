<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\CreateFormGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a bug found + fixed 2026-08-08 while running all 5
 * generator-engine integration-test suites simultaneously against a real
 * consuming project (SYSTEM_SHELL): generateFormFields() joins fields with
 * ",\n" and never trails the last one with a comma, but the inline_items
 * append here used to tack `{key}: [] as any[],` straight onto $formFields
 * with no comma in between -- a hard Vue SFC compile error confirmed live
 * (`[vue/compiler-sfc] Unexpected token, expected ","`) that broke
 * OrdersCreateForm.vue outright and, via Vite's global HMR error overlay,
 * cascaded into unrelated modules' e2e test failures in the same
 * dev-server session.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\CreateFormGenerator
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::generateFormFields()
 */
class CreateFormGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-createformgen-test-' . uniqid();
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
    private function ordersLikeConfig(): array
    {
        return [
            'table_name' => 'orders',
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'id', 'type' => 'text']]],
                    'create' => true,
                    'view' => true,
                    'edit' => true,
                    'delete' => true,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'notes'],
                    'create' => [
                        'fields' => [
                            ['field' => 'notes', 'label' => 'Notes', 'field_type' => 'input', 'type' => 'text', 'required' => false],
                        ],
                    ],
                    'view' => true,
                    'edit' => [
                        'fields' => [
                            ['field' => 'notes', 'label' => 'Notes', 'field_type' => 'input', 'type' => 'text', 'required' => false],
                        ],
                    ],
                    'delete' => true,
                ],
            ],
            'inline_items' => [
                ['key' => 'order_items', 'label' => 'Order Items', 'primary_field' => 'product_name'],
            ],
        ];
    }

    public function test_inline_items_field_is_comma_separated_from_the_last_regular_field(): void
    {
        $config = $this->ordersLikeConfig();

        $generator = new CreateFormGenerator('Orders', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $this->assertFileExists($path);

        $content = (string) file_get_contents($path);

        // The exact broken shape must never reappear: the last regular
        // field's line immediately followed by the inline_items field with
        // no comma between them.
        $this->assertStringNotContainsString("notes: ''\n\torder_items: [] as any[],", $content);
        $this->assertMatchesRegularExpression(
            "/notes: ''.*,\\s*\\n\\torder_items: \\[\\] as any\\[\\],/",
            $content
        );
    }

    public function test_form_with_no_regular_fields_still_produces_valid_leading_inline_items_field(): void
    {
        $config = $this->ordersLikeConfig();
        unset($config['features']['frontend']['create']['fields']);
        $config['features']['frontend']['create']['fields'] = [];

        $generator = new CreateFormGenerator('Orders', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath('Custom', 'Orders') . '/Components/OrdersCreateForm.vue';
        $content = (string) file_get_contents($path);

        // No leading stray comma when there are zero regular fields.
        $this->assertStringNotContainsString(",\n\torder_items: [] as any[],", $content);
        $this->assertStringContainsString("\n\torder_items: [] as any[],", $content);
    }
}
