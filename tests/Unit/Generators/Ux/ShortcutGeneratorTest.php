<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Ux;

use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Generators\Ux\ShortcutGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression coverage for Finding 2: ShortcutGenerator::patchDetailsLayout()
 * and patchMobileDetailsLayout() each fall back to guarded str_replace()
 * patches (import line + component tag), gated on str_contains() checks for
 * their own anchors. When NEITHER anchor matched, $content came out
 * byte-identical to the original file, but the code still unconditionally
 * called file_put_contents() and pushed
 * "{$layoutPath} (shortcuts patched)" onto $this->created -- reporting a
 * successful patch for a file that was never actually touched, silently
 * orphaning the shortcuts component (it's generated but never wired into
 * the details layout).
 *
 * Fix: when no anchor matches, the layout is left untouched and the path is
 * recorded in $this->skipped (not $this->created) with a message naming
 * the failure, mirroring BaseUxGenerator::writeFile()'s existing
 * created-vs-skipped convention for "this generator made no changes" --
 * a warning-shaped outcome recorded for the caller to inspect, not a hard
 * exception, since a hand-edited layout file lacking either anchor is an
 * expected, recoverable situation (the rest of the module still generates
 * fine) rather than a configuration error the run should abort over.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Ux\ShortcutGenerator::patchDetailsLayout()
 * @see \Blutrixx\GeneratorEngine\Generators\Ux\ShortcutGenerator::patchMobileDetailsLayout()
 */
class ShortcutGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-shortcut-test-' . uniqid();
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

    private function invokePrivate(ShortcutGenerator $generator, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($generator, $method);
        $ref->setAccessible(true);
        return $ref->invoke($generator, ...$args);
    }

    // ─── Frontend: patchDetailsLayout() ─────────────────────────────────────

    public function test_patch_details_layout_is_reported_skipped_not_created_when_no_anchor_matches(): void
    {
        $generator = new ShortcutGenerator([]);
        $moduleName = 'TestModule';
        $group = 'Core';

        $layoutPath = PathManager::getFrontendModulePath($group, $moduleName) . "/{$moduleName}DetailsLayout.vue";
        mkdir(dirname($layoutPath), 0755, true);
        $original = "<template>\n  <div>no known anchors here</div>\n</template>\n";
        file_put_contents($layoutPath, $original);

        $this->invokePrivate($generator, 'patchDetailsLayout', [$moduleName, $group]);

        $this->assertSame($original, file_get_contents($layoutPath), 'File content must be untouched when no anchor matched.');
        $this->assertSame([], $generator->getCreated(), 'A no-op patch must not be recorded as created/patched.');
        $this->assertCount(1, $generator->getSkipped());
        $this->assertStringContainsString($layoutPath, $generator->getSkipped()[0]);
    }

    public function test_patch_details_layout_reports_created_when_anchors_match(): void
    {
        $generator = new ShortcutGenerator([]);
        $moduleName = 'TestModule';
        $group = 'Core';

        $layoutPath = PathManager::getFrontendModulePath($group, $moduleName) . "/{$moduleName}DetailsLayout.vue";
        mkdir(dirname($layoutPath), 0755, true);
        $original = "<script>\nimport MainLayout from '@/layouts/MainLayout.vue'\n</script>\n<template>\n  <!-- Main Content Area -->\n</template>\n";
        file_put_contents($layoutPath, $original);

        $this->invokePrivate($generator, 'patchDetailsLayout', [$moduleName, $group]);

        $patched = file_get_contents($layoutPath);
        $this->assertStringContainsString("{$moduleName}Shortcuts", $patched);
        $this->assertNotSame($original, $patched);
        $this->assertSame([], $generator->getSkipped());
        $this->assertCount(1, $generator->getCreated());
        $this->assertStringContainsString($layoutPath, $generator->getCreated()[0]);
    }

    // ─── Mobile: patchMobileDetailsLayout() ─────────────────────────────────

    public function test_patch_mobile_details_layout_is_reported_skipped_not_created_when_no_anchor_matches(): void
    {
        $generator = new ShortcutGenerator([]);
        $moduleName = 'TestModule';
        $group = 'Core';

        $layoutPath = PathManager::getMobileAppModulePath($group, $moduleName) . "/{$moduleName}DetailsLayout.vue";
        mkdir(dirname($layoutPath), 0755, true);
        $original = "<template>\n  <div>no known anchors here</div>\n</template>\n";
        file_put_contents($layoutPath, $original);

        $this->invokePrivate($generator, 'patchMobileDetailsLayout', [$moduleName, $group]);

        $this->assertSame($original, file_get_contents($layoutPath), 'File content must be untouched when no anchor matched.');
        $this->assertSame([], $generator->getCreated(), 'A no-op patch must not be recorded as created/patched.');
        $this->assertCount(1, $generator->getSkipped());
        $this->assertStringContainsString($layoutPath, $generator->getSkipped()[0]);
    }
}
