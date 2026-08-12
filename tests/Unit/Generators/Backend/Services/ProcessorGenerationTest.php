<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\CreateServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\DeleteServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\EditServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * `processors[]` (module-wide lifecycle hooks) had zero test coverage of any
 * kind before this file -- confirmed via a full-tree grep for "processors"
 * across tests/ turning up only an unrelated manual smoke script. Both
 * docs/processors.md and schema/module-config.schema.json also described a
 * completely different, non-existent shape (instance-based `(new
 * Service())->handle($data, $model)`, a `method` config key, and
 * before_validation/after_validation stages) before being corrected alongside
 * this test -- see BaseServiceGenerator::generateProcessorCalls() for the
 * real implementation this file locks in.
 *
 * Real, confirmed behavior this test guards:
 * - The generated call is STATIC (`{Namespace}::{method}(...)`), not an
 *   instance call.
 * - The method name is always `Str::camel($stage)` -- there is no `method`
 *   config key, and one supplied in config is silently ignored.
 * - Only before_save/after_save/before_delete/after_delete actually splice
 *   anything; before_validation/after_validation match no call site at all
 *   and are silently dropped.
 * - `$model` is passed as literal `null` for before_save on BOTH create and
 *   edit, even though EditServiceGenerator's beforeUpdate() has a real
 *   $model in scope -- a real, easy-to-get-wrong asymmetry worth locking in
 *   explicitly rather than assuming symmetry with after_save.
 * - `operations` gates which generator emits the call at all -- a processor
 *   declared for `["create"]` only must not appear in the Edit/Delete
 *   service, and vice versa.
 */
class ProcessorGenerationTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-processors-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::setModuleRegistry([]);
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

    public function test_create_service_splices_before_save_and_after_save_processor_calls(): void
    {
        $generator = new CreateServiceGenerator('Orders', 'Core', [
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'total', 'rules' => 'required|numeric'],
            ]]]],
            'processors' => [
                [
                    'stage' => 'before_save',
                    'service' => 'NormalizeTotalsProcessor',
                    'module' => 'Orders',
                    'operations' => ['create'],
                ],
                [
                    'stage' => 'after_save',
                    'service' => 'SendOrderConfirmationProcessor',
                    'module' => 'Orders',
                    'operations' => ['create'],
                    'fields' => ['total'],
                    'config' => ['channel' => 'email'],
                ],
            ],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Orders/Services/OrdersCreateService.php';
        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        // before_save -> beforeSave, static call, $model forced to literal null.
        $this->assertStringContainsString(
            "\$validData = \\App\\Project\\Modules\\Core\\Orders\\Services\\NormalizeTotalsProcessor::beforeSave(\$validData, null, json_decode('[]', true), json_decode('{}', true));",
            $content
        );

        // after_save -> afterSave, real $model passed, fields/config threaded through.
        $this->assertStringContainsString(
            "\$validData = \\App\\Project\\Modules\\Core\\Orders\\Services\\SendOrderConfirmationProcessor::afterSave(\$validData, \$model, json_decode('[\"total\"]', true), json_decode('{\"channel\":\"email\"}', true));",
            $content
        );

        // No instance-call form should ever be emitted.
        $this->assertStringNotContainsString('new NormalizeTotalsProcessor()', $content);
        $this->assertStringNotContainsString('new SendOrderConfirmationProcessor()', $content);
    }

    public function test_edit_service_before_save_processor_also_forces_null_model_despite_model_being_in_scope(): void
    {
        $generator = new EditServiceGenerator('Orders', 'Core', [
            'features' => ['backend' => ['edit' => ['fields' => [
                ['field' => 'total', 'rules' => 'nullable|numeric'],
            ]]]],
            'processors' => [
                [
                    'stage' => 'before_save',
                    'service' => 'NormalizeTotalsProcessor',
                    'module' => 'Orders',
                    'operations' => ['edit'],
                ],
                [
                    'stage' => 'after_save',
                    'service' => 'SendOrderConfirmationProcessor',
                    'module' => 'Orders',
                    'operations' => ['edit'],
                ],
            ],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(
            $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Orders/Services/OrdersEditService.php'
        );

        $this->assertStringContainsString(
            "\$validData = \\App\\Project\\Modules\\Core\\Orders\\Services\\NormalizeTotalsProcessor::beforeSave(\$validData, null, json_decode('[]', true), json_decode('{}', true));",
            $content
        );
        $this->assertStringContainsString(
            "\$validData = \\App\\Project\\Modules\\Core\\Orders\\Services\\SendOrderConfirmationProcessor::afterSave(\$validData, \$model, json_decode('[]', true), json_decode('{}', true));",
            $content
        );
    }

    public function test_delete_service_splices_before_delete_and_after_delete_processor_calls(): void
    {
        $generator = new DeleteServiceGenerator('Orders', 'Core', [
            'features' => ['backend' => ['delete' => ['enabled' => true]]],
            'processors' => [
                [
                    'stage' => 'before_delete',
                    'service' => 'CheckOpenInvoicesProcessor',
                    'module' => 'Orders',
                    'operations' => ['delete'],
                ],
                [
                    'stage' => 'after_delete',
                    'service' => 'ArchiveOrderProcessor',
                    'module' => 'Orders',
                    'operations' => ['delete'],
                ],
            ],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(
            $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Orders/Services/OrdersDeleteService.php'
        );

        $this->assertStringContainsString('CheckOpenInvoicesProcessor::beforeDelete(', $content);
        $this->assertStringContainsString('ArchiveOrderProcessor::afterDelete(', $content);
    }

    public function test_unsupported_before_validation_and_after_validation_stages_emit_nothing(): void
    {
        $generator = new CreateServiceGenerator('Orders', 'Core', [
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'total', 'rules' => 'required|numeric'],
            ]]]],
            'processors' => [
                [
                    'stage' => 'before_validation',
                    'service' => 'NormalizePhoneProcessor',
                    'module' => 'Orders',
                    'operations' => ['create'],
                ],
                [
                    'stage' => 'after_validation',
                    'service' => 'AuditProcessor',
                    'module' => 'Orders',
                    'operations' => ['create'],
                ],
            ],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(
            $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Orders/Services/OrdersCreateService.php'
        );

        $this->assertStringNotContainsString('NormalizePhoneProcessor', $content);
        $this->assertStringNotContainsString('AuditProcessor', $content);
    }

    public function test_operations_gate_restricts_processor_to_declared_operations_only(): void
    {
        $config = [
            'features' => [
                'backend' => [
                    'create' => ['fields' => [['field' => 'total', 'rules' => 'required|numeric']]],
                    'edit'   => ['fields' => [['field' => 'total', 'rules' => 'nullable|numeric']]],
                ],
            ],
            'processors' => [
                [
                    'stage' => 'after_save',
                    'service' => 'CreateOnlyProcessor',
                    'module' => 'Orders',
                    'operations' => ['create'],
                ],
            ],
        ];

        $createGenerator = new CreateServiceGenerator('Orders', 'Core', $config);
        $createGenerator->setForce(true);
        $this->assertTrue($createGenerator->generate());

        $editGenerator = new EditServiceGenerator('Orders', 'Core', $config);
        $editGenerator->setForce(true);
        $this->assertTrue($editGenerator->generate());

        $createContent = (string) file_get_contents(
            $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Orders/Services/OrdersCreateService.php'
        );
        $editContent = (string) file_get_contents(
            $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Orders/Services/OrdersEditService.php'
        );

        $this->assertStringContainsString('CreateOnlyProcessor::afterSave(', $createContent);
        $this->assertStringNotContainsString('CreateOnlyProcessor', $editContent);
    }

    /**
     * `module` does not need to already be a scaffolded/registered module --
     * PathManager::resolveBackendModuleNamespace() falls back to
     * App\Project\Modules\Core\{module} (with a non-fatal warning) when it
     * can't resolve the name any other way. Confirms a processor can point
     * at a hand-placed helper class without ever running make:module for it.
     */
    public function test_unregistered_processor_module_falls_back_to_core_namespace_without_failing_generation(): void
    {
        $generator = new CreateServiceGenerator('Orders', 'Core', [
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'total', 'rules' => 'required|numeric'],
            ]]]],
            'processors' => [
                [
                    'stage' => 'after_save',
                    'service' => 'NotifyOpsProcessor',
                    'module' => 'Helpers',
                    'operations' => ['create'],
                ],
            ],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents(
            $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Orders/Services/OrdersCreateService.php'
        );

        $this->assertStringContainsString(
            '\\App\\Project\\Modules\\Core\\Helpers\\Services\\NotifyOpsProcessor::afterSave(',
            $content
        );
    }
}
