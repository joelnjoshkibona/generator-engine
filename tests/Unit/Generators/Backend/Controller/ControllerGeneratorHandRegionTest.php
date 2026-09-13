<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Controller;

use Blutrixx\GeneratorEngine\Generators\Backend\Controller\ControllerGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Hand-written controller methods and `use` lines inside custom-methods/
 * custom-imports were never actually protected: ControllerGenerator::
 * generate() rebuilds both regions purely from module.json on every
 * --force. This is the fix: hand-methods/hand-imports regions --force
 * copies verbatim, plus a migration that moves anything in custom-methods/
 * custom-imports no longer matching what module.json currently generates
 * into the hand-* region instead of discarding it, with a warning naming
 * what moved.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Controller\ControllerGenerator
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGeneratorHandRegionTest
 */
class ControllerGeneratorHandRegionTest extends TestCase
{
    private string $tmpRoot;

    /** @var list<string> */
    private array $issues = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-controller-hand-region-' . uniqid();
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

    // ─── fixtures ───────────────────────────────────────────────────────────

    private function baseConfig(array $actions = []): array
    {
        return [
            'module_name' => 'TestWidget',
            'module_type' => 'Core',
            'table_name'  => 'test_widgets',
            'id_type'     => 'bigint',
            'columns'     => [],
            'features'    => ['backend' => ['list' => [], 'create' => [], 'view' => []]],
            'delegations' => [],
            'actions'     => $actions,
        ];
    }

    private function sendAction(array $overrides = []): array
    {
        return array_merge([
            'name'       => 'Send',
            'methodName' => 'send',
            'operations' => [
                'create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/test-widgets/send']],
            ],
        ], $overrides);
    }

    private function issueAction(array $overrides = []): array
    {
        return array_merge([
            'name'       => 'Issue',
            'urlParams'  => ['uuid'],
            'operations' => [
                'view' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/test-widgets/{uuid}/issue']],
            ],
        ], $overrides);
    }

    private function path(): string
    {
        return $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/TestWidget/TestWidgetController.php';
    }

    private function content(): string
    {
        return file_get_contents($this->path());
    }

    private function generate(array $config, bool $force = true): bool
    {
        $generator = new ControllerGenerator('TestWidget', 'Core', $config);
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

    public function test_1_fresh_file_has_empty_hand_imports_and_hand_methods_and_lints(): void
    {
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction(), 'Issue' => $this->issueAction()])));
        $content = $this->content();

        $customImportsEnd = strpos($content, '// [generator:region:custom-imports:end]');
        $handImportsStart = strpos($content, '// [generator:region:hand-imports:start]');
        $this->assertNotFalse($customImportsEnd);
        $this->assertNotFalse($handImportsStart);
        $this->assertTrue($customImportsEnd < $handImportsStart, 'hand-imports must come right after custom-imports');
        $this->assertSame('', $this->regionInner($content, 'hand-imports'));

        $customMethodsEnd = strpos($content, '    // [generator:region:custom-methods:end]');
        $handMethodsStart = strpos($content, '    // [generator:region:hand-methods:start]');
        $classClose = strrpos($content, '}');
        $this->assertNotFalse($customMethodsEnd);
        $this->assertNotFalse($handMethodsStart);
        $this->assertTrue($customMethodsEnd < $handMethodsStart, 'hand-methods must come right after custom-methods');
        $this->assertTrue($handMethodsStart < $classClose);
        $this->assertSame('', $this->regionInner($content, 'hand-methods'));

        $this->assertSame([], $this->issues);
        $this->assertSame(0, $this->lintExitCode());
    }

    public function test_2_hand_methods_text_survives_force_verbatim(): void
    {
        $this->assertTrue($this->generate($this->baseConfig()));
        $before = $this->content();

        $handMethod = "/** {version} */\n    public function regionPing(Request \$request): \\Illuminate\\Http\\JsonResponse\n    {\n        return response()->json(['pong' => '}'], 200);\n    }";
        file_put_contents($this->path(), $this->insertBeforeRegionEnd($before, 'hand-methods', $handMethod));

        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig()));
        $after = $this->content();

        $this->assertSame(1, substr_count($after, 'function regionPing('));
        $this->assertSame($handMethod, $this->regionInner($after, 'hand-methods'));
        $this->assertSame(0, $this->lintExitCode());
    }

    public function test_3_a_method_placed_in_custom_methods_is_migrated_with_a_warning(): void
    {
        $this->assertTrue($this->generate($this->baseConfig()));
        $before = $this->content();

        $drifted = "public function regionPing(Request \$request): \\Illuminate\\Http\\JsonResponse\n    {\n        return response()->json(['pong' => true], 200);\n    }";
        file_put_contents($this->path(), $this->insertBeforeRegionEnd($before, 'custom-methods', $drifted));

        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig()));
        $after = $this->content();

        $this->assertStringNotContainsString('regionPing', $this->regionInner($after, 'custom-methods'));
        $this->assertStringContainsString('regionPing', $this->regionInner($after, 'hand-methods'));

        $issueText = implode("\n", $this->issues);
        $this->assertStringContainsString('moved into hand-methods', $issueText);
        $this->assertStringContainsString('regionPing()', $issueText);
    }

    public function test_4_repeated_force_runs_are_idempotent(): void
    {
        $config = $this->baseConfig(['Send' => $this->sendAction()]);

        $this->assertTrue($this->generate($config));

        $this->issues = [];
        $this->assertTrue($this->generate($config));
        $run2 = $this->content();
        $this->assertSame([], $this->issues);

        $this->issues = [];
        $this->assertTrue($this->generate($config));
        $run3 = $this->content();
        $this->assertSame([], $this->issues);

        $this->assertSame(1, substr_count($run3, 'function createSend('));
        $this->assertSame($run2, $run3);
    }

    public function test_5_removed_actions_move_methods_to_hand_methods_and_imports_to_hand_imports(): void
    {
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction(), 'Issue' => $this->issueAction()])));

        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig([])));
        $content = $this->content();

        $handMethods = $this->regionInner($content, 'hand-methods');
        $this->assertStringContainsString('function createSend(', $handMethods);
        $this->assertStringContainsString('function viewIssue(', $handMethods);

        $handImports = $this->regionInner($content, 'hand-imports');
        $this->assertStringContainsString('TestWidgetSendService', $handImports);
        $this->assertStringContainsString('TestWidgetIssueService', $handImports);

        $issueText = implode("\n", $this->issues);
        $this->assertStringContainsString('moved into hand-methods', $issueText);
    }

    public function test_6_a_drifted_body_is_kept_in_hand_methods_with_a_warning(): void
    {
        $this->assertTrue($this->generate($this->baseConfig(['Issue' => $this->issueAction()])));
        $before = $this->content();
        $edited = str_replace(
            'TestWidgetIssueService::execute($request->all(), $uuid)',
            'TestWidgetIssueService::issueFromConsole($request->all(), $uuid)',
            $before,
        );
        $this->assertNotSame($before, $edited, 'the fixture must actually contain the expected call to edit');
        file_put_contents($this->path(), $edited);

        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig(['Issue' => $this->issueAction()])));
        $after = $this->content();

        $this->assertSame(1, substr_count($after, 'function viewIssue('));
        $this->assertStringContainsString('issueFromConsole', $this->regionInner($after, 'hand-methods'));

        $issueText = implode("\n", $this->issues);
        $this->assertStringContainsString('hand-methods defines viewIssue()', $issueText);

        $this->assertSame(0, $this->lintExitCode());
    }

    public function test_7_hand_methods_defines_a_standard_method_name_and_it_lines_up_exactly_once(): void
    {
        $this->assertTrue($this->generate($this->baseConfig()));
        $before = $this->content();

        $handMethod = "public function createTestWidget(Request \$request): \\Illuminate\\Http\\JsonResponse\n    {\n        return response()->json(['ok' => true], 200);\n    }";
        file_put_contents($this->path(), $this->insertBeforeRegionEnd($before, 'hand-methods', $handMethod));

        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig()));
        $after = $this->content();

        $this->assertSame(1, substr_count($after, 'function createTestWidget('));
    }

    public function test_8_an_import_placed_in_custom_imports_moves_to_hand_imports(): void
    {
        $this->assertTrue($this->generate($this->baseConfig()));
        $before = $this->content();

        $drifted = 'use Illuminate\Support\Carbon;';
        file_put_contents($this->path(), $this->insertBeforeRegionEnd($before, 'custom-imports', $drifted));

        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig()));
        $after = $this->content();

        $this->assertStringNotContainsString('Carbon', $this->regionInner($after, 'custom-imports'));
        $this->assertStringContainsString('Carbon', $this->regionInner($after, 'hand-imports'));
        $this->assertSame(1, substr_count($after, 'use Illuminate\Support\Carbon;'));
    }

    public function test_9_addActionMethods_after_a_shadowed_edit_does_not_add_a_second_method(): void
    {
        $this->assertTrue($this->generate($this->baseConfig(['Issue' => $this->issueAction()])));
        $before = $this->content();
        file_put_contents($this->path(), str_replace(
            'TestWidgetIssueService::execute($request->all(), $uuid)',
            'TestWidgetIssueService::issueFromConsole($request->all(), $uuid)',
            $before,
        ));
        $this->assertTrue($this->generate($this->baseConfig(['Issue' => $this->issueAction()])));

        $generator = new ControllerGenerator('TestWidget', 'Core', $this->baseConfig(['Issue' => $this->issueAction()]));
        $generator->addActionMethods('Issue', $this->issueAction());

        $content = $this->content();
        $this->assertSame(1, substr_count($content, 'function viewIssue('));
        $this->assertSame(0, $this->lintExitCode());
    }

    public function test_10_a_config_edit_is_shadowed_then_applies_once_hand_methods_is_emptied(): void
    {
        $this->assertTrue($this->generate($this->baseConfig(['Issue' => $this->issueAction()])));

        $movedIssue = $this->issueAction(['urlParams' => []]);
        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig(['Issue' => $movedIssue])));
        $after = $this->content();

        $handMethods = $this->regionInner($after, 'hand-methods');
        $this->assertStringContainsString('function viewIssue(Request $request, string $uuid)', $handMethods);
        $this->assertSame(1, substr_count($after, 'function viewIssue('));

        $issueText = implode("\n", $this->issues);
        $this->assertStringContainsString('moved into hand-methods', $issueText);
        $this->assertStringContainsString('hand-methods defines viewIssue()', $issueText);

        // Empty hand-methods; the config edit applies cleanly on the next --force.
        $emptied = preg_replace(
            '~    // \[generator:region:hand-methods:start\].*?    // \[generator:region:hand-methods:end\]~s',
            "    // [generator:region:hand-methods:start]\n    // [generator:region:hand-methods:end]",
            $after,
        );
        file_put_contents($this->path(), $emptied);

        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig(['Issue' => $movedIssue])));
        $final = $this->content();

        $this->assertSame(1, substr_count($final, 'function viewIssue('));
        $this->assertStringContainsString('function viewIssue(Request $request)', $this->regionInner($final, 'custom-methods'));
        $this->assertSame([], $this->issues);
        $this->assertSame(0, $this->lintExitCode());
    }

    public function test_11_an_identical_hand_copy_of_a_generated_method_is_silently_deduplicated(): void
    {
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction()])));
        $before = $this->content();
        $sendMethod = $this->regionInner($before, 'custom-methods');

        file_put_contents($this->path(), $this->insertBeforeRegionEnd($before, 'hand-methods', $sendMethod));

        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction()])));
        $after = $this->content();

        $this->assertSame(1, substr_count($after, 'function createSend('));
        $this->assertSame([], $this->issues);
    }

    public function test_12_half_present_hand_methods_marker_aborts_without_writing(): void
    {
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction()])));
        $before = $this->content();

        $broken = str_replace('    // [generator:region:hand-methods:start]', '', $before);
        $this->assertNotSame($before, $broken);
        file_put_contents($this->path(), $broken);

        $this->issues = [];
        $this->assertFalse($this->generate($this->baseConfig(['Send' => $this->sendAction()])));

        $this->assertSame($broken, $this->content());
        $this->assertStringContainsString('only one marker', implode("\n", $this->issues));
    }
}
