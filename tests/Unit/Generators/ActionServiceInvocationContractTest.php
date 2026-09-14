<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators;

use Blutrixx\GeneratorEngine\Generators\Backend\Controller\ControllerGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\Action\ActionServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Cross-file contract for plan 033: for every action route the generated
 * Routes/api.php declares, the controller method it points at exists, that
 * method's body calls exactly one `{Class}Service::{method}(...)`, that
 * service class exists with a matching static method whose parameter types
 * line up with the call's own arguments. This is the whole point of
 * serviceMethod/serviceArgs -- the three generators (Routes, Controller,
 * ActionService) must never independently drift on the call shape.
 *
 * @see \Blutrixx\GeneratorEngine\Helpers\ActionServiceInvocation
 */
class ActionServiceInvocationContractTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-action-invocation-contract-' . uniqid();
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

    public function test_every_generated_action_route_calls_a_real_static_method_with_matching_argument_types(): void
    {
        $op = fn (string $method, string $path): array => [
            'enabled' => true,
            'endpoint' => ['method' => $method, 'path' => $path],
        ];

        $actions = [
            'approve' => [
                'name' => 'approve',
                'urlParams' => ['uuid'],
                'operations' => ['create' => $op('POST', '/invoke-contract/{uuid}/approve')],
            ],
            'send' => [
                'name' => 'send',
                'serviceMethod' => 'sendFromConsole',
                'operations' => ['create' => $op('POST', '/invoke-contract/send')],
            ],
            'issue' => [
                'name' => 'issue',
                'serviceName' => 'IssueKeyService',
                'methodName' => 'issueKey',
                'urlParams' => ['uuid'],
                'serviceArgs' => ['request', 'user', 'param:uuid'],
                'operations' => ['view' => $op('GET', '/invoke-contract/{uuid}/issue')],
            ],
            'archive' => [
                'name' => 'archive',
                'urlParams' => ['uuid'],
                'serviceArgs' => [],
                'operations' => ['delete' => $op('POST', '/invoke-contract/{uuid}/archive')],
            ],
        ];

        $config = [
            'module_name' => 'InvokeContract',
            'module_type' => 'Core',
            'table_name' => 'invoke_contracts',
            'id_type' => 'bigint',
            'columns' => [],
            'features' => ['backend' => []],
            'actions' => $actions,
        ];

        $routesGen = new RoutesGenerator('InvokeContract', 'Core', $config);
        $routesGen->setForce(true);
        $this->assertTrue($routesGen->generate());

        $controllerGen = new ControllerGenerator('InvokeContract', 'Core', $config);
        $controllerGen->setForce(true);
        $this->assertTrue($controllerGen->generate());

        foreach ($actions as $key => $action) {
            $serviceGen = new ActionServiceGenerator('InvokeContract', 'Core', $config, $key, $action);
            $this->assertTrue($serviceGen->generate());
        }

        $basePath = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/InvokeContract';
        $routesContent = (string) file_get_contents($basePath . '/Routes/api.php');
        $controllerContent = (string) file_get_contents($basePath . '/InvokeContractController.php');

        preg_match(
            '~\[generator:region:custom-routes:start\](.*?)// \[generator:region:custom-routes:end\]~s',
            $routesContent,
            $regionMatch
        );
        $customRoutesRegion = $regionMatch[1] ?? '';

        preg_match_all(
            "~->(get|post|put|patch|delete)\('([^']+)', \[InvokeContractController::class, '(\w+)'\]\)~",
            $customRoutesRegion,
            $matches,
            PREG_SET_ORDER
        );

        $this->assertCount(4, $matches, 'Expected exactly 4 action routes in the custom-routes region.');

        foreach ($matches as $match) {
            [, , , $handler] = $match;

            $this->assertStringContainsString("public function {$handler}(", $controllerContent);

            $methodBody = $this->extractMethodBody($controllerContent, $handler);
            $this->assertMatchesRegularExpression('~(\w+)Service::(\w+)\((.*)\);~', $methodBody);
            preg_match('~(\w+)Service::(\w+)\((.*)\);~', $methodBody, $callMatch);
            [, $className, $serviceMethod, $argsStr] = $callMatch;
            $fullClassName = $className . 'Service';

            $servicePath = $basePath . "/Services/{$fullClassName}.php";
            $this->assertFileExists($servicePath, "Expected {$fullClassName}.php to exist for handler {$handler}.");

            $serviceSource = (string) file_get_contents($servicePath);
            $this->assertMatchesRegularExpression('~^namespace (.+);~m', $serviceSource);
            preg_match('~^namespace (.+);~m', $serviceSource, $nsMatch);
            $namespace = $nsMatch[1];
            $fqcn = $namespace . '\\' . $fullClassName;

            require_once $servicePath;

            $args = $argsStr === '' ? [] : explode(', ', $argsStr);

            $reflectionMethod = new \ReflectionMethod($fqcn, $serviceMethod);
            $this->assertTrue($reflectionMethod->isPublic(), "{$fqcn}::{$serviceMethod} must be public.");
            $this->assertTrue($reflectionMethod->isStatic(), "{$fqcn}::{$serviceMethod} must be static.");
            $this->assertSame(count($args), $reflectionMethod->getNumberOfRequiredParameters());
            $this->assertSame(count($args) + 1, $reflectionMethod->getNumberOfParameters());

            $controllerMethodSignature = $this->extractMethodSignature($controllerContent, $handler);

            foreach ($args as $index => $arg) {
                $paramType = $reflectionMethod->getParameters()[$index]->getType();

                if ($arg === '$request->all()') {
                    $this->assertSame('array', (string) $paramType);
                } elseif ($arg === '$request') {
                    $this->assertSame('Illuminate\Http\Request', (string) $paramType);
                } elseif ($arg === '$request->user()') {
                    $this->assertSame('?Illuminate\Contracts\Auth\Authenticatable', (string) $paramType);
                    $this->assertTrue($paramType->allowsNull());
                } elseif (preg_match('~^\$(\w+)$~', $arg, $m)) {
                    $this->assertSame('string', (string) $paramType);
                    $this->assertStringContainsString("string \${$m[1]}", $controllerMethodSignature);
                } else {
                    $this->fail("Unexpected controller call argument: {$arg}");
                }
            }
        }
    }

    private function extractMethodBody(string $content, string $methodName): string
    {
        $pattern = '~public function ' . preg_quote($methodName, '~') . '\([^)]*\)[^{]*\{(.*?)\n    \}~s';
        preg_match($pattern, $content, $m);

        return $m[1] ?? '';
    }

    private function extractMethodSignature(string $content, string $methodName): string
    {
        $pattern = '~public function ' . preg_quote($methodName, '~') . '\(([^)]*)\)~';
        preg_match($pattern, $content, $m);

        return $m[1] ?? '';
    }
}
