<?php

namespace Blutrixx\GeneratorEngine\Generators\Ux;

use Blutrixx\GeneratorEngine\Generators\PathManager;

class DashboardGenerator extends BaseUxGenerator
{
    public function generate(): void
    {
        $dashboard = $this->blueprint['dashboard'] ?? [];
        if (empty($dashboard['quick_actions'])) return;

        $this->generateQuickActionsComponent($dashboard['quick_actions']);
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
}
