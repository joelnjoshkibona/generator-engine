<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * `resolveActionServiceNameRaw()`/`resolveActionBaseMethod()` are the shared base
 * RoutesGenerator, ControllerGenerator and ActionSplashServiceGenerator all resolve
 * their action-derived names from — see plans/038: before this, each guessed
 * independently and diverged whenever an action set a custom serviceName/methodName.
 */
class BaseGeneratorActionNamingTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-base-generator-naming-test-' . uniqid();
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

    private function fixture(): object
    {
        return new class('Golden', 'Core', []) extends BaseGenerator {
            public function generate(): bool { return true; }
            public function exposeServiceNameRaw(string $k, array $a): string { return $this->resolveActionServiceNameRaw($k, $a); }
            public function exposeBaseMethod(string $k, array $a): string { return $this->resolveActionBaseMethod($k, $a); }
        };
    }

    public function test_no_overrides_uses_the_studly_action_name(): void
    {
        $gen = $this->fixture();
        $action = [];

        $this->assertSame('Report', $gen->exposeServiceNameRaw('report', $action));
        $this->assertSame('Report', $gen->exposeBaseMethod('report', $action));
    }

    public function test_service_name_is_module_prefix_and_service_suffix_stripped(): void
    {
        $gen = $this->fixture();
        $action = ['serviceName' => 'GoldenYearReportService'];

        $this->assertSame('YearReport', $gen->exposeServiceNameRaw('report', $action));
        $this->assertSame('YearReport', $gen->exposeBaseMethod('report', $action));
    }

    public function test_method_name_overrides_the_base_method_but_not_the_service_name_raw(): void
    {
        $gen = $this->fixture();
        $action = ['serviceName' => 'GoldenYearReportService', 'methodName' => 'yearReport'];

        $this->assertSame('YearReport', $gen->exposeServiceNameRaw('report', $action));
        $this->assertSame('yearReport', $gen->exposeBaseMethod('report', $action));
    }

    public function test_blank_strings_behave_like_no_overrides(): void
    {
        $gen = $this->fixture();
        $action = ['serviceName' => '', 'methodName' => ''];

        $this->assertSame('Report', $gen->exposeServiceNameRaw('report', $action));
        $this->assertSame('Report', $gen->exposeBaseMethod('report', $action));
    }

    public function test_the_actions_own_name_wins_over_its_array_key(): void
    {
        $gen = $this->fixture();
        $action = ['name' => 'sendNotification'];

        $this->assertSame('SendNotification', $gen->exposeServiceNameRaw('sendNotif', $action));
        $this->assertSame('SendNotification', $gen->exposeBaseMethod('sendNotif', $action));
    }
}
