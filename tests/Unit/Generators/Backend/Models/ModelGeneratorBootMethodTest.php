<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Models;

use Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for ModelGenerator::generateBootMethod() — the
 * Relation::morphMap() registration for a module's own morphs[].targets[].
 *
 * Registration lives on the OWNING model (the one with the morph columns,
 * e.g. Payments), not on each target model — confirmed against the only real
 * precedent in the codebase, NotificationSubscriptionsModel::boot(), whose
 * own module.json owns subscriber_type/subscriber_id and whose own boot()
 * registers the map for both of its targets.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator::generateBootMethod()
 */
class ModelGeneratorBootMethodTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-model-boot-test-' . uniqid();
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

    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'connection' => '',
            'id_type'    => 'integer',
            'columns'    => [
                ['name' => 'amount', 'type' => 'decimal'],
            ],
        ], $overrides);
    }

    private function generateAndRead(array $config, string $moduleName = 'Payments', string $moduleGroup = 'Custom'): string
    {
        $generator = new ModelGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/{$moduleGroup}/{$moduleName}/{$moduleName}Model.php";
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function test_no_morphs_configured_emits_no_boot_method(): void
    {
        $content = $this->generateAndRead($this->baseConfig());

        $this->assertStringNotContainsString('function boot()', $content);
        $this->assertStringNotContainsString('morphMap', $content);
    }

    public function test_morph_with_empty_targets_emits_no_boot_method(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'morphs' => [
                ['name' => 'payable', 'type_column' => 'payable_type', 'id_column' => 'payable_id', 'targets' => []],
            ],
        ]));

        $this->assertStringNotContainsString('function boot()', $content);
    }

    public function test_morph_with_targets_emits_boot_method_registering_relation_morph_map(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'morphs' => [
                ['name' => 'payable', 'type_column' => 'payable_type', 'id_column' => 'payable_id', 'targets' => [
                    ['alias' => 'supplier', 'model' => 'App\\Project\\Modules\\Custom\\Suppliers\\SuppliersModel', 'module' => 'Suppliers', 'label' => 'Supplier'],
                    ['alias' => 'customer', 'model' => 'App\\Project\\Modules\\Custom\\Customers\\CustomersModel', 'module' => 'Customers', 'label' => 'Customer'],
                ]],
            ],
        ]));

        $this->assertStringContainsString('protected static function boot(): void', $content);
        $this->assertStringContainsString('parent::boot();', $content);
        $this->assertStringContainsString('Relation::morphMap([', $content);
        $this->assertStringContainsString("'supplier' => \\App\\Project\\Modules\\Custom\\Suppliers\\SuppliersModel::class,", $content);
        $this->assertStringContainsString("'customer' => \\App\\Project\\Modules\\Custom\\Customers\\CustomersModel::class,", $content);
    }

    public function test_leading_backslash_on_model_class_is_not_doubled(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'morphs' => [
                ['name' => 'payable', 'type_column' => 'payable_type', 'id_column' => 'payable_id', 'targets' => [
                    ['alias' => 'supplier', 'model' => '\\App\\Models\\SuppliersModel', 'module' => 'Suppliers', 'label' => 'Supplier'],
                ]],
            ],
        ]));

        $this->assertStringContainsString("'supplier' => \\App\\Models\\SuppliersModel::class,", $content);
        $this->assertStringNotContainsString('\\\\App\\Models\\SuppliersModel', $content);
    }

    public function test_two_morphs_both_with_targets_merge_into_one_boot_method(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'morphs' => [
                ['name' => 'payable', 'type_column' => 'payable_type', 'id_column' => 'payable_id', 'targets' => [
                    ['alias' => 'supplier', 'model' => 'App\\Models\\SuppliersModel', 'module' => 'Suppliers', 'label' => 'Supplier'],
                ]],
                ['name' => 'billable', 'type_column' => 'billable_type', 'id_column' => 'billable_id', 'targets' => [
                    ['alias' => 'invoice', 'model' => 'App\\Models\\InvoicesModel', 'module' => 'Invoices', 'label' => 'Invoice'],
                ]],
            ],
        ]));

        $this->assertSame(1, substr_count($content, 'function boot()'), 'exactly one boot() method, not one per morph');
        $this->assertStringContainsString("'supplier' =>", $content);
        $this->assertStringContainsString("'invoice' =>", $content);
    }

    public function test_duplicate_alias_pointing_at_different_models_within_one_module_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/Morph alias 'supplier' on module 'Payments' is registered for two different models/");

        $this->generateAndRead($this->baseConfig([
            'morphs' => [
                ['name' => 'payable', 'type_column' => 'payable_type', 'id_column' => 'payable_id', 'targets' => [
                    ['alias' => 'supplier', 'model' => 'App\\Models\\SuppliersModel', 'module' => 'Suppliers', 'label' => 'Supplier'],
                ]],
                ['name' => 'billable', 'type_column' => 'billable_type', 'id_column' => 'billable_id', 'targets' => [
                    ['alias' => 'supplier', 'model' => 'App\\Models\\ContractorsModel', 'module' => 'Contractors', 'label' => 'Contractor'],
                ]],
            ],
        ]));
    }

    public function test_duplicate_alias_pointing_at_the_same_model_does_not_throw(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'morphs' => [
                ['name' => 'payable', 'type_column' => 'payable_type', 'id_column' => 'payable_id', 'targets' => [
                    ['alias' => 'supplier', 'model' => 'App\\Models\\SuppliersModel', 'module' => 'Suppliers', 'label' => 'Supplier'],
                ]],
                ['name' => 'billable', 'type_column' => 'billable_type', 'id_column' => 'billable_id', 'targets' => [
                    ['alias' => 'supplier', 'model' => 'App\\Models\\SuppliersModel', 'module' => 'Suppliers', 'label' => 'Supplier'],
                ]],
            ],
        ]));

        $this->assertSame(1, substr_count($content, "'supplier' =>"), 'harmless idempotent duplicate must only be registered once');
    }
}
