<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Ux;

use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Generators\Ux\DashboardGenerator;
use PHPUnit\Framework\TestCase;

/**
 * `DashboardGenerator` had zero test coverage of any kind before this file —
 * confirmed via a full-tree grep for "DashboardGenerator" across tests/
 * turning up no matches. Locks in three real, easy-to-miss behaviors:
 *
 * 1. An empty (or absent) `dashboard.quick_actions` makes generate() return
 *    immediately -- 0 files, by design, not a bug.
 * 2. The two "patch" steps (web + mobile DashboardPage.vue) are SILENT
 *    no-ops when the target file doesn't exist -- no error, no warning, no
 *    entry in getSkipped(). The DashboardQuickActions.vue component itself
 *    is still written unconditionally either way, so it's easy to believe
 *    the wiring worked when only half of it did.
 * 3. `wizard` and `composite`/`target` are mutually exclusive in how the
 *    generated JS entry's routing hint is built: a quick action with
 *    `wizard` set never gets a `routeName`, even if `target`/`composite` are
 *    also present.
 */
class DashboardGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-dashboard-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
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

    private function dashboardPagePath(): string
    {
        return PathManager::getFrontendBasePath() . '/src/pages/modules/system/Analytics/Dashboard/DashboardPage.vue';
    }

    private function quickActionsComponentPath(): string
    {
        return PathManager::getFrontendBasePath() . '/src/pages/dashboard/DashboardQuickActions.vue';
    }

    /** Minimal stand-in for the kernel-shipped DashboardPage.vue, carrying both region markers the patch step targets. */
    private function seedDashboardPageWithRegionMarkers(): void
    {
        $path = $this->dashboardPagePath();
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, <<<VUE
        <template>
          <div>
        <!-- [generator:region:quick-actions:start] -->
        <!-- [generator:region:quick-actions:end] -->
          </div>
        </template>
        <script setup lang="ts">
        // [generator:region:quick-actions-import:start]
        // [generator:region:quick-actions-import:end]
        </script>
        VUE);
    }

    public function test_generate_is_a_no_op_when_quick_actions_is_empty_or_absent(): void
    {
        $this->seedDashboardPageWithRegionMarkers();

        (new DashboardGenerator(['dashboard' => ['quick_actions' => []]]))->generate();
        $this->assertFileDoesNotExist($this->quickActionsComponentPath());

        (new DashboardGenerator([]))->generate();
        $this->assertFileDoesNotExist($this->quickActionsComponentPath());
    }

    public function test_generate_writes_quick_actions_component_and_patches_an_existing_dashboard_page(): void
    {
        $this->seedDashboardPageWithRegionMarkers();

        $generator = new DashboardGenerator([
            'dashboard' => ['quick_actions' => [
                ['label' => 'New Quote', 'icon' => 'FileText', 'composite' => 'Quotes', 'target' => 'Custom/Quotes', 'wizard' => 'QuoteWizard'],
            ]],
        ]);
        $generator->generate();

        $this->assertFileExists($this->quickActionsComponentPath());

        $dashboardContent = (string) file_get_contents($this->dashboardPagePath());
        $this->assertStringContainsString('<DashboardQuickActions />', $dashboardContent);
        $this->assertStringContainsString("import DashboardQuickActions from '@/pages/dashboard/DashboardQuickActions.vue'", $dashboardContent);
    }

    /**
     * The web DashboardPage.vue not existing at all must not throw or block
     * the (unconditional) component write -- confirms the documented silent
     * no-op, not a guess.
     */
    public function test_patch_step_silently_no_ops_when_dashboard_page_does_not_exist(): void
    {
        // Deliberately do NOT seed DashboardPage.vue.
        $generator = new DashboardGenerator([
            'dashboard' => ['quick_actions' => [
                ['label' => 'New Quote', 'icon' => 'FileText', 'target' => 'Custom/Quotes'],
            ]],
        ]);
        $generator->generate();

        $this->assertFileDoesNotExist($this->dashboardPagePath());
        $this->assertFileExists($this->quickActionsComponentPath(), 'The quick-actions component must still be written even though the patch target is missing.');
    }

    public function test_a_quick_action_with_a_wizard_never_gets_a_routename_even_when_target_is_also_present(): void
    {
        $this->seedDashboardPageWithRegionMarkers();

        (new DashboardGenerator([
            'dashboard' => ['quick_actions' => [
                ['label' => 'New Quote', 'icon' => 'FileText', 'target' => 'Custom/Quotes', 'wizard' => 'QuoteWizard'],
            ]],
        ]))->generate();

        $content = (string) file_get_contents($this->quickActionsComponentPath());
        $this->assertStringContainsString("wizard: 'QuoteWizard'", $content);
        $this->assertStringNotContainsString('routeName:', $content);
    }

    public function test_a_quick_action_without_a_wizard_derives_routename_from_target(): void
    {
        $this->seedDashboardPageWithRegionMarkers();

        (new DashboardGenerator([
            'dashboard' => ['quick_actions' => [
                ['label' => 'New Quote', 'icon' => 'FileText', 'target' => 'Custom/Quotes'],
            ]],
        ]))->generate();

        $content = (string) file_get_contents($this->quickActionsComponentPath());
        $this->assertStringContainsString("routeName: 'quotes-create'", $content);
        $this->assertStringNotContainsString('wizard:', $content);
    }
}
