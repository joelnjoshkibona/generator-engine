<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Tests;

use Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Hand-written fixture helpers and imports in a generated {Module}TestCase.php
 * were never actually protected: writeTestCaseBase() rewrites the whole file
 * on every --force with no merge. This is the fix: hand-fixtures/hand-imports
 * regions --force copies verbatim, plus a migration that moves anything
 * outside those regions no longer matching what the module's current schema
 * generates into the matching hand-* region, with a warning naming what
 * moved. Confirmed live incident this fixes: NotificationSubscriptionsTestCase
 * .php's createNotificationSubscriptionFixture() carries a hand-corrected
 * body the generator's default literal doesn't produce; before this plan, an
 * unrelated --force silently wiped that fix.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator
 * @see \Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Controller\ControllerGeneratorHandRegionTest
 */
class PhpUnitTestGeneratorHandFixturesTest extends TestCase
{
    private string $tmpRoot;

    /** @var list<string> */
    private array $issues = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-testcase-hand-fixtures-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);

        $this->issues = [];
        PathManager::setIssueHandler(function (string $message, string $level = 'warning'): void {
            $this->issues[] = $message;
        });
    }

    protected function tearDown(): void
    {
        PathManager::setIssueHandler(null);
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

    // ─── fixtures ───────────────────────────────────────────────────────────

    private function probesConfig(array $extraColumns = []): array
    {
        return [
            'module_name' => 'Probes',
            'module_type' => 'Core',
            'table_name' => 'probes',
            'id_type' => 'bigint',
            'columns' => array_merge([
                ['name' => 'label', 'type' => 'string'],
                ['name' => 'is_active', 'type' => 'boolean'],
            ], $extraColumns),
            'features' => [
                'backend' => [
                    'list' => ['enabled' => true],
                    'create' => ['enabled' => true],
                ],
            ],
        ];
    }

    private function path(): string
    {
        return $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Probes/Tests/ProbesTestCase.php';
    }

    private function content(): string
    {
        return file_get_contents($this->path());
    }

    private function generate(array $config, bool $force = true): bool
    {
        $generator = new PhpUnitTestGenerator('Probes', 'Core', $config);
        $generator->setForce($force);

        return $generator->generate();
    }

    private function regionInner(string $content, string $region): string
    {
        $quoted = preg_quote($region, '~');
        $pattern = "~// \[generator:region:{$quoted}:start\](.*?)// \[generator:region:{$quoted}:end\]~s";

        return preg_match($pattern, $content, $m) ? trim($m[1]) : '';
    }

    private function insertBeforeRegionEnd(string $content, string $region, string $text): string
    {
        return str_replace(
            "// [generator:region:{$region}:end]",
            rtrim($text) . "\n" . "// [generator:region:{$region}:end]",
            $content,
        );
    }

    private function lintExitCode(): int
    {
        exec('php -l ' . escapeshellarg($this->path()) . ' 2>&1', $output, $exitCode);

        return $exitCode;
    }

    // ─── cases ──────────────────────────────────────────────────────────────

    public function test_1_fresh_generate_has_both_regions_present_empty_and_lints(): void
    {
        $this->assertTrue($this->generate($this->probesConfig(), false));
        $content = $this->content();

        $this->assertSame('', $this->regionInner($content, 'hand-imports'));
        $this->assertSame('', $this->regionInner($content, 'hand-fixtures'));

        $handFixturesStart = strpos($content, '    // [generator:region:hand-fixtures:start]');
        $classClose = strrpos($content, '}');
        $this->assertNotFalse($handFixturesStart);
        $this->assertTrue($handFixturesStart < $classClose);

        $this->assertSame([], $this->issues);
        $this->assertSame(0, $this->lintExitCode());
    }

    public function test_2_hand_edited_fixture_body_migrates_and_survives_repeated_force(): void
    {
        $this->assertTrue($this->generate($this->probesConfig()));
        $before = $this->content();

        // The docblock alone would make hasComment() true (already enough to force
        // migration), but the VALUE change too is what must keep re-triggering
        // "hand-fixtures defines..." on every subsequent re-force -- a comment-only
        // edit would code-signature-match the generated version and be silently
        // deduplicated instead (rule 4), same as a genuinely identical hand copy.
        $edited = str_replace(
            ["    protected function createProbeFixture(array \$overrides = []): ProbesModel\n    {", "'is_active' => true,"],
            ["    /**\n     * NOTE: hand-corrected -- see plans/041.\n     */\n    protected function createProbeFixture(array \$overrides = []): ProbesModel\n    {\n        // hand-corrected marker line", "'is_active' => false,"],
            $before
        );
        $this->assertNotSame($before, $edited, 'Expected the fixture method text to be present and replaceable.');
        file_put_contents($this->path(), $edited);

        $this->issues = [];
        $this->assertTrue($this->generate($this->probesConfig()));
        $afterFirstForce = $this->content();

        $this->assertSame(1, substr_count($afterFirstForce, 'function createProbeFixture('));
        $this->assertStringContainsString('NOTE: hand-corrected', $this->regionInner($afterFirstForce, 'hand-fixtures'));
        $this->assertStringContainsString('hand-corrected marker line', $afterFirstForce);
        $issueText = implode("\n", $this->issues);
        $this->assertStringContainsString('moved into hand-fixtures', $issueText);
        $this->assertStringContainsString('createProbeFixture()', $issueText);
        $this->assertSame(0, $this->lintExitCode());

        // Re-force twice more with no further edits -- byte-identical, and the
        // issue is now "hand-fixtures defines...", never "moved into" again.
        $this->issues = [];
        $this->assertTrue($this->generate($this->probesConfig()));
        $afterSecondForce = $this->content();
        $this->assertSame($afterFirstForce, $afterSecondForce);
        $issueText2 = implode("\n", $this->issues);
        $this->assertStringNotContainsString('moved into', $issueText2);
        $this->assertStringContainsString('hand-fixtures defines', $issueText2);
        $this->assertStringContainsString('createProbeFixture()', $issueText2);

        $this->issues = [];
        $this->assertTrue($this->generate($this->probesConfig()));
        $this->assertSame($afterSecondForce, $this->content());

        // A plain non-force call changes nothing -- writeFile()'s pre-existing
        // skip-if-exists-and-not-force behaviour reports false (nothing written),
        // same as it always has for every split test file this generator emits.
        $this->assertFalse($this->generate($this->probesConfig(), false));
        $this->assertSame($afterSecondForce, $this->content());
    }

    public function test_3_a_new_method_and_import_placed_outside_regions_migrate_and_survive(): void
    {
        $this->assertTrue($this->generate($this->probesConfig()));
        $before = $this->content();

        $withImport = str_replace(
            'use Tests\Support\ActsWithoutPermission;',
            "use Tests\Support\ActsWithoutPermission;\nuse Illuminate\Support\Carbon;",
            $before
        );
        // A new method placed OUTSIDE any region (after hand-fixtures:end, before the class's closing brace).
        $withMethod = str_replace(
            "    // [generator:region:hand-fixtures:start]\n    // [generator:region:hand-fixtures:end]\n}",
            "    // [generator:region:hand-fixtures:start]\n    // [generator:region:hand-fixtures:end]\n\n    protected function aHandHelper(): string\n    {\n        return 'hand';\n    }\n}",
            $withImport
        );
        $this->assertNotSame($withImport, $withMethod, 'Expected the empty hand-fixtures region to be present and replaceable.');

        file_put_contents($this->path(), $withMethod);

        $this->issues = [];
        $this->assertTrue($this->generate($this->probesConfig()));
        $after = $this->content();
        $issueText = implode("\n", $this->issues);

        $this->assertStringContainsString('Carbon', $this->regionInner($after, 'hand-imports'));
        $this->assertStringContainsString('aHandHelper', $this->regionInner($after, 'hand-fixtures'));
        $this->assertSame(1, substr_count($after, 'use Illuminate\Support\Carbon;'));
        $this->assertSame(1, substr_count($after, 'function aHandHelper('));
        $this->assertSame(1, substr_count($after, 'function createProbeFixture('));
        $this->assertStringContainsString('hand-imports', $issueText);
        $this->assertStringContainsString('Illuminate\Support\Carbon', $issueText);
        $this->assertStringContainsString('hand-fixtures', $issueText);
        $this->assertStringContainsString('aHandHelper()', $issueText);
        $this->assertSame(0, $this->lintExitCode());

        // Both survive a second --force verbatim.
        $this->issues = [];
        $this->assertTrue($this->generate($this->probesConfig()));
        $this->assertSame($after, $this->content());
    }

    public function test_4_identical_hand_copy_is_silent_and_half_present_markers_abort(): void
    {
        $this->assertTrue($this->generate($this->probesConfig()));
        $original = $this->content();

        preg_match('/    protected function createProbeFixture.*?\n    \}\n/s', $original, $m);
        $this->assertNotEmpty($m, 'Could not locate the generated fixture method in the fresh render.');
        $exactCopy = rtrim($m[0]);

        $withHandCopy = $this->insertBeforeRegionEnd($original, 'hand-fixtures', $exactCopy);
        file_put_contents($this->path(), $withHandCopy);

        $this->issues = [];
        $this->assertTrue($this->generate($this->probesConfig()));
        $afterForce = $this->content();

        $this->assertSame(1, substr_count($afterForce, 'function createProbeFixture('), 'Identical hand copy must not duplicate the method.');
        $this->assertSame([], $this->issues, 'A byte-identical hand copy must be silent.');

        // Half-present markers: delete only the hand-fixtures end marker line.
        $broken = str_replace("    // [generator:region:hand-fixtures:end]\n", '', $afterForce);
        $this->assertNotSame($afterForce, $broken);
        file_put_contents($this->path(), $broken);

        $this->issues = [];
        $result = $this->generate($this->probesConfig());

        $this->assertFalse($result);
        $this->assertSame($broken, $this->content());
        $this->assertStringContainsString('only one marker', implode("\n", $this->issues));
    }

    public function test_5_stale_hand_copy_shadows_a_later_schema_change_until_emptied(): void
    {
        $this->assertTrue($this->generate($this->probesConfig()));

        // Plain-edit (no comment) the fixture body -- signature mismatch alone must migrate it.
        $content = $this->content();
        $edited = preg_replace("/'is_active' => .*?,/", "'is_active' => false,", $content, 1);
        $this->assertNotNull($edited);
        $this->assertNotSame($content, $edited);
        file_put_contents($this->path(), $edited);

        $this->issues = [];
        $this->assertTrue($this->generate($this->probesConfig()));
        $issueText = implode("\n", $this->issues);
        $this->assertStringContainsString('moved into hand-fixtures', $issueText);

        // Schema change: add a new column. Fresh output would differ, but the stale hand copy still wins.
        $configWithNewColumn = $this->probesConfig([['name' => 'notes', 'type' => 'string']]);
        $this->issues = [];
        $this->assertTrue($this->generate($configWithNewColumn));
        $afterSchemaChange = $this->content();
        $issueText2 = implode("\n", $this->issues);

        $this->assertStringContainsString('hand-fixtures defines', $issueText2);
        $this->assertStringContainsString('createProbeFixture()', $issueText2);
        $this->assertStringNotContainsString("'notes' =>", $afterSchemaChange, 'The new column must not reach the fixture while the stale hand copy wins.');

        // Empty hand-fixtures by hand -- --force then picks up the fresh fixture with the new column.
        $emptied = preg_replace(
            '~    // \[generator:region:hand-fixtures:start\].*?    // \[generator:region:hand-fixtures:end\]~s',
            "    // [generator:region:hand-fixtures:start]\n    // [generator:region:hand-fixtures:end]",
            $afterSchemaChange
        );
        $this->assertNotSame($afterSchemaChange, $emptied);
        file_put_contents($this->path(), $emptied);

        $this->issues = [];
        $this->assertTrue($this->generate($configWithNewColumn));
        $final = $this->content();

        $this->assertStringContainsString("'notes' =>", $final);
        $this->assertSame([], $this->issues);
    }

    public function test_6_locate_class_body_finds_the_body_including_a_class_constant_collision(): void
    {
        $this->assertTrue($this->generate($this->probesConfig(), false));
        $fresh = $this->content();

        $generator = new PhpUnitTestGenerator('Probes', 'Core', $this->probesConfig());
        $ref = new \ReflectionMethod($generator, 'locateClassBody');
        $ref->setAccessible(true);

        // (a) fresh render.
        $freshResult = $ref->invoke($generator, $fresh);
        $this->assertIsArray($freshResult);
        $this->assertArrayHasKey('bodyStart', $freshResult);
        $this->assertArrayHasKey('bodyEnd', $freshResult);
        $freshBody = substr($fresh, $freshResult['bodyStart'], $freshResult['bodyEnd'] - $freshResult['bodyStart']);
        $this->assertStringContainsString('function setUp()', $freshBody);
        $this->assertStringContainsString('createProbeFixture', $freshBody);

        // (b) extra blank lines / different docblock text before "abstract class" (simulating a stub override).
        $withExtraBlankLines = str_replace(
            "/**\n * Shared base",
            "\n\n\n/**\n * A totally different docblock text here.\n * Shared base",
            $fresh
        );
        $this->assertNotSame($fresh, $withExtraBlankLines);
        $resultB = $ref->invoke($generator, $withExtraBlankLines);
        $this->assertIsArray($resultB);
        $bodyB = substr($withExtraBlankLines, $resultB['bodyStart'], $resultB['bodyEnd'] - $resultB['bodyStart']);
        $this->assertStringContainsString('function setUp()', $bodyB);
        $this->assertStringContainsString('createProbeFixture', $bodyB);

        // (c) a `const PROBE = \Tests\TestCase::class;` line before "abstract class" -- both instances
        // tokenize as T_CLASS, so this specifically proves the "preceded by T_DOUBLE_COLON" check.
        $withConstCollision = str_replace(
            'abstract class ProbesTestCase',
            "const PROBE = \\Tests\\TestCase::class;\n\nabstract class ProbesTestCase",
            $fresh
        );
        $this->assertNotSame($fresh, $withConstCollision);
        $resultC = $ref->invoke($generator, $withConstCollision);
        $this->assertIsArray($resultC);
        $bodyC = substr($withConstCollision, $resultC['bodyStart'], $resultC['bodyEnd'] - $resultC['bodyStart']);
        $this->assertStringContainsString('function setUp()', $bodyC);
        $this->assertStringNotContainsString('const PROBE', $bodyC);
    }
}
