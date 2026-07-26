<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Ux;

use Blutrixx\GeneratorEngine\Generators\Ux\BaseUxGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for Finding 1: BaseUxGenerator::writeFile() used to
 * discard file_put_contents()'s return value and always report success --
 *
 *   file_put_contents($path, $content);
 *   $this->created[] = $path;
 *   return true;
 *
 * -- unlike the parallel BaseGenerator::writeFile(), which correctly does
 * `return file_put_contents(...) !== false;`. This left every Ux generator
 * (ShortcutGenerator, etc.) reporting a successful write even when the
 * underlying file_put_contents() call failed (permissions, disk full, ...),
 * silently orphaning generated UX affordances.
 *
 * Fix: writeFile() now checks file_put_contents()'s return value, returns
 * false on failure, and does NOT append the path to $this->created in that
 * case -- matching BaseGenerator::writeFile() exactly.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Ux\BaseUxGenerator::writeFile()
 */
class BaseUxGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-ux-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpRoot)) {
            // Best-effort: restore write perms in case a test left the dir locked down.
            @chmod($this->tmpRoot, 0755);
            $this->removeDirectory($this->tmpRoot);
        }
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
            if (is_dir($path)) {
                @chmod($path, 0755);
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function makeGenerator(): TestUxGenerator
    {
        return new TestUxGenerator([]);
    }

    public function test_write_file_returns_true_and_records_created_path_on_success(): void
    {
        $generator = $this->makeGenerator();
        $path = $this->tmpRoot . '/out/file.vue';

        $this->assertTrue($generator->callWriteFile($path, 'content'));
        $this->assertFileExists($path);
        $this->assertSame([$path], $generator->getCreated());
        $this->assertSame([], $generator->getSkipped());
    }

    public function test_write_file_skips_and_returns_false_when_file_exists_without_overwrite(): void
    {
        $generator = $this->makeGenerator();
        $path = $this->tmpRoot . '/out/file.vue';
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, 'existing');

        $this->assertFalse($generator->callWriteFile($path, 'new content'));
        $this->assertSame('existing', file_get_contents($path), 'Existing content must be untouched.');
        $this->assertSame([], $generator->getCreated());
        $this->assertSame([$path], $generator->getSkipped());
    }

    public function test_write_file_returns_false_and_does_not_record_created_when_write_fails(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Cannot simulate a permission-denied write while running as root.');
        }

        $generator = $this->makeGenerator();
        $dir = $this->tmpRoot . '/locked';
        mkdir($dir, 0755, true);
        $path = $dir . '/file.vue';
        chmod($dir, 0555); // read+execute only: file_put_contents() inside it must fail

        $result = $generator->callWriteFile($path, 'content');

        $this->assertFalse(
            $result,
            'writeFile() must report failure when file_put_contents() fails, not silently claim success.'
        );
        $this->assertSame(
            [],
            $generator->getCreated(),
            'A path whose write actually failed must NOT be recorded in $created -- that would report success for an operation that did nothing.'
        );
    }
}

/**
 * Minimal concrete subclass exposing the protected writeFile() method under
 * test. Named (not anonymous) to keep parity with the pattern used by the
 * sibling BaseServiceGeneratorTest's TestBaseServiceGenerator.
 */
class TestUxGenerator extends BaseUxGenerator
{
    public function callWriteFile(string $path, string $content, bool $overwrite = false): bool
    {
        return $this->writeFile($path, $content, $overwrite);
    }
}
