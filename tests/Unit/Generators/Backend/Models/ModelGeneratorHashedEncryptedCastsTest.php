<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Models;

use Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * A generated create service does a plain `Model::create($validData)` --
 * straight mass-assignment, no hashing hook. The ONLY write-path fix is the
 * Eloquent cast itself: Laravel intercepts `hashed`/`encrypted` casts inside
 * `setAttribute()`, so once the cast exists, `Model::create()` already
 * hashes/encrypts correctly with zero other code changes anywhere.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator::generateCasts()
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::sensitiveColumnStorage()
 */
class ModelGeneratorHashedEncryptedCastsTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-model-hashed-encrypted-' . uniqid();
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

    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'connection' => '',
            'id_type'    => 'integer',
            'columns'    => [
                ['name' => 'title', 'type' => 'string'],
            ],
        ], $overrides);
    }

    private function generateAndRead(array $config, string $moduleName = 'TestSales', string $moduleGroup = 'System'): string
    {
        $generator = new ModelGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate(), 'ModelGenerator::generate() should report a successful write.');

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/{$moduleGroup}/{$moduleName}/{$moduleName}Model.php";
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function test_a_password_shaped_column_gets_a_hashed_cast(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'password', 'type' => 'string'],
            ],
        ]));

        $this->assertStringContainsString("'password' => 'hashed'", $content);

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/TestSales/TestSalesModel.php';
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    public function test_a_secret_shaped_column_gets_an_encrypted_cast(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'webhook_secret', 'type' => 'string'],
            ],
        ]));

        $this->assertStringContainsString("'webhook_secret' => 'encrypted'", $content);
    }

    public function test_an_already_hashed_by_the_app_column_gets_no_cast(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'key_hash', 'type' => 'string'],
            ],
        ]));

        // A plain-storage sensitive column is still correctly hidden (plan
        // 032) — just not cast, so the assertion is scoped to the cast
        // shape ("'key_hash' => ") rather than the bare name, which would
        // also (correctly) appear inside $hidden.
        $this->assertStringNotContainsString("'key_hash' =>", $content);
    }

    public function test_remember_token_gets_no_cast(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'remember_token', 'type' => 'string'],
            ],
        ]));

        $this->assertStringNotContainsString("'remember_token' =>", $content);
    }

    public function test_a_manual_cast_override_still_wins_over_the_sensitive_default(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'password', 'type' => 'string'],
            ],
            'backend' => ['model' => ['casts' => ['password' => 'string']]],
        ]));

        $this->assertStringContainsString("'password' => 'string'", $content);
        $this->assertStringNotContainsString("'password' => 'hashed'", $content);
    }

    public function test_a_storage_override_of_plain_suppresses_the_cast(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'webhook_secret', 'type' => 'string'],
            ],
            'sensitive_columns' => ['storage' => ['webhook_secret' => 'plain']],
        ]));

        $this->assertStringNotContainsString("'webhook_secret' =>", $content);
    }

    public function test_only_non_sensitive_columns_emits_no_casts_block_at_all(): void
    {
        $content = $this->generateAndRead($this->baseConfig());

        $this->assertStringNotContainsString('$casts', $content);
    }
}
