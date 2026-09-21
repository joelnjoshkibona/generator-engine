<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\DeleteCheckServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Helpers\InlineItemsChildren;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * An inline_items child must not block its parent's delete.
 *
 * The parent's DeleteService cascade-deletes every child row unconditionally, but the generic FK-graph count
 * in {Module}DeleteCheckService counted `order_items` like a cross-module reference. A parent WITH items
 * therefore reported can_delete: false, the frontend showed "cannot delete" for a delete that would have
 * succeeded, and the generated DeleteCheckServiceTest asserted that wrong answer as correct. Confirmed on
 * two modules (Orders -> order_items, Tests -> test_parameters), inherited by a third.
 */
class DeleteCheckInlineItemsChildTest extends TestCase
{
    private string $tmpRoot;

    /** @return array<string, mixed> Orders, with OrderItems as an inline_items child through order_id */
    private function ordersConfig(array $overrides = []): array
    {
        return array_replace_recursive([
            'module_name' => 'Orders',
            'module_type' => 'Core',
            'table_name'  => 'orders',
            'id_type'     => 'bigint',
            'columns'     => [['name' => 'reference', 'type' => 'string', 'nullable' => false]],
            'inline_items' => [
                ['key' => 'order_items', 'child_module' => 'OrderItems', 'child_group' => 'Core', 'parent_fk' => 'order_id'],
            ],
            'features' => ['backend' => ['list' => true, 'view' => true, 'delete' => true], 'frontend' => []],
        ], $overrides);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-inline-delete-check-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
        PathManager::setModuleRegistry([
            ['name' => 'OrderItems', 'module_type' => 'Core', 'group_name' => null, 'table_name' => 'order_items'],
            ['name' => 'Payments', 'module_type' => 'Core', 'group_name' => null, 'table_name' => 'payments'],
        ]);
        PathManager::setForeignKeyGraph([
            'orders' => [
                ['source_table' => 'order_items', 'source_column' => 'order_id'],
                ['source_table' => 'payments', 'source_column' => 'order_id'],
            ],
        ]);
        PathManager::setSkipTables([]);
        PathManager::setIssueHandler(null);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        PathManager::setModuleRegistry([]);
        PathManager::setForeignKeyGraph([]);
        PathManager::setIssueHandler(null);
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    /** The count-check block the DeleteCheckService is built from, for a given module config. */
    private function countChecks(array $config): string
    {
        /** @var TestInlineDeleteCheckGenerator $generator */
        $generator = (new ReflectionClass(TestInlineDeleteCheckGenerator::class))->newInstanceWithoutConstructor();
        foreach (['moduleName' => 'Orders', 'moduleGroup' => 'Core', 'config' => $config] as $property => $value) {
            $prop = new ReflectionProperty($generator, $property);
            $prop->setAccessible(true);
            $prop->setValue($generator, $value);
        }

        return $generator->callGenerateDependentCountChecks();
    }

    public function test_the_helper_matches_only_the_declared_child_through_the_declared_foreign_key(): void
    {
        $config = $this->ordersConfig();

        $this->assertTrue(InlineItemsChildren::isChild($config, 'OrderItems', 'order_id'));
        $this->assertFalse(InlineItemsChildren::isChild($config, 'OrderItems', 'replaced_order_id'), 'another FK from the same child is a real reference');
        $this->assertFalse(InlineItemsChildren::isChild($config, 'Payments', 'order_id'), 'another module is a real dependent');
        $this->assertFalse(InlineItemsChildren::isChild(['table_name' => 'orders'], 'OrderItems', 'order_id'), 'no inline_items, no children');
    }

    public function test_an_inline_items_child_is_not_counted_as_a_blocking_dependent(): void
    {
        $checks = $this->countChecks($this->ordersConfig());

        $this->assertStringNotContainsString('OrderItemsModel::where', $checks);
        $this->assertStringContainsString('OrderItems.order_id is an inline_items child', $checks, 'the generated file says why it is absent');
    }

    public function test_a_real_dependent_next_to_an_inline_child_is_still_counted(): void
    {
        $checks = $this->countChecks($this->ordersConfig());

        $this->assertStringContainsString('\App\Project\Modules\Core\Payments\PaymentsModel::where(\'order_id\', $record->id)->count();', $checks);
    }

    public function test_without_inline_items_the_child_is_counted_as_before(): void
    {
        $config = $this->ordersConfig();
        unset($config['inline_items']);

        $this->assertStringContainsString('OrderItemsModel::where(\'order_id\', $record->id)->count();', $this->countChecks($config));
    }

    public function test_the_generated_test_asserts_that_an_inline_child_does_not_block(): void
    {
        (new PhpUnitTestGenerator('Orders', 'Core', $this->ordersConfig()))->setForce(true)->generate();

        $file = PathManager::getBackendModulePath('Core', 'Orders') . '/Tests/OrdersDeleteCheckServiceTest.php';
        $this->assertFileExists($file);
        $content = (string) file_get_contents($file);

        $this->assertStringContainsString('test_delete_check_does_not_block_on_an_inline_items_child_order_items', $content);
        $this->assertMatchesRegularExpression(
            "/OrderItemsModel::factory\\(\\)->create\\(\\['order_id' => \\\$fixture->id\\]\\);.*?data\\.can_delete', true\\)/s",
            $content,
            'a child row present, and the check still says can_delete: true'
        );

        // The "blocking" test now targets the genuine dependent, never the inline child.
        $this->assertStringContainsString("PaymentsModel::factory()->create(['order_id' => \$fixture->id]);", $content);
        $this->assertStringNotContainsString("test_delete_check_reports_blocking_relationship_when_a_dependent_record_exists(): void\n    {\n        \$fixture = \$this->createOrderFixture();\n        \\App\\Project\\Modules\\Core\\OrderItems\\OrderItemsModel", $content);

        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
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

/** Exposes the protected method under test; named so ReflectionClass can build it without the constructor. */
class TestInlineDeleteCheckGenerator extends DeleteCheckServiceGenerator
{
    public function callGenerateDependentCountChecks(): string
    {
        return $this->generateDependentCountChecks();
    }
}
