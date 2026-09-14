<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Routes;

use Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * The splash route's handler name must come from the same base
 * (resolveActionBaseMethod()) the non-splash route already uses — see plans/038.
 */
class RoutesGeneratorActionSplashNamingTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-routes-splash-naming-test-' . uniqid();
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

    private function generateAndReadRoutes(array $action, string $actionKey): string
    {
        $config = [
            'module_name' => 'Widgets',
            'module_type' => 'Core',
            'table_name' => 'widgets',
            'id_type' => 'bigint',
            'columns' => [],
            'features' => ['backend' => ['list' => ['enabled' => true]]],
            'actions' => [$actionKey => $action],
        ];

        $generator = new RoutesGenerator('Widgets', 'Core', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/Routes/api.php';
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function test_default_splash_with_no_overrides_uses_the_action_name(): void
    {
        $content = $this->generateAndReadRoutes([
            'name' => 'ping',
            'splash' => true,
            'operations' => ['create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/widgets/ping']]],
        ], 'ping');

        $this->assertStringContainsString("[WidgetsController::class, 'pingSplash']", $content);
    }

    public function test_service_name_only_uses_the_stripped_service_name_for_the_splash_handler(): void
    {
        $content = $this->generateAndReadRoutes([
            'name' => 'report',
            'serviceName' => 'WidgetsYearReportService',
            'splash' => true,
            'urlParams' => ['uuid'],
            'operations' => ['list' => ['enabled' => true, 'endpoint' => ['method' => 'GET', 'path' => '/widgets/{uuid}/report']]],
        ], 'report');

        $this->assertStringContainsString("'yearReportSplash']", $content);
        $this->assertStringNotContainsString("'reportSplash']", $content);
    }

    public function test_service_name_and_method_name_the_repro_case(): void
    {
        $content = $this->generateAndReadRoutes([
            'name' => 'report',
            'serviceName' => 'WidgetsYearReportService',
            'methodName' => 'yearReport',
            'splash' => true,
            'urlParams' => ['uuid'],
            'operations' => ['list' => ['enabled' => true, 'endpoint' => ['method' => 'GET', 'path' => '/widgets/{uuid}/report']]],
        ], 'report');

        $this->assertStringContainsString("'yearReportSplash']", $content);
        $this->assertStringNotContainsString("'reportSplash']", $content);
    }

    public function test_method_name_only_uses_it_for_the_splash_handler(): void
    {
        $content = $this->generateAndReadRoutes([
            'name' => 'send',
            'methodName' => 'sendNow',
            'splash' => true,
            'operations' => ['create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/widgets/send']]],
        ], 'send');

        $this->assertStringContainsString("'sendNowSplash']", $content);
    }

    public function test_no_splash_key_generates_no_splash_route(): void
    {
        $content = $this->generateAndReadRoutes([
            'name' => 'approve',
            'operations' => ['create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/widgets/approve']]],
        ], 'approve');

        $this->assertDoesNotMatchRegularExpression("~Splash'\\]\\);~", $content);
    }
}
