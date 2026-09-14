<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Controller;

use Blutrixx\GeneratorEngine\Generators\Backend\Controller\ControllerGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Plan 033: the generated controller method now emits whatever
 * serviceMethod/serviceArgs the action declares, instead of always calling
 * `{Module}{Action}Service::execute($request->all()[, ...])` -- a
 * write-once service reshaped by hand (see NJIWA's MessagesSendService)
 * used to break the moment its module regenerated for any unrelated reason.
 *
 * @see \Blutrixx\GeneratorEngine\Helpers\ActionServiceInvocation
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Controller\ControllerGenerator::generateActionMethods()
 */
class ControllerActionInvocationTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-controller-action-invocation-' . uniqid();
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

    private function approveAction(array $overrides = [], array $operations = null): array
    {
        return array_merge([
            'name' => 'approve',
            'urlParams' => ['uuid'],
            'operations' => $operations ?? [
                'create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/products/{uuid}/approve']],
            ],
        ], $overrides);
    }

    private function config(array $action): array
    {
        return [
            'module_name' => 'Products',
            'module_type' => 'System',
            'table_name' => 'products',
            'id_type' => 'bigint',
            'columns' => [],
            'features' => ['backend' => []],
            'actions' => ['approve' => $action],
        ];
    }

    private function generateController(array $action): string
    {
        $generator = new ControllerGenerator('Products', 'System', $this->config($action));
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/Products/ProductsController.php';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_default_call_shape_is_byte_identical_to_before(): void
    {
        $content = $this->generateController($this->approveAction());

        $this->assertStringContainsString(
            "    public function createApprove(Request \$request, string \$uuid): \\Illuminate\\Http\\JsonResponse\n"
            . "    {\n"
            . "        \$result = ProductsApproveService::execute(\$request->all(), \$uuid);\n"
            . "        return response()->json(\$result, \$result['code']);\n"
            . "    }",
            $content
        );
    }

    public function test_default_call_shape_with_no_url_params(): void
    {
        $content = $this->generateController($this->approveAction(['urlParams' => []]));

        $this->assertStringContainsString('ProductsApproveService::execute($request->all());', $content);
    }

    public function test_custom_service_method_only(): void
    {
        $content = $this->generateController($this->approveAction(['serviceMethod' => 'sendFromConsole']));

        $this->assertStringContainsString('ProductsApproveService::sendFromConsole($request->all(), $uuid);', $content);
        $this->assertStringNotContainsString('::execute(', $content);
    }

    public function test_custom_service_method_and_args(): void
    {
        $content = $this->generateController($this->approveAction([
            'serviceMethod' => 'sendFromConsole',
            'serviceArgs' => ['request', 'user', 'param:uuid'],
        ]));

        $this->assertStringContainsString('::sendFromConsole($request, $request->user(), $uuid);', $content);
        $this->assertStringContainsString('createApprove(Request $request, string $uuid)', $content);

        $tmpFile = $this->tmpRoot . '/lint_check.php';
        file_put_contents($tmpFile, $content);
        exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    public function test_create_and_view_both_enabled_emit_the_call_twice(): void
    {
        $content = $this->generateController($this->approveAction([
            'serviceMethod' => 'sendFromConsole',
        ], [
            'create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/products/{uuid}/approve']],
            'view' => ['enabled' => true, 'endpoint' => ['method' => 'GET', 'path' => '/products/{uuid}/approve-check']],
        ]));

        $this->assertSame(2, substr_count($content, '::sendFromConsole('));
    }

    public function test_invalid_service_args_throws_and_writes_nothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $generator = new ControllerGenerator('Products', 'System', $this->config(
                $this->approveAction(['serviceArgs' => ['body']])
            ));
            $generator->setForce(true);
            $generator->generate();
        } finally {
            $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/Products/ProductsController.php';
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_add_action_methods_on_an_existing_controller_uses_the_custom_method(): void
    {
        $controllerPath = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/Products/ProductsController.php';
        mkdir(dirname($controllerPath), 0755, true);
        file_put_contents($controllerPath, "<?php\n\nnamespace App\\Project\\Modules\\System\\Products;\n\nuse Illuminate\\Http\\Request;\nuse App\\Http\\Controllers\\Controller;\n\nclass ProductsController extends Controller\n{\n}\n");

        $action = $this->approveAction(['serviceMethod' => 'sendFromConsole']);
        $ctrlGen = new ControllerGenerator('Products', 'System', $this->config($action));

        $this->assertTrue($ctrlGen->addActionMethods('approve', $action));

        $content = (string) file_get_contents($controllerPath);
        $this->assertStringContainsString('::sendFromConsole(', $content);
    }

    private function makeGeneratorWithTodaysStub(array $action): ControllerGenerator
    {
        $config = $this->config($action);

        return new class('Products', 'System', $config) extends ControllerGenerator {
            protected function getTemplateContent(string $stubName, string $type = 'backend'): string
            {
                if ($stubName === 'Features/action/controller_method') {
                    return "    public function [[methodName]](Request \$request[[urlParams]]): \\Illuminate\\Http\\JsonResponse\n"
                        . "    {\n"
                        . "        \$result = [[ModuleName]][[ActionName]]Service::execute(\$request->all()[[urlParamsArgs]]);\n"
                        . "        return response()->json(\$result, \$result['code']);\n"
                        . "    }";
                }

                return parent::getTemplateContent($stubName, $type);
            }
        };
    }

    public function test_project_stub_override_lacking_the_new_placeholders_is_warned_only_when_custom_keys_are_declared(): void
    {
        $issues = [];
        PathManager::setIssueHandler(function (string $message, string $severity = 'warning') use (&$issues) {
            $issues[] = $message;
        });

        try {
            $declared = $this->makeGeneratorWithTodaysStub($this->approveAction(['serviceMethod' => 'sendFromConsole']));
            $declared->setForce(true);
            $declared->generate();

            $this->assertCount(1, $issues);
            $this->assertStringContainsString('serviceMethod/serviceArgs are ignored', $issues[0]);

            $issues = [];
            $undeclared = $this->makeGeneratorWithTodaysStub($this->approveAction());
            $undeclared->setForce(true);
            $undeclared->generate();

            $this->assertCount(0, $issues);
        } finally {
            PathManager::setIssueHandler(null);
        }
    }
}
