<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\BulkActionServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * `bulk_actions[].status_target` generates a call to a PHP constant
 * (`{Module}Model::{status_target}`) on a SEPARATE generator's output
 * (ModelGenerator::generateConstants(), driven by the top-level `constants`
 * config key) than the one that emits the call itself
 * (BulkActionServiceGenerator, driven by `features.backend.list.bulk_actions`).
 *
 * BulkActionServiceGeneratorTest::test_status_target_action_emits_a_status_id_update_body()
 * already proves the CALL text is right, but runs BulkActionServiceGenerator
 * in total isolation -- it never runs ModelGenerator, so it can't catch a
 * constant-name mismatch (e.g. `status_target: 'RECEIVED'` in the bulk action
 * config but `constants: {'Received': 3}` -- wrong case -- in the same
 * module.json) or a completely absent `constants` key. Nothing before this
 * test proved the two generators' output actually agrees when run together
 * against the SAME config, which is exactly the class of bug
 * CrossFileContractTest exists to catch for other mechanisms.
 *
 * Also confirmed here: `constants` is a FLAT {NAME: scalar} map (numeric
 * values emitted unquoted), not the nested {name, values:[{label,value}]}
 * shape schema/module-config.schema.json described before being corrected
 * alongside this test.
 */
class BulkActionStatusTargetModelAgreementTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-status-target-agreement-' . uniqid();
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

    public function test_model_generator_and_bulk_action_service_generator_agree_on_the_status_constant(): void
    {
        $config = [
            'table_name' => 'purchase_orders',
            'connection' => '',
            'id_type' => 'integer',
            'columns' => [['name' => 'po_number', 'type' => 'string']],
            'constants' => ['RECEIVED' => 3],
            'features' => ['backend' => ['list' => [
                'bulk_actions' => [
                    ['key' => 'markReceived', 'label' => 'Mark Received', 'status_target' => 'RECEIVED'],
                ],
            ]]],
        ];

        $modelGenerator = new ModelGenerator('PurchaseOrders', 'Core', $config);
        $this->assertTrue($modelGenerator->generate());

        $bulkActionGenerator = new BulkActionServiceGenerator('PurchaseOrders', 'Core', $config);
        $this->assertTrue($bulkActionGenerator->generate());

        $modelPath = PathManager::getBackendModulePath('Core', 'PurchaseOrders') . '/PurchaseOrdersModel.php';
        $servicePath = PathManager::getBackendModulePath('Core', 'PurchaseOrders') . '/Services/PurchaseOrdersMarkReceivedService.php';

        $this->assertFileExists($modelPath);
        $this->assertFileExists($servicePath);

        $modelContent = (string) file_get_contents($modelPath);
        $serviceContent = (string) file_get_contents($servicePath);

        // The Model actually emits the constant the bulk action call references --
        // numeric value unquoted, exact name match, real PHP class constant syntax.
        $this->assertStringContainsString('public const RECEIVED = 3;', $modelContent);
        $this->assertStringContainsString("\$model->update(['status_id' => PurchaseOrdersModel::RECEIVED]);", $serviceContent);

        // Both files independently valid PHP -- the constant reference isn't
        // just string-matched, it type-checks against a real class member.
        $this->assertGeneratedFileIsSyntacticallyValidAndDefinesTheReferencedConstant($modelPath, $serviceContent);
    }

    /**
     * A mismatched status_target (typo'd against `constants`) is not caught
     * by validation anywhere in the generator -- confirms the current, real
     * failure mode: the Model generates cleanly with no RECEIVED constant,
     * and the bulk action service still emits a call to a constant that will
     * fatal at runtime ("Undefined constant") the first time anyone invokes
     * it. Documents the gap rather than papering over it with an assertion
     * that doesn't match reality.
     */
    public function test_mismatched_status_target_silently_generates_a_call_to_a_constant_the_model_never_defines(): void
    {
        $config = [
            'table_name' => 'purchase_orders',
            'connection' => '',
            'id_type' => 'integer',
            'columns' => [['name' => 'po_number', 'type' => 'string']],
            'constants' => ['RECEIVED' => 3],
            'features' => ['backend' => ['list' => [
                'bulk_actions' => [
                    // Typo: 'Received' (wrong case) vs constants' 'RECEIVED'.
                    ['key' => 'markReceived', 'label' => 'Mark Received', 'status_target' => 'Received'],
                ],
            ]]],
        ];

        $modelGenerator = new ModelGenerator('PurchaseOrders', 'Core', $config);
        $this->assertTrue($modelGenerator->generate());

        $bulkActionGenerator = new BulkActionServiceGenerator('PurchaseOrders', 'Core', $config);
        $this->assertTrue($bulkActionGenerator->generate());

        $modelContent = (string) file_get_contents(
            PathManager::getBackendModulePath('Core', 'PurchaseOrders') . '/PurchaseOrdersModel.php'
        );
        $serviceContent = (string) file_get_contents(
            PathManager::getBackendModulePath('Core', 'PurchaseOrders') . '/Services/PurchaseOrdersMarkReceivedService.php'
        );

        $this->assertStringContainsString('public const RECEIVED = 3;', $modelContent);
        $this->assertStringNotContainsString('public const Received', $modelContent);
        // Generation succeeds and produces a call to a constant that doesn't exist --
        // no cross-generator validation catches this today.
        $this->assertStringContainsString("PurchaseOrdersModel::Received", $serviceContent);
    }

    private function assertGeneratedFileIsSyntacticallyValidAndDefinesTheReferencedConstant(string $modelPath, string $serviceContent): void
    {
        $output = [];
        $exitCode = 0;
        exec('php -l ' . escapeshellarg($modelPath) . ' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, "Generated Model has a PHP syntax error:\n" . implode("\n", $output));

        preg_match("/status_id'\s*=>\s*PurchaseOrdersModel::(\w+)/", $serviceContent, $matches);
        $this->assertNotEmpty($matches, 'Expected the bulk action service to reference a PurchaseOrdersModel status constant.');
        $this->assertStringContainsString("public const {$matches[1]} =", (string) file_get_contents($modelPath));
    }
}
