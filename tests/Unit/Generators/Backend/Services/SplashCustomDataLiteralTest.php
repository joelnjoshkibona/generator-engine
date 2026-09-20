<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\CreateSplashServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * `type: custom` splash data is emitted into the generated service as PHP source, so a value that is not a
 * plain word must be escaped: `Washer 'M8'` used to become `'Washer 'M8''` -- a parse error, and with it a
 * 500 on every create/edit form mount of the module.
 */
class SplashCustomDataLiteralTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-splash-literal-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::setModuleRegistry([]);
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

    private function generated(array $data): string
    {
        $generator = new CreateSplashServiceGenerator('Widgets', 'Core', [
            'constants' => ['KIND' => 1],
            'features' => ['backend' => ['createSplash' => ['splashData' => [
                ['key' => 'catalog', 'type' => 'custom', 'paginate' => false, 'data' => $data],
            ]]]],
        ]);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        return (string) file_get_contents($this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/Services/WidgetsCreateSplashService.php');
    }

    private function assertParsesAndReturns(string $source, array $expected): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'splash') . '.php';
        file_put_contents($tmp, $source);
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, "generated service is not valid PHP:\n" . implode("\n", $out));
        unlink($tmp);

        // Evaluate just the literal: it is the text between `'catalog' => ` and the end of that array item.
        $this->assertSame(1, preg_match("/'catalog' => (\\[.*\\])\\s*\\n/s", $source, $m), 'catalog literal not found');
        $this->assertSame($expected, eval('return ' . $m[1] . ';'));
    }

    public function test_a_quote_or_backslash_in_the_data_cannot_break_the_literal(): void
    {
        $data = [
            ['id' => 3, 'name' => "Washer 'M8'", 'note' => 'C:\\temp\\x', 'dq' => 'say "hi"'],
        ];

        $this->assertParsesAndReturns($this->generated($data), $data);
    }

    public function test_scalars_nulls_and_nested_values_become_real_php(): void
    {
        $data = [
            ['id' => 1, 'price' => 2.5, 'active' => true, 'gone' => null, 'tags' => ['a', 'b'], 'meta' => ['k' => "it's"]],
        ];

        $this->assertParsesAndReturns($this->generated($data), $data);
    }

    public function test_a_plain_catalog_is_unchanged(): void
    {
        $this->assertStringContainsString(
            "'catalog' => [['id' => 1, 'name' => 'Bolt']]",
            $this->generated([['id' => 1, 'name' => 'Bolt']])
        );
    }
}
