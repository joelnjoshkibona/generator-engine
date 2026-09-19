<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators;

use Blutrixx\GeneratorEngine\Generators\Backend\Controller\ControllerGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\Action\ActionSplashServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Catches the two-way mismatch directly: the splash route's handler must exist
 * on the controller, and the controller's own splash-service import must name a
 * class the splash service generator actually wrote — regardless of which of
 * RoutesGenerator/ControllerGenerator/ActionSplashServiceGenerator this plan's
 * fix landed on first, this test fails if any one of the three still guesses
 * independently. See plans/038.
 */
class ActionSplashNamingContractTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-action-splash-contract-test-' . uniqid();
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

    private function assertSplashNamingContract(array $action, string $actionKey): void
    {
        $config = [
            'module_name' => 'InvokeSplash',
            'module_type' => 'Core',
            'table_name' => 'invoke_splashes',
            'id_type' => 'bigint',
            'columns' => [],
            'features' => [],
            'actions' => [$actionKey => $action],
        ];

        $modulePath = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/InvokeSplash';

        foreach ([ControllerGenerator::class, RoutesGenerator::class] as $cls) {
            $g = new $cls('InvokeSplash', 'Core', $config);
            $g->setForce(true);
            $this->assertTrue($g->generate());
        }
        $splashGen = new ActionSplashServiceGenerator('InvokeSplash', 'Core', $config, $actionKey, $action);
        $splashGen->setForce(true);
        $this->assertTrue($splashGen->generate());

        $routesContent = file_get_contents($modulePath . '/Routes/api.php');
        $controllerContent = file_get_contents($modulePath . '/InvokeSplashController.php');

        $matches = [];
        preg_match_all(
            "~->get\\('/[^']+/\\{uuid\\}/[^']+/splash',\\s*\\[\\w+Controller::class,\\s*'(\\w+)'\\]\\)~",
            $routesContent,
            $matches
        );
        $this->assertCount(1, $matches[1], 'Expected exactly one splash route registration.');
        $handler = $matches[1][0];

        $this->assertStringContainsString(
            "public function {$handler}(",
            $controllerContent,
            "Controller has no method named '{$handler}', the exact name the splash route declares."
        );

        $importMatches = [];
        preg_match_all('~^use ([\w\\\\]+\\\\(\w+SplashService));~m', $controllerContent, $importMatches);
        $importedClasses = $importMatches[2] ?? [];

        $realClasses = [];
        foreach (glob($modulePath . '/Services/*SplashService.php') as $file) {
            $body = file_get_contents($file);
            if (preg_match('~^class (\w+)~m', $body, $m)) {
                $realClasses[] = $m[1];
            }
        }

        foreach ($importedClasses as $imported) {
            $this->assertContains(
                $imported,
                $realClasses,
                "Controller imports {$imported}, but no generated SplashService file declares that class."
            );
        }
    }

    public function test_default_splash(): void
    {
        $this->assertSplashNamingContract([
            'name' => 'ping',
            'splash' => true,
            'operations' => ['create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/invoke-splash/ping']]],
        ], 'ping');
    }

    public function test_service_name_only(): void
    {
        $this->assertSplashNamingContract([
            'name' => 'report',
            'serviceName' => 'InvokeSplashYearReportService',
            'splash' => true,
            'urlParams' => ['uuid'],
            'operations' => ['list' => ['enabled' => true, 'endpoint' => ['method' => 'GET', 'path' => '/invoke-splash/{uuid}/report']]],
        ], 'report');
    }

    public function test_method_name_only(): void
    {
        $this->assertSplashNamingContract([
            'name' => 'send',
            'methodName' => 'sendNow',
            'splash' => true,
            'operations' => ['create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/invoke-splash/send']]],
        ], 'send');
    }

    public function test_service_name_and_method_name_the_repro_case(): void
    {
        $this->assertSplashNamingContract([
            'name' => 'report2',
            'serviceName' => 'InvokeSplashYearReportService',
            'methodName' => 'yearReport',
            'splash' => true,
            'urlParams' => ['uuid'],
            'operations' => ['list' => ['enabled' => true, 'endpoint' => ['method' => 'GET', 'path' => '/invoke-splash/{uuid}/report2']]],
        ], 'report2');
    }
}
