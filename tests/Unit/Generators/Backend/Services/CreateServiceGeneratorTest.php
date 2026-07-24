<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\CreateServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for CreateServiceGenerator's file_columns support: a
 * column marked via IntrospectionToConfig's file_columns meta (threaded to
 * config['file_columns'] at the top level) must get upload-then-store-id
 * handling injected into beforeCreate() -- extract the raw UploadedFile,
 * call MediaService::createFile(), store the returned int id back onto the
 * same column -- while every module with no file_columns generates exactly
 * as before (regression guard).
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\CreateServiceGenerator
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\BaseServiceGenerator::generateFileColumnUploads()
 */
class CreateServiceGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-create-service-test-' . uniqid();
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

    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'features' => [
                'backend' => [
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                        ],
                    ],
                ],
            ],
        ], $overrides);
    }

    private function generateAndRead(array $config, string $moduleName = 'ItemImages', string $moduleGroup = 'Custom'): string
    {
        $generator = new CreateServiceGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate(), 'CreateServiceGenerator::generate() should report a successful write.');

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/{$moduleGroup}/{$moduleName}/Services/{$moduleName}CreateService.php";
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function test_file_column_generates_media_service_upload_in_before_create(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'file_columns' => ['image_media_id'],
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'image_media_id', 'rules' => 'required|integer'],
            ]]]],
        ]));

        // Validation rule overridden to 'file'.
        $this->assertStringContainsString("'image_media_id' => [\"required\", \"file\"]", $content);

        // Upload-then-store-id logic lands inside beforeCreate(), ahead of
        // the model create.
        $this->assertStringContainsString("instanceof \\Illuminate\\Http\\UploadedFile", $content);
        $this->assertStringContainsString('\App\Project\Modules\Core\Media\Services\MediaService::createFile($validData[\'image_media_id\'], Auth::id())', $content);

        // The MediaService call must live INSIDE the beforeCreate() method body
        // (between its own opening and the next method, afterCreate()) --
        // process() already calls "self::beforeCreate($validData)" before
        // "{ModuleName}Model::create($validData)" (that ordering is the
        // stub's own pre-existing contract, unrelated to this feature); what
        // this feature must get right is putting the upload logic inside
        // that already-first-run method, not appended somewhere else.
        $beforeCreatePos = strpos($content, 'function beforeCreate');
        $afterCreatePos = strpos($content, 'function afterCreate');
        $mediaCallPos = strpos($content, 'MediaService::createFile');
        $selfBeforeCreateCallPos = strpos($content, 'self::beforeCreate($validData)');
        $modelCreatePos = strpos($content, 'ItemImagesModel::create');

        $this->assertNotFalse($beforeCreatePos);
        $this->assertNotFalse($afterCreatePos);
        $this->assertNotFalse($mediaCallPos);
        $this->assertGreaterThan($beforeCreatePos, $mediaCallPos, 'The MediaService call must be inside beforeCreate().');
        $this->assertLessThan($afterCreatePos, $mediaCallPos, 'The MediaService call must be inside beforeCreate(), not afterCreate().');
        $this->assertLessThan($modelCreatePos, $selfBeforeCreateCallPos, 'process() must call beforeCreate() before creating the model.');
    }

    public function test_php_lints_clean_with_a_file_column(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'file_columns' => ['image_media_id'],
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'image_media_id', 'rules' => 'required|integer'],
            ]]]],
        ]));

        $tmpFile = $this->tmpRoot . '/lint_check.php';
        file_put_contents($tmpFile, $content);

        exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, 'Generated file must be syntactically valid PHP: ' . implode("\n", $output));
    }

    public function test_no_file_columns_regression_guard(): void
    {
        // The common case: no 'file_columns' key at all. Must generate
        // exactly as it did before this feature existed -- no MediaService
        // reference, no UploadedFile check, normal validation rules.
        $content = $this->generateAndRead($this->baseConfig());

        $this->assertStringNotContainsString('MediaService', $content);
        $this->assertStringNotContainsString('UploadedFile', $content);
        $this->assertStringContainsString("'name' => [\"required\", \"string\", \"max:255\"]", $content);
    }
}
