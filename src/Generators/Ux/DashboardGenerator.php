<?php

namespace Blutrixx\GeneratorEngine\Generators\Ux;

use Blutrixx\GeneratorEngine\Generators\PatchesRegions;
use Blutrixx\GeneratorEngine\Generators\PathManager;

class DashboardGenerator extends BaseUxGenerator
{
    use PatchesRegions;

    public function generate(): void
    {
        $dashboard = $this->blueprint['dashboard'] ?? [];
        if (empty($dashboard['quick_actions'])) return;

        $this->generateQuickActionsComponent($dashboard['quick_actions']);
        $this->generateMobileQuickActionsComponent($dashboard['quick_actions']);
        $this->patchDashboardPage();
        $this->patchMobileDashboardPage();
    }

    private function generateQuickActionsComponent(array $quickActions): void
    {
        $stub = $this->loadStub('dashboard-quick-actions.stub');

        $emojiMap = [
            'ShoppingCart' => '🛒', 'PackageCheck' => '📦', 'Receipt' => '🧾',
            'Calculator' => '🧮', 'ShoppingBag' => '🛍️', 'RotateCcw' => '↩️',
            'Truck' => '🚚', 'Wallet' => '💳', 'Users' => '👥',
        ];

        $items = array_map(function ($action) use ($emojiMap) {
            $emoji = $emojiMap[$action['icon']] ?? '⚡';
            $wizardPart = isset($action['wizard']) ? ", wizard: '{$action['wizard']}'" : '';
            $routePart = '';
            if (!isset($action['wizard'])) {
                $module = $action['composite'] ?? (isset($action['target']) ? (explode('/', $action['target'])[1] ?? '') : '');
                if ($module) {
                    $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $module));
                    $routePart = ", routeName: '{$kebab}-create'";
                }
            }
            return "    { label: '{$action['label']}', emoji: '{$emoji}'{$wizardPart}{$routePart} }";
        }, $quickActions);

        $quickActionsConfig = "[\n" . implode(",\n", $items) . "\n]";
        $content = str_replace('[[quickActionsConfig]]', $quickActionsConfig, $stub);

        $dir = PathManager::getFrontendBasePath() . '/src/pages/dashboard';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = "{$dir}/DashboardQuickActions.vue";
        $this->writeFile($path, $content);
    }

    private function generateMobileQuickActionsComponent(array $quickActions): void
    {
        $stub = $this->loadMobileStub('dashboard-quick-actions.stub');

        $emojiMap = [
            'ShoppingCart' => '🛒', 'PackageCheck' => '📦', 'Receipt' => '🧾',
            'Calculator' => '🧮', 'ShoppingBag' => '🛍️', 'RotateCcw' => '↩️',
            'Truck' => '🚚', 'Wallet' => '💳', 'Users' => '👥',
        ];

        $items = array_map(function ($action) use ($emojiMap) {
            $emoji = $emojiMap[$action['icon']] ?? '⚡';
            $wizardPart = isset($action['wizard']) ? ", wizard: '{$action['wizard']}'" : '';
            $routePart = '';
            if (!isset($action['wizard'])) {
                $module = $action['composite'] ?? (isset($action['target']) ? (explode('/', $action['target'])[1] ?? '') : '');
                if ($module) {
                    $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $module));
                    $routePart = ", routeName: '{$kebab}-create'";
                }
            }
            return "\t{ label: '{$action['label']}', emoji: '{$emoji}'{$wizardPart}{$routePart} }";
        }, $quickActions);

        $quickActionsConfig = "[\n" . implode(",\n", $items) . "\n]";
        $content = str_replace('[[quickActionsConfig]]', $quickActionsConfig, $stub);

        $dir = PathManager::getMobileAppBasePath() . '/resources/js/src/pages/dashboard';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = "{$dir}/DashboardQuickActions.vue";
        $this->writeFile($path, $content);
    }

    private function patchDashboardPage(): void
    {
        $dashboardPage = PathManager::getFrontendBasePath()
            . '/src/pages/modules/system/Analytics/Dashboard/DashboardPage.vue';

        if (!file_exists($dashboardPage)) return;

        $importLine   = "import DashboardQuickActions from '@/pages/dashboard/DashboardQuickActions.vue'";
        $componentTag = "        <DashboardQuickActions />";

        $importPatched  = $this->patchRegion($dashboardPage, 'quick-actions-import', $importLine);
        $contentPatched = $this->patchRegion($dashboardPage, 'quick-actions', $componentTag);

        if ($importPatched || $contentPatched) {
            $this->created[] = $dashboardPage . ' (quick-actions region patched)';
        }
    }

    private function patchMobileDashboardPage(): void
    {
        $dashboardPage = PathManager::getMobileAppBasePath()
            . '/resources/js/src/pages/modules/system/Analytics/Dashboard/DashboardPage.vue';

        if (!file_exists($dashboardPage)) return;

        $importLine   = "import DashboardQuickActions from '@/pages/dashboard/DashboardQuickActions.vue'";
        $componentTag = "        <DashboardQuickActions />";

        $importPatched  = $this->patchRegion($dashboardPage, 'quick-actions-import', $importLine);
        $contentPatched = $this->patchRegion($dashboardPage, 'quick-actions', $componentTag);

        if ($importPatched || $contentPatched) {
            $this->created[] = $dashboardPage . ' (quick-actions region patched)';
        }
    }
}
