<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services\Action;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\Action\ActionServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for ActionServiceGenerator's writeFileOnce() switch.
 *
 * Bug: the generated service stub's entire purpose is a "Add your custom
 * logic here" TODO a developer fills in by hand (see
 * UsersForceResetPasswordService.php for the real, shipped shape this stub
 * is meant to grow into). Plain writeFile() force-overwrites on every
 * regenerate (V1/SYSTEM_SHELL/THC_V2's own ModuleGenerationService calls
 * setForce(true) on every generator it constructs except migrations), so a
 * developer's hand-written business logic was silently discarded back to the
 * empty stub the next time the module regenerated for any unrelated reason
 * (a schema tweak, a second action, etc) -- same bug class already fixed for
 * the inline-items wrapper component.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\BaseGenerator::writeFileOnce()
 */
class ActionServiceGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-actionservicegen-test-' . uniqid();
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

    private function filePath(string $moduleGroup, string $moduleName): string
    {
        return PathManager::getBackendModulePath($moduleGroup, $moduleName) . "/Services/{$moduleName}ReceiveService.php";
    }

    public function test_first_generation_writes_the_stub(): void
    {
        $generator = new ActionServiceGenerator('PurchaseOrders', 'Demo', [], 'receive', ['name' => 'receive']);

        $this->assertTrue($generator->generate());

        $path = $this->filePath('Demo', 'PurchaseOrders');
        $this->assertFileExists($path);
        $this->assertStringContainsString('Add your custom logic here', (string) file_get_contents($path));
    }

    public function test_regenerate_with_force_does_not_clobber_hand_written_logic(): void
    {
        $generator = new ActionServiceGenerator('PurchaseOrders', 'Demo', [], 'receive', ['name' => 'receive']);
        $generator->generate();

        $path = $this->filePath('Demo', 'PurchaseOrders');
        $handWritten = "<?php\n// hand-written receiving logic — must survive regeneration\nclass PurchaseOrdersReceiveService {}\n";
        file_put_contents($path, $handWritten);

        // Mirrors ModuleGenerationService::generateModule(), which calls
        // setForce(true) on every generator it constructs (except
        // migrations) before regenerating an already-built module.
        $regenerated = new ActionServiceGenerator('PurchaseOrders', 'Demo', [], 'receive', ['name' => 'receive']);
        $regenerated->setForce(true);
        $regenerated->generate();

        $this->assertSame($handWritten, file_get_contents($path), 'a forced regenerate must not overwrite hand-written action service logic');
    }
}
