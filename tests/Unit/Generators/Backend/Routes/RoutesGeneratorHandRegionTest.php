<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Routes;

use Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Hand-written routes inside the custom-routes region were never actually
 * protected: RoutesGenerator::generate() rebuilds that region purely from
 * module.json on every --force, silently discarding anything a developer
 * added by hand believing it was safe (NJIWA's Messages console, its global
 * Logs page and its API-key issue path all lived there). This is the fix:
 * a sibling hand-routes region that --force copies verbatim, plus a
 * migration that moves anything in custom-routes no longer matching what
 * module.json currently generates into hand-routes instead of discarding
 * it, with a warning naming what moved.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator
 * @see \Blutrixx\GeneratorEngine\Generators\PatchesRegions
 */
class RoutesGeneratorHandRegionTest extends TestCase
{
    private string $tmpRoot;

    /** @var list<string> */
    private array $issues = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-routes-hand-region-' . uniqid();
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
            'module_name' => 'Widgets',
            'module_type' => 'Core',
            'table_name'  => 'widgets',
            'id_type'     => 'bigint',
            'columns'     => [],
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
                'create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/widgets/send']],
            ],
        ], $overrides);
    }

    private function issueAction(array $overrides = []): array
    {
        return array_merge([
            'name'       => 'Issue',
            'urlParams'  => ['uuid'],
            'operations' => [
                'view' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/widgets/{uuid}/issue']],
            ],
        ], $overrides);
    }

    private function path(): string
    {
        return $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Widgets/Routes/api.php';
    }

    private function content(): string
    {
        return file_get_contents($this->path());
    }

    private function generate(array $config, bool $force = true): bool
    {
        $generator = new RoutesGenerator('Widgets', 'Core', $config);
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

    public function test_1_fresh_file_has_ordered_empty_hand_routes_region_and_no_issues(): void
    {
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction(), 'Issue' => $this->issueAction()])));
        $content = $this->content();

        $customEnd = strpos($content, '// [generator:region:custom-routes:end]');
        $handStart = strpos($content, '// [generator:region:hand-routes:start]');
        $handEnd = strpos($content, '// [generator:region:hand-routes:end]');
        $activity = strpos($content, '/widgets/{uuid}/activity');

        $this->assertNotFalse($customEnd);
        $this->assertNotFalse($handStart);
        $this->assertNotFalse($handEnd);
        $this->assertNotFalse($activity);
        $this->assertTrue($customEnd < $handStart);
        $this->assertTrue($handStart < $handEnd);
        $this->assertTrue($handEnd < $activity);

        $this->assertSame('', $this->regionInner($content, 'hand-routes'));
        $this->assertSame([], $this->issues);
    }

    public function test_2_hand_routes_text_survives_force_byte_for_byte_exactly_once(): void
    {
        $this->assertTrue($this->generate($this->baseConfig()));
        $before = $this->content();

        $handText = "// Probe: hand route inside the generated region\n"
            . "Route::middleware(['auth:sanctum'])\n"
            . "    ->get('/widgets/probe/ping', [WidgetsController::class, 'probePing']);";
        file_put_contents($this->path(), $this->insertBeforeRegionEnd($before, 'hand-routes', $handText));

        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig()));
        $after = $this->content();

        $this->assertSame(1, substr_count($after, 'probePing'));
        $this->assertSame($handText, $this->regionInner($after, 'hand-routes'));
    }

    public function test_3_drifted_route_inside_custom_routes_is_migrated_to_hand_routes_with_a_warning(): void
    {
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction()])));
        $before = $this->content();

        $drifted = "Route::middleware(['auth:sanctum'])->get('/widgets/conversations', [WidgetsController::class, 'listConversations']);";
        file_put_contents($this->path(), $this->insertBeforeRegionEnd($before, 'custom-routes', $drifted));

        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction()])));
        $after = $this->content();

        $this->assertStringNotContainsString('listConversations', $this->regionInner($after, 'custom-routes'));
        $this->assertStringContainsString('listConversations', $this->regionInner($after, 'hand-routes'));

        $issueText = implode("\n", $this->issues);
        $this->assertStringContainsString('moved into hand-routes', $issueText);
        $this->assertStringContainsString('GET /widgets/conversations', $issueText);

        $this->assertSame(0, $this->lintExitCode());
    }

    public function test_4_repeated_force_runs_are_idempotent(): void
    {
        $config = $this->baseConfig(['Send' => $this->sendAction()]);

        $this->assertTrue($this->generate($config));

        $this->issues = [];
        $this->assertTrue($this->generate($config));
        $run2 = $this->content();
        $this->assertSame([], $this->issues, 'run 2 should report no issues');

        $this->issues = [];
        $this->assertTrue($this->generate($config));
        $run3 = $this->content();
        $this->assertSame([], $this->issues, 'run 3 should report no issues');

        $this->assertSame(1, substr_count($run3, 'createSend'));
        $this->assertSame($run2, $run3, 'run 3 must be byte-identical to run 2');
    }

    public function test_5_removed_actions_are_migrated_to_hand_routes(): void
    {
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction(), 'Issue' => $this->issueAction()])));

        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig([])));
        $content = $this->content();

        $handInner = $this->regionInner($content, 'hand-routes');
        $this->assertStringContainsString('createSend', $handInner);
        $this->assertStringContainsString('viewIssue', $handInner);

        $customInner = $this->regionInner($content, 'custom-routes');
        $this->assertStringNotContainsString('createSend', $customInner);
        $this->assertStringNotContainsString('viewIssue', $customInner);

        $issueText = implode("\n", $this->issues);
        $this->assertStringContainsString('POST /widgets/send -> createSend', $issueText);
        $this->assertStringContainsString('POST /widgets/{uuid}/issue -> viewIssue', $issueText);
    }

    public function test_6_messages_shape_edited_handler_is_migrated_and_shadows_the_fresh_route(): void
    {
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction()])));
        $before = $this->content();
        file_put_contents($this->path(), str_replace('createSend', 'viewSend', $before));

        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction()])));
        $after = $this->content();

        $this->assertSame(1, substr_count($after, 'viewSend'));
        $this->assertStringNotContainsString('createSend', $after);
        $this->assertStringContainsString('viewSend', $this->regionInner($after, 'hand-routes'));

        $issueText = implode("\n", $this->issues);
        $this->assertStringContainsString('hand-routes owns POST /widgets/send', $issueText);

        $this->assertSame(0, $this->lintExitCode());
    }

    public function test_7_apikeys_shape_edited_path_with_same_handler_is_migrated_and_shadows_the_fresh_route(): void
    {
        $this->assertTrue($this->generate($this->baseConfig(['Issue' => $this->issueAction()])));
        $before = $this->content();
        file_put_contents($this->path(), str_replace('/widgets/{uuid}/issue', '/widgets/issue', $before));

        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig(['Issue' => $this->issueAction()])));
        $after = $this->content();

        $this->assertStringNotContainsString('/widgets/{uuid}/issue', $after);
        $this->assertSame(1, substr_count($after, "'/widgets/issue'"));

        $this->assertSame(0, $this->lintExitCode());
    }

    public function test_8_non_force_generate_on_existing_file_is_a_noop(): void
    {
        $config = $this->baseConfig(['Send' => $this->sendAction()]);
        $this->assertTrue($this->generate($config));
        $before = $this->content();

        $this->assertFalse($this->generate($config, force: false));
        $this->assertSame($before, $this->content());
    }

    public function test_9_addActionRoute_after_a_shadowed_edit_is_a_noop(): void
    {
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction()])));
        $before = $this->content();
        file_put_contents($this->path(), str_replace('createSend', 'viewSend', $before));
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction()])));
        $customBefore = $this->regionInner($this->content(), 'custom-routes');

        $generator = new RoutesGenerator('Widgets', 'Core', $this->baseConfig(['Send' => $this->sendAction()]));
        $this->assertFalse($generator->addActionRoute('Send', $this->sendAction()));

        $this->assertSame($customBefore, $this->regionInner($this->content(), 'custom-routes'));
    }

    public function test_10_a_config_edit_is_shadowed_by_a_stale_hand_copy_then_applies_once_deleted(): void
    {
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction()])));

        $movedAction = $this->sendAction([
            'operations' => ['create' => ['enabled' => true, 'endpoint' => ['method' => 'POST', 'path' => '/widgets/send-now']]],
        ]);

        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $movedAction])));
        $after = $this->content();

        $this->assertStringContainsString("'/widgets/send'", $this->regionInner($after, 'hand-routes'));
        $this->assertStringNotContainsString('send-now', $after);

        $issueText = implode("\n", $this->issues);
        $this->assertStringContainsString('moved into hand-routes', $issueText);
        $this->assertStringContainsString('omitted generated POST /widgets/send-now -> createSend', $issueText);

        // Empty hand-routes by hand; the config edit applies cleanly on the next --force.
        $emptied = preg_replace(
            '~// \[generator:region:hand-routes:start\].*?// \[generator:region:hand-routes:end\]~s',
            "// [generator:region:hand-routes:start]\n// [generator:region:hand-routes:end]",
            $after,
        );
        file_put_contents($this->path(), $emptied);

        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $movedAction])));
        $final = $this->content();

        $this->assertSame(1, substr_count($final, 'send-now'));
        $this->assertStringContainsString('send-now', $this->regionInner($final, 'custom-routes'));
        $this->assertSame([], $this->issues);
    }

    public function test_11_an_identical_hand_copy_of_a_generated_route_is_silently_deduplicated(): void
    {
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction()])));
        $before = $this->content();
        $sendLine = $this->regionInner($before, 'custom-routes');

        file_put_contents($this->path(), $this->insertBeforeRegionEnd($before, 'hand-routes', $sendLine));

        $this->issues = [];
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction()])));
        $after = $this->content();

        $this->assertSame(1, substr_count($after, "'/widgets/send'"));
        $this->assertSame([], $this->issues);
    }

    public function test_12_half_present_hand_routes_marker_aborts_without_writing(): void
    {
        $this->assertTrue($this->generate($this->baseConfig(['Send' => $this->sendAction()])));
        $before = $this->content();

        $broken = str_replace('// [generator:region:hand-routes:end]', '', $before);
        $this->assertNotSame($before, $broken);
        file_put_contents($this->path(), $broken);

        $this->issues = [];
        $this->assertFalse($this->generate($this->baseConfig(['Send' => $this->sendAction()])));

        $this->assertSame($broken, $this->content());
        $this->assertStringContainsString('only one marker', implode("\n", $this->issues));
    }
}
