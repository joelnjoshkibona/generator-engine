<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\EditServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for EditServiceGenerator's file_columns support.
 * Mirrors CreateServiceGeneratorTest, but Edit gets the
 * MobileReleasesEditService-style optional-reupload behaviour: only convert
 * + overwrite when a new file was actually sent; otherwise unset the key so
 * $model->update() leaves the existing media_id column untouched.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\EditServiceGenerator
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\BaseServiceGenerator::generateFileColumnUploads()
 */
class EditServiceGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-edit-service-test-' . uniqid();
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
                    'edit' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'nullable|string|max:255'],
                        ],
                    ],
                ],
            ],
        ], $overrides);
    }

    private function generateAndRead(array $config, string $moduleName = 'ItemImages', string $moduleGroup = 'Custom'): string
    {
        $generator = new EditServiceGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate(), 'EditServiceGenerator::generate() should report a successful write.');

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/{$moduleGroup}/{$moduleName}/Services/{$moduleName}EditService.php";
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function test_file_column_generates_media_service_upload_with_optional_reupload(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'file_columns' => ['image_media_id'],
            'features' => ['backend' => ['edit' => ['fields' => [
                ['field' => 'image_media_id', 'rules' => 'required|integer'],
            ]]]],
        ]));

        // Validation rule overridden to nullable file (optional re-upload).
        $this->assertStringContainsString("'image_media_id' => [\"nullable\", \"file\"]", $content);

        // Upload-then-store-id logic, with the "keep existing value" else-branch.
        $this->assertStringContainsString("instanceof \\Illuminate\\Http\\UploadedFile", $content);
        $this->assertStringContainsString('\App\Project\Modules\Core\Media\Services\MediaService::createFile($validData[\'image_media_id\'], Auth::id())', $content);
        $this->assertStringContainsString("unset(\$validData['image_media_id'])", $content);

        // As in CreateServiceGeneratorTest: the MediaService call must live
        // INSIDE beforeUpdate()'s method body (between its own opening and
        // the next method, afterUpdate()) -- process() already calls
        // "self::beforeUpdate(...)" before "$model->update($data)" as part
        // of the stub's own pre-existing contract.
        $beforeUpdatePos = strpos($content, 'function beforeUpdate');
        $afterUpdatePos = strpos($content, 'function afterUpdate');
        $mediaCallPos = strpos($content, 'MediaService::createFile');
        $selfBeforeUpdateCallPos = strpos($content, 'self::beforeUpdate(');
        $modelUpdatePos = strpos($content, '$model->update');

        $this->assertNotFalse($beforeUpdatePos);
        $this->assertNotFalse($afterUpdatePos);
        $this->assertNotFalse($mediaCallPos);
        $this->assertGreaterThan($beforeUpdatePos, $mediaCallPos, 'The MediaService call must be inside beforeUpdate().');
        $this->assertLessThan($afterUpdatePos, $mediaCallPos, 'The MediaService call must be inside beforeUpdate(), not afterUpdate().');
        $this->assertLessThan($modelUpdatePos, $selfBeforeUpdateCallPos, 'process() must call beforeUpdate() before updating the model.');
    }

    public function test_php_lints_clean_with_a_file_column(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'file_columns' => ['image_media_id'],
            'features' => ['backend' => ['edit' => ['fields' => [
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
        $content = $this->generateAndRead($this->baseConfig());

        $this->assertStringNotContainsString('MediaService', $content);
        $this->assertStringNotContainsString('UploadedFile', $content);
        $this->assertStringContainsString("'name' => [\"nullable\", \"string\", \"max:255\"]", $content);
    }
}
