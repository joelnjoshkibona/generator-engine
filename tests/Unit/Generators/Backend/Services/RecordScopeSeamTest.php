<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\Action\ActionServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\DeleteCheckServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\DeleteServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\EditServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\ViewServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Location scoping has only ever reached list/export queries. Every
 * generated view/edit/delete/deleteCheck fetches its record by uuid with no
 * scope, so a record outside the acting user's assigned locations is
 * missing from their list but still fully readable, editable and deletable
 * by uuid. This is the engine half of plan 031's fix: every generated
 * single-record fetch (and a uuid-taking action) now routes through
 * `{Model}::applyRecordScope($baseQuery)` when the consuming app's
 * BaseModel defines it, guarded by `method_exists()` so an app without
 * that method keeps today's behaviour byte-for-byte.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\ViewServiceGenerator
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\Action\ActionServiceGenerator::buildRecordLookup()
 */
class RecordScopeSeamTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-record-scope-seam-' . uniqid();
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

    private function lint(string $path): void
    {
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    public function test_view_with_soft_deletes_chains_withTrashed_after_query_and_applies_the_scope(): void
    {
        $generator = new ViewServiceGenerator('Widgets', 'Core', [
            'has_soft_deletes' => true,
            'features' => ['backend' => ['view' => ['enabled' => true]]],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/Services/WidgetsViewService.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);

        $this->assertStringContainsString('WidgetsModel::query()->withTrashed();', $content);
        $this->assertStringContainsString("WidgetsModel::applyRecordScope(\$baseQuery)", $content);

        $baseQueryPos = strpos($content, '$baseQuery = $query ??');
        $applyPos = strpos($content, 'applyRecordScope(');
        $wherePos = strpos($content, '$baseQuery->where(');

        $this->assertNotFalse($baseQueryPos);
        $this->assertNotFalse($applyPos);
        $this->assertNotFalse($wherePos);
        $this->assertTrue($baseQueryPos < $applyPos);
        $this->assertTrue($applyPos < $wherePos);

        $this->lint($path);
    }

    public function test_view_without_soft_deletes_has_no_withTrashed(): void
    {
        $generator = new ViewServiceGenerator('Widgets', 'Core', [
            'has_soft_deletes' => false,
            'features' => ['backend' => ['view' => ['enabled' => true]]],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/Services/WidgetsViewService.php';
        $content = file_get_contents($path);

        $this->assertStringContainsString('WidgetsModel::query();', $content);
        $this->assertStringNotContainsString('withTrashed', $content);
        $this->lint($path);
    }

    public function test_edit_applies_the_scope(): void
    {
        $generator = new EditServiceGenerator('Widgets', 'Core', [
            'features' => ['backend' => ['edit' => [
                'fields' => [['field' => 'name', 'rules' => 'nullable|string|max:255']],
            ]]],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/Services/WidgetsEditService.php';
        $content = file_get_contents($path);

        $this->assertStringContainsString('$baseQuery = $query ?? WidgetsModel::query();', $content);
        $this->assertStringContainsString("WidgetsModel::applyRecordScope(\$baseQuery)", $content);
        $this->lint($path);
    }

    public function test_delete_applies_the_scope(): void
    {
        $generator = new DeleteServiceGenerator('Widgets', 'Core', [
            'features' => ['backend' => ['delete' => ['enabled' => true]]],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/Services/WidgetsDeleteService.php';
        $content = file_get_contents($path);

        $this->assertStringContainsString('$baseQuery = $query ?? WidgetsModel::query();', $content);
        $this->assertStringContainsString("WidgetsModel::applyRecordScope(\$baseQuery)", $content);
        $this->lint($path);
    }

    public function test_delete_check_applies_the_scope(): void
    {
        $generator = new DeleteCheckServiceGenerator('Widgets', 'Core', [
            'table_name' => 'widgets',
            'features' => ['backend' => ['delete' => ['enabled' => true]]],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/Services/WidgetsDeleteCheckService.php';
        $content = file_get_contents($path);

        $this->assertStringContainsString('$baseQuery = $query ?? WidgetsModel::query();', $content);
        $this->assertStringContainsString("WidgetsModel::applyRecordScope(\$baseQuery)", $content);
        $this->assertStringContainsString("\$record = \$baseQuery->where('uuid'", $content);
        $this->lint($path);
    }

    public function test_a_uuid_taking_action_applies_the_scope_and_aborts_when_not_found(): void
    {
        $generator = new ActionServiceGenerator('PurchaseOrders', 'Demo', [], 'receive', [
            'name' => 'receive',
            'urlParams' => ['uuid'],
        ]);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Demo/PurchaseOrders/Services/PurchaseOrdersReceiveService.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);

        $this->assertStringContainsString('PurchaseOrdersModel::applyRecordScope(', $content);
        $this->assertStringContainsString("abort(404, 'Record not found')", $content);
        $this->lint($path);
    }

    public function test_an_action_without_urlParams_has_no_record_lookup(): void
    {
        $generator = new ActionServiceGenerator('PurchaseOrders', 'Demo', [], 'archive', [
            'name' => 'archive',
        ]);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Demo/PurchaseOrders/Services/PurchaseOrdersArchiveService.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);

        $this->assertStringNotContainsString('applyRecordScope', $content);
        $this->assertStringContainsString('Add your custom logic here', $content);
        $this->lint($path);
    }
}
