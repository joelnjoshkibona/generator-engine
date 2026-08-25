<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend;

use Blutrixx\GeneratorEngine\Generators\Frontend\FrontendPipeline;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression net for FrontendPipeline — the frontend generator sequence lifted
 * out of SYSTEM_SHELL's ModuleScaffolder::generate() so it can also be driven
 * standalone by bin/gen-frontend.
 *
 * The extraction had to be behaviour-preserving, and the parts most likely to
 * drift silently are exactly the ones asserted here:
 *
 *  - the emitted file manifest,
 *  - the generator LABELS and their ORDER, which `--only=` matches on
 *    (substring, case-insensitive), so a rename or reorder quietly changes what
 *    an existing `make:module --only=...` invocation produces, and
 *  - two deliberate asymmetries that look like bugs and must not be "tidied up"
 *    by accident: delegation components ignore --only, and action components are
 *    never handed force.
 *
 * Every test here runs the real generators against a scratch project root — no
 * Laravel container, no database. That is itself the load-bearing claim: if this
 * file ever needs a framework to run, the standalone CLI has been broken.
 */
class FrontendPipelineTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-frontend-pipeline-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetModuleSubGroup();
        PathManager::resetProjectRoot();
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    // ─── Manifest and ordering ────────────────────────────────────────────────

    public function test_full_crud_config_emits_the_expected_file_manifest(): void
    {
        $result = $this->pipeline()->run('Widgets', 'Core', $this->crudConfig());

        $this->assertSame([], $result['errors']);
        $this->assertSame(17, $result['created']);
        $this->assertSame(0, $result['skipped']);

        $this->assertSame([
            'FRONTEND/src/api-contract.json',
            'FRONTEND/src/menus.json',
            'FRONTEND/src/modules.json',
            'FRONTEND/src/pages/modules/core/Widgets/Components/WidgetsCreateForm.vue',
            'FRONTEND/src/pages/modules/core/Widgets/Components/WidgetsDeleteForm.vue',
            'FRONTEND/src/pages/modules/core/Widgets/Components/WidgetsEditForm.vue',
            'FRONTEND/src/pages/modules/core/Widgets/Components/WidgetsViewModal.vue',
            'FRONTEND/src/pages/modules/core/Widgets/WidgetsCreatePage.vue',
            'FRONTEND/src/pages/modules/core/Widgets/WidgetsDeletePage.vue',
            'FRONTEND/src/pages/modules/core/Widgets/WidgetsDetailsHistoryPage.vue',
            'FRONTEND/src/pages/modules/core/Widgets/WidgetsDetailsLayout.vue',
            'FRONTEND/src/pages/modules/core/Widgets/WidgetsDetailsOverviewPage.vue',
            'FRONTEND/src/pages/modules/core/Widgets/WidgetsEditPage.vue',
            'FRONTEND/src/pages/modules/core/Widgets/WidgetsListPage.vue',
            'FRONTEND/src/pages/modules/core/Widgets/e2e/widgets-crud.e2e.js',
            'FRONTEND/src/pages/modules/core/Widgets/locales/en.json',
            'FRONTEND/src/pages/modules/core/Widgets/locales/sw.json',
            'FRONTEND/src/pages/modules/core/Widgets/routes.ts',
        ], $this->emittedFiles());
    }

    public function test_generator_labels_run_in_the_documented_order(): void
    {
        $log = [];
        $this->pipeline($log)->run('Widgets', 'Core', $this->crudConfig());

        $this->assertSame([
            'ListPage',
            'CreatePage',
            'CreateForm',
            'EditPage',
            'EditForm',
            'ViewLayout',
            'ViewOverview',
            'ViewHistory',
            'ViewModal',
            'DeletePage',
            'DeleteForm',
            'FrontendRoutes',
            'ModulesJson',
            'MenusJson',
            'ApiContract',
            'FrontendLocales',
            'PlaywrightTest',
        ], $this->createdLabels($log));
    }

    public function test_pipeline_runs_without_any_laravel_runtime(): void
    {
        // Guards the standalone CLI's core premise. If a generator ever reaches for
        // the container, this is the test that says so before bin/gen-frontend
        // starts fataling for users.
        $this->assertFalse(function_exists('app'), 'Laravel container helper leaked into the test runtime');
        $this->assertFalse(function_exists('config'), 'Laravel config helper leaked into the test runtime');

        $result = $this->pipeline()->run('Widgets', 'Core', $this->crudConfig());

        $this->assertSame([], $result['errors']);
    }

    // ─── Feature gating ───────────────────────────────────────────────────────

    public function test_only_the_configured_frontend_features_are_generated(): void
    {
        $config = $this->crudConfig();
        $config['features']['frontend'] = ['list' => $config['features']['frontend']['list']];

        $log = [];
        $this->pipeline($log)->run('Widgets', 'Core', $config);

        $this->assertSame(
            ['ListPage', 'FrontendRoutes', 'ModulesJson', 'MenusJson', 'ApiContract', 'FrontendLocales', 'PlaywrightTest'],
            $this->createdLabels($log)
        );
    }

    public function test_only_filter_restricts_generation_to_matching_labels(): void
    {
        $log = [];
        $this->pipeline($log)->setOnly(['Page'])->run('Widgets', 'Core', $this->crudConfig());

        $this->assertSame(
            ['ListPage', 'CreatePage', 'EditPage', 'DeletePage'],
            $this->createdLabels($log)
        );
    }

    public function test_only_filter_matches_labels_case_insensitively(): void
    {
        $log = [];
        $this->pipeline($log)->setOnly(['modulesjson'])->run('Widgets', 'Core', $this->crudConfig());

        $this->assertSame(['ModulesJson'], $this->createdLabels($log));
    }

    // ─── Delegations and actions ──────────────────────────────────────────────

    public function test_delegation_emits_a_tab_component_and_reports_its_ui_type(): void
    {
        $config = $this->crudConfig();
        $config['delegations'] = [
            'Readings' => [
                'name'          => 'Readings',
                'label'         => 'Readings',
                'uiType'        => 'tab',
                'relatedModule' => ['name' => 'Readings', 'group' => 'Core'],
                'filterKey'     => 'widget_id',
            ],
        ];

        $log = [];
        $this->pipeline($log)->run('Widgets', 'Core', $config);

        $this->assertContains('DelegationtabComponent [Readings]', $this->createdLabels($log));
    }

    public function test_delegation_components_respect_the_only_filter(): void
    {
        // ModuleScaffolder did NOT gate the delegation component on its --only=
        // filter, and that was not harmless: running the standalone CLI with
        // `--only=ApiContract` against SYSTEM_SHELL rewrote a tracked, hand-tuned
        // LocationsUserLocationsTab.vue purely as a side effect. A filter must not
        // touch files outside it.
        $config = $this->crudConfig();
        $config['delegations'] = [
            'Readings' => [
                'name'          => 'Readings',
                'uiType'        => 'tab',
                'relatedModule' => ['name' => 'Readings', 'group' => 'Core'],
                'filterKey'     => 'widget_id',
            ],
        ];

        $log = [];
        $this->pipeline($log)->setOnly(['ModulesJson'])->run('Widgets', 'Core', $config);

        $this->assertSame(['ModulesJson'], $this->createdLabels($log));
    }

    public function test_only_filter_can_still_target_delegation_components_by_name(): void
    {
        $config = $this->crudConfig();
        $config['delegations'] = [
            'Readings' => [
                'name'          => 'Readings',
                'uiType'        => 'tab',
                'relatedModule' => ['name' => 'Readings', 'group' => 'Core'],
                'filterKey'     => 'widget_id',
            ],
        ];

        $log = [];
        $this->pipeline($log)->setOnly(['Delegation'])->run('Widgets', 'Core', $config);

        $this->assertSame(['DelegationtabComponent [Readings]'], $this->createdLabels($log));
    }

    public function test_action_component_is_gated_on_the_only_filter(): void
    {
        $config = $this->crudConfig();
        $config['actions'] = ['approve' => $this->approveAction()];

        $log = [];
        $this->pipeline($log)->setOnly(['ModulesJson'])->run('Widgets', 'Core', $config);

        $this->assertSame(['ModulesJson'], $this->createdLabels($log));
    }

    public function test_action_without_ui_is_skipped_rather_than_generated(): void
    {
        $config = $this->crudConfig();
        $config['actions'] = ['approve' => $this->approveAction(['hasUI' => false])];

        $log = [];
        $this->pipeline($log)->run('Widgets', 'Core', $config);

        $this->assertNotContains('ActionComponent [approve]', $this->createdLabels($log));
        $this->assertContains('  Skipped (no UI or already exists): ActionComponent [approve]', $log);
    }

    // ─── Degradation ──────────────────────────────────────────────────────────

    public function test_unresolvable_delegation_target_is_reported_and_degrades_instead_of_aborting(): void
    {
        // A delegation whose related module is not in the registry used to kill the
        // whole run standalone: CustomFeatureTabComponentGenerator logged the warning
        // through the Log facade, which fatals with "A facade root has not been set"
        // outside Laravel. It now goes through PathManager::reportIssue(), so the
        // component is still emitted with its related-module imports stubbed out.
        $issues = [];
        PathManager::setIssueHandler(function (string $message, string $level = 'warning') use (&$issues): void {
            $issues[] = $message;
        });

        try {
            $config = $this->crudConfig();
            $config['delegations'] = [
                'Ghosts' => [
                    'name'          => 'Ghosts',
                    'uiType'        => 'tab',
                    'relatedModule' => ['name' => 'Ghosts', 'group' => 'Core'],
                    'filterKey'     => 'widget_id',
                ],
            ];

            $result = $this->pipeline()->run('Widgets', 'Core', $config);

            $this->assertSame([], $result['errors'], 'an unresolvable delegation target must not abort the run');
            $this->assertContains(
                'FRONTEND/src/pages/modules/core/Widgets/WidgetsListPage.vue',
                $this->emittedFiles()
            );

            $this->assertNotEmpty($issues, 'the unresolvable related module should be reported, not swallowed');
            $this->assertStringContainsString('Ghosts', implode("\n", $issues));

            $tab = file_get_contents(
                $this->tmpRoot . '/FRONTEND/src/pages/modules/core/Widgets/WidgetsGhostsTab.vue'
            );
            $this->assertStringContainsString('component imports skipped', $tab);
        } finally {
            PathManager::setIssueHandler(null);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** @param array<int, string> $log */
    private function pipeline(array &$log = []): FrontendPipeline
    {
        return (new FrontendPipeline(function (string $message, string $level) use (&$log): void {
            $log[] = $message;
        }))->setForce(true);
    }

    /**
     * Extract the ordered list of successfully-generated labels from the callback log.
     *
     * @param  array<int, string> $log
     * @return array<int, string>
     */
    private function createdLabels(array $log): array
    {
        $labels = [];
        foreach ($log as $line) {
            if (preg_match('/^\s*Created: (.+?)(?: \(en\.json.*)?$/', $line, $m)) {
                $labels[] = trim($m[1]);
            }
        }

        return $labels;
    }

    /** @return array<int, string> Project-root-relative paths, sorted. */
    private function emittedFiles(): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace($this->tmpRoot . '/', '', $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    private function crudConfig(): array
    {
        $fields = [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'field' => 'name'],
        ];

        return [
            'module_name' => 'Widgets',
            'module_type' => 'Core',
            'table_name'  => 'widgets',
            'id_type'     => 'bigint',
            'columns'     => [
                [
                    'name'     => 'name',
                    'type'     => 'string',
                    'length'   => '100',
                    'nullable' => false,
                    'unique'   => true,
                    'indexed'  => true,
                ],
            ],
            'features' => [
                'backend' => [
                    'list'   => ['enabled' => true],
                    'create' => ['enabled' => true],
                    'view'   => ['enabled' => true],
                    'edit'   => ['enabled' => true],
                    'delete' => ['enabled' => true],
                ],
                'frontend' => [
                    'list'   => ['enabled' => true, 'primaryField' => 'name', 'fields' => $fields],
                    'create' => ['enabled' => true, 'fields' => $fields],
                    'view'   => ['enabled' => true, 'fields' => $fields, 'titleData' => 'name', 'idParam' => 'uuid'],
                    'edit'   => ['enabled' => true, 'fields' => $fields],
                    'delete' => ['enabled' => true, 'fields' => $fields],
                ],
            ],
        ];
    }

    private function approveAction(array $overrides = []): array
    {
        return array_merge([
            'name'       => 'approve',
            'label'      => 'Approve',
            'hasUI'      => true,
            'uiType'     => 'modal',
            'urlParams'  => ['uuid'],
            'operations' => [
                'create' => [
                    'enabled'  => true,
                    'endpoint' => ['method' => 'POST', 'path' => '/widgets/{uuid}/approve'],
                ],
            ],
        ], $overrides);
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
}
