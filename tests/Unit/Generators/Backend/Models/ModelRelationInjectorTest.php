<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Models;

use Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelRelationInjector;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for ModelRelationInjector — the one generator in this
 * package that deliberately mutates an ALREADY-GENERATED sibling module's
 * own Model file, to add the reverse MorphMany relation a morph target
 * (e.g. Vendors, for Payments.payable) never gets automatically (see
 * docs/examples/morphs.md's "What you still do NOT get" section).
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelRelationInjector
 */
class ModelRelationInjectorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-relation-injector-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::setModuleRegistry([]);
        PathManager::resetModuleSubGroup();
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

    private function generateTargetModel(): string
    {
        $generator = new ModelGenerator('Vendors', 'System', [
            'connection' => '',
            'id_type'    => 'integer',
            'columns'    => [
                ['name' => 'name', 'type' => 'string'],
            ],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        return $this->tmpRoot . '/BACKEND/app/Project/Modules/System/Vendors/VendorsModel.php';
    }

    public function test_injects_a_real_morph_many_relation_onto_the_target_model(): void
    {
        $modelPath = $this->generateTargetModel();

        $injector = new ModelRelationInjector('Vendors', 'System');
        $inserted = $injector->injectMorphMany(
            'payments',
            'App\\Project\\Modules\\System\\AccountsPayments\\Payments\\PaymentsModel',
            'payable'
        );

        $this->assertTrue($inserted);

        $content = file_get_contents($modelPath);
        $this->assertStringContainsString('public function payments(): \Illuminate\Database\Eloquent\Relations\MorphMany', $content);
        $this->assertStringContainsString(
            "return \$this->morphMany(\App\Project\Modules\System\AccountsPayments\Payments\PaymentsModel::class, 'payable');",
            $content
        );

        // File must still be valid, parseable PHP after splicing.
        $this->assertStringContainsString('class VendorsModel extends BaseModel', $content);
        $lintResult = shell_exec('php -l ' . escapeshellarg($modelPath) . ' 2>&1');
        $this->assertStringContainsString('No syntax errors detected', (string) $lintResult);
    }

    public function test_second_run_is_idempotent_and_does_not_duplicate_the_method(): void
    {
        $modelPath = $this->generateTargetModel();

        $injector = new ModelRelationInjector('Vendors', 'System');
        $first = $injector->injectMorphMany('payments', 'App\\Project\\Modules\\System\\AccountsPayments\\Payments\\PaymentsModel', 'payable');
        $second = $injector->injectMorphMany('payments', 'App\\Project\\Modules\\System\\AccountsPayments\\Payments\\PaymentsModel', 'payable');

        $this->assertTrue($first);
        $this->assertFalse($second, 'second call must no-op, not duplicate');

        $content = file_get_contents($modelPath);
        $this->assertEquals(1, substr_count($content, 'public function payments('));
    }

    public function test_two_different_targets_can_both_be_injected_onto_the_same_model(): void
    {
        $modelPath = $this->generateTargetModel();

        $injector = new ModelRelationInjector('Vendors', 'System');
        $injector->injectMorphMany('payments', 'App\\Project\\Modules\\System\\AccountsPayments\\Payments\\PaymentsModel', 'payable');
        $injector->injectMorphMany('notes', 'App\\Project\\Modules\\System\\Notes\\Notes\\NotesModel', 'noteable');

        $content = file_get_contents($modelPath);
        $this->assertStringContainsString('public function payments():', $content);
        $this->assertStringContainsString('public function notes():', $content);

        $lintResult = shell_exec('php -l ' . escapeshellarg($modelPath) . ' 2>&1');
        $this->assertStringContainsString('No syntax errors detected', (string) $lintResult);
    }

    public function test_throws_when_the_target_model_does_not_exist_yet(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must already be generated/');

        $injector = new ModelRelationInjector('Vendors', 'System');
        $injector->injectMorphMany('payments', 'App\\Project\\Modules\\System\\AccountsPayments\\Payments\\PaymentsModel', 'payable');
    }
}
