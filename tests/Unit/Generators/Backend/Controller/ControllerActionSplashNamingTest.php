<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Controller;

use Blutrixx\GeneratorEngine\Generators\Backend\Controller\ControllerGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * The splash controller method's name (and the splash service import) must
 * come from the same base RoutesGenerator's splash route now uses — see
 * plans/038.
 */
class ControllerActionSplashNamingTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-controller-splash-naming-test-' . uniqid();
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

    private function generateAndReadController(array $action, string $actionKey): string
    {
        $config = [
            'module_name' => 'Widgets',
            'module_type' => 'Core',
            'table_name' => 'widgets',
            'id_type' => 'bigint',
            'columns' => [],
            'features' => [],
            'actions' => [$actionKey => $action],
        ];

        $generator = new ControllerGenerator('Widgets', 'Core', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/WidgetsController.php';
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function test_default_splash_with_no_overrides_uses_the_action_name(): void
    {
        $content = $this->generateAndReadController([
            'name' => 'ping',
            'splash' => true,
            'operations' => ['create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/widgets/ping']]],
        ], 'ping');

        $this->assertStringContainsString('public function pingSplash(Request $request, string $uuid)', $content);
    }

    public function test_service_name_only_uses_the_stripped_service_name(): void
    {
        $content = $this->generateAndReadController([
            'name' => 'report',
            'serviceName' => 'WidgetsYearReportService',
            'splash' => true,
            'urlParams' => ['uuid'],
            'operations' => ['list' => ['enabled' => true, 'endpoint' => ['method' => 'GET', 'path' => '/widgets/{uuid}/report']]],
        ], 'report');

        $this->assertStringContainsString('public function yearReportSplash(', $content);
        $this->assertStringContainsString('WidgetsYearReportSplashService::execute(', $content);
    }

    public function test_service_name_and_method_name_the_repro_case(): void
    {
        $content = $this->generateAndReadController([
            'name' => 'report',
            'serviceName' => 'WidgetsYearReportService',
            'methodName' => 'yearReport',
            'splash' => true,
            'urlParams' => ['uuid'],
            'operations' => ['list' => ['enabled' => true, 'endpoint' => ['method' => 'GET', 'path' => '/widgets/{uuid}/report']]],
        ], 'report');

        $this->assertStringContainsString('public function yearReportSplash(', $content);
        $this->assertStringNotContainsString('reportSplash(', $content);
        $this->assertStringContainsString('WidgetsYearReportSplashService::execute(', $content);
    }

    public function test_method_name_only_before_this_fix_would_have_emitted_send_splash(): void
    {
        $content = $this->generateAndReadController([
            'name' => 'send',
            'methodName' => 'sendNow',
            'splash' => true,
            'operations' => ['create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/widgets/send']]],
        ], 'send');

        $this->assertStringContainsString('public function sendNowSplash(', $content);
    }

    public function test_generate_action_import_uses_the_stripped_service_name_not_the_method_name(): void
    {
        $config = [
            'module_name' => 'Widgets',
            'module_type' => 'Core',
            'table_name' => 'widgets',
            'id_type' => 'bigint',
            'columns' => [],
            'features' => [],
            'actions' => [
                'report' => [
                    'name' => 'report',
                    'serviceName' => 'WidgetsYearReportService',
                    'methodName' => 'yearReport',
                    'splash' => true,
                    'urlParams' => ['uuid'],
                    'operations' => ['list' => ['enabled' => true, 'endpoint' => ['method' => 'GET', 'path' => '/widgets/{uuid}/report']]],
                ],
            ],
        ];

        $generator = new ControllerGenerator('Widgets', 'Core', $config);
        $ref = new \ReflectionMethod($generator, 'generateActionImport');
        $ref->setAccessible(true);
        $result = $ref->invoke($generator, 'report', $config['actions']['report']);

        $this->assertSame(
            "use App\Project\Modules\Core\Widgets\Services\WidgetsYearReportService;\nuse App\Project\Modules\Core\Widgets\Services\WidgetsYearReportSplashService;",
            $result
        );
    }
}
